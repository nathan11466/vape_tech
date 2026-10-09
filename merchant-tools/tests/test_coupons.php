<?php
/**
 * Integration with the WP Coupon & Deals plugin.
 *
 * Two separate risks here. The offer claim must follow the real coupon
 * records, including readers' own reports that a code has stopped working --
 * a page still saying "verified" over a dead code is worse than one that
 * claims nothing. And two plugins describing the same shop must not emit two
 * competing descriptions of it to Google.
 *
 *     php tests/test_coupons.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['meta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['terms'] = array();      // term_id => name
$GLOBALS['term_slugs'] = array(); // term_id => slug
$GLOBALS['coupons'] = array();    // list of post objects
$GLOBALS['current_id'] = 1;
$GLOBALS['filters'] = array();
$GLOBALS['has_coupon_plugin'] = true;
$GLOBALS['options'] = array();
$GLOBALS['term_meta'] = array();
$GLOBALS['shortcode_tags'] = array('coupon_deals' => 'x', 'coupon_reveal' => 'x', 'merchant_page' => 'y');

class FakeCoupon {
    public $ID, $post_modified_gmt, $post_date_gmt;
    function __construct($id) {
        $this->ID = $id;
        $this->post_modified_gmt = '2026-10-01 10:00:00';
        $this->post_date_gmt = '2026-10-01 10:00:00';
    }
}
class FakePost {
    public $ID;
    function __construct($id) { $this->ID = $id; }
}

function post_type_exists($t) { return $GLOBALS['has_coupon_plugin'] && $t === 'wcd_coupon'; }
function taxonomy_exists($t) { return $GLOBALS['has_coupon_plugin'] && $t === 'wcd_brand'; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$id][$k]); return true; }
function get_the_ID() { return $GLOBALS['current_id']; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id ?: $GLOBALS['current_id']] ?? ''; }
function get_permalink($id = null) { return 'https://vapingcheap.com/stores/voopoo/'; }

function _fake_term($id) {
    return (object) array(
        'term_id'  => $id,
        'name'     => $GLOBALS['terms'][$id],
        'slug'     => $GLOBALS['term_slugs'][$id] ?? null,
        'taxonomy' => 'wcd_brand',
    );
}
function get_term($id, $tax = '') {
    return isset($GLOBALS['terms'][$id]) ? _fake_term($id) : null;
}
function get_term_by($field, $value, $tax = '') {
    foreach ($GLOBALS['terms'] as $id => $name) {
        if ($name === $value || sanitize_title($name) === $value) {
            return _fake_term($id);
        }
    }
    return false;
}
function get_term_link($t) { return 'https://vapingcheap.com/brand/voopoo/'; }
function get_terms($a = array()) { return array(); }
function get_term_meta($id, $k, $single = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function get_posts($a = array()) {
    return ($a['post_type'] ?? '') === 'wcd_coupon' ? ($GLOBALS['probe_ids'] ?? array()) : array();
}
function current_time($f) { return date($f); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function is_wp_error($t) { return false; }
function apply_filters($tag, $value) {
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) {
        $value = $fn($value, func_num_args() > 2 ? func_get_arg(2) : null);
    }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_action() {}
function add_meta_box() {}
function wp_nonce_field() {}
function selected($a, $b, $e = true) { return ''; }
function current_user_can() { return true; }
function wp_verify_nonce() { return false; }
function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-')); }
function date_i18n($f, $ts = null) { return date($f, $ts ?: time()); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return $v; }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return $v; }
function esc_attr__($v, $d = null) { return $v; }
function __($v, $d = null) { return $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function get_edit_post_link($id) {
    return 'https://site.test/wp-admin/post.php?post=' . $id . '&action=edit';
}
function admin_url($p = '') { return 'https://site.test/wp-admin/' . $p; }
function get_current_screen() { return null; }
function metadata_exists($t, $id, $k) { return array_key_exists($k, $GLOBALS['meta'][$id] ?? array()); }
function wp_unslash($v) { return $v; }
function add_submenu_page() {}
function submit_button() {}
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function vc_merchant_post_types() { return array('merchant'); }
function vc_merchant_display_name($id = null) {
    $id = $id ?: $GLOBALS['current_id'];
    $d = trim((string) get_post_meta($id, 'display_brand_name', true));
    return $d !== '' ? $d : get_the_title($id);
}
class WP_Query {
    public $posts = array();
    function __construct($a = array()) { $this->posts = $GLOBALS['coupons']; }
}

require __DIR__ . '/../wp-merchant-coupons.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}
function set_coupon($id, $type, $code, $ok, $bad, $expires = '') {
    $GLOBALS['meta'][$id] = array(
        '_wcd_type' => $type, '_wcd_code' => $code,
        '_wcd_success_count' => $ok, '_wcd_fail_count' => $bad,
        '_wcd_expiration' => $expires,
    );
    return new FakeCoupon($id);
}
function tags_balanced($html, $tag) {
    return substr_count($html, "<$tag") === substr_count($html, "</$tag>");
}

$GLOBALS['titles'][1] = 'VooPoo';
$GLOBALS['terms'][50] = 'VooPoo';
$GLOBALS['term_slugs'][50] = 'voopoo';
$GLOBALS['current_id'] = 1;

/* -------------------------------------------------------------------------
 * Linking a store to its coupon brand
 * ---------------------------------------------------------------------- */

