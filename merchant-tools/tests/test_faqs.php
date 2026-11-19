<?php
/**
 * FAQs: the duplication guard and the generated questions.
 *
 * The spreadsheet's three FAQ columns restated the policies table on all 112
 * stores -- "Shipping details vary by order" in the table and "Shipping
 * costs vary by order, so check your cart" in the FAQ. That is one fact
 * twice in different words, which is the pattern this project refuses
 * everywhere else, so it cannot be allowed here either.
 *
 * The opposite failure matters just as much: dropping a FAQ that was NOT a
 * restatement loses real content, and asking a question we cannot answer is
 * worse than not asking it.
 *
 *     php tests/test_faqs.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['posts'] = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['current_id'] = 1;

function get_post_meta($id, $key, $single = false) {
    return $GLOBALS['posts'][$id][$key] ?? '';
}
function update_post_meta($id, $key, $value) {
    $GLOBALS['posts'][$id][$key] = $value;

    return true;
}
function get_the_title($id = null) { return $GLOBALS['titles'][$id] ?? 'Store'; }
function get_the_ID() { return $GLOBALS['current_id']; }
function apply_filters($tag, $value) { return $value; }
function add_filter() {}
function add_action() {}
function add_shortcode($tag, $fn) { $GLOBALS['shortcodes'][$tag] = $fn; }
function shortcode_atts($pairs, $atts, $sc = '') {
    return array_merge($pairs, is_array($atts) ? $atts : array());
}
function do_shortcode($s) { return $s; }
function shortcode_exists($t) { return false; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return $v; }
function esc_html__($v, $d = null) { return $v; }
function __($v, $d = null) { return $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function wpautop($v) { return '<p>' . $v . '</p>'; }
function wp_kses_post($v) { return $v; }
function current_user_can() { return false; }
function is_wp_error($v) { return false; }
function get_option($k, $d = false) { return $d; }
function taxonomy_exists($t) { return false; }
function get_the_terms($id, $tax) { return false; }
function get_posts($a = array()) { return array_keys($GLOBALS['posts']); }
function get_permalink($id = null) { return 'https://example.com/s/'; }
function get_term_link($t) { return ''; }
function sanitize_title($v) { return strtolower($v); }
function vc_merchant_post_types() { return array('merchant'); }
function vc_merchant_display_name($id = null) {
    $d = trim((string) get_post_meta($id, 'display_brand_name', true));
    return $d !== '' ? $d : get_the_title($id);
}
function vc_block_heading($block, $id, $default) { return $default; }

require __DIR__ . '/../wp-merchant-sections.php';
require __DIR__ . '/../wp-merchant-repetition.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

function faqs($id) {
    return $GLOBALS['shortcodes']['merchant_faqs'](array('id' => $id));
}

/* --- The real duplication, taken verbatim from the live page ----------- */

$GLOBALS['titles'][1] = '2Fdeal';
$GLOBALS['posts'][1] = array(
    'display_brand_name' => '2Fdeal',
    'free_shipping_info' => 'Shipping details vary by order — check cart at checkout',
    'return_policy_summary' => 'DOA items can be returned within 5 days of delivery for exchange or refund; products carry a 3-month warranty with free replacement within 1 month of shipment',
    'faq_1_question' => 'Does 2Fdeal offer free shipping?',
    'faq_1_answer'   => 'Shipping costs for 2Fdeal vary by order, so check your cart total at checkout for exact rates.',
    'faq_2_question' => "What is 2Fdeal's return policy?",
    'faq_2_answer'   => '2Fdeal allows DOA items to be returned within 5 days of delivery, and products carry a 3-month warranty with free replacement within 1 month of shipment.',
    'faq_3_question' => 'Can I stack multiple 2Fdeal coupon codes?',
    'faq_3_answer'   => 'Something genuinely different about stacking that the table does not say at all.',
);

$out = faqs(1);
check('the returns FAQ restating the returns row is dropped',
    strpos($out, 'allows DOA items to be returned') === false, $out);
check('the shipping FAQ restating the shipping row is dropped',
    strpos($out, 'vary by order, so check your cart') === false, $out);
