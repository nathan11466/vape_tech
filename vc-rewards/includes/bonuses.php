<?php
/**
 * Smaller ways to earn:
 *
 *   first_post_bonus  the first deal find for a given store page to be
 *                     verified earns extra (stops reposting a known deal
 *                     from paying as well as finding it)
 *   awards            a moderator awards points for a proof photo or a
 *                     confirmed spam report, or a custom amount
 *   profile           one-time bonus for filling in the profile bio
 *   follow            one-time bonus per channel listed in settings. It
 *                     can't be checked, so it is not redeemable until the
 *                     member has a post accepted (like visit points)
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * First to post a deal
 * ---------------------------------------------------------------------- */

/**
 * Same store page = same key: host without www, path without trailing slash.
 * Query strings and fragments are ignored, so tracking tags don't make a
 * repost look new.
 */
function vc_rewards_link_key($url) {
    $parts = wp_parse_url((string) $url);
    if (empty($parts['host'])) {
        return '';
    }
    $host = preg_replace('/^www\./', '', strtolower($parts['host']));
    $path = isset($parts['path']) ? rtrim(strtolower($parts['path']), '/') : '';
    return md5($host . $path);
}

add_action('vc_rewards_contribution_state', function ($contribution_id, $state) {
    global $wpdb;
    if ($state !== 'accepted') {
        return;
    }
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c || $c->contrib_type !== 'deal' || $c->link_key === '') {
        return;
    }
    $table = vc_rewards_table('contributions');
    $first = !$wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM $table WHERE link_key = %s AND id <> %d AND state IN ('accepted', 'withdrawn') LIMIT 1",
        $c->link_key,
        (int) $c->id
    ));
    $points = (int) vc_rewards_setting('first_post_bonus');
    if ($first && $points > 0) {
        // Kind 'contribution', so it is clawed back with the post's reward.
        vc_rewards_ledger_add($c->author_id, $points, 'contribution', array(
            'settle_days'     => vc_rewards_type('deal')['holding_days'],
            'contribution_id' => $c->id,
            'note'            => __('First to post this deal', 'vc-rewards'),
        ));
    }
}, 20, 2);

/* -------------------------------------------------------------------------
 * Moderator awards
 * ---------------------------------------------------------------------- */

function vc_rewards_award_reasons() {
    return array(
        'proof'       => __('Proof photo (receipt or unboxing)', 'vc-rewards'),
        'spam_report' => __('Confirmed spam report', 'vc-rewards'),
        'custom'      => __('Other', 'vc-rewards'),
    );
}

/**
 * Returns array('ok', 'message').
 */
function vc_rewards_award($moderator_id, $user, $reason, $points = 0, $note = '') {
    $user_obj = is_numeric($user) ? get_userdata((int) $user) : get_user_by('login', (string) $user);
    if (!$user_obj) {
        $user_obj = get_user_by('email', (string) $user);
    }
    if (!$user_obj) {
        return array('ok' => false, 'message' => __('No member with that username or email.', 'vc-rewards'));
    }
    if ((int) $user_obj->ID === (int) $moderator_id) {
        return array('ok' => false, 'message' => __("You can't award points to yourself.", 'vc-rewards'));
    }
    $reasons = vc_rewards_award_reasons();
    if (!isset($reasons[$reason])) {
        return array('ok' => false, 'message' => __('Choose a reason.', 'vc-rewards'));
    }
    $preset = vc_rewards_setting('award_points');
    if ($reason !== 'custom') {
        $points = isset($preset[$reason]) ? (int) $preset[$reason] : 0;
    }
    $points = (int) $points;
    if ($points <= 0 || $points > (int) vc_rewards_setting('award_max')) {
        return array('ok' => false, 'message' => sprintf(
            /* translators: %s: maximum points */
            __('Awards must be between 1 and %s points.', 'vc-rewards'),
            number_format_i18n((int) vc_rewards_setting('award_max'))
        ));
    }
    $mod  = get_userdata((int) $moderator_id);
    $text = $reasons[$reason] . ($note !== '' ? ': ' . $note : '');
    vc_rewards_ledger_add($user_obj->ID, $points, 'award', array(
        'settle_days' => 7,
        /* translators: 1: reason, 2: moderator name */
        'note'        => sprintf(__('%1$s (from %2$s)', 'vc-rewards'), $text, $mod ? $mod->display_name : '#' . (int) $moderator_id),
    ));
    return array('ok' => true, 'message' => sprintf(
        /* translators: 1: points, 2: member name */
        __('Awarded %1$s to %2$s. It settles in 7 days.', 'vc-rewards'),
        vc_rewards_format_points($points),
        $user_obj->display_name
    ));
}

