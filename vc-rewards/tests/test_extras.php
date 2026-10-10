<?php
/**
 * Stage 3: dead-coupon reports, store corrections, accepted answers,
 * first-post bonus, awards, profile and follow bonuses, challenges,
 * referrals and cashback.
 */

require __DIR__ . '/bootstrap.php';
global $wpdb;

register_post_type('merchant', array('public' => true, 'label' => 'Merchants'));

// Posting limits are covered in test_rewards.php; lift them here.
update_option(VC_REWARDS_OPTION, array(
    'daily_post_limit' => array('newcomer' => 0, 'member' => 0, 'trusted' => 0, 'expert' => 0, 'moderator' => 0),
));

$brand = make_brand('Cloud Shop');

function editor_coupon($brand, $code, $expires = '') {
    $id = wp_insert_post(array('post_type' => 'wcd_coupon', 'post_status' => 'publish', 'post_title' => 'Cloud Shop: ' . $code, 'post_author' => 1));
    wp_set_object_terms($id, array($brand), 'wcd_brand');
    update_post_meta($id, '_wcd_code', $code);
    update_post_meta($id, '_wcd_discount', '10% off');
    update_post_meta($id, '_wcd_destination_url', 'https://cloudshop.example/');
    update_post_meta($id, '_wcd_expiration', $expires);
    return $id;
}

function pending_sum($user_id, $kind) {
    return sum_rows(ledger_rows($user_id, $kind), 'pending');
}

$reporter = make_user('reporter');
$e = array();
for ($i = 1; $i <= 8; $i++) {
    $e[$i] = make_user('xexpert' . $i, 'expert');
}
$mod = make_user('xmod', 'moderator');

/* ------------------------------------------------------------------------- */
section('Dead-coupon reports');

$coupon = editor_coupon($brand, 'SAVE10');
check('find a coupon by store and code', vc_rewards_find_coupon($brand, 'save-10') === $coupon);

$r = vc_rewards_report_coupon($reporter, $coupon, 'Says invalid at checkout');
$rc = vc_rewards_get_contribution($r['contribution_id']);
check('report on an editor coupon opens for voting', $r['ok'] && $rc->contrib_type === 'report' && $rc->state === 'voting', $r['message']);
check('a second report on the same coupon is refused', !vc_rewards_report_coupon($e[1], $coupon)['ok']);
check('reports show in the verify queue', in_array((int) $rc->id, array_map('intval', wp_list_pluck(vc_rewards_queue_items($e[1]), 'id')), true));

as_ip('198.51.100.77');
check('voting needs the code revealed first', !vc_rewards_cast_vote($rc->id, $e[1], 'confirmed')['ok']);
$payload = vc_rewards_reveal_payload($rc);
check('revealing a report shows the coupon code', $payload['code'] === 'SAVE10');

vote($rc, $e[1], 'confirmed');
vote($rc, $e[2], 'confirmed');
check('two experts confirm: report accepted', fresh($rc)->state === 'accepted');
check('coupon gets yesterday as its expiry', get_post_meta($coupon, '_wcd_expiration', true) === gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' -1 day')));
check('reporter earns a flat 150, pending', pending_sum($reporter, 'contribution') === 150);
check('accurate voters are paid', pending_sum($e[1], 'vote') > 0);

vote($rc, $e[3], 'still_works');
vote($rc, $e[4], 'still_works');
vote($rc, $e[5], 'still_works');
vote($rc, $e[6], 'still_works');
check('voted back to working while pending: withdrawn', fresh($rc)->state === 'withdrawn');
check('expiry restored', get_post_meta($coupon, '_wcd_expiration', true) === '');
check('reporter reward voided', pending_sum($reporter, 'contribution') === 0);

$coupon2 = editor_coupon($brand, 'WORKS20');
$r2 = vc_rewards_report_coupon($reporter, $coupon2);
$rc2 = vc_rewards_get_contribution($r2['contribution_id']);
vote($rc2, $e[7], 'still_works');
vote($rc2, $e[8], 'still_works');
check('report of a working code is rejected', fresh($rc2)->state === 'rejected' && fresh($rc2)->reject_reason === 'still_works');
check('an honest wrong report costs nothing', vc_rewards_balance($reporter) === 0 && vc_rewards_reputation($reporter) >= 0);
check('same member cannot re-report within a month', !vc_rewards_report_coupon($reporter, $coupon2)['ok']);

