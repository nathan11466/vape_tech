<?php
/**
 * The shipping explainer page, and the links into it.
 *
 * The PACT Act is the reason buying a vape online works the way it does:
 * no USPS delivery, an age check against government records before the
 * order is accepted, an adult signature at the door. It is federal, so it
 * is identical on all 51 destination pages -- which is exactly why it does
 * not belong on them. Fifty-one copies of one paragraph is fifty-one
 * pages of boilerplate in the field whose whole job is to carry what the
 * other fifty do not.
 *
 * So it lives on one page, and the destination pages link to it. One page
 * to maintain when the law moves, one page accruing the links and the
 * rankings for "why did my vape order need a signature", and no
 * duplication.
 *
 * The page is created from templates/shipping-explainer.html on request
 * rather than generated on the fly, because once it exists it is an
 * ordinary WordPress page: editable, schedulable, and the editor's
 * changes are never overwritten by this plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VC_EXPLAINER_OPTION = 'vc_merchant_explainer_page';

/**
 * The shipped copy for the page.
 */
function vc_explainer_template() {
    $path = __DIR__ . '/templates/shipping-explainer.html';
    if (!is_readable($path)) {
        return '';
    }

    return (string) file_get_contents($path);
}

/**
 * The page ID, if one is linked and still exists.
 *
 * A page that was linked and has since been deleted or trashed must not
 * leave fifty-one links pointing at a 404, so the stored ID is checked
 * rather than trusted.
 */
function vc_explainer_page_id() {
    $id = (int) get_option(VC_EXPLAINER_OPTION, 0);
    if ($id < 1) {
        return 0;
    }

    $status = get_post_status($id);
    if ($status !== 'publish') {
        return 0;
    }

    return $id;
}

function vc_explainer_url() {
    $id = vc_explainer_page_id();

    return $id ? (string) get_permalink($id) : '';
}

/**
 * The anchor text. Filterable, because the right phrasing depends on the
 * niche and on where the link is being shown.
 */
function vc_explainer_link_text() {
    return apply_filters(
        'vc_explainer_link_text',
        __('Why vape deliveries need a signature, and why the Post Office cannot bring them', 'vc-merchant')
    );
}

/**
 * The link, as HTML. Empty when no page is linked.
 *
 * Followed, not nofollowed: this is an internal link to our own page and
 * the point of consolidating the explainer is that it should accumulate
 * the equity from all fifty-one destinations.
 */
function vc_explainer_link_html() {
    $url = vc_explainer_url();
    if ($url === '') {
        return '';
    }

    $html = '<p class="vc-archive-explainer"><a href="' . esc_url($url) . '">'
        . esc_html(vc_explainer_link_text()) . '</a></p>';

    return apply_filters('vc_explainer_link_html', $html, $url);
}

add_shortcode('merchant_shipping_explainer', function () {
    return vc_explainer_link_html();
});

/**
 * Put the link at the end of the editorial block on a destination page.
 *
 * Deliberately after the state's own note: the state-specific position is
 * what somebody on that page came for, and the federal background is the
 * follow-up rather than the headline. Appended through the filter rather
 * than folded into the disclaimer, so a site that rewords the disclaimer
 * cannot delete the link along with it.
 */
add_filter('vc_archive_intro_html', function ($html, $term = null) {
    $link = vc_explainer_link_html();
    if ($link === '' || !is_string($html) || $html === '') {
        return $html;
    }

    // Inside the intro wrapper, at the end, so it sits with the editorial
    // copy rather than floating above the merchant list.
    $closing = '</div>';
    $at = strrpos($html, $closing);
    if ($at === false) {
        return $html . $link;
    }

    return substr($html, 0, $at) . $link . substr($html, $at);
}, 10, 2);

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    $types = function_exists('vc_merchant_post_types')
        ? vc_merchant_post_types() : array('merchant');
    add_submenu_page(
        'edit.php?post_type=' . reset($types),
        __('Shipping Explainer', 'vc-merchant'),
        __('Shipping Explainer', 'vc-merchant'),
        'manage_options',
        'vc-merchant-explainer',
        'vc_explainer_screen'
    );
}, 23);

/**
 * Create the page from the shipped copy. Returns the new ID or WP_Error.
 */
function vc_explainer_create($title = '') {
    $content = vc_explainer_template();
    if ($content === '') {
        return new WP_Error('vc_explainer_no_template',
            __('templates/shipping-explainer.html is missing from the plugin, so there is nothing to create the page from.', 'vc-merchant'));
    }

    $title = trim((string) $title);
    if ($title === '') {
        $title = __('Why Vape Orders Ship Differently: the PACT Act Explained', 'vc-merchant');
    }

    $id = wp_insert_post(array(
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => $content,
    ), true);

    if (is_wp_error($id)) {
        return $id;
    }

    update_option(VC_EXPLAINER_OPTION, (int) $id);

    return (int) $id;
}

