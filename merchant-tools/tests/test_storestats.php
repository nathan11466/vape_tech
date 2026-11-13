<?php
/**
 * Counted store stats, and the offer summary table.
 *
 * The figures are the point: a reader trusts "10 coupon codes, best 50%,
 * 4 verified" precisely because it is counted. So the risks are a number
 * that overstates (counting a deal as a code, ranking $10 above 50%), a
 * zero presented as a finding when we simply have no data, and the summary
 * table printing a code the reader is supposed to click to reveal.
 *
 *     php tests/test_storestats.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['posts'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['modified'] = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['term_for'] = array();

function get_post_meta($id, $k, $single = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id] ?? ''; }
function get_post_field($field, $id) { return $GLOBALS['modified'][$id] ?? ''; }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function get_posts($a = array()) { return $GLOBALS['offer_ids'] ?? array(); }
function add_shortcode($t, $fn) { $GLOBALS['shortcodes'][$t] = $fn; }
function shortcode_atts($p, $a, $s = '') { return array_merge($p, is_array($a) ? $a : array()); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html__($v, $d = null) { return $v; }
function __($v, $d = null) { return $v; }
function date_i18n($f, $t = null) { return date($f, $t ?: time()); }
function vc_merchant_display_name($id = null) { return $GLOBALS['titles'][$id] ?? 'Store'; }
function vc_section_post_id($a) { return (int) ($a['id'] ?? 0); }
function vc_section_meta($id, $k) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function vc_rehub_store_term($id, $create = true) {
    return $GLOBALS['term_for'][$id] ?? null;
}

require __DIR__ . '/../wp-merchant-storestats.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

function offer($id, $title, $meta = array()) {
    $GLOBALS['titles'][$id] = $title;
    $GLOBALS['meta'][$id] = $meta;
    $GLOBALS['modified'][$id] = '2026-11-10 12:00:00';
    $GLOBALS['offer_ids'][] = $id;
}

$GLOBALS['titles'][1] = 'VooPoo';
$GLOBALS['meta'][1] = array();
$GLOBALS['term_for'][1] = (object) array('term_id' => 50, 'name' => 'VooPoo');
$GLOBALS['offer_ids'] = array();

offer(101, '50% off everything', array(
    'rehub_offer_product_coupon' => 'SAVE50', 'rehub_offer_discount' => '50%',
    'rehub_offer_verify_label' => 'Verified', 'rehub_offer_coupon_date' => '2027-01-05 12:00'));
offer(102, 'Free shipping weekend', array('rehub_offer_discount' => '')); // a deal
offer(103, '20% off coils', array(
    'rehub_offer_product_coupon' => 'COIL20', 'rehub_offer_discount' => '20%'));
offer(104, '$10 off orders', array('rehub_offer_discount' => '$10'));

$s = vc_stats_summary(1);

/* --- Counting ---------------------------------------------------------- */

check('every offer is counted', $s['offers'] === 4, $s['offers']);
check('a code is a code', $s['codes'] === 2, $s['codes']);
check('an offer with no code is a deal', $s['deals'] === 2, $s['deals']);
check('only a labelled offer counts as verified', $s['verified'] === 1, $s['verified']);

// The trap: a dollar figure must not be ranked against a percentage.
check('the best discount is the highest PERCENTAGE, not the biggest number',
    (float) $s['best_percent'] === 50.0, var_export($s['best_percent'], true));

check('a money discount is not read as a percentage',
    vc_stats_percent('$10') === null, var_export(vc_stats_percent('$10'), true));
check('and a percentage is', vc_stats_percent('50%') === 50.0);
check('with decimals kept', vc_stats_percent('12.5%') === 12.5);
check('an empty discount is nothing', vc_stats_percent('') === null);

/* --- A zero is an absence, not a finding ------------------------------- */

$panel = $GLOBALS['shortcodes']['merchant_stats'](array('id' => 1));
check('the panel shows the counts', strpos($panel, '>4<') !== false, $panel);
check('and the best discount as a percentage',
    strpos($panel, '50%') !== false, $panel);

// A store with offers but none verified must not say "Verified: 0", which
// reads as a judgement on the shop rather than a gap in our checking.
$GLOBALS['offer_ids'] = array();
offer(201, 'A deal', array('rehub_offer_discount' => '15%'));
$bare = $GLOBALS['shortcodes']['merchant_stats'](array('id' => 1));
check('a store with nothing verified omits the row rather than showing zero',
    strpos($bare, 'Verified') === false, $bare);
check('but still shows what it does have',
    strpos($bare, '15%') !== false, $bare);

// No offers at all, and no data: nothing to print.
$GLOBALS['offer_ids'] = array();
$GLOBALS['meta'][1] = array();
check('a store with no offers and no fields renders no panel at all',
    $GLOBALS['shortcodes']['merchant_stats'](array('id' => 1)) === '');

// Free shipping comes from the merchant, not the offers.
$GLOBALS['meta'][1] = array('free_shipping_threshold_usd' => '50');
check('a free-shipping threshold appears on its own',
    strpos($GLOBALS['shortcodes']['merchant_stats'](array('id' => 1)), 'Over $50') !== false,
    $GLOBALS['shortcodes']['merchant_stats'](array('id' => 1)));
$GLOBALS['meta'][1] = array();

/* --- The summary table must not give the code away --------------------- */

$GLOBALS['offer_ids'] = array();
offer(301, '50% off everything', array(
    'rehub_offer_product_coupon' => 'SAVE50', 'rehub_offer_discount' => '50%'));
offer(302, 'Free shipping', array('rehub_offer_discount' => '')); // no code

$table = $GLOBALS['shortcodes']['merchant_offer_table'](array('id' => 1));

check('the table lists the offer', strpos($table, '50% off everything') !== false);
check('the whole code is NOT printed',
    strpos($table, 'SAVE50') === false, $table);
check('but enough of it shows that the row means something',
    strpos($table, 'SAV') !== false, $table);
check('a deal says no code is needed rather than showing a blank cell',
    strpos($table, 'No code needed') !== false, $table);

// A short code keeps one character and still gets three stars: the mask is
// never shorter than that, so the length of a two-character code does not
// leak through the length of its mask.
foreach (array('SAVE50' => 'SAV***', 'A1' => 'A***', 'VAPE-20-OFF' => 'VAP********') as $raw => $want) {
    check("masking $raw", vc_stats_mask_code($raw) === $want, vc_stats_mask_code($raw));
}
check('an empty code masks to nothing', vc_stats_mask_code('') === '');
check('a masked code never contains the original in full',
    strpos(vc_stats_mask_code('SAVE50'), 'SAVE50') === false);

/* --- Nothing to show ---------------------------------------------------- */

$GLOBALS['offer_ids'] = array();
check('a store with no offers renders no table',
    $GLOBALS['shortcodes']['merchant_offer_table'](array('id' => 1)) === '');

// A store whose dealstore term does not exist must not query for offers.
$GLOBALS['term_for'][1] = null;
check('a store with no term has no offers',
    vc_stats_offer_ids(1) === array());

/* --- Escaping ---------------------------------------------------------- */

$GLOBALS['term_for'][1] = (object) array('term_id' => 50, 'name' => 'VooPoo');
$GLOBALS['offer_ids'] = array();
offer(401, '<script>x</script>', array('rehub_offer_discount' => '10%'));
check('an offer title is escaped in the table',
    strpos($GLOBALS['shortcodes']['merchant_offer_table'](array('id' => 1)), '<script>') === false);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All store stats checks passed.\n";
