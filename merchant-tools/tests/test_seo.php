<?php
/**
 * SEO titles, meta descriptions and JSON-LD, against stubbed WordPress.
 *
 * The invariant under test is honesty: a claim must never exceed what the data
 * confirms. These are the failures that are published automatically across
 * every merchant page before anyone notices, so they are worth pinning down.
 *
 *     php tests/test_seo.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['meta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['current_id'] = 1;
$GLOBALS['filters'] = array();

function get_post_meta($id, $key, $single = false) {
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_the_ID() { return $GLOBALS['current_id']; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id ?: $GLOBALS['current_id']] ?? ''; }
function get_permalink($id = null) { return 'https://vapingcheap.com/stores/brand/'; }
function get_post_type($id = null) { return 'merchant'; }
function is_singular() { return true; }
function is_tax($t = '') { return false; }
function get_queried_object() { return null; }
function apply_filters($tag, $value) {
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) {
        $value = $fn($value, func_num_args() > 2 ? func_get_arg(2) : null);
    }
    return $value;
}
function add_action() {}
function add_filter($tag, $fn, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_shortcode() {}
function do_shortcode($s) { return $s; }
function shortcode_exists($t) { return false; }
function shortcode_atts($pairs, $atts, $sc = '') { return array_merge($pairs, (array) $atts); }
function wpautop($s) { return "<p>$s</p>"; }
function register_activation_hook() {}
function register_deactivation_hook() {}
function wp_next_scheduled($h) { return $GLOBALS['scheduled'][$h] ?? false; }
function wp_schedule_event($t, $r, $h) { $GLOBALS['scheduled'][$h] = $t; return true; }
function wp_unschedule_event($t, $h) { unset($GLOBALS['scheduled'][$h]); return true; }
function has_action($t, $f = false) { return false; }
function do_action() {}
function wp_get_post_terms($i, $t, $a = array()) { return array(); }
function delete_term_meta($i, $k) { return true; }
function update_term_meta($i, $k, $v) { return true; }

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
function current_user_can() { return false; }
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

$month = date('F Y');

/* -------------------------------------------------------------------------
 * A merchant with a verified code
 * ---------------------------------------------------------------------- */

$GLOBALS['meta'][1] = array(
    'display_brand_name' => 'Vape Street',
    'publish_status' => 'Ready',
    'offer_display_mode' => 'verified_code',
    'best_offer_summary' => '15% off all devices (code DEVICE15)',
    'brand_url' => 'https://vape-street.com',
    'alternative_brand_names' => 'Vape Street USA|VapeStreet',
    'ships_to_countries' => 'United States|Canada',
    'contact_email' => 'support@vape-street.com',
    'fact_last_verified' => '2026-09-09',
    'faq_1_question' => 'Does Vape Street offer free shipping?',
    'faq_1_answer' => 'Yes, on orders over $70 with code FREESHIP.',
);
$GLOBALS['current_id'] = 1;

$title = vc_merchant_seo_title(1);
check('SEO title carries brand + coupon intent + freshness',
    strpos($title, 'Vape Street') !== false
    && stripos($title, 'Coupon Codes') !== false
    && strpos($title, $month) !== false, "got: $title");

$desc = vc_merchant_meta_description(1);
check('verified_code description says "Verified"', stripos($desc, 'Verified') !== false, "got: $desc");
check('description within snippet length', strlen($desc) <= 158, 'len=' . strlen($desc));

check('focus keyword is brand + coupon code',
    vc_merchant_focus_keyword(1) === 'vape street coupon code', vc_merchant_focus_keyword(1));

$graph = vc_merchant_schema_graph(1);
$types = array_map(fn($n) => $n['@type'], $graph);
check('schema types the merchant as an OnlineStore', in_array('OnlineStore', $types));
check('schema has Offer for a confirmed deal', in_array('Offer', $types));
check('schema has FAQPage from real Q&A', in_array('FAQPage', $types));
check('schema has WebPage', in_array('WebPage', $types));

$org = $graph[0];
check('Organization carries aliases as alternateName',
    isset($org['alternateName']) && in_array('VapeStreet', $org['alternateName']));
check('Organization carries areaServed from ships_to',
    isset($org['areaServed']) && count($org['areaServed']) === 2);
