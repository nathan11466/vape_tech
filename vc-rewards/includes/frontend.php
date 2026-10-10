<?php
/**
 * Member-facing pages, as shortcodes so they drop into any page:
 *
 *   [vc_submit_coupon]       submission form
 *   [vc_verify_queue]        coupons waiting for members to verify
 *   [vc_rewards_account]     balance, rank, history, redemptions, appeals
 *   [vc_rewards_leaderboard] top members by reputation
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_enqueue() {
    wp_enqueue_style('vc-rewards', VC_REWARDS_URL . 'assets/vc-rewards.css', array(), VC_REWARDS_VERSION);
    wp_enqueue_script('vc-rewards', VC_REWARDS_URL . 'assets/vc-rewards.js', array(), VC_REWARDS_VERSION, true);
    wp_localize_script('vc-rewards', 'vcRewards', array(
        'ajax'  => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('vc_rewards'),
        'error' => __('Something went wrong. Please try again.', 'vc-rewards'),
    ));
}

function vc_rewards_notice($message, $ok = true) {
    return sprintf(
        '<div class="vc-rewards-notice %s" role="status">%s</div>',
        $ok ? 'is-ok' : 'is-error',
        esc_html($message)
    );
}

function vc_rewards_login_prompt() {
    return sprintf(
        '<p class="vc-rewards-login">%s <a href="%s">%s</a></p>',
        esc_html__('Members earn points for finding and verifying deals.', 'vc-rewards'),
        esc_url(wp_login_url(get_permalink())),
        esc_html__('Log in or join', 'vc-rewards')
    );
}

/** Verifies a front-end form post. */
function vc_rewards_form_posted($action) {
    return isset($_POST['vc_rewards_action'])
        && $_POST['vc_rewards_action'] === $action
        && !empty($_POST['_vc_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_vc_nonce'])), 'vc_rewards_' . $action);
}

/**
 * Process front-end forms once, before the page renders, then redirect.
 *
 * Handling them inside the shortcodes would run them again every time
 * something renders the page content -- SEO plugins do that to build meta
 * descriptions -- and a refresh would resubmit. The result is passed to
 * the next page view as a one-time message.
 */
function vc_rewards_handle_forms() {
    if (empty($_POST['vc_rewards_action']) || !is_user_logged_in()) {
        return;
    }
    $user_id = get_current_user_id();
    $action  = sanitize_key(wp_unslash($_POST['vc_rewards_action']));
    if (!vc_rewards_form_posted($action)) {
        return;
    }
    $result = vc_rewards_process_form($action, $user_id, wp_unslash($_POST));
    if ($result === null) {
        return;
    }
    set_transient('vc_rewards_flash_' . $user_id, $result, 5 * MINUTE_IN_SECONDS);
    if (!apply_filters('vc_rewards_redirect_after_form', true)) {
        return;
    }
    wp_safe_redirect(wp_get_referer() ?: home_url(add_query_arg(array())));
    exit;
}
add_action('template_redirect', 'vc_rewards_handle_forms');

/**
 * Returns array('action', 'ok', 'message', 'values') or null.
 */
function vc_rewards_process_form($action, $user_id, array $posted) {
    switch ($action) {
        case 'submit_coupon':
            $values = array();
            foreach (array('brand', 'code', 'discount', 'url', 'expires', 'details') as $key) {
                $values[$key] = isset($posted['vc_' . $key]) ? (string) $posted['vc_' . $key] : '';
            }
            $r = vc_rewards_submit_coupon($user_id, $values);
            return array('action' => $action, 'ok' => $r['ok'], 'message' => $r['message'], 'values' => $r['ok'] ? array() : $values);

        case 'dob':
            $dob = isset($posted['vc_dob']) ? sanitize_text_field($posted['vc_dob']) : '';
            $err = vc_rewards_dob_error($dob);
            if ($err === '' && vc_rewards_set_dob($user_id, $dob)) {
                return array('action' => $action, 'ok' => true, 'message' => __('Thanks, your age is confirmed.', 'vc-rewards'));
            }
            return array('action' => $action, 'ok' => false, 'message' => $err ?: __('Your age is already confirmed.', 'vc-rewards'));

        case 'redeem':
            $r = vc_rewards_request_redemption(
                $user_id,
                isset($posted['vc_points']) ? (int) $posted['vc_points'] : 0,
                isset($posted['vc_reward']) ? sanitize_text_field($posted['vc_reward']) : ''
            );
            return array('action' => $action, 'ok' => $r['ok'], 'message' => $r['message']);

        case 'appeal':
            $ok = vc_rewards_request_appeal(isset($posted['vc_contribution']) ? (int) $posted['vc_contribution'] : 0, $user_id);
            return array(
                'action'  => $action,
                'ok'      => $ok,
                'message' => $ok ? __('Appeal sent. A moderator will take another look.', 'vc-rewards') : __('That post can not be appealed.', 'vc-rewards'),
            );
    }
    return null;
}

