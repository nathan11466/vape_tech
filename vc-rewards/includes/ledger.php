<?php
/**
 * The points ledger.
 *
 * Every change to a balance is one row. Rows start 'pending' when they can
 * still be taken back (a contribution in its holding period, a redemption
 * waiting for review) and become 'settled' or 'void'. Balances are always
 * sums over rows, never a counter that can drift.
 *
 *   settled   counts toward the balance
 *   pending   positive: earned but can still be clawed back
 *             negative: points reserved by a redemption request
 *   void      cancelled; kept for the audit trail
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Kinds that count toward the daily earning cap. */
function vc_rewards_capped_kinds() {
    return array('contribution', 'vote');
}

/** Kinds that cannot be redeemed until the member has an accepted contribution. */
function vc_rewards_unearned_kinds() {
    return array('login', 'streak', 'onetime');
}

/**
 * Add a ledger row. Returns the row ID, or 0 when nothing was added (the cap
 * left nothing, or the amount was zero).
 *
 * $args:
 *   status          'pending' (default) or 'settled'
 *   settle_days     days until a pending row settles; null = never automatically
 *   contribution_id what the row is for
 *   note            short human-readable reason
 */
function vc_rewards_ledger_add($user_id, $points, $kind, array $args = array()) {
    global $wpdb;

    $args = wp_parse_args($args, array(
        'status'          => 'pending',
        'settle_days'     => null,
        'contribution_id' => 0,
        'note'            => '',
    ));

    $user_id = (int) $user_id;
    $points  = (int) $points;

    if ($points > 0 && in_array($kind, vc_rewards_capped_kinds(), true)) {
        $cap = (int) vc_rewards_setting('daily_earn_cap');
        if ($cap > 0) {
            $room   = max(0, $cap - vc_rewards_earned_today($user_id));
            $points = min($points, $room);
        }
    }

    // Penalties stop at the floor, so a spammer cannot be driven to an
    // arbitrarily large debt, but can still end up below zero -- which blocks
    // redemption until they earn their way back.
    if ($points < 0 && $kind === 'penalty') {
        $floor     = (int) vc_rewards_setting('balance_floor');
        $available = vc_rewards_available($user_id);
        $points    = max($points, min(0, $floor - $available));
    }

    if ($points === 0 || !$user_id) {
        return 0;
    }

    $settle_after = null;
    if ($args['status'] === 'pending' && $args['settle_days'] !== null) {
        $settle_after = vc_rewards_now((int) round($args['settle_days'] * DAY_IN_SECONDS));
    }

    $wpdb->insert(vc_rewards_table('ledger'), array(
        'user_id'         => $user_id,
        'points'          => $points,
        'kind'            => $kind,
        'status'          => $args['status'],
        'contribution_id' => (int) $args['contribution_id'],
        'note'            => mb_substr((string) $args['note'], 0, 255),
        'created_at'      => vc_rewards_now(),
        'settle_after'    => $settle_after,
    ));
    $id = (int) $wpdb->insert_id;

    if ($args['status'] === 'settled') {
        vc_rewards_mirror($id);
    }

    do_action('vc_rewards_ledger_added', $id, $user_id, $points, $kind, $args);

    return $id;
}

function vc_rewards_sum($user_id, $where_sql, array $params = array()) {
    global $wpdb;
    $table = vc_rewards_table('ledger');
    array_unshift($params, (int) $user_id);
    $sql = "SELECT COALESCE(SUM(points), 0) FROM $table WHERE user_id = %d AND $where_sql";
    return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
}

/** Settled points. */
function vc_rewards_balance($user_id) {
    return vc_rewards_sum($user_id, "status = 'settled'");
}

/** Earned, still in the holding period. */
function vc_rewards_pending($user_id) {
    return vc_rewards_sum($user_id, "status = 'pending' AND points > 0");
}

/** Settled points minus anything reserved by open redemption requests. */
function vc_rewards_available($user_id) {
    return vc_rewards_balance($user_id)
        + vc_rewards_sum($user_id, "status = 'pending' AND points < 0");
}