$old = editor_coupon($brand, 'OLD5', '2020-01-01');
check('already expired coupons cannot be reported', !vc_rewards_report_coupon($reporter, $old)['ok']);
$member_coupon = submit($e[1], $brand, 'FRESH15');
check('coupons still being verified are voted on, not reported', !vc_rewards_report_coupon($reporter, $member_coupon->object_id)['ok']);

/* ------------------------------------------------------------------------- */
section('Store fact corrections');

$merchant = wp_insert_post(array('post_type' => 'merchant', 'post_status' => 'publish', 'post_title' => 'Cloud Shop'));
update_post_meta($merchant, 'free_shipping_info', 'Free shipping over $75');
$fixer = make_user('fixer');

check('a source link is required', !vc_rewards_suggest_correction($fixer, $merchant, 'free_shipping_info', 'Free shipping over $50', '')['ok']);
check('the same value is refused', !vc_rewards_suggest_correction($fixer, $merchant, 'free_shipping_info', 'Free shipping over $75', 'https://cloudshop.example/shipping')['ok']);
check('unknown fields are refused', !vc_rewards_suggest_correction($fixer, $merchant, 'post_title', 'Hacked', 'https://x.example/')['ok']);
$fc = vc_rewards_suggest_correction($fixer, $merchant, 'free_shipping_info', 'Free shipping over $50', 'https://cloudshop.example/shipping', 'Changed last week');
$cc = vc_rewards_get_contribution($fc['contribution_id']);
check('correction waits for a moderator', $fc['ok'] && $cc->state === 'voting' && $cc->contrib_type === 'correction');
check('corrections are not in the members\' verify queue', !in_array((int) $cc->id, array_map('intval', wp_list_pluck(vc_rewards_queue_items($e[1]), 'id')), true));
check('members cannot vote on corrections', !vc_rewards_cast_vote($cc->id, $e[1], 'works')['ok']);
check('a second correction to the same field waits its turn', !vc_rewards_suggest_correction($e[2], $merchant, 'free_shipping_info', 'Free shipping over $40', 'https://x.example/')['ok']);

check('approving applies it', vc_rewards_approve_correction($cc->id) && get_post_meta($merchant, 'free_shipping_info', true) === 'Free shipping over $50');
check('with the source and today\'s date', get_post_meta($merchant, 'fact_source_url', true) === 'https://cloudshop.example/shipping'
    && get_post_meta($merchant, 'fact_last_verified', true) === current_time('Y-m-d'));
check('member earns 250, pending', pending_sum($fixer, 'contribution') === 250);
check('approving twice does nothing', !vc_rewards_approve_correction($cc->id));

$fc2 = vc_rewards_suggest_correction($fixer, $merchant, 'restricted_states', ' Utah | Vermont ', 'https://cloudshop.example/shipping');
vc_rewards_reject($fc2['contribution_id'], 'declined');
check('declined correction costs nothing', vc_rewards_balance($fixer) === 0 && get_post_meta($merchant, 'restricted_states', true) === '');
check('state lists are tidied', vc_rewards_get_item(vc_rewards_get_contribution($fc2['contribution_id'])->object_id)->new_value === 'Utah|Vermont');

wp_set_current_user($mod);
ob_start();
vc_rewards_queue_page();
$html = ob_get_clean();
check('queue page lists corrections and the award form', strpos($html, 'Store fact corrections') !== false && strpos($html, 'Award points') !== false);

/* ------------------------------------------------------------------------- */
section('First to post a deal');

