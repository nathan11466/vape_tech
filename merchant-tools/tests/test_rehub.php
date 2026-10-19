<?php
/**
 * The ReHub adapter.
 *
 * Every key it writes is read from the theme source. The risks are writing
 * to a site where ReHub's taxonomy is switched off, overwriting a store
 * field someone edited in ReHub, and letting the honesty gate slip -- a
 * "Verified" label on a store with no confirmed code would be the same
 * false claim the rest of the project refuses to make.
 *
 *     php tests/test_rehub.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['taxes'] = array('dealstore');
$GLOBALS['meta'] = array();
$GLOBALS['term_meta'] = array();
$GLOBALS['terms'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['assigned'] = array();
$GLOBALS['query_ids'] = array();
$GLOBALS['next_term'] = 70;

function taxonomy_exists($t) { return in_array($t, $GLOBALS['taxes'], true); }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function update_term_meta($id, $k, $v) { $GLOBALS['term_meta'][$id][$k] = $v; return true; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id] ?? ''; }
function get_term($id, $tax = '') { return $GLOBALS['terms'][$id] ?? null; }
function get_term_by($f, $v, $tax = '') {
    foreach ($GLOBALS['terms'] as $t) { if ($f === 'name' && $t->name === $v) return $t; }
    return false;
}
function wp_insert_term($name, $tax, $a = array()) {
    $id = $GLOBALS['next_term']++;
    $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name, 'taxonomy' => $tax);
    return array('term_id' => $id);
}
function wp_set_object_terms($id, $terms, $tax, $append = false) {
    $GLOBALS['assigned'][$id][$tax] = $terms;
}
function get_posts($a = array()) { return $GLOBALS['query_ids']; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function apply_filters($tag, $value) {
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) { $value = $fn($value); }
    return $value;
}
function add_filter($t, $fn, $p = 10, $n = 1) { $GLOBALS['filters'][$t][] = $fn; }
$GLOBALS['options'] = array();
function add_action($t, $fn = null, $p = 10, $n = 1) { $GLOBALS['hooks'][$t][] = $fn; }
function add_submenu_page() {}
function current_user_can() { return true; }
function wp_verify_nonce() { return false; }
function wp_nonce_field() {}
function submit_button() {}
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return $v; }
function esc_html__($v, $d = null) { return $v; }
function esc_html_e($v, $d = null) { echo $v; }
function __($v, $d = null) { return $v; }
function vc_merchant_post_types() { return array('merchant'); }
function vc_merchant_coupon_shortcode($id = null) {
    return $GLOBALS['coupon_sc'] ?? '[coupon_deals brand="voopoo"]';
}
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function get_permalink($id = null) { return 'https://vapingcheap.com/stores/store-' . $id . '/'; }
function get_term_link($t) {
    $t = is_object($t) ? $t : ($GLOBALS['terms'][$t] ?? null);
    return $t ? 'https://vapingcheap.com/coupon-codes/' . sanitize_title($t->name) . '/' : new WP_Error();
}
function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i','-',$v),'-')); }
function is_singular() { return !empty($GLOBALS['is_singular']); }
function get_post_type($id = null) { return $GLOBALS['current_type'] ?? 'merchant'; }
function get_the_ID() { return $GLOBALS['current_id'] ?? 0; }
function flush_rewrite_rules() { $GLOBALS['flushed'] = ($GLOBALS['flushed'] ?? 0) + 1; }
function checked($a, $b = true, $e = true) { return ''; }
function wp_safe_redirect($url, $code = 302) { throw new Redirected($url, $code); }
class Redirected extends Exception {
    public $url, $code;
    function __construct($u, $c) { parent::__construct('r'); $this->url = $u; $this->code = $c; }
}
function vc_merchant_display_name($id = null) {
    $d = trim((string) get_post_meta($id, 'display_brand_name', true));
    return $d !== '' ? $d : get_the_title($id);
}
function vc_merchant_seo_title($id = null) { return vc_merchant_display_name($id) . ' Coupon Codes'; }
function vc_merchant_offer_mode($id = null) {
    return (string) get_post_meta($id, 'offer_display_mode', true);
}
function vc_merchant_headline_discount($id = null) {
    $offer = (string) get_post_meta($id, 'best_offer_summary', true);
    return preg_match('/(\d{1,2})\s*%/', $offer, $m) ? array($m[1] . '%', '') : array('', '');
}
class WP_Error {
    private $c, $m;
    function __construct($c = '', $m = '') { $this->c = $c; $this->m = $m; }
    function get_error_message() { return $this->m; }
    function get_error_code() { return $this->c; }
}

require __DIR__ . '/../wp-merchant-rehub.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

function store($id, $extra = array()) {
    $GLOBALS['titles'][$id] = 'VooPoo';
    $GLOBALS['meta'][$id] = array_merge(array(
        'display_brand_name' => 'VooPoo',
        'brand_url'          => 'https://voopoo.com',
        'brand_logo_url'     => 'https://cdn.x.com/voopoo.png',
        'coupon_intro'       => 'VooPoo run frequent sitewide sales.',
        'best_ways_to_save'  => 'Sign up for the newsletter.',
        'best_offer_summary' => '20% off sitewide',
        'offer_display_mode' => 'verified_code',
        'restricted_states'  => 'Utah|Vermont',
    ), $extra);
    return $id;
}

/* -------------------------------------------------------------------------
 * It must refuse to act when ReHub's taxonomy is switched off
 * ---------------------------------------------------------------------- */

