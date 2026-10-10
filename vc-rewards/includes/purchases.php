<?php
/**
 * Cashback on confirmed purchases.
 *
 * Store links can carry the member's sub-ID (set the parameter name your
 * affiliate network uses in settings). When the network reports a sale as
 * confirmed, after the return window, import its report under Rewards →
 * Purchases and the member gets a share of the order value as points. Only
 * money the site has actually earned is ever paid on, and a reversal later
 * takes the points back.
 *
 * A confirmed purchase also counts as a real contribution: it unlocks visit
 * and bonus points for redemption and qualifies a referral.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_subid($user_id) {
    return 'vc' . vc_rewards_referral_code($user_id);
}

function vc_rewards_user_from_subid($subid) {
    $subid = strtolower(trim((string) $subid));
    return strpos($subid, 'vc') === 0 ? vc_rewards_referrer_from_code(substr($subid, 2)) : 0;
}

/**
 * Add the member's sub-ID to an outbound store link. Other templates can use
 * it too: apply_filters('vc_rewards_tag_link', $url).
 */
function vc_rewards_tag_link($url, $user_id = null) {
    $param   = sanitize_key((string) vc_rewards_setting('subid_param'));
    $user_id = $user_id === null ? get_current_user_id() : (int) $user_id;
    if ($param === '' || !$user_id || (string) $url === '') {
        return $url;
    }
    return add_query_arg($param, vc_rewards_subid($user_id), $url);
}
add_filter('vc_rewards_tag_link', 'vc_rewards_tag_link');

add_filter('vc_rewards_reveal_payload', function ($payload) {
    if (is_array($payload) && !empty($payload['url'])) {
        $payload['url'] = esc_url(vc_rewards_tag_link(html_entity_decode($payload['url'])));
    }
    return $payload;
}, 50);

function vc_rewards_purchase_points($order_value) {
    // 2,500 points = $1.
    return (int) round((float) $order_value * (float) vc_rewards_setting('cashback_percent') / 100 * 2500);
}

function vc_rewards_get_purchase($txn_id) {
    global $wpdb;
    $table = vc_rewards_table('purchases');
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE txn_id = %s", (string) $txn_id));
}

/**
 * Record one row of a network report.
 *
 * $row: txn_id, subid (or user_id), merchant, order_value, status
 * (pending, confirmed or reversed; networks' own words like "approved" and
 * "declined" are mapped). Re-importing the same report changes nothing.
 *
 * Returns array('result' => added|paid|reversed|unchanged|skipped, 'message').
 */
