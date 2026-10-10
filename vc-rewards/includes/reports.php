<?php
/**
 * Dead-coupon reports and store fact corrections.
 *
 * Neither is a post of the member's own, so each is stored as a row in the
 * items table and the contribution points at ('vc_item', row ID):
 *
 *   report      "this published coupon no longer works". Members check the
 *               code and vote Confirmed / Still works. Confirmed pays the
 *               reporter and gives the coupon yesterday's expiry date.
 *               A report that turns out wrong costs nothing.
 *   correction  "this store's free-shipping threshold is wrong". A moderator
 *               approves it, and the value goes straight into the
 *               merchant-tools field with the member's source link.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_get_item($id) {
    global $wpdb;
    $table = vc_rewards_table('items');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id));
}

function vc_rewards_add_item(array $data) {
    global $wpdb;
    $wpdb->insert(vc_rewards_table('items'), array_merge(array(
        'kind'        => '',
        'user_id'     => 0,
        'target_type' => '',
        'target_id'   => 0,
        'field'       => '',
        'old_value'   => '',
        'new_value'   => '',
        'source_url'  => '',
        'note'        => '',
        'created_at'  => vc_rewards_now(),
    ), $data));
    return (int) $wpdb->insert_id;
}

/** The open (held or voting) contribution for an item of this kind and target, if any. */
function vc_rewards_open_item($kind, $target_type, $target_id, $field = null) {
    global $wpdb;
    $items   = vc_rewards_table('items');
    $contrib = vc_rewards_table('contributions');
    $sql = $wpdb->prepare(
        "SELECT c.* FROM $items i JOIN $contrib c ON c.object_type = 'vc_item' AND c.object_id = i.id
         WHERE i.kind = %s AND i.target_type = %s AND i.target_id = %d AND c.state IN ('held', 'voting')",
        $kind,
        $target_type,
        (int) $target_id
    );
    if ($field !== null) {
        $sql .= $wpdb->prepare(' AND i.field = %s', $field);
    }
    return $wpdb->get_row($sql . ' LIMIT 1');
}

function vc_rewards_post_limit_error($user_id) {
    $limits = vc_rewards_setting('daily_post_limit');
    $rank   = vc_rewards_rank($user_id);
    $limit  = isset($limits[$rank]) ? (int) $limits[$rank] : 0;
    if ($limit > 0 && vc_rewards_posts_today($user_id) >= $limit) {
        return __("You've reached today's posting limit. It goes up as your rank does.", 'vc-rewards');
    }
    return '';
}

/* -------------------------------------------------------------------------
 * Dead-coupon reports
 * ---------------------------------------------------------------------- */

/** A published coupon for this store with this code, or 0. */
function vc_rewards_find_coupon($term_id, $code) {
    $normalized = vc_rewards_normalize_code($code);
    if ($normalized === '' || !vc_rewards_coupons_active()) {
        return 0;
    }
    $ids = get_posts(array(
        'post_type'      => 'wcd_coupon',
        'post_status'    => 'publish',
        'posts_per_page' => 500,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'tax_query'      => array(array('taxonomy' => 'wcd_brand', 'field' => 'term_id', 'terms' => (int) $term_id)),
    ));
    foreach ($ids as $id) {
        if (vc_rewards_normalize_code(get_post_meta($id, '_wcd_code', true)) === $normalized) {
            return (int) $id;
        }
    }
    return 0;
}

/**
 * Returns array('ok', 'message', 'contribution_id').
 */
