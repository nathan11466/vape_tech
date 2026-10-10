<?php
/**
 * Reputation and rank.
 *
 * Reputation is never spent and cannot be bought or transferred. Rank is
 * derived from it (plus a few account checks) every time it is needed, so a
 * penalty takes effect on the very next vote with nothing to recalculate.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_rank_order() {
    return array('newcomer', 'member', 'trusted', 'expert', 'moderator');
}

function vc_rewards_rank_label($rank) {
    $labels = array(
        'newcomer'  => __('Newcomer', 'vc-rewards'),
        'member'    => __('Member', 'vc-rewards'),
        'trusted'   => __('Trusted', 'vc-rewards'),
        'expert'    => __('Expert', 'vc-rewards'),
        'moderator' => __('Moderator', 'vc-rewards'),
    );
    return isset($labels[$rank]) ? $labels[$rank] : $rank;
}

function vc_rewards_add_reputation($user_id, $delta, $reason, $contribution_id = 0) {
    global $wpdb;
    $user_id = (int) $user_id;
    $delta   = (int) $delta;
    if (!$user_id || !$delta) {
        return;
    }
    $table = vc_rewards_table('reputation');
    $wpdb->insert($table, array(
        'user_id'         => $user_id,
        'delta'           => $delta,
        'reason'          => $reason,
        'contribution_id' => (int) $contribution_id,
        'created_at'      => vc_rewards_now(),
    ));
    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(delta), 0) FROM $table WHERE user_id = %d",
        $user_id
    ));
    update_user_meta($user_id, '_vc_reputation', $total);

    vc_rewards_check_rankup($user_id);
}

function vc_rewards_reputation($user_id) {
    return (int) get_user_meta((int) $user_id, '_vc_reputation', true);
}

function vc_rewards_is_moderator($user_id) {
    $user_id = (int) $user_id;
    if (!$user_id) {
        return false;
    }
    return user_can($user_id, 'manage_options') || (bool) get_user_meta($user_id, '_vc_moderator', true);
}

function vc_rewards_age_verified($user_id) {
    return (bool) get_user_meta((int) $user_id, '_vc_age_verified', true);
}

/**
 * Share of resolved factual votes that matched the outcome.
 *
 * Divides by at least 5, so two lucky votes do not read as 100% accuracy.
 */
function vc_rewards_accuracy($user_id) {
    $resolved = (int) get_user_meta((int) $user_id, '_vc_votes_resolved', true);
    $matched  = (int) get_user_meta((int) $user_id, '_vc_votes_matched', true);
    return $matched / max(5, $resolved);
}

function vc_rewards_rank($user_id) {
    $user_id = (int) $user_id;
    if (!$user_id) {
        return 'newcomer';
    }
    if (vc_rewards_is_moderator($user_id)) {
        return 'moderator';
    }

    $s   = vc_rewards_settings();
    $rep = vc_rewards_reputation($user_id);

    if ($rep < 0 || !vc_rewards_age_verified($user_id) || !get_user_meta($user_id, '_vc_email_confirmed', true)) {
        return 'newcomer';
    }

    $user = get_userdata($user_id);
    $days = $user ? (time() - strtotime($user->user_registered . ' UTC')) / DAY_IN_SECONDS : 0;
    $matched = (int) get_user_meta($user_id, '_vc_votes_matched', true);
    if ($days < $s['member_min_account_days'] || $matched < $s['member_min_accurate_votes']) {
        return 'newcomer';
    }

    $accuracy = vc_rewards_accuracy($user_id);
    if ($rep >= $s['expert_min_reputation'] && $accuracy >= $s['expert_min_accuracy']) {
        return 'expert';
    }
    if ($rep >= $s['trusted_min_reputation'] && $accuracy >= $s['trusted_min_accuracy']) {
        return 'trusted';
    }
    return 'member';
}

function vc_rewards_rank_weight($rank) {
    $weights = vc_rewards_setting('rank_weights');
    return isset($weights[$rank]) ? (float) $weights[$rank] : 0.0;
}

/**
 * Pay rank-up bonuses the member has not had yet.
 *
 * Each bonus is paid once per account, ever: dropping a rank and climbing
 * back does not pay it again. Bonuses sit pending for 14 days so a rank
 * reached through collusion can be reversed before it settles.
 */
function vc_rewards_check_rankup($user_id) {
    $rank    = vc_rewards_rank($user_id);
    $order   = vc_rewards_rank_order();
    $reached = array_search($rank, $order, true);
    $bonuses = vc_rewards_setting('rankup_bonus');
    $paid    = get_user_meta($user_id, '_vc_rankups_paid', true);
    $paid    = is_array($paid) ? $paid : array();

    $previous = get_user_meta($user_id, '_vc_rank', true);
    if ($previous !== $rank) {
        update_user_meta($user_id, '_vc_rank', $rank);
        do_action('vc_rewards_rank_changed', $user_id, $rank, $previous);
    }

    // Moderators are appointed, not earned; appointing one pays nothing.
    if ($rank === 'moderator') {
        return;
    }

    foreach ($order as $i => $r) {
        if ($i > $reached || in_array($r, $paid, true) || empty($bonuses[$r])) {
            continue;
        }
        vc_rewards_ledger_add($user_id, (int) $bonuses[$r], 'rankup', array(
            'settle_days' => 14,
            /* translators: %s: rank name */
            'note'        => sprintf(__('Reached %s', 'vc-rewards'), vc_rewards_rank_label($r)),
        ));
        $paid[] = $r;
    }
    update_user_meta($user_id, '_vc_rankups_paid', $paid);
}

/**
 * Whether this member's new posts wait for a moderator before members can
 * see them: the first few posts of every account, and anyone whose
 * reputation has fallen below zero.
 */
function vc_rewards_under_review($user_id) {
    if (vc_rewards_is_moderator($user_id)) {
        return false;
    }
    if (vc_rewards_reputation($user_id) < 0) {
        return true;
    }
    $approved = (int) get_user_meta((int) $user_id, '_vc_hold_approved', true);
    return $approved < (int) vc_rewards_setting('hold_first_posts');
}

function vc_rewards_is_banned($user_id) {
    return (bool) get_user_meta((int) $user_id, '_vc_banned', true);
}