$finder = make_user('finder');
$second = make_user('secondfinder');
$key = vc_rewards_link_key('https://www.Shop.example/sale/?utm_source=x');
check('link key ignores www, case, slash and tracking', $key === vc_rewards_link_key('https://shop.example/SALE'));
$d1 = vc_rewards_create_contribution('wpforo_topic', 9001, 'deal', $finder, array('link_key' => $key));
$d2 = vc_rewards_create_contribution('wpforo_topic', 9002, 'deal', $second, array('link_key' => $key));
vc_rewards_accept($d1);
vc_rewards_accept($d2);
$notes = wp_list_pluck(ledger_rows($finder, 'contribution'), 'note');
check('first verified deal gets the bonus', in_array('First to post this deal', $notes, true) && pending_sum($finder, 'contribution') === 600);
check('the repost does not', pending_sum($second, 'contribution') === 400);

/* ------------------------------------------------------------------------- */
section('Moderator awards');

$photo = make_user('photographer');
check('proof photo award', vc_rewards_award($mod, 'photographer', 'proof', 0, 'receipt')['ok'] && pending_sum($photo, 'award') === 150);
check('custom amount over the limit is refused', !vc_rewards_award($mod, $photo, 'custom', 5000)['ok']);
check('custom amount', vc_rewards_award($mod, 'photographer@example.com', 'custom', 300)['ok'] && pending_sum($photo, 'award') === 450);
check('no awarding yourself', !vc_rewards_award($mod, $mod, 'proof')['ok']);
check('unknown member', !vc_rewards_award($mod, 'nobody-here', 'proof')['ok']);

/* ------------------------------------------------------------------------- */
section('Profile and follow bonuses');

$fan = make_user('fan');
update_user_meta($fan, 'description', 'Short');
check('short bio earns nothing', !vc_rewards_maybe_profile_bonus($fan));
wp_update_user(array('ID' => $fan, 'description' => 'Vaping for ten years, mostly pod systems and salts.'));
check('completed profile bonus once', sum_rows(ledger_rows($fan, 'onetime')) === 250 && !vc_rewards_maybe_profile_bonus($fan));

update_option(VC_REWARDS_OPTION, array_merge(get_option(VC_REWARDS_OPTION), array(
    'follow_channels' => "YouTube | https://youtube.com/@vapingcheap\nbroken line\nX | https://x.com/vapingcheap",
)));
$channels = vc_rewards_follow_channels();
check('channels parsed, bad lines skipped', count($channels) === 2);
$yt = array_keys($channels)[0];
check('following returns the channel link', vc_rewards_follow($fan, $yt) === 'https://youtube.com/@vapingcheap');
vc_rewards_follow($fan, $yt);
check('follow bonus paid once per channel', sum_rows(ledger_rows($fan, 'onetime')) === 500);
check('unknown channel', vc_rewards_follow($fan, 'follow_nope') === null);
check('follow points are not redeemable on their own', vc_rewards_redeemable($fan) === 0 && vc_rewards_available($fan) === 500);

/* ------------------------------------------------------------------------- */
section('Challenges');

$today = current_time('Y-m-d');
check('bad challenge refused', is_string(vc_rewards_create_challenge(array('title' => '', 'metric' => 'votes', 'target' => 1, 'bonus' => 1, 'starts' => $today, 'ends' => $today))));
$ch = vc_rewards_create_challenge(array(
    'title' => 'Verify 2 Cloud Shop coupons', 'metric' => 'votes', 'contrib_type' => 'coupon', 'brand_id' => $brand,
    'target' => 2, 'bonus' => 1500, 'starts' => $today, 'ends' => gmdate('Y-m-d', strtotime($today . ' +6 days')),
));
check('challenge created', is_int($ch) && $ch > 0);
$challenger = make_user('challenger', 'expert');
$poster = make_user('chposter');
$other_brand = make_brand('Other Shop');
$c1 = submit($poster, $brand, 'CH1');
vote($c1, $challenger, 'works');
vote($c1, $e[1], 'works');
check('one accurate vote: 1 of 2', vc_rewards_challenge_progress(vc_rewards_get_challenge($ch), $challenger) === 1 && !vc_rewards_challenge_done($ch, $challenger));
$c_other = submit($poster, $other_brand, 'CH2');
vote($c_other, $challenger, 'works');
vote($c_other, $e[2], 'works');
check('other stores do not count', vc_rewards_challenge_progress(vc_rewards_get_challenge($ch), $challenger) === 1);
$c3 = submit($poster, $brand, 'CH3');
vote($c3, $challenger, 'invalid');
vote($c3, $e[3], 'works');
check('wrong votes do not count', vc_rewards_challenge_progress(vc_rewards_get_challenge($ch), $challenger) === 1);
// A different author, so the repeat-vote discount doesn't apply.
$poster2 = make_user('chposter2');
$c4 = submit($poster2, $brand, 'CH4');
vote($c4, $challenger, 'works');
vote($c4, $e[4], 'works');
check('goal reached: bonus paid', vc_rewards_challenge_done($ch, $challenger) && pending_sum($challenger, 'challenge') === 1500);
vc_rewards_check_challenges($challenger);
check('and only once', pending_sum($challenger, 'challenge') === 1500);

