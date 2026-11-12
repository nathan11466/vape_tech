<?php
/**
 * wpForo integration, against a stand-in for wpForo's WPF() object.
 *
 * The stand-in keeps topics and posts in memory but fires the same hooks
 * with the same arguments as wpForo 3.2 does (Topics::add, Posts::set_status,
 * Topics::set_status, Topics::delete), so the plugin sees what it would see
 * on the live site.
 */

require __DIR__ . '/bootstrap.php';
global $wpdb;

class VC_Test_WPF_Topics {
    public $rows = array();
    public function get_topic($args, $protect = true) {
        $id = is_array($args) ? (int) $args['topicid'] : (int) $args;
        return isset($this->rows[$id]) ? $this->rows[$id] : array();
    }
    public function get_url($topic) {
        $id = is_array($topic) ? (int) $topic['topicid'] : (int) $topic;
        return 'http://localhost/community/topic-' . $id . '/';
    }
    public function set_status($topicid, $status) {
        $topic = $this->get_topic($topicid);
        if (!$topic || (int) $topic['status'] === (int) $status) {
            return false;
        }
        $this->rows[$topicid]['status'] = (int) $status;
        do_action($status ? 'wpforo_topic_unapprove' : 'wpforo_topic_approve', $topic);
        return true;
    }
    public function close($topicid) {
        $this->rows[$topicid]['closed'] = 1;
    }
    public function delete($topicid) {
        $topic = $this->get_topic($topicid);
        do_action('wpforo_before_delete_topic', $topic);
        unset($this->rows[$topicid]);
        do_action('wpforo_after_delete_topic', $topic);
    }
}

class VC_Test_WPF_Posts {
    public $rows = array();
    public function get_post($postid, $protect = true) {
        return isset($this->rows[$postid]) ? $this->rows[$postid] : array();
    }
    public function set_status($postid, $status) {
        $post = $this->get_post($postid);
        if (!$post) {
            return false;
        }
        if ($post['is_first_post']) {
            WPF()->topic->set_status($post['topicid'], $status);
        }
        if ((int) $post['status'] === (int) $status) {
            return false;
        }
        $this->rows[$postid]['status'] = (int) $status;
        do_action($status ? 'wpforo_post_unapprove' : 'wpforo_post_approve', $post);
        return true;
    }
}

class VC_Test_WPF_Forums {
    public function get_forum($id) {
        return array('forumid' => (int) $id, 'title' => 'Forum ' . (int) $id);
    }
}

class VC_Test_WPF_Notice {
    public $last = array();
    public function add($message, $type = 'success') {
        $this->last = array($message, $type);
    }
}

class VC_Test_WPF {
    public $topic, $post, $forum, $notice;
    public $next = 1;
    public function __construct() {
        $this->topic  = new VC_Test_WPF_Topics();
        $this->post   = new VC_Test_WPF_Posts();
        $this->forum  = new VC_Test_WPF_Forums();
        $this->notice = new VC_Test_WPF_Notice();
    }
}

function WPF() {
    static $wpf = null;
    if (!$wpf) {
        $wpf = new VC_Test_WPF();
    }
    return $wpf;
}

/** Topics::add, reduced to what the hooks see. Returns the topic ID or 0. */
function forum_topic($user_id, $forumid, $title, $body) {
    wp_set_current_user($user_id);
    as_ip('192.0.2.' . ($user_id % 250));
    WPF()->notice->last = array();
    $forum = WPF()->forum->get_forum($forumid);
    $args  = apply_filters('wpforo_add_topic_data_filter', array(
        'forumid' => $forumid, 'title' => $title, 'body' => $body, 'userid' => $user_id,
    ), $forum);
    if (empty($args)) {
        return 0;
    }
    $status  = !empty($args['status']) ? 1 : 0;
    $topicid = WPF()->next++;
    $postid  = WPF()->next++;
    WPF()->topic->rows[$topicid] = array(
        'topicid' => $topicid, 'forumid' => $forumid, 'first_postid' => $postid, 'userid' => $user_id,
        'title' => $title, 'status' => $status, 'closed' => 0,
    );
    WPF()->post->rows[$postid] = array(
        'postid' => $postid, 'topicid' => $topicid, 'forumid' => $forumid, 'userid' => $user_id,
        'body' => $body, 'status' => $status, 'is_first_post' => 1,
    );
    $args['topicid']      = $topicid;
    $args['first_postid'] = $postid;
    $args['status']       = $status;
    do_action('wpforo_after_add_topic', $args, $forum);
    return $topicid;
}