check('a FAQ that says something new is kept',
    strpos($out, 'genuinely different about stacking') !== false, $out);

/* --- Not over-eager --------------------------------------------------- */

$GLOBALS['posts'][2] = array(
    'display_brand_name' => 'VooPoo',
    'free_shipping_info' => 'Free shipping on orders over $50.',
    'faq_1_question' => 'Which couriers does VooPoo use?',
    'faq_1_answer'   => 'VooPoo ships with USPS Priority Mail and UPS Ground on heavier orders.',
);
$GLOBALS['titles'][2] = 'VooPoo';

check('an unrelated FAQ about the same topic is kept',
    strpos(faqs(2), 'USPS Priority Mail') !== false, faqs(2));

// Two FAQs that restate each other: the second goes, not just policy rows.
$GLOBALS['posts'][3] = array(
    'display_brand_name' => 'Alpha',
    'faq_1_question' => 'What is the return window?',
    'faq_1_answer'   => 'Returns are accepted within thirty days of delivery for unopened items only.',
    'faq_2_question' => 'Can I send something back?',
    'faq_2_answer'   => 'Returns are accepted within thirty days of delivery for unopened items only.',
);
$GLOBALS['titles'][3] = 'Alpha';
$out3 = faqs(3);
check('two FAQs restating each other collapse to one',
    substr_count($out3, '<div class="vc-faq">') === 1,
    substr_count($out3, '<div class="vc-faq">') . ' kept');

/* --- The questions that were asked for -------------------------------- */

$GLOBALS['posts'][4] = array(
    'display_brand_name'        => 'Vape Street',
    'do_they_id_on_delivery'    => 'Yes — the courier checks photo ID at the door and will not leave the parcel.',
    'age_verification_method'   => 'AgeChecker|Adult signature on delivery',
    'age_verification_required' => '21+ nationwide.',
    'processing_days'           => '2',
    'warranty_days'             => '90',
);
$GLOBALS['titles'][4] = 'Vape Street';

$out4 = faqs(4);
check('"Does X require ID on delivery?" is asked',
    strpos($out4, 'Does Vape Street require ID on delivery?') !== false, $out4);
check('and answered from the field',
    strpos($out4, 'courier checks photo ID at the door') !== false);
check('how age is verified is asked',
    strpos($out4, 'How does Vape Street verify your age?') !== false);
check('the minimum age is asked',
    strpos($out4, 'How old do you have to be') !== false);
check('dispatch time is asked and reads as plural',
    strpos($out4, 'within 2 days') !== false, $out4);
check('warranty is asked',
    strpos($out4, '90-day warranty') !== false, $out4);

// One day, not "1 days".
$GLOBALS['posts'][5] = array('display_brand_name' => 'Solo', 'processing_days' => '1');
$GLOBALS['titles'][5] = 'Solo';
check('one day is singular',
    strpos(faqs(5), 'within 1 day of') !== false, faqs(5));

/* --- A question we cannot answer is never asked ----------------------- */

$GLOBALS['posts'][6] = array('display_brand_name' => 'Bare');
$GLOBALS['titles'][6] = 'Bare';
check('a store with no data produces no FAQ block at all',
    faqs(6) === '', faqs(6));

$GLOBALS['posts'][7] = array(
    'display_brand_name' => 'Partial',
    'do_they_id_on_delivery' => 'Yes, ID is checked on delivery.',
);
$GLOBALS['titles'][7] = 'Partial';
$out7 = faqs(7);
check('a store with one field gets exactly one question',
    substr_count($out7, '<div class="vc-faq">') === 1, $out7);
check('and no question about the fields it has no data for',
    strpos($out7, 'warranty') === false && strpos($out7, 'dispatch') === false, $out7);

// A non-numeric value in a numeric field must not reach the page.
$GLOBALS['posts'][8] = array('display_brand_name' => 'Junk',
    'processing_days' => 'varies', 'warranty_days' => 'see site');
$GLOBALS['titles'][8] = 'Junk';
check('a non-numeric day count is skipped rather than printed',
    faqs(8) === '', faqs(8));

/* --- More than three columns ------------------------------------------ */

