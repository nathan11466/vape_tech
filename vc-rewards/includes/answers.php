<?php
/**
 * Accepted answers in wpForo Q&A forums.
 *
 * When the person who asked marks a reply as the best answer, the member who
 * wrote it earns a flat reward. wpForo keeps that mark in its posts table
 * (is_answer) without a dedicated hook, so the hourly settle job looks for
 * newly marked answers instead of relying on one.
 *
 * Only counts when:
 *   - the asker is Member rank or above (so a fresh account can't farm a friend)
 *   - the asker and answerer are different people
 *   - the same asker hasn't already rewarded the same member within
 *     answer_pair_days, so two friends can't trade answers for points
 *   - the answerer can take part in rewards at all
 *
 * An answer unmarked while its reward is still pending is withdrawn.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('vc_rewards_settle', 'vc_rewards_scan_answers', 20);

/** Returns the number of new accepted answers credited. */
function vc_rewards_scan_answers() {
    if (!vc_rewards_wpforo_active() || !isset(WPF()->post) || !method_exists(WPF()->post, 'get_posts')) {
        return 0;
    }
    $posts = WPF()->post->get_posts(array(
        'is_answer'     => 1,
        'status'        => 0,
        'orderby'       => '`postid`',
        'order'         => 'DESC',
        'row_count'     => 200,
        'check_private' => false,
    ));
    $credited = 0;
    foreach ((array) $posts as $post) {
        if (vc_rewards_credit_answer($post)) {
            $credited++;
        }
    }
    vc_rewards_recheck_answers();
    return $credited;
}

/** Credit one best-answer post if it qualifies. Returns the contribution ID or 0. */
function vc_rewards_credit_answer($post) {
    global $wpdb;
    $postid   = isset($post['postid']) ? (int) $post['postid'] : 0;
    $answerer = isset($post['userid']) ? (int) $post['userid'] : 0;
    if (!$postid || !$answerer || !empty($post['is_first_post']) || vc_rewards_contribution_for('wpforo_answer', $postid)) {
        return 0;
    }
    $topic = WPF()->topic->get_topic((int) $post['topicid'], false);
    $asker = $topic ? (int) $topic['userid'] : 0;
    if (!$asker || $asker === $answerer) {
        return 0;
    }
    if (in_array(vc_rewards_rank($asker), array('newcomer'), true) || vc_rewards_is_banned($asker)) {
        return 0;
    }
    if (vc_rewards_participation_error($answerer) !== '') {
        return 0;
    }

    // Pair limit: the asker's earlier accepted answers to this member.
    $contrib = vc_rewards_table('contributions');
    $recent  = $wpdb->get_col($wpdb->prepare(
        "SELECT object_id FROM $contrib WHERE contrib_type = 'answer' AND author_id = %d AND created_at >= %s",
        $answerer,
        vc_rewards_now(-1 * (int) vc_rewards_setting('answer_pair_days') * DAY_IN_SECONDS)
    ));
    foreach ($recent as $earlier_postid) {
        $earlier = WPF()->post->get_post((int) $earlier_postid, false);
        $earlier_topic = $earlier ? WPF()->topic->get_topic((int) $earlier['topicid'], false) : null;
        if ($earlier_topic && (int) $earlier_topic['userid'] === $asker) {
            return 0;
        }
    }

    $id = vc_rewards_create_contribution('wpforo_answer', $postid, 'answer', $answerer, array('state' => 'voting'));
    if ($id) {
        vc_rewards_accept($id);
    }
    return $id;
}

/** Answers unmarked (or deleted) while their reward is pending lose it. */
function vc_rewards_recheck_answers() {
    global $wpdb;
    $contrib = vc_rewards_table('contributions');
    $ledger  = vc_rewards_table('ledger');
    $rows = $wpdb->get_results(
        "SELECT c.* FROM $contrib c WHERE c.contrib_type = 'answer' AND c.state = 'accepted' AND EXISTS (
            SELECT 1 FROM $ledger l WHERE l.contribution_id = c.id AND l.kind = 'contribution' AND l.status = 'pending')"
    );
    foreach ($rows as $c) {
        $post = WPF()->post->get_post((int) $c->object_id, false);
        if (!$post || empty($post['is_answer'])) {
            vc_rewards_withdraw($c->id);
        }
    }
}

add_filter('vc_rewards_object_info', function ($info, $c) {
    if ($c->object_type !== 'wpforo_answer' || !vc_rewards_wpforo_active()) {
        return $info;
    }
    $post  = WPF()->post->get_post((int) $c->object_id, false);
    $topic = $post ? WPF()->topic->get_topic((int) $post['topicid'], false) : null;
    if (!$topic) {
        return array('title' => __('(deleted forum answer)', 'vc-rewards'));
    }
    return array(
        /* translators: %s: topic title */
        'title'   => sprintf(__('Answer in "%s"', 'vc-rewards'), $topic['title']),
        'url'     => (string) WPF()->topic->get_url((int) $topic['topicid']),
        'summary' => __('Marked best answer', 'vc-rewards'),
        'detail'  => wp_trim_words(wp_strip_all_tags((string) $post['body']), 40),
    );
}, 10, 2);
