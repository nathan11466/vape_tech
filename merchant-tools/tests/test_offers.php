<?php
/**
 * Migrating coupons onto ReHub's native offers.
 *
 * This one creates content, which makes the failure modes expensive: a
 * duplicate post per coupon per run, a coupon landing on the wrong store,
 * 300 drafts published by accident, or empty posts created from a field map
 * of guessed key names. Each of those is checked here.
 *
 *     php tests/test_offers.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['terms'] = array();
$GLOBALS['object_terms'] = array();
$GLOBALS['assigned'] = array();
$GLOBALS['options'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['taxes'] = array('dealstore', 'wcd_brand');
$GLOBALS['next_post'] = 900;

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function apply_filters($tag, $value) {
    $args = func_get_args();
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) {
        $value = $fn($value, $args[2] ?? null);
    }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $n = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_action() {}
function is_admin() { return false; }
function taxonomy_exists($t) { return in_array($t, $GLOBALS['taxes'], true); }
function post_type_exists($t) { return true; }
function vc_merchant_post_types() { return array('merchant'); }

function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_meta($id, $key = '', $single = false) {
    if ($key === '') {
        $out = array();
        foreach ($GLOBALS['meta'][$id] ?? array() as $k => $v) { $out[$k] = array($v); }
        return $out;
    }
    return $GLOBALS['meta'][$id][$key] ?? '';
}
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_the_terms($id, $tax) { return $GLOBALS['object_terms'][$id][$tax] ?? false; }
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_set_object_terms($id, $terms, $tax, $append = false) {
    $GLOBALS['assigned'][$id][$tax] = $terms;
    return $terms;
}
function wp_insert_post($arr, $wp_error = false) {
    $id = $GLOBALS['next_post']++;
    $GLOBALS['posts'][$id] = (object) $arr;
    $GLOBALS['posts'][$id]->ID = $id;
    return $id;
}
function wp_update_post($arr, $wp_error = false) {
    $id = (int) $arr['ID'];
    foreach ($arr as $k => $v) { $GLOBALS['posts'][$id]->$k = $v; }
    return $id;
}
function get_posts($args = array()) {
    $type = $args['post_type'] ?? 'post';
    $types = is_array($type) ? $type : array($type);
    $key = $args['meta_key'] ?? '';
    $want = $args['meta_value'] ?? null;
    $out = array();
    foreach ($GLOBALS['posts'] as $id => $p) {
        if (!in_array($p->post_type ?? 'post', $types, true)) { continue; }
        if ($key !== '' && (string) ($GLOBALS['meta'][$id][$key] ?? '') !== (string) $want) {
            continue;
        }
        $out[] = $id;
    }
    $limit = (int) ($args['posts_per_page'] ?? -1);
    return $limit > 0 ? array_slice($out, 0, $limit) : $out;
}
function vc_rehub_store_term($post_id, $create = true) {
    $id = (int) ($GLOBALS['meta'][$post_id]['rehub_dealstore_term_id'] ?? 0);
    return $GLOBALS['terms'][$id] ?? null;
}
function get_template_directory() { return '/nonexistent'; }
function get_stylesheet_directory() { return '/nonexistent'; }
class WP_Error {
    private $m;
    function __construct($c = '', $m = '') { $this->m = $m; }
    function get_error_message() { return $this->m; }
}

require __DIR__ . '/../wp-merchant-offers.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

/** A dealstore term. */
function dealstore($id, $name) {
    $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name);
    return $id;
}
/** A merchant linked to a brand term and a dealstore term. */
function merchant($id, $name, $brand_term, $store_term) {
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => 'merchant',
        'post_title' => $name);
    $GLOBALS['meta'][$id] = array('display_brand_name' => $name,
        'wcd_brand_term_id' => $brand_term, 'rehub_dealstore_term_id' => $store_term);
}
/** A coupon in a brand term. */
function coupon($id, $title, $brand_term, $meta = array()) {
    $GLOBALS['posts'][$id] = (object) array('ID' => $id, 'post_type' => 'wcd_coupon',
        'post_title' => $title, 'post_content' => 'Body.', 'post_excerpt' => '');
    $GLOBALS['meta'][$id] = $meta;
    $GLOBALS['object_terms'][$id]['wcd_brand'] = array(
        (object) array('term_id' => $brand_term, 'name' => 'Brand ' . $brand_term));
}

dealstore(50, '2Fdeal');
dealstore(51, 'VooPoo');
merchant(10, '2Fdeal', 300, 50);
merchant(11, 'VooPoo', 301, 51);
coupon(20, '60% off atomizers', 300, array('wcd_code' => 'SAVE60'));
coupon(21, 'Free shipping', 301, array('wcd_code' => 'SHIPFREE'));

/* --- The guard that matters most -------------------------------------- */

check('with no field map, nothing is considered ready to migrate',
    vc_offers_map_ready() === false);

/* --- Resolving which store a coupon belongs to ------------------------ */

check('a coupon resolves to its merchant through the shared brand term',
    vc_offers_merchant_of(20) === 10, vc_offers_merchant_of(20));
