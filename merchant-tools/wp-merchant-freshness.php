<?php
/**
 * Keep the month in cached pages current.
 *
 * Titles and descriptions carry the current month and year, generated at
 * render time. A page cache stores the rendered HTML, so on 1 November every
 * cached page still says "October" until something clears it -- the date is
 * right in the code and wrong in the SERP.
 *
 * Deliberately a daily check of whether the month has changed rather than a
 * cron scheduled for the 1st. WP-Cron only fires when someone visits, so an
 * event due at 00:00 on the 1st can be missed entirely on a quiet site and,
 * depending on how it was scheduled, skipped rather than run late. Comparing
 * a stored month against the current one catches up whenever the site is
 * next visited, however long that takes.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Schedule the daily check. Idempotent.
 */
function vc_freshness_schedule() {
    if (!wp_next_scheduled('vc_merchant_freshness_check')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'vc_merchant_freshness_check');
    }
}
add_action('init', 'vc_freshness_schedule');

/**
 * Stop the schedule when the plugin is deactivated, so WordPress is not left
 * firing an event nothing handles.
 */
function vc_freshness_unschedule() {
    $next = wp_next_scheduled('vc_merchant_freshness_check');
    if ($next) {
        wp_unschedule_event($next, 'vc_merchant_freshness_check');
    }
}

/**
 * Clear page caches if the month has rolled over since the last check.
 *
 * @param bool $force Clear regardless of the stored month.
 * @return bool Whether a purge was performed.
 */
function vc_freshness_check($force = false) {
    $current = current_time('Y-m');
    $stored  = (string) get_option('vc_merchant_freshness_month', '');

    if (!$force && $stored === $current) {
        return false;
    }

    // Written before purging, not after. A purge that fatals partway through
    // would otherwise leave the month unrecorded and purge again on every
    // subsequent request.
    update_option('vc_merchant_freshness_month', $current);

    // First run on an existing site: record the month without clearing
    // anything, since nothing is stale yet and a purge on activation is a
    // surprise on a large site.
    if ($stored === '' && !$force) {
        return false;
    }

    vc_freshness_purge_caches();

    return true;
}
add_action('vc_merchant_freshness_check', 'vc_freshness_check');

// Also check on a normal page load, so a site whose cron is disabled
// (DISABLE_WP_CRON) still catches up. The option comparison makes this a
// single cheap read in the overwhelming majority of requests.
add_action('admin_init', function () {
    vc_freshness_check();
});

/**
 * Clear whichever page cache is installed.
 *
 * Each call is guarded, so this is safe with none, one, or several of them
 * active. Only full-page caches are touched -- these pages carry a month in
 * the title, so a partial purge would leave some of them stale.
 */
function vc_freshness_purge_caches() {
    // WP Rocket
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
    // LiteSpeed Cache
    if (has_action('litespeed_purge_all')) {
        do_action('litespeed_purge_all');
    }
    // W3 Total Cache
    if (function_exists('w3tc_flush_posts')) {
        w3tc_flush_posts();
    }
    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
    // WP Fastest Cache
    if (isset($GLOBALS['wp_fastest_cache']) && method_exists($GLOBALS['wp_fastest_cache'], 'deleteCache')) {
        $GLOBALS['wp_fastest_cache']->deleteCache(true);
    }
    // Autoptimize
    if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
        autoptimizeCache::clearall();
    }
    // SiteGround Optimizer
    if (function_exists('sg_cachepress_purge_cache')) {
        sg_cachepress_purge_cache();
    }

    // Our own cached brand figures, which also feed the title.
    vc_freshness_clear_brand_figures();

    /**
     * For any cache not covered above.
     */
    do_action('vc_merchant_caches_purged');
}

/**
 * Drop the cached per-brand figures so they are recomputed.
 */
function vc_freshness_clear_brand_figures() {
    if (!taxonomy_exists('wcd_brand')) {
        return;
    }
    $terms = get_terms(array(
        'taxonomy'   => 'wcd_brand',
        'hide_empty' => false,
        'fields'     => 'ids',
    ));
    if (is_wp_error($terms)) {
        return;
    }
    foreach ($terms as $term_id) {
        delete_term_meta($term_id, '_vc_figures_updated');
    }
}

/* -------------------------------------------------------------------------
 * Admin: show the state, and allow a manual purge
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    foreach (vc_merchant_post_types() as $post_type) {
        add_submenu_page(
            'edit.php?post_type=' . $post_type,
            __('Freshness', 'vc-merchant'),
            __('Freshness', 'vc-merchant'),
            'manage_options',
            'vc-merchant-freshness',
            'vc_freshness_screen'
        );
        break;
    }
}, 23);

function vc_freshness_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $purged = false;
    if (!empty($_POST['vc_freshness_nonce'])
        && wp_verify_nonce($_POST['vc_freshness_nonce'], 'vc_freshness_purge')) {
        vc_freshness_purge_caches();
        update_option('vc_merchant_freshness_month', current_time('Y-m'));
        $purged = true;
    }

    $stored = (string) get_option('vc_merchant_freshness_month', '');
    $next = wp_next_scheduled('vc_merchant_freshness_check');
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Freshness', 'vc-merchant'); ?></h1>

        <?php if ($purged) : ?>
            <div class="notice notice-success"><p><?php esc_html_e('Page caches cleared.', 'vc-merchant'); ?></p></div>
        <?php endif; ?>

        <p class="description" style="max-width:680px;">
            <?php esc_html_e('Store titles and descriptions include the current month, generated when the page renders. A page cache stores the finished HTML, so without this the pages would keep saying last month until something cleared them. This checks daily whether the month has rolled over and clears the cache when it has.', 'vc-merchant'); ?>
        </p>

        <table class="widefat striped" style="max-width:680px;">
            <tbody>
                <tr>
                    <td style="width:260px;"><?php esc_html_e('Month pages were built for', 'vc-merchant'); ?></td>
                    <td><?php echo $stored === ''
                        ? esc_html__('not recorded yet', 'vc-merchant')
                        : esc_html($stored); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Current month', 'vc-merchant'); ?></td>
                    <td><?php echo esc_html(current_time('Y-m')); ?></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('Next automatic check', 'vc-merchant'); ?></td>
                    <td><?php echo $next
                        ? esc_html(date_i18n('j M Y, H:i', $next))
                        : esc_html__('not scheduled', 'vc-merchant'); ?></td>
                </tr>
            </tbody>
        </table>

        <form method="post">
            <?php wp_nonce_field('vc_freshness_purge', 'vc_freshness_nonce'); ?>
            <?php submit_button(__('Clear page caches now', 'vc-merchant'), 'secondary'); ?>
        </form>
    </div>
    <?php
}
