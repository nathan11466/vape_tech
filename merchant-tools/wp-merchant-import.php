<?php
/**
 * CSV importer: turns an enriched merchant CSV into WordPress posts.
 *
 * Available two ways -- an admin screen under Tools, and a WP-CLI command for
 * sites that have shell access. Both share the same import routine.
 *
 * The import is idempotent: rows are matched on externalMerchantKey, so
 * re-running updates the existing post instead of creating duplicates. Run it
 * as often as you like as the data improves.
 *
 * Post status follows the publish gate -- only rows graded Ready are
 * published; Needs review and Hold land as drafts.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Meta key holding the stable merchant identifier used for matching.
 */
const VC_MERCHANT_KEY_META = '_vc_merchant_key';

/**
 * Every column copied from the CSV into post meta.
 */
function vc_merchant_import_meta_keys() {
    return array(
        'externalMerchantKey', 'brand_url', 'coupon_plugin_shortcode', 'best_offer_summary',
        'last_checked_text', 'top_offer_type', 'coupon_intro', 'brand_summary',
        'best_ways_to_save', 'free_shipping_info', 'return_policy_summary', 'payment_methods',
        'common_exclusions', 'stacking_policy', 'why_code_not_work', 'verification_method',
        'editor_name', 'editor_title', 'brand_review_url', 'deals_hub_url', 'seasonal_deals_url',
        'brand_category', 'brand_logo_url', 'faq_1_question', 'faq_1_answer', 'faq_2_question',
        'faq_2_answer', 'faq_3_question', 'faq_3_answer', 'shipping_restrictions',
        'age_verification_required', 'company_trust_info',
        // Columns added by enrich_merchants.py
        'service_locations', 'primary_service_location', 'ships_to_countries',
        'alternative_brand_names', 'display_brand_name', 'contact_email', 'contact_page_url',
        'contact_method', 'contact_verified_at', 'contact_source_url', 'fact_source_url',
        'fact_last_verified', 'content_confidence', 'publish_status', 'offer_display_mode',
        'content_score', 'low_confidence_fields', 'review_notes',
    );
}

/**
 * Import one row. Returns array(action, post_id, message).
 */
function vc_merchant_import_row(array $row, $dry_run = false) {
    $types = vc_merchant_post_types();
    $post_type = reset($types);

    $brand = trim((string) ($row['brand_name'] ?? ''));
    if ($brand === '') {
        return array('skipped', 0, 'row has no brand_name');
    }

    $key = trim((string) ($row['externalMerchantKey'] ?? '')) ?: sanitize_title($brand);
    $status = trim((string) ($row['publish_status'] ?? ''));

    // Only fully graded rows go live. Everything else lands as a draft so it
    // can be edited in wp-admin without ever being publicly reachable.
    $post_status = ($status === 'Ready') ? 'publish' : 'draft';

    // Match an existing post on the merchant key.
    $existing = get_posts(array(
        'post_type'   => $post_type,
        'post_status' => 'any',
        'numberposts' => 1,
        'fields'      => 'ids',
        'meta_key'    => VC_MERCHANT_KEY_META,
        'meta_value'  => $key,
    ));
    $post_id = !empty($existing) ? (int) $existing[0] : 0;

    if ($dry_run) {
        return array(
            $post_id ? 'would update' : 'would create',
            $post_id,
            "$brand -> $post_status",
        );
    }

    $postarr = array(
        'post_type'    => $post_type,
        'post_title'   => $brand,
        'post_name'    => sanitize_title($brand),
        'post_status'  => $post_status,
        'post_content' => '[merchant_page]',
    );

    if ($post_id) {
        $postarr['ID'] = $post_id;
        $result = wp_update_post($postarr, true);
        $action = 'updated';
    } else {
        $result = wp_insert_post($postarr, true);
        $action = 'created';
    }

    if (is_wp_error($result)) {
        return array('failed', 0, $brand . ': ' . $result->get_error_message());
    }
    $post_id = (int) $result;

    // Stable identifier for future matching.
    update_post_meta($post_id, VC_MERCHANT_KEY_META, $key);

    // Copy every known column into meta.
    foreach (vc_merchant_import_meta_keys() as $meta_key) {
        if (array_key_exists($meta_key, $row)) {
            update_post_meta($post_id, $meta_key, (string) $row[$meta_key]);
        }
    }

    // Service location taxonomy terms, from the pipe-delimited source column.
    $locations = array_filter(array_map('trim',
        explode('|', (string) ($row['service_locations'] ?? ''))));
    if (!empty($locations)) {
        $term_ids = array();
        foreach ($locations as $location) {
            $term = term_exists($location, 'service_location');
            if (!$term) {
                $term = wp_insert_term($location, 'service_location');
            }
            if (!is_wp_error($term)) {
                $term_ids[] = is_array($term) ? (int) $term['term_id'] : (int) $term;
            }
        }
        if (!empty($term_ids)) {
            wp_set_object_terms($post_id, $term_ids, 'service_location', false);
        }
    }

    // Seed Rank Math's stored fields. The frontend filters in
    // wp-merchant-seo.php generate these live too, but writing them here means
    // the values are visible and editable in the Rank Math meta box.
    if (function_exists('vc_merchant_seo_title')) {
        if (trim((string) get_post_meta($post_id, 'rank_math_focus_keyword', true)) === '') {
            update_post_meta($post_id, 'rank_math_focus_keyword', vc_merchant_focus_keyword($post_id));
        }
    }

    return array($action, $post_id, $brand);
}

