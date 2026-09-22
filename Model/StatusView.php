<?php
/**
 * Turns PingView API payloads into a view model.
 *
 * Pure functions, no Magento and no translation: every method returns keys,
 * tones and numbers, never sentences. The block maps those keys to translated
 * labels, the template renders them. That split is what makes this logic
 * testable outside a store (Test/status-view-smoke.php).
 *
 * Nothing here recomputes backend-owned state: status comes from CT-STATUS,
 * availability from BR-AGG-01, incident state from BR-INC-01. The only numbers
 * derived here are presentation choices (which latency to show, which failed
 * security check to show first).
 *
 * Ported verbatim from presta-plugin/pingview/classes/PingViewStatusView.php
 * and kept diffable against it on purpose: CT-PARITY says the panels render
 * one product, and one product is easiest to keep when the derivations behind
 * the three panels are the same file. Only the class name, the namespace and
 * the framework guard differ. No declare(strict_types=1): the source is
 * coercion-tolerant by design (API payloads are untyped) and adding it here
 * would change behaviour rather than preserve the port.
 */

namespace PingView\Monitoring\Model;

class StatusView
{
    /** The six canonical statuses (BR-STATUS-01 / CT-STATUS). */
    const STATUSES = array('operational', 'degraded', 'partial', 'offline', 'maintenance', 'unknown');

    /**
     * Stable action-item codes the module knows how to translate. Not yet live
     * in production (quick-status does not send `code` yet), so every reader
     * of this list must treat an unrecognised or missing code as normal, not
     * as an error - see {@see attention()}.
     */
    const ACTION_CODES = array(
        'ssl_expiring',
        'domain_expiring',
        'plain_http',
        'performance_degrading',
        'availability_below_target',
    );

    /** Validation `field` names the panel has a translated label for. */
    const KNOWN_FIELDS = array(
        'locations', 'target', 'name', 'checkInterval', 'monitorType', 'heartbeatOptions', 'slowThreshold', 'sla',
    );

    /**
     * Known validation `message` texts (source-language, case/whitespace
     * normalized) mapped to a stable key the module translates. Anything not
     * in this list renders as the API sent it.
     */
    const MESSAGE_KEYS = array(
        'at least one monitoring location is required for non-heartbeat monitors' => 'locations_required',
        'target url or hostname is required for non-heartbeat monitors' => 'target_required',
        'monitor name is required' => 'name_required',
        'check interval is required for non-heartbeat monitors' => 'check_interval_required',
    );

    /**
     * Pull the new monitor out of a POST /monitors response.
     *
     * That endpoint nests it one level deeper than it looks: the envelope is
     * `{ success, data: { monitor: {...} } }`, while /magento/provision puts
     * the monitor beside the team under `data`. Reading `data.id` therefore
     * yields nothing and fails silently, which is exactly what happened on the
     * "paste an existing API key for a shop that has no monitor yet" path and
     * again when enabling the scheduler watch. Every caller reads it here so
     * there is one place to be wrong.
     *
     * @param array $result Result array from ApiClient (ok/data/error/status).
     *
     * @return array The monitor, or an empty array when the shape is not what we expect.
     */
    public static function createdMonitor($result)
    {
        return is_array($result) && isset($result['data']['monitor']) && is_array($result['data']['monitor'])
            ? $result['data']['monitor']
            : array();
    }

    /**
     * Values the backend no longer writes but old rows may still carry.
     * Mapping them beats rendering "up" as unknown.
     */
    public static function legacyAliases()
    {
        return array(
            'online' => 'operational',
            'healthy' => 'operational',
            'up' => 'operational',
            'down' => 'offline',
            'failed' => 'offline',
            'missed' => 'offline',
            'major_outage' => 'offline',
            'partial_outage' => 'partial',
            'under_maintenance' => 'maintenance',
        );
    }

    /**
     * @param mixed $status
     *
     * @return string One of self::STATUSES.
     */
    public static function normalizeStatus($status)
    {
        $key = strtolower(trim((string) $status));
        $aliases = self::legacyAliases();

        if (isset($aliases[$key])) {
            $key = $aliases[$key];
        }

        return in_array($key, self::STATUSES, true) ? $key : 'unknown';
    }

    /**
     * Accent for a status. Colour never travels alone: the template pairs this
     * with an icon and the status word.
     *
     * @param string $status
     *
     * @return string ok|warn|bad|neutral
     */
    public static function tone($status)
    {
        switch (self::normalizeStatus($status)) {
            case 'operational':
                return 'ok';
            case 'offline':
                return 'bad';
            case 'degraded':
            case 'partial':
                return 'warn';
            default:
                return 'neutral';
        }
    }

