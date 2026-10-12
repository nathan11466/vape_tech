<?php
/**
 * Awin: tag store links with the member, and pull confirmed sales daily.
 *
 * Setup:
 *   1. Rewards → Settings → Awin publisher ID (the awinaffid in your links).
 *   2. In wp-config.php: define('VC_AWIN_TOKEN', 'your API token');
 *      (Awin → your account menu → API credentials). It is never stored in
 *      the database or shown on screen.
 *
 * Links: plain links to stores on Awin (coupon buttons and post content)
 * become Awin tracking links in the same shape as the site's own, and a
 * logged-in member's links also get clickref=<member sub-ID>. The store
 * list comes from the programmes you've joined (refreshed daily through the
 * API) plus any you list in settings. Stores not on Awin are left alone.
 *
 * Sales: once a day the last 90 days of transactions are read from the
 * Publisher API. Those with a member sub-ID in clickref are recorded:
 * pending ones are noted, approved ones pay cashback, and declined or
 * deleted ones take it back. Running it twice changes nothing.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_rewards_awin_token() {
    return (string) apply_filters('vc_rewards_awin_token', defined('VC_AWIN_TOKEN') ? VC_AWIN_TOKEN : '');
}

function vc_rewards_awin_publisher() {
    return preg_replace('/\D/', '', (string) vc_rewards_setting('awin_publisher_id'));
}

function vc_rewards_awin_ready() {
    return vc_rewards_awin_publisher() !== '' && vc_rewards_awin_token() !== '';
}

function vc_rewards_is_awin_link($url) {
    $host = strtolower((string) wp_parse_url((string) $url, PHP_URL_HOST));
    return $host === 'awin1.com' || substr($host, -10) === '.awin1.com';
}

/* -------------------------------------------------------------------------
 * Building Awin links from plain store links
 * ---------------------------------------------------------------------- */

function vc_rewards_awin_domain($host) {
    return preg_replace('/^www\./', '', strtolower(trim((string) $host)));
}

/** Stores from the API cache plus the settings list: domain => advertiser ID. */
function vc_rewards_awin_stores() {
    $stores = array();
    $cached = get_option('vc_rewards_awin_programmes');
    if (is_array($cached) && !empty($cached['stores'])) {
        $stores = $cached['stores'];
    }
    // The settings list wins, so a wrong API entry can be overridden.
    foreach (preg_split('/\r\n|\r|\n/', (string) vc_rewards_setting('awin_stores')) as $line) {
        if (preg_match('/^\s*(?:https?:\/\/)?([a-z0-9.\-]+)[^=]*=\s*(\d+)\s*$/i', $line, $m)) {
            $stores[vc_rewards_awin_domain($m[1])] = (int) $m[2];
        }
    }
    return $stores;
}

/** The advertiser ID for a store link (subdomains count), or 0. */
function vc_rewards_awin_advertiser_for($url) {
    $host = vc_rewards_awin_domain(wp_parse_url((string) $url, PHP_URL_HOST));
    if ($host === '' || vc_rewards_is_awin_link($url)) {
        return 0;
    }
    $stores = vc_rewards_awin_stores();
    while ($host !== '' && strpos($host, '.') !== false) {
        if (isset($stores[$host])) {
            return (int) $stores[$host];
        }
        $host = substr($host, strpos($host, '.') + 1);
    }
    return 0;
}

/**
 * A plain store link as an Awin tracking link, in the same shape as the
 * site's own: cread.php?awinmid=..&awinaffid=..&clickref6=..&ued=<link>.
 * Links to stores that aren't on Awin come back unchanged.
 */
function vc_rewards_awin_wrap($url) {
    $mid = vc_rewards_awin_advertiser_for($url);
    $pub = vc_rewards_awin_publisher();
    if (!$mid || $pub === '' || !preg_match('#^https?://#i', (string) $url)) {
        return $url;
    }
    $args = array('awinmid' => $mid, 'awinaffid' => $pub);
    $tag  = sanitize_key((string) vc_rewards_setting('awin_source_tag'));
    if ($tag !== '') {
        $args['clickref6'] = $tag;
    }
    $args['ued'] = rawurlencode((string) $url);
    return add_query_arg($args, 'https://www.awin1.com/cread.php');
}

/** Wrap if it's an Awin store, then add the member's sub-ID if logged in. */
function vc_rewards_affiliate_link($url) {
    $url = vc_rewards_awin_wrap($url);
    // Only affiliate links carry the member's sub-ID; other sites never see it.
    return is_user_logged_in() && vc_rewards_is_awin_link($url) ? vc_rewards_tag_link($url) : $url;
}
add_filter('vc_rewards_affiliate_link', 'vc_rewards_affiliate_link');