$GLOBALS['taxes'] = array();
check('the adapter reports ReHub as absent when the taxonomy is off',
    !vc_rehub_active());
$err = vc_rehub_sync_merchant(store(1));
check('and syncing returns an error rather than writing anything',
    is_wp_error($err) && $err->get_error_code() === 'vc_rehub_absent');
check('with a message naming the theme option to switch on',
    strpos($err->get_error_message(), 'Enable Affiliate Store taxonomy') !== false,
    $err->get_error_message());
check('and no store term was created', empty($GLOBALS['terms']));
$GLOBALS['taxes'] = array('dealstore');

/* -------------------------------------------------------------------------
 * A clean sync
 * ---------------------------------------------------------------------- */

$id = store(10);
$res = vc_rehub_sync_merchant($id);
check('a sync succeeds', !is_wp_error($res), is_wp_error($res) ? $res->get_error_message() : '');

$term_id = $res['term_id'];
check('a dealstore term is created from the display name',
    $GLOBALS['terms'][$term_id]->name === 'VooPoo');
check('the term id is remembered on the merchant',
    (int) get_post_meta($id, 'rehub_dealstore_term_id', true) === $term_id);
check('the merchant post is assigned to its own store term',
    in_array($term_id, $GLOBALS['assigned'][$id]['dealstore'] ?? array(), true));

// The term fields, under ReHub's own key names.
check('brand_url is written', get_term_meta($term_id, 'brand_url', true) === 'https://voopoo.com');
check('brandimage gets the logo',
    get_term_meta($term_id, 'brandimage', true) === 'https://cdn.x.com/voopoo.png');
check('brand_heading gets the SEO title',
    get_term_meta($term_id, 'brand_heading', true) === 'VooPoo Coupon Codes');
check('brand_short_description prefers coupon_intro',
    strpos(get_term_meta($term_id, 'brand_short_description', true), 'frequent sitewide') !== false);
check('brand_second_description gets the savings text',
    get_term_meta($term_id, 'brand_second_description', true) === 'Sign up for the newsletter.');

// The offer meta, on the post.
check('rehub_offer_discount gets the headline figure',
    get_post_meta($id, 'rehub_offer_discount', true) === '20%',
    get_post_meta($id, 'rehub_offer_discount', true));
check('rehub_offer_product_url gets the destination',
    get_post_meta($id, 'rehub_offer_product_url', true) === 'https://voopoo.com');
check('rehub_offer_disclaimer carries the exclusions',
    strpos(get_post_meta($id, 'rehub_offer_disclaimer', true), 'Utah, Vermont') !== false,
    get_post_meta($id, 'rehub_offer_disclaimer', true));

/* -------------------------------------------------------------------------
 * The honesty gate must survive the crossing
 *
 * ReHub's own "verified" label is a public claim. A store with no confirmed
 * code must not acquire one.
 * ---------------------------------------------------------------------- */

check('a verified_code store gets the Verified label',
    get_post_meta($id, 'rehub_offer_verify_label', true) === 'Verified',
    get_post_meta($id, 'rehub_offer_verify_label', true));

$deal = store(11, array('offer_display_mode' => 'best_deal'));
vc_rehub_sync_merchant($deal);
check('a best_deal store gets "Best deal", not "Verified"',
    get_post_meta($deal, 'rehub_offer_verify_label', true) === 'Best deal',
    get_post_meta($deal, 'rehub_offer_verify_label', true));

