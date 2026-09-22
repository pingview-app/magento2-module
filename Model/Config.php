<?php
declare(strict_types=1);

namespace PingView\Monitoring\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;

final class Config
{
    private const PREFIX = 'pingview/general/';
    private const CACHE_ID = 'pingview_monitor_status';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $writer,
        private readonly ReinitableConfigInterface $appConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    public function getApiBase(): string
    {
        return rtrim((string)$this->scopeConfig->getValue(self::PREFIX . 'api_base'), '/');
    }

    public function getCacheTtl(): int
    {
        return max(60, (int)$this->scopeConfig->getValue(self::PREFIX . 'cache_ttl'));
    }

    public function getMonitorId(): string
    {
        return (string)$this->scopeConfig->getValue(self::PREFIX . 'monitor_id');
    }

    public function getConnectedAt(): string
    {
        return (string)$this->scopeConfig->getValue(self::PREFIX . 'connected_at');
    }

    public function getConnectedStoreUrl(): string
    {
        return (string)$this->scopeConfig->getValue(self::PREFIX . 'store_url');
    }

    public function getTeamId(): string
    {
        return (string)$this->scopeConfig->getValue(self::PREFIX . 'team_id');
    }

    /**
     * Who this store reports into, as the API reported it. Never guessed from
     * the Magento admin's own email: that is a different person than whoever
     * owns the PingView account, which is the whole point of the
     * account-identity card (CT-PARITY).
     *
     * @return array{email: string|null, team_name: string|null, team_plan: string|null}
     */
    public function getAccount(): array
    {
        return StatusView::accountIdentity(
            (string)$this->scopeConfig->getValue(self::PREFIX . 'account_email'),
            (string)$this->scopeConfig->getValue(self::PREFIX . 'team_name'),
            (string)$this->scopeConfig->getValue(self::PREFIX . 'team_plan')
        );
    }

    public function saveAccount(?string $email, ?string $teamName, ?string $teamPlan): void
    {
        // Called on every uncached panel load. commit() reinitialises the whole
        // config cache, so nothing is written - and nothing reinitialised -
        // when the API reports what is already stored.
        $changed = false;
        foreach (['account_email' => $email, 'team_name' => $teamName, 'team_plan' => $teamPlan] as $key => $value) {
            if ($value !== null && $value !== '' && $value !== (string)$this->scopeConfig->getValue(self::PREFIX . $key)) {
                $this->writer->save(self::PREFIX . $key, $value);
                $changed = true;
            }
        }
        if ($changed) {
            $this->commit();
        }
    }

    /** Heartbeat monitor that watches this store's own cron from outside. */
    public function getSchedulerHeartbeat(): array
    {
        return [
            'monitor_id' => (string)$this->scopeConfig->getValue(self::PREFIX . 'scheduler_monitor_id'),
            'url' => (string)$this->scopeConfig->getValue(self::PREFIX . 'scheduler_ping_url'),
        ];
    }

    public function saveSchedulerHeartbeat(string $monitorId, string $pingUrl): void
    {
        $this->writer->save(self::PREFIX . 'scheduler_monitor_id', $monitorId);
        $this->writer->save(self::PREFIX . 'scheduler_ping_url', $pingUrl);
        $this->commit();
    }

    public function clearSchedulerHeartbeat(): void
    {
        $this->writer->delete(self::PREFIX . 'scheduler_monitor_id');
        $this->writer->delete(self::PREFIX . 'scheduler_ping_url');
        $this->commit();
    }

    /** Fingerprint of the last shop profile sent, so a resend needs a change. */
    public function getShopProfileHash(): string
    {
        return (string)$this->scopeConfig->getValue(self::PREFIX . 'shop_profile_hash');
    }

    public function saveShopProfileHash(string $hash): void
    {
        $this->writer->save(self::PREFIX . 'shop_profile_hash', $hash);
        $this->commit();
    }

    public function getApiKey(): ?string
    {
        $encrypted = (string)$this->scopeConfig->getValue(self::PREFIX . 'api_key');
        if ($encrypted === '') {
            return null;
        }

        try {
            // decrypt() answers '' for a payload the current crypt key cannot read
            // (restored DB, rotated key). Without this, isConnected() stays true and
            // every call goes out as a bare `Bearer `.
            return $this->encryptor->decrypt($encrypted) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function isConnected(): bool
    {
        return $this->getApiKey() !== null && $this->getMonitorId() !== '';
    }

    public function saveConnection(string $apiKey, string $monitorId, string $teamId, string $storeUrl): void
    {
        $values = [
            'api_key' => $this->encryptor->encrypt($apiKey),
            'monitor_id' => $monitorId,
            'team_id' => $teamId,
            'store_url' => $storeUrl,
            'connected_at' => gmdate(DATE_ATOM),
        ];

        foreach ($values as $key => $value) {
            $this->writer->save(self::PREFIX . $key, $value);
        }
        $this->commit();
    }

    /**
     * Every key this module writes. Disconnect and the module uninstaller both
     * read this list, so a value added to one is never forgotten by the other -
     * an encrypted API key surviving an uninstall is the failure that matters.
     */
    public const OWNED_CONFIG_KEYS = [
        'api_key',
        'monitor_id',
        'team_id',
        'store_url',
        'connected_at',
        'account_email',
        'team_name',
        'team_plan',
        'scheduler_monitor_id',
        'scheduler_ping_url',
        'shop_profile_hash',
    ];

    public function disconnect(): void
    {
        foreach (self::OWNED_CONFIG_KEYS as $key) {
            $this->writer->delete(self::PREFIX . $key);
        }
        $this->commit();
    }

    /**
     * Make a just-written connection readable on the next request.
     *
     * WriterInterface::save() only touches core_config_data. The `config` cache
     * type still serves the pre-write snapshot, so ScopeConfig keeps answering
     * '' and isConnected() stays false: provisioning succeeds upstream, the
     * store stays on the setup screen, and the retry comes back "account
     * already exists". Core does the same reinit after its own config saves
     * (Magento\Config\Model\Config::save).
     */
    private function commit(): void
    {
        $this->appConfig->reinit();
        $this->clearStatusCache();
    }

    public function loadStatusCache(): ?array
    {
        $raw = $this->cache->load(self::CACHE_ID);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            $data = $this->json->unserialize($raw);
            return is_array($data) ? $data : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function saveStatusCache(array $data): void
    {
        $this->cache->save($this->json->serialize($data), self::CACHE_ID, ['PINGVIEW'], 86400);
    }

    public function clearStatusCache(): void
    {
        $this->cache->remove(self::CACHE_ID);
    }
}
