<?php
/**
 * Where the stylesheet loads.
 *
 * It used to load on every page on the site: 22KB of render-blocking CSS
 * on the homepage, on every post, on the cart, styling nothing at all.
 * Nobody notices that, which is precisely why it needs a test -- the
 * failure is invisible and permanent.
 *
 * The rule: registered everywhere, enqueued only where this plugin renders.
 *
 *     php tests/test_assets.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['singular'] = false;      // false | post type string
$GLOBALS['tax'] = '';              // current taxonomy
$GLOBALS['storepage_merchant'] = 0;
$GLOBALS['post_content'] = '';
$GLOBALS['filters'] = array();

function vc_merchant_post_types() { return array('merchant'); }
function vc_archive_taxonomies() { return array('ships_to', 'service_location', 'merchant_category'); }
function vc_storepage_current_merchant() { return (int) $GLOBALS['storepage_merchant']; }

function is_singular($types = '') {
    if ($GLOBALS['singular'] === false) { return false; }
    if ($types === '' || $types === array()) { return true; }
    return in_array($GLOBALS['singular'], (array) $types, true);
}
function is_tax($taxes = '') {
    if ($GLOBALS['tax'] === '') { return false; }
    if ($taxes === '' || $taxes === array()) { return true; }
    return in_array($GLOBALS['tax'], (array) $taxes, true);
}
function taxonomy_exists($t) { return true; }
function get_post() {
    return $GLOBALS['singular'] === false ? null
        : (object) array('post_content' => $GLOBALS['post_content']);
}
function has_shortcode($content, $tag) { return strpos((string) $content, '[' . $tag) !== false; }
function apply_filters($tag, $value) {
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) { $value = $fn($value); }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }

// Only the needs-styles decision is under test here; pull it out of the
// plugin file rather than loading the whole plugin and its hooks.
$src = file_get_contents(__DIR__ . '/../wp-merchant-fields.php');
$start = strpos($src, 'function vc_merchant_needs_styles()');
$end = strpos($src, "\n}", $start) + 2;
if ($start === false) {
    echo "FAIL: vc_merchant_needs_styles() not found in the plugin\n";
    exit(1);
}
eval(substr($src, $start, $end - $start));

$fails = array();
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fails[] = $label; }
}
function context($singular = false, $tax = '', $merchant = 0, $content = '') {
    $GLOBALS['singular'] = $singular;
    $GLOBALS['tax'] = $tax;
    $GLOBALS['storepage_merchant'] = $merchant;
    $GLOBALS['post_content'] = $content;
}

/* --- Where it must NOT load ------------------------------------------- */

context();                       // the homepage
check('not on the homepage', !vc_merchant_needs_styles());

context('post', '', 0, 'An ordinary blog post about vaping.');
check('not on a blog post', !vc_merchant_needs_styles());

context('page', '', 0, '<p>Contact us.</p>');
check('not on an ordinary page', !vc_merchant_needs_styles());

context(false, 'category');
check('not on a category archive', !vc_merchant_needs_styles());

context(false, 'dealstore', 0);   // a store term with no merchant behind it
check('not on a dealstore term that matches no merchant',
    !vc_merchant_needs_styles());

/* --- Where it must load ------------------------------------------------ */

context('merchant');
check('on a merchant page', vc_merchant_needs_styles());

foreach (array('ships_to', 'service_location', 'merchant_category') as $t) {
    context(false, $t);
    check("on a $t archive", vc_merchant_needs_styles());
}

context(false, 'dealstore', 101);
check('on a ReHub store page that resolves to a merchant',
    vc_merchant_needs_styles());

context('page', '', 0, 'Intro. [merchant_page id="12"] Outro.');
check('on an ordinary page carrying the shortcode',
    vc_merchant_needs_styles());

/* --- The escape hatch -------------------------------------------------- */

context();
add_filter('vc_merchant_needs_styles', function () { return true; });
check('a theme can force it on through the filter',
    vc_merchant_needs_styles());
$GLOBALS['filters']['vc_merchant_needs_styles'] = array();
check('and turning the filter off restores the default',
    !vc_merchant_needs_styles());

echo "\n";
if ($fails) {
    echo count($fails) . " check(s) FAILED: " . implode(', ', $fails) . "\n";
    exit(1);
}
echo "All asset-loading checks passed.\n";
