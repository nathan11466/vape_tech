<?php
/**
 * Destination archive pages -- the 51 state pages.
 *
 * These exist to rank for "<niche> shops that ship to X", so the risks are
 * saying a store does not ship somewhere when it does (the exclusion list is
 * built with a LIKE query, which matches substrings), and letting 51
 * near-identical pages into the index.
 *
 *     php tests/test_archive.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['meta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['term_meta'] = array();
$GLOBALS['query_posts'] = array();   // ids returned by WP_Query
$GLOBALS['sibling_terms'] = array();
$GLOBALS['queried'] = null;
$GLOBALS['filters'] = array();
$GLOBALS['niche'] = 'Vape';

function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function update_term_meta($id, $k, $v) { $GLOBALS['term_meta'][$id][$k] = $v; return true; }
function get_the_ID() { return 1; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id] ?? ''; }
function get_permalink($id = null) { return 'https://vapingcheap.com/stores/store-' . $id . '/'; }
function get_post_type($id = null) { return 'merchant'; }
function get_queried_object() { return $GLOBALS['queried']; }
function is_tax($t = '') { return $GLOBALS['queried'] !== null; }
function is_singular() { return false; }
function is_main_query() { return true; }
function get_terms($a = array()) {
    $out = array();
    foreach ($GLOBALS['sibling_terms'] as $t) {
        if (isset($a['exclude']) && in_array($t->term_id, (array) $a['exclude'], true)) { continue; }
        if (isset($a['parent']) && (int) $t->parent !== (int) $a['parent']) { continue; }
        if (!empty($a['hide_empty']) && (int) $t->count < 1) { continue; }
        $out[] = $t;
    }
    return $out;
}
function get_term($id, $t = '') { return $GLOBALS['sibling_terms'][$id] ?? null; }
function get_term_by() { return false; }
function get_term_link($t) {
    return 'https://vapingcheap.com/ships-to/' . (is_object($t) ? $t->slug : $t) . '/';
}
function taxonomy_exists($t) { return true; }
function post_type_exists($t) { return true; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function apply_filters($tag, $value) {
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) {
        $value = $fn($value, func_num_args() > 2 ? func_get_arg(2) : null);
    }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_action($tag, $fn = null, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_shortcode($tag, $fn) { $GLOBALS['shortcodes'][$tag] = $fn; }
function shortcode_atts($pairs, $atts, $sc = '') { return array_merge($pairs, (array) $atts); }
function current_time($f) { return date($f); }
function date_i18n($f, $ts = null) { return date($f); }
function get_option($k, $d = false) { return $d; }
function current_user_can() { return false; }
function wp_verify_nonce() { return false; }
function wp_nonce_field() {}
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html_e($v, $d = null) { echo $v; }
function esc_textarea($v) { return $v; }
function __($v, $d = null) { return $v; }
function _e($v, $d = null) { echo $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-')); }
function sanitize_text_field($v) { return $v; }
function sanitize_textarea_field($v) { return $v; }
function wp_kses_post($v) { return $v; }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function wpautop($s) { return "<p>$s</p>"; }
function get_posts() { return array(); }
function vc_merchant_post_types() { return array('merchant'); }
function vc_merchant_display_name($id = null) {
    $d = trim((string) get_post_meta($id, 'display_brand_name', true));
    return $d !== '' ? $d : get_the_title($id);
}
function vc_merchant_niche_label() { return $GLOBALS['niche']; }
class WP_Error { function get_error_message() { return 'err'; } }
class WP_Query {
    public $posts = array();
    function __construct($a = array()) { $this->posts = $GLOBALS['query_posts']; }
}

require __DIR__ . '/../wp-merchant-archive.php';

$fail = 0;
function check($label, $cond, $detail = '') {
    global $fail;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fail++; }
}
function term($id, $name, $slug, $parent = 1, $count = 5, $tax = 'ships_to') {
    return (object) array('term_id' => $id, 'name' => $name, 'slug' => $slug,
        'parent' => $parent, 'count' => $count, 'taxonomy' => $tax);
}

$virginia = term(10, 'Virginia', 'virginia');
$west_virginia = term(11, 'West Virginia', 'west-virginia');
$texas = term(12, 'Texas', 'texas');

/* -------------------------------------------------------------------------
 * Query-aligned titles
 * ---------------------------------------------------------------------- */

