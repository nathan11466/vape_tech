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
            $text = trim((string) get_post_meta($post_id, 'best_ways_to_save', true));

            // ReHub runs both store descriptions through do_shortcode
            // (taxonomy-dealstore.php:60 and :201), which is the only way to
            // get coupon cards onto a ReHub store page: its own listing is
            // post_type => 'post', so neither wcd_coupon posts nor our
            // merchant posts can ever appear in it.
            $embed = vc_rehub_coupon_embed($post_id);
            if ($embed !== '') {
                $text = $text === '' ? $embed : $text . "\n\n" . $embed;
            }

            return $text;
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
 * The coupon shortcode to embed on a ReHub store page, if any.
 *
 * Returns '' when the option is off, the coupon plugin is absent, or this
 * store has no brand to scope the shortcode to -- an unscoped one would list
 * every coupon on the site on every store page.
 */
function vc_rehub_coupon_embed($post_id) {
    if (get_option('vc_rehub_embed_coupons', '') !== '1') {
        return '';
    }
    if (!function_exists('vc_merchant_coupon_shortcode')) {
        return '';
    }

    return trim((string) vc_merchant_coupon_shortcode($post_id));
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
 * May the sync write over what is currently on the ReHub side?
 *
 * The rule the importer already uses for page bodies, applied here: replace
 * our own value, never somebody else's.
 *
 * @param string      $current   What is there now.
 * @param string|null $last      md5 of what the last sync wrote, if any.
 * @param bool        $overwrite Explicitly asked to replace regardless.
 */
function vc_rehub_may_write($current, $last, $overwrite) {
    if ($overwrite) {
        return true;
    }
    if ($current === '') {
        // Nothing there to lose.
        return true;
    }
    if ($last === null) {
        // Present, but the sync never wrote it -- so it pre-dates the sync or
        // was typed in. Leave it.
        return false;
    }

    // Ours, and untouched since. Safe to bring up to date.
    return md5($current) === $last;
}

/**
 * Mirror one merchant into ReHub.
 *
 * @param int  $post_id   Merchant post.
 * @param bool $overwrite Replace everything, including values an editor
 *                        changed in ReHub. Rarely needed: without it the
 *                        sync still updates any value it wrote itself and
 *                        left untouched, so editing a store and re-syncing
 *                        works. It only declines to touch a value somebody
 *                        else changed.
 * @return array{term_id:int,fields:int,offer:int,kept:int}|WP_Error
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

    // What the last sync wrote, so its own values can be told from an
    // editor's. Keyed md5s rather than the values themselves, to keep the
    // record small.
    $written_log = get_post_meta($post_id, '_vc_rehub_written', true);
    if (!is_array($written_log)) {
        $written_log = array();
    }

    $written = $offer = $kept = 0;

    foreach (vc_rehub_term_map() as $key => $source) {
        $value = trim((string) call_user_func($source, $post_id));
        if ($value === '') {
            continue;
        }

        $current = trim((string) get_term_meta($term->term_id, $key, true));
        $last = $written_log['term'][$key] ?? null;

        if (!vc_rehub_may_write($current, $last, $overwrite)) {
            $kept++;
            continue;
        }

        update_term_meta($term->term_id, $key, $value);
        $written_log['term'][$key] = md5($value);
        $written++;
    }

    foreach (vc_rehub_offer_map() as $key => $source) {
        $value = trim((string) call_user_func($source, $post_id));
        if ($value === '') {
            continue;
        }

        $current = trim((string) get_post_meta($post_id, $key, true));
        $last = $written_log['offer'][$key] ?? null;

        if (!vc_rehub_may_write($current, $last, $overwrite)) {
            $kept++;
            continue;
        }

        update_post_meta($post_id, $key, $value);
        $written_log['offer'][$key] = md5($value);
        $offer++;
    }

    update_post_meta($post_id, '_vc_rehub_written', $written_log);

    // Assign the merchant post to its store term. This does NOT make it
    // appear in ReHub's store listing -- that query is post_type => 'post'
    // only (module_shortcodes.php, the default branch), so a custom post type
    // can never show there. It is done for the admin column and so our own
    // queries can find the pair.
    wp_set_object_terms($post_id, array((int) $term->term_id), 'dealstore', false);

    return array('term_id' => (int) $term->term_id, 'fields' => $written,
                 'offer' => $offer, 'kept' => $kept);
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

    $report = array('stores' => 0, 'fields' => 0, 'offer' => 0, 'kept' => 0,
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
        $report['kept']   += $result['kept'];
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
    $handover_notice = '';

    if (!empty($_POST['vc_rehub_nonce'])
        && wp_verify_nonce($_POST['vc_rehub_nonce'], 'vc_rehub_sync')) {

        if (isset($_POST['vc_rehub_options_submit'])) {
            update_option('vc_rehub_embed_coupons',
                !empty($_POST['embed_coupons']) ? '1' : '');
            $handover_notice = __('Saved. Re-sync to push the change onto stores already synced.', 'vc-merchant');
        } elseif (isset($_POST['vc_rehub_handover_submit'])) {
            $want = !empty($_POST['handover']);

            // Refuse to hand over before the stores exist, which would 301
            // every merchant page to a term that is not there -- i.e. leave
            // the pages reachable but pointing nowhere useful.
            $unmatched = vc_rehub_unmatched_count();
            if ($want && $unmatched > 0) {
                $handover_notice = sprintf(
                    /* translators: %d: number of stores */
                    __('Not switched on: %d stores have no ReHub store to point at yet. Sync first, then try again.', 'vc-merchant'),
                    $unmatched);
            } else {
                update_option('vc_rehub_handover', $want ? '1' : '');
                flush_rewrite_rules();
                $handover_notice = $want
                    ? __('Handover is on. Store pages now redirect to ReHub and internal links point there.', 'vc-merchant')
                    : __('Handover is off. Store pages serve their own content again.', 'vc-merchant');
            }
        } else {
            $limit = isset($_POST['batch']) ? max(1, (int) $_POST['batch']) : 25;
            $report = vc_rehub_sync_batch($limit, !empty($_POST['overwrite']));
        }
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
                <?php if (!empty($report['kept'])) : ?>
                    <p><?php echo esc_html(sprintf(
                        /* translators: %d: number of fields */
                        _n('%d field was left alone because it was changed in ReHub rather than here.',
                           '%d fields were left alone because they were changed in ReHub rather than here.',
                           $report['kept'], 'vc-merchant'),
                        $report['kept'])); ?>
                        <?php esc_html_e('Tick "Replace everything" below to overrule that.', 'vc-merchant'); ?></p>
                <?php endif; ?>
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
                    <th scope="row"><?php esc_html_e('Coupon cards', 'vc-merchant'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="embed_coupons" value="1"
                                <?php checked(get_option('vc_rehub_embed_coupons', '') === '1'); ?> />
                            <?php esc_html_e('Embed the coupon shortcode on each ReHub store page', 'vc-merchant'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('ReHub\'s own store listing queries ordinary posts only, so neither the coupon plugin\'s coupons nor our store records can appear in it. ReHub does run both store descriptions through the shortcode parser, which is how the coupon cards get there. Save this, then sync.', 'vc-merchant'); ?>
                        </p>
                        <?php submit_button(__('Save option', 'vc-merchant'), 'secondary',
                            'vc_rehub_options_submit', false); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Existing values', 'vc-merchant'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="overwrite" value="1" />
                            <?php esc_html_e('Replace everything, including fields changed in ReHub', 'vc-merchant'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('You should rarely need this. A normal sync already updates anything it wrote itself, so editing a store here and re-syncing works. It only leaves alone a value somebody changed on the ReHub side.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Sync to ReHub', 'vc-merchant'), 'primary', 'submit', true,
                $active ? array() : array('disabled' => 'disabled')); ?>
        </form>

        <hr />

        <h2><?php esc_html_e('Hand the store page over to ReHub', 'vc-merchant'); ?></h2>

        <?php if ($handover_notice) : ?>
            <div class="notice notice-info"><p><?php echo esc_html($handover_notice); ?></p></div>
        <?php endif; ?>

        <?php $unmatched = vc_rehub_unmatched_count(); ?>

        <p class="description" style="max-width:700px;">
            <?php esc_html_e('With ReHub rendering the store page, our own store pages become a second URL for the same shop, which is duplicate content. Switching this on redirects each of our pages to its ReHub store with a 301, points every internal link there, and drops them from the sitemap.', 'vc-merchant'); ?>
        </p>
        <p class="description" style="max-width:700px;">
            <?php esc_html_e('The pages are redirected rather than deleted, so whatever links and ranking they have passes to the page taking over, and the posts stay editable - they are still what the CSV pipeline writes to. Switching it back off restores them.', 'vc-merchant'); ?>
        </p>

        <?php if ($unmatched > 0) : ?>
            <div class="notice notice-warning inline" style="max-width:700px;">
                <p><?php echo esc_html(sprintf(
                    _n('%d store has no ReHub store to point at. Sync before handing over, or that page would redirect nowhere.',
                       '%d stores have no ReHub store to point at. Sync before handing over, or those pages would redirect nowhere.',
                       $unmatched, 'vc-merchant'),
                    $unmatched)); ?></p>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field('vc_rehub_sync', 'vc_rehub_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Status', 'vc-merchant'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="handover" value="1"
                                <?php checked(vc_rehub_handover()); ?> />
                            <?php esc_html_e('Redirect our store pages to ReHub', 'vc-merchant'); ?>
                        </label>
                        <p class="description">
                            <?php echo vc_rehub_handover()
                                ? esc_html__('Currently on.', 'vc-merchant')
                                : esc_html__('Currently off - both sets of pages are live.', 'vc-merchant'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save handover setting', 'vc-merchant'), 'secondary',
                'vc_rehub_handover_submit', false,
                $active ? array() : array('disabled' => 'disabled')); ?>
        </form>
    </div>
    <?php
}

/**
 * How many merchants have no ReHub store to point at.
 *
 * Checked before the handover is allowed on, because a page redirecting to a
 * store that does not exist is worse than two pages that do.
 */
function vc_rehub_unmatched_count() {
    if (!vc_rehub_active()) {
        return 0;
    }

    $ids = get_posts(array(
        'post_type'      => vc_merchant_post_types(),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));

    $missing = 0;
    foreach ($ids as $post_id) {
        if (!vc_rehub_store_term($post_id, false)) {
            $missing++;
        }
    }

    return $missing;
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

/* -------------------------------------------------------------------------
 * Handover: let ReHub's store page be the only one
 *
 * With ReHub rendering the store page, our merchant pages become a second
 * indexable URL for the same shop. This hands the page over: every internal
 * link points at ReHub's store, and our URL 301s there.
 *
 * Deliberately a redirect rather than unregistering the post type. Making it
 * non-public would 404 all 112 URLs and throw away whatever links and
 * ranking they have; a 301 passes that to the page taking over. The posts
 * also stay editable, which is what the whole pipeline writes to.
 * ---------------------------------------------------------------------- */

/**
 * Is the handover switched on?
 *
 * Off unless ReHub is actually present, so flipping the option on a site
 * without the theme cannot strand every store page.
 */
function vc_rehub_handover() {
    if (!vc_rehub_active()) {
        return false;
    }

    return (bool) apply_filters('vc_rehub_handover',
        get_option('vc_rehub_handover', '') === '1');
}

/**
 * Where a store's page lives.
 *
 * The ReHub store archive once handed over, our own permalink otherwise.
 * Every internal link goes through this, so the two can never disagree.
 */
function vc_merchant_permalink($post_id) {
    $own = get_permalink($post_id);

    if (!vc_rehub_handover()) {
        return $own;
    }

    $term = vc_rehub_store_term($post_id, false);
    if (!$term) {
        // No store to point at. Keep our own URL rather than linking nowhere.
        return $own;
    }

    $link = get_term_link($term);

    return is_wp_error($link) ? $own : $link;
}

/**
 * Send a merchant page to the ReHub store that replaced it.
 *
 * Only once handed over, and only when there is somewhere to go.
 */
add_action('template_redirect', function () {
    if (!is_singular() || !vc_rehub_handover()) {
        return;
    }
    if (!in_array(get_post_type(), vc_merchant_post_types(), true)) {
        return;
    }

    $post_id = get_the_ID();
    $term = vc_rehub_store_term($post_id, false);
    if (!$term) {
        return;
    }

    $link = get_term_link($term);
    if (is_wp_error($link)) {
        return;
    }

    wp_safe_redirect($link, 301);
    exit;
}, 1);

/**
 * Belt and braces: keep a handed-over page out of the index even if
 * something upstream stops the redirect from firing.
 */
add_filter('rank_math/frontend/robots', function ($robots) {
    if (!is_singular() || !vc_rehub_handover()) {
        return $robots;
    }
    if (!in_array(get_post_type(), vc_merchant_post_types(), true)) {
        return $robots;
    }

    $robots['index'] = 'noindex';
    $robots['follow'] = 'follow';

    return $robots;
});

/**
 * Drop our store pages from the sitemap once ReHub owns them.
 */
add_filter('wp_sitemaps_post_types', function ($post_types) {
    if (!vc_rehub_handover()) {
        return $post_types;
    }
    foreach (vc_merchant_post_types() as $type) {
        unset($post_types[$type]);
    }

    return $post_types;
});
add_filter('rank_math/sitemap/exclude_post_type', function ($exclude, $type) {
    return vc_rehub_handover() && in_array($type, vc_merchant_post_types(), true)
        ? true : $exclude;
}, 10, 2);