/** Refresh the store list from the programmes you've joined. Returns the count or a WP_Error. */
function vc_rewards_awin_refresh_stores() {
    if (!vc_rewards_awin_ready()) {
        return new WP_Error('vc_awin_setup', __('Awin is not set up.', 'vc-rewards'));
    }
    $response = wp_remote_get(
        add_query_arg('relationship', 'joined', 'https://api.awin.com/publishers/' . vc_rewards_awin_publisher() . '/programmes'),
        array('timeout' => 30, 'headers' => array('Authorization' => 'Bearer ' . vc_rewards_awin_token(), 'Accept' => 'application/json'))
    );
    if (is_wp_error($response)) {
        return $response;
    }
    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return new WP_Error('vc_awin_http', sprintf(
            /* translators: %d: HTTP status */
            __('Awin answered with status %d. Check the publisher ID and API token.', 'vc-rewards'),
            (int) wp_remote_retrieve_response_code($response)
        ));
    }
    $list = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($list)) {
        return new WP_Error('vc_awin_json', __('Awin sent something that is not a programme list.', 'vc-rewards'));
    }
    $stores = array();
    foreach ($list as $p) {
        if (empty($p['id'])) {
            continue;
        }
        $hosts = array();
        if (!empty($p['displayUrl'])) {
            $hosts[] = wp_parse_url((string) $p['displayUrl'], PHP_URL_HOST);
        }
        foreach ((array) ($p['validDomains'] ?? array()) as $d) {
            $hosts[] = is_array($d) ? ($d['domain'] ?? '') : (string) $d;
        }
        foreach ($hosts as $host) {
            $host = vc_rewards_awin_domain($host);
            if ($host !== '' && strpos($host, 'awin') === false) {
                $stores[$host] = (int) $p['id'];
            }
        }
    }
    update_option('vc_rewards_awin_programmes', array('time' => time(), 'stores' => $stores), false);
    return count($list);
}

/* -------------------------------------------------------------------------
 * Where links are rewritten
 * ---------------------------------------------------------------------- */

/** Coupon buttons read the destination from post meta. */
add_filter('get_post_metadata', function ($value, $object_id, $meta_key, $single) {
    static $busy = false;
    if ($busy || $meta_key !== '_wcd_destination_url' || is_admin() || wp_doing_ajax()) {
        return $value;
    }
    $busy = true;
    $url  = get_post_meta($object_id, '_wcd_destination_url', true);
    $busy = false;
    if (!$url) {
        return $value;
    }
    $link = vc_rewards_affiliate_link($url);
    if ($link === $url) {
        return $value;
    }
    return $single ? $link : array($link);
}, 10, 4);

/** Store links in posts and pages. */
add_filter('the_content', 'vc_rewards_affiliate_links_in_html', 20);

function vc_rewards_affiliate_links_in_html($html) {
    if (strpos((string) $html, 'href') === false) {
        return $html;
    }
    return preg_replace_callback('#(<a\s[^>]*href=)(["\'])(https?://[^"\']+)\2#i', function ($m) {
        $url  = html_entity_decode($m[3]);
        $link = vc_rewards_affiliate_link($url);
        return $link === $url ? $m[0] : $m[1] . $m[2] . esc_url($link) . $m[2];
    }, $html);
}

/* -------------------------------------------------------------------------
 * Sales sync
 * ---------------------------------------------------------------------- */

/**
 * One API call. Returns the decoded transactions, or a WP_Error.
 * $start, $end: UTC 'Y-m-d\TH:i:s'. Awin allows at most 31 days per call.
 */
