<?php
/**
 * The vault and the community fund.
 *
 * Every point a member earns also goes into their vault, where it stays: it
 * is never spent, sold or moved to anyone else, and redeeming points does not
 * lower it. Each week (or month) the site puts up a fund of ordinary points,
 * and it is split by vault size between members who earned at least a set
 * minimum from real activity that period, so idle accounts get nothing.
 *
 * The fund is a set number of points the site owner chooses each period. It
 * is not tied to revenue, and vault points cannot be bought, which keeps this
 * a loyalty reward rather than an investment.
 *
 * A vault is a sum over settled ledger rows, like every other balance, so
 * penalties and reversed purchases take points back out of it too.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Ledger kinds that never count toward a vault. */
function vc_rewards_vault_excluded_kinds() {
    return apply_filters('vc_rewards_vault_excluded_kinds', array('redemption', 'refund', 'vault_share'));
}

/** Vault size, optionally as it stood just before $before (UTC). */
function vc_rewards_vault_balance($user_id, $before = null) {
    $kinds  = vc_rewards_vault_excluded_kinds();
    $where  = "status = 'settled' AND kind NOT IN (" . implode(',', array_fill(0, count($kinds), '%s')) . ')';
    $params = $kinds;
    if ($before) {
        $where   .= ' AND created_at < %s';
        $params[] = $before;
    }
    return max(0, vc_rewards_sum($user_id, $where, $params));
}

function vc_rewards_vault_period_length() {
    return vc_rewards_setting('vault_period') === 'monthly' ? 'monthly' : 'weekly';
}

/**
 * The fund period containing $time (UTC timestamp), or the one $offset periods
 * before it: array(key, start, end) with MySQL UTC datetimes. Weeks run
 * Monday to Monday.
 */
function vc_rewards_vault_period($offset = 0, $time = null) {
    $time = $time === null ? time() : (int) $time;
    if (vc_rewards_vault_period_length() === 'monthly') {
        $start = gmmktime(0, 0, 0, (int) gmdate('n', $time) - $offset, 1, (int) gmdate('Y', $time));
        $end   = gmmktime(0, 0, 0, (int) gmdate('n', $start) + 1, 1, (int) gmdate('Y', $start));
        $key   = 'm' . gmdate('Y-m', $start);
    } else {
        $monday = gmmktime(0, 0, 0, (int) gmdate('n', $time), (int) gmdate('j', $time) - ((int) gmdate('N', $time) - 1), (int) gmdate('Y', $time));
        $start  = $monday - $offset * WEEK_IN_SECONDS;
        $end    = $start + WEEK_IN_SECONDS;
        $key    = 'w' . gmdate('o-\WW', $start);
    }
    return array('key' => $key, 'start' => gmdate('Y-m-d H:i:s', $start), 'end' => gmdate('Y-m-d H:i:s', $end));
}

/** Kinds that don't count as activity: just showing up, or the fund itself. */
function vc_rewards_vault_passive_kinds() {
    return array_merge(vc_rewards_vault_excluded_kinds(), array('login', 'streak', 'onetime'));
}

/**
 * Who shares a period's fund, with their vault at the end of it: members who
 * earned at least the weekly (or monthly) minimum from real activity during
 * the period -- posts, votes, purchases, challenges, not daily visits -- are
 * past Newcomer, have had a post accepted (or a confirmed purchase), aren't
 * banned, and hold the minimum vault.
 */
