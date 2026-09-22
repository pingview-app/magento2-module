<?php
/**
 * Every source string the panel can render has a Polish row.
 *
 * Magento looks a string up by exact source text, so a row is either a byte
 * match or it is not used at all - no partial match, no warning. A string that
 * drifts by one character silently ships in English to every Polish merchant,
 * which is how the WordPress panel shipped four untranslated sentences: the
 * key carried an unescaped `%1$d` and stopped matching.
 *
 * The WordPress plugin has tools/crosscheck-pot.php and PrestaShop now has
 * tests/translation-coverage.php; this is the third panel's copy of the same
 * gate. Deliberately not a "translate everything" tool: it only proves that
 * what the code asks for exists in pl_PL.
 *
 * Run: php Test/translation-coverage.php
 */

$root = dirname(__DIR__);
$catalogue = $root . '/i18n/pl_PL.csv';

if (!is_file($catalogue)) {
    throw new RuntimeException('Catalogue not found: ' . $catalogue);
}

$known = [];
$handle = fopen($catalogue, 'r');
while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
    if (isset($row[0]) && $row[0] !== '') {
        $known[$row[0]] = true;
    }
}
fclose($handle);

/** Every file that can call __(). */
$files = [];
foreach (['Block', 'Model', 'Controller', 'Observer', 'Setup', 'view'] as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) {
        continue;
    }

    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    foreach ($walk as $file) {
        $name = $file->getFilename();
        if (substr($name, -4) === '.php' || substr($name, -6) === '.phtml') {
            $files[] = $file->getPathname();
        }
    }
}

$sources = [];
foreach ($files as $path) {
    preg_match_all("/__\(\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($path), $matches);
    foreach ($matches[1] as $raw) {
        $sources[stripcslashes($raw)] = true;
    }
}

$missing = [];
foreach (array_keys($sources) as $source) {
    if (!isset($known[$source])) {
        $missing[] = $source;
    }
}

if ($missing) {
    sort($missing);
    throw new RuntimeException(
        count($missing) . " source string(s) have no pl_PL row:\n  - "
        . implode("\n  - ", array_slice($missing, 0, 20))
    );
}

echo 'Translation coverage: OK (' . count($sources) . " strings)\n";
