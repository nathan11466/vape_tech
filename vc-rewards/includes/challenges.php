<?php
/**
 * Weekly challenges set by moderators, such as "verify 10 Vape Street
 * coupons this week" or "get 2 reviews accepted". They pay a one-time bonus
 * on top of the normal rewards. A sponsor name can be shown, for challenges
 * a store pays for.
 *
 * Only work that passed the normal checks counts, so a challenge never
 * rewards anything the rest of the system wouldn't:
 *
 *   votes     votes cast during the challenge that matched the outcome
 *             (helpful-type votes count once the post is decided)
 *   accepted  the member's posts, made during the challenge, that were accepted
 *
 * Either can be limited to one contribution type and, for coupons, one store.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_challenge_metrics() {
    return array(
        'votes'    => __('Accurate votes', 'vc-rewards'),
        'accepted' => __('Accepted posts', 'vc-rewards'),
    );
}

/**
 * $data: title, metric, contrib_type, brand_id, target, bonus, sponsor,
 * starts (Y-m-d), ends (Y-m-d, inclusive). Returns the ID or a message.
 */
function vc_rewards_create_challenge(array $data) {
    global $wpdb;
    $title  = mb_substr(sanitize_text_field($data['title'] ?? ''), 0, 191);
    $metric = sanitize_key($data['metric'] ?? '');
    $type   = sanitize_key($data['contrib_type'] ?? '');
    $target = (int) ($data['target'] ?? 0);
    $bonus  = (int) ($data['bonus'] ?? 0);
    $starts = strtotime((string) ($data['starts'] ?? ''));
    $ends   = strtotime((string) ($data['ends'] ?? ''));

    if ($title === '' || !isset(vc_rewards_challenge_metrics()[$metric]) || $target < 1 || $bonus < 1) {
        return __('Give the challenge a title, a goal of at least 1 and a bonus.', 'vc-rewards');
    }
    if ($type !== '' && !vc_rewards_type($type)) {
        return __('Unknown post type.', 'vc-rewards');
    }
    if (!$starts || !$ends || $ends < $starts) {
        return __('Choose a start date and an end date after it.', 'vc-rewards');
    }
    $wpdb->insert(vc_rewards_table('challenges'), array(
        'title'        => $title,
        'metric'       => $metric,
        'contrib_type' => $type,
        'brand_id'     => (int) ($data['brand_id'] ?? 0),
        'target'       => $target,
        'bonus'        => $bonus,
        'sponsor'      => mb_substr(sanitize_text_field($data['sponsor'] ?? ''), 0, 191),
        // Dates are site-local days; stored in UTC like everything else.
        'starts_at'    => get_gmt_from_date(gmdate('Y-m-d 00:00:00', $starts)),
        'ends_at'      => get_gmt_from_date(gmdate('Y-m-d 23:59:59', $ends)),
        'status'       => 'active',
    ));
    return (int) $wpdb->insert_id;
}

function vc_rewards_get_challenge($id) {
    global $wpdb;
    $table = vc_rewards_table('challenges');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", (int) $id));
}

/** Active challenges whose window includes now (or $at). */
function vc_rewards_current_challenges($at = null) {
    global $wpdb;
    $table = vc_rewards_table('challenges');
    $at    = $at ?: vc_rewards_now();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status = 'active' AND starts_at <= %s AND ends_at >= %s ORDER BY ends_at ASC",
        $at,
        $at
    ));
}

/** Active challenges that started and haven't been closed: still worth checking. */
function vc_rewards_checkable_challenges() {
    global $wpdb;
    $table = vc_rewards_table('challenges');
    // Votes cast during a challenge can be decided a few days after it ends.
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE status = 'active' AND starts_at <= %s AND ends_at >= %s",
        vc_rewards_now(),
        vc_rewards_now(-14 * DAY_IN_SECONDS)
    ));
}

/** SQL limiting contributions (alias c) to the challenge's type and store. */
function vc_rewards_challenge_filter_sql($challenge) {
    global $wpdb;
    $sql = '';
    if ($challenge->contrib_type !== '') {
        $sql .= $wpdb->prepare(' AND c.contrib_type = %s', $challenge->contrib_type);
    }
    if ((int) $challenge->brand_id) {
        $sql .= $wpdb->prepare(
            " AND c.object_type = 'wcd_coupon' AND c.object_id IN (
                SELECT tr.object_id FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                WHERE tt.taxonomy = 'wcd_brand' AND tt.term_id = %d)",
            (int) $challenge->brand_id
        );
    }
    return $sql;
}