$acc = vc_rewards_create_challenge(array('title' => 'Get 2 coupons accepted', 'metric' => 'accepted', 'contrib_type' => 'coupon', 'target' => 2, 'bonus' => 1000, 'starts' => $today, 'ends' => $today));
vc_rewards_check_challenges($poster);
check('accepted-posts challenge counts the author\'s posts', vc_rewards_challenge_done($acc, $poster));
wp_set_current_user($poster);
check('challenges show on the account page', strpos(do_shortcode('[vc_rewards_account]'), 'Verify 2 Cloud Shop coupons') !== false);
wp_set_current_user($mod);
ob_start();
vc_rewards_challenges_page();
check('challenges admin page renders', strpos(ob_get_clean(), 'New challenge') !== false);

/* ------------------------------------------------------------------------- */
section('Referrals');

$inviter = make_user('inviter');
$code = vc_rewards_referral_code($inviter);
check('referral code round trip', vc_rewards_referrer_from_code($code) === $inviter);
check('tampered code rejected', vc_rewards_referrer_from_code(substr($code, 0, -1) . (substr($code, -1) === 'a' ? 'b' : 'a')) === 0);

as_ip('203.0.113.50');
update_user_meta($inviter, '_vc_signup_ip', vc_rewards_ip_hash());
as_ip('203.0.113.51');
$_COOKIE['vc_ref'] = $code;
$friend = wp_insert_user(array('user_login' => 'friend', 'user_pass' => 'pw', 'user_email' => 'friend@example.com'));
unset($_COOKIE['vc_ref']);
check('new member remembers who invited them', (int) get_user_meta($friend, '_vc_referred_by', true) === $inviter);
check('not paid while a newcomer', vc_rewards_referral_blocker($friend) === 'rank');

update_user_meta($friend, '_vc_age_verified', 1);
update_user_meta($friend, '_vc_email_confirmed', 1);
update_user_meta($friend, '_vc_hold_approved', 99);
update_user_meta($friend, '_vc_rankups_paid', array('member', 'trusted', 'expert'));
$wpdb->update($wpdb->users, array('user_registered' => gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), array('ID' => $friend));
clean_user_cache($friend);
update_user_meta($friend, '_vc_votes_matched', 5);
update_user_meta($friend, '_vc_votes_resolved', 5);
check('Member but nothing accepted yet: not paid', vc_rewards_referral_blocker($friend) === 'contribution');

$fcoupon = submit($friend, $brand, 'FRIEND1');
vote($fcoupon, $e[5], 'works');
vote($fcoupon, $e[6], 'works');
check('first accepted post pays the inviter', pending_sum($inviter, 'referral') === 2500);
vc_rewards_maybe_pay_referral($friend);
check('only once', pending_sum($inviter, 'referral') === 2500);

as_ip('203.0.113.50');
$_COOKIE['vc_ref'] = $code;
$sock = wp_insert_user(array('user_login' => 'sockpuppet', 'user_pass' => 'pw', 'user_email' => 'sock@example.com'));
unset($_COOKIE['vc_ref']);
update_user_meta($sock, '_vc_age_verified', 1);
update_user_meta($sock, '_vc_email_confirmed', 1);
update_user_meta($sock, '_vc_hold_approved', 99);
$wpdb->update($wpdb->users, array('user_registered' => gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS)), array('ID' => $sock));
clean_user_cache($sock);
update_user_meta($sock, '_vc_votes_matched', 5);
update_user_meta($sock, '_vc_votes_resolved', 5);
vc_rewards_ledger_add($sock, 10, 'purchase', array('status' => 'settled'));
check('same signup address as the inviter never pays', !vc_rewards_maybe_pay_referral($sock) && pending_sum($inviter, 'referral') === 2500);

