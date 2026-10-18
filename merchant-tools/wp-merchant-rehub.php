<?php
/**
 * ReHub adapter.
 *
 * ReHub keeps stores as `dealstore` terms on ordinary posts, with the store
 * page rendered by taxonomy-dealstore.php from term meta plus the posts
 * assigned to the term. Our data lives on `merchant` posts. This mirrors one
 * into the other so ReHub's own templates can render it, and our pipeline
 * stays the single place the data is edited.
 *
 * Every key below is read from the theme source, not guessed:
 *   dealstore term meta  - rehub-framework/includes/theme_metabox.php,
 *                          meta_for_brand_cat()
 *   offer post meta      - same file, the Post Offer section
 *
 * Nothing here runs unless ReHub's taxonomy actually exists, which depends on
 * the theme's `enable_brand_taxonomy` option being switched on.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Is ReHub's store taxonomy present?
 *
 * The taxonomy is registered only when the theme option is on, so its
 * existence is the honest test for "is this site a ReHub store site".
 */
function vc_rehub_active() {
    return taxonomy_exists('dealstore');
}

/**
 * The dealstore term fields, mapped to where our value comes from.
 *
 * Keys are ReHub's. 'source' is a callable taking a merchant post id.
 */
function vc_rehub_term_map() {
    return apply_filters('vc_rehub_term_map', array(
        // ReHub's own key happens to be the same name as our CSV column.
        'brand_url' => function ($post_id) {
            return trim((string) get_post_meta($post_id, 'brand_url', true));
        },
        'brandimage' => function ($post_id) {
            $logo = trim((string) get_post_meta($post_id, 'brand_logo_url', true));

            // Goes through the same filter as the page, so a logo the coupon
            // plugin holds is used when ours is blank.
            return (string) apply_filters('vc_merchant_logo_url', $logo, $post_id);
        },
        'brand_heading' => function ($post_id) {
            return function_exists('vc_merchant_seo_title')
                ? vc_merchant_seo_title($post_id) : '';
        },
        'brand_short_description' => function ($post_id) {
            $intro = trim((string) get_post_meta($post_id, 'coupon_intro', true));

            return $intro !== ''
                ? $intro
                : trim((string) get_post_meta($post_id, 'brand_summary', true));
        },
        'brand_second_description' => function ($post_id) {
            return trim((string) get_post_meta($post_id, 'best_ways_to_save', true));
        },
    ));
}

/**
 * The offer meta ReHub reads on a post, mapped to our values.
 *
 * This is what makes ReHub's offer and coupon layouts render our data.
 */
function vc_rehub_offer_map() {
    return apply_filters('vc_rehub_offer_map', array(
        'rehub_offer_name' => function ($post_id) {
            return function_exists('vc_merchant_display_name')
                ? vc_merchant_display_name($post_id) : get_the_title($post_id);
        },
        'rehub_offer_product_url' => function ($post_id) {
            return trim((string) get_post_meta($post_id, 'brand_url', true));
        },
        'rehub_offer_logo_url' => function ($post_id) {
            $logo = trim((string) get_post_meta($post_id, 'brand_logo_url', true));

            return (string) apply_filters('vc_merchant_logo_url', $logo, $post_id);
        },
        'rehub_offer_product_desc' => function ($post_id) {
            return trim((string) get_post_meta($post_id, 'best_offer_summary', true));
        },
        // The discount figure, which already refuses thresholds and prices.
        'rehub_offer_discount' => function ($post_id) {
            if (!function_exists('vc_merchant_headline_discount')) {
                return '';
            }
            list($figure, ) = vc_merchant_headline_discount($post_id);

            return $figure;
        },
        // The honesty gate, landing in ReHub's own "verified" label. A page
        // with no confirmed code gets no label rather than a misleading one.
        'rehub_offer_verify_label' => function ($post_id) {
            $mode = function_exists('vc_merchant_offer_mode')
                ? vc_merchant_offer_mode($post_id) : '';

            $labels = array(
                'verified_code' => __('Verified', 'vc-merchant'),
                'best_deal'     => __('Best deal', 'vc-merchant'),
            );

            return $labels[$mode] ?? '';
        },
        'rehub_offer_disclaimer' => function ($post_id) {
            $parts = array();

            $restricted = trim((string) get_post_meta($post_id, 'restricted_states', true));
            if ($restricted !== '') {
                $list = array_filter(array_map('trim', explode('|', $restricted)));
                if (!empty($list)) {
                    $parts[] = sprintf(
                        /* translators: %s: comma-separated list of places */
                        __('Cannot ship to: %s.', 'vc-merchant'), implode(', ', $list));
                }
            }

            $why = trim((string) get_post_meta($post_id, 'why_code_not_work', true));
            if ($why !== '') {
                $parts[] = $why;
            }

            return implode(' ', $parts);
        },
    ));
}