function vc_explainer_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';
    $error = '';

    if (!empty($_POST['vc_explainer_nonce'])
        && wp_verify_nonce($_POST['vc_explainer_nonce'], 'vc_explainer_save')) {

        if (isset($_POST['vc_explainer_create'])) {
            $result = vc_explainer_create($_POST['page_title'] ?? '');
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } else {
                $notice = __('Page created and linked. Every destination page now links to it.', 'vc-merchant');
            }
        } elseif (isset($_POST['vc_explainer_unlink'])) {
            delete_option(VC_EXPLAINER_OPTION);
            $notice = __('Unlinked. The page itself is untouched - delete it yourself if you want it gone.', 'vc-merchant');
        } elseif (isset($_POST['vc_explainer_link'])) {
            $id = (int) ($_POST['existing_page'] ?? 0);
            if ($id > 0 && get_post_status($id) === 'publish') {
                update_option(VC_EXPLAINER_OPTION, $id);
                $notice = __('Linked to the page you chose.', 'vc-merchant');
            } else {
                $error = __('That page could not be found, or is not published.', 'vc-merchant');
            }
        }
    }

    $current = vc_explainer_page_id();
    $pages = get_posts(array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => 200,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Shipping Explainer Page', 'vc-merchant'); ?></h1>

        <p class="description" style="max-width:46em;">
            <?php esc_html_e('The PACT Act is why buying a vape online works the way it does, and it is the same in every state. Rather than repeat it on all 51 destination pages, it goes on one page that they all link to - one page to update when the law moves, and one page collecting the links.', 'vc-merchant'); ?>
        </p>

        <?php if ($error) : ?>
            <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
        <?php endif; ?>
        <?php if ($notice) : ?>
            <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <?php if ($current) : ?>
            <div class="notice notice-info">
                <p>
                    <strong><?php esc_html_e('Linked page:', 'vc-merchant'); ?></strong>
                    <a href="<?php echo esc_url(get_permalink($current)); ?>" target="_blank"><?php
                        echo esc_html(get_the_title($current)); ?></a>
                    &middot;
                    <a href="<?php echo esc_url(get_edit_post_link($current)); ?>"><?php
                        esc_html_e('edit it', 'vc-merchant'); ?></a>
                </p>
                <p class="description"><?php esc_html_e('Edit it freely - this plugin never overwrites the page once it exists. It only links to it.', 'vc-merchant'); ?></p>
            </div>

            <form method="post">
                <?php wp_nonce_field('vc_explainer_save', 'vc_explainer_nonce'); ?>
                <?php submit_button(__('Unlink this page', 'vc-merchant'), 'secondary', 'vc_explainer_unlink', false); ?>
            </form>
        <?php else : ?>
            <h2><?php esc_html_e('Create it', 'vc-merchant'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('vc_explainer_save', 'vc_explainer_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="page_title"><?php esc_html_e('Page title', 'vc-merchant'); ?></label></th>
                        <td>
                            <input type="text" name="page_title" id="page_title" class="large-text"
                                   value="<?php echo esc_attr__('Why Vape Orders Ship Differently: the PACT Act Explained', 'vc-merchant'); ?>" />
                            <p class="description"><?php esc_html_e('The page is created published, with the copy shipped in the plugin. Read it before you send traffic at it.', 'vc-merchant'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Create the page', 'vc-merchant'), 'primary', 'vc_explainer_create'); ?>
            </form>

            <h2><?php esc_html_e('Or point at a page you already have', 'vc-merchant'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('vc_explainer_save', 'vc_explainer_nonce'); ?>
                <select name="existing_page">
                    <option value="0"><?php esc_html_e('— choose a page —', 'vc-merchant'); ?></option>
                    <?php foreach ($pages as $page) : ?>
                        <option value="<?php echo (int) $page->ID; ?>"><?php
                            echo esc_html($page->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Link it', 'vc-merchant'), 'secondary', 'vc_explainer_link', false); ?>
            </form>
        <?php endif; ?>

        <h2><?php esc_html_e('Placing the link yourself', 'vc-merchant'); ?></h2>
        <p class="description" style="max-width:46em;">
            <?php esc_html_e('Destination pages link to it automatically, under the local-rules note. To put the link anywhere else - a merchant page, a sidebar, a post - use this shortcode. It renders nothing at all until a page is linked above.', 'vc-merchant'); ?>
        </p>
        <p><code>[merchant_shipping_explainer]</code></p>
    </div>
    <?php
}