    /**
     * Response time is the median across locations, not the last check.
     *
     * lastLatency is whichever location happened to write most recently, so one
     * probe on a slow path became "your" response time and coloured the tile
     * red for a shop every other location read as fast. A median needs half the
     * locations to agree before it moves. Falls back to lastLatency when fewer
     * than two locations reported.
     *
     * @param array    $locations
     * @param int|null $fallback   lastLatency from quick-status.
     *
     * @return array{value: int|null, count: int, from_locations: bool}
     */
    public static function responseLatency(array $locations, $fallback = null)
    {
        $values = array();
        foreach ($locations as $location) {
            if (isset($location['latency']) && is_numeric($location['latency']) && $location['latency'] > 0) {
                $values[] = (int) $location['latency'];
            }
        }

        if (count($values) < 2) {
            // A failed check reports 0 ms, which is not a fast response: it is
            // the absence of one. Showing "0 ms" in the fast colour next to an
            // offline store is the panel contradicting itself.
            return array(
                'value' => (is_numeric($fallback) && $fallback > 0) ? (int) $fallback : null,
                'count' => count($values),
                'from_locations' => false,
            );
        }

        sort($values);
        $mid = (int) floor(count($values) / 2);
        $median = (count($values) % 2 === 1)
            ? $values[$mid]
            : (int) round(($values[$mid - 1] + $values[$mid]) / 2);

        return array('value' => $median, 'count' => count($values), 'from_locations' => true);
    }

    /**
     * @param int|null $ms
     *
     * @return string ok|warn|bad|neutral
     */
    public static function latencyTone($ms)
    {
        if ($ms === null) {
            return 'neutral';
        }

        if ($ms > 1500) {
            return 'bad';
        }

        return $ms > 500 ? 'warn' : 'ok';
    }

    /**
     * Why a location is not simply answering, as a key the panel translates.
     *
     * "Degraded" is a state, not a cause. `rawState` comes off the check row and
     * separates the three cases a merchant would act on differently: answering
     * but slow, not answering in time, and not answering at all. Anything the
     * API has not sent - including a monitor whose rows predate `rawState` -
     * falls back to 'status', meaning "print the canonical status label",
     * never to a guess.
     *
     * @param string      $lifecycle reporting|pending|retired
     * @param string|null $rawState  rawState from the check row.
     * @param string      $status    Canonical monitor status.
     *
     * @return string retired|pending|slow|timeout|status, or '' when simply operational
     */
    public static function locationReason($lifecycle, $rawState, $status)
    {
        if ($lifecycle === 'retired') {
            return 'retired';
        }

        if ($lifecycle === 'pending') {
            return 'pending';
        }

        if ($rawState === 'SLOW_SUCCESS') {
            return 'slow';
        }

        if ($rawState === 'TIMEOUT') {
            return 'timeout';
        }

        return $status === 'operational' ? '' : 'status';
    }

    /**
     * Response time over the same 24 hours the availability blocks cover.
     *
     * `dataPoints[].latency` already arrives with the availability read and was
     * being discarded; drawing it costs no extra request and no charting
     * library. Null below two points: one dot is not a trend, and an empty
     * chart reads as a broken one.
     *
     * ponytail: one averaged series, because that is what the aggregate
     * carries. A per-location chart needs a different response shape, not a
     * bigger SVG.
     *
     * @param array    $dataPoints dataPoints from /uptime.
     * @param int|null $threshold  slowThreshold, for the dashed guide line.
     *
     * @return array{points: string, low: int, peak: int, width: int, height: int,
     *               threshold_y: float|null}|null
     */
    public static function sparkline(array $dataPoints, $threshold = null)
    {
        $values = array();
        foreach ($dataPoints as $point) {
            if (is_array($point) && isset($point['latency']) && is_numeric($point['latency']) && $point['latency'] > 0) {
                $values[] = (int) $point['latency'];
            }
        }

        if (count($values) < 2) {
            return null;
        }

        $width = 240;
        $height = 40;
        $max = max($values);
        if ($threshold !== null) {
            $max = max($max, (int) $threshold);
        }
        $max = max($max, 1);

        $step = $width / (count($values) - 1);
        $points = array();
        foreach ($values as $index => $value) {
            $points[] = round($index * $step, 1) . ',' . round($height - (($value / $max) * $height), 1);
        }

        return array(
            'points' => implode(' ', $points),
            'low' => min($values),
            'peak' => max($values),
            'width' => $width,
            'height' => $height,
            'threshold_y' => ($threshold !== null && (int) $threshold <= $max)
                ? round($height - (((int) $threshold / $max) * $height), 1)
                : null,
        );
    }

