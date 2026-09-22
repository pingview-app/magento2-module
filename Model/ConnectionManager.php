<?php
declare(strict_types=1);

namespace PingView\Monitoring\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

final class ConnectionManager
{
    /**
     * Provisioning refused because this merchant is already a PingView user
     * (USER_EXISTS) or this store is already monitored (DUPLICATE_MONITOR).
     * Carried as the exception code so the setup screen can offer the only
     * move left - connecting an existing API key - instead of a dead end.
     */
    public const ACCOUNT_EXISTS = 409;

    public function __construct(
        private readonly ApiClient $api,
        private readonly Config $config,
        private readonly ShopProfile $shopProfile,
        private readonly StoreManagerInterface $stores,
        private readonly ProductMetadataInterface $productMetadata,
        private readonly LoggerInterface $logger
    ) {
    }

    public function provision(string $email): void
    {
        $result = $this->api->provision(
            $email,
            $this->storeUrl(),
            (string)$this->stores->getStore()->getName(),
            $this->productMetadata->getVersion()
        );
        if (!$result['ok']) {
            throw new LocalizedException(
                __($result['error'] ?: 'Could not connect to PingView.'),
                null,
                self::failureCode($result)
            );
        }

        $this->saveResponse($result['data']);

        // The account email arrives here in full; GET /token/info only ever
        // returns it masked. This is the one moment the panel can learn it.
        $this->config->saveAccount(
            isset($result['data']['user']['email']) ? (string)$result['data']['user']['email'] : null,
            isset($result['data']['team']['name']) ? (string)$result['data']['team']['name'] : null,
            null
        );

        $this->sendShopProfile();
    }

    public function connect(string $apiKey): void
    {
        $info = $this->api->tokenInfo($apiKey);
        if (!$info['ok'] || empty($info['data']['valid']) || empty($info['data']['team']['id'])) {
            throw new LocalizedException(__($info['error'] ?: 'That API key is invalid or expired.'));
        }

        $storeUrl = $this->storeUrl();
        $host = parse_url($storeUrl, PHP_URL_HOST);
        $monitor = null;
        // The home page monitor first, a same-host one only as a fallback: after
        // addPages() the team also holds "Cart - host" and "Checkout - host",
        // and a reconnect that took the first host match pinned the panel to
        // the cart.
        $candidates = (array)($info['data']['monitors'] ?? []);
        foreach ($candidates as $candidate) {
            if (!empty($candidate['target'])
                && StatusView::normalizeUrl((string)$candidate['target']) === StatusView::normalizeUrl($storeUrl)) {
                $monitor = $candidate;
                break;
            }
        }
        foreach ($monitor === null ? $candidates : [] as $candidate) {
            if (!empty($candidate['target']) && parse_url((string)$candidate['target'], PHP_URL_HOST) === $host) {
                $monitor = $candidate;
                break;
            }
        }

        if ($monitor === null) {
            $created = $this->api->createMonitor($apiKey, [
                'name' => 'Magento 2 - ' . $host,
                'target' => $storeUrl,
            ]);
            if (!$created['ok']) {
                throw new LocalizedException(__($created['error'] ?: 'Could not create a monitor for this store.'));
            }

            // POST /monitors nests the monitor one level deeper than the
            // provisioning response does; reading data.id yields nothing.
            $monitor = StatusView::createdMonitor($created);
            if (empty($monitor['id'])) {
                throw new LocalizedException(__('Could not create a monitor for this store.'));
            }
        }

        $this->config->saveConnection($apiKey, (string)$monitor['id'], (string)$info['data']['team']['id'], $storeUrl);
        $this->config->saveAccount(
            isset($info['data']['account']['email']) ? (string)$info['data']['account']['email'] : null,
            isset($info['data']['team']['name']) ? (string)$info['data']['team']['name'] : null,
            isset($info['data']['team']['plan']) ? (string)$info['data']['team']['plan'] : null
        );

        $this->sendShopProfile();
    }

    /**
     * Point the monitor at the address this store actually serves now.
     *
     * The module owns this monitor and holds a write-scoped key, so it
     * repoints it rather than asking the admin to disconnect and reconnect -
     * which, for a merchant whose account already exists, is a path that ends
     * at "account already exists".
     */
    public function resyncTarget(): void
    {
        [$apiKey, $monitorId] = $this->credentials();
        $storeUrl = $this->storeUrl();

        $result = $this->api->updateMonitorTarget($apiKey, $monitorId, $storeUrl);
        if (!$result['ok']) {
            throw new LocalizedException(__($result['error'] ?: 'Could not repoint the monitor.'));
        }

        $this->config->saveConnection($apiKey, $monitorId, $this->config->getTeamId(), $storeUrl);
        $this->sendShopProfile(true);
    }

    /**
     * Add a monitor for each customer-facing page the merchant picked.
     *
     * @param string[] $urls
     * @return int How many were created.
     */
    public function addPages(array $urls): int
    {
        [$apiKey] = $this->credentials();
        $host = (string)parse_url($this->storeUrl(), PHP_URL_HOST);

        // Only addresses this module itself offered are accepted: the form is
        // the only caller, and a monitor pointed anywhere else is not this
        // store's to create.
        $allowed = [];
        foreach ($this->shopProfile->monitorablePages() as $page) {
            $allowed[StatusView::normalizeUrl($page['url'])] = $page['label'];
        }

        $created = 0;
        foreach ($urls as $url) {
            $key = StatusView::normalizeUrl((string)$url);
            if (!isset($allowed[$key])) {
                continue;
            }

            $result = $this->api->createMonitor($apiKey, [
                'name' => $allowed[$key] . ' - ' . $host,
                'target' => (string)$url,
            ]);
            if ($result['ok'] && !empty(StatusView::createdMonitor($result)['id'])) {
                ++$created;
                continue;
            }

            // One refused page (a plan limit, a duplicate) must not lose the
            // ones that worked, so the loop reports instead of throwing.
            $this->logger->warning('PingView could not add a page monitor', [
                'target' => $url,
                'error' => $result['error'],
            ]);
        }

        $this->config->clearStatusCache();

        return $created;
    }