$none = store(12, array('offer_display_mode' => 'no_code_confirmed'));
vc_rehub_sync_merchant($none);
check('a store with no confirmed code gets NO verified label at all',
    get_post_meta($none, 'rehub_offer_verify_label', true) === '',
    get_post_meta($none, 'rehub_offer_verify_label', true));

// A threshold is not a discount, and must not cross as one.
$thresh = store(13, array('best_offer_summary' => 'Free shipping over $49'));
vc_rehub_sync_merchant($thresh);
check('a threshold does not cross as a discount figure',
    get_post_meta($thresh, 'rehub_offer_discount', true) === '',
    get_post_meta($thresh, 'rehub_offer_discount', true));

/* -------------------------------------------------------------------------
 * Work done on the ReHub side must not be destroyed
 * ---------------------------------------------------------------------- */

$hand = store(20);
$res = vc_rehub_sync_merchant($hand);
$t = $res['term_id'];
update_term_meta($t, 'brand_short_description', 'Hand-written by an editor in ReHub.');
update_post_meta($hand, 'rehub_offer_disclaimer', 'Hand-written disclaimer.');

vc_rehub_sync_merchant($hand);
check('a hand-edited store field survives a re-sync',
    get_term_meta($t, 'brand_short_description', true) === 'Hand-written by an editor in ReHub.',
    get_term_meta($t, 'brand_short_description', true));
check('and a hand-edited offer field survives too',
    get_post_meta($hand, 'rehub_offer_disclaimer', true) === 'Hand-written disclaimer.');

vc_rehub_sync_merchant($hand, true);
check('but overwrite=true does replace it, when asked explicitly',
    strpos(get_term_meta($t, 'brand_short_description', true), 'frequent sitewide') !== false,
    get_term_meta($t, 'brand_short_description', true));

/* -------------------------------------------------------------------------
 * Matching, and not duplicating
 * ---------------------------------------------------------------------- */

$before = count($GLOBALS['terms']);
vc_rehub_sync_merchant($id);
check('re-syncing does not create a second term for the same store',
    count($GLOBALS['terms']) === $before, count($GLOBALS['terms']) . " vs $before");

// An explicit term link overrides the name match.
$odd = store(30, array('display_brand_name' => 'Totally Different Name'));
$GLOBALS['terms'][500] = (object) array('term_id' => 500, 'name' => 'VooPoo Global',
    'taxonomy' => 'dealstore');
update_post_meta($odd, 'rehub_dealstore_term_id', 500);
$res = vc_rehub_sync_merchant($odd);
check('an explicit term link beats the name match',
    $res['term_id'] === 500, (string) $res['term_id']);

// A store with no name at all cannot be synced.
$GLOBALS['titles'][40] = '';
$GLOBALS['meta'][40] = array();
check('a merchant with no name is refused rather than creating a blank store',
    is_wp_error(vc_rehub_sync_merchant(40)));

/* -------------------------------------------------------------------------
 * The logo comes through the shared filter
 * ---------------------------------------------------------------------- */

// Its own store name: reusing "VooPoo" would match the term created above,
// which already has a logo, and the no-overwrite rule would rightly keep it.
$nologo = store(50, array('brand_logo_url' => '', 'display_brand_name' => 'Logoless Store'));
$GLOBALS['filters']['vc_merchant_logo_url'][] = function ($url) {
    return $url !== '' ? $url : 'https://cdn.x.com/from-coupon-plugin.png';
};
$res = vc_rehub_sync_merchant($nologo);
check('a logo held by the coupon plugin reaches ReHub',
    get_term_meta($res['term_id'], 'brandimage', true) === 'https://cdn.x.com/from-coupon-plugin.png',
    get_term_meta($res['term_id'], 'brandimage', true));
$GLOBALS['filters']['vc_merchant_logo_url'] = array();

/* -------------------------------------------------------------------------
 * Batching
 * ---------------------------------------------------------------------- */

$GLOBALS['query_ids'] = array();
for ($i = 100; $i < 112; $i++) { $GLOBALS['query_ids'][] = store($i); }
$batch = vc_rehub_sync_batch(5);
check('a batch stops at the limit', $batch['stores'] === 5, json_encode($batch));
check('and reports what is left', $batch['remaining'] === 7, json_encode($batch));

