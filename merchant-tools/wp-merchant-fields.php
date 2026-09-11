<?php
/**
 * Plugin Name: VapingCheap Merchant Fields
 * Description: Service-location taxonomy, brand aliases, merchant contact details, and publish-gated display helpers for coupon/merchant pages.
 * Version: 1.0.0
 * Text Domain: vc-merchant
 *
 * Companion to merchant-tools/enrich_merchants.py. The enrichment script grades
 * each merchant and derives `offer_display_mode`; the helpers here are the only
 * sanctioned way to render offer claims, so a page can never imply a coupon code
 * exists when one has not been confirmed.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post type the merchant data attaches to. Filterable so this drops into an
 * existing coupon/offers plugin rather than assuming a post type name.
 */
function vc_merchant_post_types() {
    return apply_filters('vc_merchant_post_types', array('merchant'));
}

/**
 * Load the rest of the plugin. Each file is optional, so a partial upload
 * degrades rather than fataling the site.
 */
foreach (array('wp-merchant-shipping.php', 'wp-merchant-render.php',
               'wp-merchant-seo.php', 'wp-merchant-archive.php',
               'wp-merchant-import.php') as $vc_module) {
    $vc_path = __DIR__ . '/' . $vc_module;
    if (file_exists($vc_path)) {
        require_once $vc_path;
    }
}
unset($vc_module, $vc_path);

/**
 * Front-end stylesheet. Scoped to .vc-* classes so it does not fight the theme.
 */
add_action('wp_enqueue_scripts', function () {
    $rel = 'assets/merchant-pages.css';
    $path = __DIR__ . '/' . $rel;
    if (!file_exists($path)) {
        return;
    }
    wp_enqueue_style(
        'vc-merchant-pages',
        plugins_url($rel, __FILE__),
        array(),
        (string) filemtime($path)
    );
});

/* -------------------------------------------------------------------------
 * Service location taxonomy
 *
 * Operating market only. Where a merchant SHIPS is tracked separately in the
 * ships_to_countries meta field -- the two are routinely different for vape and
 * cannabinoid sellers with state/country product restrictions.
 * ---------------------------------------------------------------------- */

function vc_register_service_location_taxonomy() {
    register_taxonomy('service_location', vc_merchant_post_types(), array(
        'labels' => array(
            'name'          => __('Service Locations', 'vc-merchant'),
            'singular_name' => __('Service Location', 'vc-merchant'),
            'search_items'  => __('Search Service Locations', 'vc-merchant'),
            'all_items'     => __('All Service Locations', 'vc-merchant'),
            'parent_item'   => __('Parent Service Location', 'vc-merchant'),
            'edit_item'     => __('Edit Service Location', 'vc-merchant'),
            'add_new_item'  => __('Add New Service Location', 'vc-merchant'),
        ),
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'rewrite'           => array('slug' => 'service-location', 'hierarchical' => true),
    ));
}
add_action('init', 'vc_register_service_location_taxonomy');

/**
 * The seed hierarchy. Runs on activation and is idempotent, so re-running it
 * never duplicates terms.
 */
function vc_service_location_seed() {
    return array(
        'North America' => array('United States', 'Canada', 'Mexico'),
        'Europe'        => array('United Kingdom', 'Germany', 'France', 'European Union'),
        'Asia-Pacific'  => array('Australia', 'New Zealand', 'Japan'),
        'International Shipping'            => array(),
        'Online Only / Location Not Confirmed' => array(),
    );
}

function vc_seed_service_locations() {
    vc_register_service_location_taxonomy();

    foreach (vc_service_location_seed() as $parent_name => $children) {
        $parent = term_exists($parent_name, 'service_location');
        if (!$parent) {
            $parent = wp_insert_term($parent_name, 'service_location');
        }
        if (is_wp_error($parent)) {
            continue;
        }
        $parent_id = is_array($parent) ? (int) $parent['term_id'] : (int) $parent;

        foreach ($children as $child_name) {
            if (!term_exists($child_name, 'service_location')) {
                wp_insert_term($child_name, 'service_location', array('parent' => $parent_id));
            }
        }
    }
}
register_activation_hook(__FILE__, 'vc_seed_service_locations');

