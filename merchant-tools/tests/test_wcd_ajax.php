<?php
/**
 * Abuse resistance of the WP Coupon & Deals AJAX endpoints.
 *
 * This covers the other plugin, not this one, because its vote counts are
 * what decide whether a merchant page is allowed to present a code as
 * verified. If those numbers can be moved by anyone with a browser, every
 * "verified" claim on the site rests on them.
 *
 * The plugin ships no tests, so this is kept here alongside the integration
 * it protects. It expects the plugin at the path below; it skips cleanly when
 * the plugin is not present.
 *
 *     php tests/test_wcd_ajax.php [/path/to/wp-coupon-deals]
 */
// setcookie() runs for real below; in CLI that warns about sent headers.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);

$plugin = $argv[1] ?? getenv('WCD_PATH')
    ?: '/tmp/claude-0/-home-user-vape-tech/0da2384f-d7fc-5446-ad82-a1fa7a4aa6be/scratchpad/wcd/wp-coupon-deals';
$ajax = rtrim($plugin, '/') . '/includes/class-ajax.php';

if (!is_readable($ajax)) {
    echo "SKIP: coupon plugin not found at $ajax\n";
    echo "      pass its path as an argument to run these checks.\n";
    exit(0);
}

define('ABSPATH', '/tmp/');
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['transients'] = array();
$GLOBALS['meta'] = array();
$GLOBALS['ip'] = '203.0.113.9';
$GLOBALS['sent'] = null;
$GLOBALS['cookies_set'] = array();

/** Thrown in place of the exit() inside wp_send_json_*. */
class Sent extends Exception {
    public $ok, $payload, $status;
    function __construct($ok, $payload, $status = 200) {
        parent::__construct('sent');
        $this->ok = $ok; $this->payload = $payload; $this->status = $status;
    }
}

function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_post_type($id = null) { return 'wcd_coupon'; }
function get_post_status($id = null) { return 'publish'; }
function wp_verify_nonce($n, $a = '') { return $n === 'good-nonce'; }
function wp_hash($data) { return hash('sha256', 'site-salt|' . $data); }
function sanitize_text_field($v) { return is_string($v) ? trim(strip_tags($v)) : ''; }
function sanitize_textarea_field($v) { return sanitize_text_field($v); }
function wp_unslash($v) { return $v; }
function is_ssl() { return true; }
function current_time($f) { return date($f); }
function add_action() {}
function __($v, $d = null) { return $v; }
function esc_html__($v, $d = null) { return $v; }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function wp_send_json_error($p = null, $status = 200) { throw new Sent(false, $p, $status); }
function wp_send_json_success($p = null, $status = 200) { throw new Sent(true, $p, $status); }
if (!defined('COOKIEPATH')) { define('COOKIEPATH', '/'); }

require $ajax;

$fail = 0;
function check($label, $cond, $detail = '') {
    global $fail;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fail++; }
}

/**
 * Call a handler as a visitor would, returning the JSON response.
 */
function call($method, array $post, $ip = null, array $cookies = array()) {
    $_SERVER['REMOTE_ADDR'] = $ip ?: $GLOBALS['ip'];
    $_POST = array_merge(array('nonce' => 'good-nonce'), $post);
    $_COOKIE = $cookies;
    try {
        WCD_Ajax::$method();
    } catch (Sent $s) {
        return $s;
    }
    return null;
}

/* -------------------------------------------------------------------------
 * The nonce still gates everything
 * ---------------------------------------------------------------------- */

$r = call('verify_vote', array('post_id' => 7, 'vote' => 'yes', 'nonce' => 'forged'));
check('a forged nonce is rejected', $r && !$r->ok && $r->status === 403);

/* -------------------------------------------------------------------------
 * Vote stuffing
 *
 * These counts decide whether a page says "verified", so clearing a cookie
 * must not buy another vote.
 * ---------------------------------------------------------------------- */

$GLOBALS['transients'] = array();
$GLOBALS['meta'] = array();

$r = call('verify_vote', array('post_id' => 7, 'vote' => 'yes'));
check('a first vote is counted', $r && $r->ok, json_encode($r ? $r->payload : null));
check('and reaches the success counter',
    (int) get_post_meta(7, '_wcd_success_count', true) === 1);

// The cookie path, as before.
$r = call('verify_vote', array('post_id' => 7, 'vote' => 'yes'),
    null, array('wcd_voted_7' => '1'));
check('a second vote with the cookie present is refused', $r && !$r->ok);

// The attack: same visitor, cookie cleared.
$r = call('verify_vote', array('post_id' => 7, 'vote' => 'yes'), null, array());
check('a second vote with the cookie CLEARED is still refused',
    $r && !$r->ok, json_encode($r ? $r->payload : null));
check('and the counter did not move',
    (int) get_post_meta(7, '_wcd_success_count', true) === 1,
    (string) get_post_meta(7, '_wcd_success_count', true));