$GLOBALS['query_ids'][] = 40;  // the unnamed one
$batch = vc_rehub_sync_batch(0);
check('a batch collects errors instead of aborting',
    count($batch['errors']) === 1, json_encode($batch['errors']));

/* -------------------------------------------------------------------------
 * The maps must only name keys the theme actually reads
 * ---------------------------------------------------------------------- */

$theme_term_keys = array('brand_heading', 'brand_short_description', 'brand_url',
                         'cashback_notice', 'brand_second_description', 'brandimage');
$unknown = array_diff(array_keys(vc_rehub_term_map()), $theme_term_keys);
check('every store field written is one ReHub defines',
    empty($unknown), implode(', ', $unknown));

$theme_offer_keys = array('rehub_offer_btn_text', 'rehub_offer_coupon_date',
    'rehub_offer_coupon_mask', 'rehub_offer_disclaimer', 'rehub_offer_discount',
    'rehub_offer_logo_url', 'rehub_offer_name', 'rehub_offer_product_coupon',
    'rehub_offer_product_desc', 'rehub_offer_product_price',
    'rehub_offer_product_price_old', 'rehub_offer_product_thumb',
    'rehub_offer_product_url', 'rehub_offer_shortcode_generate',
    'rehub_offer_verify_label');
$unknown = array_diff(array_keys(vc_rehub_offer_map()), $theme_offer_keys);
check('every offer field written is one ReHub defines',
    empty($unknown), implode(', ', $unknown));

/* -------------------------------------------------------------------------
 * The handover
 *
 * Our store pages and ReHub's are two URLs for the same shop. Handing over
 * must redirect rather than delete -- 404ing 112 pages would throw away
 * whatever links and ranking they have -- and every internal link has to
 * agree with where the page actually is.
 * ---------------------------------------------------------------------- */

$GLOBALS['options'] = array();
$h = store(200);
$res = vc_rehub_sync_merchant($h);
$hterm = $res['term_id'];

check('handover is off by default', !vc_rehub_handover());
check('and a link points at our own page while it is off',
    strpos(vc_merchant_permalink($h), '/stores/') !== false, vc_merchant_permalink($h));

$GLOBALS['options']['vc_rehub_handover'] = '1';
check('handover reads as on once set', vc_rehub_handover());
check('and every link now points at the ReHub store',
    strpos(vc_merchant_permalink($h), '/coupon-codes/voopoo/') !== false,
    vc_merchant_permalink($h));

// A store with nowhere to go must keep its own URL rather than link nowhere.
$GLOBALS['titles'][201] = 'Never Synced';
$GLOBALS['meta'][201] = array('display_brand_name' => 'Never Synced');
check('an unsynced store keeps its own URL rather than linking nowhere',
    strpos(vc_merchant_permalink(201), '/stores/') !== false,
    vc_merchant_permalink(201));

// With ReHub absent the switch must be inert, however the option is set.
$GLOBALS['taxes'] = array();
check('handover is inert when ReHub is not installed', !vc_rehub_handover());
check('so links fall back to our own pages',
    strpos(vc_merchant_permalink($h), '/stores/') !== false);
$GLOBALS['taxes'] = array('dealstore');

/* --- The redirect ------------------------------------------------------ */

function fire_redirect() {
    foreach ($GLOBALS['hooks']['template_redirect'] ?? array() as $fn) {
        try { $fn(); } catch (Redirected $r) { return array($r->url, $r->code); }
    }
    return null;
}

$GLOBALS['is_singular'] = true;
$GLOBALS['current_id'] = $h;
$GLOBALS['current_type'] = 'merchant';
$r = fire_redirect();
check('a handed-over store page 301s to its ReHub store',
    $r && $r[1] === 301 && strpos($r[0], '/coupon-codes/voopoo/') !== false, json_encode($r));

$GLOBALS['current_id'] = 201;
check('a store with no ReHub store is NOT redirected',
    fire_redirect() === null);

$GLOBALS['current_id'] = $h;
$GLOBALS['current_type'] = 'post';
check('an unrelated post type is never redirected', fire_redirect() === null);
$GLOBALS['current_type'] = 'merchant';

$GLOBALS['is_singular'] = false;
check('an archive is never redirected', fire_redirect() === null);
$GLOBALS['is_singular'] = true;

$GLOBALS['options']['vc_rehub_handover'] = '';
check('nothing is redirected while handover is off', fire_redirect() === null);
$GLOBALS['options']['vc_rehub_handover'] = '1';

