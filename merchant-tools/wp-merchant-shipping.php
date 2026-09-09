<?php
/**
 * Shipping-destination taxonomy.
 *
 * Distinct from service_location, which is the merchant's operating market.
 * This is where they actually ship, which for vape and hemp sellers is
 * routinely narrower -- and it is what shoppers filter by.
 *
 * Hierarchical: United States > each of the 50 states plus DC, alongside the
 * other countries. Because it is public with archives, /ships-to/california/
 * becomes a browsable landing page listing every merchant shipping there.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * US states plus DC.
 */
function vc_us_states() {
    return array(
        'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado',
        'Connecticut', 'Delaware', 'District of Columbia', 'Florida', 'Georgia',
        'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa', 'Kansas', 'Kentucky',
        'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan',
        'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada',
        'New Hampshire', 'New Jersey', 'New Mexico', 'New York',
        'North Carolina', 'North Dakota', 'Ohio', 'Oklahoma', 'Oregon',
        'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota',
        'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington',
        'West Virginia', 'Wisconsin', 'Wyoming',
    );
}

/**
 * The full destination tree: parent => children.
 */
function vc_shipping_destinations() {
    return array(
        'United States'  => vc_us_states(),
        'Canada'         => array(
            'Alberta', 'British Columbia', 'Manitoba', 'New Brunswick',
            'Newfoundland and Labrador', 'Nova Scotia', 'Ontario',
            'Prince Edward Island', 'Quebec', 'Saskatchewan',
        ),
        'United Kingdom' => array('England', 'Scotland', 'Wales', 'Northern Ireland'),
        'Mexico'         => array(),
        'Germany'        => array(),
        'France'         => array(),
        'Netherlands'    => array(),
        'Spain'          => array(),
        'Italy'          => array(),
        'Ireland'        => array(),
        'Australia'      => array(),
        'New Zealand'    => array(),
        'Japan'          => array(),
        'European Union' => array(),
        'Worldwide'      => array(),
    );
}

function vc_register_ships_to_taxonomy() {
    register_taxonomy('ships_to', vc_merchant_post_types(), array(
        'labels' => array(
            'name'              => __('Ships To', 'vc-merchant'),
            'singular_name'     => __('Shipping Destination', 'vc-merchant'),
            'search_items'      => __('Search Destinations', 'vc-merchant'),
            'all_items'         => __('All Destinations', 'vc-merchant'),
            'parent_item'       => __('Parent Destination', 'vc-merchant'),
            'edit_item'         => __('Edit Destination', 'vc-merchant'),
            'add_new_item'      => __('Add New Destination', 'vc-merchant'),
            'menu_name'         => __('Ships To', 'vc-merchant'),
        ),
        'hierarchical'      => true,
        'public'            => true,
        'show_admin_column' => true,
        'show_in_rest'      => true,
        'query_var'         => true,
        'rewrite'           => array('slug' => 'ships-to', 'hierarchical' => true),
    ));
}
add_action('init', 'vc_register_ships_to_taxonomy');

/**
 * Seed the destination tree. Idempotent -- safe to re-run.
 */
function vc_seed_ships_to() {
    vc_register_ships_to_taxonomy();

    foreach (vc_shipping_destinations() as $parent_name => $children) {
        $parent = term_exists($parent_name, 'ships_to');
        if (!$parent) {
            $parent = wp_insert_term($parent_name, 'ships_to');
        }
        if (is_wp_error($parent)) {
            continue;
        }
        $parent_id = is_array($parent) ? (int) $parent['term_id'] : (int) $parent;

        foreach ($children as $child) {
            // Slug is namespaced by parent, since some state and country names
            // collide (Georgia the state vs Georgia the country, and several
            // US/Canadian province names).
            $slug = sanitize_title($parent_name . '-' . $child);
            if (!term_exists($slug, 'ships_to')) {
                wp_insert_term($child, 'ships_to', array(
                    'parent' => $parent_id,
                    'slug'   => $slug,
                ));
            }
        }
    }
}
register_activation_hook(__DIR__ . '/wp-merchant-fields.php', 'vc_seed_ships_to');

/**
 * Assign destination terms to a merchant from a pipe-delimited list.
 *
 * Values may be plain names ("California") or parent-qualified
 * ("United States > California"). A state assignment also assigns its parent
 * country, so a country archive lists every merchant shipping anywhere in it.
 */
function vc_assign_ships_to($post_id, $list) {
    $names = array_filter(array_map('trim', explode('|', (string) $list)));
    if (empty($names)) {
        return 0;
    }

    $tree = vc_shipping_destinations();
    $term_ids = array();

    foreach ($names as $name) {
        $parent_hint = '';
        if (strpos($name, '>') !== false) {
            list($parent_hint, $name) = array_map('trim', explode('>', $name, 2));
        }

        // Find which country this name belongs under, if any.
        $parent_name = '';
        if ($parent_hint !== '' && isset($tree[$parent_hint])) {
            $parent_name = $parent_hint;
        } else {
            foreach ($tree as $country => $children) {
                if (strcasecmp($country, $name) === 0) {
                    $parent_name = '';
                    break;
                }
                if (in_array($name, $children, true)) {
                    $parent_name = $country;
                    break;
                }
            }
        }

        $slug = $parent_name !== ''
            ? sanitize_title($parent_name . '-' . $name)
            : sanitize_title($name);

        $term = get_term_by('slug', $slug, 'ships_to');
        if (!$term) {
            $term = get_term_by('name', $name, 'ships_to');
        }
        if (!$term) {
            continue;
        }
        $term_ids[] = (int) $term->term_id;

        // Roll the country up too, so country archives stay complete.
        if ($parent_name !== '') {
            $country_term = get_term_by('name', $parent_name, 'ships_to');
            if ($country_term) {
                $term_ids[] = (int) $country_term->term_id;
            }
        }
    }

    $term_ids = array_values(array_unique($term_ids));
    if (empty($term_ids)) {
        return 0;
    }

    wp_set_object_terms($post_id, $term_ids, 'ships_to', false);

    return count($term_ids);
}

/**
 * Destinations this merchant does NOT ship to, for display.
 *
 * Kept as meta rather than terms: an archive of "merchants that cannot ship
 * here" is not a page anyone wants to browse, but it is worth stating on the
 * merchant's own page.
 */
function vc_merchant_restricted_line($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $raw = trim((string) get_post_meta($post_id, 'restricted_states', true));
    if ($raw === '') {
        return '';
    }

    $names = array_filter(array_map('trim', explode('|', $raw)));
    if (empty($names)) {
        return '';
    }

    return '<p class="vc-restricted"><strong>'
        . esc_html__('Cannot ship to:', 'vc-merchant') . '</strong> '
        . esc_html(implode(', ', $names)) . '</p>';
}
