<?php
/**
 * What a contribution points at.
 *
 * The core only knows ('wcd_coupon', 12) or ('wpforo_topic', 34). Each
 * integration describes its own objects through two filters, so the verify
 * queue, the vote widget and the admin queue work the same for all of them:
 *
 *   vc_rewards_object_info     title, link and text to show
 *   vc_rewards_reveal_payload  what "reveal" hands the member (a code, a link)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Returns array:
 *   title    one line
 *   url      where the member can see it in context ('' if nowhere yet)
 *   summary  the headline fact ("15% off", "Product review")
 *   detail   a few sentences of body text
 *   expires  Y-m-d or ''
 *   brand    store or forum name
 *   code     shown to moderators only
 *   link     outbound link, shown to moderators only
 */
function vc_rewards_object_info($contribution) {
    $info = apply_filters('vc_rewards_object_info', array(), $contribution);
    return array_merge(array(
        'title'   => sprintf('%s #%d', $contribution->object_type, $contribution->object_id),
        'url'     => '',
        'summary' => '',
        'detail'  => '',
        'expires' => '',
        'brand'   => '',
        'code'    => '',
        'link'    => '',
    ), is_array($info) ? $info : array());
}

/**
 * What revealing gives the member, or null when this object has nothing to
 * reveal. Recording the reveal is what unlocks a factual vote.
 */
function vc_rewards_reveal_payload($contribution) {
    return apply_filters('vc_rewards_reveal_payload', null, $contribution);
}

function vc_rewards_ajax_guard() {
    if (empty($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'vc_rewards')) {
        wp_send_json_error(array('message' => __('This page is out of date. Refresh and try again.', 'vc-rewards')), 403);
    }
    $user_id = get_current_user_id();
    $error   = vc_rewards_participation_error($user_id);
    if ($error) {
        wp_send_json_error(array('message' => $error), 403);
    }
    return $user_id;
}

add_action('wp_ajax_vc_rewards_reveal', function () {
    $user_id = vc_rewards_ajax_guard();
    $c = vc_rewards_get_contribution(isset($_POST['contribution']) ? (int) $_POST['contribution'] : 0);
    $visible = $c && ($c->state === 'voting' || $c->state === 'accepted');
    $payload = $visible ? vc_rewards_reveal_payload($c) : null;
    if ($payload === null) {
        wp_send_json_error(array('message' => __('That post is not available.', 'vc-rewards')), 404);
    }
    vc_rewards_record_reveal($c->id, $user_id);
    wp_send_json_success($payload);
});

add_action('wp_ajax_vc_rewards_vote', function () {
    $user_id = vc_rewards_ajax_guard();
    $result  = vc_rewards_cast_vote(
        isset($_POST['contribution']) ? (int) $_POST['contribution'] : 0,
        $user_id,
        isset($_POST['verdict']) ? sanitize_key(wp_unslash($_POST['verdict'])) : ''
    );
    if ($result['ok']) {
        wp_send_json_success(array('message' => $result['message']));
    }
    wp_send_json_error(array('message' => $result['message']));
});
