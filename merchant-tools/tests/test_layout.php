<?php
/**
 * Configurable headings, custom blocks and the appearance tokens.
 *
 * The risk in all three is the same: a stored setting that silently reverts,
 * or a default that quietly overwrites a deliberate choice. A heading an
 * editor cleared on purpose must stay cleared, and a token left alone must
 * keep the stylesheet's own value rather than being pinned to a copy of it.
 *
 *     php tests/test_layout.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['options'] = array();
$GLOBALS['head'] = '';
$GLOBALS['shortcodes_run'] = array();

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }
function add_action($tag, $fn = null, $p = 10, $n = 1) { $GLOBALS['hooks'][$tag][] = $fn; }
function add_filter() {}
function add_submenu_page() {}
function apply_filters($t, $v) { return $v; }
function current_user_can() { return true; }
function wp_verify_nonce() { return !empty($GLOBALS['nonce_ok']); }
function wp_nonce_field() {}
function submit_button() {}
function selected($a, $b, $e = true) { return ''; }
function sanitize_hex_color($c) {
    return preg_match('/^#[0-9a-f]{6}$/i', (string) $c) ? strtolower($c) : null;
}
function sanitize_text_field($v) { return is_string($v) ? trim(strip_tags($v)) : ''; }
function wp_kses_post($v) {
    // Close enough for these checks: strip <script> but keep ordinary markup
    // and shortcode brackets.
    return preg_replace('#<script.*?</script>#is', '', (string) $v);
}
function wp_unslash($v) { return $v; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_textarea($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return $v; }
function esc_html_e($v, $d = null) { echo $v; }
function esc_attr_e($v, $d = null) { echo $v; }
function __($v, $d = null) { return $v; }
function _e($v, $d = null) { echo $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function wpautop($s) { return '<p>' . $s . '</p>'; }
function do_shortcode($s) {
    $GLOBALS['shortcodes_run'][] = $s;
    return str_replace('[merchant_name]', 'VooPoo', $s);
}
function vc_merchant_post_types() { return array('merchant'); }
function vc_merchant_display_name($id = null) { return 'VooPoo'; }
function shortcode_exists($t) { return false; }
function add_shortcode($tag, $fn) { $GLOBALS['shortcodes'][$tag] = $fn; }
function shortcode_atts($p, $a, $sc = '') { return array_merge($p, (array) $a); }
function get_the_ID() { return 1; }
function get_post_meta($id, $k, $s = false) { return ''; }
function get_the_title($id = null) { return 'VooPoo'; }
function get_permalink($id = null) { return 'https://vapingcheap.com/stores/voopoo/'; }
function is_wp_error($t) { return false; }
function get_the_terms($id, $tax) { return false; }
function taxonomy_exists($t) { return false; }

require __DIR__ . '/../wp-merchant-settings.php';
require __DIR__ . '/../wp-merchant-render.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}

/* -------------------------------------------------------------------------
 * Nothing changes until a setting is touched
 * ---------------------------------------------------------------------- */

$GLOBALS['options'] = array();
$layout = vc_layout_get();

check('the three custom blocks exist as blocks',
    isset($layout['where']['custom1'], $layout['where']['custom2'], $layout['where']['custom3']));
check('and are hidden until used, so adding them changed no page',
    $layout['where']['custom1'] === 'off'
    && $layout['where']['custom2'] === 'off'
    && $layout['where']['custom3'] === 'off',
    json_encode(array($layout['where']['custom1'], $layout['where']['custom2'])));
check('no headings are stored on a fresh install', $layout['headings'] === array(),
    json_encode($layout['headings']));
check('and no custom content', $layout['custom'] === array(), json_encode($layout['custom']));

/* -------------------------------------------------------------------------
 * Headings
 * ---------------------------------------------------------------------- */

check('an untouched block reports its shipped wording via the fallback',
    vc_block_heading('about', 1, 'About VooPoo') === 'About VooPoo');

$GLOBALS['options'][VC_LAYOUT_OPTION] = array(
    'headings' => array('about' => 'Who are {brand}?'),
);
check('a saved heading is used', vc_block_heading('about', 1, 'About VooPoo') === 'Who are VooPoo?',
    vc_block_heading('about', 1, 'About VooPoo'));
check('{brand} is replaced', strpos(vc_block_heading('about', 1, 'x'), '{brand}') === false);
check('an untouched sibling still uses its own fallback',
    vc_block_heading('save', 1, 'Best ways to save at VooPoo') === 'Best ways to save at VooPoo');

// The case that a naive array_merge over defaults would break.
$GLOBALS['options'][VC_LAYOUT_OPTION] = array('headings' => array('trust' => ''));
check('a heading cleared on purpose stays cleared',
    vc_block_heading('trust', 1, 'Company information') === '',
    vc_block_heading('trust', 1, 'Company information'));

check('an unknown block has no configurable heading',
    vc_layout_heading('not_a_block', 1) === '');

check('every heading default belongs to a real block',
    empty(array_diff(
        array_keys(vc_layout_heading_defaults()),
        array_merge(array_keys(vc_layout_defaults()), array('expired')))),
    json_encode(array_diff(array_keys(vc_layout_heading_defaults()),
        array_merge(array_keys(vc_layout_defaults()), array('expired')))));