wp_set_current_user($inviter);
check('referral link on the account page', strpos(do_shortcode('[vc_rewards_account]'), 'ref=' . $code) !== false);

/* ------------------------------------------------------------------------- */
section('Cashback');

$buyer = make_user('buyer');
$subid = vc_rewards_subid($buyer);
check('sub-ID maps back to the member', vc_rewards_user_from_subid(strtoupper($subid)) === $buyer);
check('links untagged until a parameter is set', vc_rewards_tag_link('https://shop.example/', $buyer) === 'https://shop.example/');
update_option(VC_REWARDS_OPTION, array_merge(get_option(VC_REWARDS_OPTION), array('subid_param' => 'u1')));
check('links carry the sub-ID', vc_rewards_tag_link('https://shop.example/?a=1', $buyer) === 'https://shop.example/?a=1&u1=' . $subid);

check('pending sale pays nothing', vc_rewards_record_purchase(array('txn_id' => 'T1', 'subid' => $subid, 'merchant' => 'Cloud Shop', 'order_value' => '$50.00', 'status' => 'pending'))['result'] === 'added'
    && vc_rewards_balance($buyer) === 0);
check('buyer cannot redeem visit points yet', !vc_rewards_has_accepted_contribution($buyer));
check('confirmed: 3% of $50 = 3,750 points', vc_rewards_record_purchase(array('txn_id' => 'T1', 'subid' => $subid, 'order_value' => '50', 'status' => 'approved'))['result'] === 'paid'
    && vc_rewards_balance($buyer) === 3750);
check('a confirmed purchase counts as contributing', vc_rewards_has_accepted_contribution($buyer));
check('re-importing changes nothing', vc_rewards_record_purchase(array('txn_id' => 'T1', 'subid' => $subid, 'order_value' => '50', 'status' => 'confirmed'))['result'] === 'unchanged'
    && vc_rewards_balance($buyer) === 3750);
check('reversal takes it back', vc_rewards_record_purchase(array('txn_id' => 'T1', 'subid' => $subid, 'order_value' => '50', 'status' => 'declined'))['result'] === 'reversed'
    && vc_rewards_balance($buyer) === 0);
check('unknown sub-ID skipped', vc_rewards_record_purchase(array('txn_id' => 'T9', 'subid' => 'vcnobody', 'order_value' => '5', 'status' => 'confirmed'))['result'] === 'skipped');

$csv = tempnam(sys_get_temp_dir(), 'vc');
file_put_contents($csv, "txn_id,subid,merchant,order_value,status\nT2,$subid,Cloud Shop,20,confirmed\nT3,$subid,Cloud Shop,10,pending\nT4,bogus,X,5,confirmed\n");
$counts = vc_rewards_import_purchases($csv);
unlink($csv);
check('CSV import', $counts['paid'] === 1 && $counts['added'] === 1 && $counts['skipped'] === 1 && vc_rewards_balance($buyer) === 1500);

wp_set_current_user(1);
ob_start();
vc_rewards_purchases_page();
check('purchases page renders', strpos(ob_get_clean(), 'Import a network report') !== false);

/* ------------------------------------------------------------------------- */
section('Awin links');

$expected = 'https://www.awin1.com/cread.php?awinmid=67004&awinaffid=1961155&clickref6=rewd&ued=https%3A%2F%2Fwww.ecigmafia.com%2F';
check('plain store link becomes the same Awin link as the site\'s own', vc_rewards_awin_wrap('https://www.ecigmafia.com/') === $expected, vc_rewards_awin_wrap('https://www.ecigmafia.com/'));
check('deep links and subdomains work', strpos(vc_rewards_awin_wrap('https://shop.sourcemore.com/kits?x=1'), 'awinmid=90119') !== false
    && strpos(vc_rewards_awin_wrap('https://shop.sourcemore.com/kits?x=1'), 'ued=https%3A%2F%2Fshop.sourcemore.com%2Fkits%3Fx%3D1') !== false);