/**
 * The dealstore term for a merchant, created if absent.
 *
 * Matched by name, because that is what a human sees in both systems and
 * what ReHub's own store URL is derived from.
 */
function vc_rehub_store_term($post_id, $create = true) {
    if (!vc_rehub_active()) {
        return null;
    }

    $name = function_exists('vc_merchant_display_name')
        ? vc_merchant_display_name($post_id) : get_the_title($post_id);
    $name = trim((string) $name);
    if ($name === '') {
        return null;
    }

    // An explicit link set by hand always wins, so a store whose two names
    // genuinely differ can be pointed at the right term.
    $stored = (int) get_post_meta($post_id, 'rehub_dealstore_term_id', true);
    if ($stored) {
        $term = get_term($stored, 'dealstore');
        if ($term && !is_wp_error($term)) {
            return $term;
        }
    }

    $term = get_term_by('name', $name, 'dealstore');
    if ($term && !is_wp_error($term)) {
        return $term;
    }

    if (!$create) {
        return null;
    }

    $created = wp_insert_term($name, 'dealstore');
    if (is_wp_error($created)) {
        return null;
    }

    $term = get_term((int) $created['term_id'], 'dealstore');

    return ($term && !is_wp_error($term)) ? $term : null;
}

/**
 * Mirror one merchant into ReHub.
 *
 * @param int  $post_id   Merchant post.
 * @param bool $overwrite Replace values already present on the ReHub side.
 *                        Off by default: a field someone edited in ReHub is
 *                        their decision, and silently replacing it on the
 *                        next sync would destroy real work.
 * @return array{term_id:int,fields:int,offer:int}|WP_Error
 */
function vc_rehub_sync_merchant($post_id, $overwrite = false) {
    if (!vc_rehub_active()) {
        return new WP_Error('vc_rehub_absent',
            __('ReHub\'s store taxonomy is not registered. Switch on "Enable Affiliate Store taxonomy for posts" in the theme options first.', 'vc-merchant'));
    }

    $term = vc_rehub_store_term($post_id);
    if (!$term) {
        return new WP_Error('vc_rehub_no_term',
            __('Could not find or create a store for this merchant.', 'vc-merchant'));
    }

    update_post_meta($post_id, 'rehub_dealstore_term_id', (int) $term->term_id);

    $written = 0;
    foreach (vc_rehub_term_map() as $key => $source) {
        $value = trim((string) call_user_func($source, $post_id));
        if ($value === '') {
            continue;
        }
        if (!$overwrite && trim((string) get_term_meta($term->term_id, $key, true)) !== '') {
            continue;
        }
        update_term_meta($term->term_id, $key, $value);
        $written++;
    }

    $offer = 0;
    foreach (vc_rehub_offer_map() as $key => $source) {
        $value = trim((string) call_user_func($source, $post_id));
        if ($value === '') {
            continue;
        }
        if (!$overwrite && trim((string) get_post_meta($post_id, $key, true)) !== '') {
            continue;
        }
        update_post_meta($post_id, $key, $value);
        $offer++;
    }

    // Assign the merchant post to its own store term, so it appears in
    // ReHub's store archive and its filter panel.
    wp_set_object_terms($post_id, array((int) $term->term_id), 'dealstore', false);

    return array('term_id' => (int) $term->term_id, 'fields' => $written, 'offer' => $offer);
}

/**
 * Mirror a batch of merchants.
 */
function vc_rehub_sync_batch($limit = 0, $overwrite = false) {
    $ids = get_posts(array(
        'post_type'      => vc_merchant_post_types(),
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    $report = array('stores' => 0, 'fields' => 0, 'offer' => 0,
                    'errors' => array(), 'remaining' => 0);
    $done = 0;

    foreach ($ids as $post_id) {
        if ($limit > 0 && $done >= $limit) {
            $report['remaining'] = count($ids) - $done;
            break;
        }

        $result = vc_rehub_sync_merchant($post_id, $overwrite);
        $done++;

        if (is_wp_error($result)) {
            $name = function_exists('vc_merchant_display_name')
                ? vc_merchant_display_name($post_id) : $post_id;
            $report['errors'][] = $name . ': ' . $result->get_error_message();
            continue;
        }

        $report['stores']++;
        $report['fields'] += $result['fields'];
        $report['offer']  += $result['offer'];
    }

    return $report;
}

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    foreach (vc_merchant_post_types() as $post_type) {
        add_submenu_page(
            'edit.php?post_type=' . $post_type,
            __('ReHub Sync', 'vc-merchant'),
            __('ReHub Sync', 'vc-merchant'),
            'manage_options',
            'vc-merchant-rehub',
            'vc_rehub_screen'
        );
        break;
    }
}, 24);