/**
 * The one-time result for one of $actions, if the last form was one of them.
 * Read once per request and kept, so a page rendered twice shows it twice.
 */
function vc_rewards_flash(array $actions) {
    static $flash = false;
    if ($flash === false) {
        $key   = 'vc_rewards_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if ($flash) {
            delete_transient($key);
        }
    }
    return is_array($flash) && in_array($flash['action'], $actions, true) ? $flash : null;
}

function vc_rewards_flash_notice(array $actions) {
    $f = vc_rewards_flash($actions);
    return $f ? vc_rewards_notice($f['message'], $f['ok']) : '';
}

function vc_rewards_form_fields($action) {
    return '<input type="hidden" name="vc_rewards_action" value="' . esc_attr($action) . '">'
        . wp_nonce_field('vc_rewards_' . $action, '_vc_nonce', true, false);
}

/* -------------------------------------------------------------------------
 * [vc_submit_coupon]
 * ---------------------------------------------------------------------- */

add_shortcode('vc_submit_coupon', function () {
    if (!is_user_logged_in()) {
        return vc_rewards_login_prompt();
    }
    vc_rewards_enqueue();
    $user_id = get_current_user_id();
    $out = '';
    $values = array('brand' => 0, 'code' => '', 'discount' => '', 'url' => '', 'expires' => '', 'details' => '');

    $flash = vc_rewards_flash(array('submit_coupon'));
    if ($flash) {
        $out .= vc_rewards_notice($flash['message'], $flash['ok']);
        $values = array_merge($values, array_intersect_key((array) ($flash['values'] ?? array()), $values));
    }

    $error = vc_rewards_participation_error($user_id);
    if ($error) {
        return $out . vc_rewards_notice($error, false);
    }

    $brands = get_terms(array('taxonomy' => 'wcd_brand', 'hide_empty' => false, 'orderby' => 'name'));
    $options = '<option value="">' . esc_html__('Choose a store', 'vc-rewards') . '</option>';
    foreach (is_wp_error($brands) ? array() : $brands as $brand) {
        $options .= sprintf(
            '<option value="%d" %s>%s</option>',
            (int) $brand->term_id,
            selected((int) $values['brand'], (int) $brand->term_id, false),
            esc_html($brand->name)
        );
    }

    ob_start();
    ?>
    <form method="post" class="vc-rewards-form">
        <?php echo vc_rewards_form_fields('submit_coupon'); // phpcs:ignore WordPress.Security.EscapeOutput ?>
        <p><label><?php esc_html_e('Store', 'vc-rewards'); ?><br>
            <select name="vc_brand" required><?php echo $options; // phpcs:ignore WordPress.Security.EscapeOutput ?></select></label></p>
        <p><label><?php esc_html_e('Coupon code', 'vc-rewards'); ?><br>
            <input type="text" name="vc_code" maxlength="60" value="<?php echo esc_attr($values['code']); ?>"></label></p>
        <p><label><?php esc_html_e('Discount', 'vc-rewards'); ?><br>
            <input type="text" name="vc_discount" maxlength="120" required placeholder="<?php esc_attr_e('20% off sitewide', 'vc-rewards'); ?>" value="<?php echo esc_attr($values['discount']); ?>"></label></p>
        <p><label><?php esc_html_e('Link to the deal (optional if there is a code)', 'vc-rewards'); ?><br>
            <input type="url" name="vc_url" value="<?php echo esc_attr($values['url']); ?>"></label></p>
        <p><label><?php esc_html_e('Expires (if known)', 'vc-rewards'); ?><br>
            <input type="date" name="vc_expires" value="<?php echo esc_attr($values['expires']); ?>"></label></p>
        <p><label><?php esc_html_e('Details: minimum order, exclusions, anything that helps it work', 'vc-rewards'); ?><br>
            <textarea name="vc_details" rows="3" maxlength="1000"><?php echo esc_textarea($values['details']); ?></textarea></label></p>
        <p><button type="submit"><?php esc_html_e('Submit coupon', 'vc-rewards'); ?></button></p>
    </form>
    <?php
    return $out . ob_get_clean();
});

