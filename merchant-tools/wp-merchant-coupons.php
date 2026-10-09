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

    // Say what the page will actually claim, in words. "verified_code" is an
    // internal key and tells an editor nothing about what readers will see.
    $labels = array(
        'verified_code'     => __('Shows a verified code', 'vc-merchant'),
        'best_deal'         => __('Shows the best deal (no code)', 'vc-merchant'),
        'no_code_confirmed' => __('No code claimed', 'vc-merchant'),
    );
    $label = $labels[$status] ?? __('Set by the store data', 'vc-merchant');

    echo '<p class="vc-coupon-status"><span class="vc-coupon-status__badge vc-coupon-status--'
        . esc_attr($status ? str_replace('_', '-', $status) : 'none') . '">'
        . esc_html($label) . '</span></p>';

    echo '<p class="description">' . esc_html(sprintf(
        _n('%d live coupon linked.', '%d live coupons linked.', count($live), 'vc-merchant'),
        count($live))) . '</p>';

    if (!empty($live)) {
        $shown = array_slice($live, 0, 8);

        echo '<ul class="vc-coupon-list">';
        foreach ($shown as $coupon) {
            $code = trim((string) get_post_meta($coupon->ID, '_wcd_code', true));
            $ok   = (int) get_post_meta($coupon->ID, '_wcd_success_count', true);
            $bad  = (int) get_post_meta($coupon->ID, '_wcd_fail_count', true);
            $edit = function_exists('get_edit_post_link') ? get_edit_post_link($coupon->ID) : '';

            echo '<li class="vc-coupon-list__item">';

            $title = get_the_title($coupon->ID);
            if ($edit) {
                printf('<a class="vc-coupon-list__title" href="%s">%s</a>',
                    esc_url($edit), esc_html($title));
            } else {
                echo '<span class="vc-coupon-list__title">' . esc_html($title) . '</span>';
            }

            echo '<span class="vc-coupon-list__meta">';
            if ($code !== '') {
                echo '<code class="vc-coupon-list__code">' . esc_html($code) . '</code>';
            }
            if ($ok || $bad) {
                // Readers' own reports. This is what decides whether the page
                // is allowed to call a code verified, so show the split.
                printf(
                    '<span class="vc-coupon-list__votes" title="%s">%s / %s</span>',
                    esc_attr__('Reader reports: worked / did not work', 'vc-merchant'),
                    esc_html(sprintf(__('%d ok', 'vc-merchant'), $ok)),
                    esc_html(sprintf(__('%d bad', 'vc-merchant'), $bad))
                );
            }
            echo '</span>';

            echo '</li>';
        }
        echo '</ul>';

        $extra = count($live) - count($shown);
        if ($extra > 0) {
            echo '<p class="description">' . esc_html(sprintf(
                _n('%d more not shown.', '%d more not shown.', $extra, 'vc-merchant'),
                $extra)) . '</p>';
        }
    }

    if (function_exists('admin_url')) {
        // The admin list filters by slug. If a term somehow has none, link to
        // the unfiltered list rather than emitting a broken filter.
        $slug = isset($term->slug) ? (string) $term->slug : '';
        $target = 'edit.php?post_type=wcd_coupon';
        if ($slug !== '') {
            $target .= '&wcd_brand=' . urlencode($slug);
        }
        printf(
            '<p class="vc-coupon-manage"><a href="%s">%s</a></p>',
            esc_url(admin_url($target)),
            esc_html__('Manage this brand\'s coupons', 'vc-merchant')
        );
    }
}

/**
 * Styles for the "Linked coupons" box.
 *
 * Inline so there is no extra request for a handful of rules, and printed only
 * on the merchant editor screen.
 */
add_action('admin_head', function () {
    if (!vc_coupons_active() || !function_exists('get_current_screen')) {
        return;
    }
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->post_type, vc_merchant_post_types(), true)) {
        return;
    }
    echo '<style>
    .vc-coupon-status { margin: 0 0 8px; }
    .vc-coupon-status__badge { display: inline-block; padding: 2px 8px; border-radius: 10px;
        font-size: 11px; font-weight: 600; background: #f0f0f1; color: #3c434a; }
    .vc-coupon-status--verified-code { background: #e3f3e8; color: #0a5c33; }
    .vc-coupon-status--best-deal { background: #fdf3e0; color: #76520c; }
    .vc-coupon-list { margin: 8px 0 4px; padding: 0; list-style: none; }
    .vc-coupon-list__item { display: flex; flex-direction: column; gap: 2px;
        padding: 6px 0; border-top: 1px solid #f0f0f1; }
    .vc-coupon-list__item:first-child { border-top: 0; }
    .vc-coupon-list__title { font-size: 12px; line-height: 1.4; overflow-wrap: anywhere; }
    .vc-coupon-list__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
    .vc-coupon-list__code { font-size: 11px; padding: 1px 5px; background: #f6f7f7; }
    .vc-coupon-list__votes { font-size: 11px; color: #646970; cursor: help; }
    .vc-coupon-manage { margin: 8px 0 0; font-size: 12px; }
    </style>';
});

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
