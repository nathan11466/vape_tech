<?php
/**
 * Store statistics, counted from the offers themselves.
 *
 * The coupon sites this is modelled on put numbers in the sidebar where we
 * put prose: how many codes, the best discount, how many are verified,
 * whether shipping is free, when it was last updated. A reader scanning a
 * store page wants those in two seconds, and a paragraph does not give them
 * in two seconds.
 *
 * Everything here is COUNTED, never asserted. The figures come from the
 * offer posts in the store's dealstore term and from the merchant's own
 * fields, so a store with three offers says three. Nothing is rounded up,
 * no range is invented, and a figure with nothing behind it is left out
 * rather than shown as zero -- "Verified codes: 0" reads as a warning, and
 * it is not one when we simply have not checked.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The offer posts assigned to a store's dealstore term.
 */
function vc_stats_offer_ids($post_id) {
    if (!function_exists('vc_rehub_store_term')) {
        return array();
    }

    $term = vc_rehub_store_term($post_id, false);
    if (!$term) {
        return array();
    }

    return get_posts(array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'tax_query'      => array(array(
            'taxonomy' => 'dealstore',
            'field'    => 'term_id',
            'terms'    => (int) $term->term_id,
        )),
    ));
}

/**
 * A discount figure as a number, or null.
 *
 * ReHub stores "50%" or "$10". Only a percentage is comparable across
 * offers, so a money figure is reported separately rather than ranked
 * against one: "best discount: $10" beside "50%" would otherwise pick
 * whichever number happened to be larger, which is meaningless.
 */
function vc_stats_percent($value) {
    // One rule, not two: the pattern itself requires the per-cent sign, so a
    // separate check for it only looked like a safeguard.
    return preg_match('/(\d{1,3}(?:\.\d+)?)\s*%/', trim((string) $value), $m)
        ? (float) $m[1] : null;
}

/**
 * Everything the stats panel and the offer table need, counted once.
 */
function vc_stats_summary($post_id) {
    $ids = vc_stats_offer_ids($post_id);

    $out = array(
        'offers' => 0, 'codes' => 0, 'deals' => 0, 'verified' => 0,
        'best_percent' => null, 'expiring' => 0, 'updated' => '',
        'free_shipping' => '', 'rows' => array(),
    );

    $newest = 0;

    foreach ($ids as $id) {
        $code = trim((string) get_post_meta($id, 'rehub_offer_product_coupon', true));
        $discount = trim((string) get_post_meta($id, 'rehub_offer_discount', true));
        $expires = trim((string) get_post_meta($id, 'rehub_offer_coupon_date', true));
        $verified = trim((string) get_post_meta($id, 'rehub_offer_verify_label', true));

        $out['offers']++;
        if ($code !== '') {
            $out['codes']++;
        } else {
            $out['deals']++;
        }
        if ($verified !== '') {
            $out['verified']++;
        }

        $percent = vc_stats_percent($discount);
        if ($percent !== null
            && ($out['best_percent'] === null || $percent > $out['best_percent'])) {
            $out['best_percent'] = $percent;
        }

        $stamp = strtotime((string) get_post_field('post_modified_gmt', $id));
        if ($stamp && $stamp > $newest) {
            $newest = $stamp;
        }

        $out['rows'][] = array(
            'title'    => get_the_title($id),
            'discount' => $discount,
            'expires'  => $expires,
            'code'     => $code,
        );
    }

    if ($newest) {
        $out['updated'] = date_i18n(get_option('date_format'), $newest);
    } elseif (function_exists('vc_section_meta')) {
        // No offers yet: fall back to when the store itself was checked.
        $out['updated'] = trim((string) vc_section_meta($post_id, 'last_checked_text'));
    }

    $threshold = function_exists('vc_section_meta')
        ? trim((string) vc_section_meta($post_id, 'free_shipping_threshold_usd')) : '';
    if ($threshold !== '' && ctype_digit($threshold)) {
        $out['free_shipping'] = sprintf(
            /* translators: %s: dollar amount */
            __('Over $%s', 'vc-merchant'), $threshold);
    }

    return $out;
}

/**
 * A code with its middle hidden, for the summary table.
 *
 * The whole point of a store page is that the code is revealed by a click
 * that fires the affiliate link. Printing it in a table beside the button
 * gives it away for nothing.
 */
function vc_stats_mask_code($code) {
    $code = trim((string) $code);
    if ($code === '') {
        return '';
    }
    $keep = $code === '' ? 0 : min(3, max(1, (int) floor(strlen($code) / 2)));

    return substr($code, 0, $keep) . str_repeat('*', max(3, strlen($code) - $keep));
}

/* -------------------------------------------------------------------------
 * The sidebar panel
 * ---------------------------------------------------------------------- */

/**
 * [merchant_stats] -- the counted figures, as a short list.
 */