/* -------------------------------------------------------------------------
 * Merchant meta fields
 *
 * Aliases are stored as meta (pipe-delimited on import, array in PHP) rather
 * than a taxonomy -- they are naming variants, not a browsable classification.
 * ---------------------------------------------------------------------- */

function vc_merchant_meta_fields() {
    return array(
        'display_brand_name'      => 'string',
        'alternative_brand_names' => 'string',
        'ships_to_countries'      => 'string',
        'primary_service_location'=> 'string',
        'contact_email'           => 'string',
        'contact_page_url'        => 'string',
        'contact_method'          => 'string',
        'contact_verified_at'     => 'string',
        'contact_source_url'      => 'string',
        'fact_source_url'         => 'string',
        'fact_last_verified'      => 'string',
        'content_confidence'      => 'string',
        'publish_status'          => 'string',
        'offer_display_mode'      => 'string',
        'company_trust_info'      => 'string',
        'last_checked_text'       => 'string',
    );
}

function vc_register_merchant_meta() {
    foreach (vc_merchant_post_types() as $post_type) {
        foreach (vc_merchant_meta_fields() as $key => $type) {
            register_post_meta($post_type, $key, array(
                'type'          => $type,
                'single'        => true,
                'show_in_rest'  => true,
                'auth_callback' => function () {
                    return current_user_can('edit_posts');
                },
            ));
        }
    }
}
add_action('init', 'vc_register_merchant_meta');

/* -------------------------------------------------------------------------
 * Display helpers -- the trust layer
 * ---------------------------------------------------------------------- */

/**
 * Whether this merchant is publicly visible.
 *
 * This defers entirely to WordPress post status. The enrichment script's
 * grading is advisory metadata for sorting a review queue -- it does not gate
 * the front end, because a post you published is a post you decided to publish.
 *
 * Retained mainly for preview_page.php, which has no WordPress post status to
 * consult.
 */
function vc_merchant_is_publishable($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    if (function_exists('get_post_status')) {
        return get_post_status($post_id) === 'publish';
    }

    return true;
}

/**
 * Offer badge. The ONLY sanctioned way to state what a page is claiming.
 *
 * Never asserts that a code exists unless offer_display_mode says so, which the
 * enrichment script only sets when a real code is paired with a source or a
 * recent test.
 */
function vc_merchant_offer_badge($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $mode = trim((string) get_post_meta($post_id, 'offer_display_mode', true));
    $checked = trim((string) get_post_meta($post_id, 'last_checked_text', true));

    switch ($mode) {
        case 'verified_code':
            $label = __('Verified Code', 'vc-merchant');
            $class = 'vc-badge vc-badge--verified';
            break;

        case 'best_deal':
            $label = __('Best Deal', 'vc-merchant');
            $class = 'vc-badge vc-badge--deal';
            break;

        default:
            // No confirmed code. Say so plainly rather than implying one exists.
            $label = __('No active code confirmed - see current deals', 'vc-merchant');
            $class = 'vc-badge vc-badge--none';
            break;
    }

    $html  = '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    if ($checked !== '') {
        $html .= ' <span class="vc-checked">' . esc_html($checked) . '</span>';
    }

    return $html;
}

/**
 * Aliases as a short "Also known as" line.
 *
 * Aliases belong in body copy and FAQs, never stuffed into the title, H1, or
 * schema name -- those stay on the single canonical display name.
 */