    /**
     * Everything else this site has under watch: monitors created in the
     * PingView app, or pointed at addresses page discovery cannot propose (a
     * campaign landing, an old CMS page). Without this the panel showed four
     * candidate pages while nine monitors ran against the same host, and the
     * merchant had no way to see the other five from the back office.
     *
     * Scoped to this shop's host, never to the team: GET /monitors answers with
     * every monitor the team owns, and an agency account holds other clients'
     * shops - listing those here would leak them into an unrelated back office.
     *
     * @param array  $monitors   monitors array from GET /monitors.
     * @param string $siteHost   Host of this shop.
     * @param array  $candidates monitoredPageCandidates() output, already listed above.
     * @param string $currentId  This shop's own monitor id.
     *
     * @return array<int, array{id: string, name: string, path: string, status: string, latency: int|null}>
     */
    public static function otherMonitorRows(array $monitors, $siteHost, array $candidates, $currentId)
    {
        $host = strtolower((string) $siteHost);
        if ($host === '') {
            return array();
        }

        $shown = array();
        foreach ($candidates as $candidate) {
            if (!empty($candidate['url'])) {
                $shown[self::normalizeUrl($candidate['url'])] = true;
            }
        }

        $rows = array();
        foreach ($monitors as $monitor) {
            if (!is_array($monitor) || empty($monitor['target']) || !is_string($monitor['target'])) {
                continue;
            }

            if (isset($monitor['id']) && (string) $monitor['id'] === (string) $currentId) {
                continue;
            }

            $targetHost = strtolower((string) parse_url($monitor['target'], PHP_URL_HOST));
            if ($targetHost !== $host) {
                continue;
            }

            // Already on screen in the list above; two lists claiming the same
            // monitor is how a count stops matching what is visible.
            if (isset($shown[self::normalizeUrl($monitor['target'])])) {
                continue;
            }

            $path = (string) parse_url($monitor['target'], PHP_URL_PATH);

            $rows[] = array(
                'id' => isset($monitor['id']) ? (string) $monitor['id'] : '',
                'name' => isset($monitor['name']) ? (string) $monitor['name'] : $monitor['target'],
                'path' => ($path === '' || $path === '/') ? '/' : $path,
                'status' => self::normalizeStatus(isset($monitor['status']) ? $monitor['status'] : 'unknown'),
                'latency' => (isset($monitor['lastLatency']) && is_numeric($monitor['lastLatency']) && $monitor['lastLatency'] > 0)
                    ? (int) $monitor['lastLatency']
                    : null,
            );
        }

        return $rows;
    }

    /**
     * Certificate state, told honestly.
     *
     * A shop that serves https has a certificate, so "no TLS" can only be true
     * when the shop itself runs on plain http. When shop and monitor disagree
     * the monitor is still checking the old address: a stale target, not a shop
     * without TLS. Reporting the second as the first is how certificate
     * monitoring goes quiet after an http -> https move.
     *
     * @param array|null $ssl          ssl block from quick-status.
     * @param bool       $shopIsHttps
     * @param string     $monitorTarget
     *
     * @return array{state: string, days: int|null}
     *               state: stale|no_https|expired|expiring|valid|unknown
     */
    public static function sslState($ssl, $shopIsHttps, $monitorTarget)
    {
        $target = (string) $monitorTarget;

        if ($shopIsHttps && $target !== '' && stripos($target, 'https://') !== 0) {
            return array('state' => 'stale', 'days' => null);
        }

        if (!is_array($ssl)) {
            return array('state' => 'unknown', 'days' => null);
        }

        if (!$shopIsHttps && array_key_exists('hasSSL', $ssl) && !$ssl['hasSSL']) {
            return array('state' => 'no_https', 'days' => null);
        }

        $days = null;
        if (isset($ssl['daysUntilExpiry']) && is_numeric($ssl['daysUntilExpiry'])) {
            $days = (int) $ssl['daysUntilExpiry'];
        } elseif (!empty($ssl['expirationDate'])) {
            $expires = strtotime($ssl['expirationDate']);
            if ($expires !== false) {
                $days = (int) floor(($expires - time()) / 86400);
            }
        }

        if (!empty($ssl['isExpired'])) {
            return array('state' => 'expired', 'days' => $days);
        }

        if ($days !== null && $days > 0 && $days < 30) {
            return array('state' => 'expiring', 'days' => $days);
        }

        return $days === null
            ? array('state' => 'unknown', 'days' => null)
            : array('state' => 'valid', 'days' => $days);
    }

