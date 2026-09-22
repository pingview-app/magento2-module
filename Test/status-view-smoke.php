<?php
/**
 * Run with: php Test/status-view-smoke.php
 *
 * Guards the decisions the panel makes about backend data: which latency it
 * shows, whether a certificate is missing or the monitor is simply stale,
 * which security fix comes first, and which statuses are allowed to exist.
 * These are the places where a wrong answer looks plausible on screen.
 *
 * Standalone on purpose: Model/StatusView.php has no Magento dependency, so
 * this runs on bare PHP with no store, exactly like the PrestaShop plugin's
 * copy of the same suite. Keeping the two runnable the same way is what makes
 * a divergence between the panels show up as a failing assertion instead of a
 * screenshot someone notices months later.
 */

require dirname(__DIR__) . '/Model/StatusView.php';

class_alias(\PingView\Monitoring\Model\StatusView::class, 'PingViewStatusView');

function pv_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/* Status vocabulary (BR-STATUS-01 / CT-STATUS) ---------------------------- */

pv_assert(PingViewStatusView::normalizeStatus('OPERATIONAL') === 'operational', 'Case is not normalized.');
pv_assert(PingViewStatusView::normalizeStatus('up') === 'operational', 'Legacy alias not mapped.');
pv_assert(PingViewStatusView::normalizeStatus('partial_outage') === 'partial', 'Legacy alias not mapped.');
pv_assert(PingViewStatusView::normalizeStatus('exploded') === 'unknown', 'Invented status was accepted.');
pv_assert(PingViewStatusView::tone('partial') === 'warn', 'Partial must not read as operational.');
pv_assert(PingViewStatusView::tone('maintenance') === 'neutral', 'Maintenance is not a failure.');

/* Response time: median across locations, never one probe ----------------- */

$locations = array(
    array('latency' => 120),
    array('latency' => 140),
    array('latency' => 4000), // one location on a slow path
);
$response = PingViewStatusView::responseLatency($locations, 4000);
pv_assert($response['value'] === 140, 'Median must ignore the outlier, got ' . var_export($response['value'], true));
pv_assert($response['from_locations'] === true, 'Median should be reported as coming from locations.');
/* A failed check has no response time, so the tile must not read as fast. */
pv_assert(PingViewStatusView::responseLatency(array(), 0)['value'] === null, 'A zero fallback latency must not be shown as a response time.');
pv_assert(PingViewStatusView::responseLatency(array(array('latency' => 0)), 0)['value'] === null, 'Zero-latency locations must not produce a response time.');
pv_assert(PingViewStatusView::responseLatency(array(), 250)['value'] === 250, 'A real fallback latency must still be shown.');


$single = PingViewStatusView::responseLatency(array(array('latency' => 900)), 250);
pv_assert($single['value'] === 250, 'One location is not a median: fall back to lastLatency.');
pv_assert($single['from_locations'] === false, 'Single location must not claim a median.');

$none = PingViewStatusView::responseLatency(array(), null);
pv_assert($none['value'] === null, 'No data must stay null, not become zero.');
pv_assert(PingViewStatusView::latencyTone(null) === 'neutral', 'Unknown latency must not be coloured.');
pv_assert(PingViewStatusView::latencyTone(120) === 'ok', 'Fast response mis-toned.');
pv_assert(PingViewStatusView::latencyTone(2000) === 'bad', 'Slow response mis-toned.');

/* Certificate: a stale monitor is not a shop without TLS ------------------ */

$stale = PingViewStatusView::sslState(array('hasSSL' => false), true, 'http://shop.example');
pv_assert($stale['state'] === 'stale', 'https shop with an http target must read as stale, got ' . $stale['state']);

$plain = PingViewStatusView::sslState(array('hasSSL' => false), false, 'http://shop.example');
pv_assert($plain['state'] === 'no_https', 'Plain-http shop has no certificate to wait for.');

$expiring = PingViewStatusView::sslState(array('hasSSL' => true, 'daysUntilExpiry' => 12), true, 'https://shop.example');
pv_assert($expiring['state'] === 'expiring' && $expiring['days'] === 12, 'Certificate expiry window missed.');

$valid = PingViewStatusView::sslState(array('hasSSL' => true, 'daysUntilExpiry' => 200), true, 'https://shop.example');
pv_assert($valid['state'] === 'valid', 'Healthy certificate mis-read.');

