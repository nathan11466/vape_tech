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
function current_time($f) { return date($f); }
function get_option($k, $d = false) { return $d; }
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