    /**
     * @param array|null $domainInfo
     *
     * @return array{state: string, days: int|null} state: expired|urgent|soon|active|unknown
     */
    public static function domainState($domainInfo)
    {
        if (!is_array($domainInfo)) {
            return array('state' => 'unknown', 'days' => null);
        }

        if (!empty($domainInfo['isExpired'])) {
            return array('state' => 'expired', 'days' => null);
        }

        if (!isset($domainInfo['daysUntilExpiry']) || !is_numeric($domainInfo['daysUntilExpiry'])) {
            return array('state' => 'unknown', 'days' => null);
        }

        $days = (int) $domainInfo['daysUntilExpiry'];

        if ($days < 30) {
            return array('state' => 'urgent', 'days' => $days);
        }

        return $days < 60
            ? array('state' => 'soon', 'days' => $days)
            : array('state' => 'active', 'days' => $days);
    }

    /**
     * Availability blocks for the 24h chart, one per bucket.
     *
     * @param array $dataPoints dataPoints from /uptime.
     *
     * @return array<int, array{tone: string, status: string, timestamp: string}>
     */
    public static function uptimeBlocks(array $dataPoints)
    {
        $blocks = array();

        foreach ($dataPoints as $point) {
            $raw = isset($point['status']) ? (string) $point['status'] : 'unknown';

            // 'no-data' is a real bucket state from the aggregator: no checks ran.
            // It is not a status value, so it never goes through normalizeStatus.
            if ($raw === 'no-data') {
                $blocks[] = array(
                    'tone' => 'empty',
                    'status' => 'no-data',
                    'timestamp' => isset($point['timestamp']) ? (string) $point['timestamp'] : '',
                );
                continue;
            }

            $status = self::normalizeStatus($raw);
            $tone = self::tone($status);

            $blocks[] = array(
                'tone' => $tone === 'neutral' ? 'empty' : $tone,
                'status' => $status,
                'timestamp' => isset($point['timestamp']) ? (string) $point['timestamp'] : '',
            );
        }

        return $blocks;
    }

    /**
     * Action items, ordered so the thing that can actually break the shop is first.
     *
     * `title`/`description`/`action` are always the API's own English sentence,
     * so a caller that never looks at `code` renders exactly what it renders
     * today. `code` and `values` are the stable, translatable alternative: a
     * caller may use them to build its own sentence, but must fall back to the
     * three fields above whenever `code` is null - which is every row until
     * quick-status starts sending it.
     *
     * @param array $items actionItems from quick-status.
     *
     * @return array{items: array, count: int, tone: string, top: array|null}
     */
    public static function attention(array $items)
    {
        $rank = array('high' => 0, 'medium' => 1, 'low' => 2);
        $ordered = array();

        foreach ($items as $item) {
            if (!is_array($item) || empty($item['title'])) {
                continue;
            }

            $priority = isset($item['priority']) ? (string) $item['priority'] : 'low';
            $code = (isset($item['code']) && is_string($item['code']) && in_array($item['code'], self::ACTION_CODES, true))
                ? $item['code']
                : null;

            $ordered[] = array(
                'priority' => isset($rank[$priority]) ? $priority : 'low',
                'title' => (string) $item['title'],
                'description' => isset($item['description']) ? (string) $item['description'] : '',
                'action' => isset($item['action']) ? (string) $item['action'] : '',
                'tone' => $priority === 'high' ? 'bad' : ($priority === 'medium' ? 'warn' : 'info'),
                'code' => $code,
                'values' => ($code !== null && isset($item['values']) && is_array($item['values'])) ? $item['values'] : array(),
            );
        }

        usort($ordered, function ($a, $b) use ($rank) {
            return $rank[$a['priority']] - $rank[$b['priority']];
        });

        $count = count($ordered);
        $hasHigh = $count > 0 && $ordered[0]['priority'] === 'high';

        return array(
            'items' => $ordered,
            'count' => $count,
            'tone' => $count === 0 ? 'ok' : ($hasHigh ? 'bad' : 'warn'),
            'top' => $count > 0 ? $ordered[0] : null,
        );
    }

