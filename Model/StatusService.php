<?php
declare(strict_types=1);

namespace PingView\Monitoring\Model;

/**
 * One snapshot per panel load, cached as a single blob.
 *
 * The panel needs six reads (status, incidents, uptime buckets, deep scan,
 * token info, status pages). They are fetched together and cached together so
 * a card can never render against a fresher status than its neighbour, and so
 * a page refresh inside the TTL costs nothing.
 *
 * Status is the only mandatory read. Every other one degrades to an empty
 * block: a plan that has no deep scan, a team with no status page and a
 * revoked-scope key must each cost their own card, never the panel.
 */
final class StatusService
{
    public function __construct(private readonly ApiClient $api, private readonly Config $config)
    {
    }

    public function get(): array
    {
        $cached = $this->config->loadStatusCache();
        if ($cached !== null && time() - (int)($cached['fetchedAt'] ?? 0) < $this->config->getCacheTtl()) {
            return array_replace($this->empty(), $cached, ['stale' => false, 'error' => '']);
        }

        $apiKey = $this->config->getApiKey();
        $monitorId = $this->config->getMonitorId();
        if ($apiKey === null || $monitorId === '') {
            return array_replace($this->empty(), ['error' => 'Reconnect PingView to read status.']);
        }

        $status = $this->api->quickStatus($monitorId, $apiKey);
        if (!$status['ok']) {
            return $cached !== null
                ? array_replace($this->empty(), $cached, ['stale' => true, 'error' => $status['error']])
                : array_replace($this->empty(), ['error' => $status['error']]);
        }

        $incidents = $this->api->incidents($monitorId, $apiKey);
        $uptime = $this->api->uptime($monitorId, $apiKey);
        $deepScan = $this->api->deepScan($monitorId, $apiKey);
        $token = $this->api->tokenInfo($apiKey);
        $statusPages = $this->api->statusPages($apiKey);

        // The account is persisted, not just rendered: a later panel load with
        // a failing /token/info still has to be able to name the account this
        // store reports into.
        if ($token['ok']) {
            $this->config->saveAccount(
                isset($token['data']['account']['email']) ? (string)$token['data']['account']['email'] : null,
                isset($token['data']['team']['name']) ? (string)$token['data']['team']['name'] : null,
                isset($token['data']['team']['plan']) ? (string)$token['data']['team']['plan'] : null
            );
        }

        $result = [
            'data' => $status['data'],
            'incidents' => $incidents['ok'] ? (array)($incidents['data']['incidents'] ?? []) : [],
            'uptime' => $uptime['ok'] ? (array)($uptime['data']['dataPoints'] ?? []) : [],
            'deepScan' => $deepScan['ok'] ? $deepScan['data'] : [],
            'monitors' => $token['ok'] ? (array)($token['data']['monitors'] ?? []) : [],
            'statusPages' => $statusPages['ok'] ? (array)($statusPages['data']['statusPages'] ?? []) : [],
            'fetchedAt' => time(),
            'stale' => false,
            'error' => '',
        ];
        $this->config->saveStatusCache($result);

        return $result;
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return [
            'data' => null,
            'incidents' => [],
            'uptime' => [],
            'deepScan' => [],
            'monitors' => [],
            'statusPages' => [],
            'fetchedAt' => 0,
            'stale' => false,
            'error' => '',
        ];
    }
}