function vc_rehub_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $report = null;
    if (!empty($_POST['vc_rehub_nonce'])
        && wp_verify_nonce($_POST['vc_rehub_nonce'], 'vc_rehub_sync')) {
        $limit = isset($_POST['batch']) ? max(1, (int) $_POST['batch']) : 25;
        $report = vc_rehub_sync_batch($limit, !empty($_POST['overwrite']));
    }

    $active = vc_rehub_active();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('ReHub Sync', 'vc-merchant'); ?></h1>

        <p class="description" style="max-width:700px;">
            <?php esc_html_e('ReHub renders a store page from its own dealstore term, not from a post. This copies each store\'s data onto that term, and the offer figures onto the post, so ReHub\'s templates show what the CSV pipeline produced. The pipeline stays the one place you edit the data.', 'vc-merchant'); ?>
        </p>

        <?php if (!$active) : ?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('ReHub\'s store taxonomy is not registered.', 'vc-merchant'); ?></strong>
                    <?php esc_html_e('Switch on "Enable Affiliate Store taxonomy for posts" under Theme Options → Affiliate, then reload this page. Nothing can be synced until then.', 'vc-merchant'); ?>
                </p>
            </div>
        <?php endif; ?>

        <?php if ($report !== null) : ?>
            <div class="notice notice-success">
                <p><?php echo esc_html(sprintf(
                    /* translators: 1: stores, 2: term fields, 3: offer fields, 4: remaining */
                    __('Synced %1$d stores. Store fields written: %2$d. Offer fields written: %3$d. Remaining: %4$d.', 'vc-merchant'),
                    $report['stores'], $report['fields'], $report['offer'], $report['remaining']
                )); ?></p>
            </div>
            <?php if (!empty($report['errors'])) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('Could not sync:', 'vc-merchant'); ?></strong></p>
                    <ul style="list-style:disc;margin-left:20px;">
                        <?php foreach (array_slice($report['errors'], 0, 10) as $line) : ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h2><?php esc_html_e('What gets copied', 'vc-merchant'); ?></h2>
        <table class="widefat striped" style="max-width:700px;">
            <thead><tr>
                <th><?php esc_html_e('ReHub field', 'vc-merchant'); ?></th>
                <th><?php esc_html_e('Where it comes from', 'vc-merchant'); ?></th>
            </tr></thead>
            <tbody>
                <tr><td><code>brand_url</code></td><td>brand_url</td></tr>
                <tr><td><code>brandimage</code></td><td><?php esc_html_e('brand_logo_url, or the coupon plugin\'s brand logo', 'vc-merchant'); ?></td></tr>
                <tr><td><code>brand_heading</code></td><td><?php esc_html_e('the generated SEO title', 'vc-merchant'); ?></td></tr>
                <tr><td><code>brand_short_description</code></td><td>coupon_intro, <?php esc_html_e('else', 'vc-merchant'); ?> brand_summary</td></tr>
                <tr><td><code>brand_second_description</code></td><td>best_ways_to_save</td></tr>
                <tr><td><code>rehub_offer_discount</code></td><td><?php esc_html_e('the headline discount figure', 'vc-merchant'); ?></td></tr>
                <tr><td><code>rehub_offer_verify_label</code></td><td><?php esc_html_e('the offer gate: Verified, Best deal, or nothing', 'vc-merchant'); ?></td></tr>
                <tr><td><code>rehub_offer_disclaimer</code></td><td>restricted_states, why_code_not_work</td></tr>
            </tbody>
        </table>

        <form method="post">
            <?php wp_nonce_field('vc_rehub_sync', 'vc_rehub_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="batch"><?php esc_html_e('Batch size', 'vc-merchant'); ?></label></th>
                    <td><input type="number" name="batch" id="batch" value="25" min="1" class="small-text" /></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Existing values', 'vc-merchant'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="overwrite" value="1" />
                            <?php esc_html_e('Replace values already set on the ReHub side', 'vc-merchant'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Off by default. A store field edited in ReHub is a deliberate choice, and replacing it on every sync would destroy that work.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Sync to ReHub', 'vc-merchant'), 'primary', 'submit', true,
                $active ? array() : array('disabled' => 'disabled')); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp merchant rehub [--limit=<n>] [--overwrite]
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant rehub', function ($args, $assoc) {
        if (!vc_rehub_active()) {
            WP_CLI::error('ReHub\'s dealstore taxonomy is not registered.');
        }

        $report = vc_rehub_sync_batch(
            isset($assoc['limit']) ? (int) $assoc['limit'] : 0,
            !empty($assoc['overwrite'])
        );

        WP_CLI::log(sprintf('stores   %d', $report['stores']));
        WP_CLI::log(sprintf('fields   %d', $report['fields']));
        WP_CLI::log(sprintf('offer    %d', $report['offer']));
        foreach ($report['errors'] as $line) {
            WP_CLI::warning($line);
        }
        if ($report['remaining']) {
            WP_CLI::log(sprintf('%d remaining -- run again.', $report['remaining']));
        }

        WP_CLI::success('ReHub sync finished.');
    });
}