function vc_rewards_challenge_progress($challenge, $user_id) {
    global $wpdb;
    $contrib = vc_rewards_table('contributions');
    $filter  = vc_rewards_challenge_filter_sql($challenge);

    if ($challenge->metric === 'accepted') {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $contrib c WHERE c.author_id = %d AND c.state = 'accepted'
             AND c.created_at BETWEEN %s AND %s $filter",
            (int) $user_id,
            $challenge->starts_at,
            $challenge->ends_at
        ));
    }

    $votes = vc_rewards_table('votes');
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT v.verdict, v.weight, c.contrib_type, c.state FROM $votes v JOIN $contrib c ON c.id = v.contribution_id
         WHERE v.voter_id = %d AND v.weight > 0 AND v.created_at BETWEEN %s AND %s
           AND c.state IN ('accepted', 'rejected', 'withdrawn') $filter",
        (int) $user_id,
        $challenge->starts_at,
        $challenge->ends_at
    ));
    $count = 0;
    foreach ($rows as $row) {
        $type = vc_rewards_type($row->contrib_type);
        if (!$type) {
            continue;
        }
        if ($type['kind'] === 'helpful') {
            $count++;
            continue;
        }
        $verdicts = vc_rewards_verdicts($type['kind']);
        $side     = isset($verdicts[$row->verdict]) ? $verdicts[$row->verdict]['side'] : 0;
        $outcome  = $row->state === 'accepted' ? 1 : -1;
        if ($side === $outcome) {
            $count++;
        }
    }
    return $count;
}

function vc_rewards_challenge_done($challenge_id, $user_id) {
    global $wpdb;
    $table = vc_rewards_table('challenge_done');
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM $table WHERE challenge_id = %d AND user_id = %d",
        (int) $challenge_id,
        (int) $user_id
    ));
}

/** Pay any challenges the member has just completed. Returns how many. */
function vc_rewards_check_challenges($user_id) {
    global $wpdb;
    $user_id = (int) $user_id;
    if (!$user_id || vc_rewards_participation_error($user_id) !== '') {
        return 0;
    }
    $paid = 0;
    foreach (vc_rewards_checkable_challenges() as $ch) {
        if (vc_rewards_challenge_done($ch->id, $user_id) || vc_rewards_challenge_progress($ch, $user_id) < (int) $ch->target) {
            continue;
        }
        // The primary key makes a race between two requests pay once.
        $inserted = $wpdb->insert(vc_rewards_table('challenge_done'), array(
            'challenge_id' => (int) $ch->id,
            'user_id'      => $user_id,
            'created_at'   => vc_rewards_now(),
        ));
        if (!$inserted) {
            continue;
        }
        $ledger_id = vc_rewards_ledger_add($user_id, (int) $ch->bonus, 'challenge', array(
            'settle_days' => 7,
            /* translators: %s: challenge title */
            'note'        => sprintf(__('Challenge completed: %s', 'vc-rewards'), $ch->title),
        ));
        $wpdb->update(vc_rewards_table('challenge_done'), array('ledger_id' => $ledger_id), array('challenge_id' => (int) $ch->id, 'user_id' => $user_id));
        $paid++;
    }
    return $paid;
}

/** A decided post can complete a challenge for its author or any voter. */
add_action('vc_rewards_contribution_state', function ($contribution_id, $state) {
    global $wpdb;
    if (!in_array($state, array('accepted', 'rejected', 'withdrawn'), true) || !vc_rewards_checkable_challenges()) {
        return;
    }
    $c = vc_rewards_get_contribution($contribution_id);
    if (!$c) {
        return;
    }
    $votes  = vc_rewards_table('votes');
    $voters = $wpdb->get_col($wpdb->prepare("SELECT voter_id FROM $votes WHERE contribution_id = %d", (int) $c->id));
    foreach (array_unique(array_merge(array((int) $c->author_id), array_map('intval', $voters))) as $uid) {
        vc_rewards_check_challenges($uid);
    }
}, 30, 2);

/* -------------------------------------------------------------------------
 * Front end
 * ---------------------------------------------------------------------- */

function vc_rewards_render_challenges($user_id) {
    $current = vc_rewards_current_challenges();
    if (!$current) {
        return '';
    }
    $out = '<ul class="vc-rewards-challenges">';
    foreach ($current as $ch) {
        $done     = $user_id && vc_rewards_challenge_done($ch->id, $user_id);
        $progress = $user_id ? min((int) $ch->target, vc_rewards_challenge_progress($ch, $user_id)) : 0;
        $out .= '<li><strong>' . esc_html($ch->title) . '</strong> <span class="vc-rewards-badge">'
            . esc_html(vc_rewards_format_points((int) $ch->bonus)) . '</span>';
        if ($ch->sponsor !== '') {
            /* translators: %s: sponsor name */
            $out .= ' <span class="vc-rewards-meta">' . esc_html(sprintf(__('Sponsored by %s', 'vc-rewards'), $ch->sponsor)) . '</span>';
        }
        $out .= '<br><span class="vc-rewards-meta">' . esc_html(sprintf(
            /* translators: 1: progress, 2: goal, 3: what counts, 4: end date */
            __('%1$d of %2$d %3$s. Ends %4$s.', 'vc-rewards'),
            $progress,
            (int) $ch->target,
            strtolower(vc_rewards_challenge_metrics()[$ch->metric]),
            mysql2date(get_option('date_format'), get_date_from_gmt($ch->ends_at))
        )) . ($done ? ' ' . esc_html__('Completed!', 'vc-rewards') : '') . '</span>'
            . '<progress max="' . (int) $ch->target . '" value="' . (int) $progress . '"></progress></li>';
    }
    return $out . '</ul>';
}

