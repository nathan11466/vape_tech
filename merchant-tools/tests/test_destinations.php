<?php
/**
 * The "Where this store ships" block.
 *
 * It reads the assigned taxonomy terms rather than the summary meta string,
 * so it is the first thing on a store page that reflects what is actually
 * assigned -- and the first that links stores to the destination pages.
 *
 * The constraint under test is restraint: 112 stores times 51 states is 5,600
 * links, which would dilute every one of them. A nationwide store must
 * collapse to the country.
 *
 *     php tests/test_destinations.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['meta'] = array();
$GLOBALS['post_terms'] = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['filters'] = array();

function taxonomy_exists($t) {
    return in_array($t, array('ships_to', 'service_location', 'merchant_category'), true);
}
function get_the_terms($id, $tax) { return $GLOBALS['post_terms'][$id][$tax] ?? false; }
function get_term_link($t) {
    return is_object($t) ? 'https://vapingcheap.com/ships-to/' . $t->slug . '/' : new WP_Error();
}
function get_term_by($field, $value, $tax = '') {
    foreach ($GLOBALS['post_terms'] as $sets) {
        foreach ($sets as $terms) {
            foreach ((array) $terms as $t) {
                if ($field === 'name' && $t->name === $value) { return $t; }
            }
        }
    }
    return false;
}
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function get_the_ID() { return 1; }
function get_the_title($id = null) { return 'VooPoo'; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function add_shortcode($tag, $fn) { $GLOBALS['shortcodes'][$tag] = $fn; }
function shortcode_atts($p, $a, $s = '') { return array_merge($p, (array) $a); }
function apply_filters($tag, $value) {
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) { $value = $fn($value); }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_action() {}
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function __($v, $d = null) { return $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function vc_section_meta($id, $k) { return (string) get_post_meta($id, $k, true); }
function vc_section_post_id($atts) { return !empty($atts['id']) ? (int) $atts['id'] : get_the_ID(); }
class WP_Error { function get_error_message() { return 'err'; } }

// Loading the whole sections module would pull in the rest of the plugin, so
// only the destinations functions are loaded -- sliced out of the real file at
// run time rather than copied, so the test cannot drift away from the source.
$source = file_get_contents(__DIR__ . '/../wp-merchant-sections.php');
$marker = '/**' . "\n" . ' * At or above this many US child terms';
$at = strpos($source, $marker);
if ($at === false) {
    echo "FAIL: cannot find the destinations functions in wp-merchant-sections.php\n";
    echo "      the marker comment moved or was removed; this test is now blind.\n";
    exit(1);
}
$slice = tempnam(sys_get_temp_dir(), 'vcdest') . '.php';
file_put_contents($slice, "<?php\n" . substr($source, $at));
require $slice;
@unlink($slice);

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}
function t($id, $name, $slug, $parent = 0) {
    return (object) array('term_id' => $id, 'name' => $name, 'slug' => $slug,
        'parent' => $parent, 'taxonomy' => 'ships_to');
}

$us = t(1, 'United States', 'united-states');
$states = array();
foreach (range(1, 51) as $i) {
    $states[] = t(100 + $i, "State $i", "state-$i", 1);
}

/* --- A nationwide store must not emit 51 links ----------------------- */

$GLOBALS['post_terms'][1]['ships_to'] = array_merge(array($us), $states);
$out = vc_merchant_destinations(1);

check('the block renders', strpos($out, 'vc-destinations') !== false, $out);
check('a nationwide store links the country, not every state',
    substr_count($out, '<a href=') === 1, substr_count($out, '<a href=') . ' links');
check('and says how many destinations that covers',
    strpos($out, '51 destinations') !== false, $out);
check('the country link points at the country page',
    strpos($out, '/ships-to/united-states/') !== false, $out);

/* --- A specific subset IS worth listing ------------------------------ */

$GLOBALS['post_terms'][2]['ships_to'] = array($us, $states[0], $states[1], $states[2]);
$out2 = vc_merchant_destinations(2);
check('a store serving a few states lists them all',
    substr_count($out2, '<a href=') === 3, substr_count($out2, '<a href=') . ' links');
check('and names the country as a heading rather than a link',
    strpos($out2, '<strong>United States:</strong>') !== false, $out2);

/* --- Exclusions are the part readers check for ----------------------- */

$GLOBALS['meta'][1]['restricted_states'] = 'State 3|Nowhereland';
$out3 = vc_merchant_destinations(1);
check('exclusions are listed', strpos($out3, 'Cannot ship to') !== false, $out3);
check('an excluded state that has a page is linked',
    strpos($out3, '/ships-to/state-3/') !== false, $out3);
check('an excluded place with no page still appears, unlinked',
    strpos($out3, 'Nowhereland') !== false
    && strpos($out3, '>Nowhereland</a>') === false, $out3);

/* --- Assumed coverage must stay marked as an assumption -------------- */

$GLOBALS['meta'][1]['shipping_confidence'] = 'assumed';
$out4 = vc_merchant_destinations(1);
check('assumed coverage is disclosed in the block itself',
    strpos($out4, 'our assumption') !== false, $out4);

$GLOBALS['meta'][1]['shipping_confidence'] = 'stated';
check('a stated coverage claim carries no disclaimer',
    strpos(vc_merchant_destinations(1), 'our assumption') === false);

/* --- Nothing assigned means nothing rendered ------------------------- */

check('a store with no destinations renders nothing',
    vc_merchant_destinations(99) === '', vc_merchant_destinations(99));

/* --- The threshold is tunable ---------------------------------------- */

$GLOBALS['filters']['vc_destinations_nationwide_threshold'][] = function () { return 2; };
$out5 = vc_merchant_destinations(2);
check('lowering the threshold collapses a smaller set too',
    substr_count($out5, '<a href=') === 1, substr_count($out5, '<a href=') . ' links');
$GLOBALS['filters']['vc_destinations_nationwide_threshold'] = array();

/* --- Serves / sells ---------------------------------------------------- */

$GLOBALS['post_terms'][3]['service_location'] = array(t(300, 'United States', 'us-loc'));
$GLOBALS['post_terms'][3]['merchant_category'] = array(
    t(301, 'Vape Juice', 'vape-juice'), t(302, 'Box Mods', 'box-mods'));
$tags = vc_merchant_taxonomy_tags(3);
check('service locations are shown', strpos($tags, 'Serves') !== false, $tags);
check('categories are shown and linked',
    strpos($tags, 'Sells') !== false && substr_count($tags, '<a href=') === 3, $tags);

check('a store with no tags renders nothing', vc_merchant_taxonomy_tags(99) === '');

/* --- Shortcodes ------------------------------------------------------- */

check('[merchant_destinations] is registered', isset($GLOBALS['shortcodes']['merchant_destinations']));
check('[merchant_tags] is registered', isset($GLOBALS['shortcodes']['merchant_tags']));
check('markup is balanced',
    substr_count($out3, '<ul') === substr_count($out3, '</ul>')
    && substr_count($out3, '<li') === substr_count($out3, '</li>')
    && substr_count($out3, '<section') === substr_count($out3, '</section>'), $out3);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All destination block checks passed.\n";