function vc_merchant_aliases_line($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $raw = trim((string) get_post_meta($post_id, 'alternative_brand_names', true));
    if ($raw === '') {
        return '';
    }

    $aliases = array_filter(array_map('trim', explode('|', $raw)));
    if (empty($aliases)) {
        return '';
    }

    $escaped = array_map('esc_html', $aliases);

    return '<p class="vc-aliases"><strong>' . esc_html__('Also known as:', 'vc-merchant') . '</strong> '
        . implode(', ', $escaped) . '</p>';
}

/**
 * Canonical public name for title / H1 / schema. Falls back to the raw brand
 * name when no editorial display name was set.
 */
function vc_merchant_display_name($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $display = trim((string) get_post_meta($post_id, 'display_brand_name', true));

    return $display !== '' ? $display : get_the_title($post_id);
}

/**
 * Trust / reputation copy, gated hard.
 *
 * Returns an empty string unless the claim carries both a source URL and a
 * verification date. Reputation claims are the highest-risk content on these
 * pages, so an unsourced one renders as nothing at all.
 */
function vc_merchant_trust_info($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $claim  = trim((string) get_post_meta($post_id, 'company_trust_info', true));
    $source = trim((string) get_post_meta($post_id, 'fact_source_url', true));
    $date   = trim((string) get_post_meta($post_id, 'fact_last_verified', true));

    if ($claim === '' || $source === '' || $date === '') {
        return '';
    }

    return '<div class="vc-trust"><p>' . esc_html($claim) . '</p>'
        . '<p class="vc-trust__source"><a href="' . esc_url($source) . '" rel="nofollow noopener" target="_blank">'
        . esc_html__('Source', 'vc-merchant') . '</a> &middot; '
        . esc_html(sprintf(__('verified %s', 'vc-merchant'), $date))
        . '</p></div>';
}

/**
 * Merchant information panel: operating market, shipping coverage, aliases,
 * support link, last checked.
 *
 * Policy-type claims carry a change disclaimer, since shipping, returns and
 * payment terms move without notice.
 */
function vc_merchant_info_panel($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    $primary = trim((string) get_post_meta($post_id, 'primary_service_location', true));
    $ships   = trim((string) get_post_meta($post_id, 'ships_to_countries', true));
    $email   = trim((string) get_post_meta($post_id, 'contact_email', true));
    $page    = trim((string) get_post_meta($post_id, 'contact_page_url', true));
    $checked = trim((string) get_post_meta($post_id, 'last_checked_text', true));

    $rows = array();

    if ($primary !== '') {
        $rows[] = array(__('Primary market', 'vc-merchant'), esc_html($primary));
    }

    if ($ships !== '') {
        $list = array_filter(array_map('trim', explode('|', $ships)));
        if (!empty($list)) {
            $rows[] = array(__('Ships to', 'vc-merchant'), esc_html(implode(', ', $list)));
        }
    }

    // Prefer a public support email; fall back to the official contact page.
    if ($email !== '' && is_email($email)) {
        $rows[] = array(
            __('Customer support', 'vc-merchant'),
            '<a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a>',
        );
    } elseif ($page !== '') {
        $rows[] = array(
            __('Customer support', 'vc-merchant'),
            '<a href="' . esc_url($page) . '" rel="nofollow noopener" target="_blank">'
                . esc_html__('Official contact page', 'vc-merchant') . '</a>',
        );
    }

    if ($checked !== '') {
        $rows[] = array(__('Last checked', 'vc-merchant'), esc_html($checked));
    }

    if (empty($rows)) {
        return '';
    }

    $html = '<div class="vc-merchant-info"><h2>' . esc_html__('Merchant information', 'vc-merchant') . '</h2><dl>';
    foreach ($rows as $row) {
        $html .= '<dt>' . esc_html($row[0]) . '</dt><dd>' . $row[1] . '</dd>';
    }
    $html .= '</dl>';

    $html .= '<p class="vc-disclaimer">'
        . esc_html__('Shipping, returns and payment details can change - confirm at checkout.', 'vc-merchant')
        . '</p>';

    $html .= vc_merchant_aliases_line($post_id);
    $html .= '</div>';

    return $html;
}