$term = vc_merchant_brand_term(1);
check('merchant matches its brand term by name', $term && $term->term_id === 50);

// An explicit link must beat the name match, so a brand whose coupon-plugin
// name differs from the store title can be corrected by hand.
$GLOBALS['titles'][4] = 'Totally Different Title';
$GLOBALS['terms'][52] = 'VooPoo Global';
$GLOBALS['term_slugs'][52] = 'voopoo-global';
$GLOBALS['meta'][4] = array('wcd_brand_term_id' => 52);
$explicit = vc_merchant_brand_term(4);
check('an explicit brand link overrides the name match',
    $explicit && $explicit->term_id === 52,
    $explicit ? $explicit->term_id : 'null');

/* -------------------------------------------------------------------------
 * The offer claim follows the real records
 * ---------------------------------------------------------------------- */

$GLOBALS['coupons'] = array(set_coupon(201, 'code', 'VOOPOO15', 12, 2));
check('a working code gives verified_code',
    vc_merchant_coupon_status(1) === 'verified_code', vc_merchant_coupon_status(1));

// Readers reporting failure must withdraw the "verified" claim.
$GLOBALS['coupons'] = array(set_coupon(202, 'code', 'DEAD20', 1, 9));
check('a code readers report as broken is NOT verified',
    vc_merchant_coupon_status(1) !== 'verified_code', vc_merchant_coupon_status(1));

$GLOBALS['coupons'] = array(set_coupon(203, 'deal', '', 0, 0));
check('a deal with no code gives best_deal',
    vc_merchant_coupon_status(1) === 'best_deal', vc_merchant_coupon_status(1));

// An expired code must not keep the page claiming one exists.
$GLOBALS['coupons'] = array(set_coupon(204, 'code', 'OLD10', 50, 0, '2020-01-01'));
check('an expired code is excluded',
    vc_merchant_live_coupons(1) === array(), count(vc_merchant_live_coupons(1)) . ' live');
check('expiry flips the page to no_code_confirmed',
    vc_merchant_coupon_status(1) === 'no_code_confirmed', vc_merchant_coupon_status(1));

$GLOBALS['coupons'] = array(set_coupon(205, 'code', 'FUTURE', 3, 0, '2099-12-31'));
check('a future expiry stays live',
    vc_merchant_coupon_status(1) === 'verified_code');

// A tie goes to the code: more successes than failures is the rule, and equal
// counts are not evidence it stopped working.
$GLOBALS['coupons'] = array(set_coupon(206, 'code', 'TIED', 5, 5));
check('an evenly reported code is still treated as working',
    vc_merchant_coupon_status(1) === 'verified_code', vc_merchant_coupon_status(1));

// No reports at all is not a failure signal either.
$GLOBALS['coupons'] = array(set_coupon(207, 'code', 'NEW', 0, 0));
check('a brand-new code with no reports is still a code',
    vc_merchant_coupon_status(1) === 'verified_code', vc_merchant_coupon_status(1));

