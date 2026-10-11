<?php
/**
 * Plugin Name: Coupon Exporter (one-time migration)
 * Description: Reads coupons stored by another plugin and writes a CSV in the format WP Coupon & Deals imports. Read-only - it never writes to your database. Install it, export, then deactivate and delete it.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.2
 *
 * Why a separate plugin: the old plugin's post type and meta keys are not
 * known in advance, so this discovers them first and shows you what it found
 * before exporting anything. Nothing here is needed once the migration is
 * done.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post types that are plausibly coupons, with the ones WP Coupon & Deals
 * itself uses excluded -- exporting its own data back into it is not a
 * migration.
 */
function vce_candidate_post_types() {
    $skip = array('wcd_coupon', 'post', 'page', 'attachment', 'revision',
                  'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part',
                  'wp_navigation', 'wp_global_styles', 'merchant');

    $out = array();
    foreach (get_post_types(array(), 'objects') as $type) {
        if (in_array($type->name, $skip, true)) {
            continue;
        }
        $count = (int) wp_count_posts($type->name)->publish
            + (int) wp_count_posts($type->name)->draft;
        if ($count < 1) {
            continue;
        }
        $out[$type->name] = array(
            'label' => $type->label,
            'count' => $count,
            // A name mentioning coupons, deals, offers or vouchers is worth
            // putting at the top of the list.
            'likely' => (bool) preg_match('/(coupon|deal|offer|voucher|promo|discount)/i',
                $type->name . ' ' . $type->label),
        );
    }

    uasort($out, function ($a, $b) {
        if ($a['likely'] !== $b['likely']) {
            return $a['likely'] ? -1 : 1;
        }
        return $b['count'] - $a['count'];
    });

    return $out;
}

/**
 * Every meta key in use on a post type, with how often it is filled.
 *
 * This is what tells you which of the old plugin's fields hold the code, the
 * expiry and so on, without having to read its source.
 */
function vce_meta_keys($post_type, $sample = 40) {
    global $wpdb;

    $ids = get_posts(array(
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => $sample,
        'fields'         => 'ids',
    ));
    if (empty($ids)) {
        return array();
    }

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT meta_key, COUNT(*) AS filled
           FROM {$wpdb->postmeta}
          WHERE post_id IN ($placeholders)
            AND meta_value <> ''
       GROUP BY meta_key
       ORDER BY filled DESC",
        $ids
    ));

    $out = array();
    foreach ($rows as $row) {
        // Skip WordPress's own bookkeeping, which is never coupon data.
        if (strpos($row->meta_key, '_edit') === 0 || $row->meta_key === '_thumbnail_id') {
            continue;
        }
        $out[$row->meta_key] = (int) $row->filled;
    }

    return array('keys' => $out, 'sampled' => count($ids));
}

/**
 * Taxonomies attached to a post type, with how many terms each holds.
 *
 * Coupon plugins commonly keep the store as a taxonomy -- "vendor", "store",
 * "brand" -- set up like categories rather than as a field, so these have to
 * be offered alongside the meta keys.
 */
function vce_taxonomies_for($post_type) {
    $out = array();
    foreach (get_object_taxonomies($post_type, 'objects') as $tax) {
        $count = wp_count_terms(array('taxonomy' => $tax->name, 'hide_empty' => false));
        if (is_wp_error($count)) {
            $count = 0;
        }
        $out[$tax->name] = array(
            'label' => $tax->label,
            'count' => (int) $count,
        );
    }

    return $out;
}

/**
 * Which of the four taxonomy columns a source taxonomy most likely feeds.
 */
function vce_guess_taxonomy_mapping(array $taxonomies) {
    $patterns = array(
        // The store itself goes by several names depending on the plugin.
        'brand'            => array('vendor', 'brand', 'store', 'shop', 'merchant', 'retailer', 'company'),
        'product_category' => array('product'),
        'location'         => array('location', 'country', 'region', 'geo'),
        'store_category'   => array('category', 'cat', 'type', 'niche'),
    );

    $guess = array();
    foreach ($patterns as $column => $needles) {
        foreach ($needles as $needle) {
            foreach (array_keys($taxonomies) as $slug) {
                if (in_array($slug, $guess, true)) {
                    continue;
                }
                if (stripos($slug, $needle) !== false
                    || stripos($taxonomies[$slug]['label'], $needle) !== false) {
                    $guess[$column] = $slug;
                    break 2;
                }
            }
        }
    }

    return $guess;
}

/**
 * A post's terms in a taxonomy, pipe-joined.
 *
 * Every term, not just the first: the importer takes several per column
 * separated by a pipe, and dropping the rest would lose data silently.
 */