/** Posts::add for a reply. Returns the post ID or 0. */
function forum_reply($user_id, $topicid, $body) {
    wp_set_current_user($user_id);
    $topic = WPF()->topic->get_topic($topicid);
    $args  = apply_filters('wpforo_add_post_data_filter', array(
        'topicid' => $topicid, 'forumid' => $topic['forumid'], 'body' => $body, 'userid' => $user_id,
    ));
    if (empty($args)) {
        return 0;
    }
    $postid = WPF()->next++;
    WPF()->post->rows[$postid] = array(
        'postid' => $postid, 'topicid' => $topicid, 'forumid' => $topic['forumid'], 'userid' => $user_id,
        'body' => $body, 'status' => !empty($args['status']) ? 1 : 0, 'is_first_post' => 0,
    );
    return $postid;
}

function topic_row($topicid) {
    return WPF()->topic->get_topic($topicid);
}

function topic_contribution($topicid) {
    return vc_rewards_contribution_for('wpforo_topic', $topicid);
}

function words($n, $seed = 'pod') {
    $out = array();
    for ($i = 0; $i < $n; $i++) {
        $out[] = $seed . $i;
    }
    return implode(' ', $out);
}

// Deals in forums 3 and 4, reviews in 5, guides in 6.
// Posting limits are covered in test_rewards.php; lift them here.
update_option(VC_REWARDS_OPTION, array(
    'forum_types'      => array('deal' => '3, 4', 'review' => '5', 'guide' => '6'),
    'daily_post_limit' => array('newcomer' => 0, 'member' => 0, 'trusted' => 0, 'expert' => 0, 'moderator' => 0),
));

$author = make_user('poster');
$other  = make_user('copier');
$loser  = make_user('badposter');
$m1     = make_user('fv1');
$m2     = make_user('fv2');
$m3     = make_user('fv3');
$mod    = make_user('fmod', 'moderator');
// Experts (weight 3): two agree and a deal is decided. A fresh pair per
// deal keeps the repeat-vote discount out of the picture.
$e = array();
for ($i = 1; $i <= 6; $i++) {
    $e[$i] = make_user('fexpert' . $i, 'expert');
}

/* ------------------------------------------------------------------------- */
section('Forum mapping');

check('forum list "3, 4" maps both forums', vc_rewards_forum_type(3) === 'deal' && vc_rewards_forum_type(4) === 'deal');
check('review and guide forums map', vc_rewards_forum_type(5) === 'review' && vc_rewards_forum_type(6) === 'guide');
check('unmapped forum earns nothing', vc_rewards_forum_type(9) === '');

$chat = forum_topic($author, 9, 'Hello all', 'Just saying hi');
check('topic in an unmapped forum is not a contribution', $chat && !topic_contribution($chat));

$deal = forum_topic($author, 3, 'Disposables 30% off at Cloud Shop', 'Found this today https://cloudshop.example/sale works at checkout.');
$dc   = topic_contribution($deal);
check('deal topic becomes a deal contribution', $dc && $dc->contrib_type === 'deal' && $dc->state === 'voting');
check('deal topic stays published', (int) topic_row($deal)['status'] === 0);

/* ------------------------------------------------------------------------- */
section('Forum object info');

$info = vc_rewards_object_info($dc);
check('title and topic link come from wpForo', $info['title'] === 'Disposables 30% off at Cloud Shop'
    && $info['url'] === 'http://localhost/community/topic-' . $deal . '/');
check('forum name and outbound link', $info['brand'] === 'Forum 3' && $info['link'] === 'https://cloudshop.example/sale');
$payload = vc_rewards_reveal_payload($dc);
check('revealing a forum deal opens its store link', $payload['url'] === 'https://cloudshop.example/sale' && $payload['code'] === '');

/* ------------------------------------------------------------------------- */
section('Vote widget in the topic');