add_shortcode('vc_rewards_challenges', function () {
    vc_rewards_enqueue();
    $user_id = get_current_user_id();
    if ($user_id) {
        vc_rewards_check_challenges($user_id);
    }
    return vc_rewards_render_challenges($user_id);
});

add_filter('vc_rewards_account_sections', function ($out, $user_id) {
    vc_rewards_check_challenges($user_id);
    $list = vc_rewards_render_challenges($user_id);
    return $list ? $out . '<section><h3>' . esc_html__('Challenges', 'vc-rewards') . '</h3>' . $list . '</section>' : $out;
}, 10, 2);

/* -------------------------------------------------------------------------
 * wp-admin: Rewards → Challenges
 * ---------------------------------------------------------------------- */

add_action('vc_rewards_admin_menu', function () {
    add_submenu_page('vc-rewards', __('Challenges', 'vc-rewards'), __('Challenges', 'vc-rewards'), 'read', 'vc-rewards-challenges', 'vc_rewards_challenges_page');
});

add_filter('vc_rewards_mod_op', function ($result, $op, $id) {
    global $wpdb;
    if ($op === 'challenge_add') {
        $r = vc_rewards_create_challenge(array(
            'title'        => isset($_POST['ch_title']) ? wp_unslash($_POST['ch_title']) : '',
            'metric'       => isset($_POST['ch_metric']) ? wp_unslash($_POST['ch_metric']) : '',
            'contrib_type' => isset($_POST['ch_type']) ? wp_unslash($_POST['ch_type']) : '',
            'brand_id'     => isset($_POST['ch_brand']) ? (int) $_POST['ch_brand'] : 0,
            'target'       => isset($_POST['ch_target']) ? (int) $_POST['ch_target'] : 0,
            'bonus'        => isset($_POST['ch_bonus']) ? (int) $_POST['ch_bonus'] : 0,
            'sponsor'      => isset($_POST['ch_sponsor']) ? wp_unslash($_POST['ch_sponsor']) : '',
            'starts'       => isset($_POST['ch_starts']) ? sanitize_text_field(wp_unslash($_POST['ch_starts'])) : '',
            'ends'         => isset($_POST['ch_ends']) ? sanitize_text_field(wp_unslash($_POST['ch_ends'])) : '',
        ));
        return array('vc-rewards-challenges', is_int($r) ? __('Challenge created.', 'vc-rewards') : $r);
    }
    if ($op === 'challenge_close') {
        $wpdb->update(vc_rewards_table('challenges'), array('status' => 'closed'), array('id' => (int) $id));
        return array('vc-rewards-challenges', __('Challenge closed. Members who already completed it keep their bonus.', 'vc-rewards'));
    }
    return $result;
}, 10, 3);

