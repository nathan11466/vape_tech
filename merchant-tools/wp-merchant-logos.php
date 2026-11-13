<?php
/**
 * Pull merchant logos into the media library.
 *
 * brand_logo_url normally arrives pointing at the merchant's own CDN. That is
 * fine for identifying the image and wrong for publishing it: Shopify and
 * friends version their asset URLs, hosts can and do block hotlinking, and an
 * image on someone else's server can change or vanish without notice -- on a
 * page that feeds it to Google as Organization.logo.
 *
 * This copies each logo into the media library once and repoints
 * brand_logo_url at the local file.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Is this URL already served from this site?
 */
function vc_logo_is_local($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return false;
    }
    // A root-relative path is local by definition.
    if (strpos($url, '//') === false && strpos($url, '/') === 0) {
        return true;
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $home = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
    if ($host === '' || $home === '') {
        return false;
    }

    $host = preg_replace('/^www\./', '', $host);
    $home = preg_replace('/^www\./', '', $home);

    return $host === $home;
}

/**
 * Copy one merchant's logo into the media library.
 *
 * Idempotent: a logo already local, or already sideloaded and still present,
 * is left alone. Returns 'imported', 'skipped', 'local', 'none', or a WP_Error.
 *
 * @param int  $post_id   Merchant post.
 * @param bool $set_thumb Also use the logo as the featured image when there
 *                        is not one already.
 */
function vc_logo_sideload($post_id, $set_thumb = true) {
    $url = trim((string) get_post_meta($post_id, 'brand_logo_url', true));
    if ($url === '') {
        return 'none';
    }

    // Already pulled in on an earlier run, and the attachment still exists.
    $existing = (int) get_post_meta($post_id, 'brand_logo_attachment_id', true);
    if ($existing && get_post($existing)) {
        return 'skipped';
    }

    if (vc_logo_is_local($url)) {
        return 'local';
    }

    if (!preg_match('#^https?://#i', $url)) {
        return new WP_Error('vc_logo_scheme', __('Logo URL is not http(s).', 'vc-merchant'));
    }

    // Only loaded in admin by default.
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $name = vc_merchant_display_name($post_id);

    // Strip the cache-busting query Shopify and Magento append, so the
    // attachment lands as logo.png rather than logo.png%3Fv=123.
    $clean = strtok($url, '?');
    $extension = strtolower((string) pathinfo((string) parse_url($clean, PHP_URL_PATH), PATHINFO_EXTENSION));
    $allowed = array('png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif');

    // A CDN that serves through a resizing endpoint may carry no extension.
    // media_sideload_image() sniffs the real type, so an unknown extension is
    // not fatal -- but anything that is clearly not an image is rejected.
    if ($extension !== '' && !in_array($extension, $allowed, true)) {
        return new WP_Error('vc_logo_type',
            sprintf(__('Not an image URL (.%s).', 'vc-merchant'), $extension));
    }

    $attachment_id = media_sideload_image($url, $post_id,
        sprintf(__('%s logo', 'vc-merchant'), $name), 'id');

    if (is_wp_error($attachment_id)) {
        return $attachment_id;
    }

    $local = wp_get_attachment_url($attachment_id);
    if (!$local) {
        return new WP_Error('vc_logo_url', __('Sideloaded but no local URL.', 'vc-merchant'));
    }

    // Alt text, because the logo is rendered in the page hero.
    update_post_meta($attachment_id, '_wp_attachment_image_alt',
        sprintf(__('%s logo', 'vc-merchant'), $name));

    update_post_meta($post_id, 'brand_logo_attachment_id', (int) $attachment_id);
    // Keep the original, so it is clear where the file came from and a re-run
    // can be told apart from a hand-entered local URL.
    update_post_meta($post_id, 'brand_logo_source_url', $url);
    update_post_meta($post_id, 'brand_logo_url', $local);

    if ($set_thumb && !has_post_thumbnail($post_id)) {
        set_post_thumbnail($post_id, $attachment_id);
    }

    return 'imported';
}

/**
 * Sideload logos for a batch of merchants.
 *
 * Batched because each logo is an HTTP request and PHP will time out long
 * before 112 of them finish in one page load.
 *
 * @return array Counts keyed by outcome, plus 'errors' and 'remaining'.
 */
