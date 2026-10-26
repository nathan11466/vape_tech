<?php
/**
 * The ReHub port helper.
 *
 * This plugin writes to sidebars_widgets, which is the option holding every
 * widget placement on the site. Getting that wrong does not throw an error
 * -- it silently unassigns somebody's widgets, which is the exact failure
 * that started this whole thread. So the rules are pinned here:
 *
 *   never remove anything
 *   never touch a sidebar outside ReHub's footer
 *   adding twice adds once
 *   a preview writes nothing at all
 *
 *     php tests/test_port.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['options'] = array();
$GLOBALS['menus'] = array();      // term_id => name
$GLOBALS['menu_items'] = array(); // term_id => list of args
$GLOBALS['pages'] = array();      // path => id
$GLOBALS['next_term'] = 90;

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function wp_parse_args($a, $d) { return array_merge($d, (array) $a); }
function home_url($path = '') { return 'https://vapingcheap.com/staging' . $path; }
function get_page_by_path($path) {
    $id = $GLOBALS['pages'][trim($path, '/')] ?? 0;
    return $id ? (object) array('ID' => $id, 'post_type' => 'page') : null;
}
function wp_get_nav_menu_object($id) {
    if (is_numeric($id)) {
        return isset($GLOBALS['menus'][$id])
            ? (object) array('term_id' => (int) $id, 'name' => $GLOBALS['menus'][$id]) : false;
    }
    foreach ($GLOBALS['menus'] as $tid => $name) {
        if ($name === $id) { return (object) array('term_id' => (int) $tid, 'name' => $name); }
    }
    return false;
}
function wp_create_nav_menu($name) {
    $id = $GLOBALS['next_term']++;
    $GLOBALS['menus'][$id] = $name;
    $GLOBALS['menu_items'][$id] = array();
    return $id;
}
function wp_update_nav_menu_item($menu_id, $item_id, $args) {
    $GLOBALS['menu_items'][$menu_id][] = $args;
    return count($GLOBALS['menu_items'][$menu_id]);
}
function wp_specialchars_decode($s) { return html_entity_decode((string) $s, ENT_QUOTES); }
function is_plugin_active($f) { return !empty($GLOBALS['active_plugins'][$f]); }
function wp_get_theme() {
    return new class {
        public function get($k) { return $GLOBALS['theme_name'] ?? 'ReHub'; }
        public function get_template() { return $GLOBALS['theme_slug'] ?? 'rehub-theme'; }
    };
}
function add_action() {}
function add_theme_page() {}
function current_user_can() { return true; }
function wp_verify_nonce() { return false; }
function wp_nonce_field() {}
function submit_button() {}
function checked() {}
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_html__($s, $d = null) { return $s; }
function __($s, $d = null) { return $s; }
class WP_Error {
    public function get_error_message() { return 'err'; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }

require_once __DIR__ . '/../vc-rehub-port.php';

$fails = array();
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fails[] = $label; }
}

function reset_site() {
    $GLOBALS['options'] = array(
        // Somebody else's widgets, which must survive untouched.
        'sidebars_widgets' => array(
            'footerfirst' => array('text-7'),
            'sidebar-1'   => array('search-2', 'recent-posts-3'),
            'wp_inactive_widgets' => array('custom_html-4', 'custom_html-5'),
        ),
        'widget_nav_menu' => array('_multiwidget' => 1),
        'widget_text' => array(7 => array('title' => 'Keep me')),
    );
    $GLOBALS['menus'] = array();
    $GLOBALS['menu_items'] = array();
    $GLOBALS['pages'] = array(
        'about' => 11, 'contact' => 12, 'advertise-vaping-cheap' => 13,
        'editorial-policy' => 14, 'review-policy' => 15,
        'transparency-statement' => 16, 'privacy-policy' => 17,
        'terms-and-conditions' => 18, 'health-legal-disclaimer' => 19,
        'youth-prevention-policy' => 20,
    );
    $GLOBALS['next_term'] = 90;
}

/* --- A preview writes nothing ----------------------------------------- */

reset_site();
$before = $GLOBALS['options'];
$report = vc_port_build_footer(true);
check('a preview reports three menus', count($report) === 6, count($report) . ' lines');
check('and writes nothing at all', $GLOBALS['options'] === $before);
check('and creates no menus', $GLOBALS['menus'] === array());
check('the preview says "would"',
    strpos(implode(' ', $report), 'Would create') !== false, implode(' | ', $report));

// Previewing when the menus already exist is a different path: the build
// reaches the widget placement instead of stopping at menu creation, so
// the dry-run guard inside it is the only thing standing between a preview
// and a write. Without this case, removing that guard broke no test.
reset_site();
$GLOBALS['menus'] = array(90 => 'Company', 91 => 'Editorial', 92 => 'Legal');
$GLOBALS['menu_items'] = array(90 => array(), 91 => array(), 92 => array());
$before = $GLOBALS['options'];
$report = vc_port_build_footer(true);
check('a preview over existing menus still writes nothing',
    $GLOBALS['options'] === $before,
    json_encode($GLOBALS['options']['sidebars_widgets'] ?? array()));
check('and says it would add the widgets',
    strpos(implode(' ', $report), 'would add') !== false, implode(' | ', $report));

/* --- Building it ------------------------------------------------------- */

reset_site();
vc_port_build_footer(false);
check('three menus are created', count($GLOBALS['menus']) === 3,
    json_encode($GLOBALS['menus']));
check('named for the three columns',
    array_values($GLOBALS['menus']) === array('Company', 'Editorial', 'Legal'),
    json_encode(array_values($GLOBALS['menus'])));