check('stores not on Awin are left alone', vc_rewards_awin_wrap('https://notonawin.example/') === 'https://notonawin.example/');
check('Awin links are not wrapped twice', vc_rewards_awin_wrap($expected) === $expected);
$tagged = vc_rewards_tag_link($expected, $buyer);
check('member sub-ID goes in clickref, clickref6 kept', strpos($tagged, 'clickref=' . $subid) !== false && strpos($tagged, 'clickref6=rewd') !== false);

update_option(VC_REWARDS_OPTION, array_merge(get_option(VC_REWARDS_OPTION), array('awin_stores' => "ecigmafia.com = 67004\nhttps://www.extra-store.example/ = 555")));
check('settings list accepts full URLs', vc_rewards_awin_advertiser_for('https://extra-store.example/x') === 555);

$c_awin = editor_coupon($brand, 'AWIN1');
update_post_meta($c_awin, '_wcd_destination_url', 'https://www.ecigmafia.com/sale/');
wp_set_current_user(0);
check('guests get the Awin link on coupon buttons', strpos(get_post_meta($c_awin, '_wcd_destination_url', true), 'awinmid=67004') !== false
    && strpos(get_post_meta($c_awin, '_wcd_destination_url', true), 'clickref=') === false);
wp_set_current_user($buyer);
check('members get it with their sub-ID', strpos(get_post_meta($c_awin, '_wcd_destination_url', true), 'clickref=' . $subid) !== false);
$html = vc_rewards_affiliate_links_in_html('<p><a href="https://www.ecigmafia.com/">Ecig Mafia</a> and <a href="https://other.example/">other</a></p>');
check('post content links are rewritten', strpos($html, 'awinmid=67004') !== false && strpos($html, 'href="https://other.example/"') !== false);

/* ------------------------------------------------------------------------- */
section('Awin sync');

check('sync without a token explains what is missing', !empty(vc_rewards_awin_sync()['errors']));
define('VC_AWIN_TOKEN', 'test-token');
$awin_calls = array();
add_filter('pre_http_request', function ($pre, $args, $url) use (&$awin_calls, $subid) {
    if (strpos($url, 'api.awin.com') === false) {
        return $pre;
    }
    $awin_calls[] = array($url, $args['headers']['Authorization'] ?? '');
    if (strpos($url, '/programmes') !== false) {
        $body = array(array('id' => 777, 'name' => 'New Store', 'displayUrl' => 'https://www.newstore.example', 'validDomains' => array(array('domain' => 'www.newstore.example'), array('domain' => 'www.awin1.com'))));
    } elseif (count($awin_calls) === 2) {
        $body = array(
            array('id' => 501, 'advertiserId' => 67004, 'commissionStatus' => 'approved', 'saleAmount' => array('amount' => 40, 'currency' => 'USD'), 'clickRefs' => array('clickRef' => $subid, 'clickRef6' => 'rewd')),
            array('id' => 502, 'advertiserId' => 67004, 'commissionStatus' => 'pending', 'saleAmount' => array('amount' => 25, 'currency' => 'USD'), 'clickRefs' => array('clickRef' => $subid)),
            array('id' => 503, 'advertiserId' => 90119, 'commissionStatus' => 'approved', 'saleAmount' => array('amount' => 99, 'currency' => 'USD'), 'clickRefs' => array('clickRef6' => 'rewd')),
            array('id' => 504, 'advertiserId' => 1, 'commissionStatus' => 'approved', 'saleAmount' => array('amount' => 30, 'currency' => 'GBP'), 'clickRefs' => array('clickRef' => $subid)),
        );
    } else {
        $body = array();
    }
    return array('headers' => array(), 'body' => wp_json_encode($body), 'response' => array('code' => 200, 'message' => 'OK'), 'cookies' => array(), 'filename' => null);
}, 10, 3);

$before = vc_rewards_balance($buyer);
$counts = vc_rewards_awin_sync(90);
check('90 days is read in 31-day pieces with the token', count($awin_calls) === 3 && $awin_calls[0][1] === 'Bearer test-token'
    && strpos($awin_calls[0][0], 'publishers/1961155/transactions/') !== false);
