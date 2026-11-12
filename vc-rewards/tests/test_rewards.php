<?php
/**
 * End-to-end behaviour of the rewards system on a real WordPress database.
 */

require __DIR__ . '/bootstrap.php';
global $wpdb;

$brand  = make_brand('Vape Street');
$brand2 = make_brand('Cloud Shop');

/* ------------------------------------------------------------------------- */
section('Activation');

vc_rewards_activate();
check('activation schedules settlement', (bool) wp_next_scheduled('vc_rewards_settle'));
check('AJAX endpoints are registered', has_action('wp_ajax_vc_rewards_vote') && has_action('wp_ajax_vc_rewards_reveal'));
check('logged-out visitors get no AJAX endpoint', !has_action('wp_ajax_nopriv_vc_rewards_vote'));

/* ------------------------------------------------------------------------- */
section('Age check');

check('20-year-old is refused', vc_rewards_dob_error(gmdate('Y-m-d', strtotime('-20 years'))) !== '');
check('21-year-old is accepted', vc_rewards_dob_error(gmdate('Y-m-d', strtotime('-21 years -1 day'))) === '');
check('missing date is refused', vc_rewards_dob_error('') !== '');

$fresh = wp_insert_user(array('user_login' => 'fresh', 'user_pass' => 'pw', 'user_email' => 'fresh@example.com'));
check('unverified member cannot submit', !vc_rewards_submit_coupon($fresh, array('brand' => $brand, 'code' => 'X', 'discount' => '5%'))['ok']);
check('setting a valid DOB verifies age', vc_rewards_set_dob($fresh, gmdate('Y-m-d', strtotime('-30 years'))));
check('age check pays the one-time bonus once', sum_rows(ledger_rows($fresh, 'onetime')) === 250);
vc_rewards_onetime_bonus($fresh, 'age', 'again');
check('one-time bonus is not paid twice', sum_rows(ledger_rows($fresh, 'onetime')) === 250);
check('DOB cannot be changed once verified', !vc_rewards_set_dob($fresh, gmdate('Y-m-d', strtotime('-50 years'))));

/* ------------------------------------------------------------------------- */
section('Daily visits and streaks');

$visitor = make_user('visitor', 'newcomer');
check('first visit pays', vc_rewards_daily_visit($visitor, '2026-01-01'));
check('second visit same day pays nothing', !vc_rewards_daily_visit($visitor, '2026-01-01'));
for ($d = 2; $d <= 7; $d++) {
    vc_rewards_daily_visit($visitor, sprintf('2026-01-%02d', $d));
}
check('7 visits = 7 x 25 login points', sum_rows(ledger_rows($visitor, 'login')) === 175);
check('7-day streak pays 250 once', sum_rows(ledger_rows($visitor, 'streak')) === 250);
vc_rewards_daily_visit($visitor, '2026-01-09');
check('a missed day resets the streak', (int) get_user_meta($visitor, '_vc_streak', true) === 1);
check('visit points add no reputation', vc_rewards_reputation($visitor) === 0);
check('visit points are not redeemable without an accepted post',
    vc_rewards_redeemable($visitor) === 0 && vc_rewards_available($visitor) === 450);

/* ------------------------------------------------------------------------- */
section('Ranks');

$newbie  = make_user('newbie', 'newcomer');
$m1      = make_user('member1');
$m2      = make_user('member2');
$m3      = make_user('member3');
$trusted = make_user('trusty', 'trusted');
$expert  = make_user('expert', 'expert');
$mod     = make_user('moddy', 'moderator');

check('fresh account is Newcomer', vc_rewards_rank($newbie) === 'newcomer');
check('Member', vc_rewards_rank($m1) === 'member');
check('Trusted', vc_rewards_rank($trusted) === 'trusted');
check('Expert', vc_rewards_rank($expert) === 'expert');
check('Moderator', vc_rewards_rank($mod) === 'moderator');
check('weights 0/1/2/3/5',
    vc_rewards_rank_weight('newcomer') == 0 && vc_rewards_rank_weight('member') == 1
    && vc_rewards_rank_weight('trusted') == 2 && vc_rewards_rank_weight('expert') == 3
    && vc_rewards_rank_weight('moderator') == 5);

