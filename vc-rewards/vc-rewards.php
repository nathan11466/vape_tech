<?php
/**
 * Plugin Name: VapingCheap Community Rewards
 * Description: Members submit coupons and forum deal finds, reviews and guides, verify each other's posts, and earn from reports, corrections, answers, challenges, referrals and cashback. Rewards are weighted by the rank of the members who verify, with reputation, penalties and hand-reviewed redemptions.
 * Version: 0.3.0
 * Requires PHP: 7.4
 * Text Domain: vc-rewards
 *
 * Design: rewards are paid for *contributions* (a coupon, or a wpForo deal find,
 * review or guide) once members with enough standing have
 * voted on them. Two scores are kept apart on purpose:
 *
 *   points      spendable, can be redeemed, live in the ledger table
 *   reputation  never spent, decides rank, and rank decides vote weight
 *
 * Nothing in this plugin pays out real value on its own. Every redemption
 * waits for a person to approve it in wp-admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VC_REWARDS_VERSION    = '0.3.0';
const VC_REWARDS_DB_VERSION = '3';

define('VC_REWARDS_DIR', plugin_dir_path(__FILE__));
define('VC_REWARDS_URL', plugin_dir_url(__FILE__));

require_once VC_REWARDS_DIR . 'includes/install.php';
require_once VC_REWARDS_DIR . 'includes/config.php';
require_once VC_REWARDS_DIR . 'includes/ledger.php';
require_once VC_REWARDS_DIR . 'includes/reputation.php';
require_once VC_REWARDS_DIR . 'includes/contributions.php';
require_once VC_REWARDS_DIR . 'includes/votes.php';
require_once VC_REWARDS_DIR . 'includes/members.php';
require_once VC_REWARDS_DIR . 'includes/objects.php';
require_once VC_REWARDS_DIR . 'includes/coupons.php';
require_once VC_REWARDS_DIR . 'includes/wpforo.php';
require_once VC_REWARDS_DIR . 'includes/reports.php';
require_once VC_REWARDS_DIR . 'includes/answers.php';
require_once VC_REWARDS_DIR . 'includes/bonuses.php';
require_once VC_REWARDS_DIR . 'includes/challenges.php';
require_once VC_REWARDS_DIR . 'includes/referrals.php';
require_once VC_REWARDS_DIR . 'includes/purchases.php';
require_once VC_REWARDS_DIR . 'includes/awin.php';
require_once VC_REWARDS_DIR . 'includes/redemptions.php';
require_once VC_REWARDS_DIR . 'includes/frontend.php';

if (is_admin()) {
    require_once VC_REWARDS_DIR . 'includes/admin.php';
}

register_activation_hook(__FILE__, 'vc_rewards_activate');
register_deactivation_hook(__FILE__, 'vc_rewards_deactivate');

function vc_rewards_activate() {
    vc_rewards_install();
    if (!wp_next_scheduled('vc_rewards_settle')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'vc_rewards_settle');
    }
}

function vc_rewards_deactivate() {
    wp_clear_scheduled_hook('vc_rewards_settle');
}

// Upgrades that arrive by replacing files never fire the activation hook.
add_action('plugins_loaded', function () {
    if (get_option('vc_rewards_db_version') !== VC_REWARDS_DB_VERSION) {
        vc_rewards_install();
    }
});

add_action('vc_rewards_settle', 'vc_rewards_settle_due');