/* -------------------------------------------------------------------------
 * Schema must not describe the shop twice
 *
 * Matching @ids merge into one node. Differing ids read as two separate
 * businesses that happen to share a name.
 * ---------------------------------------------------------------------- */

$org_id = apply_filters('vc_merchant_org_id', 'https://vapingcheap.com/stores/voopoo/#merchant', 1);
check('store node adopts the coupon plugin Organization @id',
    $org_id === 'https://vapingcheap.com/brand/voopoo/#organization', $org_id);

$GLOBALS['coupons'] = array(set_coupon(208, 'code', 'LIVE', 5, 0));
check('our Offer node is suppressed when real coupons exist',
    apply_filters('vc_merchant_emit_offer', true, 1) === false);

$GLOBALS['coupons'] = array();
check('our Offer node is kept when there are no coupons',
    apply_filters('vc_merchant_emit_offer', true, 1) === true);

/* -------------------------------------------------------------------------
 * The "Linked coupons" admin box
 *
 * Rendered for real: a box emitting broken markup wrecks the sidebar of
 * every store editor screen.
 * ---------------------------------------------------------------------- */

$GLOBALS['coupons'] = array(
    set_coupon(301, 'code', 'VOOPOO15', 12, 2),
    set_coupon(302, 'deal', '', 0, 0),
);
$GLOBALS['titles'][301] = '15% off everything sitewide at VooPoo';
$GLOBALS['titles'][302] = 'Free shipping on orders over $45';

ob_start();
vc_merchant_coupons_meta_box(new FakePost(1));
$box = ob_get_clean();

check('box markup has balanced <ul>/<li>/<span>',
    tags_balanced($box, 'ul') && tags_balanced($box, 'li') && tags_balanced($box, 'span'),
    $box);
check('box uses classes, not scattered inline styles on list items',
    strpos($box, 'vc-coupon-list__item') !== false
    && !preg_match('/<li[^>]*style=/', $box), $box);
check('box states the claim in words, not the internal key',
    strpos($box, 'Shows a verified code') !== false
    && strpos($box, 'verified_code<') === false, $box);
check('each coupon links through to its editor',
    substr_count($box, 'action=edit') === 2, $box);
check('reader vote split is shown',
    strpos($box, '12 ok') !== false && strpos($box, '2 bad') !== false, $box);
check('long coupon titles can wrap rather than overflow the sidebar',
    strpos($box, 'vc-coupon-list__title') !== false, $box);
check('the box links out to the brand\'s coupon list',
    strpos($box, 'post_type=wcd_coupon&wcd_brand=voopoo') !== false, $box);

// A term with no slug must not produce a broken filter link or a warning.
// vc_merchant_brand_term() memoizes per post for the request, so this needs
// its own store rather than a mutated copy of the one above.
$GLOBALS['titles'][2] = 'SlugLess';
$GLOBALS['terms'][51] = 'SlugLess';
$GLOBALS['term_slugs'][51] = null;
ob_start();
$warned = false;
set_error_handler(function () use (&$warned) { $warned = true; return true; });
vc_merchant_coupons_meta_box(new FakePost(2));
restore_error_handler();
$noslug = ob_get_clean();
check('a term with no slug raises no warning', !$warned);
check('and falls back to the unfiltered coupon list',
    strpos($noslug, 'post_type=wcd_coupon') !== false
    && strpos($noslug, 'wcd_brand=') === false, $noslug);

// With more than 8, the overflow must be acknowledged rather than silently cut.
$many = array();
for ($i = 0; $i < 11; $i++) {
    $many[] = set_coupon(400 + $i, 'code', 'C' . $i, 3, 0);
    $GLOBALS['titles'][400 + $i] = 'Coupon ' . $i;
}
$GLOBALS['coupons'] = $many;
ob_start();
vc_merchant_coupons_meta_box(new FakePost(1));
$box2 = ob_get_clean();
check('an overflowing list says how many are hidden',
    strpos($box2, '3 more not shown') !== false, $box2);

// An unmatched store must say so rather than render an empty box. Again its
// own post id, because of the per-post cache.
$GLOBALS['titles'][3] = 'Nothing Matches This';
ob_start();
vc_merchant_coupons_meta_box(new FakePost(3));
$unmatched = ob_get_clean();
check('an unmatched store explains how to link it',
    strpos($unmatched, 'No coupon brand matched') !== false, $unmatched);
