<?php
/**
 * Redemptions. Every one waits for a person to approve it.
 *
 * A request reserves the points straight away (a negative pending ledger
 * row), so the member cannot request the same points twice. Approval
 * settles the reservation; rejection voids it and the points come back.
 * Delivering the reward itself (sending a code, adding store credit) is done
 * by hand, and the admin note records what was sent.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_reward_options() {
    $lines = preg_split('/\r\n|\r|\n/', (string) vc_rewards_setting('reward_options'));
    return array_values(array_filter(array_map('trim', $lines), 'strlen'));
}

/**
 * Returns array('ok' => bool, 'message' => string, 'id' => int).
 */
function vc_rewards_request_redemption($user_id, $points, $reward) {
    global $wpdb;
    $user_id = (int) $user_id;
    $points  = (int) $points;
    $fail = function ($message) {
        return array('ok' => false, 'message' => $message, 'id' => 0);
    };

    $error = vc_rewards_participation_error($user_id);
    if ($error) {
        return $fail($error);
    }
    if (!in_array($reward, vc_rewards_reward_options(), true)) {
        return $fail(__('Choose a reward.', 'vc-rewards'));
    }
    $min = (int) vc_rewards_setting('min_redemption');
    if ($points < $min) {
        /* translators: %s: minimum points */
        return $fail(sprintf(__('The minimum redemption is %s.', 'vc-rewards'), vc_rewards_format_points($min)));
    }
    if (vc_rewards_available($user_id) < 0) {
        return $fail(__('Your balance is below zero. Earn it back above zero before redeeming.', 'vc-rewards'));
    }
    $redeemable = vc_rewards_redeemable($user_id);
    if ($points > $redeemable) {
        $message = vc_rewards_has_accepted_contribution($user_id)
            ? __("You don't have that many points available.", 'vc-rewards')
            : __('Daily visit and bonus points unlock once one of your posts has been accepted.', 'vc-rewards');
        return $fail($message);
    }

    $ledger_id = vc_rewards_ledger_add($user_id, -$points, 'redemption', array(
        'status' => 'pending',
        'note'   => $reward,
    ));
    $wpdb->insert(vc_rewards_table('redemptions'), array(
        'user_id'    => $user_id,
        'points'     => $points,
        'reward'     => $reward,
        'status'     => 'requested',
        'ledger_id'  => $ledger_id,
        'created_at' => vc_rewards_now(),
    ));
    $id = (int) $wpdb->insert_id;
    do_action('vc_rewards_redemption_requested', $id, $user_id, $points, $reward);

    return array('ok' => true, 'message' => __("Request sent. We review every redemption by hand and you'll hear from us soon.", 'vc-rewards'), 'id' => $id);
}

function vc_rewards_get_redemption($id) {
    global $wpdb;
    $table = vc_rewards_table('redemptions');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id));
}

function vc_rewards_decide_redemption($id, $approve, $admin_id, $note = '') {
    global $wpdb;
    $r = vc_rewards_get_redemption($id);
    if (!$r || $r->status !== 'requested') {
        return false;
    }
    vc_rewards_ledger_set_status((int) $r->ledger_id, $approve ? 'settled' : 'void');
    $wpdb->update(vc_rewards_table('redemptions'), array(
        'status'     => $approve ? 'approved' : 'rejected',
        'admin_note' => mb_substr(sanitize_text_field($note), 0, 255),
        'decided_at' => vc_rewards_now(),
        'decided_by' => (int) $admin_id,
    ), array('id' => (int) $r->id));
    do_action('vc_rewards_redemption_decided', (int) $r->id, (bool) $approve);
    return true;
}

function vc_rewards_redemptions($status = 'requested', $user_id = 0, $limit = 50) {
    global $wpdb;
    $table = vc_rewards_table('redemptions');
    if ($user_id) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT %d",
            (int) $user_id, (int) $limit
        ));
    }
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status = %s ORDER BY id ASC LIMIT %d",
        $status, (int) $limit
    ));
}

/**
 * Everything a reviewer needs to judge a request at a glance.
 */
function vc_rewards_review_summary($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    $ledger  = vc_rewards_table('ledger');
    $votes   = vc_rewards_table('votes');
    $contrib = vc_rewards_table('contributions');
    $user    = get_userdata($user_id);

    $earned = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(points), 0) FROM $ledger WHERE user_id = %d AND points > 0 AND status = 'settled' AND kind <> 'refund'",
        $user_id
    ));
    $unearned_kinds = vc_rewards_unearned_kinds();
    $from_visits = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(points), 0) FROM $ledger WHERE user_id = %d AND status = 'settled' AND kind IN (%s, %s, %s)",
        array_merge(array($user_id), $unearned_kinds)
    ));
    $flags_as_voter = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $votes WHERE voter_id = %d AND flags <> ''",
        $user_id
    ));
    $flags_on_posts = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $votes WHERE author_id = %d AND (flags LIKE %s OR flags LIKE %s)",
        $user_id, '%same_ip%', '%reciprocal%'
    ));
    $counts = $wpdb->get_results($wpdb->prepare(
        "SELECT state, COUNT(*) AS n FROM $contrib WHERE author_id = %d GROUP BY state",
        $user_id
    ), OBJECT_K);

    return array(
        'name'           => $user ? $user->display_name : '#' . $user_id,
        'registered'     => $user ? $user->user_registered : '',
        'rank'           => vc_rewards_rank($user_id),
        'reputation'     => vc_rewards_reputation($user_id),
        'balance'        => vc_rewards_balance($user_id),
        'pending'        => vc_rewards_pending($user_id),
        'earned'         => $earned,
        'visit_share'    => $earned > 0 ? $from_visits / $earned : 0,
        'flags_as_voter' => $flags_as_voter,
        'flags_on_posts' => $flags_on_posts,
        'accepted'       => isset($counts['accepted']) ? (int) $counts['accepted']->n : 0,
        'rejected'       => isset($counts['rejected']) ? (int) $counts['rejected']->n : 0,
    );
}