/* -------------------------------------------------------------------------
 * [vc_verify_queue]
 * ---------------------------------------------------------------------- */

function vc_rewards_queue_items($user_id, $limit = 20) {
    global $wpdb;
    $contrib = vc_rewards_table('contributions');
    $ledger  = vc_rewards_table('ledger');
    // Open for voting, or accepted but still in its holding period, so a
    // code that dies early can still be caught.
    return $wpdb->get_results($wpdb->prepare(
        "SELECT c.* FROM $contrib c
         WHERE c.object_type = 'wcd_coupon' AND c.author_id <> %d
           AND (c.state = 'voting'
                OR (c.state = 'accepted' AND EXISTS (
                    SELECT 1 FROM $ledger l WHERE l.contribution_id = c.id AND l.user_id = c.author_id
                      AND l.kind = 'contribution' AND l.status = 'pending')))
         ORDER BY (c.state = 'voting') DESC, c.id DESC
         LIMIT %d",
        (int) $user_id,
        (int) $limit
    ));
}

function vc_rewards_render_vote_card($c, $user_id) {
    $post_id  = (int) $c->object_id;
    $type     = vc_rewards_type($c->contrib_type);
    $verdicts = vc_rewards_verdicts($type['kind']);
    $voted    = vc_rewards_user_vote($c->id, $user_id);
    $terms    = get_the_terms($post_id, 'wcd_brand');
    $brand    = $terms && !is_wp_error($terms) ? $terms[0]->name : '';
    $expires  = (string) get_post_meta($post_id, '_wcd_expiration', true);
    $details  = get_post_field('post_content', $post_id);

    $buttons = '';
    foreach ($verdicts as $key => $v) {
        $buttons .= sprintf(
            '<button type="button" class="vc-rewards-vote" data-verdict="%s">%s</button>',
            esc_attr($key),
            esc_html($v['label'])
        );
    }

    $status = $c->state === 'accepted'
        ? __('Verified. Still working?', 'vc-rewards')
        : __('Needs verifying', 'vc-rewards');

    ob_start();
    ?>
    <article class="vc-rewards-card" data-contribution="<?php echo (int) $c->id; ?>">
        <header>
            <strong><?php echo esc_html($brand); ?></strong>
            <span class="vc-rewards-badge"><?php echo esc_html($status); ?></span>
        </header>
        <p class="vc-rewards-discount"><?php echo esc_html(get_post_meta($post_id, '_wcd_discount', true)); ?></p>
        <?php if ($details) : ?>
            <p class="vc-rewards-details"><?php echo esc_html(wp_trim_words($details, 40)); ?></p>
        <?php endif; ?>
        <?php if ($expires) : ?>
            <p class="vc-rewards-meta"><?php
                /* translators: %s: date */
                printf(esc_html__('Expires %s', 'vc-rewards'), esc_html(date_i18n(get_option('date_format'), strtotime($expires))));
            ?></p>
        <?php endif; ?>
        <?php if ($voted) : ?>
            <p class="vc-rewards-meta"><?php
                /* translators: %s: the member's vote */
                printf(esc_html__('You voted: %s', 'vc-rewards'), esc_html($verdicts[$voted]['label'] ?? $voted));
            ?></p>
        <?php else : ?>
            <div class="vc-rewards-reveal">
                <button type="button" class="vc-rewards-reveal-btn"><?php esc_html_e('Reveal code and try it', 'vc-rewards'); ?></button>
                <span class="vc-rewards-code" hidden></span>
            </div>
            <div class="vc-rewards-votes" hidden><?php echo $buttons; // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
            <p class="vc-rewards-result" role="status"></p>
        <?php endif; ?>
    </article>
    <?php
    return ob_get_clean();
}