function vc_rewards_report_coupon($user_id, $coupon_id, $note = '') {
    global $wpdb;
    $user_id   = (int) $user_id;
    $coupon_id = (int) $coupon_id;
    $fail = function ($message) {
        return array('ok' => false, 'message' => $message, 'contribution_id' => 0);
    };

    $error = vc_rewards_participation_error($user_id);
    if ($error) {
        return $fail($error);
    }
    if (!$coupon_id || get_post_type($coupon_id) !== 'wcd_coupon' || get_post_status($coupon_id) !== 'publish') {
        return $fail(__("We couldn't find that coupon.", 'vc-rewards'));
    }
    $expires = (string) get_post_meta($coupon_id, '_wcd_expiration', true);
    if ($expires !== '' && $expires < current_time('Y-m-d')) {
        return $fail(__('That coupon is already marked expired. Thanks anyway!', 'vc-rewards'));
    }

    // A coupon members are still verifying is voted on directly.
    $own = vc_rewards_contribution_for('wcd_coupon', $coupon_id);
    if ($own && ($own->state === 'voting' || ($own->state === 'accepted' && vc_rewards_in_holding($own)))) {
        return $fail(__('Members are still verifying that coupon. Vote "Expired" or "Doesn\'t work" on it in the verify queue instead.', 'vc-rewards'));
    }
    if ($own && (int) $own->author_id === $user_id) {
        return $fail(__("You can't report your own coupon. Thanks for keeping it honest!", 'vc-rewards'));
    }
    if (vc_rewards_open_item('report', 'wcd_coupon', $coupon_id)) {
        return $fail(__('Someone already reported that coupon. Members are checking it now.', 'vc-rewards'));
    }

    // One report per coupon per member per month, so the same code can't be
    // reported over and over by someone hoping it dies.
    $items = vc_rewards_table('items');
    $recent = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $items WHERE kind = 'report' AND target_type = 'wcd_coupon' AND target_id = %d AND user_id = %d AND created_at >= %s",
        $coupon_id,
        $user_id,
        vc_rewards_now(-30 * DAY_IN_SECONDS)
    ));
    if ($recent) {
        return $fail(__('You reported that coupon recently.', 'vc-rewards'));
    }
    $limit = vc_rewards_post_limit_error($user_id);
    if ($limit) {
        return $fail($limit);
    }

    $item_id = vc_rewards_add_item(array(
        'kind'        => 'report',
        'user_id'     => $user_id,
        'target_type' => 'wcd_coupon',
        'target_id'   => $coupon_id,
        'field'       => '_wcd_expiration',
        'old_value'   => $expires,
        'note'        => mb_substr(sanitize_textarea_field($note), 0, 500),
    ));
    $cid = vc_rewards_create_contribution('vc_item', $item_id, 'report', $user_id);
    return array(
        'ok'              => true,
        'message'         => __('Thanks! Members will check the code. If they confirm it no longer works, you earn points.', 'vc-rewards'),
        'contribution_id' => $cid,
    );
}

/* -------------------------------------------------------------------------
 * Store fact corrections
 * ---------------------------------------------------------------------- */

/** merchant-tools fields members may correct: meta key => label. */
function vc_rewards_correction_fields() {
    return apply_filters('vc_rewards_correction_fields', array(
        'free_shipping_info' => __('Free shipping (for example "Free shipping over $75")', 'vc-rewards'),
        'restricted_states'  => __("States it won't ship to (separate with |)", 'vc-rewards'),
        'contact_email'      => __('Support email', 'vc-rewards'),
        'contact_page_url'   => __('Contact page', 'vc-rewards'),
    ));
}

function vc_rewards_merchant_post_types() {
    return function_exists('vc_merchant_post_types') ? (array) vc_merchant_post_types() : array('merchant');
}

function vc_rewards_sanitize_correction($field, $value) {
    $value = trim((string) $value);
    switch ($field) {
        case 'contact_email':
            return sanitize_email($value);
        case 'contact_page_url':
            return esc_url_raw($value, array('http', 'https'));
        case 'restricted_states':
            $parts = array_filter(array_map('trim', explode('|', sanitize_text_field($value))));
            return implode('|', $parts);
        default:
            return mb_substr(sanitize_text_field($value), 0, 200);
    }
}

/**
 * Returns array('ok', 'message', 'contribution_id').
 */
