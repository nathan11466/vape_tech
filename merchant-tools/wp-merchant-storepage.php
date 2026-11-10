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
    // Not before the body has started. get_the_archive_description() is
    // called during wp_head by SEO and page-builder code, and answering
    // one of those with a page of HTML put this entire profile inside
    // <head>, which the browser then showed above the site header.
    if (function_exists('vc_body_started') && !vc_body_started()) {
        return '';
    }

    $post_id = vc_storepage_current_merchant();
    if (!$post_id || is_paged()) {
        // Page two of a store's offers does not want the whole profile
        // again.
        return '';
    }

    return '<div class="vc-storepage">'
        . vc_merchant_render_page(array('id' => $post_id, 'sidebar' => 'no',
            'split' => vc_storepage_split_active() ? 'above' : ''))
        . '</div>';
}

/**
 * Is the page actually split on the store being viewed?
 *
 * Separate from the SETTING, which the sync reads to decide what to write.
 * This asks whether the lower slot can render at all: the sync puts
 * [merchant_page_below] into brand_second_description, and without it there
 * is nowhere for the lower blocks to go.
 *
 * The failure this prevents is silent and total. Upgrade the plugin, do not
 * re-run the sync, and the upper slot would render the title and the hero
 * while the rest of the page -- policies, FAQs, destinations, the sidebar --
 * simply vanished, with nothing on the page to say why. The same happens to
 * one store whose description was edited by hand in ReHub, because the sync
 * then declines to overwrite it.
 *
 * So the split engages only where the lower slot is wired up. Everywhere
 * else the whole profile renders in one piece, exactly as it did before the
 * split existed.
 */
function vc_storepage_split_active() {
    if (!vc_storepage_split()) {
        return false;
    }

    $term = get_queried_object();
    if (empty($term) || is_wp_error($term) || empty($term->term_id)) {
        return false;
    }

    $below = (string) get_term_meta($term->term_id, 'brand_second_description', true);

    return strpos($below, '[merchant_page_below]') !== false;
}

/**
 * Is the profile split around the theme's coupon list?
 *
 * On by default. ReHub prints the coupons in the middle of the page, and
 * everything here used to land above them, so a reader met the full profile
 * -- policies, FAQs, company details, similar stores -- before reaching the
 * codes they came for.
 */
function vc_storepage_split() {
    return (bool) apply_filters('vc_storepage_split',
        get_option('vc_storepage_split', '1') === '1');
}

/**
 * The part of the profile that belongs below the theme's coupon list.
 *
 * Reached as a shortcode rather than written into term meta: ReHub runs
 * brand_second_description through do_shortcode, so this renders live on
 * every request. Storing the HTML instead would freeze it at sync time, and
 * it is most of the page.
 */
/**
 * The sidebar column, for ReHub's own store sidebar.
 *
 * ReHub lays a store page out in three slots: content above its offer list,
 * content below it, and the sidebar. This is the third. It renders the blocks
 * set to the side column in Layout, plus the facts panel, and goes into
 * brand_short_description -- which ReHub prints in the mini-sidebar and runs
 * through do_shortcode.
 *
 * A shortcode rather than HTML written at sync time, for the same reason as
 * the lower part: term meta would freeze it, and the facts panel carries the
 * last-checked date, which is the one thing on it that must not go stale.
 */
add_shortcode('merchant_page_sidebar', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_page_sidebar');

    $post_id = (int) $atts['id'] ?: vc_storepage_current_merchant();
    if (!$post_id) {
        return '';
    }

    $out = '';
    if (function_exists('vc_rehub_sidebar_facts')) {
        $out .= vc_rehub_sidebar_facts($post_id);
    }

    $out .= vc_merchant_render_page(array('id' => $post_id, 'sidebar' => 'no',
        'split' => 'side'));

    return trim($out) === '' ? ''
        : '<div class="vc-storepage vc-storepage--side">' . $out . '</div>';
});

add_shortcode('merchant_page_below', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_page_below');

    $post_id = (int) $atts['id'] ?: vc_storepage_current_merchant();
    if (!$post_id || !vc_storepage_split()) {
        return '';
    }

    return '<div class="vc-storepage vc-storepage--below">'
        . vc_merchant_render_page(array('id' => $post_id, 'sidebar' => 'no',
            'split' => 'below'))
        . '</div>';
});

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

/**
 * The hook ReHub actually uses.
 *
 * taxonomy-dealstore.php does not call get_the_archive_description() at
 * all. Line 33 calls term_description(), prints it inside
 * <div class="term-description post"> under the store's H1, and skips it
 * on paginated pages. That is both the only reliable hook on this template
 * and the right place for the profile to sit -- inside ReHub's own content
 * column rather than wherever a generic hook happens to fire.
 *
 * It also works on a store with no offers posted to it, where no loop runs
 * and loop_start never fires.
 */
add_filter('term_description', function ($description) {
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

    // A title typed into the Rank Math box on the merchant wins, exactly as
    // it does on our own page. Without this the two disagree: an editor's
    // hand-written title is honoured at /stores/brand/ and silently
    // discarded on the store page that replaced it.
    $manual = trim((string) get_post_meta($post_id, 'rank_math_title', true));
    if ($manual !== '') {
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

    $manual = trim((string) get_post_meta($post_id, 'rank_math_description', true));
    if ($manual !== '') {
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
