<?php
/**
 * Term-meta importer: loads destination-page copy from a CSV.
 *
 * The editorial fields that decide whether a destination page is indexable
 * -- vc_intro, vc_legal_note, the two FAQ pairs, the title override -- could
 * only be filled by opening each term and typing into it. With 51 states
 * that is 51 trips through the term editor, and it is why forty of those
 * pages sat at noindex: not because nobody had the copy, but because there
 * was no way to get it in.
 *
 * The CSV is the same shape as the one build_state_intros.py writes:
 *
 *     taxonomy,term,vc_intro
 *     ships_to,Alabama,"Almost every online vape shop we track..."
 *
 * Any of the editorial field names may be used as a column. A column that
 * is not one of them is ignored, and a blank cell leaves the existing value
 * alone rather than wiping it -- so a file carrying only vc_intro can be
 * imported over terms that already have FAQs without destroying them.
 *
 * Available as an admin screen and as WP-CLI, both over the same routine.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which taxonomies a row may target.
 *
 * Restricted to the ones this plugin owns, so a crafted CSV cannot write
 * term meta onto categories, tags or another plugin's taxonomy.
 */
function vc_intro_taxonomies() {
    if (function_exists('vc_archive_taxonomies')) {
        return (array) vc_archive_taxonomies();
    }

    return array('ships_to', 'service_location', 'merchant_category');
}

/**
 * The fields a CSV is allowed to write, keyed by column name.
 */
function vc_intro_fields() {
    if (function_exists('vc_archive_term_fields')) {
        return array_keys(vc_archive_term_fields());
    }

    return array('vc_intro', 'vc_legal_note', 'vc_faq_q1', 'vc_faq_a1',
                 'vc_faq_q2', 'vc_faq_a2', 'vc_title_override');
}

/**
 * Find the term a row names, without creating anything.
 *
 * Matched on name first and slug second. Nothing is created: a typo in a
 * state name should be reported, not turned into a new empty destination
 * page that then competes with the real one.
 */
function vc_intro_find_term($name, $taxonomy) {
    $term = get_term_by('name', $name, $taxonomy);
    if ($term && !is_wp_error($term)) {
        return $term;
    }

    $term = get_term_by('slug', sanitize_title($name), $taxonomy);
    if ($term && !is_wp_error($term)) {
        return $term;
    }

    return null;
}

/**
 * Import editorial copy from a CSV. Returns a report array, or WP_Error.
 */