$expired = PingViewStatusView::sslState(array('hasSSL' => true, 'isExpired' => true, 'daysUntilExpiry' => -3), true, 'https://shop.example');
pv_assert($expired['state'] === 'expired', 'Expired certificate mis-read.');

$noData = PingViewStatusView::sslState(null, true, 'https://shop.example');
pv_assert($noData['state'] === 'unknown', 'Missing certificate data must stay unknown, never guessed.');

/* Domain ------------------------------------------------------------------ */

pv_assert(PingViewStatusView::domainState(array('daysUntilExpiry' => 1464))['state'] === 'active', 'Long-lived domain mis-read.');
pv_assert(PingViewStatusView::domainState(array('daysUntilExpiry' => 45))['state'] === 'soon', 'Domain warning window missed.');
pv_assert(PingViewStatusView::domainState(array('daysUntilExpiry' => 10))['state'] === 'urgent', 'Domain urgency missed.');
pv_assert(PingViewStatusView::domainState(null)['state'] === 'unknown', 'Missing domain data must stay unknown.');

/* Availability blocks ----------------------------------------------------- */

$blocks = PingViewStatusView::uptimeBlocks(array(
    array('status' => 'operational', 'timestamp' => '2026-08-16T00:00:00Z'),
    array('status' => 'no-data', 'timestamp' => '2026-08-16T01:00:00Z'),
    array('status' => 'down', 'timestamp' => '2026-08-16T02:00:00Z'),
    array('status' => 'maintenance', 'timestamp' => '2026-08-16T03:00:00Z'),
));
pv_assert($blocks[0]['tone'] === 'ok', 'Operational bucket mis-toned.');
pv_assert($blocks[1]['tone'] === 'empty' && $blocks[1]['status'] === 'no-data', 'A bucket with no checks is not a status.');
pv_assert($blocks[2]['tone'] === 'bad', 'Legacy "down" bucket mis-toned.');
pv_assert($blocks[3]['tone'] === 'empty', 'Maintenance has no failure colour on the chart.');

/* Action items: the thing that can break the shop comes first ------------- */

$attention = PingViewStatusView::attention(array(
    array('priority' => 'low', 'title' => 'Low', 'description' => ''),
    array('priority' => 'high', 'title' => 'High', 'description' => ''),
    array('priority' => 'medium', 'title' => 'Medium', 'description' => ''),
    array('title' => ''), // no title: nothing to show, nothing to count
));
pv_assert($attention['count'] === 3, 'Item without a title must not be counted.');
pv_assert($attention['top']['title'] === 'High', 'Highest priority must lead.');
pv_assert($attention['tone'] === 'bad', 'A high priority item must not read as a warning.');
pv_assert(PingViewStatusView::attention(array())['tone'] === 'ok', 'No items means all clear.');

/* Observatory: both payload shapes, ordered by points back ---------------- */

$asList = PingViewStatusView::observatory(array(
    'grade' => 'D+',
    'score' => 30,
    'testResults' => array(
        array('testName' => 'x-frame-options', 'passed' => false, 'score' => -20),
        array('testName' => 'content-security-policy', 'passed' => false, 'score' => -25),
        array('testName' => 'referrer-policy', 'passed' => true, 'score' => 0),
    ),
));
pv_assert($asList['tone'] === 'bad', 'Grade D must not read as acceptable.');
pv_assert($asList['failed'][0]['name'] === 'content-security-policy', 'Fixes must be ordered by points back.');
pv_assert($asList['points_back'] === 45, 'Points back mis-summed: ' . $asList['points_back']);
pv_assert($asList['passed'] === 1 && $asList['total'] === 3, 'Pass/total count wrong.');

$asMap = PingViewStatusView::observatory(array(
    'grade' => 'B',
    'testResults' => array(
        'cookies' => array('pass' => true),
        'redirection' => array('pass' => false, 'score' => -5),
    ),
));
pv_assert($asMap['tone'] === 'ok', 'Grade B mis-toned.');
pv_assert(count($asMap['failed']) === 1 && $asMap['failed'][0]['name'] === 'redirection', 'Map-shaped results not parsed.');

$noScan = PingViewStatusView::observatory(null);
pv_assert($noScan['grade'] === '' && $noScan['total'] === 0, 'Missing scan must render as a teaser, not as a zero score.');