/* ------------------------------------------------------------------------- */
section('Submitting coupons');

$author = make_user('author');
$c = submit($author, $brand, 'SAVE15');
check('submission creates a contribution in voting', is_object($c) && $c->state === 'voting', print_r($c, true));
check('coupon post is pending, not public', get_post_status($c->object_id) === 'pending');
check('meta uses the coupon plugin keys', get_post_meta($c->object_id, '_wcd_code', true) === 'SAVE15'
    && get_post_meta($c->object_id, '_wcd_type', true) === 'code');
$dup = submit($author, $brand, 'save-15');
check('same code in different case/punctuation is a duplicate', is_array($dup) && !$dup['ok']);
check('same code at another store is fine', is_object(submit($author, $brand2, 'SAVE15')));
$expired = submit($author, $brand, 'OLD10', array('expires' => '2020-01-01'));
check('expired code is refused', is_array($expired) && !$expired['ok']);
$nothing = vc_rewards_submit_coupon($author, array('brand' => $brand, 'code' => '', 'url' => '', 'discount' => 'x'));
check('needs a code or a link', !$nothing['ok']);
submit($author, $brand, 'THIRD');
$capped = submit($author, $brand, 'FOURTH');
check('Members are capped at 3 posts a day', is_array($capped) && !$capped['ok']);

/* ------------------------------------------------------------------------- */
section('Voting rules');

$r = vc_rewards_cast_vote($c->id, $m1, 'works');
check('cannot vote before revealing', !$r['ok']);
vc_rewards_record_reveal($c->id, $author);
check('cannot vote on own post', !vc_rewards_cast_vote($c->id, $author, 'works')['ok']);
check('unknown verdict refused', !vote($c, $m1, 'helpful')['ok']);

$r = vote($c, $m1, 'works');
check('member vote counts 1', $r['ok'] && (float) $r['weight'] === 1.0, print_r($r, true));
check('cannot vote twice', !vote($c, $m1, 'works')['ok']);
check('score 1 of 4: still voting', fresh($c)->state === 'voting');
$r = vote($c, $newbie, 'works');
check('newcomer vote is recorded at weight 0', $r['ok'] && (float) $r['weight'] === 0.0);

$r = vote($c, $expert, 'works');
$c = fresh($c);
check('expert (3) + member (1) = 4 from 2 voters: accepted', $c->state === 'accepted', $c->state . ' ' . $c->score);
check('accepted coupon is published', get_post_status($c->object_id) === 'publish');

$author_rows = ledger_rows($author, 'contribution');
check('author reward = 500 base + 50 x 4 weight = 700, pending',
    count($author_rows) === 1 && (int) $author_rows[0]->points === 700 && $author_rows[0]->status === 'pending',
    print_r($author_rows, true));
check('author +10 reputation', vc_rewards_reputation($author) === 10);
check('expert voter earns 150 pending', sum_rows(ledger_rows($expert, 'vote')) === 150);
check('member voter earns 50 pending', sum_rows(ledger_rows($m1, 'vote')) === 50);
check('newcomer earns nothing but is graded', sum_rows(ledger_rows($newbie, 'vote')) === 0
    && (int) get_user_meta($newbie, '_vc_votes_matched', true) === 1);
check('pending does not count toward balance', vc_rewards_balance($author) === 0 && vc_rewards_pending($author) === 700);

/* ------------------------------------------------------------------------- */
section('Settling');

settle_all();
check('after the holding period the reward settles', vc_rewards_balance($author) === 700 && vc_rewards_pending($author) === 0);

/* ------------------------------------------------------------------------- */
section('Anti-collusion');

$friend = make_user('friend');
$author2 = make_user('author2');
as_ip('192.0.2.200');
$c2 = submit($author2, $brand2, 'PAIR1', array('keep_ip' => true));
$r = vote($c2, $friend, 'works', '192.0.2.200'); // author's IP
$v = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . vc_rewards_table('votes') . ' WHERE contribution_id = %d AND voter_id = %d', $c2->id, $friend));
check('vote from the author\'s IP counts 0 and is flagged', (float) $v->weight === 0.0 && strpos($v->flags, 'same_ip') !== false);

