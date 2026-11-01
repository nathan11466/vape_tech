<?php
/**
 * The repetition report.
 *
 * The thing it exists to catch is a column filled from a template, which
 * looks fine on any one page. The risks are the opposite failures: blanking
 * store names so eagerly that unrelated copy collapses together, or so
 * timidly that the same sentence with a different brand reads as distinct.
 * Both would send the editing effort to the wrong column.
 *
 *     php tests/test_repetition.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['posts'] = array();

function get_post_meta($id, $key, $single = false) {
    return $GLOBALS['posts'][$id]['meta'][$key] ?? '';
}
function get_the_title($id = null) { return $GLOBALS['posts'][$id]['title'] ?? ''; }
function vc_merchant_post_types() { return array('merchant'); }
function get_posts($args = array()) { return array_keys($GLOBALS['posts']); }
function apply_filters($tag, $value) { return $value; }
function add_action() {}
function add_submenu_page() {}
function current_user_can() { return true; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html__($v, $d = null) { return $v; }
function __($v, $d = null) { return $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }

require __DIR__ . '/../wp-merchant-repetition.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

/** Register a store with the given meta. */
function store($id, $title, $meta = array()) {
    $GLOBALS['posts'][$id] = array('title' => $title, 'meta' => $meta);

    return $id;
}

/* --- Name blanking is the whole trick --------------------------------- */

store(1, 'VooPoo', array('display_brand_name' => 'VooPoo'));
store(2, 'Vape Street', array('display_brand_name' => 'Vape Street'));
$names = vc_rep_store_names(array(1, 2));

check('the longest name sorts first, so a substring cannot strand a word',
    $names[0] === 'Vape Street', implode(' | ', $names));

$a = vc_rep_normalize('Free shipping over $50 at VooPoo.', $names);
$b = vc_rep_normalize('Free shipping over $50 at Vape Street.', $names);
check('the same sentence with a different brand normalizes identically',
    $a === $b, "$a vs $b");

check('and genuinely different copy does not',
    vc_rep_normalize('Free shipping over $50.', $names)
    !== vc_rep_normalize('Free shipping over $75.', $names));

// Blanking must not eat words that merely contain a name. "Vapers" and
// "vaped" both start with the whole of "Vape", which is a real store name,
// so without a word boundary this column turns to gibberish -- and two
// different sentences reduced to gibberish start matching each other.
store(3, 'Vape', array('display_brand_name' => 'Vape'));
$with_short = vc_rep_store_names(array(3));
$eaten = vc_rep_normalize('Vapers who vaped here rate it well', $with_short);
check('a name inside a longer word is left alone',
    strpos($eaten, 'vapers') !== false && strpos($eaten, 'vaped') !== false,
    $eaten);
check('but the name standing on its own is still blanked',
    strpos(vc_rep_normalize('Vape has free shipping', $with_short), 'vape') === false,
    vc_rep_normalize('Vape has free shipping', $with_short));

// A two-letter name would match inside half the dictionary.
store(4, 'Jo', array('display_brand_name' => 'Jo'));
check('a name too short to be safe is not used for blanking',
    !in_array('Jo', vc_rep_store_names(array(4)), true),
    implode(' | ', vc_rep_store_names(array(4))));

/* --- Counting a templated column -------------------------------------- */

$GLOBALS['posts'] = array();
$boiler = 'We check this code weekly and it worked at %s on our last pass.';
foreach (array('Alpha', 'Beta', 'Gamma', 'Delta') as $i => $n) {
    store(10 + $i, $n, array(
        'display_brand_name' => $n,
        'why_code_not_work'  => sprintf($boiler, $n),
        // One genuinely per-store column, as a control.
        'brand_summary'      => "$n sells a distinct range of unrelated products number $i.",
    ));
}
// A fifth store that was written by hand.
store(20, 'Epsilon', array(
    'display_brand_name' => 'Epsilon',
    'why_code_not_work'  => 'Epsilon rejects codes on clearance lines, which is most of the catalogue.',
    'brand_summary'      => 'Epsilon is a specialist in hardware nobody else imports.',
));

$report = vc_rep_report();
check('every store is measured', $report['stores'] === 5, $report['stores']);

$by_key = array();
foreach ($report['fields'] as $field) { $by_key[$field['key']] = $field; }

check('the templated column collapses to two distinct values',
    ($by_key['why_code_not_work']['distinct'] ?? 0) === 2,
    $by_key['why_code_not_work']['distinct'] ?? 'missing');
check('and four of the five stores are reported as repeating',
    ($by_key['why_code_not_work']['shared'] ?? 0) === 4,
    $by_key['why_code_not_work']['shared'] ?? 'missing');
check('the hand-written column reports nothing repeating',
    ($by_key['brand_summary']['shared'] ?? -1) === 0,
    $by_key['brand_summary']['shared'] ?? 'missing');

