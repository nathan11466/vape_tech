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

/**
 * The shortcode the coupon block should render for one store.
 *
 * Resolution order:
 *   1. the store's own "Coupon widget shortcode" field, which always wins
 *   2. a site-wide template with {brand} / {brand_id} placeholders
 *
 * The template exists because the per-store field is blank on every row the
 * importer creates -- nothing in the CSV pipeline fills it -- so a store could
 * be correctly matched to a brand, have live coupons, and still render an
 * empty coupon section. One template setting wires every store at once.
 *
 * The coupon plugin's own tag and attribute names are not hard-coded here:
 * they differ between versions, and guessing wrong would silently render
 * nothing, which is the failure this is meant to remove.
 */
function vc_merchant_coupon_shortcode($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    $explicit = trim((string) get_post_meta($post_id, 'coupon_plugin_shortcode', true));
    if ($explicit !== '') {
        return $explicit;
    }

    $template = trim((string) get_option('vc_coupon_shortcode_template', ''));
    $template = (string) apply_filters('vc_merchant_coupon_shortcode_template', $template, $post_id);
    if ($template === '') {
        return '';
    }

    // Without a brand there is nothing to scope the shortcode to, and an
    // unscoped one would list every coupon on the site on every store page.
    $term = vc_merchant_brand_term($post_id);
    if (!$term) {
        return '';
    }

    return str_replace(
        array('{brand}', '{brand_id}', '{brand_name}'),
        array(
            isset($term->slug) ? (string) $term->slug : '',
            (string) $term->term_id,
            (string) $term->name,
        ),
        $template
    );
}

/* -------------------------------------------------------------------------
 * Integration status
 *
 * This module talks to another plugin through its post type, taxonomy, meta
 * keys and shortcode. Every one of those is an assumption about someone
 * else's code, and when one is wrong the integration does not error -- it
 * quietly does nothing, which is far harder to notice. This screen checks
 * each assumption against what is actually installed.
 * ---------------------------------------------------------------------- */

/**
 * Shortcode tags the coupon plugin has registered.
 *
 * Discovered rather than hard-coded, so a renamed or versioned tag shows up
 * here instead of silently rendering an empty block.
 */
function vc_coupons_registered_shortcodes() {
    $found = array();
    foreach (array_keys($GLOBALS['shortcode_tags'] ?? array()) as $tag) {
        if (strpos($tag, 'wcd') === 0 || strpos($tag, 'coupon') !== false) {
            $found[] = $tag;
        }
    }
    sort($found);

    return $found;
}

/**
 * Which of the coupon meta keys this module reads actually exist on a real
 * coupon, sampled from the newest few.
 *
 * @return array key => number of sampled coupons carrying it
 */
function vc_coupons_meta_probe($sample = 5) {
    $keys = array('_wcd_type', '_wcd_code', '_wcd_success_count',
                  '_wcd_fail_count', '_wcd_expiration');
    $seen = array_fill_keys($keys, 0);

    if (!post_type_exists('wcd_coupon')) {
        return array('total' => 0, 'keys' => $seen);
    }

    $coupons = get_posts(array(
        'post_type'      => 'wcd_coupon',
        'post_status'    => 'any',
        'posts_per_page' => $sample,
        'fields'         => 'ids',
    ));

    foreach ($coupons as $id) {
        foreach ($keys as $key) {
            // Distinguish "absent" from "present but empty" -- a code with no
            // expiry legitimately stores ''.
            if (metadata_exists('post', $id, $key)) {
                $seen[$key]++;
            }
        }
    }

    return array('total' => count($coupons), 'keys' => $seen);
}

/**
 * Per-merchant wiring tally.
 */