function vc_rewards_record_purchase(array $row) {
    global $wpdb;
    $table  = vc_rewards_table('purchases');
    $txn    = mb_substr(sanitize_text_field((string) ($row['txn_id'] ?? '')), 0, 100);
    $status = strtolower(trim((string) ($row['status'] ?? '')));
    $map    = array(
        'approved' => 'confirmed', 'confirmed' => 'confirmed', 'locked' => 'confirmed', 'paid' => 'confirmed',
        'pending' => 'pending', 'open' => 'pending', 'new' => 'pending',
        'reversed' => 'reversed', 'declined' => 'reversed', 'rejected' => 'reversed', 'void' => 'reversed', 'returned' => 'reversed',
    );
    $status  = isset($map[$status]) ? $map[$status] : '';
    $user_id = !empty($row['user_id']) ? (int) $row['user_id'] : vc_rewards_user_from_subid($row['subid'] ?? '');
    $value   = round((float) preg_replace('/[^0-9.\-]/', '', (string) ($row['order_value'] ?? '0')), 2);

    if ($txn === '' || $status === '') {
        return array('result' => 'skipped', 'message' => __('Missing transaction ID or unknown status.', 'vc-rewards'));
    }
    if (!$user_id || !get_userdata($user_id)) {
        return array('result' => 'skipped', 'message' => __('No member matches that sub-ID.', 'vc-rewards'));
    }

    $existing = vc_rewards_get_purchase($txn);
    $now      = vc_rewards_now();
    if (!$existing) {
        $wpdb->insert($table, array(
            'txn_id'      => $txn,
            'user_id'     => $user_id,
            'merchant'    => mb_substr(sanitize_text_field((string) ($row['merchant'] ?? '')), 0, 191),
            'order_value' => $value,
            'status'      => 'pending',
            'created_at'  => $now,
            'updated_at'  => $now,
        ));
        $existing = vc_rewards_get_purchase($txn);
        if ($status === 'pending') {
            return array('result' => 'added', 'message' => __('Recorded as pending.', 'vc-rewards'));
        }
    }
    if ((int) $existing->user_id !== $user_id) {
        return array('result' => 'skipped', 'message' => __('That transaction belongs to another member.', 'vc-rewards'));
    }
    if ($existing->status === $status || ($existing->status === 'reversed')) {
        return array('result' => 'unchanged', 'message' => __('No change.', 'vc-rewards'));
    }

    if ($status === 'confirmed') {
        $points = vc_rewards_purchase_points($value);
        $ledger = vc_rewards_ledger_add($user_id, $points, 'purchase', array(
            'status' => 'settled',
            /* translators: 1: store, 2: order value */
            'note'   => sprintf(__('Cashback: %1$s order of $%2$s', 'vc-rewards'), $existing->merchant ?: __('store', 'vc-rewards'), number_format($value, 2)),
        ));
        $wpdb->update($table, array('status' => 'confirmed', 'order_value' => $value, 'points' => $points, 'ledger_id' => $ledger, 'updated_at' => $now), array('id' => (int) $existing->id));
        do_action('vc_rewards_purchase_confirmed', $user_id, $existing->id);
        return array('result' => 'paid', 'message' => sprintf(
            /* translators: %s: points */
            __('Paid %s.', 'vc-rewards'),
            vc_rewards_format_points($points)
        ));
    }

    if ($status === 'reversed') {
        if ($existing->status === 'confirmed' && (int) $existing->points > 0) {
            vc_rewards_ledger_add($user_id, -1 * (int) $existing->points, 'purchase', array(
                'status' => 'settled',
                /* translators: %s: store */
                'note'   => sprintf(__('Cashback reversed: %s order returned or cancelled', 'vc-rewards'), $existing->merchant ?: __('store', 'vc-rewards')),
            ));
        }
        $wpdb->update($table, array('status' => 'reversed', 'updated_at' => $now), array('id' => (int) $existing->id));
        return array('result' => 'reversed', 'message' => __('Reversed.', 'vc-rewards'));
    }

    // confirmed -> pending happens when a network re-opens a sale; leave the points.
    return array('result' => 'unchanged', 'message' => __('No change.', 'vc-rewards'));
}

/**
 * Import a CSV report. The header row needs txn_id, subid (or user_id),
 * order_value and status; merchant is optional. Returns counts per result.
 */
function vc_rewards_import_purchases($csv_path) {
    $counts = array('added' => 0, 'paid' => 0, 'reversed' => 0, 'unchanged' => 0, 'skipped' => 0);
    $fh = fopen($csv_path, 'r');
    if (!$fh) {
        return $counts;
    }
    $header = fgetcsv($fh);
    $header = is_array($header) ? array_map(function ($h) {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)));
    }, $header) : array();
    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) !== count($header)) {
            $counts['skipped']++;
            continue;
        }
        $r = vc_rewards_record_purchase(array_combine($header, $line));
        $counts[$r['result']]++;
    }
    fclose($fh);
    return $counts;
}

/* -------------------------------------------------------------------------
 * wp-admin: Rewards → Purchases (admins only: it pays real money)
 * ---------------------------------------------------------------------- */

add_action('vc_rewards_admin_menu', function () {
    add_submenu_page('vc-rewards', __('Purchases', 'vc-rewards'), __('Purchases', 'vc-rewards'), 'manage_options', 'vc-rewards-purchases', 'vc_rewards_purchases_page');
});

add_action('admin_post_vc_rewards_purchases', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('vc_rewards_purchases')) {
        wp_die(esc_html__('Admins only.', 'vc-rewards'));
    }
    if (!empty($_FILES['vc_csv']['tmp_name']) && is_uploaded_file($_FILES['vc_csv']['tmp_name'])) {
        $c = vc_rewards_import_purchases($_FILES['vc_csv']['tmp_name']);
        $done = sprintf(
            /* translators: 1-5: counts */
            __('Imported: %1$d paid, %2$d pending, %3$d reversed, %4$d unchanged, %5$d skipped.', 'vc-rewards'),
            $c['paid'], $c['added'], $c['reversed'], $c['unchanged'], $c['skipped']
        );
    } else {
        $r = vc_rewards_record_purchase(array(
            'txn_id'      => isset($_POST['txn_id']) ? wp_unslash($_POST['txn_id']) : '',
            'subid'       => isset($_POST['subid']) ? wp_unslash($_POST['subid']) : '',
            'user_id'     => !empty($_POST['member']) && ($u = get_user_by('login', sanitize_user(wp_unslash($_POST['member'])))) ? $u->ID : 0,
            'merchant'    => isset($_POST['merchant']) ? wp_unslash($_POST['merchant']) : '',
            'order_value' => isset($_POST['order_value']) ? wp_unslash($_POST['order_value']) : '',
            'status'      => isset($_POST['status']) ? wp_unslash($_POST['status']) : '',
        ));
        $done = $r['message'];
    }
    wp_safe_redirect(vc_rewards_admin_url('vc-rewards-purchases', array('vc_done' => rawurlencode($done))));
    exit;
});

