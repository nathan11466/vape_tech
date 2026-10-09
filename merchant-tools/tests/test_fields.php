<?php
/**
 * [merchant_field] and [merchant_name].
 *
 * These let an editor drop any single CSV value into a page, so two things
 * matter: values are escaped, since they come from scraped third-party sites;
 * and a key that does not resolve says so, because rendering nothing looks
 * exactly like an empty field and sends people hunting through the CSV.
 *
 *     php tests/test_fields.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['meta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['current_id'] = 1;
$GLOBALS['shortcodes'] = array();
$GLOBALS['can_edit'] = true;

function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_the_ID() { return $GLOBALS['current_id']; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id ?: $GLOBALS['current_id']] ?? ''; }
function get_permalink($id = null) { return 'https://vapingcheap.com/stores/brand/'; }
function get_post_type($id = null) { return 'merchant'; }
function current_user_can($cap = '') { return $GLOBALS['can_edit']; }
function is_singular() { return true; }
function is_tax($t = '') { return false; }
function get_queried_object() { return null; }
function apply_filters($tag, $value) { return $value; }
function add_action() {}
function add_filter() {}
function add_shortcode($tag, $fn) { $GLOBALS['shortcodes'][$tag] = $fn; }
function do_shortcode($s) { return $s; }
function shortcode_exists($t) { return isset($GLOBALS['shortcodes'][$t]); }
function shortcode_atts($pairs, $atts, $sc = '') { return array_merge($pairs, (array) $atts); }
function wpautop($s) { return "<p>$s</p>"; }
function register_activation_hook() {}
function register_taxonomy() {}
function register_post_meta() {}
function register_post_type() {}
function post_type_exists() { return false; }
function taxonomy_exists() { return false; }
function delete_post_meta($i, $k) { return true; }
function current_time($f) { return date($f); }
function term_exists() { return false; }
function wp_insert_term() { return array('term_id' => 1); }
function is_wp_error($t) { return false; }
function home_url($p = '') { return 'https://vapingcheap.com' . $p; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html_e($v, $d = null) { echo htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr__($v, $d = null) { return $v; }
function __($v, $d = null) { return $v; }
function _e($v, $d = null) { echo $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function date_i18n($fmt) { return date($fmt); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function add_management_page() {}
function get_option($k, $d = false) { return $d; }
function delete_option($k) {}
function update_option($k, $v) { return true; }
function add_submenu_page() {}
function add_meta_box() {}
function selected($a, $b, $e = true) { return ''; }
function sanitize_hex_color($c) { return $c; }
function wp_nonce_field() {}
function submit_button() {}
function wp_verify_nonce() { return false; }
function get_posts() { return array(); }
function get_terms() { return array(); }
function get_term($id, $t = '') { return null; }
function get_term_by() { return false; }
function get_term_link($t) { return '#'; }
function get_term_meta($id, $k, $s = false) { return ''; }
function wp_insert_post() { return 1; }
function wp_update_post() { return 1; }
function wp_set_object_terms() {}
function sanitize_title($v) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $v)); }
function sanitize_text_field($v) { return $v; }
function is_main_query() { return false; }
function is_404() { return false; }
function wp_safe_redirect() {}
function flush_rewrite_rules() {}
function wp_update_term() { return array('term_id' => 1); }
function has_post_thumbnail() { return false; }
function set_post_thumbnail() {}
function get_post() { return null; }
function wp_get_attachment_url() { return false; }
class WP_Error { function get_error_message() { return ''; } }
class WP_Query { public $posts = array(); function __construct($a = array()) {} }

require __DIR__ . '/../wp-merchant-fields.php';

$fail = 0;
function check($label, $cond, $detail = '') {
    global $fail;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fail++; }
}
function field($atts) {
    return call_user_func($GLOBALS['shortcodes']['merchant_field'], $atts);
}
function merchant_name($atts = array()) {
    return call_user_func($GLOBALS['shortcodes']['merchant_name'], $atts);
}

$GLOBALS['titles'][1] = 'VooPoo';
$GLOBALS['titles'][2] = 'Element Vape';
$GLOBALS['meta'][1] = array(
    'display_brand_name' => 'VooPoo',
    'free_shipping_info' => 'Free shipping over $75',
    'best_offer_summary' => '15% off sitewide',
    'restricted_states'  => 'Utah|Vermont',
);
$GLOBALS['meta'][2] = array(
    'display_brand_name' => 'Element Vape',
    'free_shipping_info' => 'Free shipping over $50',
);
$GLOBALS['current_id'] = 1;

/* -------------------------------------------------------------------------
 * Both shortcodes are registered
 * ---------------------------------------------------------------------- */

check('[merchant_field] is registered', isset($GLOBALS['shortcodes']['merchant_field']));
check('[merchant_name] is registered', isset($GLOBALS['shortcodes']['merchant_name']));

/* -------------------------------------------------------------------------
 * Reading a value
 * ---------------------------------------------------------------------- */

check('a field renders its value',
    field(array('key' => 'free_shipping_info')) === 'Free shipping over $75',
    field(array('key' => 'free_shipping_info')));

check('an empty field renders nothing',
    field(array('key' => 'why_code_not_work')) === '',
    field(array('key' => 'why_code_not_work')));

check('no key renders nothing', field(array()) === '');

/* -------------------------------------------------------------------------
 * The name is the post title, not meta
 *
 * key="brand_name" is the obvious thing to try and used to silently produce
 * nothing, which is indistinguishable from an empty column.
 * ---------------------------------------------------------------------- */

