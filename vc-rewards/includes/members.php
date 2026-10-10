<?php
/**
 * Members: age check at signup, daily visit points, and the moderator and
 * pause switches on each user's profile in wp-admin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------------------------------------------------------
 * Age check
 *
 * Self-declared date of birth. It keeps under-age visitors out of rewards;
 * it is not ID verification, which belongs to a dedicated age-verification
 * plugin if the site needs it.
 * ---------------------------------------------------------------------- */

function vc_rewards_age_from_dob($dob) {
    $stamp = strtotime((string) $dob);
    if (!$stamp || $stamp > time()) {
        return -1;
    }
    $birth = new DateTime(gmdate('Y-m-d', $stamp));
    return (int) $birth->diff(new DateTime(gmdate('Y-m-d')))->y;
}

/** Returns '' when the date is acceptable, otherwise the error to show. */
function vc_rewards_dob_error($dob) {
    $age = vc_rewards_age_from_dob($dob);
    if ($age < 0) {
        return __('Enter your date of birth.', 'vc-rewards');
    }
    $min = (int) vc_rewards_setting('min_age');
    if ($age < $min) {
        /* translators: %d: minimum age */
        return sprintf(__('You must be %d or older to join.', 'vc-rewards'), $min);
    }
    return '';
}

function vc_rewards_set_dob($user_id, $dob) {
    if (vc_rewards_age_verified($user_id) || vc_rewards_dob_error($dob) !== '') {
        return false;
    }
    update_user_meta($user_id, '_vc_dob', gmdate('Y-m-d', strtotime($dob)));
    update_user_meta($user_id, '_vc_age_verified', 1);
    vc_rewards_onetime_bonus($user_id, 'age', __('Confirmed age', 'vc-rewards'));
    vc_rewards_check_rankup($user_id);
    return true;
}

add_action('register_form', function () {
    $dob = isset($_POST['vc_dob']) ? sanitize_text_field(wp_unslash($_POST['vc_dob'])) : '';
    printf(
        '<p><label for="vc_dob">%s<br><input type="date" name="vc_dob" id="vc_dob" class="input" value="%s" required></label></p>',
        esc_html__('Date of birth', 'vc-rewards'),
        esc_attr($dob)
    );
});

add_filter('registration_errors', function ($errors) {
    $dob   = isset($_POST['vc_dob']) ? sanitize_text_field(wp_unslash($_POST['vc_dob'])) : '';
    $error = vc_rewards_dob_error($dob);
    if ($error) {
        $errors->add('vc_dob', $error);
    }
    return $errors;
});

add_action('user_register', function ($user_id) {
    if (!empty($_POST['vc_dob'])) {
        vc_rewards_set_dob($user_id, sanitize_text_field(wp_unslash($_POST['vc_dob'])));
    }
});

/* -------------------------------------------------------------------------
 * One-time and daily points
 * ---------------------------------------------------------------------- */

function vc_rewards_onetime_bonus($user_id, $key, $note) {
    $done = get_user_meta($user_id, '_vc_onetime', true);
    $done = is_array($done) ? $done : array();
    if (in_array($key, $done, true)) {
        return;
    }
    $done[] = $key;
    update_user_meta($user_id, '_vc_onetime', $done);
    vc_rewards_ledger_add($user_id, (int) vc_rewards_setting('onetime_bonus'), 'onetime', array(
        'status' => 'settled',
        'note'   => $note,
    ));
}

/**
 * Logging in proves the member opened the password email WordPress sends at
 * signup, which is as much email confirmation as core provides.
 */
add_action('wp_login', function ($login, $user) {
    if (!get_user_meta($user->ID, '_vc_email_confirmed', true)) {
        update_user_meta($user->ID, '_vc_email_confirmed', 1);
        vc_rewards_onetime_bonus($user->ID, 'email', __('Confirmed email', 'vc-rewards'));
        vc_rewards_check_rankup($user->ID);
    }
    vc_rewards_daily_visit($user->ID);
}, 10, 2);

// Members who stay logged in never pass through wp_login again, so the daily
// points are paid on their first page view of the day instead.
add_action('init', function () {
    if (is_user_logged_in() && !wp_doing_cron()) {
        vc_rewards_daily_visit(get_current_user_id());
    }
});

/**
 * Once per site-local day: login points, plus a bonus every Nth day in a row.
 * Never reputation, and not redeemable until the member has an accepted post.
 */
