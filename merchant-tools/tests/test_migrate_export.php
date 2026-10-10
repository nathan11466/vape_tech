<?php
/**
 * The one-time coupon exporter.
 *
 * Its whole job is to hand WP Coupon & Deals a CSV it will accept, so the
 * column list has to match that plugin's importer exactly -- and these are
 * two separate codebases that can drift apart silently.
 *
 *     php tests/test_migrate_export.php [/path/to/wp-coupon-deals]
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$plugin = $argv[1] ?? getenv('WCD_PATH')
    ?: '/tmp/claude-0/-home-user-vape-tech/0da2384f-d7fc-5446-ad82-a1fa7a4aa6be/scratchpad/wcd/wp-coupon-deals';

function add_action() {}
function add_management_page() {}
function get_post_types($a = array(), $out = 'names') { return array(); }
function wp_count_posts($t) { return (object) array('publish' => 0, 'draft' => 0); }
function get_posts($a = array()) { return array(); }
function current_user_can() { return false; }
function esc_html($v) { return $v; }
function esc_attr($v) { return $v; }
function esc_url($v) { return $v; }
function esc_html__($v, $d = null) { return $v; }
function esc_html_e($v, $d = null) { echo $v; }
function esc_attr_e($v, $d = null) { echo $v; }
function __($v, $d = null) { return $v; }
function sanitize_key($v) { return strtolower(preg_replace('/[^a-z0-9_]/i', '', (string) $v)); }
function sanitize_text_field($v) { return trim(strip_tags((string) $v)); }
function selected($a, $b = true, $e = true) { return ''; }
function wp_nonce_field() {}
function submit_button() {}
function wp_verify_nonce() { return false; }
function add_query_arg($a, $u = '') { return $u; }
function admin_url($p = '') { return '/wp-admin/' . $p; }
function post_type_exists($t) { return false; }
function taxonomy_exists($t) { return false; }

require __DIR__ . '/../migrate/vc-coupon-exporter.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

/* --- The column list must match the importer's own, read from its source - */

$importer = rtrim($plugin, '/') . '/includes/class-import.php';
if (is_readable($importer)) {
    $src = file_get_contents($importer);
    // The header its own sample CSV writes is the authoritative list.
    if (preg_match("/fputcsv\(\s*\\\$out,\s*array\((.*?)\)\s*\);/s", $src, $m)) {
        preg_match_all("/'([a-z_]+)'/", $m[1], $cols);
        check('the exporter writes exactly the columns the importer reads',
            $cols[1] === vce_target_columns(),
            'importer: ' . implode(',', $cols[1]) . ' | exporter: ' . implode(',', vce_target_columns()));
    } else {
        check('the importer\'s sample header could be located', false,
            'pattern did not match - this check is now blind');
    }
} else {
    echo "SKIP: coupon plugin not found at $importer\n";
}

/* --- Field guessing ---------------------------------------------------- */

$guess = vce_guess_mapping(array(
    '_coupon_code'        => 40,
    '_coupon_expiry_date' => 38,
    '_affiliate_link'     => 40,
    '_discount_amount'    => 30,
    '_terms_conditions'   => 12,
    '_is_featured'        => 3,
    '_unrelated_thing'    => 40,
));
check('the code field is found', ($guess['code'] ?? '') === '_coupon_code', json_encode($guess));
check('the expiry field is found', ($guess['expiration'] ?? '') === '_coupon_expiry_date', json_encode($guess));
check('the affiliate link is found as the destination',
    ($guess['destination_url'] ?? '') === '_affiliate_link', json_encode($guess));
check('the discount field is found', ($guess['discount'] ?? '') === '_discount_amount', json_encode($guess));
check('terms are mapped to additional_info',
    ($guess['additional_info'] ?? '') === '_terms_conditions', json_encode($guess));
check('an unrelated field is not mapped anywhere',
    !in_array('_unrelated_thing', $guess, true), json_encode($guess));

// One source must not be claimed by two columns.
check('no field is mapped to two columns at once',
    count($guess) === count(array_unique($guess)), json_encode($guess));

// Nothing to guess from must not invent anything.
check('no fields means no guesses', vce_guess_mapping(array()) === array());

/* --- The post types it offers ------------------------------------------ */

check('it never offers the destination post type as a source',
    !in_array('wcd_coupon', array_keys(vce_candidate_post_types()), true));

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All migration exporter checks passed.\n";
