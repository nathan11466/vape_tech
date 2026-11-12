<?php
/**
 * wpForo: deal finds, product reviews and guides posted in the forum.
 *
 * Which forums earn rewards is set on the Settings screen (forum IDs per
 * type). A topic posted in one of those forums becomes a contribution.
 * Replies never earn points.
 *
 * wpForo keeps posts in its own tables, so this file talks to it only
 * through its public hooks and WPF() methods (checked against wpForo 3.2):
 *
 *   wpforo_add_topic_data_filter / wpforo_add_post_data_filter
 *       hold posts from new or below-zero accounts (status 1 = awaiting
 *       moderation), and stop paused accounts posting at all
 *   wpforo_after_add_topic        create the contribution
 *   wpforo_post_approve / wpforo_topic_approve
 *       a moderator approving in wpForo's own screens counts as a release
 *   wpforo_after_delete_topic     cancel any pending reward
 *   wpforo_content_after          the vote widget under the first post
 *
 * Turn off wpForo's own reputation points and "likes" rewards in its
 * settings, so members do not see two competing point systems.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_wpforo_active() {
    return function_exists('WPF');
}

/** The contribution type a forum earns as, or ''. */
function vc_rewards_forum_type($forumid) {
    $map = vc_rewards_setting('forum_types');
    foreach ((array) $map as $type => $ids) {
        $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', (string) $ids)));
        if (in_array((int) $forumid, $ids, true) && vc_rewards_type($type)) {
            return $type;
        }
    }
    return '';
}

function vc_rewards_word_count($html) {
    $text = trim(wp_strip_all_tags((string) $html));
    return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
}

/** Outbound links in a post body, excluding this site. */
function vc_rewards_external_links($html) {
    preg_match_all('#https?://[^\s"\'<>\]\[]+#i', (string) $html, $m);
    $home  = wp_parse_url(home_url(), PHP_URL_HOST);
    $links = array();
    foreach (array_unique($m[0]) as $url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if ($host && $host !== $home) {
            $links[] = $url;
        }
    }
    return $links;
}

/**
 * Why a reward-eligible topic should wait for a moderator, or ''.
 *
 * Reviews and guides with a store link are where affiliate spam and
 * merchant shills turn up; anything with a pile of links is held too.
 */
function vc_rewards_forum_hold_reason($type, $body) {
    $links = count(vc_rewards_external_links($body));
    if ($links > (int) vc_rewards_setting('forum_max_links')) {
        return 'links';
    }
    if ($links > 0 && vc_rewards_type($type)['kind'] === 'helpful') {
        return 'store_link';
    }
    return '';
}

function vc_rewards_wpforo_notice($message, $type = 'success') {
    if (vc_rewards_wpforo_active() && isset(WPF()->notice) && method_exists(WPF()->notice, 'add')) {
        WPF()->notice->add($message, $type);
    }
}

/* -------------------------------------------------------------------------
 * Before a post is saved
 * ---------------------------------------------------------------------- */

/** Set while this plugin changes a status, so its own hooks ignore it. */
function vc_rewards_wpforo_syncing($set = null) {
    static $syncing = false;
    if ($set !== null) {
        $syncing = (bool) $set;
    }
    return $syncing;
}

add_filter('wpforo_add_topic_data_filter', function ($args, $forum = array()) {
    $user_id = get_current_user_id();
    if (!$user_id || empty($args)) {
        return $args;
    }
    if (vc_rewards_is_banned($user_id)) {
        vc_rewards_wpforo_notice(__('Your account is paused while a moderator reviews it.', 'vc-rewards'), 'error');
        return array();
    }
    if (vc_rewards_under_review($user_id)) {
        $args['status'] = 1;
    }
    $type = vc_rewards_forum_type(isset($args['forumid']) ? $args['forumid'] : 0);
    if ($type && !vc_rewards_is_moderator($user_id) && vc_rewards_forum_hold_reason($type, isset($args['body']) ? $args['body'] : '') !== '') {
        $args['status'] = 1;
    }
    return $args;
}, 20, 2);

add_filter('wpforo_add_post_data_filter', function ($post) {
    $user_id = get_current_user_id();
    if (!$user_id || empty($post)) {
        return $post;
    }
    if (vc_rewards_is_banned($user_id)) {
        vc_rewards_wpforo_notice(__('Your account is paused while a moderator reviews it.', 'vc-rewards'), 'error');
        return array();
    }
    if (vc_rewards_under_review($user_id)) {
        $post['status'] = 1;
    }
    return $post;
}, 20);

/* -------------------------------------------------------------------------
 * After a topic is saved
 * ---------------------------------------------------------------------- */

