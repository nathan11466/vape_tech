<?php
/**
 * The vault and the community fund.
 */

require __DIR__ . '/bootstrap.php';
global $wpdb;

function vault_settings(array $s) {
    update_option(VC_REWARDS_OPTION, $s);
}

/** A ledger row at a set time, bypassing caps, for building history. */
function row_at($user_id, $points, $kind, $created, $status = 'settled') {
    global $wpdb;
    $wpdb->insert(vc_rewards_table('ledger'), array(
        'user_id' => $user_id, 'points' => $points, 'kind' => $kind, 'status' => $status,
        'contribution_id' => 0, 'note' => 'test', 'created_at' => $created, 'settle_after' => null,
    ));
    return (int) $wpdb->insert_id;
}

/* ------------------------------------------------------------------------- */
section('Vault balance');

$a = make_user('vault_a');
row_at($a, 5000, 'contribution', '2026-09-01 10:00:00');
row_at($a, 1000, 'login', '2026-09-02 10:00:00');
row_at($a, -3000, 'redemption', '2026-09-03 10:00:00');
row_at($a, 800, 'vote', '2026-09-04 10:00:00', 'pending');
row_at($a, 400, 'vault_share', '2026-09-05 10:00:00');
check('vault counts settled earnings, ignores redemptions, pending rows and fund shares', vc_rewards_vault_balance($a) === 6000, vc_rewards_vault_balance($a));
check('redeeming leaves the vault alone but lowers the balance', vc_rewards_balance($a) === 3400);
row_at($a, -500, 'penalty', '2026-09-06 10:00:00');
check('penalties come out of the vault', vc_rewards_vault_balance($a) === 5500);
check('vault as of a past time', vc_rewards_vault_balance($a, '2026-09-02 00:00:00') === 5000);
$z = make_user('vault_zero');
row_at($z, -900, 'penalty', '2026-09-06 10:00:00');
check('a vault never goes below zero', vc_rewards_vault_balance($z) === 0);

/* ------------------------------------------------------------------------- */
section('Periods');

$t = gmmktime(15, 0, 0, 10, 15, 2026); // Thursday 2026-10-15
$p = vc_rewards_vault_period(0, $t);
check('weekly period runs Monday to Monday', $p['start'] === '2026-10-12 00:00:00' && $p['end'] === '2026-10-19 00:00:00', print_r($p, true));
$p1 = vc_rewards_vault_period(1, $t);
check('previous week', $p1['start'] === '2026-10-05 00:00:00' && $p1['key'] === 'w2026-W41', print_r($p1, true));
vault_settings(array('vault_period' => 'monthly'));
$m = vc_rewards_vault_period(1, gmmktime(1, 0, 0, 1, 10, 2027));
check('previous month across a year end', $m['start'] === '2026-12-01 00:00:00' && $m['end'] === '2027-01-01 00:00:00' && $m['key'] === 'm2026-12', print_r($m, true));
vault_settings(array());

/* ------------------------------------------------------------------------- */
section('Splitting the fund');

$s = vc_rewards_vault_split(1000, array(1 => 300, 2 => 100));
check('split by vault size', $s === array(1 => 750, 2 => 250), print_r($s, true));
$s = vc_rewards_vault_split(1000, array(1 => 9000, 2 => 500, 3 => 500), 400);
check('a capped member gets the cap, the rest goes to everyone else', $s[1] === 400 && $s[2] === 300 && $s[3] === 300, print_r($s, true));
$s = vc_rewards_vault_split(1000, array(1 => 1, 2 => 1, 3 => 1));
check('rounding down never pays more than the fund', array_sum($s) <= 1000 && $s[1] === 333);
$s = vc_rewards_vault_split(1000, array(1 => 5000), 100);
check('with everyone capped, the rest of the fund is not paid', $s === array(1 => 100));

/* ------------------------------------------------------------------------- */
section('Who shares');

$period = array('key' => 'wtest', 'start' => '2026-10-05 00:00:00', 'end' => '2026-10-12 00:00:00');
$in    = '2026-10-07 12:00:00';
$early = '2026-08-01 12:00:00';

// Has a confirmed purchase (counts as an accepted post) and history.
function fund_member($login, $vault, $earned_in_period, $earned_kind = 'vote') {
    global $early, $in;
    $id = make_user($login);
    row_at($id, 100, 'purchase', $early);
    row_at($id, $vault - 100, 'contribution', $early);
    if ($earned_in_period) {
        row_at($id, $earned_in_period, $earned_kind, $in);
    }
    return $id;
}