function vc_intro_import_csv($path, $dry_run = false, $overwrite = false) {
    if (!is_readable($path)) {
        return new WP_Error('vc_intro_unreadable',
            __('That file could not be read.', 'vc-merchant'));
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
        return new WP_Error('vc_intro_unreadable',
            __('That file could not be opened.', 'vc-merchant'));
    }

    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return new WP_Error('vc_intro_empty',
            __('That file has no header row.', 'vc-merchant'));
    }

    // Strip a UTF-8 BOM, which Excel writes and which otherwise makes the
    // first column name unmatchable.
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }
    $header = array_map(function ($column) {
        return strtolower(trim((string) $column));
    }, $header);

    $allowed = vc_intro_fields();
    $writable = array_values(array_intersect($header, $allowed));

    if (!in_array('term', $header, true)) {
        fclose($handle);
        return new WP_Error('vc_intro_no_term',
            __('That file has no "term" column, so there is nothing to say which destination each row belongs to.', 'vc-merchant'));
    }
    if (empty($writable)) {
        fclose($handle);
        return new WP_Error('vc_intro_no_fields', sprintf(
            /* translators: %s: comma-separated list of column names */
            __('None of that file\'s columns are editorial fields. Expected one or more of: %s', 'vc-merchant'),
            implode(', ', $allowed)));
    }

    $report = array(
        'messages' => array(),
        'tally'    => array('written' => 0, 'unchanged' => 0, 'kept' => 0,
                            'skipped' => 0),
        'fields'   => $writable,
        'indexable' => 0,
    );

    $row_number = 1;
    $taxonomies = vc_intro_taxonomies();
    $default_taxonomy = reset($taxonomies);

    while (($line = fgetcsv($handle)) !== false) {
        $row_number++;

        if (count($line) !== count($header)) {
            // Almost always an unquoted comma inside the copy itself.
            $report['tally']['skipped']++;
            $report['messages'][] = sprintf(
                __('Row %1$d: skipped - %2$d columns, header has %3$d. A comma inside the text needs quotes around the field.', 'vc-merchant'),
                $row_number, count($line), count($header));
            continue;
        }

        $row = array_combine($header, $line);
        $name = trim((string) ($row['term'] ?? ''));
        $taxonomy = trim((string) ($row['taxonomy'] ?? $default_taxonomy));

        if ($name === '') {
            $report['tally']['skipped']++;
            $report['messages'][] = sprintf(
                __('Row %d: skipped - no term name.', 'vc-merchant'), $row_number);
            continue;
        }

        if (!in_array($taxonomy, $taxonomies, true)) {
            $report['tally']['skipped']++;
            $report['messages'][] = sprintf(
                __('Row %1$d: skipped - "%2$s" is not a destination taxonomy on this site.', 'vc-merchant'),
                $row_number, $taxonomy);
            continue;
        }

        $term = vc_intro_find_term($name, $taxonomy);
        if (!$term) {
            $report['tally']['skipped']++;
            $report['messages'][] = sprintf(
                __('Row %1$d: skipped - no "%2$s" term called "%3$s". Nothing was created; check the spelling.', 'vc-merchant'),
                $row_number, $taxonomy, $name);
            continue;
        }

        $changed = array();
        $kept = array();

        foreach ($writable as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') {
                // A blank cell means "no opinion", not "delete what is there".
                continue;
            }

            $current = trim((string) get_term_meta($term->term_id, $field, true));
            if ($current === $value) {
                continue;
            }
            if ($current !== '' && !$overwrite) {
                // Something is already written here by hand. Say so and move
                // on rather than quietly replacing somebody's editing.
                $kept[] = $field;
                continue;
            }

            if (!$dry_run) {
                update_term_meta($term->term_id, $field, wp_kses_post($value));
            }
            $changed[] = $field;
        }

        if ($changed) {
            $report['tally']['written']++;
            $report['messages'][] = sprintf(
                __('%1$s: %2$s', 'vc-merchant'), $term->name, implode(', ', $changed));
        } elseif ($kept) {
            $report['tally']['kept']++;
            $report['messages'][] = sprintf(
                __('%1$s: left alone - %2$s already written. Tick "Replace existing copy" to overwrite.', 'vc-merchant'),
                $term->name, implode(', ', $kept));
        } else {
            $report['tally']['unchanged']++;
        }
    }

    fclose($handle);

    // How many destination pages would now be indexable. This is the number
    // the whole exercise is for, so report it rather than leaving it to be
    // counted by hand across 51 term screens.
    if (function_exists('vc_archive_has_unique_content')) {
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
            if (is_wp_error($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                if (vc_archive_has_unique_content($term)) {
                    $report['indexable']++;
                }
            }
        }
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
            __('Import Page Copy', 'vc-merchant'),
            __('Import Page Copy', 'vc-merchant'),
            'manage_options',
            'vc-merchant-intros',
            'vc_intro_screen'
        );
        break;
    }
}, 22);

