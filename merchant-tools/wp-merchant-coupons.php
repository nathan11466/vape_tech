<?php
/**
 * Integration with WP Coupon & Deals (wcd_coupon).
 *
 * That plugin holds the real offer records -- codes, expiry dates, and
 * success/fail votes from readers. This plugin had been inferring the same
 * things by parsing prose, which goes stale the moment a code expires.
 *
 * Three jobs:
 *
 *   1. Link a merchant post to its wcd_brand term.
 *   2. Derive the offer claim from live coupon records rather than text.
 *   3. Stop the two plugins emitting conflicting structured data.
 *
 * Everything here no-ops cleanly when the coupon plugin is inactive, so the
 * prose-derived behaviour remains the fallback.
 */

if (!defined('ABSPATH')) {
    exit;
}

function vc_coupons_active() {
    return post_type_exists('wcd_coupon') && taxonomy_exists('wcd_brand');
}

/* -------------------------------------------------------------------------
 * Linking a merchant to its brand term
 * ---------------------------------------------------------------------- */

/**
 * The wcd_brand term for a merchant, or null.
 *
 * Matches on an explicit stored term id first, then the display name, then the
 * post title. Name matching is the pragmatic bridge -- the two plugins were
 * built independently and nothing shared an identifier.
 */
function vc_merchant_brand_term($post_id = null) {
    if (!vc_coupons_active()) {
        return null;
    }
    $post_id = $post_id ?: get_the_ID();

    static $cache = array();
    if (array_key_exists($post_id, $cache)) {
        return $cache[$post_id];
    }

    $term = null;

    // An explicit link always wins, so a mismatched name can be corrected.
    $stored = (int) get_post_meta($post_id, 'wcd_brand_term_id', true);
    if ($stored) {
        $found = get_term($stored, 'wcd_brand');
        if ($found && !is_wp_error($found)) {
            $term = $found;
        }
    }

    if (!$term) {
        $names = array();
        $display = trim((string) get_post_meta($post_id, 'display_brand_name', true));
        if ($display !== '') {
            $names[] = $display;
        }
        $names[] = get_the_title($post_id);

        foreach ($names as $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $found = get_term_by('name', $name, 'wcd_brand');
            if (!$found) {
                $found = get_term_by('slug', sanitize_title($name), 'wcd_brand');
            }
            if ($found && !is_wp_error($found)) {
                $term = $found;
                break;
            }
        }
    }

    $cache[$post_id] = $term ?: null;

    return $cache[$post_id];
}

/* -------------------------------------------------------------------------
 * Live coupon records
 * ---------------------------------------------------------------------- */

/**
 * Unexpired coupons for a merchant, newest first.
 *
 * A coupon with no expiry date is treated as live; only a date in the past
 * excludes one.
 */
function vc_merchant_live_coupons($post_id = null) {
    $term = vc_merchant_brand_term($post_id);
    if (!$term) {
        return array();
    }

    $query = new WP_Query(array(
        'post_type'           => 'wcd_coupon',
        'post_status'         => 'publish',
        'posts_per_page'      => 30,
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
        'tax_query'           => array(array(
            'taxonomy' => 'wcd_brand',
            'field'    => 'term_id',
            'terms'    => $term->term_id,
        )),
    ));

    $today = current_time('Y-m-d');
    $live = array();

    foreach ($query->posts as $post) {
        $expires = trim((string) get_post_meta($post->ID, '_wcd_expiration', true));
        if ($expires !== '') {
            $stamp = strtotime($expires);
            if ($stamp && date('Y-m-d', $stamp) < $today) {
                continue;
            }
        }
        $live[] = $post;
    }

    return $live;
}

/**
 * The offer claim this page may make, derived from real records.
 *
 * Returns null when the coupon plugin cannot answer, so the caller falls back
 * to the value the enrichment script derived from prose.
 *
 * A code only counts as verified when readers have actually confirmed it
 * works -- more successes than failures. Votes are the strongest evidence
 * available here, and far better than the text heuristics this replaces.
 */
function vc_merchant_coupon_status($post_id = null) {
    if (!vc_coupons_active()) {
        return null;
    }
    $term = vc_merchant_brand_term($post_id);
    if (!$term) {
        return null;
    }

    $coupons = vc_merchant_live_coupons($post_id);
    if (empty($coupons)) {
        // The brand is known but has no live coupon: that is itself a fact,
        // and the page should say so rather than implying a code exists.
        return 'no_code_confirmed';
    }

    $has_working_code = false;
    $has_deal = false;

    foreach ($coupons as $post) {
        $type = trim((string) get_post_meta($post->ID, '_wcd_type', true));
        $code = trim((string) get_post_meta($post->ID, '_wcd_code', true));
        $ok   = (int) get_post_meta($post->ID, '_wcd_success_count', true);
        $bad  = (int) get_post_meta($post->ID, '_wcd_fail_count', true);

        if ($type === 'code' && $code !== '') {
            // Unvoted codes are given the benefit of the doubt; a code readers
            // report as broken more often than working is not "verified".
            if ($ok >= $bad) {
                $has_working_code = true;
            }
            continue;
        }

        $has_deal = true;
    }

    if ($has_working_code) {
        return 'verified_code';
    }

    return $has_deal ? 'best_deal' : 'no_code_confirmed';
}

