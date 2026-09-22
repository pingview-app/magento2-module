<?php
/**
 * Guards CT-PARITY: this module and the WordPress and PrestaShop plugins
 * render the same product, and the contract lists every capability that must
 * exist in all three panels. A capability card added in one and forgotten in
 * another is exactly how the panels diverged before the contract existed - a
 * merchant running two of them would see two different products.
 *
 * This does not render the view. It reads the raw PHP source, because the
 * marker's job is to prove a capability exists in the file even when only
 * one of two mutually exclusive branches (e.g. the two status-pill states)
 * renders for any given request.
 *
 * Fails when:
 *  - a capability key from the contract is missing from this panel;
 *  - a capability key appears more than once with no "else"/"elseif" between
 *    the occurrences (i.e. it is a real duplicate, not two mutually
 *    exclusive branches such as the two status-pill states);
 *  - the panel carries a data-pv-capability value that is not in the
 *    contract (drift in the other direction: an undocumented capability).
 *
 * Run: php Test/parity-smoke.php
 */

$contractPath = dirname(__DIR__, 2) . '/Backend/contracts/plugin-parity.contract.json';
$panelPath = dirname(__DIR__) . '/view/adminhtml/templates/dashboard.phtml';

function pv_parity_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * True when every gap between consecutive occurrences of a key contains an
 * "else"/"elseif" keyword - i.e. the occurrences sit in alternate branches
 * of the same conditional, so only one of them ever renders. Anything else
 * (two markers with no branch split between them) is a real duplicate.
 *
 * @param string $source Panel source.
 * @param array  $offsets Byte offsets of each occurrence, ascending.
 * @return bool
 */
function pv_parity_is_branch_split($source, $offsets)
{
    for ($i = 1; $i < count($offsets); $i++) {
        $between = substr($source, $offsets[$i - 1], $offsets[$i] - $offsets[$i - 1]);
        if (!preg_match('/\belse(if)?\b/i', $between)) {
            return false;
        }
    }
    return true;
}

// The contract lives in the PingView backend repository, which is checked out
// next to this one only on a full PingView working copy. A standalone clone of
// this module has nothing to compare against, so skip rather than fail: a
// contributor running the documented checks must not meet a fatal error for a
// file that was never theirs to have.
if (!is_file($contractPath)) {
    fwrite(STDOUT, 'Plugin parity smoke test: SKIPPED (no contract at ' . $contractPath . ')' . PHP_EOL);
    exit(0);
}

pv_parity_assert(is_file($panelPath), 'Panel not found at ' . $panelPath);

$contract = json_decode(file_get_contents($contractPath), true);
pv_parity_assert(is_array($contract) && !empty($contract['capabilities']), 'Contract did not parse to a capabilities list.');

$expectedKeys = array();
foreach ($contract['capabilities'] as $capability) {
    pv_parity_assert(!empty($capability['key']), 'Contract has a capability with no key.');
    $expectedKeys[] = $capability['key'];
}

$source = file_get_contents($panelPath);

preg_match_all('/data-pv-capability="([^"]*)"/', $source, $matches, PREG_OFFSET_CAPTURE);

$positionsByKey = array();
foreach ($matches[1] as $match) {
    list($key, $offset) = $match;
    $positionsByKey[$key][] = $offset;
}

foreach ($expectedKeys as $key) {
    pv_parity_assert(
        isset($positionsByKey[$key]),
        'Capability "' . $key . '" from the contract is missing its data-pv-capability marker in ' . $panelPath
    );
    $count = count($positionsByKey[$key]);
    if ($count > 1) {
        pv_parity_assert(
            pv_parity_is_branch_split($source, $positionsByKey[$key]),
            'Capability "' . $key . '" is marked ' . $count . ' times in ' . $panelPath
            . ' with no else/elseif between them - that is a real duplicate, not mutually exclusive branches.'
        );
    }
}

$driftKeys = array_diff(array_keys($positionsByKey), $expectedKeys);
pv_parity_assert(
    empty($driftKeys),
    'Panel carries data-pv-capability value(s) not in the contract: ' . implode(', ', $driftKeys)
    . '. Add them to Backend/contracts/plugin-parity.contract.json or remove the marker.'
);


/* Payload fields: the drift a marker cannot see ---------------------------- */

// A panel can carry every marker in the contract and still ignore the payload
// behind them. That is exactly what happened: all three panels kept the
// `locations` marker while one showed latency and a cause, and the others
// showed a status word instead.
$fieldSources = '';
foreach (array(
    dirname(__DIR__) . '/view/adminhtml/templates/dashboard.phtml',
    dirname(__DIR__) . '/Block/Adminhtml/Dashboard.php',
    dirname(__DIR__) . '/Model/StatusView.php',
) as $fieldSourcePath) {
    pv_parity_assert(is_file($fieldSourcePath), 'Magento source not found: ' . $fieldSourcePath);
    $fieldSources .= file_get_contents($fieldSourcePath);
}

foreach ($contract['payloadFields']['fields'] as $field) {
    pv_parity_assert(
        strpos($fieldSources, $field['name']) !== false,
        'CT-PARITY payload field "' . $field['name'] . '" is never read by this panel. '
        . 'Every panel reads it or none do: ' . $field['what']
    );
}

echo "Plugin parity smoke test: OK\n";