function vc_rewards_awin_fetch($start, $end) {
    $url = add_query_arg(array(
        'startDate' => $start,
        'endDate'   => $end,
        'timezone'  => 'UTC',
    ), 'https://api.awin.com/publishers/' . vc_rewards_awin_publisher() . '/transactions/');
    $response = wp_remote_get($url, array(
        'timeout' => 30,
        'headers' => array('Authorization' => 'Bearer ' . vc_rewards_awin_token(), 'Accept' => 'application/json'),
    ));
    if (is_wp_error($response)) {
        return $response;
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    if ($code !== 200) {
        return new WP_Error('vc_awin_http', sprintf(
            /* translators: %d: HTTP status */
            __('Awin answered with status %d. Check the publisher ID and API token.', 'vc-rewards'),
            $code
        ));
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return is_array($data) ? $data : new WP_Error('vc_awin_json', __('Awin sent something that is not a transaction list.', 'vc-rewards'));
}

/** Turn one Awin transaction into a purchase row, or null if it isn't a member's. */
function vc_rewards_awin_row(array $t) {
    $subid = isset($t['clickRefs']['clickRef']) ? (string) $t['clickRefs']['clickRef'] : '';
    if ($subid === '' || stripos($subid, 'vc') !== 0 || empty($t['id'])) {
        return null;
    }
    return array(
        'txn_id'      => 'awin-' . $t['id'],
        'subid'       => $subid,
        'merchant'    => !empty($t['advertiserName']) ? $t['advertiserName'] : (!empty($t['advertiserId']) ? 'Awin ' . $t['advertiserId'] : 'Awin'),
        'order_value' => isset($t['saleAmount']['amount']) ? $t['saleAmount']['amount'] : 0,
        'currency'    => isset($t['saleAmount']['currency']) ? strtoupper($t['saleAmount']['currency']) : 'USD',
        'status'      => isset($t['commissionStatus']) ? $t['commissionStatus'] : '',
    );
}

/**
 * Pull the last $days of transactions and record members' sales.
 * Returns array of counts, plus 'errors' => list of messages.
 */
function vc_rewards_awin_sync($days = 90) {
    $counts = array('added' => 0, 'paid' => 0, 'reversed' => 0, 'unchanged' => 0, 'skipped' => 0, 'other_currency' => 0, 'errors' => array());
    if (!vc_rewards_awin_ready()) {
        $counts['errors'][] = __('Awin is not set up: add the publisher ID in settings and VC_AWIN_TOKEN in wp-config.php.', 'vc-rewards');
        return $counts;
    }
    $end = time();
    $stop = $end - (int) $days * DAY_IN_SECONDS;
    while ($end > $stop) {
        $start = max($stop, $end - 31 * DAY_IN_SECONDS + 1);
        $list  = vc_rewards_awin_fetch(gmdate('Y-m-d\TH:i:s', $start), gmdate('Y-m-d\TH:i:s', $end));
        if (is_wp_error($list)) {
            $counts['errors'][] = $list->get_error_message();
            break;
        }
        foreach ($list as $t) {
            $row = is_array($t) ? vc_rewards_awin_row($t) : null;
            if (!$row) {
                continue;
            }
            // Points are priced in dollars; other currencies need a moderator.
            if ($row['currency'] !== 'USD') {
                $counts['other_currency']++;
                continue;
            }
            $r = vc_rewards_record_purchase($row);
            $counts[$r['result']]++;
        }
        $end = $start - 1;
    }
    update_option('vc_rewards_awin_last_sync', array('time' => time(), 'counts' => $counts), false);
    return $counts;
}

add_action('vc_rewards_awin_sync', 'vc_rewards_awin_sync');
add_action('vc_rewards_awin_sync', 'vc_rewards_awin_refresh_stores');

// Daily, once Awin is set up.
add_action('init', function () {
    if (vc_rewards_awin_ready() && !wp_next_scheduled('vc_rewards_awin_sync')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'vc_rewards_awin_sync');
    }
});

/* -------------------------------------------------------------------------
 * Purchases page: status and "Sync now"
 * ---------------------------------------------------------------------- */

function vc_rewards_awin_summary(array $c) {
    $text = sprintf(
        /* translators: 1-4: counts */
        __('%1$d paid, %2$d pending, %3$d reversed, %4$d unchanged.', 'vc-rewards'),
        $c['paid'], $c['added'], $c['reversed'], $c['unchanged']
    );
    if (!empty($c['other_currency'])) {
        /* translators: %d: count */
        $text .= ' ' . sprintf(__('%d not in USD, left for you to add by hand.', 'vc-rewards'), $c['other_currency']);
    }
    if (!empty($c['errors'])) {
        $text .= ' ' . implode(' ', $c['errors']);
    }
    return $text;
}

add_action('vc_rewards_purchases_top', function () {
    echo '<h2>' . esc_html__('Awin', 'vc-rewards') . '</h2>';
    echo '<p>' . esc_html(sprintf(
        /* translators: %d: number of stores */
        __('Plain links to %d stores become Awin links.', 'vc-rewards'),
        count(vc_rewards_awin_stores())
    )) . '</p>';
    if (!vc_rewards_awin_ready()) {
        echo '<p>' . esc_html__('Not set up. Add your Awin publisher ID in Settings, and define VC_AWIN_TOKEN in wp-config.php with the token from Awin → API credentials.', 'vc-rewards') . '</p>';
        return;
    }
    $last = get_option('vc_rewards_awin_last_sync');
    echo '<p>' . esc_html(sprintf(
        /* translators: %s: publisher ID */
        __('Publisher %s. Sales sync daily; members\' Awin links carry their sub-ID in clickref.', 'vc-rewards'),
        vc_rewards_awin_publisher()
    )) . '</p>';
    if (is_array($last)) {
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: 1: date, 2: summary */
            __('Last sync %1$s: %2$s', 'vc-rewards'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $last['time']),
            vc_rewards_awin_summary($last['counts'])
        )) . '</p>';
    }
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="vc_rewards_awin_sync">'
        . wp_nonce_field('vc_rewards_awin_sync', '_wpnonce', true, false)
        . '<button type="submit" class="button">' . esc_html__('Sync now', 'vc-rewards') . '</button></form>';
});

add_action('admin_post_vc_rewards_awin_sync', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('vc_rewards_awin_sync')) {
        wp_die(esc_html__('Admins only.', 'vc-rewards'));
    }
    $stores = vc_rewards_awin_refresh_stores();
    $done   = vc_rewards_awin_summary(vc_rewards_awin_sync());
    if (!is_wp_error($stores)) {
        /* translators: %d: number of stores */
        $done .= ' ' . sprintf(__('%d joined stores found.', 'vc-rewards'), $stores);
    }
    wp_safe_redirect(vc_rewards_admin_url('vc-rewards-purchases', array('vc_done' => rawurlencode($done))));
    exit;
});