/* Lighthouse: mobile leads, SEO stays out --------------------------------- */

$lh = PingViewStatusView::lighthouse(array(
    'desktop' => array('performance' => 99, 'accessibility' => 100, 'seo' => 54),
    'mobile' => array('performance' => 100, 'accessibility' => 89, 'coreWebVitals' => array('lcp' => 611, 'cls' => 0, 'tbt' => 0)),
));
pv_assert($lh['device'] === 'mobile', 'Mobile is the scoring device and must lead.');
pv_assert(count($lh['scores']) === 2, 'Only performance and accessibility belong on this card.');
pv_assert($lh['scores'][1]['tone'] === 'warn', 'Accessibility 89 sits in the "needs work" band.');
pv_assert($lh['vitals'][0]['display'] === '611ms', 'LCP formatting wrong: ' . $lh['vitals'][0]['display']);
pv_assert($lh['vitals'][1]['display'] === '0.00', 'CLS must keep two decimals.');
pv_assert(PingViewStatusView::lighthouse(null) === null, 'No scan must return null, not an empty card.');

/* Relative time ----------------------------------------------------------- */

$now = 1770000000;
pv_assert(PingViewStatusView::relativeTime(gmdate('c', $now - 30), $now)['unit'] === 'now', 'Sub-minute must read as "just now".');
$mins = PingViewStatusView::relativeTime(gmdate('c', $now - 600), $now);
pv_assert($mins['unit'] === 'min' && $mins['value'] === 10, 'Minute bucket wrong.');
$days = PingViewStatusView::relativeTime(gmdate('c', $now - 172800), $now);
pv_assert($days['unit'] === 'day' && $days['value'] === 2, 'Day bucket wrong.');
pv_assert(PingViewStatusView::relativeTime('not a date') === null, 'Unparsable timestamp must not become 1970.');

pv_assert(PingViewStatusView::minutesUntil(gmdate('c', $now + 300), $now) === 5, 'Countdown wrong.');
pv_assert(PingViewStatusView::minutesUntil(gmdate('c', $now - 300), $now) === null, 'A past check is not a countdown.');
pv_assert(PingViewStatusView::minutesUntil(null) === null, 'Missing nextCheckAt must stay silent.');

/* URL normalization: comparable regardless of case, trailing slash, query --- */

pv_assert(
    PingViewStatusView::normalizeUrl('https://Shop.example/Cart/') === PingViewStatusView::normalizeUrl('https://shop.example/Cart'),
    'Case and trailing slash must not affect the comparison key.'
);
pv_assert(
    PingViewStatusView::normalizeUrl('https://shop.example/cart?ref=nav') === PingViewStatusView::normalizeUrl('https://shop.example/cart'),
    'Query string must not affect the comparison key.'
);
pv_assert(
    PingViewStatusView::normalizeUrl('http://shop.example/cart') !== PingViewStatusView::normalizeUrl('https://shop.example/cart'),
    'Scheme is part of the identity: http and https are different monitors.'
);
pv_assert(PingViewStatusView::normalizeUrl('') === '', 'Empty URL must normalize to empty, not throw.');

/* Monitor location ids: inherited from quick-status, never a gapped array -- */

$locIds = PingViewStatusView::monitorLocationIds(array(
    'locations' => array(
        array('id' => 'de-ber'),
        array('id' => 'pl-wro'),
        array('id' => 'de-ber'), // duplicate
        array('id' => ''), // empty: dropped
        array('id' => 42), // non-string: dropped
        array('label' => 'No id'), // missing id: dropped
        'not an array', // wrong shape: dropped
    ),
));
pv_assert($locIds === array('de-ber', 'pl-wro'), 'Location ids not deduplicated/filtered correctly, got ' . var_export($locIds, true));
pv_assert(array_keys($locIds) === array(0, 1), 'Result must have continuous numeric keys (a list), not a gapped array.');

pv_assert(PingViewStatusView::monitorLocationIds(array()) === array(), 'Missing locations field must yield an empty list.');
pv_assert(PingViewStatusView::monitorLocationIds(array('locations' => array())) === array(), 'Empty locations array must yield an empty list.');
pv_assert(PingViewStatusView::monitorLocationIds(null) === array(), 'Non-array payload must yield an empty list, not throw.');
pv_assert(PingViewStatusView::monitorLocationIds(array('locations' => 'not an array')) === array(), 'Non-array locations value must yield an empty list.');

