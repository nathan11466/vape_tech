<?php
/**
 * Destination slugs, the migration onto them, the redirects that keep the old
 * URLs alive, and assignment finding terms afterwards.
 *
 * The state pages are the commercial target, so a rename that is not
 * redirected 404s the pages this project exists for, and an assignment that
 * silently matches nothing leaves every store with no destinations.
 *
 *     php tests/test_slug.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['terms'] = array();
$GLOBALS['term_meta'] = array();
$GLOBALS['options'] = array();
$GLOBALS['flushed'] = 0;
$GLOBALS['is404'] = true;
$GLOBALS['assigned'] = array();
$GLOBALS['inserted'] = array();

function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-')); }
function get_term_by($field, $value, $tax = '') {
    foreach ($GLOBALS['terms'] as $t) {
        if ($field === 'slug' && $t->slug === $value) { return $t; }
        if ($field === 'name' && strcasecmp($t->name, $value) === 0) { return $t; }
    }
    return false;
}
function get_term($id, $tax = '') { return $GLOBALS['terms'][$id] ?? null; }
function get_terms($a = array()) { return array_values($GLOBALS['terms']); }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['term_meta'][$id][$k] ?? ''; }
function wp_update_term($id, $tax, $args) {
    if (!isset($GLOBALS['terms'][$id])) { return new WP_Error(); }
    $GLOBALS['terms'][$id]->slug = $args['slug'];
    return array('term_id' => $id);
}
function get_term_link($t) {
    $t = is_object($t) ? $t : ($GLOBALS['terms'][$t] ?? null);
    return $t ? 'https://vapingcheap.com/ships-to/' . $t->slug . '/' : new WP_Error();
}
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function flush_rewrite_rules() { $GLOBALS['flushed']++; }
function is_404() { return $GLOBALS['is404']; }
function is_wp_error($t) { return $t instanceof WP_Error; }
function term_exists($s, $tax = '') { return (bool) get_term_by('slug', $s, $tax); }
function wp_insert_term($name, $tax, $a = array()) {
    static $next = 900;
    $id = $next++;
    $GLOBALS['inserted'][] = array('name' => $name, 'slug' => $a['slug'] ?? '', 'id' => $id);
    $GLOBALS['terms'][$id] = (object) array('term_id' => $id, 'name' => $name,
        'parent' => $a['parent'] ?? 0, 'slug' => $a['slug'] ?? '',
        'taxonomy' => 'ships_to', 'count' => 0);
    return array('term_id' => $id);
}
function wp_set_object_terms($post_id, $terms, $tax, $append = false) {
    $GLOBALS['assigned'][$post_id] = $terms;
}
function register_taxonomy() {}
function add_action($t, $f, $p = 10, $n = 1) { $GLOBALS['hooks'][$t][] = $f; }
function add_filter() {}
function apply_filters($t, $v) { return $v; }
function get_the_ID() { return 1; }
function get_queried_object() { return null; }
function current_user_can() { return false; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return $v; }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return $v; }
function __($v, $d = null) { return $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function vc_merchant_post_types() { return array('merchant'); }

/** Throwing stops the handler before its exit(), which would kill the suite. */
class Redirected extends Exception {
    public $url, $code;
    function __construct($u, $c) { parent::__construct('redirect'); $this->url = $u; $this->code = $c; }
}
function wp_safe_redirect($url, $code = 302) { throw new Redirected($url, $code); }
class WP_Error { function get_error_message() { return 'err'; } }

require __DIR__ . '/../wp-merchant-shipping.php';

$fail = 0;
function check($l, $c, $d = '') {
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l" . ($d ? " -- $d" : '') . "\n"; $fail++; }
}
function mk($id, $name, $parent, $slug) {
    return (object) array('term_id' => $id, 'name' => $name, 'parent' => $parent,
        'slug' => $slug, 'taxonomy' => 'ships_to', 'count' => 0);
}

/* -------------------------------------------------------------------------
 * Slug resolution
 * ---------------------------------------------------------------------- */

$GLOBALS['terms'] = array(1 => mk(1, 'United States', 0, 'united-states'));
check('a state takes its own name, not the country prefix',
    vc_shipping_preferred_slug('United States', 'Texas') === 'texas',
    vc_shipping_preferred_slug('United States', 'Texas'));

$GLOBALS['terms'][2] = mk(2, 'Georgia', 0, 'georgia');
check('a real collision falls back to the namespaced form',
    vc_shipping_preferred_slug('United States', 'Georgia') === 'united-states-georgia',
    vc_shipping_preferred_slug('United States', 'Georgia'));
check('a term does not collide with itself when renamed',
    vc_shipping_preferred_slug('United States', 'Georgia', 2) === 'georgia');

/* -------------------------------------------------------------------------
 * Migration
 * ---------------------------------------------------------------------- */

