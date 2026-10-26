<?php
/**
 * Merchant sections on a ReHub store page.
 *
 * The failure that matters most is a mismatch. These pages carry shipping
 * policies, exclusion lists and discount codes; putting one store's
 * profile on another store's page publishes a false claim about both. So
 * the term is matched on the id the sync stored, and the name fallbacks
 * are exact -- never fuzzy, never "closest".
 *
 * Second: the stack must print exactly once. Three hooks can print it,
 * because ReHub's taxonomy template calls none of the obvious ones and a
 * store with no offers posted to it runs no loop at all.
 *
 *     php tests/test_storepage.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');
define('OBJECT', 'OBJECT');

$GLOBALS['posts'] = array();      // id => array(post_title, post_type)
$GLOBALS['meta'] = array();       // id => array(key => value)
$GLOBALS['terms'] = array();      // id => array(term_id, name, taxonomy)
$GLOBALS['queried'] = null;
$GLOBALS['is_tax'] = 'dealstore';
$GLOBALS['is_paged'] = false;
$GLOBALS['filters'] = array();
$GLOBALS['rendered_for'] = array();

function vc_merchant_post_types() { return array('merchant'); }

/** The archive module owns this in production; stubbed here. */
function vc_body_started() { return !empty($GLOBALS['vc_body_started']); }

/** The real renderer is exercised by test_fields; here we record the id. */
function vc_merchant_render_page($atts = array()) {
    $id = (int) ($atts['id'] ?? 0);
    $GLOBALS['rendered_for'][] = $id;
    return '<div class="vc-merchant-page">profile of '
        . ($GLOBALS['posts'][$id]['post_title'] ?? '?') . '</div>';
}
function vc_merchant_seo_title($id) { return 'SEO title for ' . $id; }
function vc_merchant_meta_description($id) { return 'SEO description for ' . $id; }

function get_posts($args = array()) {
    $key = $args['meta_key'] ?? '';
    $value = $args['meta_value'] ?? null;
    $out = array();
    foreach ($GLOBALS['posts'] as $id => $p) {
        if ($p['post_type'] !== 'merchant') { continue; }
        if ($key !== '' && (string) ($GLOBALS['meta'][$id][$key] ?? '') !== (string) $value) { continue; }
        $out[] = $id;
    }
    return array_slice($out, 0, (int) ($args['posts_per_page'] ?? 1));
}
function get_page_by_title($title, $output = 'OBJECT', $types = array()) {
    foreach ($GLOBALS['posts'] as $id => $p) {
        if ($p['post_type'] === 'merchant' && $p['post_title'] === $title) {
            return (object) array('ID' => $id);
        }
    }
    return null;
}
function get_the_title($id) { return $GLOBALS['posts'][$id]['post_title'] ?? ''; }
function get_edit_post_link($id) { return 'https://example.com/edit?p=' . (int) $id; }
function get_term($id, $tax = '') { return $GLOBALS['terms'][$id] ?? null; }
function get_queried_object() { return $GLOBALS['queried']; }
function is_tax($tax = '') { return $GLOBALS['is_tax'] === $tax; }
function is_paged() { return !empty($GLOBALS['is_paged']); }
function apply_filters($tag, $value) {
    $args = func_get_args();
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) {
        $value = call_user_func_array($fn, array_merge(array($value), array_slice($args, 2)));
    }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_action($tag, $fn = null, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return $s; }
function __($s, $d = null) { return $s; }
class WP_Error {}
function is_wp_error($t) { return $t instanceof WP_Error; }
class WP_Query {
    function is_main_query() { return true; }
}

require_once __DIR__ . '/../wp-merchant-storepage.php';

$fails = array();
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fails[] = $label; }
}

function merchant($id, $title, $meta = array()) {
    $GLOBALS['posts'][$id] = array('post_title' => $title, 'post_type' => 'merchant');
    $GLOBALS['meta'][$id] = $meta;
}
function dealstore($id, $name) {
    $GLOBALS['terms'][$id] = (object) array(
        'term_id' => $id, 'name' => $name, 'taxonomy' => 'dealstore');
    return $GLOBALS['terms'][$id];
}
function fresh($term = null) {
    unset($GLOBALS['vc_storepage_claimed'], $GLOBALS['vc_storepage_merchant']);
    $GLOBALS['rendered_for'] = array();
    $GLOBALS['is_tax'] = 'dealstore';
    $GLOBALS['is_paged'] = false;
    $GLOBALS['vc_body_started'] = true;
    $GLOBALS['queried'] = $term;
}
function fire($tag) {
    ob_start();
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) { $fn(new WP_Query()); }
    return ob_get_clean();
}
function description_hook($d = '') {
    foreach ($GLOBALS['filters']['get_the_archive_description'] ?? array() as $fn) { $d = $fn($d); }
    return $d;
}

merchant(101, '1st Class Cigar Humidors', array('rehub_dealstore_term_id' => 4923));
merchant(102, 'VooPoo', array('display_brand_name' => 'VooPoo'));
merchant(103, 'Juice Head', array());

$humidors = dealstore(4923, '1st Class Cigar Humidors');
$voopoo   = dealstore(5001, 'VooPoo');
$juice    = dealstore(5002, 'Juice Head');
$orphan   = dealstore(5003, 'Some Store We Never Imported');