function vc_rewards_challenges_page() {
    global $wpdb;
    if (!vc_rewards_is_moderator(get_current_user_id())) {
        wp_die(esc_html__('Moderators only.', 'vc-rewards'));
    }
    $notice = isset($_GET['vc_done']) ? sanitize_text_field(wp_unslash($_GET['vc_done'])) : '';
    echo '<div class="wrap"><h1>' . esc_html__('Challenges', 'vc-rewards') . '</h1>';
    if ($notice) {
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
    echo '<p class="description">' . esc_html__('Bonuses on top of normal rewards. Only accurate votes and accepted posts count, so a challenge never pays for anything the usual checks would reject. Bonuses settle after 7 days.', 'vc-rewards') . '</p>';

    $table = vc_rewards_table('challenges');
    $done  = vc_rewards_table('challenge_done');
    $rows  = $wpdb->get_results("SELECT ch.*, (SELECT COUNT(*) FROM $done d WHERE d.challenge_id = ch.id) AS completions FROM $table ch ORDER BY ch.id DESC LIMIT 50");
    if ($rows) {
        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Challenge', 'vc-rewards') . '</th><th>' . esc_html__('Goal', 'vc-rewards') . '</th><th>'
            . esc_html__('Bonus', 'vc-rewards') . '</th><th>' . esc_html__('Dates', 'vc-rewards') . '</th><th>' . esc_html__('Completed by', 'vc-rewards') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $ch) {
            $type = $ch->contrib_type !== '' ? vc_rewards_type($ch->contrib_type) : null;
            $goal = $ch->target . ' ' . strtolower(vc_rewards_challenge_metrics()[$ch->metric] ?? $ch->metric);
            if ($type) {
                $goal .= ' (' . $type['label'] . ')';
            }
            if ((int) $ch->brand_id) {
                $term = get_term((int) $ch->brand_id, 'wcd_brand');
                $goal .= $term && !is_wp_error($term) ? ', ' . $term->name : '';
            }
            echo '<tr><td>' . esc_html($ch->title) . ($ch->sponsor !== '' ? '<br><span class="description">' . esc_html($ch->sponsor) . '</span>' : '') . '</td><td>' . esc_html($goal) . '</td><td>'
                . esc_html(vc_rewards_format_points((int) $ch->bonus)) . '</td><td>' . esc_html(get_date_from_gmt($ch->starts_at, 'Y-m-d') . ' to ' . get_date_from_gmt($ch->ends_at, 'Y-m-d')) . '</td><td>'
                . (int) $ch->completions . '</td><td>'
                . ($ch->status === 'active' ? vc_rewards_action_button('challenge_close', $ch->id, __('Close', 'vc-rewards')) : esc_html__('Closed', 'vc-rewards')) // phpcs:ignore WordPress.Security.EscapeOutput
                . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    $types = '<option value="">' . esc_html__('Any', 'vc-rewards') . '</option>';
    foreach (vc_rewards_setting('types') as $key => $type) {
        $types .= '<option value="' . esc_attr($key) . '">' . esc_html($type['label']) . '</option>';
    }
    $brands = '<option value="0">' . esc_html__('Any store', 'vc-rewards') . '</option>';
    $terms  = taxonomy_exists('wcd_brand') ? get_terms(array('taxonomy' => 'wcd_brand', 'hide_empty' => false, 'orderby' => 'name')) : array();
    foreach (is_wp_error($terms) ? array() : $terms as $term) {
        $brands .= '<option value="' . (int) $term->term_id . '">' . esc_html($term->name) . '</option>';
    }
    $metrics = '';
    foreach (vc_rewards_challenge_metrics() as $key => $label) {
        $metrics .= '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    $today = current_time('Y-m-d');
    echo '<h2>' . esc_html__('New challenge', 'vc-rewards') . '</h2>'
        . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vc_rewards_mod"><input type="hidden" name="op" value="challenge_add">'
        . wp_nonce_field('vc_rewards_mod', '_wpnonce', true, false)
        . '<table class="form-table" role="presentation">'
        . '<tr><th>' . esc_html__('Title', 'vc-rewards') . '</th><td><input type="text" name="ch_title" class="regular-text" placeholder="' . esc_attr__('Verify 10 Vape Street coupons', 'vc-rewards') . '" required></td></tr>'
        . '<tr><th>' . esc_html__('Counts', 'vc-rewards') . '</th><td><select name="ch_metric">' . $metrics . '</select> ' . esc_html__('of type', 'vc-rewards') . ' <select name="ch_type">' . $types . '</select> ' . esc_html__('for', 'vc-rewards') . ' <select name="ch_brand">' . $brands . '</select>'
        . '<p class="description">' . esc_html__('A store limits it to coupons for that store.', 'vc-rewards') . '</p></td></tr>'
        . '<tr><th>' . esc_html__('Goal', 'vc-rewards') . '</th><td><input type="number" name="ch_target" min="1" value="10" class="small-text"></td></tr>'
        . '<tr><th>' . esc_html__('Bonus points', 'vc-rewards') . '</th><td><input type="number" name="ch_bonus" min="1" value="1500" class="small-text" style="width:7em"> <span class="description">' . esc_html__('1,000 to 2,500 is the usual range (40¢ to $1).', 'vc-rewards') . '</span></td></tr>'
        . '<tr><th>' . esc_html__('Sponsor (optional)', 'vc-rewards') . '</th><td><input type="text" name="ch_sponsor" class="regular-text"></td></tr>'
        . '<tr><th>' . esc_html__('Dates', 'vc-rewards') . '</th><td><input type="date" name="ch_starts" value="' . esc_attr($today) . '" required> ' . esc_html__('to', 'vc-rewards')
        . ' <input type="date" name="ch_ends" value="' . esc_attr(gmdate('Y-m-d', strtotime($today . ' +6 days'))) . '" required></td></tr>'
        . '</table>';
    submit_button(__('Create challenge', 'vc-rewards'));
    echo '</form></div>';
}