function vce_term_names($post_id, $taxonomy) {
    if (!taxonomy_exists($taxonomy)) {
        return '';
    }

    $terms = get_the_terms($post_id, $taxonomy);
    if (!$terms || is_wp_error($terms)) {
        return '';
    }

    $names = array();
    foreach ($terms as $term) {
        $name = trim((string) $term->name);
        // A pipe inside a term name would read as a term separator on the
        // way back in, so it cannot survive as-is.
        $name = str_replace('|', '/', $name);
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return implode('|', array_unique($names));
}

/**
 * The columns WP Coupon & Deals imports, in its own order.
 */
function vce_target_columns() {
    return array('title', 'description', 'discount', 'code', 'type', 'expiration',
                 'destination_url', 'brand', 'store_category', 'product_category',
                 'location', 'additional_info', 'featured');
}

/**
 * Guess which old meta key feeds each target column.
 *
 * Only a starting point -- the screen shows every guess and lets you correct
 * it before exporting, because a wrong guess here silently moves the wrong
 * data into a live coupon.
 */
function vce_guess_mapping(array $meta_keys) {
    $patterns = array(
        'code'            => array('code', 'coupon_code', 'promo'),
        'discount'        => array('discount', 'amount', 'percent', 'save', 'value'),
        'expiration'      => array('expir', 'valid_until', 'end_date', 'enddate', 'until', 'expiry'),
        'destination_url' => array('url', 'link', 'affiliate', 'destination', 'target'),
        'type'            => array('type', 'kind'),
        'description'     => array('description', 'excerpt', 'details', 'summary'),
        'additional_info' => array('terms', 'conditions', 'note', 'info', 'fine_print'),
        'featured'        => array('featured', 'sticky', 'highlight'),
    );

    $guess = array();
    foreach ($patterns as $column => $needles) {
        foreach ($needles as $needle) {
            foreach (array_keys($meta_keys) as $key) {
                if (stripos($key, $needle) !== false && !in_array($key, $guess, true)) {
                    $guess[$column] = $key;
                    break 2;
                }
            }
        }
    }

    return $guess;
}

/* -------------------------------------------------------------------------
 * Screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_management_page(
        __('Export Coupons', 'vc-coupon-exporter'),
        __('Export Coupons', 'vc-coupon-exporter'),
        'manage_options',
        'vce-export',
        'vce_screen'
    );
});

function vce_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $types = vce_candidate_post_types();
    $chosen = isset($_GET['pt']) ? sanitize_key($_GET['pt']) : '';
    $meta = ($chosen && isset($types[$chosen])) ? vce_meta_keys($chosen) : array();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Export Coupons', 'vc-coupon-exporter'); ?></h1>
        <p class="description" style="max-width:720px;">
            <?php esc_html_e('Reads coupons another plugin stored and writes a CSV that WP Coupon & Deals can import. It never writes to your database, so running it cannot damage anything. Delete this plugin once you are done.', 'vc-coupon-exporter'); ?>
        </p>

        <h2><?php esc_html_e('1. Which post type holds the old coupons?', 'vc-coupon-exporter'); ?></h2>
        <?php if (empty($types)) : ?>
            <p><?php esc_html_e('No other post types with content were found. The old plugin may store coupons in its own database tables rather than as posts, which this cannot read.', 'vc-coupon-exporter'); ?></p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:720px;">
                <thead><tr>
                    <th><?php esc_html_e('Post type', 'vc-coupon-exporter'); ?></th>
                    <th><?php esc_html_e('Name', 'vc-coupon-exporter'); ?></th>
                    <th><?php esc_html_e('Posts', 'vc-coupon-exporter'); ?></th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($types as $name => $info) : ?>
                    <tr<?php echo $name === $chosen ? ' style="background:#f0f6fc;"' : ''; ?>>
                        <td><code><?php echo esc_html($name); ?></code>
                            <?php if ($info['likely']) : ?>
                                <strong><?php esc_html_e('- likely', 'vc-coupon-exporter'); ?></strong>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($info['label']); ?></td>
                        <td><?php echo (int) $info['count']; ?></td>
                        <td><a class="button" href="<?php
                            echo esc_url(add_query_arg(array('page' => 'vce-export', 'pt' => $name),
                                admin_url('tools.php'))); ?>"><?php
                            esc_html_e('Inspect', 'vc-coupon-exporter'); ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ($chosen && (!empty($meta['keys']) || !empty(vce_taxonomies_for($chosen)))) :
            $guess = vce_guess_mapping($meta['keys'] ?? array());
            $taxonomies = vce_taxonomies_for($chosen);
            $tax_guess = vce_guess_taxonomy_mapping($taxonomies);
            foreach ($tax_guess as $column => $slug) {
                // A taxonomy only wins where no meta key already claimed the
                // column, so a plugin that stores both is not overridden.
                if (empty($guess[$column])) {
                    $guess[$column] = '__tax:' . $slug;
                }
            } ?>
            <h2><?php echo esc_html(sprintf(
                __('2. Map the fields of %s', 'vc-coupon-exporter'), $chosen)); ?></h2>
            <p class="description" style="max-width:720px;">
                <?php echo esc_html(sprintf(
                    __('These are the fields actually filled on %d posts of this type, most-used first. Check every guess before exporting - a wrong one moves the wrong data into a live coupon.', 'vc-coupon-exporter'),
                    (int) $meta['sampled'])); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field('vce_export', 'vce_nonce'); ?>
                <input type="hidden" name="post_type" value="<?php echo esc_attr($chosen); ?>" />
                <table class="form-table" style="max-width:720px;">
                    <?php foreach (vce_target_columns() as $column) : ?>
                        <tr>
                            <th scope="row"><code><?php echo esc_html($column); ?></code></th>
                            <td>
                                <select name="map[<?php echo esc_attr($column); ?>]">
                                    <option value=""><?php esc_html_e('- leave empty -', 'vc-coupon-exporter'); ?></option>
                                    <?php if (in_array($column, array('title', 'description'), true)) : ?>
                                        <option value="__post_title" <?php selected($column === 'title'); ?>>
                                            <?php esc_html_e('the post title', 'vc-coupon-exporter'); ?></option>
                                        <option value="__post_content" <?php selected($column === 'description'); ?>>
                                            <?php esc_html_e('the post content', 'vc-coupon-exporter'); ?></option>
                                        <option value="__post_excerpt">
                                            <?php esc_html_e('the post excerpt', 'vc-coupon-exporter'); ?></option>
                                    <?php endif; ?>
                                    <?php foreach ($taxonomies as $slug => $tax) : ?>
                                        <option value="<?php echo esc_attr('__tax:' . $slug); ?>"
                                            <?php selected(($guess[$column] ?? '') === '__tax:' . $slug); ?>>
                                            <?php echo esc_html(sprintf(
                                                /* translators: 1: taxonomy label, 2: slug, 3: term count */
                                                __('category: %1$s (%2$s, %3$d terms)', 'vc-coupon-exporter'),
                                                $tax['label'], $slug, $tax['count'])); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <?php foreach (($meta['keys'] ?? array()) as $key => $filled) : ?>
                                        <option value="<?php echo esc_attr($key); ?>"
                                            <?php selected(($guess[$column] ?? '') === $key); ?>>
                                            <?php echo esc_html(sprintf('%s (%d filled)', $key, $filled)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php submit_button(__('Download CSV', 'vc-coupon-exporter')); ?>
            </form>
        <?php elseif ($chosen) : ?>
            <p><?php esc_html_e('That post type has no custom fields filled, so there is nothing to map. Its coupons may live in the post content alone, or in the plugin\'s own tables.', 'vc-coupon-exporter'); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Export
 * ---------------------------------------------------------------------- */

add_action('admin_init', function () {
    if (empty($_POST['vce_nonce']) || !wp_verify_nonce($_POST['vce_nonce'], 'vce_export')) {
        return;
    }
    if (!current_user_can('manage_options')) {
        wp_die('Not allowed.');
    }

    $post_type = sanitize_key($_POST['post_type'] ?? '');
    if (!$post_type || !post_type_exists($post_type)) {
        wp_die('Unknown post type.');
    }

    $map = array();
    foreach (vce_target_columns() as $column) {
        // Not sanitize_key: a source is either a meta key or "__tax:slug".
        $value = sanitize_text_field(wp_unslash($_POST['map'][$column] ?? ''));
        if ($value !== '') {
            $map[$column] = $value;
        }
    }

    $posts = get_posts(array(
        'post_type'      => $post_type,
        'post_status'    => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="coupons-for-import.csv"');

    $out = fopen('php://output', 'w');
    // BOM, so Excel opens it as UTF-8 rather than mangling accents.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, vce_target_columns());

    foreach ($posts as $post) {
        $row = array();
        foreach (vce_target_columns() as $column) {
            $source = $map[$column] ?? '';
            $value = '';

            if ($source === '__post_title') {
                $value = $post->post_title;
            } elseif ($source === '__post_content') {
                $value = $post->post_content;
            } elseif ($source === '__post_excerpt') {
                $value = $post->post_excerpt;
            } elseif (strpos($source, '__tax:') === 0) {
                $value = vce_term_names($post->ID, substr($source, 6));
            } elseif ($source !== '') {
                $value = get_post_meta($post->ID, $source, true);
            }

            if (is_array($value)) {
                $value = implode('|', array_filter(array_map('strval', $value)));
            }
            $value = (string) $value;

            // Normalise the two columns the importer is strict about.
            if ($column === 'expiration' && $value !== '') {
                $stamp = is_numeric($value) ? (int) $value : strtotime($value);
                $value = $stamp ? gmdate('Y-m-d', $stamp) : '';
            }
            if ($column === 'type') {
                $value = (stripos($value, 'deal') !== false || stripos($value, 'sale') !== false)
                    ? 'deal' : 'code';
            }
            if ($column === 'featured') {
                $value = ($value === '' || $value === '0' || strtolower($value) === 'no') ? '0' : '1';
            }

            // A code column that came through empty leaves type as "deal",
            // because a code coupon with no code is not importable as one.
            $row[$column] = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($value)));
        }

        if ($row['code'] === '' && $row['type'] === 'code') {
            $row['type'] = 'deal';
        }
        // A row with no title is not a coupon.
        if ($row['title'] === '') {
            continue;
        }

        fputcsv($out, array_values($row));
    }

    fclose($out);
    exit;
});