$c3 = submit($trusted, $brand, 'REPEAT1');
$c4 = submit($trusted, $brand, 'REPEAT2');
vote($c3, $m2, 'works');
$r = vote($c4, $m2, 'works');
check('second vote on the same author halves', (float) $r['weight'] === 0.5, print_r($r, true));

// Two members voting for each other repeatedly.
$a = make_user('ringa');
$b = make_user('ringb');
update_user_meta($a, '_vc_hold_approved', 99);
$ring = array();
for ($i = 0; $i < 3; $i++) {
    $ca = vc_rewards_get_contribution(vc_rewards_create_contribution('wcd_coupon', 9000 + $i, 'coupon', $a));
    $cb = vc_rewards_get_contribution(vc_rewards_create_contribution('wcd_coupon', 9100 + $i, 'coupon', $b));
    vote($ca, $b, 'works');
    vote($cb, $a, 'works');
}
$ca = vc_rewards_get_contribution(vc_rewards_create_contribution('wcd_coupon', 9200, 'coupon', $a));
vote($ca, $b, 'works');
$v = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . vc_rewards_table('votes') . ' WHERE contribution_id = %d AND voter_id = %d', $ca->id, $b));
check('reciprocal pair: 4th vote counts 0 and is flagged', (float) $v->weight === 0.0 && strpos($v->flags, 'reciprocal') !== false, $v->flags);
check('flagged votes show in the admin list', count(vc_rewards_flagged_votes()) >= 2);

/* ------------------------------------------------------------------------- */
section('Rejection and penalties');

$bad_author = make_user('badauthor');
$bad = submit($bad_author, $brand2, 'FAKE99');
vote($bad, $m3, 'invalid');
vote($bad, $expert, 'invalid');
$bad = fresh($bad);
check('voted "doesn\'t work" by 4 weight from 2 voters: rejected', $bad->state === 'rejected' && $bad->reject_reason === 'invalid', $bad->state);
check('rejected coupon goes to draft', get_post_status($bad->object_id) === 'draft');
check('author penalised 1x base = -500 points', vc_rewards_balance($bad_author) === -500);
check('author -10 reputation', vc_rewards_reputation($bad_author) === -10);
check('negative reputation sends posts back to review', vc_rewards_under_review($bad_author));
check('negative reputation drops rank to Newcomer', vc_rewards_rank($bad_author) === 'newcomer');

$exp_author = make_user('expauthor');
$old = submit($exp_author, $brand2, 'GONE5');
vote($old, $m3, 'expired');
vote($old, $expert, 'expired');
$old = fresh($old);
check('mostly "expired" votes: rejected as expired', $old->state === 'rejected' && $old->reject_reason === 'expired', print_r($old, true) . print_r($wpdb->get_results('SELECT * FROM ' . vc_rewards_table('votes') . ' WHERE contribution_id = ' . (int) $old->id), true));
check('expired code is not penalised', vc_rewards_balance($exp_author) === 0 && vc_rewards_reputation($exp_author) === 0);

/* ------------------------------------------------------------------------- */
section('Code dies inside the holding period');

$w_author = make_user('wauthor');
$w = submit($w_author, $brand2, 'DIESOON');
vote($w, $expert, 'works');
vote($w, $m3, 'works');
check('accepted', fresh($w)->state === 'accepted');
$late1 = make_user('late1', 'expert');
$late2 = make_user('late2', 'expert');
$late3 = make_user('late3', 'trusted');
vote($w, $late1, 'invalid');
vote($w, $late2, 'invalid');
vote($w, $late3, 'invalid');
$w = fresh($w);
check('voted down past -threshold while pending: withdrawn', $w->state === 'withdrawn', $w->state . ' ' . $w->score);
check('pending author reward clawed back', sum_rows(ledger_rows($w_author, 'contribution'), 'void') > 0
    && vc_rewards_pending($w_author) === 0);
