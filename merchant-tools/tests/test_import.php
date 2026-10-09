<?php
/**
 * The CSV importer, against a fake post store.
 *
 * This suite exists because the importer once destroyed hand-written page
 * bodies on re-import. That failure is silent and unrecoverable -- the only
 * sign is that somebody's copy is gone -- so the content rules are pinned
 * here rather than trusted.
 *
 *     php tests/test_import.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['posts'] = array();     // id => array(post_title, post_content, post_status, post_type)
$GLOBALS['meta'] = array();      // id => array(key => value)
$GLOBALS['terms_set'] = array(); // id => array(taxonomy => term_ids)
$GLOBALS['next_id'] = 100;

function vc_merchant_post_types() { return array('merchant'); }

function wp_insert_post($arr, $wp_error = false) {
    $id = $GLOBALS['next_id']++;
    $GLOBALS['posts'][$id] = array(
        'post_title'   => $arr['post_title'] ?? '',
        'post_content' => $arr['post_content'] ?? '',
        'post_status'  => $arr['post_status'] ?? 'draft',
        'post_type'    => $arr['post_type'] ?? 'merchant',
    );
    return $id;
}
function wp_update_post($arr, $wp_error = false) {
    $id = (int) $arr['ID'];
    if (!isset($GLOBALS['posts'][$id])) { return 0; }
    foreach ($arr as $field => $value) {
        if ($field === 'ID') { continue; }
        // Only fields actually passed are written -- the point of the content
        // rule is that post_content is sometimes absent from $postarr.
        $GLOBALS['posts'][$id][$field] = $value;
    }
    return $id;
}
function get_post_field($field, $id) { return $GLOBALS['posts'][$id][$field] ?? ''; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }

function get_posts($args = array()) {
    $want_status = $args['post_status'] ?? 'any';
    $key = $args['meta_key'] ?? '';
    $value = $args['meta_value'] ?? '';
    $out = array();
    foreach ($GLOBALS['posts'] as $id => $post) {
        if (($GLOBALS['meta'][$id][$key] ?? null) !== $value) { continue; }
        if ($want_status !== 'any' && $post['post_status'] !== $want_status) { continue; }
        $out[] = $id;
    }
    return $out;
}

function wp_set_object_terms($id, $terms, $tax, $append = false) {
    $GLOBALS['terms_set'][$id][$tax] = $terms;
}
function term_exists($name, $tax = '') { return false; }
function get_term_by($f, $v, $tax = '') { return false; }
function wp_insert_term($name, $tax, $a = array()) {
    static $next = 10;
    return array('term_id' => $next++);
}
function is_wp_error($t) { return $t instanceof WP_Error; }
function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-')); }
function sanitize_text_field($v) { return $v; }
function add_action() {}
function add_filter() {}
function add_submenu_page() {}
function current_user_can() { return true; }
function wp_verify_nonce() { return !empty($GLOBALS['nonce_ok']); }
function wp_nonce_field() {}
function submit_button() {}
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html__($v, $d = null) { return $v; }
function esc_html_e($v, $d = null) { echo $v; }
function esc_attr($v) { return $v; }
function esc_url($v) { return (string) $v; }
function admin_url($p = '') { return '/wp-admin/' . $p; }
function number_format_i18n($n) { return (string) $n; }
function selected($a, $b, $e = true) { return ''; }
function checked($a, $b, $e = true) { return ''; }
function __($v, $d = null) { return $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function get_option($k, $d = false) { return $d; }
class WP_Error {
    private $m;
    function __construct($c = '', $m = '') { $this->m = $m; }
    function get_error_message() { return $this->m; }
}

require __DIR__ . '/../wp-merchant-import.php';

$fail = 0;
function check($label, $cond, $detail = '') {
    global $fail;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fail++; }
}

function row($extra = array()) {
    return array_merge(array(
        'brand_name'         => 'VooPoo',
        'display_brand_name' => 'VooPoo',
        'best_offer_summary' => '15% off sitewide',
    ), $extra);
}

/* -------------------------------------------------------------------------
 * Basics
 * ---------------------------------------------------------------------- */