$items = array();
foreach ($GLOBALS['menu_items'] as $list) { $items = array_merge($items, $list); }
check('eleven links in total', count($items) === 11, count($items));

$internal = array_filter($items, function ($a) { return ($a['menu-item-type'] ?? '') === 'post_type'; });
check('internal links resolve to the page, not a hard-coded URL',
    count($internal) === 10, count($internal) . ' of 11');
check('the external one stays a plain link',
    count($items) - count($internal) === 1);
$ext = array_values(array_filter($items, function ($a) { return ($a['menu-item-type'] ?? '') === 'custom'; }));
check('and keeps its full URL',
    ($ext[0]['menu-item-url'] ?? '') === 'https://www.p65warnings.ca.gov/',
    $ext[0]['menu-item-url'] ?? '');
check('entities in a label are decoded, not printed raw',
    in_array('Terms & Conditions', array_column($items, 'menu-item-title'), true),
    json_encode(array_column($items, 'menu-item-title')));

/* --- Existing widgets are never disturbed ------------------------------ */

$sidebars = get_option('sidebars_widgets');
check('the widget already in footerfirst is still there',
    in_array('text-7', $sidebars['footerfirst'], true),
    json_encode($sidebars['footerfirst']));
check('and ours was appended after it',
    count($sidebars['footerfirst']) === 2, json_encode($sidebars['footerfirst']));
check('an unrelated sidebar is untouched',
    $sidebars['sidebar-1'] === array('search-2', 'recent-posts-3'),
    json_encode($sidebars['sidebar-1']));
check('the inactive widgets are left alone',
    count($sidebars['wp_inactive_widgets']) === 2);
check('nothing was written to footercustom',
    !isset($sidebars['footercustom']) || count($sidebars['footercustom']) === 0);
check('no sidebar outside ReHub\'s footer gained a widget',
    $sidebars['sidebar-1'] === array('search-2', 'recent-posts-3'));

$widgets = get_option('widget_nav_menu');
check('three nav menu widget instances exist',
    count(array_filter(array_keys($widgets), 'is_numeric')) === 3,
    json_encode(array_keys($widgets)));
check('each carries its title, which is the column heading',
    count(array_filter($widgets, function ($w) {
        return is_array($w) && !empty($w['title']);
    })) === 3);
check('_multiwidget is preserved', ($widgets['_multiwidget'] ?? 0) === 1);
check('the other widget type is untouched',
    get_option('widget_text')[7]['title'] === 'Keep me');

/* --- Running it twice -------------------------------------------------- */

$snapshot = $GLOBALS['options'];
$report = vc_port_build_footer(false);
check('a second run writes nothing', $GLOBALS['options'] === $snapshot);
check('and says so',
    strpos(implode(' ', $report), 'already') !== false, implode(' | ', $report));
check('and creates no further menus', count($GLOBALS['menus']) === 3);

/* --- A menu the user already made by hand is respected ---------------- */

reset_site();
$GLOBALS['menus'][50] = 'Legal';
$GLOBALS['menu_items'][50] = array(array('menu-item-title' => 'My own link'));
vc_port_build_footer(false);
check('an existing menu of that name is not duplicated',
    count(array_filter($GLOBALS['menus'], function ($n) { return $n === 'Legal'; })) === 1,
    json_encode($GLOBALS['menus']));
check('and its items are left alone',
    count($GLOBALS['menu_items'][50]) === 1,
    count($GLOBALS['menu_items'][50]) . ' items');
check('but it still gets its widget',
    vc_port_sidebar_has_menu('footerthird', 50));

/* --- The CSS ----------------------------------------------------------- */

reset_site();
check('no CSS by default', vc_port_css() === '', vc_port_css());

update_option(VC_PORT_OPTION, array('footer_type' => 1, 'footer_px' => 15, 'full_width' => 0));
$css = vc_port_css();
check('the footer override is scoped to the footer',
    strpos($css, '.footer-bottom .footer_widget') === 0, substr($css, 0, 60));
check('it sets the size asked for', strpos($css, '15px') !== false);
check('and does not touch the container',
    strpos($css, 'rh-container') === false);

update_option(VC_PORT_OPTION, array('footer_type' => 0, 'footer_px' => 15, 'full_width' => 1));
$css = vc_port_css();
check('the container override is the only thing emitted',
    $css === '.rh-container{max-width:100%;width:100%;}', $css);

update_option(VC_PORT_OPTION, array('footer_type' => 1, 'footer_px' => 999, 'full_width' => 0));
check('a silly font size is clamped',
    strpos(vc_port_css(), '20px') !== false, vc_port_css());

/* --- Reporting --------------------------------------------------------- */

reset_site();
$GLOBALS['active_plugins'] = array(
    'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' => true,
);
$state = vc_port_plugin_state();
check('plugin state is reported for three plugins', count($state) === 3);
check('an active one reads active',
    $state[0]['active'] === true, json_encode($state[0]));
check('an inactive one reads inactive',
    $state[1]['active'] === false);

$GLOBALS['options']['rehub_option'] = array('rehub_ads_top' => '<script>banner</script>');
$slots = vc_port_ad_slots();
check('a filled ad slot is detected',
    $slots[0]['filled'] === true, json_encode($slots[0]));
check('an empty one is not', $slots[1]['filled'] === false);
check('every slot names where to find it',
    count(array_filter($slots, function ($s) { return $s['where'] !== ''; })) === count($slots));

echo "\n";
if ($fails) {
    echo count($fails) . " check(s) FAILED: " . implode(', ', $fails) . "\n";
    exit(1);
}
echo "All ReHub port checks passed.\n";
