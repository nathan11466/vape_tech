<?php
/**
 * Pre-flight integrity check. Run before packaging or importing.
 *
 * Loads the plugin against a stubbed WordPress and asserts the structural
 * things that unit tests miss and that fail silently in production:
 *
 *   - every taxonomy the code references is actually registered
 *   - activation seeding is reachable from the main plugin file
 *   - cross-module functions all exist
 *   - shortcodes are registered
 *   - the shipping assignment cannot silently drop terms
 *   - shipped assets are present
 *
 * Usage: php preflight.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', __DIR__ . '/');

$GLOBALS['registered_taxonomies'] = array();
$GLOBALS['registered_shortcodes'] = array();
$GLOBALS['activation_hooks'] = array();
$GLOBALS['actions'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['termmeta'] = array();
$GLOBALS['inserted_terms'] = array();
$GLOBALS['assigned'] = array();
$GLOBALS['current_id'] = 1;
$GLOBALS['queried'] = null;

$GLOBALS['registered_post_types'] = array();
function register_post_type($name, $args = array()) { $GLOBALS['registered_post_types'][$name] = $args; }
function post_type_exists($name) { return isset($GLOBALS['registered_post_types'][$name]); }
function register_taxonomy($name, $types, $args = array()) {
    $GLOBALS['registered_taxonomies'][$name] = $args;
}
function add_shortcode($tag, $cb) { $GLOBALS['registered_shortcodes'][$tag] = true; }
function register_activation_hook($file, $cb) { $GLOBALS['activation_hooks'][] = array($file, $cb); }
function add_action($tag, $cb = null, $p = 10, $a = 1) { $GLOBALS['actions'][$tag][] = $cb; }
function add_filter($tag, $cb = null, $p = 10, $a = 1) { $GLOBALS['actions'][$tag][] = $cb; }
function apply_filters($tag, $value) { return $value; }
function register_post_meta() {}
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['termmeta'][$id][$k] ?? ''; }
function update_term_meta($id, $k, $v) { $GLOBALS['termmeta'][$id][$k] = $v; return true; }
function get_the_ID() { return $GLOBALS['current_id']; }
function get_the_title($id = null) { return 'Test Merchant'; }
function get_permalink($id = null) { return 'https://example.com/m/'; }
function get_post_type($p = null) { return 'merchant'; }
function get_post_status($id = null) { return 'publish'; }
function is_singular() { return true; }
function is_tax($t = '') { return false; }
function is_main_query() { return true; }
function get_queried_object() { return $GLOBALS['queried']; }
function get_terms($a = array()) { return array(); }
function get_term_link($t) { return '#'; }
function term_exists($name, $tax = '') { return false; }
function wp_insert_term($name, $tax, $args = array()) {
    static $next = 100;
    $id = $next++;
    $GLOBALS['inserted_terms'][] = array('name' => $name, 'tax' => $tax, 'id' => $id);
    return array('term_id' => $id);
}
function get_term($id, $tax = '') {
    foreach ($GLOBALS['inserted_terms'] as $t) {
        if ($t['id'] === $id) {
            return (object) array('term_id' => $id, 'name' => $t['name'], 'taxonomy' => $t['tax']);
        }
    }
    return null;
}
function get_term_by($field, $value, $tax = '') {
    foreach ($GLOBALS['inserted_terms'] as $t) {
        if ($t['tax'] === $tax && ($t['name'] === $value || sanitize_title($t['name']) === $value)) {
            return (object) array('term_id' => $t['id'], 'name' => $t['name'], 'taxonomy' => $tax);
        }
    }
    return false;
}
function wp_set_object_terms($post_id, $ids, $tax, $append = false) {
    $GLOBALS['assigned'][$tax] = $ids;
}
function is_wp_error($t) { return false; }
function flush_rewrite_rules() {}
function current_user_can() { return true; }
function wpautop($s) { return "<p>$s</p>"; }
function wp_kses_post($s) { return $s; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_textarea($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html_e($v, $d = null) { echo $v; }
function __($v, $d = null) { return $v; }
function _e($v, $d = null) { echo $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function date_i18n($f) { return date($f); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-')); }
function add_management_page() {}
function wp_nonce_field() {}
function submit_button() {}
function wp_verify_nonce() { return false; }
function get_posts() { return array(); }
function wp_insert_post() { return 1; }
function wp_update_post() { return 1; }
function plugins_url($p, $f = '') { return $p; }
function wp_enqueue_style() {}
function get_option($k, $d = false) { return $d; }
function delete_option($k) {}
function update_option($k, $v) { return true; }
function add_submenu_page() {}
function selected($a, $b, $e = true) { return ''; }
function sanitize_hex_color($c) { return $c; }
function do_shortcode($s) { return ''; }
function shortcode_exists($t) { return isset($GLOBALS['registered_shortcodes'][$t]); }
function wp_get_object_terms($id, $tax, $args = array()) { return array(); }
class WP_Query { public $posts = array(); public function __construct($a = array()) {} }
function shortcode_atts($pairs, $atts, $sc = '') { return array_merge($pairs, (array) $atts); }
class WP_Error { public function get_error_message() { return ''; } }

$mainFile = __DIR__ . '/wp-merchant-fields.php';
require $mainFile;

// Fire the init callbacks, since that is where taxonomies register. Running
// them here also proves they are callable, not merely hooked.
foreach ($GLOBALS['actions']['init'] ?? array() as $cb) {
    if (is_callable($cb)) {
        $cb();
    }
}

$fail = 0;
$warn = 0;
function ok($label)            { echo "  ok    $label\n"; }
function bad($label, $d = '')  { global $fail; $fail++; echo "  FAIL  $label" . ($d ? " -- $d" : '') . "\n"; }
function warn($label, $d = '') { global $warn; $warn++; echo "  warn  $label" . ($d ? " -- $d" : '') . "\n"; }
function check($label, $cond, $d = '') { $cond ? ok($label) : bad($label, $d); }

echo "\nTaxonomies\n";
$registered = array_keys($GLOBALS['registered_taxonomies']);
foreach (array('service_location', 'ships_to', 'merchant_category') as $tax) {
    check("$tax is registered", in_array($tax, $registered, true));
}

// Anything the archive module claims to handle must actually exist.
foreach (vc_archive_taxonomies() as $tax) {
    check("archive taxonomy '$tax' is registered", in_array($tax, $registered, true),
        'referenced by vc_archive_taxonomies() but never registered');
}

// Public + rewrite, or the archive URLs will not resolve.
foreach (array('ships_to', 'merchant_category') as $tax) {
    $args = $GLOBALS['registered_taxonomies'][$tax] ?? array();
    check("$tax is public (archives reachable)", !empty($args['public']));
    check("$tax has a rewrite slug", !empty($args['rewrite']['slug']));
}

echo "\nPost type\n";
$pts = $GLOBALS['registered_post_types'];
check('a merchant post type is registered', !empty($pts),
    'nothing registers it - imported posts would be orphaned with no admin menu');
if (!empty($pts)) {
    $args = reset($pts);
    check('post type is public', !empty($args['public']));
    check('post type has an archive', !empty($args['has_archive']));
    check('post type supports custom-fields',
        in_array('custom-fields', $args['supports'] ?? array(), true));
    check('post type has a rewrite slug', !empty($args['rewrite']['slug']));
}

echo "\nActivation\n";
check('exactly one activation hook is registered', count($GLOBALS['activation_hooks']) === 1,
    count($GLOBALS['activation_hooks']) . ' found');
if (!empty($GLOBALS['activation_hooks'])) {
    list($file, $cb) = $GLOBALS['activation_hooks'][0];
    check('activation hook is bound to the main plugin file', $file === $mainFile, "got: $file");
    check('activation callback exists', is_string($cb) && function_exists($cb));
}

// Run activation and confirm the taxonomies actually get populated.
if (function_exists('vc_merchant_activate')) {
    vc_merchant_activate();
    $byTax = array();
    foreach ($GLOBALS['inserted_terms'] as $t) {
        $byTax[$t['tax']] = ($byTax[$t['tax']] ?? 0) + 1;
    }
    check('activation seeds service_location', ($byTax['service_location'] ?? 0) > 0);
    check('activation seeds ships_to', ($byTax['ships_to'] ?? 0) > 0,
        'no destination terms created - every import would assign nothing');
    check('ships_to includes all 50 states + DC',
        ($byTax['ships_to'] ?? 0) >= 51, ($byTax['ships_to'] ?? 0) . ' terms');
}

echo "\nShipping assignment\n";
if (function_exists('vc_assign_ships_to')) {
    // Terms already seeded above.
    $n = vc_assign_ships_to(1, 'United States|California|Texas');
    check('assigns known destinations', $n >= 3, "assigned $n");

    // An unseeded destination must be created, not silently dropped.
    $before = count($GLOBALS['inserted_terms']);
    vc_assign_ships_to(2, 'Narnia');
    check('creates an unknown destination rather than dropping it silently',
        count($GLOBALS['inserted_terms']) > $before);
}

echo "\nCross-module functions\n";
$required = array(
    'vc_layout_get', 'vc_layout_blocks', 'vc_merchant_block',
    'vc_merchant_post_types', 'vc_merchant_display_name', 'vc_merchant_offer_badge',
    'vc_merchant_info_panel', 'vc_merchant_trust_info', 'vc_merchant_render_page',
    'vc_merchant_seo_title', 'vc_merchant_meta_description', 'vc_merchant_schema_graph',
    'vc_merchant_freshness_stamp', 'vc_archive_title', 'vc_archive_intro',
    'vc_archive_schema_graph', 'vc_archive_merchant_card', 'vc_assign_ships_to',
    'vc_merchant_restricted_line', 'vc_merchant_import_csv', 'vc_seed_ships_to',
);
foreach ($required as $fn) {
    check("$fn() exists", function_exists($fn));
}

echo "\nShortcodes\n";
foreach (array('merchant_page', 'merchant_list', 'merchant_related', 'merchant_offer',
               'merchant_about', 'merchant_policies', 'merchant_faqs', 'merchant_info',
               'merchant_trust', 'merchant_editorial', 'merchant_field',
               'merchant_name', 'merchant_hero', 'merchant_quickfacts', 'merchant_links') as $sc) {
    check("[$sc] is registered", isset($GLOBALS['registered_shortcodes'][$sc]));
}

echo "\nShipped files\n";
$files = array(
    'wp-merchant-fields.php', 'wp-merchant-shipping.php', 'wp-merchant-render.php',
    'wp-merchant-seo.php', 'wp-merchant-archive.php', 'wp-merchant-sections.php',
    'wp-merchant-settings.php',
    'wp-merchant-import.php',
    'assets/merchant-pages.css',
);
foreach ($files as $f) {
    check("$f present", file_exists(__DIR__ . '/' . $f));
}

echo "\nImporter coverage\n";
if (function_exists('vc_merchant_import_meta_keys')) {
    $keys = vc_merchant_import_meta_keys();
    foreach (array('ships_to_terms', 'restricted_states', 'brand_category',
                   'offer_display_mode', 'section_origins', 'social_links',
                   'useful_links', 'do_they_id_on_delivery', 'shipping_policy_url') as $k) {
        check("importer carries '$k'", in_array($k, $keys, true));
    }
}

echo "\n";
if ($fail) {
    echo "$fail check(s) FAILED";
    if ($warn) { echo ", $warn warning(s)"; }
    echo "\n";
    exit(1);
}
echo "Preflight clean" . ($warn ? " ($warn warning(s))" : '') . ".\n";