add_shortcode('vc_verify_queue', function () {
    if (!is_user_logged_in()) {
        return vc_rewards_login_prompt();
    }
    $user_id = get_current_user_id();
    $error   = vc_rewards_participation_error($user_id);
    if ($error) {
        return vc_rewards_notice($error, false);
    }
    vc_rewards_enqueue();

    $items = vc_rewards_queue_items($user_id);
    if (!$items) {
        return '<p class="vc-rewards-empty">' . esc_html__('Nothing waiting to be verified right now. Check back soon.', 'vc-rewards') . '</p>';
    }

    $rank = vc_rewards_rank($user_id);
    $out  = '<p class="vc-rewards-intro">' . esc_html(sprintf(
        /* translators: 1: rank, 2: vote weight */
        __('You are a %1$s, so your vote counts %2$s. Reveal a code, try it at the store, then vote.', 'vc-rewards'),
        vc_rewards_rank_label($rank),
        vc_rewards_rank_weight($rank) > 0
            /* translators: %s: weight number */
            ? sprintf(__('x%s', 'vc-rewards'), vc_rewards_rank_weight($rank))
            : __('toward your own rank only until you reach Member', 'vc-rewards')
    )) . '</p>';

    $out .= '<div class="vc-rewards-queue">';
    foreach ($items as $c) {
        $out .= vc_rewards_render_vote_card($c, $user_id);
    }
    return $out . '</div>';
});

/* -------------------------------------------------------------------------
 * [vc_rewards_account]
 * ---------------------------------------------------------------------- */

function vc_rewards_state_label($state) {
    $labels = array(
        'held'      => __('Waiting for a moderator', 'vc-rewards'),
        'voting'    => __('Being verified', 'vc-rewards'),
        'accepted'  => __('Accepted', 'vc-rewards'),
        'rejected'  => __('Rejected', 'vc-rewards'),
        'withdrawn' => __('Stopped working', 'vc-rewards'),
    );
    return isset($labels[$state]) ? $labels[$state] : $state;
}