function vc_logo_sideload_batch($limit = 20, $set_thumb = true) {
    $ids = get_posts(array(
        'post_type'        => vc_merchant_post_types(),
        'post_status'      => 'any',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'meta_query'       => array(
            array('key' => 'brand_logo_url', 'compare' => 'EXISTS'),
            array('key' => 'brand_logo_attachment_id', 'compare' => 'NOT EXISTS'),
        ),
        'suppress_filters' => false,
    ));

    $counts = array('imported' => 0, 'skipped' => 0, 'local' => 0, 'none' => 0);
    $errors = array();
    $done = 0;

    foreach ($ids as $post_id) {
        if ($limit > 0 && $done >= $limit) {
            break;
        }

        $result = vc_logo_sideload($post_id, $set_thumb);
        $done++;

        if (is_wp_error($result)) {
            $errors[] = sprintf('%s: %s',
                vc_merchant_display_name($post_id), $result->get_error_message());
            continue;
        }
        $counts[$result] = ($counts[$result] ?? 0) + 1;
    }

    $counts['errors'] = $errors;
    $counts['remaining'] = max(0, count($ids) - $done);

    return $counts;
}

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    foreach (vc_merchant_post_types() as $post_type) {
        add_submenu_page(
            'edit.php?post_type=' . $post_type,
            __('Import Logos', 'vc-merchant'),
            __('Import Logos', 'vc-merchant'),
            'manage_options',
            'vc-merchant-logos',
            'vc_logo_screen'
        );
        break;
    }
}, 21);

function vc_logo_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $result = null;

    if (!empty($_POST['vc_logo_nonce'])
        && wp_verify_nonce($_POST['vc_logo_nonce'], 'vc_logo_run')) {
        $limit = isset($_POST['batch']) ? max(1, (int) $_POST['batch']) : 20;
        $result = vc_logo_sideload_batch($limit, !empty($_POST['set_thumb']));
    }

    // How much is still pointing off-site.
    $pending = get_posts(array(
        'post_type'      => vc_merchant_post_types(),
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array('key' => 'brand_logo_url', 'compare' => 'EXISTS'),
            array('key' => 'brand_logo_attachment_id', 'compare' => 'NOT EXISTS'),
        ),
    ));
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Import Logos', 'vc-merchant'); ?></h1>

        <p class="description" style="max-width:640px;">
            <?php esc_html_e('Copies each store\'s logo from the URL in brand_logo_url into this site\'s media library, then repoints brand_logo_url at the local copy. Hotlinked logos break when a merchant changes their CDN path or blocks hotlinking, so this is worth doing once after an import.', 'vc-merchant'); ?>
        </p>

        <?php if ($result !== null) : ?>
            <div class="notice notice-success">
                <p>
                    <?php echo esc_html(sprintf(
                        /* translators: 1: imported, 2: already local, 3: already done, 4: remaining */
                        __('Imported %1$d. Already local: %2$d. Already imported: %3$d. Remaining: %4$d.', 'vc-merchant'),
                        $result['imported'], $result['local'], $result['skipped'], $result['remaining']
                    )); ?>
                </p>
            </div>
            <?php if (!empty($result['errors'])) : ?>
                <div class="notice notice-warning">
                    <p><strong><?php esc_html_e('Could not import:', 'vc-merchant'); ?></strong></p>
                    <ul style="list-style:disc;margin-left:20px;">
                        <?php foreach ($result['errors'] as $line) : ?>
                            <li><?php echo esc_html($line); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <p>
            <?php echo esc_html(sprintf(
                _n('%d store still has an off-site logo.', '%d stores still have an off-site logo.',
                    count($pending), 'vc-merchant'),
                count($pending)
            )); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('vc_logo_run', 'vc_logo_nonce'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="batch"><?php esc_html_e('Batch size', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" name="batch" id="batch" value="20" min="1" class="small-text" />
                        <span class="description"><?php esc_html_e('Each logo is a download, so run in batches and click again until none remain.', 'vc-merchant'); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Featured image', 'vc-merchant'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="set_thumb" value="1" checked />
                            <?php esc_html_e('Also set the logo as the featured image when the store has none', 'vc-merchant'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Import logos', 'vc-merchant')); ?>
        </form>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp merchant logos [--limit=<n>] [--no-thumb]
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant logos', function ($args, $assoc) {
        $limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 0;
        $thumb = empty($assoc['no-thumb']);

        $result = vc_logo_sideload_batch($limit, $thumb);

        foreach (array('imported', 'local', 'skipped', 'none') as $key) {
            if (!empty($result[$key])) {
                WP_CLI::log(sprintf('%-10s %d', $key, $result[$key]));
            }
        }
        foreach ($result['errors'] as $line) {
            WP_CLI::warning($line);
        }
        if ($result['remaining']) {
            WP_CLI::log(sprintf('%d remaining -- run again to continue.', $result['remaining']));
        }

        WP_CLI::success('Logo import finished.');
    });
}