$GLOBALS['terms'] = array(
    1 => mk(1, 'United States', 0, 'united-states'),
    2 => mk(2, 'Texas', 1, 'united-states-texas'),
    3 => mk(3, 'Utah', 1, 'united-states-utah'),
    4 => mk(4, 'Canada', 0, 'canada'),
    5 => mk(5, 'Ontario', 4, 'canada-ontario'),
);
$GLOBALS['options'] = array();
$n = vc_shipping_migrate_slugs();

check('migration renames every namespaced child', $n === 3, "renamed $n");
check('Texas ends up at the clean slug', $GLOBALS['terms'][2]->slug === 'texas',
    $GLOBALS['terms'][2]->slug);
check('parents are left alone', $GLOBALS['terms'][1]->slug === 'united-states');
check('rewrite rules are flushed once slugs move', $GLOBALS['flushed'] >= 1);

$map = get_option('vc_ships_to_slug_map');
check('every old slug is recorded for redirecting',
    isset($map['united-states-texas']) && $map['united-states-texas'] === 2, json_encode($map));

$flushed_before = $GLOBALS['flushed'];
check('re-running the migration changes nothing',
    vc_shipping_migrate_slugs() === 0 && $GLOBALS['flushed'] === $flushed_before);

/* -------------------------------------------------------------------------
 * Redirects
 * ---------------------------------------------------------------------- */

$redirect_hook = null;
foreach ($GLOBALS['hooks']['template_redirect'] ?? array() as $fn) { $redirect_hook = $fn; }
check('a redirect handler is registered', $redirect_hook !== null);

function try_url($hook, $uri) {
    $_SERVER['REQUEST_URI'] = $uri;
    try { $hook(); } catch (Redirected $r) { return array($r->url, $r->code); }
    return null;
}

$r = try_url($redirect_hook, '/ships-to/united-states/united-states-texas/');
check('the old deep URL 301s to the clean one',
    $r && $r[1] === 301 && strpos($r[0], '/ships-to/texas/') !== false, json_encode($r));

$r = try_url($redirect_hook, '/ships-to/united-states-texas/');
check('the old flat URL also 301s', $r && $r[1] === 301, json_encode($r));

$r = try_url($redirect_hook, '/some-other-plugin/united-states-texas/');
check('404s outside /ships-to/ are left to other plugins', $r === null, json_encode($r));

$r = try_url($redirect_hook, '/ships-to/never-existed/');
check('an unknown destination is not redirected', $r === null, json_encode($r));

$GLOBALS['is404'] = false;
$r = try_url($redirect_hook, '/ships-to/united-states-texas/');
check('a page that resolves is never redirected', $r === null, json_encode($r));
$GLOBALS['is404'] = true;

/* -------------------------------------------------------------------------
 * Assignment must still find destinations after the migration
 *
 * vc_assign_ships_to() rebuilt the old parent-prefixed slug to look a term
 * up. Once destinations moved to bare slugs that lookup missed, and the
 * create path it fell through to would have inserted a duplicate carrying
 * the old format -- leaving the Ships To boxes on a store unticked.
 * ---------------------------------------------------------------------- */

$GLOBALS['terms'] = array(
    1 => mk(1, 'United States', 0, 'united-states'),
    2 => mk(2, 'Texas', 1, 'texas'),
    3 => mk(3, 'Utah', 1, 'utah'),
);
$GLOBALS['inserted'] = array();

$n = vc_assign_ships_to(500, 'United States|Texas');
check('a destination on a clean slug is found and assigned', $n >= 2, "assigned $n");
check('the state term is among those assigned',
    in_array(2, $GLOBALS['assigned'][500] ?? array(), true),
    json_encode($GLOBALS['assigned'][500] ?? null));
check('and the country is rolled up alongside it',
    in_array(1, $GLOBALS['assigned'][500] ?? array(), true),
    json_encode($GLOBALS['assigned'][500] ?? null));
check('nothing was created, because the terms already existed',
    empty($GLOBALS['inserted']), json_encode($GLOBALS['inserted']));

// A term left on an old prefixed slug must still be found.
$GLOBALS['terms'][6] = mk(6, 'Ontario', 1, 'canada-ontario');
vc_assign_ships_to(501, 'Canada>Ontario');
check('a destination still on an old prefixed slug is still found',
    in_array(6, $GLOBALS['assigned'][501] ?? array(), true),
    json_encode($GLOBALS['assigned'][501] ?? null));

// A genuinely missing destination is created -- on the clean slug.
$GLOBALS['terms'] = array(1 => mk(1, 'United States', 0, 'united-states'));
$GLOBALS['inserted'] = array();
vc_assign_ships_to(502, 'United States|Texas');
$made = $GLOBALS['inserted'][0] ?? array();
check('a missing destination is created rather than skipped', !empty($made), json_encode($made));
check('and on the clean slug, not the old prefixed form',
    ($made['slug'] ?? '') === 'texas', json_encode($made));

check('an empty list assigns nothing', vc_assign_ships_to(503, '') === 0);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All destination slug + assignment checks passed.\n";