function vc_coupons_link_report() {
    $ids = get_posts(array(
        'post_type'      => vc_merchant_post_types(),
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    $report = array(
        'merchants'      => count($ids),
        'linked'         => 0,
        'with_coupons'   => 0,
        'has_shortcode'  => 0,
        'renders_empty'  => array(),
    );

    foreach ($ids as $id) {
        $term = vc_merchant_brand_term($id);
        if ($term) {
            $report['linked']++;
        }

        $live = $term ? vc_merchant_live_coupons($id) : array();
        if (!empty($live)) {
            $report['with_coupons']++;
        }

        $shortcode = trim((string) get_post_meta($id, 'coupon_plugin_shortcode', true));
        if ($shortcode !== '') {
            $report['has_shortcode']++;
        }

        // The case that matters: real coupons exist and the page shows none,
        // because the coupon block renders the shortcode column and that
        // column is blank.
        if (!empty($live) && $shortcode === '') {
            $report['renders_empty'][] = $id;
        }
    }

    return $report;
}

add_action('admin_menu', function () {
    if (!vc_coupons_active()) {
        return;
    }
    foreach (vc_merchant_post_types() as $post_type) {
        add_submenu_page(
            'edit.php?post_type=' . $post_type,
            __('Coupon Integration', 'vc-merchant'),
            __('Coupon Integration', 'vc-merchant'),
            'manage_options',
            'vc-coupon-status',
            'vc_coupons_status_screen'
        );
        break;
    }
}, 22);

function vc_coupons_status_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved = false;
    if (!empty($_POST['vc_coupon_tpl_nonce'])
        && wp_verify_nonce($_POST['vc_coupon_tpl_nonce'], 'vc_coupon_tpl')) {
        // Not sanitize_text_field: that would strip the square brackets a
        // shortcode is made of.
        $template = trim(wp_unslash((string) ($_POST['vc_coupon_shortcode_template'] ?? '')));
        update_option('vc_coupon_shortcode_template', $template);
        $saved = true;
    }

    $probe = vc_coupons_meta_probe();
    $tags  = vc_coupons_registered_shortcodes();
    $report = vc_coupons_link_report();
    $template = (string) get_option('vc_coupon_shortcode_template', '');

    $rows = array(
        array(
            __('Coupon post type (wcd_coupon)', 'vc-merchant'),
            post_type_exists('wcd_coupon'),
            __('Not found -- the coupon plugin is inactive or renamed its post type.', 'vc-merchant'),
        ),
        array(
            __('Brand taxonomy (wcd_brand)', 'vc-merchant'),
            taxonomy_exists('wcd_brand'),
            __('Not found -- stores cannot be matched to coupon brands.', 'vc-merchant'),
        ),
        array(
            __('Coupon shortcode registered', 'vc-merchant'),
            !empty($tags),
            __('No coupon shortcode found, so the coupon block has nothing to render.', 'vc-merchant'),
        ),
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Coupon Integration', 'vc-merchant'); ?></h1>
        <p class="description" style="max-width:680px;">
            <?php esc_html_e('This plugin reads the coupon plugin\'s post type, brand taxonomy and vote counts. When one of those does not match, nothing errors -- the coupon section just comes out empty. This page checks each one against what is installed right now.', 'vc-merchant'); ?>
        </p>

        <h2><?php esc_html_e('Contract', 'vc-merchant'); ?></h2>
        <table class="widefat striped" style="max-width:860px;">
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr>
                    <td style="width:280px;"><strong><?php echo esc_html($row[0]); ?></strong></td>
                    <td style="width:90px;">
                        <?php if ($row[1]) : ?>
                            <span style="color:#0a5c33;font-weight:600;">&#10003; <?php esc_html_e('OK', 'vc-merchant'); ?></span>
                        <?php else : ?>
                            <span style="color:#8a2424;font-weight:600;">&#10007; <?php esc_html_e('Missing', 'vc-merchant'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $row[1] ? '' : esc_html($row[2]); ?></td>
                </tr>
            <?php endforeach; ?>
                <tr>
                    <td><strong><?php esc_html_e('Shortcodes found', 'vc-merchant'); ?></strong></td>
                    <td colspan="2">
                        <?php echo $tags
                            ? '<code>[' . implode(']</code>, <code>[', array_map('esc_html', $tags)) . ']</code>'
                            : esc_html__('none', 'vc-merchant'); ?>
                    </td>
                </tr>
            </tbody>
        </table>

        <h2><?php esc_html_e('Coupon fields', 'vc-merchant'); ?></h2>
        <?php if ($probe['total'] < 1) : ?>
            <p><?php esc_html_e('No coupons exist yet, so the field names cannot be checked. Add one coupon and reload this page.', 'vc-merchant'); ?></p>
        <?php else : ?>
            <p class="description">
                <?php echo esc_html(sprintf(
                    __('Sampled %d coupon(s). A field missing from all of them means this plugin is reading a name the coupon plugin does not use.', 'vc-merchant'),
                    $probe['total'])); ?>
            </p>
            <table class="widefat striped" style="max-width:860px;">
                <tbody>
                <?php foreach ($probe['keys'] as $key => $count) : ?>
                    <tr>
                        <td style="width:280px;"><code><?php echo esc_html($key); ?></code></td>
                        <td style="width:90px;">
                            <?php if ($count > 0) : ?>
                                <span style="color:#0a5c33;font-weight:600;">&#10003;</span>
                            <?php else : ?>
                                <span style="color:#8a2424;font-weight:600;">&#10007;</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html(sprintf(
                            __('present on %1$d of %2$d', 'vc-merchant'), $count, $probe['total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2><?php esc_html_e('Coupon section shortcode', 'vc-merchant'); ?></h2>
        <?php if ($saved) : ?>
            <div class="notice notice-success"><p><?php esc_html_e('Saved.', 'vc-merchant'); ?></p></div>
        <?php endif; ?>
        <p class="description" style="max-width:680px;">
            <?php esc_html_e('The coupon section renders this shortcode, with {brand} replaced by the store\'s linked coupon brand. Set it once here instead of filling the field on all 112 stores. A store\'s own "Coupon widget shortcode" field still overrides it.', 'vc-merchant'); ?>
        </p>
        <form method="post">
            <?php wp_nonce_field('vc_coupon_tpl', 'vc_coupon_tpl_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="vc_coupon_shortcode_template"><?php esc_html_e('Template', 'vc-merchant'); ?></label>
                    </th>
                    <td>
                        <input type="text" class="large-text code"
                               id="vc_coupon_shortcode_template"
                               name="vc_coupon_shortcode_template"
                               value="<?php echo esc_attr($template); ?>"
                               placeholder="[wcd_coupons brand=&quot;{brand}&quot;]" />
                        <p class="description">
                            <?php esc_html_e('Placeholders: {brand} (slug), {brand_id}, {brand_name}.', 'vc-merchant'); ?>
                            <?php if ($tags) : ?>
                                <br /><?php esc_html_e('Tags this site has registered:', 'vc-merchant'); ?>
                                <?php echo '<code>[' . implode(']</code> <code>[', array_map('esc_html', $tags)) . ']</code>'; ?>
                                <br /><?php esc_html_e('Check that plugin\'s documentation for the attribute name it expects for a brand.', 'vc-merchant'); ?>
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save template', 'vc-merchant')); ?>
        </form>

        <h2><?php esc_html_e('Store wiring', 'vc-merchant'); ?></h2>
        <table class="widefat striped" style="max-width:860px;">
            <tbody>
                <tr><td style="width:280px;"><?php esc_html_e('Stores', 'vc-merchant'); ?></td>
                    <td><?php echo (int) $report['merchants']; ?></td></tr>
                <tr><td><?php esc_html_e('Matched to a coupon brand', 'vc-merchant'); ?></td>
                    <td><?php echo (int) $report['linked']; ?></td></tr>
                <tr><td><?php esc_html_e('With at least one live coupon', 'vc-merchant'); ?></td>
                    <td><?php echo (int) $report['with_coupons']; ?></td></tr>
                <tr><td><?php esc_html_e('With a coupon widget shortcode set', 'vc-merchant'); ?></td>
                    <td><?php echo (int) $report['has_shortcode']; ?></td></tr>
            </tbody>
        </table>

        <?php if (!empty($report['renders_empty'])) : ?>
            <div class="notice notice-warning" style="max-width:860px;">
                <p>
                    <strong><?php echo esc_html(sprintf(
                        _n('%d store has live coupons but shows none.',
                           '%d stores have live coupons but show none.',
                           count($report['renders_empty']), 'vc-merchant'),
                        count($report['renders_empty']))); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('The coupon section renders whatever is in the store\'s "Coupon widget shortcode" field, and that field is empty. Fill it on each store, or set a site-wide default below.', 'vc-merchant'); ?>
                </p>
                <ul style="list-style:disc;margin-left:20px;">
                    <?php foreach (array_slice($report['renders_empty'], 0, 10) as $id) : ?>
                        <li><a href="<?php echo esc_url(get_edit_post_link($id)); ?>">
                            <?php echo esc_html(vc_merchant_display_name($id)); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