function vc_rewards_daily_visit($user_id, $today = null) {
    $today = $today ?: current_time('Y-m-d');
    $last  = (string) get_user_meta($user_id, '_vc_last_visit', true);
    if ($last === $today || vc_rewards_is_banned($user_id)) {
        return false;
    }
    update_user_meta($user_id, '_vc_last_visit', $today);

    $yesterday = gmdate('Y-m-d', strtotime($today . ' -1 day'));
    $streak    = $last === $yesterday ? (int) get_user_meta($user_id, '_vc_streak', true) + 1 : 1;
    update_user_meta($user_id, '_vc_streak', $streak);

    vc_rewards_ledger_add($user_id, (int) vc_rewards_setting('login_points'), 'login', array(
        'status' => 'settled',
        'note'   => __('Daily visit', 'vc-rewards'),
    ));

    $every = (int) vc_rewards_setting('streak_days');
    if ($every > 0 && $streak % $every === 0) {
        vc_rewards_ledger_add($user_id, (int) vc_rewards_setting('streak_bonus'), 'streak', array(
            'status' => 'settled',
            /* translators: %d: days in a row */
            'note'   => sprintf(__('%d-day streak', 'vc-rewards'), $streak),
        ));
    }
    return true;
}

/* -------------------------------------------------------------------------
 * Profile switches for admins
 * ---------------------------------------------------------------------- */

function vc_rewards_profile_fields($user) {
    if (!current_user_can('manage_options')) {
        return;
    }
    wp_nonce_field('vc_rewards_profile', 'vc_rewards_profile_nonce');
    ?>
    <h2><?php esc_html_e('Community rewards', 'vc-rewards'); ?></h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><?php esc_html_e('Standing', 'vc-rewards'); ?></th>
            <td>
                <?php
                printf(
                    /* translators: 1: rank, 2: reputation, 3: balance */
                    esc_html__('%1$s, reputation %2$d, balance %3$s', 'vc-rewards'),
                    esc_html(vc_rewards_rank_label(vc_rewards_rank($user->ID))),
                    (int) vc_rewards_reputation($user->ID),
                    esc_html(vc_rewards_format_points(vc_rewards_balance($user->ID)))
                );
                ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e('Moderator', 'vc-rewards'); ?></th>
            <td><label><input type="checkbox" name="vc_moderator" value="1" <?php checked((bool) get_user_meta($user->ID, '_vc_moderator', true)); ?>>
                <?php esc_html_e('Can approve and reject posts, and votes count at moderator weight', 'vc-rewards'); ?></label></td>
        </tr>
        <tr>
            <th><?php esc_html_e('Paused', 'vc-rewards'); ?></th>
            <td><label><input type="checkbox" name="vc_banned" value="1" <?php checked(vc_rewards_is_banned($user->ID)); ?>>
                <?php esc_html_e('Cannot post, vote, earn or redeem', 'vc-rewards'); ?></label></td>
        </tr>
        <tr>
            <th><?php esc_html_e('New-account review', 'vc-rewards'); ?></th>
            <td><label><input type="checkbox" name="vc_skip_hold" value="1" <?php checked(!vc_rewards_under_review($user->ID)); ?>>
                <?php esc_html_e('Posts go straight to voting (untick to hold them for review again)', 'vc-rewards'); ?></label></td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', 'vc_rewards_profile_fields');
add_action('edit_user_profile', 'vc_rewards_profile_fields');

function vc_rewards_save_profile_fields($user_id) {
    if (!current_user_can('manage_options')
        || empty($_POST['vc_rewards_profile_nonce'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['vc_rewards_profile_nonce'])), 'vc_rewards_profile')) {
        return;
    }
    update_user_meta($user_id, '_vc_moderator', empty($_POST['vc_moderator']) ? 0 : 1);
    update_user_meta($user_id, '_vc_banned', empty($_POST['vc_banned']) ? 0 : 1);
    if (empty($_POST['vc_banned'])) {
        update_user_meta($user_id, '_vc_hold_rejections', 0);
    }
    $hold = (int) vc_rewards_setting('hold_first_posts');
    $approved = (int) get_user_meta($user_id, '_vc_hold_approved', true);
    if (!empty($_POST['vc_skip_hold']) && $approved < $hold) {
        update_user_meta($user_id, '_vc_hold_approved', $hold);
    } elseif (empty($_POST['vc_skip_hold']) && $approved >= $hold) {
        update_user_meta($user_id, '_vc_hold_approved', 0);
    }
    vc_rewards_check_rankup($user_id);
}
add_action('personal_options_update', 'vc_rewards_save_profile_fields');
add_action('edit_user_profile_update', 'vc_rewards_save_profile_fields');