    /**
     * Mozilla HTTP Observatory results.
     *
     * The grade is the headline, the failed tests are the product: a grade on
     * its own is a verdict, a failed test is work someone can do. Failures are
     * ordered by the points they give back, because that is the order to fix
     * them in.
     *
     * @param array|null $observatory httpObservatory block from /deep-scan.
     *
     * @return array{grade: string, score: int|null, tone: string, failed: array, passed: int, points_back: int, total: int}
     */
    public static function observatory($observatory)
    {
        $empty = array(
            'grade' => '',
            'score' => null,
            'tone' => 'neutral',
            'failed' => array(),
            'failed_count' => 0,
            'passed' => 0,
            'points_back' => 0,
            'total' => 0,
        );

        if (!is_array($observatory)) {
            return $empty;
        }

        $raw = isset($observatory['testResults']) ? $observatory['testResults'] : null;
        $failed = array();
        $passed = 0;
        $pointsBack = 0;

        if (is_array($raw)) {
            foreach ($raw as $key => $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                // Results arrive either as a list or as a map keyed by test name,
                // depending on scan age. One shape out.
                $name = isset($entry['testName']) ? (string) $entry['testName'] : (is_string($key) ? $key : '');
                if ($name === '') {
                    continue;
                }

                if (!empty($entry['passed']) || !empty($entry['pass'])) {
                    ++$passed;
                    continue;
                }

                // Observatory scores a failure as a negative modifier; the points
                // back on the board are its absolute value.
                $points = isset($entry['score']) ? abs((int) $entry['score']) : 0;
                $failed[] = array('name' => $name, 'points' => $points);
                $pointsBack += $points;
            }
        }

        usort($failed, function ($a, $b) {
            return $b['points'] - $a['points'];
        });

        $grade = isset($observatory['grade']) ? (string) $observatory['grade'] : '';
        $letter = $grade === '' ? '' : strtoupper(substr($grade, 0, 1));

        return array(
            'grade' => $grade,
            'score' => isset($observatory['score']) ? (int) $observatory['score'] : null,
            'tone' => $letter === '' ? 'neutral' : (in_array($letter, array('A', 'B'), true) ? 'ok' : ($letter === 'C' ? 'warn' : 'bad')),
            'failed' => $failed,
            'failed_count' => count($failed),
            'passed' => $passed,
            'points_back' => $pointsBack,
            'total' => $passed + count($failed),
        );
    }

    /**
     * Lighthouse scores. Mobile leads: it is the device Google scores on.
     *
     * SEO and Best practices are deliberately not returned. A red 54 next to
     * "SEO" reads as a fault this module will tell you about, and it never
     * will; they stay in the full report where the surrounding context makes
     * them mean something.
     *
     * @param array|null $lighthouse lighthouse block from /deep-scan.
     *
     * @return array{device: string, scores: array, vitals: array}|null
     */
    public static function lighthouse($lighthouse)
    {
        if (!is_array($lighthouse)) {
            return null;
        }

        $device = null;
        if (!empty($lighthouse['mobile'])) {
            $device = 'mobile';
        } elseif (!empty($lighthouse['desktop'])) {
            $device = 'desktop';
        }

        if ($device === null || !is_array($lighthouse[$device])) {
            return null;
        }

        $report = $lighthouse[$device];
        $scores = array();
        foreach (array('performance', 'accessibility') as $metric) {
            $value = (isset($report[$metric]) && is_numeric($report[$metric])) ? (int) $report[$metric] : null;
            $scores[] = array(
                'key' => $metric,
                'value' => $value,
                'tone' => self::scoreTone($value),
            );
        }

        $vitals = array();
        $shapes = array('lcp' => 'ms', 'cls' => 'ratio', 'tbt' => 'ms');
        $cwv = isset($report['coreWebVitals']) && is_array($report['coreWebVitals']) ? $report['coreWebVitals'] : array();
        foreach ($shapes as $key => $unit) {
            if (!isset($cwv[$key]) || !is_numeric($cwv[$key])) {
                continue;
            }
            $value = (float) $cwv[$key];
            $vitals[] = array(
                'key' => $key,
                'display' => $unit === 'ms' ? (string) round($value) . 'ms' : number_format($value, 2),
            );
        }

        return array('device' => $device, 'scores' => $scores, 'vitals' => $vitals);
    }

    /**
     * Lighthouse colour bands: 90+ good, 50+ needs work, below that poor.
     *
     * @param int|null $score
     *
     * @return string
     */
    public static function scoreTone($score)
    {
        if ($score === null) {
            return 'neutral';
        }

        return $score >= 90 ? 'ok' : ($score >= 50 ? 'warn' : 'bad');
    }