check('a destination title matches how people search',
    vc_archive_title($texas) === 'Online Vape Shops That Ship to Texas',
    vc_archive_title($texas));

$GLOBALS['term_meta'][12]['vc_title_override'] = 'Texas Vape Delivery, Explained';
check('a per-term override wins', vc_archive_title($texas) === 'Texas Vape Delivery, Explained');
unset($GLOBALS['term_meta'][12]['vc_title_override']);

$GLOBALS['niche'] = 'CBD';
check('the niche label drives the wording',
    vc_archive_title($texas) === 'Online CBD Shops That Ship to Texas',
    vc_archive_title($texas));
$GLOBALS['niche'] = '';
check('an empty niche still reads naturally',
    vc_archive_title($texas) === 'Online Shops That Ship to Texas',
    vc_archive_title($texas));
$GLOBALS['niche'] = 'Vape';

check('a category archive gets its own wording',
    vc_archive_title(term(20, 'Box Mods', 'box-mods', 0, 4, 'merchant_category'))
        === 'Box Mods Vape Deals & Coupons',
    vc_archive_title(term(20, 'Box Mods', 'box-mods', 0, 4, 'merchant_category')));

/* -------------------------------------------------------------------------
 * Exclusions, and the substring trap
 *
 * The lookup is a LIKE query, so "Virginia" matches a merchant whose
 * restricted_states says "West Virginia". Claiming a store will not ship to
 * Virginia when it will is a factual error on a published page.
 * ---------------------------------------------------------------------- */

$GLOBALS['titles'][101] = 'Blocks West Virginia Only';
$GLOBALS['meta'][101] = array('restricted_states' => 'West Virginia|Utah');
$GLOBALS['titles'][102] = 'Blocks Virginia';
$GLOBALS['meta'][102] = array('restricted_states' => 'Virginia|Vermont');

$GLOBALS['query_posts'] = array(101, 102);
$excluded = vc_archive_excluded_merchants($virginia);
check('a store that lists the state is excluded', in_array(102, $excluded, true));
check('substring state names do NOT false-match',
    !in_array(101, $excluded, true),
    'West Virginia matched Virginia: ' . json_encode($excluded));

$excluded_wv = vc_archive_excluded_merchants($west_virginia);
check('West Virginia still matches its own page',
    in_array(101, $excluded_wv, true) && !in_array(102, $excluded_wv, true),
    json_encode($excluded_wv));

check('a non-destination taxonomy has no exclusions',
    vc_archive_excluded_merchants(term(20, 'Box Mods', 'box-mods', 0, 4, 'merchant_category'))
        === array());

$html = vc_archive_excluded_html($virginia);
check('the exclusions block names the state and the store',
    strpos($html, 'Virginia') !== false && strpos($html, 'Blocks Virginia') !== false, $html);
check('and links to the store page',
    strpos($html, '/stores/store-102/') !== false, $html);
check('and is singular for one store',
    strpos($html, '1 store that does not ship') !== false, $html);

$GLOBALS['query_posts'] = array();
check('no exclusions renders no block', vc_archive_excluded_html($texas) === '');

/* -------------------------------------------------------------------------
 * Near-duplicate pages must stay out of the index until they differ
 * ---------------------------------------------------------------------- */

$GLOBALS['query_posts'] = array();
check('a bare destination page has no unique content',
    !vc_archive_has_unique_content($texas));

foreach (array('vc_intro', 'vc_legal_note', 'vc_faq_q1', 'vc_faq_q2') as $key) {
    $GLOBALS['term_meta'][12] = array($key => 'Something specific to Texas.');
    check("$key alone makes the page indexable",
        vc_archive_has_unique_content($texas));
}
$GLOBALS['term_meta'][12] = array();