/**
 * Prefer live coupon data over the stored, prose-derived value.
 */
add_filter('vc_merchant_offer_display_mode', function ($mode, $post_id) {
    $live = vc_merchant_coupon_status($post_id);

    return $live !== null ? $live : $mode;
}, 10, 2);

/**
 * Freshness from the newest live coupon, which beats a hand-typed date.
 */
function vc_merchant_coupon_checked($post_id = null) {
    $coupons = vc_merchant_live_coupons($post_id);
    if (empty($coupons)) {
        return '';
    }

    $newest = 0;
    foreach ($coupons as $post) {
        $stamp = strtotime($post->post_modified_gmt ?: $post->post_date_gmt);
        if ($stamp > $newest) {
            $newest = $stamp;
        }
    }

    return $newest ? date_i18n(get_option('date_format'), $newest) : '';
}

/* -------------------------------------------------------------------------
 * Structured data: one entity, one set of offers
 * ---------------------------------------------------------------------- */

/**
 * Align our store node's @id with the coupon plugin's Organization @id.
 *
 * Both describe the same shop. Two nodes with different @ids read as two
 * separate businesses; the same @id merges them, so our aliases, service area
 * and contact point combine with its aggregate rating rather than competing.
 */
add_filter('vc_merchant_org_id', function ($id, $post_id) {
    $term = vc_merchant_brand_term($post_id);
    if (!$term) {
        return $id;
    }
    $link = get_term_link($term);

    return is_wp_error($link) ? $id : $link . '#organization';
}, 10, 2);

/**
 * Drop our Offer node when the coupon plugin is emitting real ones.
 *
 * Its offers carry the actual code and a validThrough expiry, taken from
 * records rather than parsed out of a summary sentence. Ours would be a
 * vaguer duplicate of the same deal.
 */
add_filter('vc_merchant_emit_offer', function ($emit, $post_id) {
    return empty(vc_merchant_live_coupons($post_id)) ? $emit : false;
}, 10, 2);

/* -------------------------------------------------------------------------
 * Admin: show and correct the link
 * ---------------------------------------------------------------------- */

add_action('add_meta_boxes', function () {
    if (!vc_coupons_active()) {
        return;
    }
    foreach (vc_merchant_post_types() as $post_type) {
        add_meta_box(
            'vc-merchant-coupons',
            __('Linked coupons', 'vc-merchant'),
            'vc_merchant_coupons_meta_box',
            $post_type,
            'side',
            'default'
        );
    }
}, 20);

function vc_merchant_coupons_meta_box($post) {
    wp_nonce_field('vc_coupons_save', 'vc_coupons_nonce');

    $term = vc_merchant_brand_term($post->ID);
    $terms = get_terms(array('taxonomy' => 'wcd_brand', 'hide_empty' => false));

    echo '<p><label for="wcd_brand_term_id"><strong>'
        . esc_html__('Coupon brand', 'vc-merchant') . '</strong></label><br />';
    echo '<select name="wcd_brand_term_id" id="wcd_brand_term_id" style="width:100%;">';
    echo '<option value="0">' . esc_html__('- match by name -', 'vc-merchant') . '</option>';
    if (!is_wp_error($terms)) {
        foreach ($terms as $option) {
            printf('<option value="%d" %s>%s</option>',
                (int) $option->term_id,
                selected($term && $term->term_id === $option->term_id, true, false),
                esc_html($option->name));
        }
    }
    echo '</select></p>';

    if (!$term) {
        echo '<p class="description">' . esc_html__('No coupon brand matched this store by name. Pick one above to link them.', 'vc-merchant') . '</p>';
        return;
    }

    $live = vc_merchant_live_coupons($post->ID);
    $status = vc_merchant_coupon_status($post->ID);

    echo '<p class="description">' . esc_html(sprintf(
        _n('%d live coupon', '%d live coupons', count($live), 'vc-merchant'), count($live)))
        . ' &middot; <code>' . esc_html($status) . '</code></p>';

    if (!empty($live)) {
        echo '<ul style="margin:0;font-size:12px;">';
        foreach (array_slice($live, 0, 8) as $coupon) {
            $code = trim((string) get_post_meta($coupon->ID, '_wcd_code', true));
            $ok   = (int) get_post_meta($coupon->ID, '_wcd_success_count', true);
            $bad  = (int) get_post_meta($coupon->ID, '_wcd_fail_count', true);
            echo '<li>' . esc_html(get_the_title($coupon->ID));
            if ($code !== '') {
                echo ' <code>' . esc_html($code) . '</code>';
            }
            if ($ok || $bad) {
                echo ' <span style="color:#646970;">' . esc_html("{$ok}\u{2713} {$bad}\u{2717}") . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}

add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (empty($_POST['vc_coupons_nonce'])
        || !wp_verify_nonce($_POST['vc_coupons_nonce'], 'vc_coupons_save')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    $term_id = isset($_POST['wcd_brand_term_id']) ? (int) $_POST['wcd_brand_term_id'] : 0;
    if ($term_id > 0) {
        update_post_meta($post_id, 'wcd_brand_term_id', $term_id);
    } else {
        delete_post_meta($post_id, 'wcd_brand_term_id');
    }
});