list($action, $id, $msg) = vc_merchant_import_row(row());
check('a new merchant is created', $action === 'created' && $id > 0, "$action $msg");
check('imports default to draft for manual review',
    $GLOBALS['posts'][$id]['post_status'] === 'draft',
    $GLOBALS['posts'][$id]['post_status']);
check('a new post gets the default shortcode body',
    $GLOBALS['posts'][$id]['post_content'] === '[merchant_page]',
    $GLOBALS['posts'][$id]['post_content']);
check('the CSV columns reach post meta',
    get_post_meta($id, 'best_offer_summary', true) === '15% off sitewide');
check('a stable merchant key is stored',
    get_post_meta($id, VC_MERCHANT_KEY_META, true) === 'voopoo');

list($action2, $id2, ) = vc_merchant_import_row(row());
check('re-importing updates rather than duplicating',
    $action2 === 'updated' && $id2 === $id, "$action2 $id2 vs $id");

$blank = vc_merchant_import_row(array('brand_name' => '  '));
check('a row with no brand_name is skipped', $blank[0] === 'skipped', $blank[2]);

$before = count($GLOBALS['posts']);
$dry = vc_merchant_import_row(row(array('brand_name' => 'Dry Run Store')), true);
check('a dry run reports without creating anything',
    $dry[0] === 'would create' && count($GLOBALS['posts']) === $before, $dry[0]);

/* -------------------------------------------------------------------------
 * Custom page content must survive a re-import
 *
 * The regression this suite was written for.
 * ---------------------------------------------------------------------- */

$GLOBALS['posts'][$id]['post_content'] =
    "<h2>Why we rate VooPoo</h2>\n<p>Hand-written copy an editor spent an hour on.</p>";

vc_merchant_import_row(row(array('best_offer_summary' => '20% off sitewide')));
check('hand-written page content is NOT overwritten',
    strpos($GLOBALS['posts'][$id]['post_content'], 'Hand-written copy') !== false,
    $GLOBALS['posts'][$id]['post_content']);
check('but the meta still updates alongside it',
    get_post_meta($id, 'best_offer_summary', true) === '20% off sitewide',
    get_post_meta($id, 'best_offer_summary', true));

// An untouched body is still the importer's to manage, so switching
// content_mode has to be able to take effect.
$GLOBALS['posts'][$id]['post_content'] = '[merchant_page]';
vc_merchant_import_row(row(), false, false, 'empty');
check('an untouched shortcode body can be switched to empty mode',
    $GLOBALS['posts'][$id]['post_content'] === '',
    $GLOBALS['posts'][$id]['post_content']);

// And an emptied body is restored rather than left blank forever.
vc_merchant_import_row(row(), false, false, 'shortcode');
check('an emptied body is restored to the default',
    $GLOBALS['posts'][$id]['post_content'] === '[merchant_page]',
    $GLOBALS['posts'][$id]['post_content']);

// Whitespace is not custom content.
$GLOBALS['posts'][$id]['post_content'] = "   \n  ";
vc_merchant_import_row(row());
check('a whitespace-only body counts as untouched',
    $GLOBALS['posts'][$id]['post_content'] === '[merchant_page]',
    json_encode($GLOBALS['posts'][$id]['post_content']));

/* -------------------------------------------------------------------------
 * A published merchant must never be demoted by a re-import
 * ---------------------------------------------------------------------- */

$GLOBALS['posts'][$id]['post_status'] = 'publish';
vc_merchant_import_row(row());
check('re-importing does not unpublish a live merchant',
    $GLOBALS['posts'][$id]['post_status'] === 'publish',
    $GLOBALS['posts'][$id]['post_status']);

list($a, $pid, ) = vc_merchant_import_row(row(array('brand_name' => 'Publish Me')), false, true);
check('--publish publishes on import',
    $GLOBALS['posts'][$pid]['post_status'] === 'publish',
    $GLOBALS['posts'][$pid]['post_status']);

/* -------------------------------------------------------------------------
 * Identity
 * ---------------------------------------------------------------------- */

