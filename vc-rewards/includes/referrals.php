<?php
/**
 * Referrals.
 *
 * A member shares their link (?ref=CODE). The new member's account remembers
 * who sent them, and the referrer is paid only once the new member is a real
 * contributor: Member rank, plus an accepted post or a confirmed purchase.
 * A referral from the same network address as the referrer never pays, and
 * each member can earn a limited number of referral bonuses a month.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Not a secret, just not a bare user ID. */
function vc_rewards_referral_code($user_id) {
    return base_convert((string) ((int) $user_id), 10, 36) . substr(wp_hash('vc_ref' . (int) $user_id), 0, 4);
}

function vc_rewards_referrer_from_code($code) {
    $code = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $code));
    if (strlen($code) < 5) {
        return 0;
    }
    $user_id = (int) base_convert(substr($code, 0, -4), 36, 10);
    return $user_id && get_userdata($user_id) && vc_rewards_referral_code($user_id) === $code ? $user_id : 0;
}

function vc_rewards_referral_link($user_id) {
    return add_query_arg('ref', vc_rewards_referral_code($user_id), home_url('/'));
}

// Remember the referrer for 30 days on the visitor's browser.
add_action('init', function () {
    if (empty($_GET['ref']) || is_user_logged_in()) {
        return;
    }
    $referrer = vc_rewards_referrer_from_code(sanitize_text_field(wp_unslash($_GET['ref'])));
    if ($referrer && !headers_sent()) {
        setcookie('vc_ref', vc_rewards_referral_code($referrer), time() + 30 * DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE['vc_ref'] = vc_rewards_referral_code($referrer);
    }
});

add_action('user_register', function ($user_id) {
    update_user_meta($user_id, '_vc_signup_ip', vc_rewards_ip_hash());
    $referrer = !empty($_COOKIE['vc_ref']) ? vc_rewards_referrer_from_code(sanitize_text_field(wp_unslash($_COOKIE['vc_ref']))) : 0;
    if ($referrer && $referrer !== (int) $user_id) {
        update_user_meta($user_id, '_vc_referred_by', $referrer);
    }
}, 20);

/** Why this referral can't pay (yet), or '' when it should pay now. */
function vc_rewards_referral_blocker($referee_id) {
    global $wpdb;
    $referee_id = (int) $referee_id;
    $referrer   = (int) get_user_meta($referee_id, '_vc_referred_by', true);
    if (!$referrer || get_user_meta($referee_id, '_vc_referral_paid', true)) {
        return 'none';
    }
    if (vc_rewards_is_banned($referrer) || vc_rewards_is_banned($referee_id)) {
        return 'paused';
    }
    if (in_array(vc_rewards_rank($referee_id), array('newcomer'), true)) {
        return 'rank';
    }
    if (!vc_rewards_has_accepted_contribution($referee_id)) {
        return 'contribution';
    }
    $ip = (string) get_user_meta($referee_id, '_vc_signup_ip', true);
    if ($ip !== '' && $ip === (string) get_user_meta($referrer, '_vc_signup_ip', true)) {
        return 'same_ip';
    }
    $ledger = vc_rewards_table('ledger');
    $month  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $ledger WHERE user_id = %d AND kind = 'referral' AND status <> 'void' AND created_at >= %s",
        $referrer,
        vc_rewards_now(-30 * DAY_IN_SECONDS)
    ));
    if ($month >= (int) vc_rewards_setting('referral_monthly_cap')) {
        return 'cap';
    }
    return '';
}

/** Pay the referrer if the new member now qualifies. */
function vc_rewards_maybe_pay_referral($referee_id) {
    $blocker = vc_rewards_referral_blocker($referee_id);
    if ($blocker !== '') {
        if ($blocker === 'same_ip') {
            // Never going to pay; stop checking.
            update_user_meta($referee_id, '_vc_referral_paid', 'blocked_same_ip');
        }
        return false;
    }
    $referrer = (int) get_user_meta($referee_id, '_vc_referred_by', true);
    $referee  = get_userdata($referee_id);
    update_user_meta($referee_id, '_vc_referral_paid', 1);
    vc_rewards_ledger_add($referrer, (int) vc_rewards_setting('referral_bonus'), 'referral', array(
        'settle_days' => 14,
        /* translators: %s: new member's name */
        'note'        => sprintf(__('Referred %s', 'vc-rewards'), $referee ? $referee->display_name : '#' . (int) $referee_id),
    ));
    return true;
}

add_action('vc_rewards_daily_visit', 'vc_rewards_maybe_pay_referral');
add_action('vc_rewards_rank_changed', 'vc_rewards_maybe_pay_referral');
add_action('vc_rewards_purchase_confirmed', 'vc_rewards_maybe_pay_referral');
add_action('vc_rewards_contribution_state', function ($contribution_id, $state) {
    if ($state === 'accepted') {
        $c = vc_rewards_get_contribution($contribution_id);
        if ($c) {
            vc_rewards_maybe_pay_referral((int) $c->author_id);
        }
    }
}, 40, 2);

add_filter('vc_rewards_account_sections', function ($out, $user_id) {
    if (vc_rewards_participation_error($user_id) !== '') {
        return $out;
    }
    $referred = get_users(array('meta_key' => '_vc_referred_by', 'meta_value' => (int) $user_id, 'fields' => 'ID', 'number' => 500));
    $paid = 0;
    foreach ($referred as $rid) {
        if ((string) get_user_meta($rid, '_vc_referral_paid', true) === '1') {
            $paid++;
        }
    }
    return $out . '<section class="vc-rewards-referral"><h3>' . esc_html__('Invite friends', 'vc-rewards') . '</h3><p>'
        . esc_html(sprintf(
            /* translators: %s: points */
            __('Share your link. You earn %s when someone you invite reaches Member rank and has a post accepted.', 'vc-rewards'),
            vc_rewards_format_points((int) vc_rewards_setting('referral_bonus'))
        )) . '</p><p><input type="text" readonly value="' . esc_attr(vc_rewards_referral_link($user_id)) . '" onclick="this.select()" class="vc-rewards-copy"></p>'
        . '<p class="vc-rewards-meta">' . esc_html(sprintf(
            /* translators: 1: people joined, 2: bonuses earned */
            __('%1$d joined with your link, %2$d qualified.', 'vc-rewards'),
            count($referred),
            $paid
        )) . '</p></section>';
}, 20, 2);