/* Monitored pages: candidates matched against what is already monitored ---- */

$candidates = array(
    array('key' => 'home', 'label' => 'Home page', 'url' => 'https://shop.example', 'default_checked' => false),
    array('key' => 'cart', 'label' => 'Cart', 'url' => 'https://shop.example/cart', 'default_checked' => true),
    array('key' => 'checkout', 'label' => 'Checkout', 'url' => 'https://shop.example/order', 'default_checked' => true),
    array('key' => 'empty', 'label' => 'Ignored', 'url' => '', 'default_checked' => false),
);
$monitoredTargets = array('https://Shop.example/', 'https://shop.example/cart/?utm=x');
$rows = PingViewStatusView::monitoredPageRows($candidates, $monitoredTargets);

pv_assert(count($rows) === 3, 'A candidate with no URL must be dropped, not rendered as a blank row.');
pv_assert($rows[0]['key'] === 'home' && $rows[0]['monitored'] === true, 'Home must match the monitored target despite case and trailing slash.');
pv_assert($rows[1]['key'] === 'cart' && $rows[1]['monitored'] === true, 'Cart must match the monitored target despite a query string.');
pv_assert($rows[2]['key'] === 'checkout' && $rows[2]['monitored'] === false, 'Checkout was never monitored and must offer the checkbox.');
pv_assert($rows[2]['default_checked'] === true, 'Checkout defaults to checked.');
pv_assert($rows[0]['default_checked'] === false, 'Home does not default to checked.');
pv_assert(PingViewStatusView::monitoredPageRows(array(), array()) === array(), 'No candidates must render as an empty list, not an error.');

/* Status badge: only ever built from a page marked public ------------------ */

$noPages = PingViewStatusView::statusBadge(array(), 'https://pingview.app', 'en');
pv_assert($noPages === null, 'No status pages must return null, never an invented badge.');

$onlyPrivate = PingViewStatusView::statusBadge(
    array(array('slug' => 'private-page', 'isPublic' => false, 'name' => 'Private')),
    'https://pingview.app',
    'en'
);
pv_assert($onlyPrivate === null, 'A private-only status page must not produce a badge.');

$badge = PingViewStatusView::statusBadge(
    array(
        array('slug' => 'private-page', 'isPublic' => false, 'name' => 'Private'),
        array('slug' => 'my-shop', 'isPublic' => true, 'name' => 'My Shop'),
    ),
    'https://pingview.app/',
    'pl'
);
pv_assert($badge !== null, 'The first public page must be picked even when it is not first in the list.');
pv_assert($badge['slug'] === 'my-shop', 'Wrong status page picked.');
pv_assert(
    $badge['badge_url'] === 'https://pingview.app/api/public/badge/my-shop?lang=pl&theme=light',
    'Badge URL malformed: ' . $badge['badge_url']
);
pv_assert(
    $badge['page_url'] === 'https://pingview.app/pl/status/my-shop',
    'Status page URL malformed: ' . $badge['page_url']
);

$badgeEn = PingViewStatusView::statusBadge(
    array(array('slug' => 'my-shop', 'isPublic' => true, 'name' => 'My Shop')),
    'https://pingview.app',
    'de'
);
pv_assert($badgeEn['page_url'] === 'https://pingview.app/en/status/my-shop', 'Unsupported locale must fall back to English, not be passed through raw.');

/* Scheduler watch: cron line is a plain, unescaped shell command ---------- */

pv_assert(
    PingViewStatusView::schedulerCronLine('https://pingview.app/api/v1/heartbeat/abc123')
        === '*/10 * * * * curl -fsS -m 10 "https://pingview.app/api/v1/heartbeat/abc123" > /dev/null',
    'Scheduler cron line malformed.'
);

/* Create response: the monitor is nested under data.monitor ---------------- */

// Reading data['id'] looks right and returns nothing. On the connect path that
// showed "Could not create a monitor for this shop" after the monitor had in
// fact been created; on the scheduler path it discarded the only copy of the
// ping address. Both read through createdMonitor() now, so pin the shape.
$created = PingViewStatusView::createdMonitor(array(
    'ok' => true,
    'data' => array('monitor' => array('id' => 'mon-1', 'name' => 'PrestaShop', 'heartbeatUrl' => 'https://pingview.app/api/heartbeat/hb_x')),
));
pv_assert($created['id'] === 'mon-1', 'Monitor id not read from data.monitor.');
pv_assert($created['heartbeatUrl'] === 'https://pingview.app/api/heartbeat/hb_x', 'heartbeatUrl not read from data.monitor.');