add_shortcode('merchant_stats', function ($atts) {
    $atts = shortcode_atts(array('id' => 0, 'title' => ''), $atts, 'merchant_stats');
    $post_id = function_exists('vc_section_post_id')
        ? vc_section_post_id($atts) : (int) $atts['id'];
    if (!$post_id) {
        return '';
    }

    $s = vc_stats_summary($post_id);
    $name = function_exists('vc_merchant_display_name')
        ? vc_merchant_display_name($post_id) : get_the_title($post_id);

    // Only rows with something behind them. A zero here reads as a warning
    // about the shop rather than an absence of data on our side.
    $rows = array();
    if ($s['offers']) {
        $rows[__('Offers listed', 'vc-merchant')] = (string) $s['offers'];
    }
    if ($s['codes']) {
        $rows[__('Coupon codes', 'vc-merchant')] = (string) $s['codes'];
    }
    if ($s['deals']) {
        $rows[__('Deals without a code', 'vc-merchant')] = (string) $s['deals'];
    }
    if ($s['best_percent'] !== null) {
        $rows[__('Best discount', 'vc-merchant')] =
            rtrim(rtrim(number_format((float) $s['best_percent'], 1), '0'), '.') . '%';
    }
    if ($s['verified']) {
        $rows[__('Verified', 'vc-merchant')] = (string) $s['verified'];
    }
    if ($s['free_shipping'] !== '') {
        $rows[__('Free shipping', 'vc-merchant')] = $s['free_shipping'];
    }
    if ($s['updated'] !== '') {
        $rows[__('Last updated', 'vc-merchant')] = $s['updated'];
    }

    if (empty($rows)) {
        return '';
    }

    $heading = trim((string) $atts['title']);
    if ($heading === '' && function_exists('vc_block_heading')) {
        $heading = vc_block_heading('stats', $post_id,
            sprintf(__('%s stats', 'vc-merchant'), $name));
    }

    $items = '';
    foreach ($rows as $label => $value) {
        $items .= '<li class="vc-stat"><span class="vc-stat__label">'
            . esc_html($label) . '</span><span class="vc-stat__value">'
            . esc_html($value) . '</span></li>';
    }

    $out = '<section class="vc-stats">';
    if ($heading !== '') {
        $out .= '<h2>' . esc_html($heading) . '</h2>';
    }

    return $out . '<ul class="vc-stats__list">' . $items . '</ul></section>';
});

/* -------------------------------------------------------------------------
 * The summary table
 * ---------------------------------------------------------------------- */

/**
 * [merchant_offer_table] -- every current offer in one scannable table.
 *
 * The coupon sites put this under the offer cards: the same offers again,
 * but as rows a reader can run an eye down. Codes are masked, so the table
 * summarises without giving away what the button is for.
 */
add_shortcode('merchant_offer_table', function ($atts) {
    $atts = shortcode_atts(array('id' => 0, 'title' => '', 'limit' => 10),
        $atts, 'merchant_offer_table');
    $post_id = function_exists('vc_section_post_id')
        ? vc_section_post_id($atts) : (int) $atts['id'];
    if (!$post_id) {
        return '';
    }

    $s = vc_stats_summary($post_id);
    if (empty($s['rows'])) {
        return '';
    }

    $limit = max(1, (int) $atts['limit']);
    $name = function_exists('vc_merchant_display_name')
        ? vc_merchant_display_name($post_id) : get_the_title($post_id);

    $body = '';
    foreach (array_slice($s['rows'], 0, $limit) as $row) {
        $expires = trim((string) $row['expires']);
        if ($expires !== '') {
            $stamp = strtotime($expires);
            $expires = $stamp ? date_i18n(get_option('date_format'), $stamp) : $expires;
        }

        $body .= '<tr>'
            . '<td>' . esc_html($row['title']) . '</td>'
            . '<td>' . esc_html($row['discount'] !== '' ? $row['discount'] : '—') . '</td>'
            . '<td>' . esc_html($expires !== '' ? $expires : '—') . '</td>'
            . '<td><code>' . esc_html($row['code'] !== ''
                ? vc_stats_mask_code($row['code'])
                : __('No code needed', 'vc-merchant')) . '</code></td>'
            . '</tr>';
    }

    $heading = trim((string) $atts['title']);
    if ($heading === '') {
        $heading = sprintf(
            /* translators: 1: store name, 2: month and year */
            __('Top %1$s coupons and deals %2$s', 'vc-merchant'), $name,
            function_exists('vc_merchant_freshness_stamp')
                ? vc_merchant_freshness_stamp() : date_i18n('F Y'));
    }

    return '<section class="vc-offer-table"><h2>' . esc_html($heading) . '</h2>'
        . '<table><thead><tr>'
        . '<th>' . esc_html__('Offer', 'vc-merchant') . '</th>'
        . '<th>' . esc_html__('Discount', 'vc-merchant') . '</th>'
        . '<th>' . esc_html__('Expires', 'vc-merchant') . '</th>'
        . '<th>' . esc_html__('Code', 'vc-merchant') . '</th>'
        . '</tr></thead><tbody>' . $body . '</tbody></table></section>';
});
