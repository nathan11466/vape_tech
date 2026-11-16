<?php
/**
 * What `wp merchant doctor` reports.
 *
 * The doctor's whole job is to tell the truth about an install, so a wrong
 * check here is worse than no check: it sends someone to fix something that
 * is not broken, and it trains them to ignore the output.
 *
 * That is exactly what happened with the law links. The check asked whether
 * the base URL contained "/staging/" and warned if it did. But vc_law_base()
 * falls back to home_url('/vape-laws/'), so on a staging install a perfectly
 * correct derived URL contains "/staging/" as well -- and moves to the
 * production host by itself the moment WordPress does. The warning fired on
 * every staging install, for years of go-lives that were never at risk, and
 * I repeated it to the user three times as a real problem.
 *
 * The real failure is narrower: a URL that was typed in and pinned to some
 * OTHER site. These checks pin that distinction down.
 *
 *     php tests/test_doctor.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['options'] = array();
$GLOBALS['home'] = 'https://example.com/';

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function home_url($path = '/') { return rtrim($GLOBALS['home'], '/') . '/' . ltrim($path, '/'); }
function apply_filters($t, $v) { return $v; }
function add_filter() {}
function add_action() {}
function add_shortcode() {}
function add_submenu_page() {}
function post_type_exists($t) { return false; }
function taxonomy_exists($t) { return false; }
function get_posts($a = array()) { return array(); }
function get_terms($a = array()) { return array(); }
function get_term_meta() { return ''; }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function esc_html($s) { return $s; }
function __($s, $d = null) { return $s; }

require_once __DIR__ . '/../wp-merchant-doctor.php';

/* vc_law_base() lives in the lawlinks module, which drags in a lot more
 * WordPress than this test wants. The doctor only needs it to exist, and the
 * branch under test reads the stored option and home_url() directly. */
function vc_law_base() {
    $stored = trim((string) get_option('vc_law_base_url', ''));
    return trailingslashit($stored === '' ? home_url('/vape-laws/') : $stored);
}

$fail = 0;
function check($what, $ok, $detail = '') {
    global $fail;
    if ($ok) { echo "PASS: $what\n"; return; }
    $fail++;
    echo "FAIL: $what" . ($detail !== '' ? " -- $detail" : '') . "\n";
}

/** The Law links rows from a fresh report. */
function law_rows() {
    return array_values(array_filter(vc_doctor_report(), function ($r) {
        return $r['area'] === 'Law links';
    }));
}

/* --- A staging install with nothing stored: the old false positive ----- */

$GLOBALS['home'] = 'https://vapingcheap.com/staging/';
$GLOBALS['options'] = array();

$rows = law_rows();
check('a staging install with no stored URL reports exactly one law row',
    count($rows) === 1, count($rows) . ' rows');
check('and does NOT warn, because the URL derives from home_url',
    $rows[0]['state'] !== 'warn', $rows[0]['state'] . ': ' . $rows[0]['what']);
check('and the derived URL does contain /staging/, which is the whole point',
    strpos(vc_law_base(), '/staging/') !== false, vc_law_base());

/* --- Production, nothing stored: still fine, and now without /staging/ - */

$GLOBALS['home'] = 'https://vapingcheap.com/';
$rows = law_rows();
check('the same install on production does not warn either',
    $rows[0]['state'] !== 'warn', $rows[0]['state']);
check('and the derived URL followed the move with no edit',
    vc_law_base() === 'https://vapingcheap.com/vape-laws/', vc_law_base());

/* --- Any stored URL is reported, including the one that fooled a
 *     prefix check ----------------------------------------------------- */

/* This is the case that matters, and the reason the rule is "report every
 * stored URL" rather than something cleverer: live on production, with the
 * URL pinned to the staging subdirectory. The production home
 * https://vapingcheap.com/ IS a prefix of
 * https://vapingcheap.com/staging/vape-laws/, so a prefix test calls it
 * safe. It is not: those links go to staging forever. */
$GLOBALS['home'] = 'https://vapingcheap.com/';
$GLOBALS['options']['vc_law_base_url'] = 'https://vapingcheap.com/staging/vape-laws/';
$rows = law_rows();
check('a URL pinned to the staging path while live on production is flagged',
    $rows[0]['state'] === 'warn', $rows[0]['state'] . ': ' . $rows[0]['what']);
check('and it is flagged even though home_url is a prefix of it',
    strpos('https://vapingcheap.com/staging/vape-laws/', home_url('/')) === 0,
    'the prefix test that would have missed it');
check('the message quotes the stored URL and the site, so the fix is obvious',
    strpos($rows[0]['detail'], '/staging/vape-laws/') !== false
    && strpos($rows[0]['detail'], 'https://vapingcheap.com/') !== false,
    $rows[0]['detail']);

$GLOBALS['options']['vc_law_base_url'] = 'https://someone-elses-site.test/vape-laws/';
$rows = law_rows();
check('a URL pinned to an unrelated site is flagged too',
    $rows[0]['state'] === 'warn', $rows[0]['state']);

/* A stored URL that happens to match the derived one is still reported: it
 * is redundant, and it will stop following the site the day you move. */
$GLOBALS['options']['vc_law_base_url'] = 'https://vapingcheap.com/vape-laws/';
$rows = law_rows();
check('even a stored URL identical to the derived one is reported',
    $rows[0]['state'] === 'warn', $rows[0]['state'] . ': ' . $rows[0]['what']);

/* --- The report must never be empty or malformed ----------------------- */

$GLOBALS['options'] = array();
foreach (vc_doctor_report() as $i => $row) {
    if (!isset($row['area'], $row['state'], $row['what'], $row['detail'])) {
        check("row $i has every field", false, json_encode($row));
        break;
    }
    if (!in_array($row['state'], array('live', 'idle', 'dead', 'warn'), true)) {
        check("row $i has a state the printer knows", false, $row['state']);
        break;
    }
}
check('every row carries the four fields and a state the printer can mark',
    true);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All doctor checks passed.\n";