add_action('wpforo_after_add_topic', 'vc_rewards_wpforo_topic_added', 10, 2);

function vc_rewards_wpforo_topic_added($topic, $forum = array()) {
    $user_id = isset($topic['userid']) ? (int) $topic['userid'] : 0;
    $type    = vc_rewards_forum_type(isset($topic['forumid']) ? $topic['forumid'] : 0);
    if (!$user_id || !$type || empty($topic['topicid'])) {
        return 0;
    }
    if (vc_rewards_participation_error($user_id) !== '') {
        vc_rewards_wpforo_notice(__('Posted. Confirm your date of birth on your rewards page to start earning points for posts like this.', 'vc-rewards'));
        return 0;
    }

    $body     = isset($topic['body']) ? (string) $topic['body'] : '';
    $min      = vc_rewards_setting('forum_min_words');
    $min      = isset($min[$type]) ? (int) $min[$type] : 0;
    if ($min > 0 && vc_rewards_word_count($body) < $min) {
        /* translators: 1: contribution type, 2: minimum words */
        vc_rewards_wpforo_notice(sprintf(__('Posted. A %1$s needs at least %2$d words to earn points.', 'vc-rewards'), strtolower(vc_rewards_type($type)['label']), $min));
        return 0;
    }

    $limits = vc_rewards_setting('daily_post_limit');
    $rank   = vc_rewards_rank($user_id);
    $limit  = isset($limits[$rank]) ? (int) $limits[$rank] : 0;
    if ($limit > 0 && vc_rewards_posts_today($user_id) >= $limit) {
        vc_rewards_wpforo_notice(__("Posted. You've reached today's limit for posts that earn points.", 'vc-rewards'));
        return 0;
    }

    // Only the body counts (a new title does not make a copy original), and
    // only when it is long enough that two people would not write it alike.
    $fingerprint = vc_rewards_word_count($body) >= (int) vc_rewards_setting('forum_copy_min_words')
        ? vc_rewards_fingerprint($body)
        : '';
    $links = vc_rewards_external_links($body);
    $args  = array('fingerprint' => $fingerprint, 'link_key' => $links ? vc_rewards_link_key($links[0]) : '');
    if (!empty($topic['status'])) {
        $args['state'] = 'held';
    }
    $id = vc_rewards_create_contribution('wpforo_topic', (int) $topic['topicid'], $type, $user_id, $args);

    // Someone else's text, reposted: rejected straight away. Matching is on
    // normalised words, so reformatting does not hide a copy.
    if ($id && vc_rewards_fingerprint_taken($fingerprint, $user_id)) {
        vc_rewards_reject($id, 'copied');
        vc_rewards_wpforo_notice(__('This matches a post someone else already made, so it will not earn points.', 'vc-rewards'), 'error');
    }
    return $id;
}

/* -------------------------------------------------------------------------
 * Keep the topic in step with its contribution
 * ---------------------------------------------------------------------- */

function vc_rewards_wpforo_set_status($topicid, $status) {
    if (!vc_rewards_wpforo_active()) {
        return;
    }
    $topic = WPF()->topic->get_topic((int) $topicid, false);
    if (!$topic || (int) $topic['status'] === (int) $status) {
        return;
    }
    vc_rewards_wpforo_syncing(true);
    if (!empty($topic['first_postid'])) {
        // Sets the first post and the topic together.
        WPF()->post->set_status((int) $topic['first_postid'], (int) $status);
    } else {
        WPF()->topic->set_status((int) $topicid, (int) $status);
    }
    vc_rewards_wpforo_syncing(false);
}

add_action('vc_rewards_contribution_state', function ($contribution_id, $state) {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || $c->object_type !== 'wpforo_topic' || !vc_rewards_wpforo_active()) {
        return;
    }
    $topicid = (int) $c->object_id;

    if ($state === 'held') {
        vc_rewards_wpforo_set_status($topicid, 1);
    } elseif ($state === 'voting' || $state === 'accepted') {
        vc_rewards_wpforo_set_status($topicid, 0);
    } elseif ($state === 'rejected') {
        if ($c->reject_reason === 'expired') {
            WPF()->topic->close($topicid);
        } elseif ($c->reject_reason !== 'removed') {
            vc_rewards_wpforo_set_status($topicid, 1);
        }
    } elseif ($state === 'withdrawn') {
        // A deal that died early stays readable but takes no more replies.
        WPF()->topic->close($topicid);
    }
}, 10, 2);

/**
 * A moderator approving a held post in wpForo's own moderation screen
 * counts the same as releasing it from the Rewards queue.
 */
