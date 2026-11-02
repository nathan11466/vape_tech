<?php
/**
 * The sample import CSV.
 *
 * It is a reference people copy, so a wrong header or a row the importer
 * would reject teaches the wrong format. Checked against the importer's own
 * sample header, read out of its source.
 *
 *     php tests/test_sample_csv.php [/path/to/wp-coupon-deals]
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$plugin = $argv[1] ?? getenv('WCD_PATH')
    ?: '/tmp/claude-0/-home-user-vape-tech/0da2384f-d7fc-5446-ad82-a1fa7a4aa6be/scratchpad/wcd/wp-coupon-deals';
$sample = __DIR__ . '/../migrate/coupon-import-sample.csv';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

check('the sample file exists', is_readable($sample));
if (!is_readable($sample)) { exit(1); }

$fh = fopen($sample, 'r');
$header = fgetcsv($fh);
// The importer strips a BOM from the first header cell; the file carries one
// so Excel opens it as UTF-8, and that must not break the first column name.
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
$rows = array();
while (($line = fgetcsv($fh)) !== false) { $rows[] = $line; }
fclose($fh);

/* --- The header must match the importer's own ------------------------- */

$importer = rtrim($plugin, '/') . '/includes/class-import.php';
if (is_readable($importer)) {
    $src = file_get_contents($importer);
    if (preg_match("/fputcsv\(\s*\\\$out,\s*array\(\s*'title'(.*?)\)\s*\);/s", $src, $m)) {
        preg_match_all("/'([a-z_]+)'/", "'title'" . $m[1], $cols);
        check('the sample header matches the importer exactly',
            $header === $cols[1],
            'importer: ' . implode(',', $cols[1]) . ' | sample: ' . implode(',', $header));
    } else {
        check('the importer sample header could be located', false, 'this check is blind');
    }
} else {
    echo "SKIP: coupon plugin not found at $importer\n";
}

/* --- Every row must be one the importer accepts ----------------------- */

check('there are several example rows, not just one', count($rows) >= 5, count($rows) . ' rows');

$mismatched = array();
foreach ($rows as $i => $row) {
    if (count($row) !== count($header)) { $mismatched[] = $i + 2; }
}
check('every row has the same column count as the header',
    empty($mismatched), 'rows: ' . implode(',', $mismatched));

$col = array_flip($header);
$titleless = array();
foreach ($rows as $i => $row) {
    if (trim($row[$col['title']]) === '') { $titleless[] = $i + 2; }
}
check('no row is missing a title, which the importer would skip',
    empty($titleless), 'rows: ' . implode(',', $titleless));

/* --- The rows must demonstrate the cases that trip people up ---------- */

$types = array_column($rows, $col['type']);
check('a code example is included', in_array('code', $types, true));
check('a deal example is included', in_array('deal', $types, true));

$deal_rows = array_filter($rows, function ($r) use ($col) { return $r[$col['type']] === 'deal'; });
$deal_without_code = array_filter($deal_rows, function ($r) use ($col) {
    return trim($r[$col['code']]) === '';
});
check('at least one deal has an empty code, as a deal should',
    !empty($deal_without_code));

$blank_expiry = array_filter($rows, function ($r) use ($col) {
    return trim($r[$col['expiration']]) === '';
});
check('a blank expiry is demonstrated rather than a made-up date',
    !empty($blank_expiry));

$dated = array_filter(array_column($rows, $col['expiration']), 'strlen');
$bad_dates = array_filter($dated, function ($d) {
    return !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
});
check('every expiry date uses YYYY-MM-DD', empty($bad_dates), implode(',', $bad_dates));

$future = array_filter($dated, function ($d) { return strtotime($d) > time(); });
check('the dated examples are in the future, so they would not import hidden',
    count($future) === count($dated), implode(',', array_diff($dated, $future)));

$piped = array_filter($rows, function ($r) use ($col) {
    return strpos($r[$col['product_category']], '|') !== false
        || strpos($r[$col['location']], '|') !== false;
});
check('multiple terms in one column are demonstrated with a pipe', !empty($piped));

$commas = array_filter($rows, function ($r) use ($col) {
    return strpos($r[$col['additional_info']], ',') !== false;
});
check('a comma inside a field is demonstrated, since that is the usual breakage',
    !empty($commas));

$featured = array_column($rows, $col['featured']);
check('featured is shown as exactly 1, which is the only value that counts',
    in_array('1', $featured, true)
    && empty(array_filter($featured, function ($f) {
        return $f !== '' && $f !== '0' && $f !== '1';
    })), implode(',', $featured));

/* --- Brands must be real stores, since brand is what links the pages -- */

$brands = array_filter(array_column($rows, $col['brand']), 'strlen');
check('every example row names a brand', count($brands) === count($rows),
    count($brands) . ' of ' . count($rows));

/* --- The guide must exist and cover every column ---------------------- */

$guide = __DIR__ . '/../migrate/coupon-import-guide.md';
check('the written guide exists', is_readable($guide));
if (is_readable($guide)) {
    $text = file_get_contents($guide);
    $undocumented = array();
    foreach ($header as $column) {
        if (strpos($text, '`' . $column . '`') === false) { $undocumented[] = $column; }
    }
    check('the guide documents every column', empty($undocumented),
        implode(', ', $undocumented));
    check('and warns that everything imports as published',
        stripos($text, 'published') !== false || stripos($text, 'publish') !== false);
}

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All sample CSV checks passed.\n";