function vc_rewards_purchases_page() {
    global $wpdb;
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Admins only.', 'vc-rewards'));
    }
    $notice = isset($_GET['vc_done']) ? sanitize_text_field(wp_unslash($_GET['vc_done'])) : '';
    echo '<div class="wrap"><h1>' . esc_html__('Purchases', 'vc-rewards') . '</h1>';
    if ($notice) {
        echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($notice) . '</p></div>';
    }
    $param = (string) vc_rewards_setting('subid_param');
    echo '<p class="description">' . esc_html(sprintf(
        /* translators: 1: percent, 2: parameter name */
        __('Members get %1$s%% of the order value as points when a sale is confirmed. Store links carry their sub-ID in "%2$s".', 'vc-rewards'),
        vc_rewards_setting('cashback_percent'),
        $param !== '' ? $param : __('(not set: add the parameter in Settings)', 'vc-rewards')
    )) . '</p>';

    $form = '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vc_rewards_purchases">'
        . wp_nonce_field('vc_rewards_purchases', '_wpnonce', true, false);
    echo '<h2>' . esc_html__('Import a network report', 'vc-rewards') . '</h2>'
        . '<p>' . esc_html__('CSV with a header row: txn_id, subid, merchant, order_value, status. Status can be pending, confirmed (or approved) or reversed (or declined). Importing the same report twice is safe.', 'vc-rewards') . '</p>'
        . $form . '<input type="file" name="vc_csv" accept=".csv" required> <button type="submit" class="button button-primary">' . esc_html__('Import', 'vc-rewards') . '</button></form>';

    echo '<h2>' . esc_html__('Add one by hand', 'vc-rewards') . '</h2>' . $form
        . '<input type="text" name="txn_id" placeholder="' . esc_attr__('Transaction ID', 'vc-rewards') . '" required> '
        . '<input type="text" name="member" placeholder="' . esc_attr__('Username', 'vc-rewards') . '"> '
        . '<input type="text" name="subid" placeholder="' . esc_attr__('or sub-ID', 'vc-rewards') . '"> '
        . '<input type="text" name="merchant" placeholder="' . esc_attr__('Store', 'vc-rewards') . '"> '
        . '<input type="text" name="order_value" placeholder="' . esc_attr__('Order value', 'vc-rewards') . '" class="small-text" style="width:7em" required> '
        . '<select name="status"><option value="confirmed">' . esc_html__('Confirmed', 'vc-rewards') . '</option><option value="pending">' . esc_html__('Pending', 'vc-rewards') . '</option><option value="reversed">' . esc_html__('Reversed', 'vc-rewards') . '</option></select> '
        . '<button type="submit" class="button">' . esc_html__('Save', 'vc-rewards') . '</button></form>';

    $table = vc_rewards_table('purchases');
    $rows  = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 50");
    if ($rows) {
        echo '<h2>' . esc_html__('Recent', 'vc-rewards') . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__('Transaction', 'vc-rewards') . '</th><th>' . esc_html__('Member', 'vc-rewards') . '</th><th>'
            . esc_html__('Store', 'vc-rewards') . '</th><th>' . esc_html__('Order', 'vc-rewards') . '</th><th>' . esc_html__('Status', 'vc-rewards') . '</th><th>' . esc_html__('Points', 'vc-rewards') . '</th></tr></thead><tbody>';
        foreach ($rows as $p) {
            echo '<tr><td>' . esc_html($p->txn_id) . '</td><td>' . vc_rewards_user_link($p->user_id) . '</td><td>' . esc_html($p->merchant) . '</td><td>$' // phpcs:ignore WordPress.Security.EscapeOutput
                . esc_html(number_format((float) $p->order_value, 2)) . '</td><td>' . esc_html($p->status) . '</td><td>' . esc_html(number_format_i18n((int) $p->points)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}