check('no penalty for a code that died early', vc_rewards_balance($w_author) === 0);
check('coupon marked expired', get_post_meta($w->object_id, '_wcd_expiration', true) < current_time('Y-m-d'));

/* ------------------------------------------------------------------------- */
section('New-account review');

$nu = make_user('nu');
update_user_meta($nu, '_vc_hold_approved', 0);
$h = submit($nu, $brand, 'HELD1');
check('first post from a new account is held', $h->state === 'held');
check('held posts are not in the verify queue', !in_array($h->id, wp_list_pluck(vc_rewards_queue_items($m1), 'id')));
vc_rewards_release($h->id);
check('release sends it to voting', fresh($h)->state === 'voting');
check('release counts toward the 3', (int) get_user_meta($nu, '_vc_hold_approved', true) === 1);

$h2 = submit($nu, $brand, 'HELD2');
vc_rewards_reject($h2->id, 'spam');
check('spam during the hold resets the count', (int) get_user_meta($nu, '_vc_hold_approved', true) === 0);
check('spam penalty is 2x base', vc_rewards_balance($nu) === -1000);
// Spam already dropped them to Newcomer (2 posts a day), so add the third directly.
$h3 = vc_rewards_get_contribution(vc_rewards_create_contribution('wcd_coupon', 9700, 'coupon', $nu));
vc_rewards_reject($h3->id, 'spam');
check('second spam rejection pauses the account', vc_rewards_is_banned($nu));
check('paused account cannot post', !vc_rewards_submit_coupon($nu, array('brand' => $brand, 'code' => 'Z', 'discount' => 'z'))['ok']);

/* ------------------------------------------------------------------------- */
section('Balance floor');

$spammer = make_user('spammer');
for ($i = 0; $i < 8; $i++) {
    $sc = vc_rewards_get_contribution(vc_rewards_create_contribution('wcd_coupon', 9500 + $i, 'coupon', $spammer));
    vc_rewards_reject($sc->id, 'fake');
}
check('penalties stop at -5,000', vc_rewards_balance($spammer) === -5000, (string) vc_rewards_balance($spammer));

/* ------------------------------------------------------------------------- */
section('Moderators');

$early = make_user('early');
$e = submit($early, $brand, 'MODOK');
vote($e, $mod, 'works');
check('early days: a moderator alone can accept', fresh($e)->state === 'accepted');

update_option(VC_REWARDS_OPTION, array('moderator_can_accept_alone' => 0));
$e2 = submit($early, $brand, 'MODOK2');
vote($e2, $mod, 'works');
check('with that off, one moderator vote is not enough', fresh($e2)->state === 'voting');
delete_option(VC_REWARDS_OPTION);

check('appointing a moderator pays no rank-up bonus', sum_rows(ledger_rows($mod, 'rankup')) === 0);

$x = submit($early, $brand2, 'MULTI');
vc_rewards_set_multiplier($x->id, 1.5);
vc_rewards_moderator_accept($x->id);
$rows = ledger_rows($early, 'contribution');
check('multiplier applies: (500 + 0) x 1.5 = 750', (int) end($rows)->points === 750);

/* ------------------------------------------------------------------------- */
section('Appeals');

$ap = make_user('appealer');
$flag1 = make_user('flagger1', 'expert');
$flag2 = make_user('flagger2', 'member');
$apc = submit($ap, $brand2, 'GOODCODE');
vote($apc, $flag1, 'invalid');
vote($apc, $flag2, 'invalid');
check('wrongly rejected', fresh($apc)->state === 'rejected');
check('author lost 500', vc_rewards_balance($ap) === -500);
check('appeal allowed once', vc_rewards_request_appeal($apc->id, $ap) && !vc_rewards_request_appeal($apc->id, $ap));
check('others cannot appeal it', !vc_rewards_request_appeal($apc->id, $flag1));
vc_rewards_overturn($apc->id);
check('overturned: accepted', fresh($apc)->state === 'accepted');
check('penalty refunded (+500 settled) and reward pending', vc_rewards_balance($ap) === 0 && vc_rewards_pending($ap) > 0);
check('reputation restored and accepted bonus added', vc_rewards_reputation($ap) === 10, (string) vc_rewards_reputation($ap));
check('flaggers lose 250', sum_rows(ledger_rows($flag1, 'penalty')) === -250 && sum_rows(ledger_rows($flag2, 'penalty')) === -250);