function vc_rewards_suggest_correction($user_id, $merchant_id, $field, $value, $source_url, $note = '') {
    $user_id     = (int) $user_id;
    $merchant_id = (int) $merchant_id;
    $fail = function ($message) {
        return array('ok' => false, 'message' => $message, 'contribution_id' => 0);
    };

    $error = vc_rewards_participation_error($user_id);
    if ($error) {
        return $fail($error);
    }
    if (!$merchant_id || !in_array(get_post_type($merchant_id), vc_rewards_merchant_post_types(), true) || get_post_status($merchant_id) !== 'publish') {
        return $fail(__('Choose the store this is about.', 'vc-rewards'));
    }
    $fields = vc_rewards_correction_fields();
    if (!isset($fields[$field])) {
        return $fail(__('Choose what needs correcting.', 'vc-rewards'));
    }
    $value = vc_rewards_sanitize_correction($field, $value);
    if ($value === '') {
        return $fail(__('Enter the correct information.', 'vc-rewards'));
    }
    $current = (string) get_post_meta($merchant_id, $field, true);
    if ($value === $current) {
        return $fail(__("That's what the page already says.", 'vc-rewards'));
    }
    $source = esc_url_raw(trim((string) $source_url), array('http', 'https'));
    if ($source === '') {
        return $fail(__("Add a link to where you found it, such as the store's shipping page.", 'vc-rewards'));
    }
    if (vc_rewards_open_item('correction', 'merchant', $merchant_id, $field)) {
        return $fail(__('A correction to that is already waiting for a moderator.', 'vc-rewards'));
    }
    $limit = vc_rewards_post_limit_error($user_id);
    if ($limit) {
        return $fail($limit);
    }

    $item_id = vc_rewards_add_item(array(
        'kind'        => 'correction',
        'user_id'     => $user_id,
        'target_type' => 'merchant',
        'target_id'   => $merchant_id,
        'field'       => $field,
        'old_value'   => $current,
        'new_value'   => $value,
        'source_url'  => $source,
        'note'        => mb_substr(sanitize_textarea_field($note), 0, 500),
    ));
    // Moderators decide corrections, so they never wait in the new-member hold.
    $cid = vc_rewards_create_contribution('vc_item', $item_id, 'correction', $user_id, array('state' => 'voting'));
    return array(
        'ok'              => true,
        'message'         => __('Thanks! A moderator will check your source and update the store page.', 'vc-rewards'),
        'contribution_id' => $cid,
    );
}

/** Apply an approved correction to the store page and pay for it. */
function vc_rewards_approve_correction($contribution_id) {
    $c = vc_rewards_get_contribution($contribution_id);
    $item = $c && $c->object_type === 'vc_item' ? vc_rewards_get_item($c->object_id) : null;
    if (!$item || $item->kind !== 'correction' || $c->state !== 'voting') {
        return false;
    }
    update_post_meta((int) $item->target_id, $item->field, $item->new_value);
    update_post_meta((int) $item->target_id, 'fact_source_url', $item->source_url);
    update_post_meta((int) $item->target_id, 'fact_last_verified', current_time('Y-m-d'));
    do_action('vc_rewards_correction_applied', (int) $item->target_id, $item->field, $item->new_value, $item);
    return vc_rewards_accept($c->id);
}

/* -------------------------------------------------------------------------
 * Keeping the coupon in step, and describing items
 * ---------------------------------------------------------------------- */

add_action('vc_rewards_contribution_state', function ($contribution_id, $state, $old = '') {
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || $c->object_type !== 'vc_item' || $c->contrib_type !== 'report') {
        return;
    }
    $item = vc_rewards_get_item($c->object_id);
    if (!$item) {
        return;
    }
    if ($state === 'accepted') {
        update_post_meta((int) $item->target_id, '_wcd_expiration', gmdate('Y-m-d', strtotime(current_time('Y-m-d') . ' -1 day')));
    } elseif ($state === 'withdrawn') {
        // Voted back to working while the reward was pending: undo.
        update_post_meta((int) $item->target_id, '_wcd_expiration', $item->old_value);
    }
}, 10, 3);

add_filter('vc_rewards_object_info', function ($info, $c) {
    if ($c->object_type !== 'vc_item') {
        return $info;
    }
    $item = vc_rewards_get_item($c->object_id);
    if (!$item) {
        return $info;
    }
    if ($item->kind === 'report') {
        $coupon = (int) $item->target_id;
        $terms  = get_the_terms($coupon, 'wcd_brand');
        return array(
            /* translators: %s: coupon title */
            'title'   => sprintf(__('Reported: %s', 'vc-rewards'), get_the_title($coupon)),
            'url'     => get_permalink($coupon),
            'summary' => (string) get_post_meta($coupon, '_wcd_discount', true),
            'detail'  => $item->note,
            'brand'   => $terms && !is_wp_error($terms) ? $terms[0]->name : '',
            'code'    => (string) get_post_meta($coupon, '_wcd_code', true),
            'link'    => (string) get_post_meta($coupon, '_wcd_destination_url', true),
        );
    }
    $fields = vc_rewards_correction_fields();
    $label  = isset($fields[$item->field]) ? $fields[$item->field] : $item->field;
    return array(
        /* translators: 1: store name, 2: field label */
        'title'   => sprintf(__('%1$s: %2$s', 'vc-rewards'), get_the_title((int) $item->target_id), $label),
        'url'     => get_permalink((int) $item->target_id),
        /* translators: 1: current value, 2: suggested value */
        'summary' => sprintf(__('Now "%1$s", suggested "%2$s"', 'vc-rewards'), $item->old_value, $item->new_value),
        'detail'  => $item->note,
        'brand'   => get_the_title((int) $item->target_id),
        'link'    => $item->source_url,
    );
}, 10, 2);