check('Organization carries support email',
    isset($org['contactPoint']['email']) && $org['contactPoint']['email'] === 'support@vape-street.com');

$webpage = end($graph);
check('WebPage carries dateModified for freshness',
    isset($webpage['dateModified']) && $webpage['dateModified'] === '2026-09-09');

check('schema is valid JSON', json_encode(array('@graph' => $graph)) !== false);

// Structured data must not assert what the visible copy refuses to.
$offerNode = null;
foreach ($graph as $n) { if ($n['@type'] === 'Offer') { $offerNode = $n; } }
check('Offer does NOT claim stock availability',
    $offerNode && !isset($offerNode['availability']),
    $offerNode ? json_encode($offerNode) : 'no offer node');
check('Offer carries the discount figure when the text has one',
    $offerNode && isset($offerNode['discount']),
    $offerNode ? json_encode($offerNode) : '-');

/* -------------------------------------------------------------------------
 * A merchant with NO confirmed code
 *
 * The SERP snippet must not promise a code the page cannot show, or the
 * click bounces straight back.
 * ---------------------------------------------------------------------- */

$GLOBALS['meta'][2] = array(
    'display_brand_name' => 'ZenCBD+',
    'publish_status' => 'Needs review',
    'offer_display_mode' => 'no_code_confirmed',
    'best_offer_summary' => 'Exclusive discounts via email sign-up',
);
$GLOBALS['current_id'] = 2;

$desc2 = vc_merchant_meta_description(2);
check('no_code_confirmed description never promises a code',
    stripos($desc2, 'verified') === false && stripos($desc2, 'coupon code') === false, "got: $desc2");

$graph2 = vc_merchant_schema_graph(2);
$types2 = array_map(fn($n) => $n['@type'], $graph2);
check('no Offer node when no deal is confirmed', !in_array('Offer', $types2));
check('no FAQPage node when there are no FAQs', !in_array('FAQPage', $types2));

/* -------------------------------------------------------------------------
 * Grade is advisory; WordPress post status is the only gate
 * ---------------------------------------------------------------------- */

$GLOBALS['meta'][3] = array(
    'display_brand_name' => 'ugovape',
    'publish_status' => 'Hold',
    'offer_display_mode' => 'best_deal',
    'best_offer_summary' => 'Clearance bundles',
);
$GLOBALS['current_id'] = 3;
check('schema is emitted regardless of grade (post status is the gate)',
    count(vc_merchant_schema_graph(3)) > 0);

// A robots filter exists for ARCHIVES, where an undifferentiated destination
// page is kept out of the index. It must never touch a merchant page:
// indexing those is post status's job, not the grade's.
$robotsFilters = $GLOBALS['filters']['rank_math/frontend/robots'] ?? array();
$robots = array('index' => 'index', 'follow' => 'follow');
foreach ($robotsFilters as $fn) {
    $robots = $fn($robots);
}
check('a merchant page is never noindexed by its grade',
    ($robots['index'] ?? '') === 'index', json_encode($robots));

$GLOBALS['current_id'] = 3;
check('renderer outputs the page regardless of grade',
    strpos(vc_merchant_render_page(array('id' => 3)), 'vc-merchant-page') !== false);
$GLOBALS['current_id'] = 1;
$html = vc_merchant_render_page(array('id' => 1));
check('renderer outputs the page for a Ready merchant',
    strpos($html, 'Verified Code') !== false && strpos($html, 'vc-merchant-page') !== false);

/* -------------------------------------------------------------------------
 * Reputation claims need their OWN source
 *
 * This is the subtlest bug the project has had. The trust block was gated on
 * fact_source_url, which the scraper fills with whichever policy page it
 * happened to read -- so a Trustpilot rating was published citing a shipping
 * policy as its source. A false citation, generated automatically, on every
 * page that had a score.
 * ---------------------------------------------------------------------- */

$GLOBALS['meta'][40] = array('company_trust_info' => 'Trustpilot: 3.5/5 (131 reviews)');
check('an unsourced review score renders nothing',
    vc_merchant_trust_info(40) === '', substr(vc_merchant_trust_info(40), 0, 80));