/* --- Matching ---------------------------------------------------------- */

check('the stored term id wins',
    vc_storepage_merchant_for_term($humidors) === 101);
check('display_brand_name is the first fallback',
    vc_storepage_merchant_for_term($voopoo) === 102);
check('the post title is the last fallback',
    vc_storepage_merchant_for_term($juice) === 103);
check('an unmatched term matches nothing, rather than guessing',
    vc_storepage_merchant_for_term($orphan) === 0);
check('a null term is handled', vc_storepage_merchant_for_term(null) === 0);

// A term whose name is close but not equal must not match. Publishing one
// store's shipping policy on another's page is the worst outcome here.
$nearly = dealstore(5004, 'VooPoo Official Store');
check('a near-miss name does not match',
    vc_storepage_merchant_for_term($nearly) === 0,
    'exact match only: ' . vc_storepage_merchant_for_term($nearly));

/* --- Rendering --------------------------------------------------------- */

fresh($humidors);
$out = fire('loop_start');
check('the profile prints on the store page',
    strpos($out, 'profile of 1st Class Cigar Humidors') !== false, $out);
check('it is rendered for the matched merchant',
    $GLOBALS['rendered_for'] === array(101), json_encode($GLOBALS['rendered_for']));
check('it is wrapped so CSS can reach it',
    strpos($out, 'vc-storepage') !== false);

check('a later description call does not print it twice',
    description_hook('term desc') === 'term desc');

// A theme that does call the description hook, with no loop having run.
fresh($humidors);
$out = description_hook('term desc');
check('a theme that asks for the description gets it there',
    strpos($out, 'profile of 1st Class') !== false);
check('and the term description survives',
    strpos($out, 'term desc') !== false);
check('loop_start then prints nothing', trim(fire('loop_start')) === '');

// A store with no offers posted to it runs no loop at all.
fresh($humidors);
$out = fire('loop_no_results');
check('a store with no offers still gets its profile',
    strpos($out, 'profile of 1st Class') !== false, $out);
check('and not a second time from the loop',
    trim(fire('loop_start')) === '');

/* --- Where it must not print ------------------------------------------- */

fresh($orphan);
check('an unmatched store page prints nothing',
    trim(fire('loop_start')) === '');
check('and its description is untouched',
    description_hook('just the term') === 'just the term');

fresh($humidors);
$GLOBALS['is_paged'] = true;
check('page two of a store does not repeat the profile',
    trim(fire('loop_start')) === '');

fresh($humidors);
$GLOBALS['is_tax'] = 'ships_to';
check('a destination archive is left to its own copy',
    trim(fire('loop_start')) === '');

fresh($humidors);
$GLOBALS['is_tax'] = '';
check('an ordinary page prints nothing',
    trim(fire('loop_start')) === '');

/* --- Nothing is printed before the body starts ------------------------- */
//
// get_the_archive_description() is called during wp_head by SEO plugins and
// page builders. Answering one of those with a page of HTML put the whole
// profile inside <head>, and the text showed up above the site header,
// outside the theme template.

fresh($humidors);
$GLOBALS['vc_body_started'] = false;
check('nothing prints at loop_start before the body starts',
    trim(fire('loop_start')) === '');
check('and the description filter adds nothing in head',
    description_hook('term desc') === 'term desc');
check('nor the no-results hook',
    trim(fire('loop_no_results')) === '');
check('and the slot is left unclaimed for the body',
    !vc_storepage_claimed());

$GLOBALS['vc_body_started'] = true;
check('once the body starts it prints normally',
    strpos(fire('loop_start'), 'profile of 1st Class') !== false);

/* --- The off switch ---------------------------------------------------- */

fresh($humidors);
add_filter('vc_storepage_enabled', function () { return false; });
check('the filter turns it off', trim(fire('loop_start')) === '');
check('and no merchant is resolved', vc_storepage_current_merchant() === 0);
$GLOBALS['filters']['vc_storepage_enabled'] = array();

/* --- SEO title and description ----------------------------------------- */

fresh($humidors);
$title = 'Bare term name';
foreach ($GLOBALS['filters']['rank_math/frontend/title'] ?? array() as $fn) { $title = $fn($title); }
check('Rank Math gets the merchant title',
    $title === 'SEO title for 101', $title);

fresh($orphan);
$title = 'Bare term name';
foreach ($GLOBALS['filters']['rank_math/frontend/title'] ?? array() as $fn) { $title = $fn($title); }
check('an unmatched store keeps the theme title',
    $title === 'Bare term name', $title);

fresh($humidors);
$desc = 'Bare description';
foreach ($GLOBALS['filters']['rank_math/frontend/description'] ?? array() as $fn) { $desc = $fn($desc); }
check('Rank Math gets the merchant description',
    $desc === 'SEO description for 101', $desc);

/* --- The merchant is resolved once per request ------------------------- */

fresh($humidors);
vc_storepage_current_merchant();
$GLOBALS['terms'][4923]->name = 'Renamed mid-request';
check('the resolved merchant is cached for the request',
    vc_storepage_current_merchant() === 101);

echo "\n";
if ($fails) {
    echo count($fails) . " check(s) FAILED: " . implode(', ', $fails) . "\n";
    exit(1);
}
echo "All ReHub store page checks passed.\n";