$GLOBALS['posts'][9] = array('display_brand_name' => 'Many');
$GLOBALS['titles'][9] = 'Many';
for ($i = 1; $i <= 6; $i++) {
    // Each answer must be genuinely unlike the others: the first version of
    // this fixture ended every one with the same clause, so the duplication
    // guard collapsed all six and the test blamed the column limit.
    $GLOBALS['posts'][9]["faq_{$i}_question"] = "Question number $i about this store?";
    $GLOBALS['posts'][9]["faq_{$i}_answer"] = array(
        1 => 'Orders leave the warehouse in Nevada on weekday mornings only.',
        2 => 'Bulk buyers can apply for a trade account by emailing the sales desk.',
        3 => 'Gift cards are sold in fixed denominations and never expire.',
        4 => 'Loyalty points accrue at one per dollar and convert quarterly.',
        5 => 'Pre-orders are charged when the item is allocated, not at checkout.',
        6 => 'Trade-in credit is offered on selected mods through a partner scheme.',
    )[$i];
}
check('a fourth, fifth and sixth FAQ column are read',
    substr_count(faqs(9), '<div class="vc-faq">') === 6,
    substr_count(faqs(9), '<div class="vc-faq">') . ' kept');

/* --- The topic test must not be greedy -------------------------------- */

// Each of these is about a subject the policies table touches without being
// the question that table answers. Losing them would cost real content.
$GLOBALS['posts'][11] = array(
    'display_brand_name'    => 'Keep',
    'free_shipping_info'    => 'Free shipping over $50.',
    'return_policy_summary' => 'Thirty days, unopened.',
    'payment_methods'       => 'Cards and crypto.',
    'stacking_policy'       => 'One code per order.',
);
$GLOBALS['titles'][11] = 'Keep';
foreach (array(
    'Which couriers does Keep use?'            => 'Keep ships with USPS and UPS.',
    'Does Keep ship discreetly?'               => 'Parcels carry no branding on the outside.',
    'Can I track my Keep order?'                => 'A tracking number is emailed on dispatch.',
    'Does Keep deliver on Saturdays?'           => 'Saturday delivery is available at extra cost.',
    'Is my card charged before dispatch?'       => 'Payment is taken when the order is allocated.',
) as $q => $a) {
    $GLOBALS['posts'][11]['faq_1_question'] = $q;
    $GLOBALS['posts'][11]['faq_1_answer'] = $a;
    check('kept: ' . $q, strpos(faqs(11), $a) !== false, faqs(11));
}

// And these must go: each IS the question a rendering row answers.
foreach (array(
    'Does Keep offer free shipping?'       => 'free_shipping_info',
    'What is the shipping cost at Keep?'   => 'free_shipping_info',
    "What is Keep's return policy?"        => 'return_policy_summary',
    'Can I return an item to Keep?'        => 'return_policy_summary',
    'What payment methods does Keep take?' => 'payment_methods',
    'Can I stack Keep codes?'              => 'stacking_policy',
) as $q => $row) {
    $GLOBALS['posts'][11]['faq_1_question'] = $q;
    $GLOBALS['posts'][11]['faq_1_answer'] = 'A restatement of the ' . $row . ' row.';
    check('dropped: ' . $q, strpos(faqs(11), 'A restatement of the') === false, faqs(11));
}

// A row that is empty answers nothing, so the FAQ is the only place the
// reader can learn it and must survive.
$GLOBALS['posts'][12] = array(
    'display_brand_name' => 'Thin',
    'faq_1_question'     => 'Does Thin offer free shipping?',
    'faq_1_answer'       => 'Yes, over $40 within the lower 48.',
);
$GLOBALS['titles'][12] = 'Thin';
check('with no shipping row, the shipping FAQ is kept',
    strpos(faqs(12), 'over $40 within the lower 48') !== false, faqs(12));

/* --- Escaping ---------------------------------------------------------- */

$GLOBALS['posts'][10] = array('display_brand_name' => 'Evil',
    'faq_1_question' => '<script>q</script>', 'faq_1_answer' => '<script>a</script>');
$GLOBALS['titles'][10] = 'Evil';
check('a question and answer are escaped',
    strpos(faqs(10), '<script>') === false, faqs(10));

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All FAQ checks passed.\n";