pv_assert(
    PingViewStatusView::createdMonitor(array('ok' => true, 'data' => array('id' => 'mon-2'))) === array(),
    'The old flat shape must not be mistaken for a monitor.'
);
pv_assert(PingViewStatusView::createdMonitor(array()) === array(), 'Empty result must degrade to an empty monitor.');
pv_assert(PingViewStatusView::createdMonitor(null) === array(), 'Null result must degrade to an empty monitor.');

/* Error details: field-level API errors, reduced to what a template can print - */

$details = PingViewStatusView::sanitizeDetails(array(
    array('field' => 'locations', 'message' => 'locations must not be empty'),
    array('field' => '', 'message' => 'Generic failure'),
    array('field' => 'name'), // no message: dropped, not rendered as blank
    'not an array', // wrong shape: dropped
    array('field' => 123, 'message' => 456), // wrong types: dropped
));
pv_assert(count($details) === 2, 'Only well-shaped {field, message} entries must survive, got ' . count($details));
pv_assert($details[0]['field'] === 'locations' && $details[0]['message'] === 'locations must not be empty', 'Field/message not preserved.');
pv_assert($details[1]['field'] === '', 'A detail with no field must normalize to an empty string, not be dropped.');

pv_assert(PingViewStatusView::sanitizeDetails(null) === array(), 'Non-array details must degrade to an empty list, not throw.');
pv_assert(PingViewStatusView::sanitizeDetails('Validation failed') === array(), 'A bare string (no details sent) must not be rendered as a list.');
pv_assert(PingViewStatusView::sanitizeDetails(array()) === array(), 'An empty details array must stay empty.');

/* Known vs unknown field/message keys: unknown must fall through to the API's own text - */

$knownDetail = PingViewStatusView::sanitizeDetails(array(
    array('field' => 'locations', 'message' => '  At least one monitoring location is required for non-heartbeat monitors  '),
))[0];
pv_assert($knownDetail['field_key'] === 'locations', 'Known field must resolve to its key.');
pv_assert($knownDetail['message_key'] === 'locations_required', 'Known message must resolve despite surrounding whitespace, got ' . var_export($knownDetail['message_key'], true));

$unknownDetail = PingViewStatusView::sanitizeDetails(array(
    array('field' => 'someNewField', 'message' => 'Some new validation rule the backend added yesterday'),
))[0];
pv_assert($unknownDetail['field_key'] === null, 'Unrecognised field must not be assigned a key.');
pv_assert($unknownDetail['message_key'] === null, 'Unrecognised message must not be assigned a key.');

/* detailCarriesInfo: the "Validation failed" line, dropped only when it adds nothing --- */

pv_assert(
    PingViewStatusView::detailCarriesInfo('Validation failed', array(array('field' => 'name', 'message' => 'Monitor name is required'))) === false,
    'A generic message with details present must be dropped.'
);
pv_assert(
    PingViewStatusView::detailCarriesInfo('Validation failed', array()) === true,
    'A generic message with no details is all there is - it must not be dropped.'
);
pv_assert(
    PingViewStatusView::detailCarriesInfo(
        'locations: At least one monitoring location is required for non-heartbeat monitors',
        array(array('field' => 'locations', 'message' => 'At least one monitoring location is required for non-heartbeat monitors'))
    ) === false,
    'A message that only restates "field: first detail" must be dropped.'
);
pv_assert(
    PingViewStatusView::detailCarriesInfo('Could not reach the monitoring backend', array(array('field' => 'name', 'message' => 'Monitor name is required'))) === true,
    'A message with its own content must survive even when details are present.'
);
pv_assert(PingViewStatusView::detailCarriesInfo(null, array()) === false, 'No message must never be treated as carrying info.');
pv_assert(PingViewStatusView::detailCarriesInfo('', array()) === false, 'An empty message must never be treated as carrying info.');

/* Action items: code + values pass through only when the code is one the panel knows - */