    /**
     * Incidents, flattened for the template.
     *
     * @param array $incidents incidents from /incidents.
     *
     * @return array<int, array{title: string, resolved: bool, start: string, minutes: int|null, hours: string|null}>
     */
    public static function incidents(array $incidents)
    {
        $rows = array();

        foreach ($incidents as $incident) {
            if (!is_array($incident)) {
                continue;
            }

            $seconds = isset($incident['durationSeconds']) ? (int) $incident['durationSeconds'] : 0;
            $rows[] = array(
                'title' => isset($incident['title']) ? (string) $incident['title'] : '',
                'resolved' => isset($incident['status']) && $incident['status'] === 'resolved',
                'start' => isset($incident['startTime']) ? (string) $incident['startTime'] : '',
                'minutes' => ($seconds > 0 && $seconds < 3600) ? (int) round($seconds / 60) : null,
                'hours' => $seconds >= 3600 ? number_format($seconds / 3600, 1) : null,
            );
        }

        return $rows;
    }

    /**
     * Compact relative time: "just now", "12 min ago", "3 h ago", "2 d ago".
     *
     * Returns a unit key plus a number rather than a sentence, so the template
     * owns the wording and the translator owns the string.
     *
     * @param string   $iso
     * @param int|null $now Injectable for tests.
     *
     * @return array{unit: string, value: int}|null unit: now|min|hour|day
     */
    public static function relativeTime($iso, $now = null)
    {
        $timestamp = strtotime((string) $iso);
        if ($timestamp === false) {
            return null;
        }

        $diff = ($now === null ? time() : (int) $now) - $timestamp;

        if ($diff < 60) {
            return array('unit' => 'now', 'value' => 0);
        }

        if ($diff < 3600) {
            return array('unit' => 'min', 'value' => (int) floor($diff / 60));
        }

        if ($diff < 86400) {
            return array('unit' => 'hour', 'value' => (int) floor($diff / 3600));
        }

        return array('unit' => 'day', 'value' => (int) floor($diff / 86400));
    }

    /**
     * Location ids a monitor is checked from, read out of a quick-status
     * payload. Lets a new monitor inherit the same spread as an existing one
     * instead of falling back to the backend's single default location
     * (`POST /monitors` with no `locations` key).
     *
     * @param array $data quick-status payload; only `locations` is read.
     *
     * @return array<int, string> Deduplicated, reindexed list of location ids.
     */
    public static function monitorLocationIds($data)
    {
        if (!is_array($data) || !isset($data['locations']) || !is_array($data['locations'])) {
            return array();
        }

        $ids = array();
        foreach ($data['locations'] as $location) {
            if (!is_array($location) || !isset($location['id']) || !is_string($location['id']) || $location['id'] === '') {
                continue;
            }

            $ids[$location['id']] = true;
        }

        return array_keys($ids);
    }

    /**
     * Normalizes a URL to a comparable key: lower-case scheme and host, no
     * trailing slash, no query string. "https://Shop.example/Cart/" and
     * "https://shop.example/cart?x=1" must compare equal, or a page that is
     * already monitored gets offered again.
     *
     * @param string $url
     *
     * @return string
     */
    public static function normalizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
        $host = strtolower($parts['host']);
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        // The query survives only when there is no path to identify the page
        // by. A shop without friendly URLs addresses its cart and its checkout
        // as "/?controller=..." - same path, different page - and dropping the
        // query collapsed both onto the home page's key, which reported them as
        // already monitored and hid their checkboxes.
        // See wordpress-plugin/docs/specs/2026-09-09-monitored-pages-multi-monitor-visibility.md (DEC-2)
        $query = ($path === '' && isset($parts['query']) && $parts['query'] !== '') ? '?' . $parts['query'] : '';