    /**
     * Turn external observation of Magento cron on.
     *
     * heartbeatUrl comes back only on create, so it is stored immediately or
     * the monitor is orphaned. A previously created heartbeat is resumed
     * rather than duplicated: each toggle would otherwise burn a monitor slot.
     */
    public function enableSchedulerWatch(): void
    {
        [$apiKey] = $this->credentials();
        $existing = $this->config->getSchedulerHeartbeat();

        if ($existing['monitor_id'] !== '' && $existing['url'] !== '') {
            $resumed = $this->api->setMonitorActive($apiKey, $existing['monitor_id'], true);
            if ($resumed['ok']) {
                $this->config->clearStatusCache();
                return;
            }

            // The stored monitor is gone. Fall through to a new one rather
            // than leaving the merchant stuck on a dead id.
            $this->config->clearSchedulerHeartbeat();
        }

        $result = $this->api->createMonitor($apiKey, [
            'name' => 'Magento cron - ' . parse_url($this->storeUrl(), PHP_URL_HOST),
            'type' => 'heartbeat',
            'heartbeatOptions' => ['expectedInterval' => 60, 'gracePeriod' => 15],
        ]);

        if (!$result['ok']) {
            throw new LocalizedException(
                $result['code'] === 'PLAN_GATE'
                    ? __('Watching Magento cron from outside needs a higher PingView plan.')
                    : __($result['error'] ?: 'Could not turn on cron observation.')
            );
        }

        $monitor = StatusView::createdMonitor($result);
        // The URL ends up inside a crontab line the admin pastes verbatim, so
        // only an https address on the API's own host is stored.
        $heartbeatUrl = (string)($monitor['heartbeatUrl'] ?? '');
        if (empty($monitor['id'])
            || !filter_var($heartbeatUrl, FILTER_VALIDATE_URL)
            || parse_url($heartbeatUrl, PHP_URL_SCHEME) !== 'https'
            || parse_url($heartbeatUrl, PHP_URL_HOST) !== parse_url($this->config->getApiBase(), PHP_URL_HOST)) {
            throw new LocalizedException(__('PingView returned incomplete monitor data.'));
        }

        $this->config->saveSchedulerHeartbeat((string)$monitor['id'], $heartbeatUrl);
    }

    /** Pause, never delete: see {@see ApiClient::setMonitorActive()}. */
    public function disableSchedulerWatch(): void
    {
        [$apiKey] = $this->credentials();
        $existing = $this->config->getSchedulerHeartbeat();

        if ($existing['monitor_id'] !== '') {
            $paused = $this->api->setMonitorActive($apiKey, $existing['monitor_id'], false);
            if (!$paused['ok']) {
                throw new LocalizedException(__($paused['error'] ?: 'Could not turn off cron observation.'));
            }
        }

        $this->config->clearSchedulerHeartbeat();
    }

    /**
     * Report the store's own addresses so the synthetic checkout journey can be
     * composed from what this store actually serves.
     *
     * Best effort and deliberately silent: a shop profile that will not send
     * must never fail a provisioning or a reconnect, because the monitor - the
     * thing the merchant came for - already exists by then.
     *
     * @param bool $force Send even when nothing changed (the store moved).
     */
    public function sendShopProfile(bool $force = false): void
    {
        try {
            $apiKey = $this->config->getApiKey();
            if ($apiKey === null) {
                return;
            }

            $target = $this->storeUrl();
            $fingerprint = $this->shopProfile->fingerprint($target);
            if (!$force && $fingerprint === $this->config->getShopProfileHash()) {
                return;
            }

            $result = $this->api->sendShopProfile($apiKey, $this->shopProfile->toPayload($target));
            if ($result['ok']) {
                $this->config->saveShopProfileHash($fingerprint);
                return;
            }

            $this->logger->info('PingView shop profile was not accepted', ['error' => $result['error']]);
        } catch (\Throwable $error) {
            $this->logger->info('PingView could not send the shop profile', ['exception' => $error]);
        }
    }

    /**
     * Exception code for a refused provisioning attempt: ACCOUNT_EXISTS for the
     * two API codes an existing PingView user can produce, 0 for everything
     * else (a bad address, a rate limit, an outage) where retrying the same
     * form is still the right move.
     *
     * @param array{code?: string} $result
     */
    public static function failureCode(array $result): int
    {
        return in_array($result['code'] ?? '', ['USER_EXISTS', 'DUPLICATE_MONITOR'], true)
            ? self::ACCOUNT_EXISTS
            : 0;
    }

    /** @return array{0: string, 1: string} */
    private function credentials(): array
    {
        $apiKey = $this->config->getApiKey();
        $monitorId = $this->config->getMonitorId();

        if ($apiKey === null || $monitorId === '') {
            throw new LocalizedException(__('The stored API key can no longer be read. Disconnect and connect again.'));
        }

        return [$apiKey, $monitorId];
    }

    private function saveResponse(array $data): void
    {
        if (empty($data['apiKey']) || empty($data['monitor']['id']) || empty($data['team']['id'])) {
            throw new LocalizedException(__('PingView returned incomplete connection data.'));
        }

        $this->config->saveConnection(
            (string)$data['apiKey'],
            (string)$data['monitor']['id'],
            (string)$data['team']['id'],
            $this->storeUrl()
        );
    }

    private function storeUrl(): string
    {
        return $this->shopProfile->storeUrl();
    }
}