check('approved member sale pays 3% of $40 = 3,000', $counts['paid'] === 1 && vc_rewards_balance($buyer) - $before === 3000);
check('pending noted, non-member sale ignored, other currency held back', $counts['added'] === 1 && $counts['other_currency'] === 1);
$awin_calls = array();
vc_rewards_awin_sync(90);
check('running it again pays nothing more', vc_rewards_balance($buyer) - $before === 3000);
check('joined stores load from the API', vc_rewards_awin_refresh_stores() === 1 && vc_rewards_awin_advertiser_for('https://newstore.example/x') === 777);
check('Awin\'s own domains are never treated as stores', !isset(vc_rewards_awin_stores()['awin1.com']));
wp_set_current_user(1);
ob_start();
vc_rewards_purchases_page();
check('purchases page shows Awin status', strpos(ob_get_clean(), 'Sync now') !== false);

/* ------------------------------------------------------------------------- */
section('Accepted forum answers');

class VC_Extras_WPF {
    public $topic, $post;
    public function __construct() {
        $this->topic = new class {
            public $rows = array();
            public function get_topic($id) { return $this->rows[(int) $id] ?? array(); }
            public function get_url($id) { return 'http://localhost/community/t' . (int) $id . '/'; }
        };
        $this->post = new class {
            public $rows = array();
            public function get_post($id) { return $this->rows[(int) $id] ?? array(); }
            public function get_posts($args) {
                return array_values(array_filter($this->rows, function ($p) { return !empty($p['is_answer']); }));
            }
        };
    }
}
function WPF() {
    static $w = null;
    return $w ?: ($w = new VC_Extras_WPF());
}
function qa($topicid, $asker, $postid, $answerer, $answer = 1) {
    WPF()->topic->rows[$topicid] = array('topicid' => $topicid, 'userid' => $asker, 'title' => 'Question ' . $topicid, 'forumid' => 1);
    WPF()->post->rows[$postid]   = array('postid' => $postid, 'topicid' => $topicid, 'userid' => $answerer, 'is_answer' => $answer, 'is_first_post' => 0, 'body' => 'Try a 0.8 ohm coil.');
}

$asker    = make_user('asker');
$helper   = make_user('helper');
$newbie   = make_user('qnewbie', 'newcomer');
qa(1, $asker, 11, $helper);
qa(2, $newbie, 21, $helper);
qa(3, $helper, 31, $helper);
check('scan credits one qualifying answer', vc_rewards_scan_answers() === 1);
$ac = vc_rewards_contribution_for('wpforo_answer', 11);
check('accepted answer pays 200, pending', $ac && $ac->state === 'accepted' && pending_sum($helper, 'contribution') === 200);
check('newcomer askers and self-answers earn nothing', !vc_rewards_contribution_for('wpforo_answer', 21) && !vc_rewards_contribution_for('wpforo_answer', 31));
qa(4, $asker, 41, $helper);
vc_rewards_scan_answers();
check('same asker, same member, within 30 days: once', !vc_rewards_contribution_for('wpforo_answer', 41));
check('scanning again changes nothing', vc_rewards_scan_answers() === 0 && pending_sum($helper, 'contribution') === 200);
WPF()->post->rows[11]['is_answer'] = 0;
vc_rewards_scan_answers();
check('unmarked while pending: withdrawn', fresh($ac)->state === 'withdrawn' && pending_sum($helper, 'contribution') === 0);
check('answer info for the account page', strpos(vc_rewards_object_info($ac)['title'], 'Question 1') !== false);

/* ------------------------------------------------------------------------- */
section('Store experience reports');

update_option(VC_REWARDS_OPTION, array_merge(get_option(VC_REWARDS_OPTION), array('forum_types' => array('store_report' => '12'))));
check('store report forums map', vc_rewards_forum_type(12) === 'store_report');
check('store reports are helpful-type, 400 base', vc_rewards_type('store_report')['kind'] === 'helpful' && vc_rewards_type('store_report')['base_points'] === 400);

finish();