        return $scheme . '://' . $host . $path . $query;
    }

    /**
     * Shop pages the module discovered, matched against what PingView already
     * monitors. The module is the only thing that knows these addresses; a
     * monitor pointed at the home page alone leaves the rest unwatched.
     *
     * @param array $candidates       array<int, array{key: string, label: string, url: string, default_checked: bool}>
     * @param array $monitoredTargets Raw target URLs from GET /monitors.
     *
     * @return array<int, array{key: string, label: string, url: string, monitored: bool, default_checked: bool}>
     */
    public static function monitoredPageRows(array $candidates, array $monitoredTargets)
    {
        $monitored = array();
        foreach ($monitoredTargets as $target) {
            if (is_string($target) && $target !== '') {
                $monitored[self::normalizeUrl($target)] = true;
            }
        }

        $rows = array();
        foreach ($candidates as $candidate) {
            if (empty($candidate['url'])) {
                continue;
            }

            $rows[] = array(
                'key' => isset($candidate['key']) ? (string) $candidate['key'] : '',
                'label' => isset($candidate['label']) ? (string) $candidate['label'] : '',
                'url' => (string) $candidate['url'],
                'monitored' => isset($monitored[self::normalizeUrl($candidate['url'])]),
                'default_checked' => !empty($candidate['default_checked']),
            );
        }

        return $rows;
    }

    /**
     * The team's public status page, chosen for the badge card.
     *
     * A team may have several status pages; the first public one is enough,
     * this card is not a status page manager. Only ever built from a page
     * with isPublic true, the badge is never offered for a page nobody can
     * see (BR-UI parity with the WordPress plugin).
     *
     * @param array  $statusPages statusPages array from GET /status-pages.
     * @param string $appBase     e.g. https://pingview.app
     * @param string $locale      pl|en
     *
     * @return array{slug: string, name: string, badge_url: string, page_url: string}|null
     */
    public static function statusBadge(array $statusPages, $appBase, $locale)
    {
        $lang = $locale === 'pl' ? 'pl' : 'en';
        $base = rtrim((string) $appBase, '/');

        foreach ($statusPages as $page) {
            if (!is_array($page) || empty($page['isPublic']) || empty($page['slug'])) {
                continue;
            }

            $slug = (string) $page['slug'];

            return array(
                'slug' => $slug,
                'name' => !empty($page['name']) ? (string) $page['name'] : $slug,
                'badge_url' => $base . '/api/public/badge/' . rawurlencode($slug) . '?lang=' . $lang . '&theme=light',
                'page_url' => $base . '/' . $lang . '/status/' . rawurlencode($slug),
            );
        }

        return null;
    }

    /**
     * The line to paste into a hosting cron table.
     *
     * Magento's own scheduler is already an OS cron entry running
     * `bin/magento cron:run`, so the module must not add a second scheduler of
     * its own to ping this URL - it would then be reporting on the very thing
     * that stopped. This is the external half of scheduler-watch: a ready line
     * for the same crontab that already runs Magento's cron, independent of
     * whether Magento's cron still works.
     *
     * @param string $heartbeatUrl
     *
     * @return string
     */
    public static function schedulerCronLine($heartbeatUrl)
    {
        return '*/10 * * * * curl -fsS -m 10 "' . $heartbeatUrl . '" > /dev/null';
    }

    /**
     * Field-level validation details from the API error envelope
     * ({success:false, error:{code, message, details}}), reduced to what a
     * template can safely print. Anything that is not {field?, message}
     * shaped is dropped rather than rendered as "Array" or left to throw.
     *
     * `field`/`message` are always the API's own English text, so a caller
     * that ignores `field_key`/`message_key` renders exactly what rendered
     * before. The `_key` fields are the stable, translatable alternative and
     * are null whenever this row is not one the panel recognises.
     *
     * @param mixed $details error.details from the API envelope, or anything else.
     *
     * @return array<int, array{field: string, message: string, field_key: string|null, message_key: string|null}>
     */
    public static function sanitizeDetails($details)
    {
        if (!is_array($details)) {
            return array();
        }

        $rows = array();
        foreach ($details as $item) {
            if (!is_array($item) || empty($item['message']) || !is_string($item['message'])) {
                continue;
            }

            $field = (isset($item['field']) && is_string($item['field'])) ? $item['field'] : '';
            $message = $item['message'];
            $normalizedMessage = strtolower(trim($message));

            $rows[] = array(
                'field' => $field,
                'message' => $message,
                'field_key' => in_array($field, self::KNOWN_FIELDS, true) ? $field : null,
                'message_key' => isset(self::MESSAGE_KEYS[$normalizedMessage]) ? self::MESSAGE_KEYS[$normalizedMessage] : null,
            );
        }

        return $rows;
    }

    /**
     * Whether the API's own top-level error message is worth a second line
     * under the field-level details, or whether it would just repeat what the
     * details already say.
     *
     * Kept unless: details exist and the message is a content-free generic
     * ("Validation failed"), or the message is exactly the first detail
     * ("field: text" or bare "text") restated - which is what a validation
     * error with one failing field looks like once the API's own message
     * includes the field name.
     *
     * @param mixed $message error.error from the API envelope (expected string|null).
     * @param array $details sanitizeDetails() output.
     *
     * @return bool
     */
    public static function detailCarriesInfo($message, array $details)
    {
        if (!is_string($message) || trim($message) === '') {
            return false;
        }

        $normalized = strtolower(trim($message));

        if (empty($details)) {
            return true;
        }

        $generic = array('validation failed', 'validation error', 'bad request', 'request failed');
        if (in_array($normalized, $generic, true)) {
            return false;
        }

        $first = $details[0];
        $firstField = (isset($first['field']) && is_string($first['field'])) ? $first['field'] : '';
        $firstMessage = (isset($first['message']) && is_string($first['message'])) ? $first['message'] : '';
        $withField = strtolower(trim($firstField !== '' ? $firstField . ': ' . $firstMessage : $firstMessage));
        $bare = strtolower(trim($firstMessage));

        if ($normalized === $withField || $normalized === $bare) {
            return false;
        }

        return true;
    }

    /**
     * Account identity card data: which PingView account this site reports
     * into. The address is never invented here or anywhere upstream of it -
     * it is read from the API verbatim (data.user.email at provision time,
     * the optional data.account.email from GET /token/info when connecting an
     * existing key) and never falls back to the Magento admin user's own
     * email, which is a different person than whoever owns the PingView
     * account (CT-PARITY account-identity).
     *
     * $teamPlan is title-cased for display; that is formatting, not
     * translation, so it happens here rather than forcing the module class to
     * carry a label for every plan slug the backend may ever add.
     *
     * @param string|null $email    Stored pingview/general/account_email ('' when unset).
     * @param string|null $teamName Stored pingview/general/team_name.
     * @param string|null $teamPlan Stored pingview/general/team_plan.
     *
     * @return array{email: string|null, team_name: string|null, team_plan: string|null}
     */
    public static function accountIdentity($email, $teamName, $teamPlan)
    {
        return array(
            'email' => (is_string($email) && $email !== '') ? $email : null,
            'team_name' => (is_string($teamName) && $teamName !== '') ? $teamName : null,
            'team_plan' => (is_string($teamPlan) && trim($teamPlan) !== '') ? ucfirst(trim($teamPlan)) : null,
        );
    }

    /**
     * Wraps a direct app URL behind the sign-in page when the account's email
     * is known, so every deep link this module hands out actually works: these
     * accounts have no password, sign-in is a link mailed to an address, and a
     * direct link into the app for a merchant with no session just bounces off
     * a login wall with nothing to fill in. A session that is already signed
     * in is bounced straight through to callbackUrl by the app, so this costs
     * an already-signed-in merchant nothing.
     *
     * Falls back to the direct URL untouched when the email is not known:
     * there is nothing to pre-fill, and no address to send the merchant's
     * eventual session to either.
     *
     * @param string      $directUrl Destination the module used to link to before this existed.
     * @param string|null $email     Account email, or null when not known.
     * @param string      $appBase   e.g. https://pingview.app
     * @param string      $locale    pl|en
     *
     * @return string
     */
    public static function signInUrl($directUrl, $email, $appBase, $locale)
    {
        if (!is_string($email) || $email === '') {
            return $directUrl;
        }

        $lang = $locale === 'pl' ? 'pl' : 'en';
        $base = rtrim((string) $appBase, '/');

        // callbackUrl is a path, not the full address: parse_url() strips the
        // scheme and host the sign-in page already runs on. Falls back to the
        // raw value on the rare URL parse_url cannot make sense of, rather
        // than dropping the destination entirely.
        $path = parse_url((string) $directUrl, PHP_URL_PATH);
        $callback = (is_string($path) && $path !== '') ? $path : (string) $directUrl;

        return $base . '/' . $lang . '/auth/signin?email=' . rawurlencode($email) . '&callbackUrl=' . rawurlencode($callback);
    }

    /**
     * Minutes until the next check, or null when the moment has passed or is unknown.
     *
     * @param string|null $iso
     * @param int|null    $now
     *
     * @return int|null
     */
    public static function minutesUntil($iso, $now = null)
    {
        if (empty($iso)) {
            return null;
        }

        $timestamp = strtotime((string) $iso);
        if ($timestamp === false) {
            return null;
        }

        $minutes = (int) ceil(($timestamp - ($now === null ? time() : (int) $now)) / 60);

        return $minutes > 0 ? $minutes : null;
    }
}