/** Checking a report means trying the code. */
add_filter('vc_rewards_reveal_payload', function ($payload, $c) {
    if ($c->object_type !== 'vc_item' || $c->contrib_type !== 'report') {
        return $payload;
    }
    $item = vc_rewards_get_item($c->object_id);
    if (!$item) {
        return null;
    }
    return array(
        'code' => (string) get_post_meta((int) $item->target_id, '_wcd_code', true),
        'url'  => esc_url((string) get_post_meta((int) $item->target_id, '_wcd_destination_url', true)),
    );
}, 10, 2);

/* -------------------------------------------------------------------------
 * Moderation
 * ---------------------------------------------------------------------- */

add_action('vc_rewards_queue_sections', function () {
    global $wpdb;
    $table = vc_rewards_table('contributions');
    $rows  = $wpdb->get_results("SELECT * FROM $table WHERE contrib_type = 'correction' AND state = 'voting' ORDER BY id ASC LIMIT 50");
    echo '<h2>' . esc_html(sprintf(
        /* translators: %d: count */
        __('Store fact corrections (%d)', 'vc-rewards'),
        count($rows)
    )) . '</h2>';
    echo '<p class="description">' . esc_html__('Check the source link. Approving updates the store page and pays the member.', 'vc-rewards') . '</p>';
    vc_rewards_contribution_table($rows, function ($c) {
        return vc_rewards_action_button('correction_approve', $c->id, __('Approve and update page', 'vc-rewards'), array(), 'button button-primary')
            . vc_rewards_action_button('reject', $c->id, __('Decline', 'vc-rewards'), array('reason' => 'declined'))
            . vc_rewards_action_button('reject', $c->id, __('Spam', 'vc-rewards'), array('reason' => 'spam'), 'button button-link-delete');
    });
}, 5);

add_filter('vc_rewards_mod_op', function ($result, $op, $id) {
    if ($op === 'correction_approve') {
        return array('vc-rewards', vc_rewards_approve_correction($id)
            ? __('Store page updated. The member reward is pending.', 'vc-rewards')
            : __('That correction was already decided.', 'vc-rewards'));
    }
    return $result;
}, 10, 3);

/* -------------------------------------------------------------------------
 * Front end: [vc_report_coupon] and [vc_suggest_correction]
 * ---------------------------------------------------------------------- */

add_filter('vc_rewards_process_form', function ($result, $action, $user_id, $posted) {
    if ($action === 'report_coupon') {
        $coupon = isset($posted['vc_coupon']) ? (int) $posted['vc_coupon'] : 0;
        if (!$coupon) {
            $coupon = vc_rewards_find_coupon(isset($posted['vc_brand']) ? (int) $posted['vc_brand'] : 0, isset($posted['vc_code']) ? $posted['vc_code'] : '');
        }
        $r = vc_rewards_report_coupon($user_id, $coupon, isset($posted['vc_note']) ? $posted['vc_note'] : '');
        return array('action' => $action, 'ok' => $r['ok'], 'message' => $r['message']);
    }
    if ($action === 'suggest_correction') {
        $r = vc_rewards_suggest_correction(
            $user_id,
            isset($posted['vc_merchant']) ? (int) $posted['vc_merchant'] : 0,
            isset($posted['vc_field']) ? sanitize_key($posted['vc_field']) : '',
            isset($posted['vc_value']) ? $posted['vc_value'] : '',
            isset($posted['vc_source']) ? $posted['vc_source'] : '',
            isset($posted['vc_note']) ? $posted['vc_note'] : ''
        );
        return array('action' => $action, 'ok' => $r['ok'], 'message' => $r['message']);
    }
    return $result;
}, 10, 4);

/**
 * [vc_report_coupon]          store + code form
 * [vc_report_coupon id="12"]  one button for a specific coupon
 */