function vc_rewards_wpforo_approved($post) {
    if (vc_rewards_wpforo_syncing() || empty($post['userid'])) {
        return;
    }
    $c = !empty($post['topicid']) ? vc_rewards_contribution_for('wpforo_topic', (int) $post['topicid']) : null;
    if ($c && !empty($post['is_first_post'])) {
        vc_rewards_release($c->id);
        return;
    }
    $user_id = (int) $post['userid'];
    if (vc_rewards_under_review($user_id)) {
        update_user_meta($user_id, '_vc_hold_approved', (int) get_user_meta($user_id, '_vc_hold_approved', true) + 1);
    }
}
add_action('wpforo_post_approve', 'vc_rewards_wpforo_approved');

add_action('wpforo_topic_approve', function ($topic) {
    if (vc_rewards_wpforo_syncing() || empty($topic['topicid'])) {
        return;
    }
    $c = vc_rewards_contribution_for('wpforo_topic', (int) $topic['topicid']);
    if ($c) {
        vc_rewards_release($c->id);
    }
});

add_action('wpforo_after_delete_topic', function ($topic) {
    if (empty($topic['topicid'])) {
        return;
    }
    $c = vc_rewards_contribution_for('wpforo_topic', (int) $topic['topicid']);
    if ($c && !in_array($c->state, array('rejected', 'withdrawn'), true)) {
        vc_rewards_reject($c->id, 'removed');
    }
});

/* -------------------------------------------------------------------------
 * Describing forum topics to the rest of the plugin
 * ---------------------------------------------------------------------- */

function vc_rewards_wpforo_first_post($topicid) {
    if (!vc_rewards_wpforo_active()) {
        return array();
    }
    $topic = WPF()->topic->get_topic((int) $topicid, false);
    if (!$topic) {
        return array();
    }
    $post = !empty($topic['first_postid']) ? WPF()->post->get_post((int) $topic['first_postid'], false) : array();
    return array('topic' => $topic, 'post' => $post ? $post : array());
}

add_filter('vc_rewards_object_info', function ($info, $c) {
    if ($c->object_type !== 'wpforo_topic' || !vc_rewards_wpforo_active()) {
        return $info;
    }
    $data = vc_rewards_wpforo_first_post($c->object_id);
    if (!$data) {
        return array('title' => __('(deleted forum topic)', 'vc-rewards'));
    }
    $topic = $data['topic'];
    $body  = isset($data['post']['body']) ? $data['post']['body'] : '';
    $links = vc_rewards_external_links($body);
    $forum = WPF()->forum->get_forum((int) $topic['forumid']);
    return array(
        'title'   => (string) $topic['title'],
        'url'     => (string) WPF()->topic->get_url((int) $topic['topicid']),
        'summary' => (string) $topic['title'],
        'detail'  => wp_trim_words(wp_strip_all_tags($body), 40),
        'brand'   => $forum && isset($forum['title']) ? (string) $forum['title'] : '',
        'link'    => $links ? $links[0] : '',
    );
}, 10, 2);

/** Revealing a forum deal opens its first outbound link. */
add_filter('vc_rewards_reveal_payload', function ($payload, $c) {
    if ($c->object_type !== 'wpforo_topic' || !vc_rewards_wpforo_active()) {
        return $payload;
    }
    $info = vc_rewards_object_info($c);
    return array('code' => '', 'url' => esc_url($info['link'] !== '' ? $info['link'] : $info['url']));
}, 10, 2);

/* -------------------------------------------------------------------------
 * Vote widget under the first post
 * ---------------------------------------------------------------------- */

add_filter('wpforo_content_after', function ($content, $post = array()) {
    if (empty($post['is_first_post']) || empty($post['topicid'])) {
        return $content;
    }
    $c = vc_rewards_contribution_for('wpforo_topic', (int) $post['topicid']);
    if (!$c || in_array($c->state, array('held', 'rejected'), true)) {
        return $content;
    }
    $user_id = get_current_user_id();
    if (!$user_id || vc_rewards_participation_error($user_id) !== '') {
        $type = vc_rewards_type($c->contrib_type);
        $note = $c->state === 'accepted'
            /* translators: %s: contribution type */
            ? sprintf(__('Verified %s', 'vc-rewards'), strtolower($type['label']))
            : __('Members are verifying this post.', 'vc-rewards');
        return $content . '<p class="vc-rewards-badge">' . esc_html($note) . '</p>';
    }
    vc_rewards_enqueue();
    return $content . vc_rewards_render_vote_card($c, $user_id);
}, 20, 2);