$big     = fund_member('fund_big', 30000, 600);
$small   = fund_member('fund_small', 10000, 500);
$idle    = fund_member('fund_idle', 50000, 0);
row_at($idle, 25, 'login', $in);
row_at($idle, 250, 'streak', $in);
$lazy    = fund_member('fund_lazy', 50000, 499);
$visitor = fund_member('fund_visitor', 50000, 1000, 'login');
$tiny    = fund_member('fund_tiny', 1500, 900);
$banned  = fund_member('fund_banned', 40000, 900);
update_user_meta($banned, '_vc_banned', 1);
$newbie  = make_user('fund_newbie', 'newcomer');
row_at($newbie, 100, 'purchase', $early);
row_at($newbie, 20000, 'contribution', $early);
row_at($newbie, 900, 'vote', $in);
$noposts = make_user('fund_noposts');
row_at($noposts, 20000, 'onetime', $early);
row_at($noposts, 900, 'vote', $in);
$late    = fund_member('fund_late', 10000, 600);
row_at($late, 90000, 'contribution', '2026-10-13 12:00:00'); // after the period

$holders = vc_rewards_vault_shareholders($period);
check('active members with a vault share', isset($holders[$big], $holders[$small]));
check('a member who only visited gets nothing', !isset($holders[$idle]));
check('earning just under the minimum gets nothing', !isset($holders[$lazy]));
check('daily-visit points don\'t count toward the minimum', !isset($holders[$visitor]));
check('a vault under the minimum gets nothing', !isset($holders[$tiny]));
check('banned members get nothing', !isset($holders[$banned]));
check('newcomers get nothing', !isset($holders[$newbie]));
check('members with no accepted post or purchase get nothing', !isset($holders[$noposts]));
check('vault is measured at the end of the period', isset($holders[$late]) && $holders[$late] === 10600, isset($holders[$late]) ? $holders[$late] : 'missing');
check('earnings in the period count toward the vault', $holders[$big] === 30600);

/* ------------------------------------------------------------------------- */
section('Paying out');

vault_settings(array('vault_pool' => 10000, 'vault_max_share' => 100));
$r = vc_rewards_vault_pay($period, 10000);
$rows = ledger_rows($big, 'vault_share');
check('the fund is paid', $r && $r['members'] === count($holders) && $r['paid'] <= 10000, print_r($r, true));
check('shares are held before settling', $rows && $rows[0]->status === 'pending');
$total = array_sum($holders);
check('share matches vault size', (int) $rows[0]->points === (int) floor(10000 * 30600 / $total), $rows[0]->points);
check('a period is never paid twice', vc_rewards_vault_pay($period, 10000) === false && count(ledger_rows($big, 'vault_share')) === 1);
$log = vc_rewards_vault_payout('wtest');
check('payout recorded', $log && (int) $log->paid === $r['paid'] && (int) $log->members === $r['members']);
check('fund shares don\'t grow the vault', vc_rewards_vault_balance($big) === 30600);

/* ------------------------------------------------------------------------- */
section('Schedule');

vault_settings(array('vault_pool' => 0));
delete_option('vc_rewards_vault_since');
check('fund off: nothing happens', vc_rewards_vault_maybe_pay() === false && !get_option('vc_rewards_vault_since'));
vault_settings(array('vault_pool' => 5000));
check('first run after switching on only starts the clock', vc_rewards_vault_maybe_pay() === false && get_option('vc_rewards_vault_since'));
check('the period already over when it was switched on is not paid', vc_rewards_vault_maybe_pay() === false);
update_option('vc_rewards_vault_since', '2000-01-01 00:00:00');
$last = vc_rewards_vault_period(1);
check('the last finished period is paid', vc_rewards_vault_maybe_pay() !== false && vc_rewards_vault_payout($last['key']));
check('and only once', vc_rewards_vault_maybe_pay() === false);

/* ------------------------------------------------------------------------- */
section('Account page');

wp_set_current_user($big);
$html = apply_filters('vc_rewards_account_sections', '', $big);
check('account shows the vault', strpos($html, 'Your vault') !== false && strpos($html, 'Your last share') !== false);
vault_settings(array('vault_pool' => 0));
$html = apply_filters('vc_rewards_account_sections', '', $big);
check('hidden when the fund is off', strpos($html, 'Your vault') === false);

finish();
