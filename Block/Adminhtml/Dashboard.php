<?php
declare(strict_types=1);

namespace PingView\Monitoring\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PingView\Monitoring\Model\ApiClient;
use PingView\Monitoring\Model\Config;
use PingView\Monitoring\Model\SchedulerDiagnosis;
use PingView\Monitoring\Model\ShopProfile;
use PingView\Monitoring\Model\StatusPresentation;
use PingView\Monitoring\Model\StatusService;
use PingView\Monitoring\Model\StatusView;

/**
 * View model for the admin panel.
 *
 * Every derivation lives in {@see StatusView}, shared verbatim with the
 * PrestaShop panel. This class only feeds it store facts and turns its keys
 * into things a template can print, so the three CMS panels cannot drift apart
 * in what they conclude from the same payload (CT-PARITY).
 *
 * Not final: blocks are common plugin targets, and an interceptor cannot
 * extend a final class.
 */
class Dashboard extends Template
{
    private ?array $snapshot = null;

    public function __construct(
        Template\Context $context,
        private readonly Config $config,
        private readonly StatusService $statusService,
        private readonly StatusPresentation $presentation,
        private readonly ShopProfile $shopProfile,
        private readonly SchedulerDiagnosis $scheduler,
        private readonly Session $authSession,
        private readonly AuthorizationInterface $authorization,
        private readonly TimezoneInterface $timezone,
        private readonly ResolverInterface $localeResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /* Connection state ---------------------------------------------------- */

    public function isConnected(): bool
    {
        return $this->config->isConnected();
    }

    /**
     * Whether to render the setup screen with the API key form already open:
     * the merchant arrived here from a provisioning attempt that PingView
     * refused because the account or the monitor already exists.
     */
    public function shouldOpenConnect(): bool
    {
        return (bool)$this->getRequest()->getParam('connect');
    }

    public function canManage(): bool
    {
        return $this->authorization->isAllowed('PingView_Monitoring::manage');
    }

    public function getSuggestedEmail(): string
    {
        $user = $this->authSession->getUser();
        return $user ? (string)$user->getEmail() : '';
    }

    public function getStoreUrl(): string
    {
        return $this->shopProfile->storeUrl();
    }

    public function getStoreHost(): string
    {
        return (string)parse_url($this->getStoreUrl(), PHP_URL_HOST);
    }

    public function isStoreHttps(): bool
    {
        return stripos($this->getStoreUrl(), 'https://') === 0;
    }

    public function hasStoreUrlChanged(): bool
    {
        return $this->config->getConnectedStoreUrl() !== ''
            && $this->config->getConnectedStoreUrl() !== $this->getStoreUrl();
    }

    public function getVersion(): string
    {
        return ApiClient::VERSION;
    }

    /* Snapshot ------------------------------------------------------------- */

    public function getSnapshot(): array
    {
        return $this->snapshot ??= $this->statusService->get();
    }

    /** @return array<string, mixed> */
    public function getStatus(): array
    {
        $data = $this->getSnapshot()['data'] ?? null;
        return is_array($data) ? $data : [];
    }

    public function hasResults(): bool
    {
        return !empty($this->getStatus()['lastCheck']);
    }

    public function describeStatus(?string $status): array
    {
        return $this->presentation->describe($status ?? 'unknown');
    }

    public function getMonitorTarget(): string
    {
        return (string)($this->getStatus()['target'] ?? $this->getStoreHost());
    }

    public function getCheckInterval(): int
    {
        return (int)($this->getStatus()['checkInterval'] ?? 5);
    }

    /* Cards ---------------------------------------------------------------- */

    /** @return array{value: int|null, count: int, from_locations: bool} */
    public function getResponseLatency(): array
    {
        $status = $this->getStatus();

        return StatusView::responseLatency(
            is_array($status['locations'] ?? null) ? $status['locations'] : [],
            $status['lastLatency'] ?? null
        );
    }

    public function getLatencyTone(?int $ms): string
    {
        return StatusView::latencyTone($ms);
    }

    public function getUptime(string $window): ?float
    {
        $value = $this->getStatus()['availability'][$window] ?? null;
        return is_numeric($value) ? (float)$value : null;
    }

    /** @return array{items: array, count: int, tone: string, top: array|null} */
    public function getAttention(): array
    {
        $items = $this->getStatus()['actionItems'] ?? [];
        return StatusView::attention(is_array($items) ? $items : []);
    }

    /** @return array<int, array{tone: string, status: string, timestamp: string}> */
    public function getUptimeBlocks(): array
    {
        return StatusView::uptimeBlocks((array)($this->getSnapshot()['uptime'] ?? []));
    }

    /**
     * @return array<int, array{id: string, label: string, flag: string, tone: string,
     *     status: string, status_label: string, latency: int|null, value: string, mono: bool}>
     */
    public function getLocations(): array
    {
        $reasons = [
            'retired' => __('probe retired'),
            'pending' => __('no result yet'),
            'slow' => __('answering, but slow'),
            'timeout' => __('timed out'),
        ];

        $rows = [];
        foreach ((array)($this->getStatus()['locations'] ?? []) as $location) {
            if (!is_array($location)) {
                continue;
            }

            $status = StatusView::normalizeStatus($location['status'] ?? 'unknown');
            // Same rule as StatusView::responseLatency(): a failed check
            // reports 0 ms, which is no response time at all.
            $latency = isset($location['latency']) && is_numeric($location['latency']) && $location['latency'] > 0
                ? (int)$location['latency']
                : null;
            $statusLabel = (string)__($this->presentation->describe($status)['label']);
            $lifecycle = (string)($location['lifecycle'] ?? 'reporting');
            $reasonKey = StatusView::locationReason(
                $lifecycle,
                isset($location['rawState']) ? (string)$location['rawState'] : null,
                $status
            );
            $reason = '';
            if ($reasonKey === 'status') {
                $reason = $statusLabel;
            } elseif ($reasonKey !== '') {
                $reason = (string)$reasons[$reasonKey];
            }

            $rows[] = [
                'id' => (string)($location['id'] ?? ''),
                'label' => (string)($location['label'] ?? $location['id'] ?? ''),
                'flag' => (string)($location['flag'] ?? ''),
                // A probe that left the fleet, or has not reported yet, is not a
                // red store: it is an absent reading. Colouring it as a failure
                // puts a fault on screen that nobody can act on.
                'tone' => $lifecycle === 'reporting' ? StatusView::tone($status) : 'neutral',
                'status' => $status,
                'status_label' => $statusLabel,
                'latency' => $latency,
                'lifecycle' => $lifecycle,
                // The number is the answer, so it shows on every row that has
                // one. Hiding it on exactly the rows that are not operational
                // left a colour and a word where the millisecond figure was the
                // only actionable thing on screen - and made the median in the
                // KPI strip disagree with the list it was taken from.
                'value' => $latency !== null ? $latency . ' ms' : ($reason !== '' ? $reason : $statusLabel),
                'reason' => $latency !== null ? $reason : '',
                // Monospace is for the measurement. A status sentence set in it
                // reads as terminal output, not as an answer.
                'mono' => $latency !== null,
            ];
        }

        return $rows;
    }

    /**
     * Locations behind the header count.
     *
     * Whoever states a count shows every row behind it. A retired probe can
     * never produce a result, so counting it made the card header ("checked
     * from 5 locations") disagree with the median in the KPI strip ("median of
     * 4 locations") - two correct numbers arranged so that no honest reading
     * existed. An API that does not send `lifecycle` counts every row, exactly
     * as before.
     */
    public function getCountedLocations(): int
    {
        return count($this->getLocations()) - $this->getRetiredLocations();
    }

    public function getRetiredLocations(): int
    {
        $retired = 0;
        foreach ($this->getLocations() as $location) {
            if ($location['lifecycle'] === 'retired') {
                $retired++;
            }
        }

        return $retired;
    }

    /**
     * The threshold those latencies were judged against. Owned by the backend
     * (BR-NOTIF-01) and never hardcoded here.
     */
    public function getSlowThreshold(): ?int
    {
        $value = $this->getStatus()['slowThreshold'] ?? null;
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * @return array{points: string, low: int, peak: int, width: int, height: int,
     *     threshold_y: float|null}|null
     */
    public function getSparkline(): ?array
    {
        return StatusView::sparkline(
            (array)($this->getSnapshot()['uptime'] ?? []),
            $this->getSlowThreshold()
        );
    }

    /**
     * @return array<int, array{id: string, name: string, path: string, status: string,
     *     latency: int|null, tone: string, status_label: string}>
     */
    public function getOtherMonitors(): array
    {
        $rows = StatusView::otherMonitorRows(
            (array)($this->getSnapshot()['monitors'] ?? []),
            $this->getStoreHost(),
            $this->shopProfile->monitorablePages(),
            $this->config->getMonitorId()
        );

        foreach ($rows as &$row) {
            $row['tone'] = StatusView::tone($row['status']);
            $row['status_label'] = (string)__($this->presentation->describe($row['status'])['label']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Certificate state as a printable row. StatusView decides the state, this
     * only names it: the panel owns labels, the shared view model owns the
     * conclusion.
     *
     * @return array{text: \Magento\Framework\Phrase, tone: string}
     */
    public function getSslRow(): array
    {
        $ssl = StatusView::sslState(
            $this->getStatus()['ssl'] ?? null,
            $this->isStoreHttps(),
            $this->getMonitorTarget()
        );

        return match ($ssl['state']) {
            'stale' => ['text' => __('Monitor still on the old address'), 'tone' => 'warn'],
            'no_https' => ['text' => __('Plain HTTP'), 'tone' => 'bad'],
            'expired' => ['text' => __('Expired'), 'tone' => 'bad'],
            'expiring' => ['text' => __('%1 days remaining', (int)$ssl['days']), 'tone' => 'warn'],
            'valid' => ['text' => __('%1 days remaining', (int)$ssl['days']), 'tone' => 'neutral'],
            default => ['text' => __('No data yet'), 'tone' => 'neutral'],
        };
    }

    /** @return array{text: \Magento\Framework\Phrase, tone: string} */
    public function getDomainRow(): array
    {
        $domain = StatusView::domainState($this->getStatus()['domainInfo'] ?? null);

        return match ($domain['state']) {
            'expired' => ['text' => __('Expired'), 'tone' => 'bad'],
            'urgent' => ['text' => __('%1 days remaining', (int)$domain['days']), 'tone' => 'bad'],
            'soon' => ['text' => __('%1 days remaining', (int)$domain['days']), 'tone' => 'warn'],
            'active' => ['text' => __('%1 days remaining', (int)$domain['days']), 'tone' => 'neutral'],
            default => ['text' => __('No data yet'), 'tone' => 'neutral'],
        };
    }

    public function getObservatory(): array
    {
        return StatusView::observatory($this->getSnapshot()['deepScan']['httpObservatory'] ?? null);
    }

    public function getLighthouse(): ?array
    {
        return StatusView::lighthouse($this->getSnapshot()['deepScan']['lighthouse'] ?? null);
    }

    public function getIncidents(): array
    {
        return StatusView::incidents((array)($this->getSnapshot()['incidents'] ?? []));
    }

    /** @return array<int, array{key: string, label: string, url: string, monitored: bool, default_checked: bool}> */
    public function getMonitoredPages(): array
    {
        $targets = [];
        foreach ((array)($this->getSnapshot()['monitors'] ?? []) as $monitor) {
            if (is_array($monitor) && !empty($monitor['target'])) {
                $targets[] = (string)$monitor['target'];
            }
        }

        return StatusView::monitoredPageRows($this->shopProfile->monitorablePages(), $targets);
    }

    /**
     * @return array{
     *     diagnosis: array{known: bool, running: bool, pending: int, last_executed_at: string|null, overdue: bool},
     *     watch_enabled: bool,
     *     cron_line: string
     * }
     */
    public function getScheduler(): array
    {
        $heartbeat = $this->config->getSchedulerHeartbeat();

        return [
            'diagnosis' => $this->scheduler->describe(),
            'watch_enabled' => $heartbeat['monitor_id'] !== '' && $heartbeat['url'] !== '',
            'cron_line' => $heartbeat['url'] === '' ? '' : StatusView::schedulerCronLine($heartbeat['url']),
        ];
    }

    public function getStatusBadge(): ?array
    {
        $badge = StatusView::statusBadge(
            (array)($this->getSnapshot()['statusPages'] ?? []),
            $this->getAppBase(),
            $this->getAppLocale()
        );

        if ($badge === null) {
            return null;
        }

        $badge['embed'] = '<a href="' . $this->escapeHtmlAttr($badge['page_url']) . '" target="_blank" rel="noopener">'
            . '<img src="' . $this->escapeHtmlAttr($badge['badge_url']) . '" alt="' . $this->escapeHtmlAttr($badge['name']) . '"></a>';

        return $badge;
    }

    /** @return array{email: string|null, team_name: string|null, team_plan: string|null} */
    public function getAccount(): array
    {
        return $this->config->getAccount();
    }

    /* URLs ----------------------------------------------------------------- */

    public function getDashboardUrl(): string
    {
        return $this->getAppUrls()['monitor'];
    }

    /** Where an existing PingView user reads or creates the key this form asks for. */
    public function getApiKeysUrl(): string
    {
        return $this->getAppUrls()['api_keys'];
    }

    public function getNotificationsUrl(): string
    {
        return $this->getAppUrls()['notifications'];
    }

    public function getStatusPagesUrl(): string
    {
        return $this->getAppUrls()['status_pages'];
    }

    public function getReportsUrl(): string
    {
        return $this->getAppUrls()['reports'];
    }

    public function getAccountUrl(): string
    {
        return $this->getAppUrls()['account'];
    }

    /** Where the slow threshold on this monitor is actually changed. */
    public function getMonitorSettingsUrl(): string
    {
        return $this->getAppUrls()['monitor_settings'];
    }

    /**
     * Deep links into the app, team-scoped when the team is known.
     *
     * Every one is routed through the sign-in page: these accounts have no
     * password, so a direct link for a merchant with no session lands on a
     * login wall with nothing to fill in.
     *
     * The stored account email is masked (`o***r@agency.com`) whenever it came
     * from GET /token/info rather than from provisioning. That is fine to show
     * on the identity card and useless to prefill a sign-in form with, so a
     * masked address is not passed on - the merchant types their own.
     *
     * @return array<string, string>
     */
    private function getAppUrls(): array
    {
        $base = $this->getAppBase();
        $teamId = $this->config->getTeamId();
        $monitorId = rawurlencode($this->config->getMonitorId());

        if ($teamId === '') {
            $urls = [
                'monitor' => $base . '/app/monitor/' . $monitorId,
                // No team id, no team-scoped settings route. The monitor page
                // is a real destination; an /edit path under a route with no
                // team segment is a guess.
                'monitor_settings' => $base . '/app/monitor/' . $monitorId,
                'notifications' => $base . '/app/dashboard',
                'reports' => $base . '/app/dashboard',
                'status_pages' => $base . '/app/dashboard',
                'api_keys' => $base . '/app/dashboard/api-keys',
                'account' => $base . '/app/dashboard',
            ];
        } else {
            $team = $base . '/app/teams/' . rawurlencode($teamId);
            $urls = [
                'monitor' => $team . '/monitors/' . $monitorId,
                'monitor_settings' => $team . '/monitors/' . $monitorId . '/edit',
                'notifications' => $team . '/settings/notifications',
                'reports' => $team . '/reports',
                'status_pages' => $team . '/status-pages',
                'api_keys' => $team . '/settings/apiKeys',
                'account' => $team . '/settings/general',
            ];
        }

        $email = $this->config->getAccount()['email'];
        if ($email !== null && str_contains($email, '*')) {
            $email = null;
        }

        foreach ($urls as $key => $url) {
            $urls[$key] = StatusView::signInUrl($url, $email, $base, $this->getAppLocale());
        }

        return $urls;
    }

    private function getAppBase(): string
    {
        // Derived from the configured API base: a staging install must not send
        // merchants to the production dashboard.
        return preg_replace('#/api/v\d+$#', '', $this->config->getApiBase()) ?: 'https://pingview.app';
    }

    /** The app speaks two languages; anything else lands on English. */
    private function getAppLocale(): string
    {
        return str_starts_with((string)$this->localeResolver->getLocale(), 'pl') ? 'pl' : 'en';
    }

    /* Formatting ----------------------------------------------------------- */

    public function getConnectedAt(): string
    {
        return $this->formatDateTime($this->config->getConnectedAt());
    }

    public function formatDateTime(?string $value): string
    {
        if (!$value) {
            return '';
        }

        try {
            return $this->timezone->formatDateTime(new \DateTimeImmutable($value), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT);
        } catch (\Throwable) {
            return $value;
        }
    }
}