// Hard-coded rather than read from vc_section_virtual_fields(): a test that
// asks the code what to expect cannot notice the code being emptied, which is
// exactly the regression that matters here.
$aliases = array('brand_name', 'display_brand_name', 'name', 'title');
foreach ($aliases as $alias) {
    check("key=\"$alias\" resolves to the store name",
        field(array('key' => $alias)) === 'VooPoo',
        field(array('key' => $alias)));
}
check('the alias list still holds every expected key',
    vc_section_virtual_fields() == $aliases,
    json_encode(vc_section_virtual_fields()));

check('[merchant_name] renders the store name', merchant_name() === 'VooPoo', merchant_name());

// display_brand_name is what readers should see; the post title is a fallback.
$GLOBALS['meta'][1]['display_brand_name'] = 'VooPoo Official';
check('the display name wins over the post title',
    merchant_name() === 'VooPoo Official', merchant_name());
$GLOBALS['meta'][1]['display_brand_name'] = '';
check('the post title is used when no display name is set',
    merchant_name() === 'VooPoo', merchant_name());
$GLOBALS['meta'][1]['display_brand_name'] = 'VooPoo';

/* -------------------------------------------------------------------------
 * Escaping
 *
 * These values are scraped from third-party sites, so they are untrusted.
 * ---------------------------------------------------------------------- */

$GLOBALS['meta'][1]['free_shipping_info'] = '<script>alert(1)</script> over $75';
$escaped = field(array('key' => 'free_shipping_info'));
check('a field value is escaped',
    strpos($escaped, '<script>') === false && strpos($escaped, '&lt;script&gt;') !== false,
    $escaped);

$GLOBALS['titles'][1] = 'Vape "Quotes" & <b>Bold</b>';
$GLOBALS['meta'][1]['display_brand_name'] = '';
$escaped_name = merchant_name();
check('the store name is escaped too',
    strpos($escaped_name, '<b>') === false && strpos($escaped_name, '&lt;b&gt;') !== false,
    $escaped_name);
$GLOBALS['titles'][1] = 'VooPoo';
$GLOBALS['meta'][1]['display_brand_name'] = 'VooPoo';
$GLOBALS['meta'][1]['free_shipping_info'] = 'Free shipping over $75';

/* -------------------------------------------------------------------------
 * before / after wrappers
 * ---------------------------------------------------------------------- */

check('before and after wrap the value',
    field(array('key' => 'free_shipping_info', 'before' => 'Shipping: ', 'after' => ' (verified)'))
        === 'Shipping: Free shipping over $75 (verified)',
    field(array('key' => 'free_shipping_info', 'before' => 'Shipping: ', 'after' => ' (verified)')));

check('before and after are omitted when the field is empty',
    field(array('key' => 'why_code_not_work', 'before' => 'Note: ', 'after' => '!')) === '');

check('before and after are escaped as well',
    strpos(field(array('key' => 'free_shipping_info', 'before' => '<i>')), '<i>') === false);

/* -------------------------------------------------------------------------
 * Targeting another merchant
 * ---------------------------------------------------------------------- */

check('id="" reads a different merchant',
    field(array('key' => 'free_shipping_info', 'id' => 2)) === 'Free shipping over $50',
    field(array('key' => 'free_shipping_info', 'id' => 2)));
check('id="" also switches the store name',
    merchant_name(array('id' => 2)) === 'Element Vape', merchant_name(array('id' => 2)));

/* -------------------------------------------------------------------------
 * An unresolvable key must be visible, not silent
 * ---------------------------------------------------------------------- */

$GLOBALS['can_edit'] = true;
$typo = field(array('key' => 'free_shiping_info'));
check('a typo warns an editor rather than rendering nothing',
    strpos($typo, 'unknown key') !== false && strpos($typo, 'free_shiping_info') !== false, $typo);

$GLOBALS['can_edit'] = false;
check('but a visitor never sees the warning',
    field(array('key' => 'free_shiping_info')) === '',
    field(array('key' => 'free_shiping_info')));
$GLOBALS['can_edit'] = true;

/* -------------------------------------------------------------------------
 * It must not become a way to read arbitrary post meta
 * ---------------------------------------------------------------------- */

$GLOBALS['meta'][1]['_secret_internal'] = 'should never render';
$GLOBALS['can_edit'] = false;
check('a key outside the importer columns reads nothing',
    strpos(field(array('key' => '_secret_internal')), 'should never render') === false,
    field(array('key' => '_secret_internal')));
$GLOBALS['can_edit'] = true;
check('and warns an editor that it is not a known key',
    strpos(field(array('key' => '_secret_internal')), 'unknown key') !== false);

// Every declared key must actually be reachable, or the editor panel offers
// shortcodes that render nothing.
$all_keys = vc_merchant_import_meta_keys();
// Guard against a vacuous pass: an empty column list would make the sweep
// below assert nothing at all.
check('the importer declares a substantial column list',
    count($all_keys) > 30, count($all_keys) . ' keys');

$unreachable = array();
foreach ($all_keys as $key) {
    $GLOBALS['meta'][1][$key] = 'probe-value';
    if (strpos(field(array('key' => $key)), 'probe-value') === false
        && !in_array($key, vc_section_virtual_fields(), true)) {
        $unreachable[] = $key;
    }
    unset($GLOBALS['meta'][1][$key]);
}
check('every importer column is reachable by [merchant_field]',
    empty($unreachable), implode(', ', array_slice($unreachable, 0, 5)));

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All field shortcode checks passed.\n";