/* ------------------------------------------------------------------------- */
section('Rank-up bonuses');

$climber = make_user('climber');
update_user_meta($climber, '_vc_rankups_paid', array());
vc_rewards_check_rankup($climber);
check('reaching Member pays 1,250 pending', sum_rows(ledger_rows($climber, 'rankup')) === 1250);
vc_rewards_add_reputation($climber, 120, 'test');
check('reaching Trusted pays 5,000 more', sum_rows(ledger_rows($climber, 'rankup')) === 6250);
vc_rewards_add_reputation($climber, -200, 'test');
check('dropped back', vc_rewards_rank($climber) === 'newcomer');
vc_rewards_add_reputation($climber, 200, 'test');
check('climbing back pays nothing again', sum_rows(ledger_rows($climber, 'rankup')) === 6250);

/* ------------------------------------------------------------------------- */
section('Daily earning cap');

$capper = make_user('capper');
vc_rewards_ledger_add($capper, 4900, 'contribution', array('settle_days' => 7));
$id = vc_rewards_ledger_add($capper, 500, 'contribution', array('settle_days' => 7));
check('earnings past 5,000 a day are cut to the cap', vc_rewards_pending($capper) === 5000);
check('nothing at all once capped', vc_rewards_ledger_add($capper, 50, 'vote') === 0);
check('rank-up bonuses ignore the cap', vc_rewards_ledger_add($capper, 1250, 'rankup', array('settle_days' => 14)) > 0);

/* ------------------------------------------------------------------------- */
section('Redemptions');

$rich = make_user('rich');
check('cannot redeem with nothing', !vc_rewards_request_redemption($rich, 25000, 'Partner store credit')['ok']);
vc_rewards_ledger_add($rich, 30000, 'login', array('status' => 'settled'));
check('visit points alone are not redeemable', !vc_rewards_request_redemption($rich, 25000, 'Partner store credit')['ok']);
$rc = submit($rich, $brand2, 'RICHCODE');
vc_rewards_moderator_accept($rc->id);
check('below the minimum is refused', !vc_rewards_request_redemption($rich, 1000, 'Partner store credit')['ok']);
check('unknown reward is refused', !vc_rewards_request_redemption($rich, 25000, 'Cash')['ok']);
$req = vc_rewards_request_redemption($rich, 25000, 'Partner store credit');
check('after an accepted post, the request goes through', $req['ok'], $req['message']);
check('points are reserved straight away', vc_rewards_available($rich) === 5000);
check('the same points cannot be requested twice', !vc_rewards_request_redemption($rich, 25000, 'Partner store credit')['ok']);
check('request waits for review', vc_rewards_get_redemption($req['id'])->status === 'requested');
$summary = vc_rewards_review_summary($rich);
check('reviewer sees that most points came from visits', $summary['visit_share'] > 0.5);
vc_rewards_decide_redemption($req['id'], false, $mod, 'Not this time');
check('rejecting returns the points', vc_rewards_available($rich) === 30000);
$req2 = vc_rewards_request_redemption($rich, 25000, 'Partner store credit');
vc_rewards_decide_redemption($req2['id'], true, $mod, 'Sent code ABC');
check('approving settles the deduction', vc_rewards_balance($rich) === 5000 && vc_rewards_get_redemption($req2['id'])->status === 'approved');
check('a decided request cannot be decided again', !vc_rewards_decide_redemption($req2['id'], false, $mod));

check('below-zero members cannot redeem', !vc_rewards_request_redemption($bad_author, 25000, 'Partner store credit')['ok']);

/* ------------------------------------------------------------------------- */
section('Helpful-type posts (reviews, guides)');

$writer = make_user('writer');
$rv = vc_rewards_get_contribution(vc_rewards_create_contribution('wpforo_topic', 501, 'review', $writer));
foreach (array($m1, $m2, $m3) as $u) {
    as_ip('198.51.100.' . $u);
    vc_rewards_cast_vote($rv->id, $u, 'not_helpful');
}
check('"not helpful" never rejects or penalises', fresh($rv)->state === 'voting' && vc_rewards_balance($writer) === 0);
check('helpful votes need no reveal', vc_rewards_cast_vote($rv->id, $expert, 'helpful')['ok']);

