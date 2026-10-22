<?php
/**
 * Render the merchant sections on a ReHub store page.
 *
 * ReHub keeps stores as `dealstore` terms, so the page a shopper lands on
 * -- /coupon-codes/1st-class-cigar-humidors/ -- is a taxonomy archive, not
 * the `merchant` post this plugin fills. Every one of the sixty-odd columns
 * collected for that store was sitting on a post nobody visits, while the
 * page that gets the traffic showed ReHub's offer box and nothing else.
 *
 * Nothing here re-implements those sections. The dealstore term is matched
 * back to its merchant post and vc_merchant_render_page() is called with
 * that id, so the store page shows exactly what the merchant page shows,
 * in the order and columns set under Page Layout, and both move together
 * when the CSV is re-imported.
 *
 * Printed at loop_start, because ReHub's taxonomy template never calls
 * get_the_archive_description() -- the same reason the destination pages
 * rendered nothing until 1.17.0.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Whether to fill ReHub store pages at all.
 *
 * On by default: without it these pages are empty of everything this
 * plugin knows, which is the whole reason the module exists. Filterable
 * for a site that renders the sections from its own template instead.
 */
function vc_storepage_enabled() {
    return (bool) apply_filters('vc_storepage_enabled', true);
}

/**
 * The merchant post behind a dealstore term, or 0.
 *
 * The stored term id is authoritative -- the ReHub sync writes it to
 * rehub_dealstore_term_id when it creates or adopts the term, and it
 * survives a rename on either side. Names are only a fallback, for a term
 * made by hand in wp-admin before the sync ever saw it.
 */
function vc_storepage_merchant_for_term($term) {
    if (!$term || is_wp_error($term) || empty($term->term_id)) {
        return 0;
    }

    $found = get_posts(array(
        'post_type'        => vc_merchant_post_types(),
        'post_status'      => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'meta_key'         => 'rehub_dealstore_term_id',
        'meta_value'       => (int) $term->term_id,
        'suppress_filters' => false,
    ));
    if (!empty($found)) {
        return (int) $found[0];
    }

    // Fallback: the display name, then the post title. Deliberately exact
    // rather than fuzzy -- guessing which store a page is about would put
    // one merchant's shipping policy on another's page.
    $name = trim((string) $term->name);
    if ($name === '') {
        return 0;
    }

    $by_display = get_posts(array(
        'post_type'        => vc_merchant_post_types(),
        'post_status'      => array('publish', 'draft', 'pending', 'private'),
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'meta_key'         => 'display_brand_name',
        'meta_value'       => $name,
        'suppress_filters' => false,
    ));
    if (!empty($by_display)) {
        return (int) $by_display[0];
    }

    $page = get_page_by_title($name, OBJECT, vc_merchant_post_types());

    return ($page && !is_wp_error($page)) ? (int) $page->ID : 0;
}

/**
 * The merchant post for the store page being viewed, or 0.
 *
 * Resolved once per request: the meta lookup is two queries and the
 * sections ask for it more than once.
 */
function vc_storepage_current_merchant() {
    if (!vc_storepage_enabled() || !is_tax('dealstore')) {
        return 0;
    }

    if (isset($GLOBALS['vc_storepage_merchant'])) {
        return (int) $GLOBALS['vc_storepage_merchant'];
    }

    $id = vc_storepage_merchant_for_term(get_queried_object());
    $GLOBALS['vc_storepage_merchant'] = $id;

    return $id;
}

/**
 * Has the stack already been printed on this request?
 *
 * Same two-hooks-one-slot problem as the destination intro: whichever
 * fires first claims it.
 */
function vc_storepage_claimed($claim = false) {
    if ($claim) {
        $was = !empty($GLOBALS['vc_storepage_claimed']);
        $GLOBALS['vc_storepage_claimed'] = true;

        return $was;
    }

    return !empty($GLOBALS['vc_storepage_claimed']);
}

/**
 * The sections for the current store page, or ''.
 */
function vc_storepage_html() {
    $post_id = vc_storepage_current_merchant();
    if (!$post_id || is_paged()) {
        // Page two of a store's offers does not want the whole profile
        // again.
        return '';
    }

    return '<div class="vc-storepage">'
        . vc_merchant_render_page(array('id' => $post_id, 'sidebar' => 'no'))
        . '</div>';
}

add_action('loop_start', function ($query) {
    if (!$query instanceof WP_Query || !$query->is_main_query()) {
        return;
    }
    if (vc_storepage_claimed()) {
        return;
    }
    $html = vc_storepage_html();
    if ($html === '') {
        return;
    }
    vc_storepage_claimed(true);
    echo $html;
}, 6);

add_filter('get_the_archive_description', function ($description) {
    if (vc_storepage_claimed()) {
        return $description;
    }
    $html = vc_storepage_html();
    if ($html === '') {
        return $description;
    }
    vc_storepage_claimed(true);

    return $html . $description;
});

/**
 * A store page with no offers posted to it still runs no loop, so
 * loop_start never fires and nothing above would print. Catch that case
 * from the footer of the content area instead.
 *
 * Only when the term has no posts at all -- otherwise this would print a
 * second copy below the listing.
 */
add_action('loop_no_results', 'vc_storepage_print_once');
add_action('rehub_after_archive_content', 'vc_storepage_print_once');

function vc_storepage_print_once() {
    if (vc_storepage_claimed()) {
        return;
    }
    $html = vc_storepage_html();
    if ($html === '') {
        return;
    }
    vc_storepage_claimed(true);
    echo $html;
}

/**
 * Tell Rank Math about the store page, so the title and description come
 * from the merchant's own fields rather than the bare term name.
 *
 * The seo module already does this for merchant posts and destination
 * archives; this points the same functions at the store page.
 */
add_filter('rank_math/frontend/title', function ($title) {
    $post_id = vc_storepage_current_merchant();
    if (!$post_id || !function_exists('vc_merchant_seo_title')) {
        return $title;
    }
    $built = trim((string) vc_merchant_seo_title($post_id));

    return $built !== '' ? $built : $title;
}, 11);

add_filter('rank_math/frontend/description', function ($description) {
    $post_id = vc_storepage_current_merchant();
    if (!$post_id || !function_exists('vc_merchant_meta_description')) {
        return $description;
    }
    $built = trim((string) vc_merchant_meta_description($post_id));

    return $built !== '' ? $built : $description;
}, 11);

/**
 * Admin: which store pages would render, and which would not.
 *
 * Without this, finding the stores whose term never got matched means
 * opening them one at a time.
 */
add_action('admin_init', function () {
    add_filter('manage_edit-dealstore_columns', function ($columns) {
        $columns['vc_storepage'] = __('Store profile', 'vc-merchant');

        return $columns;
    });

    add_filter('manage_dealstore_custom_column', function ($content, $column, $term_id) {
        if ($column !== 'vc_storepage') {
            return $content;
        }
        $term = get_term($term_id, 'dealstore');
        $post_id = vc_storepage_merchant_for_term($term);

        if (!$post_id) {
            return '<span class="vc-index vc-index--no">'
                . esc_html__('No merchant matched', 'vc-merchant') . '</span>'
                . '<span class="vc-index__why">'
                . esc_html__('run Stores -> ReHub Sync, or rename the term to match the store', 'vc-merchant')
                . '</span>';
        }

        return '<span class="vc-index vc-index--yes">'
            . esc_html__('Filled', 'vc-merchant') . '</span>'
            . '<span class="vc-index__why"><a href="' . esc_url((string) get_edit_post_link($post_id)) . '">'
            . esc_html(get_the_title($post_id)) . '</a></span>';
    }, 10, 3);
});