/* -------------------------------------------------------------------------
 * Saving
 * ---------------------------------------------------------------------- */

function save($post) {
    $GLOBALS['nonce_ok'] = true;
    $_POST = array_merge(array('vc_layout_nonce' => 'x'), $post);
    ob_start();
    vc_layout_screen();
    ob_get_clean();
    $_POST = array();
    $GLOBALS['nonce_ok'] = false;
}

$GLOBALS['options'] = array();
save(array(
    'heading' => array('about' => 'Who are {brand}?', 'trust' => ''),
    'custom'  => array('custom1' => 'Our pick. [merchant_name] is solid.'),
    'where'   => array('custom1' => 'main'),
    'radius'  => '0',
    'heading_size' => '1.8',
    'surface' => '#fafafa',
));

$layout = vc_layout_get();
check('a heading survives a save', ($layout['headings']['about'] ?? null) === 'Who are {brand}?',
    json_encode($layout['headings']));
check('a cleared heading survives a save as an empty string',
    array_key_exists('trust', $layout['headings']) && $layout['headings']['trust'] === '',
    json_encode($layout['headings']));
check('custom content survives a save',
    strpos($layout['custom']['custom1'] ?? '', 'Our pick') === 0,
    json_encode($layout['custom']));
check('moving a custom block out of Hidden sticks',
    $layout['where']['custom1'] === 'main', $layout['where']['custom1']);
check('the new appearance values survive',
    (int) $layout['visual']['radius'] === 0
    && (float) $layout['visual']['heading_size'] === 1.8
    && $layout['visual']['surface'] === '#fafafa',
    json_encode($layout['visual']));

// Out-of-range and junk values must be clamped or rejected, not stored.
save(array('radius' => '9999', 'heading_size' => '-5', 'text_size' => '99',
           'surface' => 'javascript:alert(1)'));
$v = vc_layout_get()['visual'];
check('an absurd radius is clamped', (int) $v['radius'] === 40, (string) $v['radius']);
check('a negative heading size is clamped up', (float) $v['heading_size'] >= 0.9,
    (string) $v['heading_size']);
check('an oversized text size is clamped', (float) $v['text_size'] <= 1.6, (string) $v['text_size']);
check('a non-colour falls back to the default rather than being stored',
    $v['surface'] === vc_layout_visual_defaults()['surface'], $v['surface']);

// A reset must clear the new arrays too.
save(array('vc_layout_reset' => '1'));
$layout = vc_layout_get();
check('a reset clears headings and custom content',
    $layout['headings'] === array() && $layout['custom'] === array(),
    json_encode(array($layout['headings'], $layout['custom'])));

/* -------------------------------------------------------------------------
 * Custom block rendering
 * ---------------------------------------------------------------------- */

$GLOBALS['options'][VC_LAYOUT_OPTION] = array(
    'custom'   => array('custom1' => 'Our pick. [merchant_name] is solid.'),
    'headings' => array('custom1' => 'Why we rate {brand}'),
);

$out = vc_merchant_custom_block('custom1', 1);
check('a custom block renders its content', strpos($out, 'Our pick.') !== false, $out);
check('its shortcodes are expanded', strpos($out, 'VooPoo is solid') !== false, $out);
check('its heading renders with {brand} resolved',
    strpos($out, '<h2>Why we rate VooPoo</h2>') !== false, $out);
check('and it is wrapped so it can be styled',
    strpos($out, 'vc-custom--custom1') !== false, $out);

$GLOBALS['options'][VC_LAYOUT_OPTION] = array(
    'custom' => array('custom1' => 'No heading here'),
);
$out = vc_merchant_custom_block('custom1', 1);
check('a block with no heading renders without an empty h2',
    strpos($out, '<h2>') === false && strpos($out, 'No heading here') !== false, $out);

$GLOBALS['options'][VC_LAYOUT_OPTION] = array('custom' => array('custom1' => '   '));
check('an empty custom block renders nothing at all',
    vc_merchant_custom_block('custom1', 1) === '',
    vc_merchant_custom_block('custom1', 1));
check('an unused slot renders nothing', vc_merchant_custom_block('custom3', 1) === '');

/* -------------------------------------------------------------------------
 * CSS tokens
 * ---------------------------------------------------------------------- */

function head_css() {
    $GLOBALS['head'] = '';
    ob_start();
    foreach ($GLOBALS['hooks']['wp_head'] ?? array() as $fn) { $fn(); }
    return ob_get_clean();
}

$GLOBALS['options'] = array();
check('an untouched install emits no inline CSS at all',
    trim(head_css()) === '', head_css());

$GLOBALS['options'][VC_LAYOUT_OPTION] = array('visual' => array_merge(
    vc_layout_visual_defaults(),
    array('radius' => 2, 'heading_size' => 1.8, 'surface' => '#fafafa')));
$css = head_css();
check('only the changed tokens are emitted',
    strpos($css, '--vc-radius:2px') !== false
    && strpos($css, '--vc-heading-size:1.8rem') !== false
    && strpos($css, '--vc-surface:#fafafa') !== false, $css);
check('and an unchanged token is left to the stylesheet',
    strpos($css, '--vc-text:') === false && strpos($css, '--vc-section-gap') === false, $css);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All layout customisation checks passed.\n";