add_action('vc_rewards_queue_sections', function () {
    echo '<h2>' . esc_html__('Award points', 'vc-rewards') . '</h2>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
        . '<input type="hidden" name="action" value="vc_rewards_mod"><input type="hidden" name="op" value="award">'
        . wp_nonce_field('vc_rewards_mod', '_wpnonce', true, false)
        . '<input type="text" name="award_user" placeholder="' . esc_attr__('Username or email', 'vc-rewards') . '" required> ';
    echo '<select name="award_reason">';
    foreach (vc_rewards_award_reasons() as $key => $label) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    echo '</select> <input type="number" name="award_points" min="1" max="' . (int) vc_rewards_setting('award_max') . '" placeholder="' . esc_attr__('Points (Other only)', 'vc-rewards') . '" class="small-text" style="width:9em"> '
        . '<input type="text" name="award_note" placeholder="' . esc_attr__('Note, e.g. which post', 'vc-rewards') . '" class="regular-text"> '
        . '<button type="submit" class="button">' . esc_html__('Award', 'vc-rewards') . '</button></form>';
}, 30);

add_filter('vc_rewards_mod_op', function ($result, $op, $id, $user_id) {
    if ($op !== 'award') {
        return $result;
    }
    $r = vc_rewards_award(
        $user_id,
        isset($_POST['award_user']) ? sanitize_text_field(wp_unslash($_POST['award_user'])) : '',
        isset($_POST['award_reason']) ? sanitize_key(wp_unslash($_POST['award_reason'])) : '',
        isset($_POST['award_points']) ? (int) $_POST['award_points'] : 0,
        isset($_POST['award_note']) ? sanitize_text_field(wp_unslash($_POST['award_note'])) : ''
    );
    return array('vc-rewards', $r['message']);
}, 10, 4);

/* -------------------------------------------------------------------------
 * Profile and follow bonuses
 * ---------------------------------------------------------------------- */

function vc_rewards_maybe_profile_bonus($user_id) {
    $bio = trim(wp_strip_all_tags((string) get_user_meta((int) $user_id, 'description', true)));
    if (mb_strlen($bio) >= 30 && vc_rewards_age_verified($user_id)) {
        return vc_rewards_onetime_bonus((int) $user_id, 'profile', __('Completed profile', 'vc-rewards'));
    }
    return false;
}
add_action('profile_update', 'vc_rewards_maybe_profile_bonus');
add_action('vc_rewards_daily_visit', 'vc_rewards_maybe_profile_bonus');

/** Channels from settings: key => array(name, url). */
function vc_rewards_follow_channels() {
    $channels = array();
    foreach (preg_split('/\r\n|\r|\n/', (string) vc_rewards_setting('follow_channels')) as $line) {
        $parts = array_map('trim', explode('|', $line, 2));
        if (count($parts) === 2 && $parts[0] !== '' && preg_match('#^https?://#i', $parts[1]) && filter_var($parts[1], FILTER_VALIDATE_URL)) {
            $channels['follow_' . substr(md5($parts[1]), 0, 10)] = array($parts[0], $parts[1]);
        }
    }
    return $channels;
}

function vc_rewards_follow($user_id, $key) {
    $channels = vc_rewards_follow_channels();
    if (!isset($channels[$key]) || vc_rewards_participation_error($user_id) !== '') {
        return null;
    }
    /* translators: %s: channel name */
    vc_rewards_onetime_bonus((int) $user_id, $key, sprintf(__('Followed %s', 'vc-rewards'), $channels[$key][0]));
    return $channels[$key][1];
}

add_filter('vc_rewards_process_form', function ($result, $action, $user_id, $posted) {
    if ($action !== 'follow') {
        return $result;
    }
    $url = vc_rewards_follow($user_id, isset($posted['vc_channel']) ? sanitize_key($posted['vc_channel']) : '');
    if (!$url) {
        return array('action' => $action, 'ok' => false, 'message' => __('That channel is not available.', 'vc-rewards'));
    }
    return array('action' => $action, 'ok' => true, 'message' => __('Thanks for following!', 'vc-rewards'), 'redirect' => $url);
}, 10, 4);

add_filter('vc_rewards_account_sections', function ($out, $user_id) {
    $channels = vc_rewards_follow_channels();
    if (!$channels) {
        return $out;
    }
    $done = get_user_meta($user_id, '_vc_onetime', true);
    $done = is_array($done) ? $done : array();
    $out .= '<section class="vc-rewards-follow"><h3>' . esc_html__('Follow us', 'vc-rewards') . '</h3><p>' . esc_html(sprintf(
        /* translators: %s: points */
        __('%s once for each channel.', 'vc-rewards'),
        vc_rewards_format_points((int) vc_rewards_setting('onetime_bonus'))
    )) . '</p><ul>';
    foreach ($channels as $key => $channel) {
        $out .= '<li>';
        if (in_array($key, $done, true)) {
            $out .= '<a href="' . esc_url($channel[1]) . '" target="_blank" rel="noopener">' . esc_html($channel[0]) . '</a> ' . esc_html__('(done)', 'vc-rewards');
        } else {
            $out .= '<form method="post" class="vc-rewards-inline">' . vc_rewards_form_fields('follow')
                . '<input type="hidden" name="vc_channel" value="' . esc_attr($key) . '">'
                . '<button type="submit" class="vc-rewards-link">' . esc_html($channel[0]) . '</button></form>';
        }
        $out .= '</li>';
    }
    return $out . '</ul></section>';
}, 30, 2);
