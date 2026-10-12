<?php
/**
 * Boots a real WordPress (SQLite) with this plugin loaded, so the tests
 * exercise actual SQL, hooks and post status changes rather than stubs.
 *
 *   VC_WP_DIR      WordPress install to use (wp-config.php must exist)
 *   VC_WP_PRISTINE SQLite file to copy in before each run, for a clean DB
 *
 * WP Coupon & Deals is not open source, so its post type and taxonomy are
 * registered here with the names and meta keys the real plugin uses.
 */

$wp_dir   = getenv('VC_WP_DIR') ?: '/home/claude/wpenv/wp';
$pristine = getenv('VC_WP_PRISTINE') ?: '/home/claude/wpenv/pristine.sqlite';

if (!file_exists($wp_dir . '/wp-config.php')) {
    fwrite(STDERR, "No WordPress at $wp_dir (set VC_WP_DIR). Skipping.\n");
    exit(0);
}
if (file_exists($pristine)) {
    copy($pristine, $wp_dir . '/wp-content/database/.ht.sqlite');
}

define('VC_REWARDS_TESTING', true);
define('WP_USE_THEMES', false);
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '203.0.113.1';

// Hooks added before WordPress loads, in the pre-initialised form core
// accepts: register the coupon plugin's types, then load ours as a must-use
// plugin would be.
function vc_test_register_coupon_types() {
    register_post_type('wcd_coupon', array('public' => true, 'label' => 'Coupons', 'supports' => array('title', 'editor', 'author')));
    register_taxonomy('wcd_brand', 'wcd_coupon', array('public' => true, 'label' => 'Brands'));
}
function vc_test_load_plugin() {
    require_once dirname(__DIR__) . '/vc-rewards.php';
}
$GLOBALS['wp_filter'] = array(
    'init'             => array(0 => array(array('function' => 'vc_test_register_coupon_types', 'accepted_args' => 1))),
    'muplugins_loaded' => array(10 => array(array('function' => 'vc_test_load_plugin', 'accepted_args' => 1))),
);

require $wp_dir . '/wp-load.php';
require_once dirname(__DIR__) . '/includes/admin.php';

vc_rewards_install();

/* -------------------------------------------------------------------------
 * Tiny test harness
 * ---------------------------------------------------------------------- */

$GLOBALS['vc_failures'] = 0;
$GLOBALS['vc_passes']   = 0;

function check($label, $cond, $detail = '') {
    if ($cond) {
        $GLOBALS['vc_passes']++;
        echo "  ok   $label\n";
    } else {
        $GLOBALS['vc_failures']++;
        echo "  FAIL $label" . ($detail !== '' ? " -- $detail" : '') . "\n";
    }
}

function section($name) {
    echo "\n$name\n";
}

function finish() {
    printf("\n%d passed, %d failed\n", $GLOBALS['vc_passes'], $GLOBALS['vc_failures']);
    exit($GLOBALS['vc_failures'] ? 1 : 0);
}

function as_ip($ip) {
    $_SERVER['REMOTE_ADDR'] = $ip;
}

/**
 * A member with whatever standing the test needs.
 *
 * $rank: newcomer (age-verified, fresh), member, trusted, expert, moderator.
 */
function make_user($login, $rank = 'member') {
    global $wpdb;
    $id = wp_insert_user(array(
        'user_login' => $login,
        'user_pass'  => 'pw',
        'user_email' => $login . '@example.com',
        'display_name' => ucfirst($login),
    ));
    update_user_meta($id, '_vc_age_verified', 1);
    update_user_meta($id, '_vc_email_confirmed', 1);
    // Past the new-account review so tests opt into it explicitly.
    update_user_meta($id, '_vc_hold_approved', 99);
    // Silence rank-up bonuses for set-up standing; tests that care reset it.
    update_user_meta($id, '_vc_rankups_paid', array('member', 'trusted', 'expert'));

    if ($rank === 'newcomer') {
        return $id;
    }
    $wpdb->update($wpdb->users, array('user_registered' => gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), array('ID' => $id));
    clean_user_cache($id);
    update_user_meta($id, '_vc_votes_matched', 20);
    update_user_meta($id, '_vc_votes_resolved', 20);
    if ($rank === 'trusted') {
        vc_rewards_add_reputation($id, 150, 'test_setup');
    } elseif ($rank === 'expert') {
        vc_rewards_add_reputation($id, 600, 'test_setup');
    } elseif ($rank === 'moderator') {
        update_user_meta($id, '_vc_moderator', 1);
    }
    return $id;
}

function make_brand($name) {
    $t = wp_insert_term($name, 'wcd_brand');
    return (int) $t['term_id'];
}

/** Submit a coupon and return its contribution row. */
function submit($user_id, $brand, $code, array $extra = array()) {
    // Each author posts from their own address unless a test says otherwise.
    if (empty($extra['keep_ip'])) {
        as_ip('192.0.2.' . ($user_id % 250));
    }
    unset($extra['keep_ip']);
    $r = vc_rewards_submit_coupon($user_id, array_merge(array(
        'brand' => $brand, 'code' => $code, 'discount' => '15% off', 'url' => 'https://store.example/', 'expires' => '',
    ), $extra));
    if (!$r['ok']) {
        return $r;
    }
    return vc_rewards_contribution_for('wcd_coupon', $r['post_id']);
}

function vote($c, $user_id, $verdict, $ip = null) {
    as_ip($ip ?: '198.51.100.' . ($user_id % 250));
    vc_rewards_record_reveal($c->id, $user_id);
    return vc_rewards_cast_vote($c->id, $user_id, $verdict);
}

function fresh($c) {
    return vc_rewards_get_contribution($c->id);
}

function ledger_rows($user_id, $kind = null) {
    global $wpdb;
    $t = vc_rewards_table('ledger');
    if ($kind) {
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE user_id = %d AND kind = %s ORDER BY id", $user_id, $kind));
    }
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE user_id = %d ORDER BY id", $user_id));
}

function sum_rows(array $rows, $status = null) {
    $total = 0;
    foreach ($rows as $r) {
        if ($status === null || $r->status === $status) {
            $total += (int) $r->points;
        }
    }
    return $total;
}

/** Push every pending row past its holding period, then settle. */
function settle_all() {
    global $wpdb;
    $t = vc_rewards_table('ledger');
    $wpdb->query("UPDATE $t SET settle_after = '2000-01-01 00:00:00' WHERE status = 'pending' AND settle_after IS NOT NULL");
    return vc_rewards_settle_due();
}