function vc_intro_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $result = null;
    $error = '';

    if (!empty($_POST['vc_intro_nonce'])
        && wp_verify_nonce($_POST['vc_intro_nonce'], 'vc_intro_import')) {

        $upload = isset($_FILES['intro_csv']) ? $_FILES['intro_csv'] : array();
        $code = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;

        if (empty($upload['tmp_name']) || $code === UPLOAD_ERR_NO_FILE) {
            $error = __('Please choose a CSV file.', 'vc-merchant');
        } elseif ($code !== UPLOAD_ERR_OK) {
            // A partial upload still leaves a readable temp file, so without
            // this an interrupted upload would import half the copy and
            // report success.
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
            $error = __('That file was not a genuine upload.', 'vc-merchant');
        } else {
            $result = vc_intro_import_csv(
                $upload['tmp_name'],
                !empty($_POST['dry_run']),
                !empty($_POST['overwrite'])
            );
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
                $result = null;
            }
        }
    }

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Import Destination Page Copy', 'vc-merchant'); ?></h1>

        <p class="description" style="max-width:46em;">
            <?php esc_html_e('Loads intros, local-rules notes and FAQs onto the Ships To, Service Location and Category terms from a CSV. A destination page with none of these is held at noindex, because without its own copy it is near-identical to the other fifty.', 'vc-merchant'); ?>
        </p>

        <?php if ($error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>

        <?php if ($result) : ?>
            <div class="notice <?php echo empty($result['tally']['skipped']) ? 'notice-success' : 'notice-warning'; ?>">
                <p><strong>
                    <?php echo esc_html(sprintf(
                        /* translators: 1: written, 2: unchanged, 3: left alone, 4: skipped */
                        __('%1$d written, %2$d already current, %3$d left alone, %4$d skipped.', 'vc-merchant'),
                        $result['tally']['written'], $result['tally']['unchanged'],
                        $result['tally']['kept'], $result['tally']['skipped']
                    )); ?>
                </strong></p>
                <p>
                    <?php echo esc_html(sprintf(
                        /* translators: %d: number of indexable destination pages */
                        __('Destination pages now carrying copy of their own: %d', 'vc-merchant'),
                        $result['indexable']
                    )); ?>
                    <?php esc_html_e('Those are the ones allowed into the search index.', 'vc-merchant'); ?>
                </p>
                <p class="description">
                    <?php echo esc_html(sprintf(
                        /* translators: %s: comma-separated column names */
                        __('Fields read from this file: %s', 'vc-merchant'),
                        implode(', ', $result['fields'])
                    )); ?>
                </p>
            </div>

            <details open>
                <summary><?php esc_html_e('Per-term detail', 'vc-merchant'); ?></summary>
                <pre style="max-height:400px;overflow:auto;background:#fff;padding:10px;border:1px solid #ccd0d4;"><?php
                    echo esc_html(implode("\n", $result['messages']));
                ?></pre>
            </details>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('vc_intro_import', 'vc_intro_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="intro_csv"><?php esc_html_e('Copy CSV', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="file" name="intro_csv" id="intro_csv" accept=".csv,text/csv" required />
                        <p class="description"><?php esc_html_e('Columns: term, optionally taxonomy, and any of vc_intro, vc_legal_note, vc_faq_q1, vc_faq_a1, vc_faq_q2, vc_faq_a2, vc_title_override.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="dry_run"><?php esc_html_e('Dry run', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="checkbox" name="dry_run" id="dry_run" value="1" checked />
                        <span class="description"><?php esc_html_e('Report what would happen without writing anything. Leave this on for your first run.', 'vc-merchant'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="overwrite"><?php esc_html_e('Replace existing copy', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="checkbox" name="overwrite" id="overwrite" value="1" />
                        <span class="description"><?php esc_html_e('Off by default: a field you have already written by hand is reported and left alone. Tick this only when the file is the newer version.', 'vc-merchant'); ?></span>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Import copy', 'vc-merchant')); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp merchant intros <file> [--dry-run] [--overwrite]
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant intros', function ($args, $assoc) {
        $path = $args[0] ?? '';
        if ($path === '') {
            WP_CLI::error('Usage: wp merchant intros <file.csv> [--dry-run] [--overwrite]');
        }

        $result = vc_intro_import_csv(
            $path,
            isset($assoc['dry-run']),
            isset($assoc['overwrite'])
        );

        if (is_wp_error($result)) {
            WP_CLI::error($result->get_error_message());
        }

        foreach ($result['messages'] as $message) {
            WP_CLI::log($message);
        }
        foreach ($result['tally'] as $action => $count) {
            if ($count > 0) {
                WP_CLI::log(sprintf('%-11s %d', $action, $count));
            }
        }
        WP_CLI::log(sprintf('indexable   %d', $result['indexable']));
        WP_CLI::success('Copy imported.');
    });
}