$withKnownCode = PingViewStatusView::attention(array(
    array('priority' => 'high', 'title' => 'SSL Certificate expiring soon', 'description' => 'Your certificate expires in 21 days', 'code' => 'ssl_expiring', 'values' => array('days' => 21)),
))['items'][0];
pv_assert($withKnownCode['code'] === 'ssl_expiring', 'Known code must pass through.');
pv_assert($withKnownCode['values'] === array('days' => 21), 'Values must pass through for a known code.');
// English fallback fields are untouched by PingViewStatusView: translation is the module's job.
pv_assert($withKnownCode['title'] === 'SSL Certificate expiring soon', 'Fallback title must still be the API text at this layer.');

$withUnknownCode = PingViewStatusView::attention(array(
    array('priority' => 'high', 'title' => 'Something new', 'description' => 'desc', 'code' => 'some_future_code', 'values' => array('foo' => 1)),
))['items'][0];
pv_assert($withUnknownCode['code'] === null, 'An unrecognised code must normalize to null, not be passed through.');
pv_assert($withUnknownCode['values'] === array(), 'Values for an unrecognised code must not be exposed.');

$withNoCode = PingViewStatusView::attention(array(
    array('priority' => 'high', 'title' => 'Availability below target', 'description' => 'Uptime is 99.87% (target: 99.9%)'),
))['items'][0];
pv_assert($withNoCode['code'] === null, 'Today\'s production payload (no code key at all) must normalize to null, not throw.');
pv_assert($withNoCode['values'] === array(), 'No code means no values, regardless of what a stray "values" key might contain.');

/* Account identity: the address is only ever what the API sent, never guessed --- */

$known = PingViewStatusView::accountIdentity('owner@shop.example', 'Acme Shop', 'pro');
pv_assert(
    $known === array('email' => 'owner@shop.example', 'team_name' => 'Acme Shop', 'team_plan' => 'Pro'),
    'Known identity fields must pass through, plan title-cased for display: ' . var_export($known, true)
);

$unknown = PingViewStatusView::accountIdentity(false, false, false);
pv_assert(
    $unknown === array('email' => null, 'team_name' => null, 'team_plan' => null),
    'Configuration::get()\'s false (unset) must normalize to null, never render as the literal word "false".'
);

/* Sign-in link: every app URL routes through the page that can issue a session
   once the account's address is known, since these accounts have no password --- */

pv_assert(
    PingViewStatusView::signInUrl('https://pingview.app/app/teams/T1/monitors/M1', 'owner@shop.example', 'https://pingview.app', 'pl')
        === 'https://pingview.app/pl/auth/signin?email=owner%40shop.example&callbackUrl=%2Fapp%2Fteams%2FT1%2Fmonitors%2FM1',
    'A known email must produce a sign-in URL with both parameters, correctly encoded.'
);

pv_assert(
    PingViewStatusView::signInUrl('https://pingview.app/app/teams/T1/monitors/M1', null, 'https://pingview.app', 'pl')
        === 'https://pingview.app/app/teams/T1/monitors/M1',
    'With no known email the direct link must be returned unchanged, not routed through sign-in with an empty parameter.'
);

pv_assert(
    PingViewStatusView::signInUrl('https://pingview.app/app/teams/T1/settings/general', '', 'https://pingview.app', 'pl')
        === 'https://pingview.app/app/teams/T1/settings/general',
    'An empty-string email must be treated the same as no email.'
);

pv_assert(
    PingViewStatusView::signInUrl('https://pingview.app/app/teams/T1/settings/general', 'a@b.com', 'https://pingview.app', 'de')
        === 'https://pingview.app/en/auth/signin?email=a%40b.com&callbackUrl=%2Fapp%2Fteams%2FT1%2Fsettings%2Fgeneral',
    'Unsupported locale must fall back to English, same convention as statusBadge().'
);

/* Location reason: why a row is not simply answering ----------------------- */