list(, $keyed, ) = vc_merchant_import_row(array(
    'brand_name' => 'Renamed Later',
    'externalMerchantKey' => 'stable-key-1',
));
check('externalMerchantKey is preferred as the identity',
    get_post_meta($keyed, VC_MERCHANT_KEY_META, true) === 'stable-key-1');

list($renamed_action, $renamed_id, ) = vc_merchant_import_row(array(
    'brand_name' => 'A Completely Different Name',
    'externalMerchantKey' => 'stable-key-1',
));
check('a renamed brand still matches on its key',
    $renamed_action === 'updated' && $renamed_id === $keyed, $renamed_action);
check('and the title follows the new name',
    $GLOBALS['posts'][$keyed]['post_title'] === 'A Completely Different Name');

/* -------------------------------------------------------------------------
 * Taxonomies
 * ---------------------------------------------------------------------- */

list(, $tid, ) = vc_merchant_import_row(array(
    'brand_name' => 'Taxonomy Store',
    'service_locations' => 'United States|Canada',
    'brand_category' => 'Vape Juice/E-Liquid, Box Mods',
));
check('service locations are assigned',
    !empty($GLOBALS['terms_set'][$tid]['service_location'])
    && count($GLOBALS['terms_set'][$tid]['service_location']) === 2,
    json_encode($GLOBALS['terms_set'][$tid] ?? array()));
check('comma-separated categories are split into terms',
    !empty($GLOBALS['terms_set'][$tid]['merchant_category'])
    && count($GLOBALS['terms_set'][$tid]['merchant_category']) === 2,
    json_encode($GLOBALS['terms_set'][$tid] ?? array()));

/* -------------------------------------------------------------------------
 * The upload itself
 *
 * A partial upload still leaves a readable temp file, so before this the
 * importer read a truncated CSV and reported success for however many rows
 * happened to arrive.
 * ---------------------------------------------------------------------- */

if (!defined('UPLOAD_ERR_OK')) {
    define('UPLOAD_ERR_OK', 0);
    define('UPLOAD_ERR_INI_SIZE', 1);
    define('UPLOAD_ERR_PARTIAL', 3);
    define('UPLOAD_ERR_NO_FILE', 4);
}

function run_screen(array $files) {
    $GLOBALS['nonce_ok'] = true;
    $_POST = array('vc_merchant_import_nonce' => 'x', 'dry_run' => '1');
    $_FILES = $files;
    ob_start();
    vc_merchant_import_screen();
    $html = ob_get_clean();
    $_POST = array();
    $_FILES = array();
    $GLOBALS['nonce_ok'] = false;
    return $html;
}

$truncated = tempnam(sys_get_temp_dir(), 'vc');
file_put_contents($truncated, "brand_name,brand_url\nVooPoo,https://x.com\n");

$html = run_screen(array('merchant_csv' => array(
    'tmp_name' => $truncated, 'error' => UPLOAD_ERR_PARTIAL, 'name' => 'm.csv')));
check('an interrupted upload is refused, not imported',
    stripos($html, 'interrupted') !== false && stripos($html, 'incomplete') !== false,
    substr(strip_tags($html), 0, 200));

$html = run_screen(array('merchant_csv' => array(
    'tmp_name' => $truncated, 'error' => UPLOAD_ERR_INI_SIZE, 'name' => 'm.csv')));
check('an oversized upload says so rather than importing nothing silently',
    stripos($html, 'larger than') !== false, substr(strip_tags($html), 0, 200));

$html = run_screen(array('merchant_csv' => array(
    'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'name' => '')));
check('no file asks for one', stripos($html, 'choose a CSV') !== false,
    substr(strip_tags($html), 0, 160));

// A path PHP did not create for this request must be refused, even with a
// clean error code.
$html = run_screen(array('merchant_csv' => array(
    'tmp_name' => $truncated, 'error' => UPLOAD_ERR_OK, 'name' => 'm.csv')));
check('a path that is not a genuine upload is refused',
    stripos($html, 'not a genuine upload') !== false,
    substr(strip_tags($html), 0, 200));

@unlink($truncated);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All importer checks passed.\n";