check('and does not render a coupon list it has no brand for',
    strpos($unmatched, 'vc-coupon-list') === false, $unmatched);

/* -------------------------------------------------------------------------
 * The coupon grid must fit the column it is rendered in
 *
 * The plugin sets --wcd-columns as an INLINE style and its own breakpoints
 * respond to the viewport, so overriding it needs !important and a container
 * query.
 * ---------------------------------------------------------------------- */

$css = file_get_contents(__DIR__ . '/../assets/merchant-pages.css');
check('coupon widget is a query container',
    (bool) preg_match('/\.vc-coupon-widget\s*\{[^}]*container-type:\s*inline-size/s', $css));
check('column overrides beat the plugin inline --wcd-columns',
    (bool) preg_match('/--wcd-columns:\s*\d+\s*!important/', $css));
check('the narrow sidebar is forced to a single column',
    (bool) preg_match('/\.vc-col-side[^{]*\.wcd-grid\s*\{\s*--wcd-columns:\s*1\s*!important/s', $css));
check('cards cannot be pushed wider than their column',
    strpos($css, '.vc-coupon-widget .wcd-card { min-width: 0; }') !== false);

/* -------------------------------------------------------------------------
 * Which shortcode the coupon section renders
 *
 * Nothing in the CSV pipeline fills coupon_plugin_shortcode, so before the
 * template existed a store could be correctly matched to a brand, hold live
 * coupons, and still render an empty coupon section.
 * ---------------------------------------------------------------------- */

$GLOBALS['titles'][5] = 'VooPoo';
$GLOBALS['meta'][5] = array();
// Explicitly blanking the template must switch the section off -- the
// default only applies when the option was never set.
$GLOBALS['options'] = array('vc_coupon_shortcode_template' => '');
check('an explicitly emptied template renders nothing',
    vc_merchant_coupon_shortcode(5) === '', vc_merchant_coupon_shortcode(5));
$GLOBALS['options'] = array();

$GLOBALS['options']['vc_coupon_shortcode_template'] = '[coupon_deals brand="{brand}"]';
check('the template is filled in from the linked brand slug',
    vc_merchant_coupon_shortcode(5) === '[coupon_deals brand="voopoo"]',
    vc_merchant_coupon_shortcode(5));

$GLOBALS['titles'][6] = 'VooPoo';
$GLOBALS['meta'][6] = array();
$GLOBALS['options']['vc_coupon_shortcode_template'] = '[coupon_deals id="{brand_id}" title="{brand_name}"]';
check('{brand_id} and {brand_name} are substituted too',
    vc_merchant_coupon_shortcode(6) === '[coupon_deals id="50" title="VooPoo"]',
    vc_merchant_coupon_shortcode(6));

// A store's own field is a deliberate override and must win.
$GLOBALS['titles'][7] = 'VooPoo';
$GLOBALS['meta'][7] = array('coupon_plugin_shortcode' => '[coupon_deals brand="hand-picked" columns="1"]');
$GLOBALS['options']['vc_coupon_shortcode_template'] = '[coupon_deals brand="{brand}"]';
check('a store\'s own shortcode field overrides the template',
    vc_merchant_coupon_shortcode(7) === '[coupon_deals brand="hand-picked" columns="1"]',
    vc_merchant_coupon_shortcode(7));

// An unmatched store must render nothing rather than an unscoped shortcode,
// which would list every coupon on the site on every store page.
$GLOBALS['titles'][8] = 'Not A Known Brand';
$GLOBALS['meta'][8] = array();
check('an unmatched store renders nothing, not an unscoped shortcode',
    vc_merchant_coupon_shortcode(8) === '', vc_merchant_coupon_shortcode(8));

$GLOBALS['filters']['vc_merchant_coupon_shortcode_template'][] =
    function ($tpl, $id = null) { return '[filtered brand="{brand}"]'; };
$GLOBALS['titles'][9] = 'VooPoo';
$GLOBALS['meta'][9] = array();
check('a filter can replace the template',
    vc_merchant_coupon_shortcode(9) === '[filtered brand="voopoo"]',
    vc_merchant_coupon_shortcode(9));