add_shortcode('vc_report_coupon', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts);
    if (!is_user_logged_in()) {
        return (int) $atts['id'] ? '' : vc_rewards_login_prompt();
    }
    vc_rewards_enqueue();
    $out = vc_rewards_flash_notice(array('report_coupon'));
    if ((int) $atts['id']) {
        return $out . '<form method="post" class="vc-rewards-inline">' . vc_rewards_form_fields('report_coupon')
            . '<input type="hidden" name="vc_coupon" value="' . (int) $atts['id'] . '">'
            . '<button type="submit" class="vc-rewards-link">' . esc_html__('Report: this code no longer works', 'vc-rewards') . '</button></form>';
    }
    $brands  = get_terms(array('taxonomy' => 'wcd_brand', 'hide_empty' => true, 'orderby' => 'name'));
    $options = '<option value="">' . esc_html__('Choose a store', 'vc-rewards') . '</option>';
    foreach (is_wp_error($brands) ? array() : $brands as $brand) {
        $options .= '<option value="' . (int) $brand->term_id . '">' . esc_html($brand->name) . '</option>';
    }
    return $out . '<form method="post" class="vc-rewards-form">' . vc_rewards_form_fields('report_coupon')
        . '<p>' . esc_html__("Found a code on this site that doesn't work any more? Report it. If members confirm it, you earn points.", 'vc-rewards') . '</p>'
        . '<p><label>' . esc_html__('Store', 'vc-rewards') . '<br><select name="vc_brand" required>' . $options . '</select></label></p>'
        . '<p><label>' . esc_html__('Coupon code', 'vc-rewards') . '<br><input type="text" name="vc_code" maxlength="60" required></label></p>'
        . '<p><label>' . esc_html__('What happened? (optional)', 'vc-rewards') . '<br><textarea name="vc_note" rows="2" maxlength="500"></textarea></label></p>'
        . '<p><button type="submit">' . esc_html__('Report it', 'vc-rewards') . '</button></p></form>';
});

/**
 * [vc_suggest_correction]  store, field, correct value and a source link.
 * Add ?merchant=ID to the page link to preselect the store.
 */
add_shortcode('vc_suggest_correction', function () {
    if (!is_user_logged_in()) {
        return vc_rewards_login_prompt();
    }
    vc_rewards_enqueue();
    $out = vc_rewards_flash_notice(array('suggest_correction'));
    $selected  = isset($_GET['merchant']) ? (int) $_GET['merchant'] : 0;
    $merchants = get_posts(array(
        'post_type'      => vc_rewards_merchant_post_types(),
        'post_status'    => 'publish',
        'posts_per_page' => 1000,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'no_found_rows'  => true,
    ));
    if (!$merchants) {
        return $out;
    }
    $store_options = '<option value="">' . esc_html__('Choose a store', 'vc-rewards') . '</option>';
    foreach ($merchants as $m) {
        $store_options .= '<option value="' . (int) $m->ID . '" ' . selected($selected, $m->ID, false) . '>' . esc_html(get_the_title($m)) . '</option>';
    }
    $field_options = '';
    foreach (vc_rewards_correction_fields() as $key => $label) {
        $field_options .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    return $out . '<form method="post" class="vc-rewards-form">' . vc_rewards_form_fields('suggest_correction')
        . '<p>' . esc_html__('Spotted wrong store information? Send the correct details with a link to where you found them.', 'vc-rewards') . '</p>'
        . '<p><label>' . esc_html__('Store', 'vc-rewards') . '<br><select name="vc_merchant" required>' . $store_options . '</select></label></p>'
        . '<p><label>' . esc_html__('What needs correcting', 'vc-rewards') . '<br><select name="vc_field" required>' . $field_options . '</select></label></p>'
        . '<p><label>' . esc_html__('Correct information', 'vc-rewards') . '<br><input type="text" name="vc_value" maxlength="200" required></label></p>'
        . '<p><label>' . esc_html__('Where you found it (link)', 'vc-rewards') . '<br><input type="url" name="vc_source" required></label></p>'
        . '<p><label>' . esc_html__('Anything else? (optional)', 'vc-rewards') . '<br><textarea name="vc_note" rows="2" maxlength="500"></textarea></label></p>'
        . '<p><button type="submit">' . esc_html__('Send correction', 'vc-rewards') . '</button></p></form>';
});