/**
 * What the member may actually redeem.
 *
 * Login, streak and one-time points only become redeemable once the member
 * has had a contribution accepted; a farm of idle accounts is worth nothing.
 */
function vc_rewards_redeemable($user_id) {
    $available = vc_rewards_available($user_id);
    if (vc_rewards_has_accepted_contribution($user_id)) {
        return max(0, $available);
    }
    $kinds = vc_rewards_unearned_kinds();
    $placeholders = implode(',', array_fill(0, count($kinds), '%s'));
    $unearned = vc_rewards_sum($user_id, "status = 'settled' AND kind IN ($placeholders)", $kinds);
    return max(0, $available - $unearned);
}

/** Capped earnings created since UTC midnight. */
function vc_rewards_earned_today($user_id) {
    $kinds = vc_rewards_capped_kinds();
    $placeholders = implode(',', array_fill(0, count($kinds), '%s'));
    $params = array_merge($kinds, array(gmdate('Y-m-d 00:00:00')));
    return vc_rewards_sum(
        $user_id,
        "points > 0 AND status <> 'void' AND kind IN ($placeholders) AND created_at >= %s",
        $params
    );
}

/** Settle every pending row whose holding period is over. */
function vc_rewards_settle_due() {
    global $wpdb;
    $table = vc_rewards_table('ledger');
    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT id FROM $table WHERE status = 'pending' AND points > 0 AND settle_after IS NOT NULL AND settle_after <= %s",
        vc_rewards_now()
    ));
    foreach ($ids as $id) {
        vc_rewards_ledger_set_status((int) $id, 'settled');
    }
    return count($ids);
}

function vc_rewards_ledger_set_status($id, $status) {
    global $wpdb;
    $changed = $wpdb->update(
        vc_rewards_table('ledger'),
        array('status' => $status),
        array('id' => (int) $id, 'status' => 'pending')
    );
    if ($changed && $status === 'settled') {
        vc_rewards_mirror($id);
    }
    return (bool) $changed;
}

/**
 * Void the pending rows tied to a contribution -- the clawback.
 * Returns how many points were taken back.
 */
function vc_rewards_void_for_contribution($contribution_id, array $kinds = array(), $user_id = 0) {
    global $wpdb;
    $table = vc_rewards_table('ledger');

    $sql    = "SELECT id, points FROM $table WHERE contribution_id = %d AND status = 'pending' AND points > 0";
    $params = array((int) $contribution_id);
    if ($kinds) {
        $sql   .= ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '%s')) . ')';
        $params = array_merge($params, $kinds);
    }
    if ($user_id) {
        $sql     .= ' AND user_id = %d';
        $params[] = (int) $user_id;
    }

    $total = 0;
    foreach ($wpdb->get_results($wpdb->prepare($sql, $params)) as $row) {
        if (vc_rewards_ledger_set_status($row->id, 'void')) {
            $total += (int) $row->points;
        }
    }
    return $total;
}

function vc_rewards_ledger_history($user_id, $limit = 25) {
    global $wpdb;
    $table = vc_rewards_table('ledger');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT %d",
        (int) $user_id,
        (int) $limit
    ));
}

/**
 * Optional: copy settled rows into myCRED, so its leaderboards and badges can
 * be used for display. This ledger stays the source of truth; myCRED has no
 * notion of pending points or clawbacks.
 */
function vc_rewards_mirror($ledger_id) {
    if (!function_exists('mycred_add') || !apply_filters('vc_rewards_mirror_to_mycred', false)) {
        return;
    }
    global $wpdb;
    $table = vc_rewards_table('ledger');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $ledger_id));
    if (!$row || $row->status !== 'settled') {
        return;
    }
    mycred_add('vc_rewards_' . $row->kind, (int) $row->user_id, (int) $row->points, $row->note ? $row->note : $row->kind, (int) $row->id);
}