$GLOBALS['filters']['vc_merchant_coupon_shortcode_template'] = array();

/* -------------------------------------------------------------------------
 * The integration contract is discovered, not assumed
 * ---------------------------------------------------------------------- */

$tags = vc_coupons_registered_shortcodes();
check('the coupon plugin\'s shortcode tags are discovered',
    in_array('coupon_deals', $tags, true) && in_array('coupon_reveal', $tags, true),
    json_encode($tags));
check('and unrelated shortcodes are not reported as the coupon plugin\'s',
    !in_array('merchant_page', $tags, true), json_encode($tags));

$GLOBALS['meta'][701] = array(
    '_wcd_type' => 'code', '_wcd_code' => 'X',
    '_wcd_success_count' => 1, '_wcd_fail_count' => 0,
);
$GLOBALS['probe_ids'] = array(701);
$probe = vc_coupons_meta_probe();
check('the meta probe reports a field the coupons do carry',
    ($probe['keys']['_wcd_code'] ?? 0) === 1, json_encode($probe));
check('and flags one they do not',
    ($probe['keys']['_wcd_expiration'] ?? null) === 0, json_encode($probe));

/* -------------------------------------------------------------------------
 * The contract, as verified against the coupon plugin's source
 *
 * [coupon_deals] filters wcd_brand by term SLUG, so the default template has
 * to pass a slug and not an id or a name.
 * ---------------------------------------------------------------------- */

check('the shipped default uses the tag the coupon plugin registers',
    strpos(VC_COUPON_SHORTCODE_DEFAULT, '[coupon_deals') === 0,
    VC_COUPON_SHORTCODE_DEFAULT);
check('and passes the brand as a slug, which is the field it matches on',
    strpos(VC_COUPON_SHORTCODE_DEFAULT, 'brand="{brand}"') !== false,
    VC_COUPON_SHORTCODE_DEFAULT);

// With no option set at all, a linked store must still render coupons.
$GLOBALS['options'] = array();
$GLOBALS['titles'][11] = 'VooPoo';
$GLOBALS['meta'][11] = array();
check('a linked store renders coupons with no configuration at all',
    vc_merchant_coupon_shortcode(11) === '[coupon_deals brand="voopoo"]',
    vc_merchant_coupon_shortcode(11));

/* -------------------------------------------------------------------------
 * A logo entered once in the coupon plugin serves both
 * ---------------------------------------------------------------------- */

$GLOBALS['titles'][12] = 'VooPoo';
$GLOBALS['meta'][12] = array();
$GLOBALS['term_meta'][50] = array('_wcd_brand_logo_url' => 'https://cdn.x.com/voopoo-logo.png');
check('the coupon brand logo is used when the store has none',
    apply_filters('vc_merchant_logo_url', '', 12) === 'https://cdn.x.com/voopoo-logo.png',
    apply_filters('vc_merchant_logo_url', '', 12));
check('but merchant data always wins over it',
    apply_filters('vc_merchant_logo_url', 'https://own.example/logo.png', 12)
        === 'https://own.example/logo.png');
$GLOBALS['term_meta'][50] = array();
check('and nothing is invented when neither has one',
    apply_filters('vc_merchant_logo_url', '', 12) === '');

/* -------------------------------------------------------------------------
 * Degrade cleanly without the coupon plugin
 *
 * It can be deactivated at any time, and the merchant pages must keep
 * working on their own prose-derived values.
 * ---------------------------------------------------------------------- */

$GLOBALS['has_coupon_plugin'] = false;
check('no brand term when the coupon plugin is inactive',
    vc_merchant_brand_term(1) === null);
check('status returns null so the prose-derived value stands',
    vc_merchant_coupon_status(1) === null);
check('org @id is left alone when the coupon plugin is inactive',
    apply_filters('vc_merchant_org_id', 'https://x/#merchant', 1) === 'https://x/#merchant');
check('our Offer node is emitted again when the coupon plugin is inactive',
    apply_filters('vc_merchant_emit_offer', true, 1) === true);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All coupon integration checks passed.\n";