// The ordering is what makes the report actionable.
check('the worst column is listed first',
    $report['fields'][0]['key'] === 'why_code_not_work',
    $report['fields'][0]['key']);

// The cluster has to name the stores, or there is nothing to go and fix.
$cluster = reset($by_key['why_code_not_work']['clusters']);
check('the biggest cluster counts four stores',
    $cluster['count'] === 4, $cluster['count']);
check('and names them',
    in_array('Alpha', $cluster['stores'], true)
    && in_array('Delta', $cluster['stores'], true),
    implode(', ', $cluster['stores']));
check('and keeps one real value to show, not a normalized one',
    strpos($cluster['sample'], 'We check this code weekly') === 0,
    $cluster['sample']);

/* --- Empty and name-only values --------------------------------------- */

check('a column nobody filled is left out of the report',
    !isset($by_key['stacking_policy']), 'stacking_policy present');

$GLOBALS['posts'] = array();
store(30, 'Alpha', array('display_brand_name' => 'Alpha', 'coupon_intro' => 'Alpha'));
store(31, 'Beta', array('display_brand_name' => 'Beta', 'coupon_intro' => 'Beta'));
$r = vc_rep_report(array('coupon_intro'));
$f = $r['fields'][0];
check('a value that was only the store name counts as filled',
    $f['filled'] === 2, $f['filled']);
check('but is not reported as repeated wording',
    $f['shared'] === 0 && $f['distinct'] === 0,
    "shared {$f['shared']} distinct {$f['distinct']}");

/* --- Partial reuse ----------------------------------------------------- */

$GLOBALS['posts'] = array();
$long = 'Codes here apply to full price stock only and cannot be used on '
      . 'clearance or bundles or subscription orders at all';
store(40, 'Alpha', array('display_brand_name' => 'Alpha',
    'common_exclusions' => $long . ' except during seasonal sales.'));
store(41, 'Beta', array('display_brand_name' => 'Beta',
    'common_exclusions' => $long . ' and never on hardware of any kind whatsoever.'));
store(42, 'Gamma', array('display_brand_name' => 'Gamma',
    'common_exclusions' => 'Gamma has no exclusions worth mentioning on any order.'));

$r = vc_rep_report(array('common_exclusions'));
$near = $r['fields'][0]['near'];
check('two values edited from one another are reported as a pair',
    count($near) === 1, count($near) . ' pairs');
check('and the pair names both stores',
    isset($near[0]) && in_array($near[0]['a'], array('Alpha', 'Beta'), true)
    && in_array($near[0]['b'], array('Alpha', 'Beta'), true),
    isset($near[0]) ? "{$near[0]['a']} / {$near[0]['b']}" : 'none');
check('the unrelated third store is not dragged in',
    !isset($near[1]), 'extra pair reported');

// Identical copy belongs in the clusters, not reported twice as a near pair.
$GLOBALS['posts'] = array();
store(50, 'Alpha', array('display_brand_name' => 'Alpha', 'common_exclusions' => $long));
store(51, 'Beta', array('display_brand_name' => 'Beta', 'common_exclusions' => $long));
$r = vc_rep_report(array('common_exclusions'));
check('identical values are not also listed as partly reused',
    empty($r['fields'][0]['near']), count($r['fields'][0]['near']) . ' pairs');
check('they are counted as a cluster instead',
    $r['fields'][0]['shared'] === 2, $r['fields'][0]['shared']);

/* --- The report must never edit anything ------------------------------ */

$before = $GLOBALS['posts'];
vc_rep_report();
check('running the report leaves every stored value untouched',
    $GLOBALS['posts'] === $before);

/* --- Nothing to measure ----------------------------------------------- */

$GLOBALS['posts'] = array();
$r = vc_rep_report();
check('an empty site reports no stores rather than failing',
    $r['stores'] === 0 && $r['fields'] === array());

/* --- The audited set must match the pipeline's own ------------------- */

$audited = array_keys(vc_rep_fields());
// enrich_merchants.py REPEATING_BY_DESIGN: these repeat as a matter of fact,
// so scoring them would point the work at a problem that is not one.
foreach (array('payment_methods', 'verification_method', 'brand_category',
               'editor_name', 'last_checked_text', 'top_offer_type',
               'age_verification_required', 'coupon_plugin_shortcode') as $key) {
    check("$key is not audited -- it repeats by design",
        !in_array($key, $audited, true));
}
// And the editorial columns must all be covered, or repetition hides in one.
foreach (array('best_offer_summary', 'coupon_intro', 'brand_summary',
               'best_ways_to_save', 'free_shipping_info', 'return_policy_summary',
               'common_exclusions', 'stacking_policy', 'why_code_not_work',
               'shipping_restrictions', 'company_trust_info',
               'faq_1_answer', 'faq_2_answer', 'faq_3_answer') as $key) {
    check("$key is audited", in_array($key, $audited, true));
}

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All repetition report checks passed.\n";
