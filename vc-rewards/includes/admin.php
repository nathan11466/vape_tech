<?php
/**
 * wp-admin screens: the moderation queue, redemptions, and settings.
 *
 * Moderators (admins, plus anyone ticked as a moderator on their profile)
 * see the queue and redemptions. Only admins change settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function () {
    if (!vc_rewards_is_moderator(get_current_user_id())) {
        return;
    }
    add_menu_page(__('Rewards', 'vc-rewards'), __('Rewards', 'vc-rewards'), 'read', 'vc-rewards', 'vc_rewards_queue_page', 'dashicons-awards', 58);
    add_submenu_page('vc-rewards', __('Moderation queue', 'vc-rewards'), __('Queue', 'vc-rewards'), 'read', 'vc-rewards', 'vc_rewards_queue_page');
    add_submenu_page('vc-rewards', __('Redemptions', 'vc-rewards'), __('Redemptions', 'vc-rewards'), 'read', 'vc-rewards-redemptions', 'vc_rewards_redemptions_page');
    do_action('vc_rewards_admin_menu');
    add_submenu_page('vc-rewards', __('Rewards settings', 'vc-rewards'), __('Settings', 'vc-rewards'), 'manage_options', 'vc-rewards-settings', 'vc_rewards_settings_page');
});

function vc_rewards_admin_url($page, array $args = array()) {
    return add_query_arg(array_merge(array('page' => $page), $args), admin_url('admin.php'));
}

function vc_rewards_action_button($op, $id, $label, array $extra = array(), $class = 'button') {
    $fields = '';
    foreach ($extra as $k => $v) {
        $fields .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
    }
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
        . '<input type="hidden" name="action" value="vc_rewards_mod">'
        . '<input type="hidden" name="op" value="' . esc_attr($op) . '">'
        . '<input type="hidden" name="id" value="' . (int) $id . '">'
        . wp_nonce_field('vc_rewards_mod', '_wpnonce', true, false)
        . $fields
        . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form> ';
}

function vc_rewards_user_link($user_id) {
    $user = get_userdata((int) $user_id);
    if (!$user) {
        return '#' . (int) $user_id;
    }
    return sprintf(
        '<a href="%s">%s</a> <span class="description">(%s, %d)</span>',
        esc_url(get_edit_user_link($user->ID)),
        esc_html($user->display_name),
        esc_html(vc_rewards_rank_label(vc_rewards_rank($user->ID))),
        (int) vc_rewards_reputation($user->ID)
    );
}

function vc_rewards_contribution_summary($c) {
    $info = vc_rewards_object_info($c);
    $type = vc_rewards_type($c->contrib_type);
    $title = $info['url']
        ? '<a href="' . esc_url($info['url']) . '" target="_blank">' . esc_html($info['title']) . '</a>'
        : esc_html($info['title']);
    $out = '<strong>' . $title . '</strong> <span class="description">' . esc_html($type ? $type['label'] : $c->contrib_type) . '</span>';
    if ($info['code'] !== '') {
        $out .= '<br><code>' . esc_html($info['code']) . '</code>';
    }
    if ($info['link'] !== '') {
        $out .= '<br><a href="' . esc_url($info['link']) . '" target="_blank" rel="noopener noreferrer">' . esc_html(wp_parse_url($info['link'], PHP_URL_HOST)) . '</a>';
    }
    if ($info['detail'] !== '' && $c->object_type !== 'wcd_coupon') {
        $out .= '<br><span class="description">' . esc_html(wp_trim_words($info['detail'], 25)) . '</span>';
    }
    return $out;
}

function vc_rewards_contributions_in($state, $limit = 50, $extra_sql = '') {
    global $wpdb;
    $table = vc_rewards_table('contributions');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE state = %s $extra_sql ORDER BY id ASC LIMIT %d",
        $state,
        (int) $limit
    ));
}

function vc_rewards_queue_page() {
    global $wpdb;
    if (!vc_rewards_is_moderator(get_current_user_id())) {
        wp_die(esc_html__('Moderators only.', 'vc-rewards'));
    }
    $notice = isset($_GET['vc_done']) ? sanitize_text_field(wp_unslash($_GET['vc_done'])) : '';
    echo '<div class="wrap"><h1>' . esc_html__('Moderation queue', 'vc-rewards') . '</h1>';
    if ($notice) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }

    // Held.
    $held = vc_rewards_contributions_in('held');
    echo '<h2>' . esc_html(sprintf(
        /* translators: %d: count */
        __('New-member posts waiting for review (%d)', 'vc-rewards'),
        count($held)
    )) . '</h2>';
    echo '<p class="description">' . esc_html__('Release sends a post to members for voting. Accept approves it outright and pays the author.', 'vc-rewards') . '</p>';
    vc_rewards_contribution_table($held, function ($c) {
        return vc_rewards_action_button('release', $c->id, __('Release to voting', 'vc-rewards'), array(), 'button button-primary')
            . vc_rewards_action_button('accept', $c->id, __('Accept', 'vc-rewards'))
            . vc_rewards_action_button('reject', $c->id, __('Reject as spam', 'vc-rewards'), array('reason' => 'spam'), 'button button-link-delete');
    });

    // Appeals.
    $appeals = vc_rewards_contributions_in('rejected', 50, "AND appeal = 'open'");
    if ($appeals) {
        echo '<h2>' . esc_html__('Appeals', 'vc-rewards') . '</h2>';
        vc_rewards_contribution_table($appeals, function ($c) {
            return vc_rewards_action_button('overturn', $c->id, __('Overturn and accept', 'vc-rewards'), array(), 'button button-primary')
                . vc_rewards_action_button('deny_appeal', $c->id, __('Keep rejected', 'vc-rewards'));
        });
    }

    // Corrections, point awards and anything else other files add.
    do_action('vc_rewards_queue_sections');

    // Voting.
    $votable = array();
    foreach (vc_rewards_setting('types') as $key => $type) {
        if (in_array($type['kind'], vc_rewards_votable_kinds(), true)) {
            $votable[] = esc_sql($key);
        }
    }
    $voting = vc_rewards_contributions_in('voting', 100, "AND contrib_type IN ('" . implode("','", $votable) . "')");
    echo '<h2>' . esc_html(sprintf(
        /* translators: %d: count */
        __('Being verified by members (%d)', 'vc-rewards'),
        count($voting)
    )) . '</h2>';
    vc_rewards_contribution_table($voting, function ($c) {
        $multiplier = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="vc_rewards_mod"><input type="hidden" name="op" value="multiplier">'
            . '<input type="hidden" name="id" value="' . (int) $c->id . '">'
            . wp_nonce_field('vc_rewards_mod', '_wpnonce', true, false)
            . '<select name="multiplier" onchange="this.form.submit()">';
        foreach (array('1.00' => 'x1', '1.50' => 'x1.5', '2.00' => 'x2') as $value => $label) {
            $multiplier .= '<option value="' . esc_attr($value) . '" ' . selected(number_format((float) $c->multiplier, 2), $value, false) . '>' . esc_html($label) . '</option>';
        }
        $multiplier .= '</select></form> ';
        return $multiplier
            . vc_rewards_action_button('accept', $c->id, __('Accept', 'vc-rewards'), array(), 'button button-primary')
            . vc_rewards_action_button('reject', $c->id, __("Didn't work", 'vc-rewards'), array('reason' => 'invalid'))
            . vc_rewards_action_button('reject', $c->id, __('Expired', 'vc-rewards'), array('reason' => 'expired'))
            . vc_rewards_action_button('reject', $c->id, __('Copied', 'vc-rewards'), array('reason' => 'copied'))
            . vc_rewards_action_button('reject', $c->id, __('Fake or spam', 'vc-rewards'), array('reason' => 'fake'), 'button button-link-delete');
    }, true);

    // Flagged votes.
    $flagged = vc_rewards_flagged_votes(30);
    echo '<h2>' . esc_html__('Votes that counted for nothing', 'vc-rewards') . '</h2>';
    echo '<p class="description">' . esc_html__('Same IP as the author, or two members who keep voting for each other. These votes were recorded with zero weight; check the accounts if a name keeps appearing.', 'vc-rewards') . '</p>';
    if (!$flagged) {
        echo '<p>' . esc_html__('None.', 'vc-rewards') . '</p>';
    } else {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Voter', 'vc-rewards') . '</th><th>' . esc_html__('Author', 'vc-rewards') . '</th><th>' . esc_html__('Why', 'vc-rewards') . '</th><th>' . esc_html__('When', 'vc-rewards') . '</th></tr></thead><tbody>';
        foreach ($flagged as $v) {
            echo '<tr><td>' . vc_rewards_user_link($v->voter_id) . '</td><td>' . vc_rewards_user_link($v->author_id) . '</td><td>' // phpcs:ignore WordPress.Security.EscapeOutput
                . esc_html($v->flags) . '</td><td>' . esc_html(get_date_from_gmt($v->created_at)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}

function vc_rewards_contribution_table(array $rows, callable $actions, $with_score = false) {
    if (!$rows) {
        echo '<p>' . esc_html__('Nothing here.', 'vc-rewards') . '</p>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Post', 'vc-rewards') . '</th><th>' . esc_html__('Author', 'vc-rewards') . '</th>';
    if ($with_score) {
        echo '<th>' . esc_html__('Score', 'vc-rewards') . '</th>';
    }
    echo '<th>' . esc_html__('Submitted', 'vc-rewards') . '</th><th>' . esc_html__('Actions', 'vc-rewards') . '</th></tr></thead><tbody>';
    foreach ($rows as $c) {
        echo '<tr><td>' . vc_rewards_contribution_summary($c) . '</td><td>' . vc_rewards_user_link($c->author_id) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
        if ($with_score) {
            $type = vc_rewards_type($c->contrib_type);
            echo '<td>' . esc_html(sprintf('%s / %s', rtrim(rtrim($c->score, '0'), '.') ?: '0', $type['threshold'])) . '</td>';
        }
        echo '<td>' . esc_html(get_date_from_gmt($c->created_at)) . '</td><td>' . $actions($c) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    echo '</tbody></table>';
}

add_action('admin_post_vc_rewards_mod', function () {
    $user_id = get_current_user_id();
    if (!vc_rewards_is_moderator($user_id) || !check_admin_referer('vc_rewards_mod')) {
        wp_die(esc_html__('Moderators only.', 'vc-rewards'));
    }
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $page = 'vc-rewards';
    $done = __('Done.', 'vc-rewards');

    switch ($op) {
        case 'release':
            vc_rewards_release($id);
            $done = __('Released to voting.', 'vc-rewards');
            break;
        case 'accept':
            vc_rewards_moderator_accept($id);
            $done = __('Accepted. The author reward is pending.', 'vc-rewards');
            break;
        case 'reject':
            $reason = isset($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : 'invalid';
            if (!in_array($reason, array('invalid', 'expired', 'fake', 'spam', 'copied', 'declined'), true)) {
                $reason = 'invalid';
            }
            vc_rewards_reject($id, $reason);
            $done = __('Rejected.', 'vc-rewards');
            break;
        case 'multiplier':
            vc_rewards_set_multiplier($id, isset($_POST['multiplier']) ? (float) $_POST['multiplier'] : 1);
            $done = __('Multiplier saved.', 'vc-rewards');
            break;
        case 'overturn':
            vc_rewards_overturn($id);
            $done = __('Rejection overturned and penalty refunded.', 'vc-rewards');
            break;
        case 'deny_appeal':
            vc_rewards_deny_appeal($id);
            $done = __('Appeal closed.', 'vc-rewards');
            break;
        default:
            // Operations added by other files return array(page, message).
            $result = apply_filters('vc_rewards_mod_op', null, $op, $id, $user_id);
            if (is_array($result)) {
                list($page, $done) = $result;
            }
            break;
        case 'redeem_approve':
        case 'redeem_reject':
            $note = isset($_POST['note']) ? sanitize_text_field(wp_unslash($_POST['note'])) : '';
            vc_rewards_decide_redemption($id, $op === 'redeem_approve', $user_id, $note);
            $page = 'vc-rewards-redemptions';
            $done = $op === 'redeem_approve' ? __('Redemption approved.', 'vc-rewards') : __('Redemption rejected; points returned.', 'vc-rewards');
            break;
    }

    wp_safe_redirect(vc_rewards_admin_url($page, array('vc_done' => rawurlencode($done))));
    exit;
});

function vc_rewards_redemptions_page() {
    if (!vc_rewards_is_moderator(get_current_user_id())) {
        wp_die(esc_html__('Moderators only.', 'vc-rewards'));
    }
    $notice = isset($_GET['vc_done']) ? sanitize_text_field(wp_unslash($_GET['vc_done'])) : '';
    echo '<div class="wrap"><h1>' . esc_html__('Redemptions', 'vc-rewards') . '</h1>';
    if ($notice) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
    echo '<p class="description">' . esc_html__('Every redemption waits here. Approving records it as paid; send the reward itself by hand and note what you sent. Rejecting returns the points.', 'vc-rewards') . '</p>';

    $open = vc_rewards_redemptions('requested');
    if (!$open) {
        echo '<p>' . esc_html__('No requests waiting.', 'vc-rewards') . '</p></div>';
        return;
    }
    echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Member', 'vc-rewards') . '</th><th>' . esc_html__('Request', 'vc-rewards') . '</th><th>' . esc_html__('Checks', 'vc-rewards') . '</th><th>' . esc_html__('Decision', 'vc-rewards') . '</th></tr></thead><tbody>';
    foreach ($open as $r) {
        $s = vc_rewards_review_summary($r->user_id);
        $warnings = array();
        if ($s['visit_share'] > 0.5) {
            $warnings[] = __('Most points came from daily visits', 'vc-rewards');
        }
        if ($s['flags_as_voter'] || $s['flags_on_posts']) {
            $warnings[] = __('Has flagged votes', 'vc-rewards');
        }
        if ($s['rejected'] > $s['accepted']) {
            $warnings[] = __('More rejected posts than accepted', 'vc-rewards');
        }
        $checks = sprintf(
            /* translators: 1: date, 2: accepted, 3: rejected, 4: percent, 5: flagged votes cast, 6: flagged votes received */
            esc_html__('Joined %1$s. Posts: %2$d accepted, %3$d rejected. %4$d%% of points from visits. Flagged votes: %5$d cast, %6$d on their posts.', 'vc-rewards'),
            esc_html(mysql2date(get_option('date_format'), $s['registered'])),
            $s['accepted'],
            $s['rejected'],
            (int) round($s['visit_share'] * 100),
            $s['flags_as_voter'],
            $s['flags_on_posts']
        );
        if ($warnings) {
            $checks .= '<br><strong style="color:#b32d2e">' . esc_html(implode('. ', $warnings)) . '.</strong>';
        }
        $decision = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="vc_rewards_mod"><input type="hidden" name="id" value="' . (int) $r->id . '">'
            . wp_nonce_field('vc_rewards_mod', '_wpnonce', true, false)
            . '<input type="text" name="note" placeholder="' . esc_attr__('What you sent, or why not', 'vc-rewards') . '" class="regular-text"><br>'
            . '<button type="submit" name="op" value="redeem_approve" class="button button-primary">' . esc_html__('Approve', 'vc-rewards') . '</button> '
            . '<button type="submit" name="op" value="redeem_reject" class="button">' . esc_html__('Reject', 'vc-rewards') . '</button></form>';

        echo '<tr><td>' . vc_rewards_user_link($r->user_id) . '<br>' // phpcs:ignore WordPress.Security.EscapeOutput
            . esc_html(sprintf(__('Balance %s', 'vc-rewards'), vc_rewards_format_points($s['balance']))) . '</td>'
            . '<td>' . esc_html($r->reward) . '<br>' . esc_html(vc_rewards_format_points($r->points)) . '<br><span class="description">' . esc_html(get_date_from_gmt($r->created_at)) . '</span></td>'
            . '<td>' . $checks . '</td><td>' . $decision . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
    echo '</tbody></table></div>';
}

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

/** Flat list of editable settings: key path => label. */
function vc_rewards_settings_fields() {
    $fields = array(
        'login_points'        => __('Daily visit points', 'vc-rewards'),
        'streak_days'         => __('Streak length (days)', 'vc-rewards'),
        'streak_bonus'        => __('Streak bonus points', 'vc-rewards'),
        'onetime_bonus'       => __('One-time bonus (age check, first login)', 'vc-rewards'),
        'rankup_bonus.member'  => __('Rank-up bonus: Member', 'vc-rewards'),
        'rankup_bonus.trusted' => __('Rank-up bonus: Trusted', 'vc-rewards'),
        'rankup_bonus.expert'  => __('Rank-up bonus: Expert', 'vc-rewards'),
        'points_per_weight'   => __('Author bonus per weight point of positive votes', 'vc-rewards'),
        'vote_bonus_cap'      => __('Author vote bonus cap', 'vc-rewards'),
        'voter_points_per_weight' => __('Voter points per weight point', 'vc-rewards'),
        'daily_earn_cap'      => __('Daily earning cap (posts and votes)', 'vc-rewards'),
        'balance_floor'       => __('Lowest balance a penalty can reach', 'vc-rewards'),
        'min_redemption'      => __('Minimum redemption', 'vc-rewards'),
        'hold_first_posts'    => __('Posts reviewed by hand for new accounts', 'vc-rewards'),
        'min_age'             => __('Minimum age', 'vc-rewards'),
        'forum_min_words.review' => __('Forum review: minimum words', 'vc-rewards'),
        'forum_min_words.guide'  => __('Forum guide: minimum words', 'vc-rewards'),
        'forum_min_words.store_report' => __('Forum store report: minimum words', 'vc-rewards'),
        'first_post_bonus'    => __('Bonus for the first verified post of a deal', 'vc-rewards'),
        'award_points.proof'  => __('Award: proof photo', 'vc-rewards'),
        'award_points.spam_report' => __('Award: confirmed spam report', 'vc-rewards'),
        'award_max'           => __('Largest custom award', 'vc-rewards'),
        'referral_bonus'      => __('Referral bonus (paid when the new member qualifies)', 'vc-rewards'),
        'referral_monthly_cap' => __('Referral bonuses per member per month', 'vc-rewards'),
        'cashback_percent'    => __('Cashback: percent of order value paid as points', 'vc-rewards'),
        'answer_pair_days'    => __('Accepted answers: days before the same asker can reward the same member again', 'vc-rewards'),
        'forum_max_links'     => __('Forum: outbound links before a moderator checks first', 'vc-rewards'),
    );
    foreach (vc_rewards_type_defaults() as $key => $type) {
        $fields['types.' . $key . '.base_points']  = sprintf(__('%s: base points', 'vc-rewards'), $type['label']);
        if (!in_array($type['kind'], vc_rewards_votable_kinds(), true)) {
            $fields['types.' . $key . '.holding_days'] = sprintf(__('%s: holding days', 'vc-rewards'), $type['label']);
            continue;
        }
        $fields['types.' . $key . '.threshold']    = sprintf(__('%s: weighted score to accept', 'vc-rewards'), $type['label']);
        $fields['types.' . $key . '.min_voters']   = sprintf(__('%s: minimum voters', 'vc-rewards'), $type['label']);
        $fields['types.' . $key . '.holding_days'] = sprintf(__('%s: holding days', 'vc-rewards'), $type['label']);
    }
    return $fields;
}

/** Text settings: key => array(label, 'text' or 'textarea', help). */
function vc_rewards_text_settings() {
    return array(
        'reward_options'  => array(__('Rewards members can request (one per line)', 'vc-rewards'), 'textarea', ''),
        'follow_channels' => array(__('Follow bonus channels (one per line: Name | URL)', 'vc-rewards'), 'textarea', __('Members get a one-time bonus per channel. It is not checked, so it only becomes redeemable after a post is accepted.', 'vc-rewards')),
        'subid_param'     => array(__('Affiliate sub-ID parameter', 'vc-rewards'), 'text', __('Added to store links for logged-in members, so the network report says who bought (for example subid, u1 or sid). Leave blank to turn off.', 'vc-rewards')),
    );
}

function vc_rewards_get_path(array $array, $path) {
    foreach (explode('.', $path) as $key) {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            return null;
        }
        $array = $array[$key];
    }
    return $array;
}

function vc_rewards_set_path(array &$array, $path, $value) {
    $ref = &$array;
    foreach (explode('.', $path) as $key) {
        if (!isset($ref[$key]) || !is_array($ref[$key])) {
            $ref[$key] = isset($ref[$key]) && is_array($ref[$key]) ? $ref[$key] : array();
        }
        $ref = &$ref[$key];
    }
    $ref = $value;
}

function vc_rewards_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Admins only.', 'vc-rewards'));
    }
    $settings = vc_rewards_settings();

    if (isset($_POST['vc_rewards_settings_nonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vc_rewards_settings_nonce'])), 'vc_rewards_settings')) {
        $saved = get_option(VC_REWARDS_OPTION, array());
        $saved = is_array($saved) ? $saved : array();
        $input = isset($_POST['vc']) && is_array($_POST['vc']) ? wp_unslash($_POST['vc']) : array();
        foreach (vc_rewards_settings_fields() as $path => $label) {
            $key = str_replace('.', '__', $path);
            if (isset($input[$key]) && is_numeric($input[$key])) {
                vc_rewards_set_path($saved, $path, (int) $input[$key]);
            }
        }
        $saved['moderator_can_accept_alone'] = empty($input['moderator_can_accept_alone']) ? 0 : 1;
        foreach (array_keys(vc_rewards_defaults()['forum_types']) as $type) {
            if (isset($input['forum_types'][$type])) {
                $ids = array_filter(array_map('absint', preg_split('/[\s,]+/', (string) $input['forum_types'][$type])));
                $saved['forum_types'][$type] = implode(',', $ids);
            }
        }
        foreach (vc_rewards_text_settings() as $key => $field) {
            if (isset($input[$key])) {
                $saved[$key] = $field[1] === 'textarea' ? sanitize_textarea_field($input[$key]) : sanitize_text_field($input[$key]);
            }
        }
        update_option(VC_REWARDS_OPTION, $saved);
        $settings = vc_rewards_merge(vc_rewards_defaults(), $saved);
        echo '<div class="notice notice-success"><p>' . esc_html__('Settings saved.', 'vc-rewards') . '</p></div>';
    }

    echo '<div class="wrap"><h1>' . esc_html__('Rewards settings', 'vc-rewards') . '</h1>';
    echo '<p class="description">' . esc_html__('25 points = 1 cent, so 2,500 points = $1.', 'vc-rewards') . '</p>';
    echo '<form method="post">';
    wp_nonce_field('vc_rewards_settings', 'vc_rewards_settings_nonce');
    echo '<table class="form-table" role="presentation">';
    foreach (vc_rewards_settings_fields() as $path => $label) {
        $key = str_replace('.', '__', $path);
        echo '<tr><th><label for="vc_' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'
            . '<input type="number" step="1" id="vc_' . esc_attr($key) . '" name="vc[' . esc_attr($key) . ']" value="' . esc_attr(vc_rewards_get_path($settings, $path)) . '" class="small-text"></td></tr>';
    }
    echo '<tr><th>' . esc_html__('Early days', 'vc-rewards') . '</th><td><label><input type="checkbox" name="vc[moderator_can_accept_alone]" value="1" ' . checked(!empty($settings['moderator_can_accept_alone']), true, false) . '> '
        . esc_html__("A moderator's vote alone can accept or reject a post (turn off once there are enough members voting)", 'vc-rewards') . '</label></td></tr>';
    foreach ($settings['forum_types'] as $type => $ids) {
        $info = vc_rewards_type($type);
        /* translators: %s: contribution type */
        echo '<tr><th><label for="vc_forum_' . esc_attr($type) . '">' . esc_html(sprintf(__('wpForo forum IDs for: %s', 'vc-rewards'), $info ? $info['label'] : $type)) . '</label></th><td>'
            . '<input type="text" id="vc_forum_' . esc_attr($type) . '" name="vc[forum_types][' . esc_attr($type) . ']" value="' . esc_attr($ids) . '" class="regular-text" placeholder="3, 7"></td></tr>';
    }
    foreach (vc_rewards_text_settings() as $key => $field) {
        $input = $field[1] === 'textarea'
            ? '<textarea id="vc_' . esc_attr($key) . '" name="vc[' . esc_attr($key) . ']" rows="5" class="large-text">' . esc_textarea($settings[$key]) . '</textarea>'
            : '<input type="text" id="vc_' . esc_attr($key) . '" name="vc[' . esc_attr($key) . ']" value="' . esc_attr($settings[$key]) . '" class="regular-text">';
        echo '<tr><th><label for="vc_' . esc_attr($key) . '">' . esc_html($field[0]) . '</label></th><td>' . $input; // phpcs:ignore WordPress.Security.EscapeOutput
        if (!empty($field[2])) {
            echo '<p class="description">' . esc_html($field[2]) . '</p>';
        }
        echo '</td></tr>';
    }
    echo '</table>';
    submit_button();
    echo '</form></div>';
}
