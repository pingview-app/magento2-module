<?php
declare(strict_types=1);

namespace PingView\Monitoring\Model;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;

final class ApiClient
{
    public const VERSION = '1.2.2';

    /**
     * Read timeout. Matches the WordPress plugin's 15s default: these are
     * aggregation endpoints, not static files, and a store on a slow uplink
     * still has to finish them.
     */
    private const TIMEOUT = 15;

    /**
     * Provisioning creates a user, a team, a monitor and an API key, then
     * notifies Slack and mails a magic link. The WordPress plugin allows 30s
     * for that reason. At the previous 8s the request could time out *after*
     * the account was created, leaving the store on the setup screen with a
     * retry that answers "account already exists" - the exact failure this
     * module shipped with.
     */
    private const PROVISION_TIMEOUT = 30;

    public function __construct(
        private readonly Curl $http,
        private readonly Json $json,
        private readonly Config $config
    ) {
    }

    public function provision(string $email, string $storeUrl, string $storeName, string $magentoVersion): array
    {
        return $this->request('POST', '/magento/provision', null, [
            'email' => $email,
            'siteUrl' => $storeUrl,
            'siteName' => $storeName,
            'pluginVersion' => self::VERSION,
            'phpVersion' => PHP_VERSION,
            'magentoVersion' => $magentoVersion,
        ], self::PROVISION_TIMEOUT);
    }

    public function tokenInfo(string $apiKey): array
    {
        return $this->request('GET', '/token/info', $apiKey);
    }

    /**
     * @param array<string, mixed> $payload Everything but the defaults below.
     */
    public function createMonitor(string $apiKey, array $payload): array
    {
        return $this->request('POST', '/monitors', $apiKey, $payload + [
            'type' => 'http',
            'checkInterval' => 5,
            'slowThreshold' => 3000,
            'locations' => [],
        ]);
    }

    /**
     * Pause or resume a monitor. Turning the scheduler watch off pauses its
     * heartbeat rather than deleting it: a monitor nobody pings any more would
     * otherwise alert forever, and deleting it means the next toggle burns
     * another monitor slot on the merchant's plan.
     */
    public function setMonitorActive(string $apiKey, string $monitorId, bool $isActive): array
    {
        return $this->request('PATCH', '/monitors/' . rawurlencode($monitorId), $apiKey, ['isActive' => $isActive]);
    }

    /**
     * Repoint an existing monitor. Used when the store base URL changed: a
     * monitor left on the old address stops negotiating TLS after an
     * http -> https move, so certificate monitoring goes quiet without ever
     * reporting a failure (CT-PARITY target-resync).
     */
    public function updateMonitorTarget(string $apiKey, string $monitorId, string $target): array
    {
        return $this->request('PATCH', '/monitors/' . rawurlencode($monitorId), $apiKey, ['target' => $target]);
    }

    public function quickStatus(string $monitorId, string $apiKey): array
    {
        return $this->request('GET', '/monitors/' . rawurlencode($monitorId) . '/quick-status', $apiKey);
    }

    public function incidents(string $monitorId, string $apiKey): array
    {
        return $this->request('GET', '/monitors/' . rawurlencode($monitorId) . '/incidents?limit=3', $apiKey);
    }

    /** 24h buckets for the availability chart. */
    public function uptime(string $monitorId, string $apiKey): array
    {
        return $this->request('GET', '/monitors/' . rawurlencode($monitorId) . '/uptime?period=24h', $apiKey);
    }

    /**
     * Security headers (free for every monitor, BR-SCAN-01) and Lighthouse
     * (premium). A free monitor answers 200 with no `lighthouse` block rather
     * than 403, so a missing block is a plan state, never an error.
     */
    public function deepScan(string $monitorId, string $apiKey): array
    {
        return $this->request('GET', '/monitors/' . rawurlencode($monitorId) . '/deep-scan', $apiKey);
    }

    /** Public status pages of the team, for the badge snippet. */
    public function statusPages(string $apiKey): array
    {
        return $this->request('GET', '/status-pages', $apiKey);
    }

    /**
     * Report what this store looks like: cart, checkout, a product, currencies
     * and payment methods. Only the platform knows those addresses, and the
     * synthetic checkout journey is composed from them.
     *
     * @param array<string, mixed> $payload
     */
    public function sendShopProfile(string $apiKey, array $payload): array
    {
        return $this->request('POST', '/shop-profile', $apiKey, $payload);
    }

    public function shopProfile(string $apiKey, string $target): array
    {
        return $this->request('GET', '/shop-profile?target=' . rawurlencode($target), $apiKey);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(
        string $method,
        string $path,
        ?string $apiKey = null,
        ?array $body = null,
        ?int $timeout = null
    ): array {
        $this->http->setTimeout($timeout ?? self::TIMEOUT);

        // Headers are replaced, never accumulated: this Curl instance is shared
        // by every call in the request, so an Authorization left over from one
        // call would ride along on the next.
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'PingView-Magento/' . self::VERSION,
        ];
        if ($apiKey !== null) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $this->http->setHeaders($headers);

        // Curl exposes no PATCH verb and keeps user options between calls, so
        // CURLOPT_CUSTOMREQUEST is set for the one call that needs it and
        // cleared for every other one.
        $this->http->setOptions($method === 'PATCH' ? [CURLOPT_CUSTOMREQUEST => 'PATCH'] : []);

        try {
            if ($body !== null) {
                $this->http->post($this->config->getApiBase() . $path, $this->json->serialize($body));
            } else {
                $this->http->get($this->config->getApiBase() . $path);
            }

            $status = $this->http->getStatus();
            $decoded = $this->json->unserialize($this->http->getBody());
        } catch (\Throwable $error) {
            return ['ok' => false, 'status' => 0, 'code' => '', 'data' => [], 'details' => [], 'error' => $error->getMessage()];
        }

        if (!is_array($decoded)) {
            return ['ok' => false, 'status' => $status, 'code' => '', 'data' => [], 'details' => [], 'error' => 'PingView returned an unreadable response.'];
        }
        if ($status >= 400 || empty($decoded['success'])) {
            return [
                'ok' => false,
                'status' => $status,
                // Machine-readable reason, kept alongside the prose: the setup
                // screen routes USER_EXISTS / DUPLICATE_MONITOR to the API key
                // form instead of leaving the merchant on a dead end.
                'code' => (string)($decoded['error']['code'] ?? ''),
                'data' => [],
                // Field-level validation rows, when the API sent them. Rendered
                // through StatusView::sanitizeDetails() like the WordPress plugin.
                'details' => (array)($decoded['error']['details'] ?? []),
                'error' => (string)($decoded['error']['message'] ?? 'PingView rejected the request.'),
            ];
        }

        return ['ok' => true, 'status' => $status, 'code' => '', 'data' => (array)($decoded['data'] ?? []), 'details' => [], 'error' => ''];
    }
}