pv_assert(
    PingViewStatusView::locationReason('retired', 'SUCCESS', 'operational') === 'retired',
    'A retired probe must say so, whatever its last row happened to contain.'
);
pv_assert(
    PingViewStatusView::locationReason('pending', null, 'unknown') === 'pending',
    'A probe with no row yet is pending, not unknown-and-therefore-broken.'
);
pv_assert(
    PingViewStatusView::locationReason('reporting', 'SLOW_SUCCESS', 'degraded') === 'slow',
    'SLOW_SUCCESS is the answering-but-slow case, which is what "degraded" hides.'
);
pv_assert(
    PingViewStatusView::locationReason('reporting', 'TIMEOUT', 'offline') === 'timeout',
    'TIMEOUT must be told apart from a refused connection.'
);
pv_assert(
    PingViewStatusView::locationReason('reporting', null, 'operational') === '',
    'An operational location needs no explanation.'
);
pv_assert(
    PingViewStatusView::locationReason('reporting', null, 'partial') === 'status',
    'Without rawState the panel falls back to the canonical label, never to a guess.'
);

/* Sparkline: the latency already arriving with the availability read ------- */

$points = array(
    array('timestamp' => '2026-09-10T00:00:00Z', 'latency' => 500),
    array('timestamp' => '2026-09-10T01:00:00Z', 'latency' => 0),      // failed check: no reading
    array('timestamp' => '2026-09-10T02:00:00Z', 'latency' => 1000),
    array('timestamp' => '2026-09-10T03:00:00Z'),                       // no latency key
    array('timestamp' => '2026-09-10T04:00:00Z', 'latency' => 250),
);
$spark = PingViewStatusView::sparkline($points, 3000);

pv_assert($spark !== null, 'Three usable points must draw a line.');
pv_assert($spark['low'] === 250 && $spark['peak'] === 1000, 'A 0 ms failed check is not the fastest response, got ' . var_export($spark, true));
pv_assert(count(explode(' ', $spark['points'])) === 3, 'Only points carrying a latency may be plotted.');
pv_assert(strpos($spark['points'], '0,') === 0, 'The series must start at x=0.');
pv_assert(
    $spark['threshold_y'] !== null && $spark['threshold_y'] < $spark['height'],
    'A threshold inside the plotted range must produce a guide line.'
);

// The threshold, not the data, sets the ceiling when it is the larger number:
// a chart scaled to the data alone would put the dashed line off-canvas and
// silently drop the one reference the reader needs.
$flat = PingViewStatusView::sparkline(array(
    array('latency' => 100),
    array('latency' => 120),
), 3000);
pv_assert($flat['threshold_y'] !== null, 'A threshold above every reading must still be drawn.');
pv_assert($flat['threshold_y'] == 0.0, 'With the threshold as the maximum it sits on the top edge, got ' . var_export($flat['threshold_y'], true));

pv_assert(PingViewStatusView::sparkline(array(array('latency' => 100)), null) === null, 'One point is not a trend.');
pv_assert(PingViewStatusView::sparkline(array(), null) === null, 'No points must yield no chart, not an empty SVG.');

/* Other monitors on this host: scoped to the shop, never to the team ------- */

$others = PingViewStatusView::otherMonitorRows(
    array(
        array('id' => 'M1', 'target' => 'https://shop.example/', 'name' => 'Shop'),          // this monitor
        array('id' => 'M2', 'target' => 'https://shop.example/cart', 'name' => 'Cart'),      // already listed above
        array('id' => 'M3', 'target' => 'https://shop.example/blog', 'name' => 'Blog', 'status' => 'degraded', 'lastLatency' => 900),
        array('id' => 'M4', 'target' => 'https://other-client.example/', 'name' => 'Someone else'),
        array('id' => 'M5', 'target' => '', 'name' => 'No target'),
        'not an array',
    ),
    'shop.example',
    array(array('key' => 'cart', 'label' => 'Cart', 'url' => 'https://shop.example/cart/')),
    'M1'
);

pv_assert(count($others) === 1, 'Only the address neither listed above nor belonging to another shop may appear, got ' . var_export($others, true));
pv_assert($others[0]['id'] === 'M3', 'Wrong monitor selected: ' . var_export($others, true));
pv_assert($others[0]['path'] === '/blog', 'The row shows the path, not the whole URL again.');
pv_assert($others[0]['latency'] === 900 && $others[0]['status'] === 'degraded', 'Status and latency must survive the mapping.');

pv_assert(
    PingViewStatusView::otherMonitorRows(array(array('id' => 'M9', 'target' => 'https://shop.example/x')), '', array(), 'M1') === array(),
    'With no known host nothing may be listed: an agency account holds other clients shops.'
);

echo "Status view smoke test: OK\n";