add_shortcode('vc_rewards_account', function () {
    global $wpdb;
    if (!is_user_logged_in()) {
        return vc_rewards_login_prompt();
    }
    vc_rewards_enqueue();
    $user_id = get_current_user_id();
    $out = '';

    $out .= vc_rewards_flash_notice(array('dob', 'redeem', 'appeal'));

    if (!vc_rewards_age_verified($user_id)) {
        $out .= '<form method="post" class="vc-rewards-form vc-rewards-dob">'
            . vc_rewards_form_fields('dob')
            . '<p>' . esc_html(sprintf(
                /* translators: %d: minimum age */
                __('Rewards are for members %d and older. Confirm your date of birth to start earning.', 'vc-rewards'),
                (int) vc_rewards_setting('min_age')
            )) . '</p>'
            . '<p><input type="date" name="vc_dob" required> <button type="submit">' . esc_html__('Confirm', 'vc-rewards') . '</button></p>'
            . '</form>';
    }

    $rank       = vc_rewards_rank($user_id);
    $available  = vc_rewards_available($user_id);
    $redeemable = vc_rewards_redeemable($user_id);

    $out .= '<section class="vc-rewards-summary"><dl>';
    $rows = array(
        __('Rank', 'vc-rewards')        => vc_rewards_rank_label($rank),
        __('Reputation', 'vc-rewards')  => number_format_i18n(vc_rewards_reputation($user_id)),
        __('Balance', 'vc-rewards')     => vc_rewards_format_points($available),
        __('Pending', 'vc-rewards')     => vc_rewards_format_points(vc_rewards_pending($user_id)),
        __('Can redeem', 'vc-rewards')  => vc_rewards_format_points($redeemable),
        __('Visit streak', 'vc-rewards') => sprintf(
            /* translators: %d: number of days */
            _n('%d day', '%d days', (int) get_user_meta($user_id, '_vc_streak', true), 'vc-rewards'),
            (int) get_user_meta($user_id, '_vc_streak', true)
        ),
    );
    foreach ($rows as $label => $value) {
        $out .= '<div><dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd></div>';
    }
    $out .= '</dl>';
    if ($redeemable < $available && !vc_rewards_has_accepted_contribution($user_id)) {
        $out .= '<p class="vc-rewards-meta">' . esc_html__('Daily visit and bonus points unlock for redemption once one of your posts is accepted.', 'vc-rewards') . '</p>';
    }
    $out .= '</section>';

    // Redemption.
    $min = (int) vc_rewards_setting('min_redemption');
    $out .= '<section class="vc-rewards-redeem"><h3>' . esc_html__('Redeem', 'vc-rewards') . '</h3>';
    if ($redeemable >= $min) {
        $choices = '';
        foreach (vc_rewards_reward_options() as $option) {
            $choices .= '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
        }
        $out .= '<form method="post" class="vc-rewards-form">' . vc_rewards_form_fields('redeem')
            . '<p><label>' . esc_html__('Reward', 'vc-rewards') . '<br><select name="vc_reward">' . $choices . '</select></label></p>'
            . '<p><label>' . esc_html__('Points', 'vc-rewards') . '<br><input type="number" name="vc_points" min="' . $min . '" max="' . (int) $redeemable . '" step="1" value="' . $min . '"></label></p>'
            . '<p><button type="submit">' . esc_html__('Request', 'vc-rewards') . '</button></p></form>';
    } else {
        $out .= '<p>' . esc_html(sprintf(
            /* translators: %s: minimum points */
            __('You can redeem once you have %s available.', 'vc-rewards'),
            vc_rewards_format_points($min)
        )) . '</p>';
    }
    $requests = vc_rewards_redemptions('', $user_id, 10);
    if ($requests) {
        $out .= '<ul class="vc-rewards-requests">';
        foreach ($requests as $r) {
            $out .= '<li>' . esc_html(sprintf('%s: %s (%s)', $r->reward, vc_rewards_format_points($r->points), $r->status)) . '</li>';
        }
        $out .= '</ul>';
    }
    $out .= '</section>';

    // My posts.
    $contrib = vc_rewards_table('contributions');
    $mine = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $contrib WHERE author_id = %d ORDER BY id DESC LIMIT 20",
        $user_id
    ));
    if ($mine) {
        $out .= '<section><h3>' . esc_html__('Your posts', 'vc-rewards') . '</h3><table class="vc-rewards-table"><tbody>';
        foreach ($mine as $c) {
            $action = '';
            if ($c->state === 'rejected' && $c->appeal === '') {
                $action = '<form method="post">' . vc_rewards_form_fields('appeal')
                    . '<input type="hidden" name="vc_contribution" value="' . (int) $c->id . '">'
                    . '<button type="submit" class="vc-rewards-link">' . esc_html__('Appeal', 'vc-rewards') . '</button></form>';
            } elseif ($c->appeal !== '') {
                /* translators: %s: appeal status */
                $action = esc_html(sprintf(__('Appeal %s', 'vc-rewards'), $c->appeal));
            }
            $out .= '<tr><td>' . esc_html(get_the_title($c->object_id)) . '</td><td>'
                . esc_html(vc_rewards_state_label($c->state)) . '</td><td>' . $action . '</td></tr>';
        }
        $out .= '</tbody></table></section>';
    }

    // History.
    $history = vc_rewards_ledger_history($user_id, 25);
    if ($history) {
        $out .= '<section><h3>' . esc_html__('Points history', 'vc-rewards') . '</h3><table class="vc-rewards-table"><tbody>';
        foreach ($history as $row) {
            $out .= '<tr class="is-' . esc_attr($row->status) . '"><td>' . esc_html(mysql2date(get_option('date_format'), get_date_from_gmt($row->created_at))) . '</td><td>'
                . esc_html($row->note ?: $row->kind) . '</td><td class="num">' . esc_html(($row->points > 0 ? '+' : '') . number_format_i18n($row->points)) . '</td><td>'
                . esc_html($row->status) . '</td></tr>';
        }
        $out .= '</tbody></table></section>';
    }

    return '<div class="vc-rewards-account">' . $out . '</div>';
});

/* -------------------------------------------------------------------------
 * [vc_rewards_leaderboard]
 * ---------------------------------------------------------------------- */

add_shortcode('vc_rewards_leaderboard', function ($atts) {
    $atts  = shortcode_atts(array('limit' => 10), $atts);
    $users = get_users(array(
        'meta_key' => '_vc_reputation',
        'orderby'  => 'meta_value_num',
        'order'    => 'DESC',
        'number'   => max(1, min(50, (int) $atts['limit'])),
        'meta_query' => array(array('key' => '_vc_reputation', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC')),
    ));
    if (!$users) {
        return '';
    }
    vc_rewards_enqueue();
    $out = '<ol class="vc-rewards-leaderboard">';
    foreach ($users as $u) {
        $out .= '<li><span>' . esc_html($u->display_name) . '</span> <span class="vc-rewards-badge">'
            . esc_html(vc_rewards_rank_label(vc_rewards_rank($u->ID))) . '</span> <span class="num">'
            . esc_html(number_format_i18n(vc_rewards_reputation($u->ID))) . '</span></li>';
    }
    return $out . '</ol>';
});