check('and a different coupon to a different merchant',
    vc_offers_merchant_of(21) === 11, vc_offers_merchant_of(21));
check('the dealstore term follows from the merchant',
    (vc_offers_term_for_coupon(20)->name ?? '') === '2Fdeal');

// A coupon whose brand nobody claims must not be guessed onto a store.
coupon(22, 'Orphan coupon', 999);
check('a coupon whose brand matches no merchant resolves to nothing',
    vc_offers_merchant_of(22) === 0);
check('and is skipped rather than placed on a guess',
    vc_offers_migrate_one(22, true)[0] === 'skipped',
    vc_offers_migrate_one(22, true)[2]);

// Names must never be used as a fallback: that is how a coupon lands on the
// wrong shop, which is worse than it landing on none.
$GLOBALS['object_terms'][23]['wcd_brand'] = array(
    (object) array('term_id' => 777, 'name' => '2Fdeal'));
$GLOBALS['posts'][23] = (object) array('ID' => 23, 'post_type' => 'wcd_coupon',
    'post_title' => 'Name twin', 'post_content' => '');
check('a brand term with a matching NAME but no link is not matched',
    vc_offers_merchant_of(23) === 0, vc_offers_merchant_of(23));

/* --- Dry run writes nothing ------------------------------------------- */

$GLOBALS['filters']['vc_offers_field_map'][] = function ($map) {
    return array('rehub_offer_coupon_code' => 'wcd_code');
};
check('the map now reads as ready', vc_offers_map_ready() === true);

$before_posts = count($GLOBALS['posts']);
$before_meta = $GLOBALS['meta'];
list($action, $id, $msg) = vc_offers_migrate_one(20, true);
check('a dry run reports what it would do', $action === 'would create', $action);
check('and names the store and the field count',
    strpos($msg, '2Fdeal') !== false && strpos($msg, '1 field') !== false, $msg);
check('a dry run creates no post', count($GLOBALS['posts']) === $before_posts);
check('and writes no meta', $GLOBALS['meta'] === $before_meta);

/* --- Applying it ------------------------------------------------------- */

list($action, $new_id, $msg) = vc_offers_migrate_one(20, false);
check('applying it creates a post', $action === 'created' && $new_id > 0, $action);
check('the created post is an ordinary post, which is the whole point',
    ($GLOBALS['posts'][$new_id]->post_type ?? '') === 'post');
check('it is a draft unless publishing was asked for',
    ($GLOBALS['posts'][$new_id]->post_status ?? '') === 'draft',
    $GLOBALS['posts'][$new_id]->post_status ?? '?');
check('the mapped field is carried across',
    ($GLOBALS['meta'][$new_id]['rehub_offer_coupon_code'] ?? '') === 'SAVE60',
    $GLOBALS['meta'][$new_id]['rehub_offer_coupon_code'] ?? '');
check('it is assigned to the right dealstore term',
    ($GLOBALS['assigned'][$new_id]['dealstore'] ?? array()) === array(50),
    implode(',', $GLOBALS['assigned'][$new_id]['dealstore'] ?? array()));
check('the title comes from the coupon',
    ($GLOBALS['posts'][$new_id]->post_title ?? '') === '60% off atomizers');
check('and the coupon it came from is recorded',
    (int) ($GLOBALS['meta'][$new_id]['_vc_offer_source'] ?? 0) === 20);

/* --- Running it twice must not duplicate ------------------------------ */

$count_before = count($GLOBALS['posts']);
list($action2, $same_id, ) = vc_offers_migrate_one(20, false);
check('a second run updates rather than duplicating',
    $action2 === 'updated', $action2);
check('and it is the same post', $same_id === $new_id);
check('so no second post appears', count($GLOBALS['posts']) === $count_before);

/* --- The source is never touched -------------------------------------- */

check('the coupon post still exists and is unchanged',
    ($GLOBALS['posts'][20]->post_type ?? '') === 'wcd_coupon'
    && ($GLOBALS['meta'][20]['wcd_code'] ?? '') === 'SAVE60');

/* --- Publishing is opt-in --------------------------------------------- */

list(, $pub_id, ) = vc_offers_migrate_one(21, false, true);
check('publishing happens only when asked',
    ($GLOBALS['posts'][$pub_id]->post_status ?? '') === 'publish');

/* --- The category, when set ------------------------------------------- */

$GLOBALS['options']['vc_offers_category'] = 77;
list(, $cat_id, ) = vc_offers_migrate_one(20, false);
check('a configured category is applied, so the blog can be filtered',
    ($GLOBALS['assigned'][$cat_id]['category'] ?? array()) === array(77));
$GLOBALS['options'] = array();

/* --- Inspecting the source ------------------------------------------- */

$report = vc_offers_inspect_source();
check('inspecting finds the coupons', $report['sampled'] >= 2, $report['sampled']);
check('and lists their real meta keys with an example',
    ($report['keys']['wcd_code'] ?? '') !== '', json_encode($report['keys']));

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All native offer migration checks passed.\n";