$GLOBALS['meta'][41] = array(
    'company_trust_info'  => 'Trustpilot: 3.5/5 (131 reviews)',
    'fact_source_url'     => 'https://shop.example.com/policies/shipping-policy',
    'fact_last_verified'  => '2026-10-08',
);
check('a policy-page source does NOT unlock a review score',
    vc_merchant_trust_info(41) === '', substr(vc_merchant_trust_info(41), 0, 80));

$GLOBALS['meta'][42] = array(
    'company_trust_info' => 'Trustpilot: 3.5/5 (131 reviews)',
    'trust_source_url'   => 'https://www.trustpilot.com/review/example.com',
    'trust_verified_at'  => '2026-10-08',
);
$out = vc_merchant_trust_info(42);
check('a properly sourced review score renders with its citation',
    strpos($out, 'trustpilot.com') !== false && strpos($out, '2026-10-08') !== false,
    substr($out, 0, 120));

$GLOBALS['meta'][43] = array(
    'company_trust_info' => 'Trustpilot: 3.5/5',
    'trust_source_url'   => 'https://www.trustpilot.com/review/example.com',
    'trust_verified_at'  => '',
);
check('a sourced but undated review score renders nothing',
    vc_merchant_trust_info(43) === '');

/* -------------------------------------------------------------------------
 * Token templates
 *
 * Plain substitution would leave "Up to % Off" on every store with no
 * percentage discount, which is most of them. Optional [[ ]] segments are
 * what prevent that.
 * ---------------------------------------------------------------------- */

$tokens = array(
    'brand_name'    => 'VooPoo',
    'current_month' => 'October',
    'current_year'  => '2026',
    'max_discount'  => '25',
    'active_count'  => '4',
);

check('tokens are substituted',
    vc_merchant_render_tokens('{brand_name} - {current_month} {current_year}', $tokens)
        === 'VooPoo - October 2026');

check('an optional segment is kept when its tokens resolve',
    vc_merchant_render_tokens('{brand_name}[[ - Up to {max_discount}% Off]]', $tokens)
        === 'VooPoo - Up to 25% Off');

$empty = array_merge($tokens, array('max_discount' => ''));
check('an optional segment is dropped when its token is empty',
    vc_merchant_render_tokens('{brand_name}[[ - Up to {max_discount}% Off]]', $empty)
        === 'VooPoo',
    vc_merchant_render_tokens('{brand_name}[[ - Up to {max_discount}% Off]]', $empty));

check('a dropped segment leaves no stray punctuation or token',
    strpos(vc_merchant_render_tokens('{brand_name}[[ - Up to {max_discount}% Off]]', $empty), '%') === false);

$none = array_merge($tokens, array('active_count' => ''));
check('each optional segment is judged on its own tokens',
    vc_merchant_render_tokens('A[[ {active_count} codes]][[ at {max_discount}% off]]', $none)
        === 'A at 25% off',
    vc_merchant_render_tokens('A[[ {active_count} codes]][[ at {max_discount}% off]]', $none));

check('an unknown token is removed rather than printed',
    vc_merchant_render_tokens('{brand_name} {not_a_token}', $tokens) === 'VooPoo');

check('whitespace left by removals is collapsed',
    vc_merchant_render_tokens('{brand_name}   {not_a_token}   codes', $tokens) === 'VooPoo codes',
    vc_merchant_render_tokens('{brand_name}   {not_a_token}   codes', $tokens));

// A store with no coupon plugin has no figures, so the title must still be
// a complete sentence.
$GLOBALS['current_id'] = 1;
$title_no_figures = vc_merchant_seo_title(1);
check('a store with no discount figure still gets a clean title',
    strpos($title_no_figures, '%') === false
    && strpos($title_no_figures, '[[') === false
    && strpos($title_no_figures, 'Vape Street') !== false,
    $title_no_figures);

check('and it still carries the freshness stamp',
    strpos($title_no_figures, date('F Y')) !== false, $title_no_figures);

// An emptied template must not produce a blank <title>.
$GLOBALS['filters']['vc_merchant_title_template'][] = function ($t) { return ''; };
check('an emptied template falls back rather than blanking the title',
    trim(vc_merchant_seo_title(1)) !== '', vc_merchant_seo_title(1));
$GLOBALS['filters']['vc_merchant_title_template'] = array();

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All SEO + schema checks passed.\n";