/**
 * Import a whole CSV file. Returns a tally plus per-row messages.
 */
function vc_merchant_import_csv($path, $dry_run = false, $limit = 0) {
    if (!is_readable($path)) {
        return new WP_Error('vc_unreadable', 'Cannot read the uploaded file.');
    }

    $fh = fopen($path, 'r');
    if (!$fh) {
        return new WP_Error('vc_open_failed', 'Could not open the CSV.');
    }

    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return new WP_Error('vc_empty', 'The CSV appears to be empty.');
    }
    // Strip a UTF-8 BOM from the first header cell if present.
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

    if (!in_array('brand_name', $header, true)) {
        fclose($fh);
        return new WP_Error('vc_bad_header', 'No brand_name column found - is this the enriched CSV?');
    }

    $tally = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0,
                   'would create' => 0, 'would update' => 0);
    $messages = array();
    $count = 0;

    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) !== count($header)) {
            $tally['skipped']++;
            continue;
        }
        $row = array_combine($header, $line);

        list($action, $post_id, $message) = vc_merchant_import_row($row, $dry_run);
        if (isset($tally[$action])) {
            $tally[$action]++;
        }
        $messages[] = "$action: $message";

        $count++;
        if ($limit > 0 && $count >= $limit) {
            break;
        }
    }
    fclose($fh);

    return array('tally' => $tally, 'messages' => $messages);
}

/* -------------------------------------------------------------------------
 * Admin screen: Tools -> Import Merchants
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_management_page(
        __('Import Merchants', 'vc-merchant'),
        __('Import Merchants', 'vc-merchant'),
        'manage_options',
        'vc-merchant-import',
        'vc_merchant_import_screen'
    );
});

function vc_merchant_import_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $result = null;
    $error = '';

    if (!empty($_POST['vc_merchant_import_nonce'])
        && wp_verify_nonce($_POST['vc_merchant_import_nonce'], 'vc_merchant_import')) {

        if (empty($_FILES['merchant_csv']['tmp_name'])) {
            $error = __('Please choose a CSV file.', 'vc-merchant');
        } else {
            $dry_run = !empty($_POST['dry_run']);
            $limit = isset($_POST['limit']) ? max(0, (int) $_POST['limit']) : 0;
            $result = vc_merchant_import_csv($_FILES['merchant_csv']['tmp_name'], $dry_run, $limit);
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
                $result = null;
            }
        }
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Import Merchants', 'vc-merchant'); ?></h1>

        <p><?php esc_html_e('Upload the enriched CSV produced by enrich_merchants.py. Merchants graded Ready are published; everything else is imported as a draft. Re-running updates existing merchants rather than creating duplicates.', 'vc-merchant'); ?></p>

        <?php if ($error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if ($result) : ?>
            <div class="notice notice-success">
                <p><strong><?php esc_html_e('Import finished.', 'vc-merchant'); ?></strong></p>
                <ul style="margin-left:1em;">
                <?php foreach ($result['tally'] as $action => $count) : ?>
                    <?php if ($count > 0) : ?>
                        <li><?php echo esc_html("$action: $count"); ?></li>
                    <?php endif; ?>
                <?php endforeach; ?>
                </ul>
            </div>
            <details>
                <summary><?php esc_html_e('Per-merchant detail', 'vc-merchant'); ?></summary>
                <pre style="max-height:400px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4;"><?php
                    echo esc_html(implode("\n", $result['messages']));
                ?></pre>
            </details>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('vc_merchant_import', 'vc_merchant_import_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="merchant_csv"><?php esc_html_e('Enriched CSV', 'vc-merchant'); ?></label></th>
                    <td><input type="file" name="merchant_csv" id="merchant_csv" accept=".csv,text/csv" required /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="dry_run"><?php esc_html_e('Dry run', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="checkbox" name="dry_run" id="dry_run" value="1" checked />
                        <span class="description"><?php esc_html_e('Report what would happen without writing anything. Leave this on for your first run.', 'vc-merchant'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="limit"><?php esc_html_e('Limit', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" name="limit" id="limit" value="0" min="0" class="small-text" />
                        <span class="description"><?php esc_html_e('Import only the first N rows. 0 imports everything.', 'vc-merchant'); ?></span>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Run import', 'vc-merchant')); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp merchant import <file> [--dry-run] [--limit=<n>]
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant import', function ($args, $assoc) {
        $path = $args[0] ?? '';
        if ($path === '') {
            WP_CLI::error('Usage: wp merchant import <file.csv> [--dry-run] [--limit=<n>]');
        }

        $result = vc_merchant_import_csv(
            $path,
            isset($assoc['dry-run']),
            isset($assoc['limit']) ? (int) $assoc['limit'] : 0
        );

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        foreach ($result['messages'] as $message) {
            WP_CLI::log($message);
        }
        foreach ($result['tally'] as $action => $count) {
            if ($count > 0) {
                WP_CLI::log(sprintf('%-13s %d', $action, $count));
            }
        }
        WP_CLI::success('Import finished.');
    });
}
