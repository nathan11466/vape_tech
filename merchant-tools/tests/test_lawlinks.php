<?php
/**
 * Links to the state vape-law pages.
 *
 * The slug is a postal abbreviation, which cannot be derived from the state
 * name. That is the whole risk here: a generated slug sends Mississippi to
 * Michigan's law page, which returns 200, looks fine, and is wrong. So the
 * map is checked against the real list, and anything not in it links nowhere
 * rather than guessing.
 *
 *     php tests/test_lawlinks.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['options'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['queried'] = null;

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function home_url($path = '') { return 'https://vapingcheap.com' . $path; }
function trailingslashit($v) { return rtrim((string) $v, '/') . '/'; }
function apply_filters($tag, $value) {
    $args = func_get_args();
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) {
        $value = $fn($value, $args[2] ?? null);
    }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $n = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_shortcode($tag, $fn) { $GLOBALS['shortcodes'][$tag] = $fn; }
function shortcode_atts($pairs, $atts, $sc = '') {
    return array_merge($pairs, is_array($atts) ? $atts : array());
}
function get_queried_object() { return $GLOBALS['queried']; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function esc_url($v) { return $v; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function __($v, $d = null) { return $v; }
function esc_html__($v, $d = null) { return $v; }
class WP_Error {}

require __DIR__ . '/../wp-merchant-lawlinks.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

function term($name) { return (object) array('term_id' => 7, 'name' => $name); }

/* --- The map itself ---------------------------------------------------- */

$slugs = vc_law_slugs();
check('all fifty states plus DC are mapped', count($slugs) === 51, count($slugs));
check('DC is included, since the law pages have one',
    ($slugs['District of Columbia'] ?? '') === 'dc');
check('every slug is two letters',
    count(array_filter($slugs, function ($s) { return preg_match('/^[a-z]{2}$/', $s); })) === 51);
check('no two states share a slug',
    count(array_unique($slugs)) === 51);

// The pair a generated slug gets wrong: both start "mi", and the real
// abbreviations are mi and ms.
check('Michigan and Mississippi do not collide',
    $slugs['Michigan'] === 'mi' && $slugs['Mississippi'] === 'ms',
    $slugs['Michigan'] . ' / ' . $slugs['Mississippi']);
// Likewise these, where the obvious first-two-letters guess is wrong.
check('Alaska is ak, not al', $slugs['Alaska'] === 'ak');
check('Arizona is az, not ar', $slugs['Arizona'] === 'az');
check('Missouri is mo and Montana is mt',
    $slugs['Missouri'] === 'mo' && $slugs['Montana'] === 'mt');

/* --- Building a URL ---------------------------------------------------- */

check('a state URL is built from the site root',
    vc_law_url('Texas') === 'https://vapingcheap.com/vape-laws/tx/',
    vc_law_url('Texas'));
check('a two-word state works',
    vc_law_url('New York') === 'https://vapingcheap.com/vape-laws/ny/',
    vc_law_url('New York'));

// The taxonomy holds countries and roll-ups too, which have no law page.
check('a country gets no URL rather than a guessed one',
    vc_law_url('United States') === '', vc_law_url('United States'));
check('an unknown name gets no URL',
    vc_law_url('Narnia') === '' && vc_law_url('') === '');
check('a near-miss is not fuzzy matched',
    vc_law_url('texas') === '' && vc_law_url('Texas ') === 'https://vapingcheap.com/vape-laws/tx/',
    'lowercase: ' . vc_law_url('texas'));

/* --- Pointing somewhere else ------------------------------------------ */

// The law pages are on production while the plugin may run on staging, so a
// configured base has to win.
$GLOBALS['options']['vc_law_base_url'] = 'https://vapingcheap.com/vape-laws';
check('a configured base is used, and gets its trailing slash',
    vc_law_url('Utah') === 'https://vapingcheap.com/vape-laws/ut/',
    vc_law_url('Utah'));
$GLOBALS['options'] = array();