/* --- Noindex and sitemap, as belt and braces -------------------------- */

function robots_now() {
    $r = array('index' => 'index', 'follow' => 'follow');
    foreach ($GLOBALS['filters']['rank_math/frontend/robots'] ?? array() as $fn) {
        $r = $fn($r);
    }
    return $r;
}
$rb = robots_now();
check('a handed-over page is noindexed even if the redirect fails',
    ($rb['index'] ?? '') === 'noindex', json_encode($rb));
check('but still followed, so link equity moves on',
    ($rb['follow'] ?? '') === 'follow', json_encode($rb));

$GLOBALS['options']['vc_rehub_handover'] = '';
check('and it is indexable again once handover is off',
    (robots_now()['index'] ?? '') === 'index');
$GLOBALS['options']['vc_rehub_handover'] = '1';

$types = array('merchant' => true, 'post' => true);
foreach ($GLOBALS['filters']['wp_sitemaps_post_types'] ?? array() as $fn) {
    $types = $fn($types);
}
check('handed-over pages leave the sitemap',
    !isset($types['merchant']) && isset($types['post']), json_encode(array_keys($types)));

/* --- The guard against handing over too early ------------------------- */

$GLOBALS['query_ids'] = array($h, 201);
check('unmatched stores are counted before handover is allowed',
    vc_rehub_unmatched_count() === 1, (string) vc_rehub_unmatched_count());

$GLOBALS['query_ids'] = array($h);
check('and the count is zero once everything is synced',
    vc_rehub_unmatched_count() === 0, (string) vc_rehub_unmatched_count());

/* -------------------------------------------------------------------------
 * Getting coupon cards onto a ReHub store page
 *
 * ReHub's store listing queries post_type => 'post' only, so neither the
 * coupon plugin's wcd_coupon posts nor our merchant posts can ever appear in
 * it. Both store descriptions do run through do_shortcode, which is the one
 * route in.
 * ---------------------------------------------------------------------- */

$GLOBALS['options'] = array();
$e = store(300, array('best_ways_to_save' => 'Sign up for the newsletter.'));

check('no coupon shortcode is embedded while the option is off',
    vc_rehub_coupon_embed($e) === '', vc_rehub_coupon_embed($e));

$res = vc_rehub_sync_merchant($e);
check('so the bottom description is just our savings text',
    get_term_meta($res['term_id'], 'brand_second_description', true)
        === 'Sign up for the newsletter.',
    get_term_meta($res['term_id'], 'brand_second_description', true));

$GLOBALS['options']['vc_rehub_embed_coupons'] = '1';
check('the shortcode is embedded once the option is on',
    strpos(vc_rehub_coupon_embed($e), '[coupon_deals') === 0, vc_rehub_coupon_embed($e));

$e2 = store(301, array('display_brand_name' => 'Embed Store',
                       'best_ways_to_save' => 'Watch for seasonal sales.'));
$res2 = vc_rehub_sync_merchant($e2);
$desc = get_term_meta($res2['term_id'], 'brand_second_description', true);
check('the description keeps our text AND gains the shortcode',
    strpos($desc, 'Watch for seasonal sales.') !== false
    && strpos($desc, '[coupon_deals') !== false, $desc);
check('with the text first, so the cards sit below the prose',
    strpos($desc, 'Watch') < strpos($desc, '[coupon_deals'), $desc);

// A store with no savings text should still get the cards.
$e3 = store(302, array('display_brand_name' => 'Bare Store', 'best_ways_to_save' => ''));
$res3 = vc_rehub_sync_merchant($e3);
check('a store with no prose still gets the coupon cards',
    strpos(get_term_meta($res3['term_id'], 'brand_second_description', true), '[coupon_deals') === 0,
    get_term_meta($res3['term_id'], 'brand_second_description', true));

// An unscoped shortcode would list every coupon on the site on every page.
$GLOBALS['coupon_sc'] = '';
$e4 = store(303, array('display_brand_name' => 'No Brand Link'));
$res4 = vc_rehub_sync_merchant($e4);
check('a store with no coupon brand embeds nothing rather than an unscoped list',
    strpos(get_term_meta($res4['term_id'], 'brand_second_description', true), '[coupon_deals') === false,
    get_term_meta($res4['term_id'], 'brand_second_description', true));
unset($GLOBALS['coupon_sc']);

$GLOBALS['options']['vc_rehub_embed_coupons'] = '';

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All ReHub adapter checks passed.\n";
