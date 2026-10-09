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
 * Everything imports as a draft by default, for manual review before
 * publishing. Tick "Publish immediately" to skip that. A merchant you have
 * already published is never demoted back to draft by a re-import.
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
        'content_score', 'low_confidence_fields', 'review_notes', 'section_origins',
        'ships_to_terms', 'restricted_states', 'shipping_confidence',
        'social_links', 'useful_links', 'shipping_policy_url', 'returns_policy_url',
        'age_policy_url', 'do_they_id_on_delivery', 'trust_source_url', 'trust_verified_at',
    );
}

/**
 * Import one row. Returns array(action, post_id, message).
 */
function vc_merchant_import_row(array $row, $dry_run = false, $publish = false, $content_mode = 'shortcode') {
    $types = vc_merchant_post_types();
    $post_type = reset($types);

    $brand = trim((string) ($row['brand_name'] ?? ''));
    if ($brand === '') {
        return array('skipped', 0, 'row has no brand_name');
    }

    $key = trim((string) ($row['externalMerchantKey'] ?? '')) ?: sanitize_title($brand);

    // Everything imports as a draft by default, for manual review before
    // publishing. Pass $publish = true to publish on import instead. The
    // enrichment grade is advisory metadata and does not decide this.
    $post_status = $publish ? 'publish' : 'draft';

    // Never demote a merchant you have already published by re-importing.
    if (!$publish) {
        $existing_published = get_posts(array(
            'post_type'   => reset($types),
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_key'    => VC_MERCHANT_KEY_META,
            'meta_value'  => $key,
        ));
        if (!empty($existing_published)) {
            $post_status = 'publish';
        }
    }

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

    // Page body. 'shortcode' puts [merchant_page] in each post, which is what
    // you want when the theme renders post content normally. 'empty' leaves the
    // body blank for a theme or page-builder template that places the section
    // shortcodes itself -- otherwise the template and the post content would
    // both render, and the whole page would appear twice.
    $default_content = ($content_mode === 'empty') ? '' : '[merchant_page]';

    $postarr = array(
        'post_type'   => $post_type,
        'post_title'  => $brand,
        'post_name'   => sanitize_title($brand),
        'post_status' => $post_status,
    );

    // Never overwrite a body someone has written. On an update the content is
    // only reset when it is still untouched -- empty, or exactly the default
    // shortcode. Anything else means custom copy or a hand-built block layout,
    // and silently replacing that on the next import would destroy real work.
    if (!$post_id) {
        $postarr['post_content'] = $default_content;
    } else {
        $existing = trim((string) get_post_field('post_content', $post_id));
        if ($existing === '' || $existing === '[merchant_page]') {
            $postarr['post_content'] = $default_content;
        }
    }

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

    // Product categories -> merchant_category taxonomy. brand_category is a
    // comma-separated list ("Vape Juice/E-Liquid, Box Mods, Nicotine Pouches").
    $categories = array_filter(array_map('trim',
        explode(',', (string) ($row['brand_category'] ?? ''))));
    if (!empty($categories)) {
        $cat_ids = array();
        foreach ($categories as $category) {
            $term = get_term_by('name', $category, 'merchant_category');
            if (!$term) {
                $created = wp_insert_term($category, 'merchant_category');
                if (is_wp_error($created)) {
                    continue;
                }
                $cat_ids[] = (int) $created['term_id'];
                continue;
            }
            $cat_ids[] = (int) $term->term_id;
        }
        if (!empty($cat_ids)) {
            wp_set_object_terms($post_id, $cat_ids, 'merchant_category', false);
        }
    }

    // Shipping destinations -> ships_to taxonomy, so pages are filterable by
    // where the merchant actually ships.
    if (function_exists('vc_assign_ships_to')) {
        vc_assign_ships_to($post_id, $row['ships_to_terms'] ?? '');
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
function vc_merchant_import_csv($path, $dry_run = false, $limit = 0, $publish = false, $content_mode = 'shortcode') {
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

    // Which taxonomy columns this file even has. Checked against the header
    // rather than per row, so a column missing from the export is reported
    // once and clearly.
    $taxonomy_columns = array(
        'ships_to_terms'    => __('Ships To', 'vc-merchant'),
        'service_locations' => __('Service Locations', 'vc-merchant'),
        'brand_category'    => __('Categories', 'vc-merchant'),
    );
    $absent = array();
    foreach ($taxonomy_columns as $column => $label) {
        if (!in_array($column, $header, true)) {
            $absent[] = sprintf('%s (no "%s" column)', $label, $column);
        }
    }
    $filled = array_fill_keys(array_keys($taxonomy_columns), 0);

    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) !== count($header)) {
            $tally['skipped']++;
            continue;
        }
        $row = array_combine($header, $line);

        foreach (array_keys($taxonomy_columns) as $column) {
            if (trim((string) ($row[$column] ?? '')) !== '') {
                $filled[$column]++;
            }
        }

        list($action, $post_id, $message) = vc_merchant_import_row($row, $dry_run, $publish, $content_mode);
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

    // Build a plain statement of what the taxonomy columns actually carried,
    // so an empty set of checkboxes in the editor has an explanation on screen
    // rather than needing someone to go and read the CSV.
    $taxonomy_report = array();
    foreach ($taxonomy_columns as $column => $label) {
        if (!in_array($column, $header, true)) {
            $taxonomy_report[] = sprintf(
                /* translators: 1: taxonomy label, 2: CSV column name */
                __('%1$s: no "%2$s" column in this file, so nothing was assigned.', 'vc-merchant'),
                $label, $column);
            continue;
        }
        if ($filled[$column] === 0) {
            $taxonomy_report[] = sprintf(
                __('%1$s: the "%2$s" column is present but empty on every row, so nothing was assigned.', 'vc-merchant'),
                $label, $column);
            continue;
        }
        $taxonomy_report[] = sprintf(
            /* translators: 1: label, 2: rows with a value, 3: rows read */
            __('%1$s: a value on %2$d of %3$d rows.', 'vc-merchant'),
            $label, $filled[$column], $count);
    }

    return array(
        'tally'    => $tally,
        'messages' => $messages,
        'taxonomy' => $taxonomy_report,
        'absent'   => $absent,
    );
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

        $upload = isset($_FILES['merchant_csv']) ? $_FILES['merchant_csv'] : array();
        $code = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

        if (empty($upload['tmp_name']) || $code === UPLOAD_ERR_NO_FILE) {
            $error = __('Please choose a CSV file.', 'vc-merchant');
        } elseif ($code !== UPLOAD_ERR_OK) {
            // A partial upload still leaves a readable temp file, so without
            // this a dropped connection imported a truncated CSV and reported
            // success for however many rows arrived.
            $messages = array(
                UPLOAD_ERR_INI_SIZE   => __('The file is larger than this server allows (upload_max_filesize).', 'vc-merchant'),
                UPLOAD_ERR_FORM_SIZE  => __('The file is larger than the form allows.', 'vc-merchant'),
                UPLOAD_ERR_PARTIAL    => __('The upload was interrupted and the file is incomplete. Nothing was imported - please try again.', 'vc-merchant'),
                UPLOAD_ERR_NO_TMP_DIR => __('The server has no temporary folder for uploads.', 'vc-merchant'),
                UPLOAD_ERR_CANT_WRITE => __('The server could not write the uploaded file to disk.', 'vc-merchant'),
                UPLOAD_ERR_EXTENSION  => __('A PHP extension blocked the upload.', 'vc-merchant'),
            );
            $error = $messages[$code] ?? __('The upload failed. Nothing was imported.', 'vc-merchant');
        } elseif (!is_uploaded_file($upload['tmp_name'])) {
            // Defence in depth: only ever read a path PHP itself created for
            // this request.
            $error = __('That file was not a genuine upload.', 'vc-merchant');
        } else {
            $dry_run = !empty($_POST['dry_run']);
            $limit = isset($_POST['limit']) ? max(0, (int) $_POST['limit']) : 0;
            $publish = !empty($_POST['publish_now']);
            $content_mode = (isset($_POST['content_mode']) && $_POST['content_mode'] === 'empty')
                ? 'empty' : 'shortcode';
            $result = vc_merchant_import_csv($upload['tmp_name'], $dry_run, $limit, $publish, $content_mode);
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

            <?php if (!empty($result['taxonomy'])) : ?>
                <div class="notice <?php echo empty($result['absent']) ? 'notice-info' : 'notice-warning'; ?>">
                    <p><strong><?php esc_html_e('Ships To, Service Locations and Categories', 'vc-merchant'); ?></strong></p>
                    <ul style="margin-left:1em;list-style:disc;">
                    <?php foreach ($result['taxonomy'] as $line) : ?>
                        <li><?php echo esc_html($line); ?></li>
                    <?php endforeach; ?>
                    </ul>
                    <p class="description">
                        <?php esc_html_e('These are the columns that tick the taxonomy boxes on a store. If one reads "nothing was assigned", those boxes will be empty however many stores imported - re-run enrichment and import that file instead.', 'vc-merchant'); ?>
                    </p>
                </div>
            <?php endif; ?>

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
                    <th scope="row"><label for="content_mode"><?php esc_html_e('Page content', 'vc-merchant'); ?></label></th>
                    <td>
                        <select name="content_mode" id="content_mode">
                            <option value="shortcode"><?php esc_html_e('[merchant_page] shortcode (default)', 'vc-merchant'); ?></option>
                            <option value="empty"><?php esc_html_e('Leave empty - my theme template renders the sections', 'vc-merchant'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Choose "leave empty" only if you have built a theme or page-builder template that places [merchant_offer], [merchant_about] and so on itself. Leaving the shortcode in as well would render the page twice.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="publish_now"><?php esc_html_e('Publish immediately', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="checkbox" name="publish_now" id="publish_now" value="1" />
                        <span class="description"><?php esc_html_e('Leave off to import everything as drafts for manual review. Merchants you have already published stay published either way.', 'vc-merchant'); ?></span>
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
            WP_CLI::error('Usage: wp merchant import <file.csv> [--dry-run] [--limit=<n>] [--publish] [--content=shortcode|empty]');
        }

        $result = vc_merchant_import_csv(
            $path,
            isset($assoc['dry-run']),
            isset($assoc['limit']) ? (int) $assoc['limit'] : 0,
            isset($assoc['publish']),
            (isset($assoc['content']) && $assoc['content'] === 'empty') ? 'empty' : 'shortcode'
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