// Switching it off must link nowhere at all, not to a bare slug.
$GLOBALS['filters']['vc_law_base'][] = function ($v) { return ''; };
check('an emptied base links nowhere',
    vc_law_url('Texas') === '', vc_law_url('Texas'));
check('and the full list renders nothing',
    $GLOBALS['shortcodes']['vape_law_links'](array()) === '');
$GLOBALS['filters']['vc_law_base'] = array();

/* --- The link on a destination page ----------------------------------- */

$intro = '<div class="vc-archive-intro"><p>Intro.</p></div>';

$texas = apply_filters('vc_archive_intro_html', $intro, term('Texas'));
check('a state destination page gains a law link',
    strpos($texas, 'https://vapingcheap.com/vape-laws/tx/') !== false, $texas);
check('and it names the state',
    strpos($texas, 'Read the full Texas vape laws') !== false, $texas);
check('the existing intro is kept, not replaced',
    strpos($texas, '<p>Intro.</p>') !== false);

$country = apply_filters('vc_archive_intro_html', $intro, term('United States'));
check('a country destination page is left alone',
    $country === $intro, $country);

// Returning the intro unchanged is not enough on its own: reading ->name off
// null also "works" in PHP while emitting a warning, so the warnings are
// captured and counted. Without the guard this is how a notice-free log
// quietly fills up on every archive page that is not a state.
$GLOBALS['warnings'] = array();
set_error_handler(function ($no, $str) {
    $GLOBALS['warnings'][] = $str;
    return true;
});

check('a null term is left alone',
    apply_filters('vc_archive_intro_html', $intro, null) === $intro);
check('a WP_Error term is left alone',
    apply_filters('vc_archive_intro_html', $intro, new WP_Error()) === $intro);

$country_again = apply_filters('vc_archive_intro_html', $intro, term('Canada'));
check('a term outside the map is left alone', $country_again === $intro);

restore_error_handler();
check('and none of those raised a PHP warning',
    $GLOBALS['warnings'] === array(), implode(' | ', $GLOBALS['warnings']));

/* --- Shortcodes -------------------------------------------------------- */

$one = $GLOBALS['shortcodes']['vape_law_link'];

check('the single-link shortcode takes a state',
    strpos($one(array('state' => 'Ohio')), '/vape-laws/oh/') !== false,
    $one(array('state' => 'Ohio')));
check('and a custom label',
    strpos($one(array('state' => 'Ohio', 'label' => 'Ohio rules')),
        '>Ohio rules</a>') !== false);
check('with no state it uses the term being viewed',
    strpos($one(array()), '/vape-laws/tx/') === false, 'no term set yet');

$GLOBALS['queried'] = term('Nevada');
check('the viewed term supplies the state',
    strpos($one(array()), '/vape-laws/nv/') !== false, $one(array()));

$GLOBALS['queried'] = term('United States');
check('a term with no law page renders nothing at all',
    $one(array()) === '', $one(array()));
$GLOBALS['queried'] = null;

check('an unknown state renders nothing rather than a broken link',
    $one(array('state' => 'Atlantis')) === '');

$list = $GLOBALS['shortcodes']['vape_law_links'](array('title' => 'By state'));
check('the list shortcode renders all fifty-one',
    substr_count($list, '<li>') === 51, substr_count($list, '<li>'));
check('and uses the heading given',
    strpos($list, '<h2>By state</h2>') !== false);
check('with no heading it renders no empty h2',
    strpos($GLOBALS['shortcodes']['vape_law_links'](array()), '<h2>') === false);
check('every link in the list is a real slug',
    preg_match_all('#/vape-laws/[a-z]{2}/#', $list) === 51);

/* --- Escaping ---------------------------------------------------------- */

// Term names come from the database and reach the label.
$GLOBALS['filters']['vc_law_slugs'][] = function ($map) {
    $map['<script>x</script>'] = 'xx';
    return $map;
};
$evil = $one(array('state' => '<script>x</script>'));
check('a state name is escaped into the label',
    strpos($evil, '<script>') === false, $evil);
$GLOBALS['filters']['vc_law_slugs'] = array();

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All law link checks passed.\n";