// Downvoting the same coupon is the same event, not a fresh one.
$r = call('verify_vote', array('post_id' => 7, 'vote' => 'no'), null, array());
check('switching to a downvote does not buy another vote',
    $r && !$r->ok);
check('so the fail counter stays at zero',
    (int) get_post_meta(7, '_wcd_fail_count', true) === 0);

// A different visitor must still be able to vote.
$r = call('verify_vote', array('post_id' => 7, 'vote' => 'no'), '198.51.100.4');
check('a different visitor can still vote', $r && $r->ok);
check('and their downvote is counted',
    (int) get_post_meta(7, '_wcd_fail_count', true) === 1);

/* -------------------------------------------------------------------------
 * One visitor must not be able to swing many coupons at once
 * ---------------------------------------------------------------------- */

$GLOBALS['transients'] = array();
$GLOBALS['meta'] = array();
$accepted = 0;
for ($i = 1; $i <= 40; $i++) {
    $r = call('verify_vote', array('post_id' => 1000 + $i, 'vote' => 'no'), '203.0.113.77');
    if ($r && $r->ok) { $accepted++; }
}
check('a daily ceiling stops mass downvoting',
    $accepted > 0 && $accepted <= 20, "$accepted of 40 accepted");

// The ceiling must not be a rolling window a trickle can hold open.
$r = call('verify_vote', array('post_id' => 9999, 'vote' => 'no'), '203.0.113.77');
check('and it stays closed once reached', $r && !$r->ok);

/* -------------------------------------------------------------------------
 * "N used today" is shown to readers as evidence about other shoppers
 * ---------------------------------------------------------------------- */

$GLOBALS['transients'] = array();
$GLOBALS['meta'] = array();

$r = call('reveal_code', array('post_id' => 8));
check('revealing a code works', $r && $r->ok);
check('and counts once',
    (int) get_post_meta(8, '_wcd_reveal_count', true) === 1);

// The abuse: a loop.
for ($i = 0; $i < 50; $i++) {
    call('reveal_code', array('post_id' => 8));
}
check('a loop cannot inflate the reveal count',
    (int) get_post_meta(8, '_wcd_reveal_count', true) === 1,
    (string) get_post_meta(8, '_wcd_reveal_count', true));

// Crucially, the reader still gets what they asked for every time.
update_post_meta(8, '_wcd_code', 'SAVE20');
$r = call('reveal_code', array('post_id' => 8));
check('but the code is still returned on a repeat reveal',
    $r && $r->ok && ($r->payload['code'] ?? '') === 'SAVE20',
    json_encode($r ? $r->payload : null));

$GLOBALS['transients'] = array();
$GLOBALS['meta'] = array();
call('track_click', array('post_id' => 9));
for ($i = 0; $i < 30; $i++) { call('track_click', array('post_id' => 9)); }
check('a loop cannot inflate the click count',
    (int) get_post_meta(9, '_wcd_click_count', true) === 1,
    (string) get_post_meta(9, '_wcd_click_count', true));

// A genuinely different visitor still counts.
call('track_click', array('post_id' => 9), '198.51.100.22');
check('a different visitor is counted',
    (int) get_post_meta(9, '_wcd_click_count', true) === 2);

/* -------------------------------------------------------------------------
 * The vote cookie
 *
 * setcookie() is an internal function and cannot be replaced, so these are
 * source-level assertions rather than observed behaviour. Stated plainly
 * because that is a weaker guarantee than everything above.
 * ---------------------------------------------------------------------- */

$src = file_get_contents($ajax);
check('[source] the vote cookie is httponly',
    preg_match('/\'httponly\'\s*=>\s*true/', $src) === 1);
check('[source] and secure follows is_ssl() rather than being hardcoded',
    preg_match('/\'secure\'\s*=>\s*is_ssl\(\)/', $src) === 1);
check('[source] and SameSite is set',
    preg_match('/\'samesite\'\s*=>\s*\'Lax\'/', $src) === 1);
check('[source] with a fallback for PHP below 7.3',
    strpos($src, 'PHP_VERSION_ID >= 70300') !== false);

/* -------------------------------------------------------------------------
 * The identifier is salted, not a bare hash of an address
 * ---------------------------------------------------------------------- */

$GLOBALS['transients'] = array();
call('verify_vote', array('post_id' => 12, 'vote' => 'yes'), '198.51.100.123');
$keys = implode(' ', array_keys($GLOBALS['transients']));
check('no transient key contains the raw IP',
    strpos($keys, '198.51.100.123') === false, $keys);
check('nor a bare md5 or sha1 of it',
    strpos($keys, md5('198.51.100.123')) === false
    && strpos($keys, sha1('198.51.100.123')) === false, $keys);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All coupon-plugin abuse-resistance checks passed.\n";