$first = WPF()->post->get_post(topic_row($deal)['first_postid']);
wp_set_current_user($m1);
$html = apply_filters('wpforo_content_after', '<p>body</p>', $first);
check('members see the vote card under the first post', strpos($html, 'vc-rewards-card') !== false || strpos($html, 'data-contribution') !== false, $html);
$reply = forum_reply($m1, $deal, 'Thanks, worked for me');
$html  = apply_filters('wpforo_content_after', '<p>reply</p>', WPF()->post->get_post($reply));
check('replies get no widget', $html === '<p>reply</p>');
wp_set_current_user(0);
$html = apply_filters('wpforo_content_after', '<p>body</p>', $first);
check('guests see a status line only', strpos($html, 'vc-rewards-badge') !== false && strpos($html, '<button') === false);

/* ------------------------------------------------------------------------- */
section('Votes drive the topic');

$bad = forum_topic($loser, 3, 'Fake deal', 'https://nope.example/ 90% off everything');
$bc  = topic_contribution($bad);

vote($bc, $e[1], 'invalid');
vote($bc, $e[2], 'invalid');
check('deal voted invalid is rejected', fresh($bc)->state === 'rejected' && fresh($bc)->reject_reason === 'invalid');
check('rejected deal is hidden in the forum', (int) topic_row($bad)['status'] === 1);
check('deal penalty 1 x 400', vc_rewards_balance($loser) === -400);
$again = forum_topic($loser, 3, 'Another one', 'https://nope2.example/');
check('below-zero reputation: next topic waits for a moderator', (int) topic_row($again)['status'] === 1 && topic_contribution($again)->state === 'held');

$deal2 = forum_topic($author, 4, 'Juice sale ended?', 'Promo https://juice.example/ 20% off');
$dc2   = topic_contribution($deal2);
vote($dc2, $e[3], 'expired');
vote($dc2, $e[4], 'expired');
check('expired deal is closed, not hidden', fresh($dc2)->reject_reason === 'expired'
    && (int) topic_row($deal2)['closed'] === 1 && (int) topic_row($deal2)['status'] === 0);
check('expired costs nothing', vc_rewards_balance($author) === 0);

$deal3 = forum_topic($author, 3, 'Coil pack deal', 'https://coils.example/ buy two get one');
$dc3   = topic_contribution($deal3);
vote($dc3, $e[5], 'works');
vote($dc3, $e[6], 'works');
check('accepted deal stays published', fresh($dc3)->state === 'accepted' && (int) topic_row($deal3)['status'] === 0);

/* ------------------------------------------------------------------------- */
section('Reviews and guides');

$short = forum_topic($author, 5, 'Quick review', words(40));
check('review under 150 words posts but earns nothing', $short && !topic_contribution($short));
check('member is told why', !empty(WPF()->notice->last) && strpos(WPF()->notice->last[0], '150') !== false);

$review = forum_topic($author, 5, 'Long-term review of the X pod', words(180));
$rc     = topic_contribution($review);
check('150+ word review becomes a review contribution', $rc && $rc->contrib_type === 'review' && $rc->state === 'voting');

$linked = forum_topic($author, 6, 'Coil building guide', words(200, 'coil') . ' buy wire at https://wire.example/');
$lc     = topic_contribution($linked);
check('guide with a store link waits for a moderator', $lc && $lc->state === 'held' && (int) topic_row($linked)['status'] === 1);

$many = forum_topic($author, 3, 'Deal roundup', 'https://a.example/ https://b.example/ https://c.example/ https://d.example/');
check('deal with more than 3 links is held', topic_contribution($many)->state === 'held');

$own = forum_topic($author, 3, 'Site link', 'See ' . home_url('/coupons/') . ' for more');
check('links to this site do not count', topic_contribution($own)->state === 'voting');

/* ------------------------------------------------------------------------- */
section('Copied posts');

$copy = forum_topic($other, 5, 'My review of the X pod!', words(180));
$cc   = topic_contribution($copy);
check('someone else\'s text reposted: rejected as copied', $cc && $cc->state === 'rejected' && $cc->reject_reason === 'copied');
check('copy penalty 2 x 750 = -1,500', vc_rewards_balance($other) === -1500);
check('copied topic hidden', (int) topic_row($copy)['status'] === 1);
check('original is untouched', fresh($rc)->state === 'voting');
$same = forum_topic($m3, 3, 'Same deal', 'Disposables 30% off https://cloudshop.example/sale');
check('two short deal posts alike are not copies', topic_contribution($same)->state === 'voting');

/* ------------------------------------------------------------------------- */
section('New accounts in the forum');