$spam_post = vc_rewards_get_contribution(vc_rewards_create_contribution('wpforo_topic', 502, 'guide', $writer));
as_ip('198.51.100.201');
vc_rewards_cast_vote($spam_post->id, $late1, 'spam');
as_ip('198.51.100.202');
vc_rewards_cast_vote($spam_post->id, $late2, 'spam');
check('spam votes net 6 >= 4: rejected as spam', fresh($spam_post)->state === 'rejected' && fresh($spam_post)->reject_reason === 'spam');
check('spam penalty 2 x 600 = -1,200', vc_rewards_balance($writer) === -1200);

$good = vc_rewards_get_contribution(vc_rewards_create_contribution('wpforo_topic', 503, 'review', $expert));
foreach (array($late1, $late2) as $u) {
    as_ip('198.51.100.' . (100 + $u));
    vc_rewards_cast_vote($good->id, $u, 'helpful');
}
check('helpful weight 6 from 2 voters is not enough (needs 3)', fresh($good)->state === 'voting');
as_ip('198.51.100.250');
vc_rewards_cast_vote($good->id, $m1, 'helpful');
check('third voter: accepted', fresh($good)->state === 'accepted');
$rows = ledger_rows($expert, 'contribution');
check('review reward = 750 + 50 x 7 = 1,100', (int) end($rows)->points === 1100);

/* ------------------------------------------------------------------------- */
section('Pages render');

wp_set_current_user($m1);
foreach (array('vc_submit_coupon', 'vc_verify_queue', 'vc_rewards_account', 'vc_rewards_leaderboard') as $sc) {
    $html = do_shortcode('[' . $sc . ']');
    check("[$sc] renders", is_string($html) && $html !== '' && strpos($html, 'Fatal') === false);
}
check('verify queue lists open coupons', strpos(do_shortcode('[vc_verify_queue]'), 'vc-rewards-card') !== false);
section('Front-end forms');

add_filter('vc_rewards_redirect_after_form', '__return_false');
$former = make_user('former');
wp_set_current_user($former);
$_POST = array(
    'vc_rewards_action' => 'submit_coupon',
    '_vc_nonce'   => wp_create_nonce('vc_rewards_submit_coupon'),
    'vc_brand'    => $brand,
    'vc_code'     => 'FORMCODE',
    'vc_discount' => '10% off',
);
as_ip('192.0.2.77');
vc_rewards_handle_forms();
$_POST = array();
$html = do_shortcode('[vc_submit_coupon]');
$again = do_shortcode('[vc_submit_coupon]');
check('form post creates exactly one submission', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . vc_rewards_table('contributions') . ' WHERE author_id = %d', $former)) === 1);
check('result message shows on the page', strpos($html, 'Thanks!') !== false);
check('rendering the page again does not resubmit', strpos($again, 'already posted') === false);
$_POST = array('vc_rewards_action' => 'submit_coupon', '_vc_nonce' => 'bad', 'vc_brand' => $brand, 'vc_code' => 'NONCE', 'vc_discount' => 'x');
vc_rewards_handle_forms();
$_POST = array();
check('bad nonce is ignored', (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . vc_rewards_table('contributions') . ' WHERE author_id = %d', $former)) === 1);

wp_set_current_user($mod);
foreach (array('vc_rewards_queue_page', 'vc_rewards_redemptions_page') as $page) {
    ob_start();
    $page();
    $html = ob_get_clean();
    check("$page renders", strpos($html, '<h1>') !== false);
}
wp_set_current_user(1);
ob_start();
vc_rewards_settings_page();
check('settings page renders', strpos(ob_get_clean(), 'Rewards settings') !== false);
check('points format as money', vc_rewards_format_points(1250) === '1,250 points (50¢)'
    && vc_rewards_format_points(12500) === '12,500 points ($5.00)');

finish();