function vc_rewards_vault_shareholders(array $period) {
    global $wpdb;
    $ledger = vc_rewards_table('ledger');
    $kinds  = vc_rewards_vault_passive_kinds();
    $earned = $wpdb->get_results($wpdb->prepare(
        "SELECT user_id, SUM(points) AS earned FROM $ledger
         WHERE points > 0 AND status <> 'void' AND created_at >= %s AND created_at < %s
         AND kind NOT IN (" . implode(',', array_fill(0, count($kinds), '%s')) . ')
         GROUP BY user_id',
        array_merge(array($period['start'], $period['end']), $kinds)
    ));
    $min_earned = max(1, (int) vc_rewards_setting('vault_min_earned'));
    $min        = max(1, (int) vc_rewards_setting('vault_min'));
    $out = array();
    foreach ($earned as $row) {
        $user_id = (int) $row->user_id;
        if ((int) $row->earned < $min_earned
            || vc_rewards_is_banned($user_id)
            || vc_rewards_rank($user_id) === 'newcomer'
            || !vc_rewards_has_accepted_contribution($user_id)) {
            continue;
        }
        $vault = vc_rewards_vault_balance($user_id, $period['end']);
        if ($vault >= $min) {
            $out[$user_id] = $vault;
        }
    }
    return apply_filters('vc_rewards_vault_shareholders', $out, $period);
}

/** Points from real activity so far in a period, for the account page. */
function vc_rewards_vault_earned_in($user_id, array $period) {
    $kinds = vc_rewards_vault_passive_kinds();
    return vc_rewards_sum(
        $user_id,
        "points > 0 AND status <> 'void' AND created_at >= %s AND created_at < %s AND kind NOT IN (" . implode(',', array_fill(0, count($kinds), '%s')) . ')',
        array_merge(array($period['start'], $period['end']), $kinds)
    );
}

/**
 * Split $pool by vault size. No one gets more than $cap; what a capped member
 * would have had above it goes to everyone else. Whole points only, rounded
 * down, so the total never exceeds the pool.
 */
function vc_rewards_vault_split($pool, array $vaults, $cap = 0) {
    $shares = array();
    $left   = (int) $pool;
    $open   = array_filter($vaults, function ($v) {
        return $v > 0;
    });
    while ($open && $left > 0) {
        $total  = array_sum($open);
        $capped = array();
        foreach ($open as $user_id => $vault) {
            if ($cap > 0 && $left * $vault / $total > $cap) {
                $capped[] = $user_id;
            }
        }
        if (!$capped) {
            foreach ($open as $user_id => $vault) {
                $shares[$user_id] = (int) floor($left * $vault / $total);
            }
            break;
        }
        foreach ($capped as $user_id) {
            $shares[$user_id] = (int) $cap;
            $left -= (int) $cap;
            unset($open[$user_id]);
        }
    }
    return array_filter($shares);
}

function vc_rewards_vault_payout($key) {
    global $wpdb;
    $table = vc_rewards_table('vault_payouts');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE period = %s", $key));
}

/**
 * Pay one period's fund. Each period is paid once; shares are held for 7 days
 * like other earnings, so one found to be fraud can still be cancelled.
 */
function vc_rewards_vault_pay(array $period, $pool) {
    global $wpdb;
    $pool = (int) $pool;
    if ($pool <= 0 || vc_rewards_vault_payout($period['key'])) {
        return false;
    }
    $vaults = vc_rewards_vault_shareholders($period);
    $cap    = (int) floor($pool * (int) vc_rewards_setting('vault_max_share') / 100);
    $shares = vc_rewards_vault_split($pool, $vaults, $cap);

    // Claim the period before paying, so two overlapping cron runs can't both pay it.
    $claimed = $wpdb->insert(vc_rewards_table('vault_payouts'), array(
        'period'      => $period['key'],
        'pool'        => $pool,
        'paid'        => 0,
        'members'     => 0,
        'total_vault' => array_sum($vaults),
        'created_at'  => vc_rewards_now(),
    ));
    if (!$claimed) {
        return false;
    }
    $paid = 0;
    foreach ($shares as $user_id => $points) {
        if (vc_rewards_ledger_add($user_id, $points, 'vault_share', array(
            'settle_days' => 7,
            /* translators: %s: period, e.g. w2026-W41 */
            'note'        => sprintf(__('Community fund %s', 'vc-rewards'), $period['key']),
        ))) {
            $paid += $points;
        }
    }
    $wpdb->update(
        vc_rewards_table('vault_payouts'),
        array('paid' => $paid, 'members' => count($shares)),
        array('period' => $period['key'])
    );
    do_action('vc_rewards_vault_paid', $period, $shares);
    return array('paid' => $paid, 'members' => count($shares));
}

/** Runs hourly: pays the last finished period, once the fund is switched on. */
function vc_rewards_vault_maybe_pay() {
    $pool = (int) vc_rewards_setting('vault_pool');
    if ($pool <= 0) {
        return false;
    }
    // Only periods that end after the fund was first switched on.
    $since = get_option('vc_rewards_vault_since');
    if (!$since) {
        update_option('vc_rewards_vault_since', vc_rewards_now(), false);
        return false;
    }
    $period = vc_rewards_vault_period(1);
    if ($period['end'] <= $since) {
        return false;
    }
    return vc_rewards_vault_pay($period, $pool);
}
add_action('vc_rewards_settle', 'vc_rewards_vault_maybe_pay', 30);

/* -------------------------------------------------------------------------
 * Member account and wp-admin
 * ---------------------------------------------------------------------- */

function vc_rewards_vault_last_share($user_id) {
    global $wpdb;
    $ledger = vc_rewards_table('ledger');
    return $wpdb->get_row($wpdb->prepare(
        "SELECT points, created_at FROM $ledger WHERE user_id = %d AND kind = 'vault_share' AND status <> 'void' ORDER BY id DESC LIMIT 1",
        (int) $user_id
    ));
}

add_filter('vc_rewards_account_sections', function ($out, $user_id) {
    $pool = (int) vc_rewards_setting('vault_pool');
    if ($pool <= 0 || vc_rewards_participation_error($user_id) !== '') {
        return $out;
    }
    $weekly = vc_rewards_vault_period_length() === 'weekly';
    $last   = vc_rewards_vault_last_share($user_id);
    $html   = '<section class="vc-rewards-vault"><h3>' . esc_html__('Your vault', 'vc-rewards') . '</h3>'
        . '<p class="vc-rewards-vault-size">' . esc_html(vc_rewards_format_points(vc_rewards_vault_balance($user_id))) . '</p><p>'
        . esc_html(sprintf(
            /* translators: 1: fund size, 2: "week" or "month" */
            __('Everything you earn is also saved here for good, even after you redeem. Each %2$s, %1$s are shared between active members by vault size.', 'vc-rewards'),
            vc_rewards_format_points($pool),
            $weekly ? __('week', 'vc-rewards') : __('month', 'vc-rewards')
        )) . '</p><p class="vc-rewards-meta">' . esc_html(sprintf(
            /* translators: 1: vault minimum, 2: activity minimum */
            __('To share in it, reach Member rank, have a post accepted, hold %1$s or more, and earn %2$s in the period from posts, votes or purchases (daily visits don\'t count).', 'vc-rewards'),
            vc_rewards_format_points(max(1, (int) vc_rewards_setting('vault_min'))),
            vc_rewards_format_points(max(1, (int) vc_rewards_setting('vault_min_earned')))
        )) . '</p><p class="vc-rewards-meta">' . esc_html(sprintf(
            /* translators: %s: points */
            __('Earned this period so far: %s.', 'vc-rewards'),
            vc_rewards_format_points(vc_rewards_vault_earned_in($user_id, vc_rewards_vault_period(0)))
        )) . '</p>';
    if ($last) {
        $html .= '<p class="vc-rewards-meta">' . esc_html(sprintf(
            /* translators: 1: points, 2: date */
            __('Your last share: %1$s on %2$s.', 'vc-rewards'),
            vc_rewards_format_points((int) $last->points),
            get_date_from_gmt($last->created_at, get_option('date_format'))
        )) . '</p>';
    }
    return $out . $html . '</section>';
}, 15, 2);

add_action('vc_rewards_admin_menu', function () {
    add_submenu_page('vc-rewards', __('Community fund', 'vc-rewards'), __('Community fund', 'vc-rewards'), 'manage_options', 'vc-rewards-vault', 'vc_rewards_vault_page');
});

function vc_rewards_vault_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Admins only.', 'vc-rewards'));
    }
    $pool   = (int) vc_rewards_setting('vault_pool');
    $period = vc_rewards_vault_period(0);
    echo '<div class="wrap"><h1>' . esc_html__('Community fund', 'vc-rewards') . '</h1><p>';
    if ($pool > 0) {
        echo esc_html(sprintf(
            /* translators: 1: points, 2: weekly/monthly, 3: date */
            __('%1$s are paid %2$s. The current period ends %3$s (UTC).', 'vc-rewards'),
            vc_rewards_format_points($pool),
            vc_rewards_vault_period_length() === 'weekly' ? __('weekly', 'vc-rewards') : __('monthly', 'vc-rewards'),
            $period['end']
        ));
    } else {
        esc_html_e('The fund is off. Set the fund size on the Settings screen to switch it on.', 'vc-rewards');
    }
    echo '</p>';
    $vaults = vc_rewards_vault_shareholders($period);
    echo '<p>' . esc_html(sprintf(
        /* translators: 1: members, 2: points */
        __('So far this period: %1$d members qualify, holding %2$s between them.', 'vc-rewards'),
        count($vaults),
        vc_rewards_format_points(array_sum($vaults))
    )) . '</p>';

    $table = vc_rewards_table('vault_payouts');
    $rows  = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC LIMIT 26"); // phpcs:ignore WordPress.DB.PreparedSQL
    echo '<h2>' . esc_html__('Past payouts', 'vc-rewards') . '</h2><table class="widefat striped"><thead><tr><th>'
        . esc_html__('Period', 'vc-rewards') . '</th><th>' . esc_html__('Fund', 'vc-rewards') . '</th><th>'
        . esc_html__('Paid', 'vc-rewards') . '</th><th>' . esc_html__('Members', 'vc-rewards') . '</th><th>'
        . esc_html__('Total in vaults', 'vc-rewards') . '</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="5">' . esc_html__('None yet.', 'vc-rewards') . '</td></tr>';
    }
    foreach ($rows as $r) {
        echo '<tr><td>' . esc_html($r->period) . '</td><td>' . esc_html(vc_rewards_format_points((int) $r->pool)) . '</td><td>'
            . esc_html(vc_rewards_format_points((int) $r->paid)) . '</td><td>' . (int) $r->members . '</td><td>'
            . esc_html(vc_rewards_format_points((int) $r->total_vault)) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}