$newbie = make_user('forumnewbie');
update_user_meta($newbie, '_vc_hold_approved', 0);
$held = forum_topic($newbie, 3, 'First deal', 'https://newbie.example/ 10% off');
$hc   = topic_contribution($held);
check('new account\'s topic is held in wpForo', (int) topic_row($held)['status'] === 1);
check('and held in rewards', $hc->state === 'held');

$chat2 = forum_topic($newbie, 9, 'Hi', 'New here');
check('even off-topic posts wait for a moderator', (int) topic_row($chat2)['status'] === 1 && !topic_contribution($chat2));
$r2 = forum_reply($newbie, $deal3, 'Thanks!');
check('and so do replies', (int) WPF()->post->get_post($r2)['status'] === 1);

wp_set_current_user($mod);
WPF()->post->set_status(topic_row($held)['first_postid'], 0);
check('approving in wpForo releases it to voting', fresh($hc)->state === 'voting' && (int) topic_row($held)['status'] === 0);
check('counts once toward the hold', (int) get_user_meta($newbie, '_vc_hold_approved', true) === 1);
WPF()->post->set_status($r2, 0);
check('approving a reply counts too', (int) get_user_meta($newbie, '_vc_hold_approved', true) === 2);
WPF()->post->set_status(topic_row($chat2)['first_postid'], 0);
check('approving an off-topic post counts once', (int) get_user_meta($newbie, '_vc_hold_approved', true) === 3);
check('hold over after 3 approvals', !vc_rewards_under_review($newbie));

$held2 = forum_topic($newbie, 3, 'Another deal', 'https://n2.example/ promo');
vc_rewards_wpforo_approved(array()); // malformed hook data is ignored
$hc2 = topic_contribution($held2);
check('after the hold, topics publish straight away', $hc2->state === 'voting' && (int) topic_row($held2)['status'] === 0);

$queue_held = forum_topic($author, 6, 'Another guide with link', words(200, 'g') . ' https://shop.example/');
$qc = topic_contribution($queue_held);
vc_rewards_release($qc->id);
check('releasing from the Rewards queue publishes the topic', (int) topic_row($queue_held)['status'] === 0);

$veteran = wp_insert_user(array('user_login' => 'veteran', 'user_pass' => 'pw', 'user_email' => 'veteran@example.com'));
$wpdb->update($wpdb->users, array('user_registered' => '2019-05-01 00:00:00'), array('ID' => $veteran));
clean_user_cache($veteran);
check('members from before launch skip the new-account review', !vc_rewards_under_review($veteran));
$fresh_user = wp_insert_user(array('user_login' => 'freshuser', 'user_pass' => 'pw', 'user_email' => 'freshuser@example.com'));
check('accounts made after launch get it', vc_rewards_under_review($fresh_user));

/* ------------------------------------------------------------------------- */
section('Paused accounts');

$paused = make_user('pausedposter');
update_user_meta($paused, '_vc_banned', 1);
check('paused member cannot start a topic', forum_topic($paused, 3, 'Spam', 'https://spam.example/') === 0);
check('and is told why', WPF()->notice->last[1] === 'error');
check('or reply', forum_reply($paused, $deal3, 'spam') === 0);

/* ------------------------------------------------------------------------- */
section('Deleted topics');

$gone = forum_topic($author, 3, 'Deleted deal', 'https://gone.example/');
$gc   = topic_contribution($gone);
$before = vc_rewards_balance($author);
WPF()->topic->delete($gone);
check('deleting a topic cancels its contribution', fresh($gc)->state === 'rejected' && fresh($gc)->reject_reason === 'removed');
check('without a penalty', vc_rewards_balance($author) === $before);
check('info for a deleted topic is safe', vc_rewards_object_info(fresh($gc))['title'] !== '');

/* ------------------------------------------------------------------------- */
section('Settings screen');

wp_set_current_user(1);
$_POST = array(
    'vc_rewards_settings_nonce' => wp_create_nonce('vc_rewards_settings'),
    'vc' => array('forum_types' => array('deal' => '3, 4, abc', 'review' => '5', 'guide' => '')),
);
ob_start();
vc_rewards_settings_page();
$html = ob_get_clean();
$_POST = array();
check('forum IDs save cleaned', vc_rewards_setting('forum_types')['deal'] === '3,4' && vc_rewards_setting('forum_types')['guide'] === '');
check('settings page shows forum fields', strpos($html, 'vc[forum_types][review]') !== false);
check('cleared guide forum no longer maps', vc_rewards_forum_type(6) === '');

finish();