// A per-state exclusion list is itself unique content.
$GLOBALS['query_posts'] = array(102);
$GLOBALS['meta'][102]['restricted_states'] = 'Texas';
check('an exclusion list counts as unique content',
    vc_archive_has_unique_content($texas));
$GLOBALS['meta'][102]['restricted_states'] = 'Virginia|Vermont';
$GLOBALS['query_posts'] = array();

// The robots filter must noindex a bare archive and leave a filled one alone.
$GLOBALS['queried'] = $texas;
$robots_filters = $GLOBALS['filters']['rank_math/frontend/robots'] ?? array();
function robots_for() {
    $robots = array('index' => 'index', 'follow' => 'follow');
    foreach ($GLOBALS['filters']['rank_math/frontend/robots'] ?? array() as $fn) {
        $robots = $fn($robots);
    }
    return $robots;
}
check('a robots filter is registered', !empty($robots_filters));
$bare = robots_for();
check('a bare destination page is noindexed',
    ($bare['index'] ?? '') === 'noindex', json_encode($bare));
check('but still followed, so link equity flows to the stores',
    ($bare['follow'] ?? '') === 'follow', json_encode($bare));

$GLOBALS['term_meta'][12]['vc_intro'] = 'Texas has no state-level vape shipping ban beyond PACT Act rules.';
$filled = robots_for();
check('a destination page with its own intro is indexable',
    ($filled['index'] ?? '') === 'index', json_encode($filled));
$GLOBALS['term_meta'][12] = array();

/* -------------------------------------------------------------------------
 * Internal links to neighbouring destinations
 * ---------------------------------------------------------------------- */

$GLOBALS['sibling_terms'] = array(
    10 => $virginia, 11 => $west_virginia, 12 => $texas,
    13 => term(13, 'Empty State', 'empty-state', 1, 0),
);
$siblings = vc_archive_sibling_links($texas);
check('sibling links point at other destinations',
    strpos($siblings, '/ships-to/virginia/') !== false, $siblings);
check('and never link the page back to itself',
    strpos($siblings, '/ships-to/texas/') === false, $siblings);
check('and skip destinations with no stores',
    strpos($siblings, 'empty-state') === false, $siblings);
check('and use the searchable title wording',
    strpos($siblings, 'Ship to Virginia') !== false, $siblings);

/* -------------------------------------------------------------------------
 * Archive schema
 * ---------------------------------------------------------------------- */

$GLOBALS['queried'] = $texas;
$GLOBALS['titles'][201] = 'Store A';
$GLOBALS['titles'][202] = 'Store B';
global $wp_query;
$wp_query = (object) array('posts' => array(
    (object) array('ID' => 201), (object) array('ID' => 202),
));

$graph = vc_archive_schema_graph();
$types = array_map(fn($n) => $n['@type'], $graph);
check('the listing is described as an ItemList', in_array('ItemList', $types), json_encode($types));
$list = $graph[0];
check('ItemList counts its members', ($list['numberOfItems'] ?? 0) === 2);
check('ItemList positions start at 1 and increment',
    ($list['itemListElement'][0]['position'] ?? null) === 1
    && ($list['itemListElement'][1]['position'] ?? null) === 2);
check('ItemList names each store', ($list['itemListElement'][0]['name'] ?? '') === 'Store A');
check('no FAQPage without real FAQs', !in_array('FAQPage', $types), json_encode($types));

$GLOBALS['term_meta'][12] = array(
    'vc_faq_q1' => 'Can I get vapes delivered in Texas?',
    'vc_faq_a1' => 'Yes. Texas has no state-level ban beyond federal PACT Act rules.',
);
$types2 = array_map(fn($n) => $n['@type'], vc_archive_schema_graph());
check('a real FAQ produces a FAQPage', in_array('FAQPage', $types2), json_encode($types2));
$GLOBALS['term_meta'][12] = array();

$wp_query = (object) array('posts' => array());
check('an empty listing emits no ItemList',
    !in_array('ItemList', array_map(fn($n) => $n['@type'], vc_archive_schema_graph())));

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All archive checks passed.\n";
