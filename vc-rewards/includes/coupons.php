<?php
/**
 * Coupons: the first contribution type, built on WP Coupon & Deals.
 *
 * A member's submission becomes a `wcd_coupon` post in 'pending' status, so
 * it stays out of every public coupon list until members have verified it.
 * Acceptance publishes it; rejection moves it to draft; a code that dies
 * inside the holding period gets yesterday's expiry date, which both this
 * site's coupon plugin and merchant-tools already treat as expired.
 *
 * The coupon plugin's own anonymous success/fail votes are left alone. They
 * still drive the "Verified Code" label; rewards only ever use member votes.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_coupons_active() {
    return post_type_exists('wcd_coupon') && taxonomy_exists('wcd_brand');
}

/** "save-20 off" and "SAVE20OFF" are the same code. */
function vc_rewards_normalize_code($code) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
}

function vc_rewards_posts_today($user_id) {
    global $wpdb;
    $table = vc_rewards_table('contributions');
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE author_id = %d AND created_at >= %s",
        (int) $user_id,
        gmdate('Y-m-d 00:00:00')
    ));
}

/**
 * Another coupon for this brand already carries this code, and it is not
 * one that was rejected. The first poster keeps the credit.
 */
function vc_rewards_duplicate_coupon($term_id, $code) {
    $normalized = vc_rewards_normalize_code($code);
    if ($normalized === '') {
        return 0;
    }
    $ids = get_posts(array(
        'post_type'      => 'wcd_coupon',
        'post_status'    => array('publish', 'pending', 'future', 'private'),
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => array(array(
            'taxonomy' => 'wcd_brand',
            'field'    => 'term_id',
            'terms'    => (int) $term_id,
        )),
    ));
    foreach ($ids as $id) {
        if (vc_rewards_normalize_code(get_post_meta($id, '_wcd_code', true)) !== $normalized) {
            continue;
        }
        $c = vc_rewards_contribution_for('wcd_coupon', $id);
        if ($c && in_array($c->state, array('rejected', 'withdrawn'), true)) {
            continue;
        }
        return (int) $id;
    }
    return 0;
}

/**
 * Validate and store a submission.
 *
 * $data: brand (term ID), code, discount, url, expires (Y-m-d, optional), details.
 * Returns array('ok' => bool, 'message' => string, 'post_id' => int).
 */
function vc_rewards_submit_coupon($user_id, array $data) {
    $user_id = (int) $user_id;
    $fail = function ($message) {
        return array('ok' => false, 'message' => $message, 'post_id' => 0);
    };

    if (!vc_rewards_coupons_active()) {
        return $fail(__('Coupon submissions are not available right now.', 'vc-rewards'));
    }
    $error = vc_rewards_participation_error($user_id);
    if ($error) {
        return $fail($error);
    }

    $limits = vc_rewards_setting('daily_post_limit');
    $rank   = vc_rewards_rank($user_id);
    $limit  = isset($limits[$rank]) ? (int) $limits[$rank] : 0;
    if ($limit > 0 && vc_rewards_posts_today($user_id) >= $limit) {
        return $fail(__("You've reached today's posting limit. It goes up as your rank does.", 'vc-rewards'));
    }

    $term = get_term((int) ($data['brand'] ?? 0), 'wcd_brand');
    if (!$term || is_wp_error($term)) {
        return $fail(__('Choose the store this coupon is for.', 'vc-rewards'));
    }

    $code     = mb_substr(sanitize_text_field($data['code'] ?? ''), 0, 60);
    $discount = mb_substr(sanitize_text_field($data['discount'] ?? ''), 0, 120);
    $details  = mb_substr(sanitize_textarea_field($data['details'] ?? ''), 0, 1000);
    $url      = esc_url_raw(trim((string) ($data['url'] ?? '')), array('http', 'https'));
    $expires  = trim((string) ($data['expires'] ?? ''));

    if ($code === '' && $url === '') {
        return $fail(__('Add a code, or a link to the deal.', 'vc-rewards'));
    }
    if ($discount === '') {
        return $fail(__('Say what the discount is, for example "20% off sitewide".', 'vc-rewards'));
    }
    if ($expires !== '') {
        $stamp = strtotime($expires);
        if (!$stamp) {
            return $fail(__('That expiry date is not valid.', 'vc-rewards'));
        }
        $expires = gmdate('Y-m-d', $stamp);
        if ($expires < current_time('Y-m-d')) {
            return $fail(__('That coupon has already expired.', 'vc-rewards'));
        }
    }
    if ($code !== '' && vc_rewards_duplicate_coupon($term->term_id, $code)) {
        return $fail(__('Someone already posted that code for this store.', 'vc-rewards'));
    }

    $post_id = wp_insert_post(array(
        'post_type'    => 'wcd_coupon',
        'post_status'  => 'pending',
        'post_author'  => $user_id,
        'post_title'   => $term->name . ': ' . $discount,
        'post_content' => $details,
    ), true);
    if (is_wp_error($post_id) || !$post_id) {
        return $fail(__('Something went wrong saving that. Please try again.', 'vc-rewards'));
    }

    wp_set_object_terms($post_id, array((int) $term->term_id), 'wcd_brand');
    update_post_meta($post_id, '_wcd_type', $code !== '' ? 'code' : 'deal');
    update_post_meta($post_id, '_wcd_code', $code);
    update_post_meta($post_id, '_wcd_discount', $discount);
    update_post_meta($post_id, '_wcd_destination_url', $url);
    update_post_meta($post_id, '_wcd_expiration', $expires);

    $contribution_id = vc_rewards_create_contribution('wcd_coupon', $post_id, 'coupon', $user_id);
    $c = vc_rewards_get_contribution($contribution_id);

    $message = $c && $c->state === 'held'
        ? __('Thanks! A moderator checks the first few posts from new members, then other members verify it.', 'vc-rewards')
        : __('Thanks! Other members will verify it, and your reward is calculated once they do.', 'vc-rewards');

    return array('ok' => true, 'message' => $message, 'post_id' => (int) $post_id);
}

/**
 * Keep the coupon post in step with its contribution.
 */
add_action('vc_rewards_contribution_state', function ($contribution_id, $state) {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || $c->object_type !== 'wcd_coupon') {
        return;
    }
    $post_id = (int) $c->object_id;

    if ($state === 'accepted') {
        wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
    } elseif ($state === 'rejected') {
        wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
    } elseif ($state === 'withdrawn') {
        update_post_meta($post_id, '_wcd_expiration', gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' -1 day')));
    }
}, 10, 2);

/** What a member sees when they reveal a coupon. */
function vc_rewards_coupon_reveal_payload($contribution) {
    $post_id = (int) $contribution->object_id;
    return array(
        'code' => (string) get_post_meta($post_id, '_wcd_code', true),
        'url'  => esc_url((string) get_post_meta($post_id, '_wcd_destination_url', true)),
    );
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

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
    if (!$visible || $c->object_type !== 'wcd_coupon') {
        wp_send_json_error(array('message' => __('That coupon is not available.', 'vc-rewards')), 404);
    }
    vc_rewards_record_reveal($c->id, $user_id);
    wp_send_json_success(vc_rewards_coupon_reveal_payload($c));
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
