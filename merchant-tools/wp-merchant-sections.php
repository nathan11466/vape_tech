<?php
/**
 * Composable section shortcodes, and related-store linking.
 *
 * [merchant_page] renders the whole body in a fixed order, which is fine as a
 * default but leaves no room to rearrange sections, drop one into a sidebar,
 * or put something of your own between two of them. Each section is therefore
 * also available on its own, so a page builder or the block editor can lay the
 * page out however you like.
 *
 * Sidebars themselves are the theme's job -- these shortcodes render into
 * whatever column you place them in.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolve the merchant a shortcode is talking about.
 */
function vc_section_post_id($atts) {
    $id = isset($atts['id']) ? (int) $atts['id'] : 0;

    return $id ?: (int) get_the_ID();
}

function vc_section_meta($post_id, $key) {
    return trim((string) get_post_meta($post_id, $key, true));
}

/* -------------------------------------------------------------------------
 * Related stores
 * ---------------------------------------------------------------------- */

/**
 * Merchants related to this one.
 *
 * Related by shared product category first, since two stores selling e-liquid
 * are a more useful comparison than two that merely ship to the same state.
 * Falls back to shared shipping destinations, then to any other merchant, so
 * the section is never empty on a site with more than one store.
 *
 * @param string $by category | ships_to | both
 * @return int[] post ids
 */
function vc_merchant_related_ids($post_id, $limit = 8, $by = 'category') {
    $taxonomies = array();
    if ($by === 'category' || $by === 'both') {
        $taxonomies[] = 'merchant_category';
    }
    if ($by === 'ships_to' || $by === 'both') {
        $taxonomies[] = 'ships_to';
    }

    $found = array();

    foreach ($taxonomies as $taxonomy) {
        if (count($found) >= $limit) {
            break;
        }

        $terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'ids'));
        if (is_wp_error($terms) || empty($terms)) {
            continue;
        }

        // For ships_to, ignore the country roll-ups -- almost every US store
        // shares "United States", which would make the section meaningless.
        if ($taxonomy === 'ships_to') {
            $specific = array();
            foreach ($terms as $term_id) {
                $term = get_term($term_id, $taxonomy);
                if ($term && !is_wp_error($term) && (int) $term->parent !== 0) {
                    $specific[] = $term_id;
                }
            }
            if (!empty($specific)) {
                $terms = $specific;
            }
        }

        $query = new WP_Query(array(
            'post_type'           => vc_merchant_post_types(),
            'post_status'         => 'publish',
            'posts_per_page'      => $limit * 2,
            'post__not_in'        => array_merge(array($post_id), $found),
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'orderby'             => 'rand',
            'tax_query'           => array(array(
                'taxonomy' => $taxonomy,
                'field'    => 'term_id',
                'terms'    => $terms,
            )),
        ));

        foreach ($query->posts as $id) {
            if (!in_array($id, $found, true)) {
                $found[] = (int) $id;
            }
            if (count($found) >= $limit) {
                break;
            }
        }
    }

    // Nothing shared? Fall back to other merchants so the section still links
    // somewhere rather than rendering an empty heading.
    if (count($found) < $limit) {
        $filler = new WP_Query(array(
            'post_type'           => vc_merchant_post_types(),
            'post_status'         => 'publish',
            'posts_per_page'      => $limit - count($found),
            'post__not_in'        => array_merge(array($post_id), $found),
            'fields'              => 'ids',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
            'orderby'             => 'rand',
        ));
        foreach ($filler->posts as $id) {
            $found[] = (int) $id;
        }
    }

    return array_slice($found, 0, $limit);
}

/**
 * [merchant_related limit="8" by="category" style="links" title="Similar stores"]
 *
 * style="links" gives a compact pill list; style="cards" reuses the archive
 * card, with each store's offer and shipping threshold.
 */
function vc_merchant_related_shortcode($atts) {
    $atts = shortcode_atts(array(
        'id'    => 0,
        'limit' => 8,
        'by'    => 'category',
        'style' => 'links',
        'title' => __('Similar stores', 'vc-merchant'),
    ), $atts, 'merchant_related');

    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }

    $ids = vc_merchant_related_ids($post_id, max(1, (int) $atts['limit']), $atts['by']);
    if (empty($ids)) {
        return '';
    }

    $out = '<section class="vc-related-stores">';
    if (trim((string) $atts['title']) !== '') {
        $out .= '<h2>' . esc_html($atts['title']) . '</h2>';
    }

    if ($atts['style'] === 'cards' && function_exists('vc_archive_merchant_card')) {
        $out .= '<ul class="vc-archive-list">';
        foreach ($ids as $id) {
            $out .= vc_archive_merchant_card($id);
        }
        $out .= '</ul>';
    } else {
        $out .= '<ul class="vc-related-links">';
        foreach ($ids as $id) {
            $name = function_exists('vc_merchant_display_name')
                ? vc_merchant_display_name($id)
                : get_the_title($id);
            $out .= '<li><a href="' . esc_url(get_permalink($id)) . '">'
                . esc_html(sprintf(__('%s coupons', 'vc-merchant'), $name)) . '</a></li>';
        }
        $out .= '</ul>';
    }

    $out .= '</section>';

    return $out;
}
add_shortcode('merchant_related', 'vc_merchant_related_shortcode');

/* -------------------------------------------------------------------------
 * Individual sections
 * ---------------------------------------------------------------------- */

/**
 * [merchant_offer] -- badge, freshness, best deal, and the coupon widget.
 */
add_shortcode('merchant_offer', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_offer');
    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }

    $out = '<div class="vc-offer-status">' . vc_merchant_offer_badge($post_id) . '</div>';
    $out .= vc_merchant_section(__('Best current deal', 'vc-merchant'),
        vc_section_meta($post_id, 'best_offer_summary'));

    $shortcode = vc_section_meta($post_id, 'coupon_plugin_shortcode');
    if ($shortcode !== '') {
        $out .= '<div class="vc-coupon-widget">' . do_shortcode($shortcode) . '</div>';
    }

    return $out;
});

/**
 * [merchant_about] -- brand summary and savings tips.
 */
add_shortcode('merchant_about', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_about');
    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }
    $name = vc_merchant_display_name($post_id);

    return vc_merchant_section(sprintf(__('About %s', 'vc-merchant'), $name),
            vc_section_meta($post_id, 'brand_summary'))
        . vc_merchant_section(sprintf(__('Best ways to save at %s', 'vc-merchant'), $name),
            vc_section_meta($post_id, 'best_ways_to_save'));
});

/**
 * [merchant_policies] -- shipping, returns, payment, exclusions, stacking,
 * troubleshooting and restrictions.
 */
add_shortcode('merchant_policies', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_policies');
    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }

    $out  = vc_merchant_section(__('Shipping', 'vc-merchant'), vc_section_meta($post_id, 'free_shipping_info'));
    $out .= vc_merchant_section(__('Returns', 'vc-merchant'), vc_section_meta($post_id, 'return_policy_summary'));
    $out .= vc_merchant_section(__('Payment methods', 'vc-merchant'), vc_section_meta($post_id, 'payment_methods'));
    $out .= vc_merchant_section(__('Exclusions', 'vc-merchant'), vc_section_meta($post_id, 'common_exclusions'));
    $out .= vc_merchant_section(__('Stacking codes', 'vc-merchant'), vc_section_meta($post_id, 'stacking_policy'));
    $out .= vc_merchant_section(__('If your code will not work', 'vc-merchant'), vc_section_meta($post_id, 'why_code_not_work'));
    $out .= vc_merchant_section(__('Shipping restrictions', 'vc-merchant'), vc_section_meta($post_id, 'shipping_restrictions'));

    if (function_exists('vc_merchant_restricted_line')) {
        $out .= vc_merchant_restricted_line($post_id);
    }

    return $out;
});

/**
 * [merchant_faqs] -- the Q&As that also feed FAQPage schema.
 */
add_shortcode('merchant_faqs', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_faqs');
    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }

    $faqs = '';
    for ($i = 1; $i <= 3; $i++) {
        $q = vc_section_meta($post_id, "faq_{$i}_question");
        $a = vc_section_meta($post_id, "faq_{$i}_answer");
        if ($q !== '' && $a !== '') {
            $faqs .= '<div class="vc-faq"><h3>' . esc_html($q) . '</h3>' . wpautop(esc_html($a)) . '</div>';
        }
    }
    if ($faqs === '') {
        return '';
    }

    return '<section class="vc-faqs"><h2>'
        . esc_html(sprintf(__('%s FAQs', 'vc-merchant'), vc_merchant_display_name($post_id)))
        . '</h2>' . $faqs . '</section>';
});

/**
 * [merchant_info] -- the merchant information panel. Sized for a sidebar.
 */
add_shortcode('merchant_info', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_info');
    $post_id = vc_section_post_id($atts);

    return $post_id ? vc_merchant_info_panel($post_id) : '';
});

/**
 * [merchant_trust] -- company information, still gated on a source and date.
 */
add_shortcode('merchant_trust', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_trust');
    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }

    $trust = vc_merchant_trust_info($post_id);

    return $trust === '' ? '' : '<section class="vc-trust-section"><h2>'
        . esc_html__('Company information', 'vc-merchant') . '</h2>' . $trust . '</section>';
});

/**
 * [merchant_editorial] -- who reviewed it, how, and when.
 */
add_shortcode('merchant_editorial', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_editorial');
    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }

    $editor = vc_section_meta($post_id, 'editor_name');
    $method = vc_section_meta($post_id, 'verification_method');
    if ($editor === '' && $method === '') {
        return '';
    }

    $out = '<section class="vc-editorial">';
    if ($editor !== '') {
        $title = vc_section_meta($post_id, 'editor_title');
        $out .= '<p>' . esc_html__('Reviewed by', 'vc-merchant') . ' ' . esc_html($editor)
            . ($title !== '' ? ', ' . esc_html($title) : '') . '</p>';
    }
    if ($method !== '') {
        $out .= '<p>' . esc_html__('How we checked:', 'vc-merchant') . ' ' . esc_html($method) . '</p>';
    }
    $verified = vc_section_meta($post_id, 'fact_last_verified');
    if ($verified !== '') {
        $out .= '<p>' . esc_html__('Last verified:', 'vc-merchant') . ' ' . esc_html($verified) . '</p>';
    }
    $out .= '</section>';

    return $out;
});

/**
 * [merchant_name] -- the merchant's canonical public name.
 *
 * Its own shortcode because the name is the post title rather than meta, so
 * looking it up by field key would not find it.
 */
add_shortcode('merchant_name', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_name');
    $post_id = vc_section_post_id($atts);

    return $post_id ? esc_html(vc_merchant_display_name($post_id)) : '';
});

/**
 * Field keys that are not post meta, resolved specially.
 */
function vc_section_virtual_fields() {
    return array('brand_name', 'display_brand_name', 'name', 'title');
}

/**
 * [merchant_field key="free_shipping_info"] -- any single field, raw.
 *
 * For dropping one value somewhere specific: a shipping threshold in a
 * sidebar, the last-checked date under the title, and so on.
 */
add_shortcode('merchant_field', function ($atts) {
    $atts = shortcode_atts(array('id' => 0, 'key' => '', 'before' => '', 'after' => ''),
        $atts, 'merchant_field');

    $post_id = vc_section_post_id($atts);
    $key = trim((string) $atts['key']);
    if (!$post_id || $key === '') {
        return '';
    }

    // The name lives in the post title, not meta, so resolve it directly --
    // key="brand_name" is the obvious thing to try and used to silently
    // produce nothing.
    if (in_array($key, vc_section_virtual_fields(), true)) {
        $value = vc_merchant_display_name($post_id);

        return $value === ''
            ? ''
            : esc_html($atts['before']) . esc_html($value) . esc_html($atts['after']);
    }

    // Only fields the importer manages, so this cannot be used to read
    // arbitrary post meta.
    $allowed = function_exists('vc_merchant_import_meta_keys')
        ? vc_merchant_import_meta_keys()
        : array();

    if (!empty($allowed) && !in_array($key, $allowed, true)) {
        // An unrecognised key used to render as nothing at all, which looks
        // identical to an empty field. Tell editors which it is.
        if (current_user_can('edit_posts')) {
            return '<span class="vc-field-error" style="color:#b32d2e;">'
                . esc_html(sprintf(__('[merchant_field] unknown key "%s"', 'vc-merchant'), $key))
                . '</span>';
        }

        return '';
    }

    $value = vc_section_meta($post_id, $key);
    if ($value === '') {
        return '';
    }

    return esc_html($atts['before']) . esc_html($value) . esc_html($atts['after']);
});

/* -------------------------------------------------------------------------
 * Hero and quick facts
 *
 * A wall of text sections reads as nothing in particular. These give the page
 * a focal point: who the store is, what the current deal is, and the one
 * action worth taking -- plus the handful of facts a shopper scans for before
 * reading anything.
 * ---------------------------------------------------------------------- */

/**
 * Store mark: the logo when there is one, otherwise a lettered tile.
 *
 * brand_logo_url is empty across most merchant data, so without a fallback
 * every page would open with a blank space.
 */
function vc_merchant_logo_mark($post_id) {
    $logo = vc_section_meta($post_id, 'brand_logo_url');
    $name = vc_merchant_display_name($post_id);

    if ($logo !== '') {
        return '<div class="vc-hero__logo"><img src="' . esc_url($logo) . '" alt="'
            . esc_attr($name) . '" loading="lazy" /></div>';
    }

    $initial = function_exists('mb_substr') ? mb_substr(trim($name), 0, 1) : substr(trim($name), 0, 1);

    return '<div class="vc-hero__logo vc-hero__logo--letter" aria-hidden="true">'
        . esc_html(mb_strtoupper($initial)) . '</div>';
}

/**
 * [merchant_hero] -- logo, name, offer badge, headline deal, and the CTA.
 */
function vc_merchant_hero($post_id) {
    $name  = vc_merchant_display_name($post_id);
    $offer = vc_section_meta($post_id, 'best_offer_summary');
    $url   = vc_section_meta($post_id, 'brand_url');
    $checked = vc_section_meta($post_id, 'last_checked_text');

    $out  = '<div class="vc-hero">';
    $out .= vc_merchant_logo_mark($post_id);

    $out .= '<div class="vc-hero__body">';
    $out .= '<div class="vc-hero__meta">' . vc_merchant_offer_badge($post_id) . '</div>';

    if ($offer !== '') {
        $out .= '<p class="vc-hero__offer">' . esc_html($offer) . '</p>';
    }

    if ($url !== '') {
        // rel="sponsored nofollow" because an outbound merchant link on a
        // deals page is a commercial link whether or not it is affiliate.
        $out .= '<p class="vc-hero__cta"><a class="vc-btn" href="' . esc_url($url) . '"'
            . ' rel="sponsored nofollow noopener" target="_blank">'
            . esc_html(sprintf(__('Visit %s', 'vc-merchant'), $name)) . '</a>';
        if ($checked !== '') {
            $out .= ' <span class="vc-hero__checked">'
                . esc_html(sprintf(__('Checked %s', 'vc-merchant'), $checked)) . '</span>';
        }
        $out .= '</p>';
    }

    $out .= '</div></div>';

    return $out;
}
add_shortcode('merchant_hero', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_hero');
    $post_id = vc_section_post_id($atts);

    return $post_id ? vc_merchant_hero($post_id) : '';
});

/**
 * [merchant_quickfacts] -- the handful of values a shopper scans for.
 *
 * Only facts actually present are shown; the strip disappears entirely rather
 * than printing empty tiles.
 */
function vc_merchant_quick_facts($post_id) {
    $facts = array();

    $ship = vc_section_meta($post_id, 'free_shipping_info');
    if ($ship !== '' && preg_match('/\$\s?([\d,]+)/', $ship, $m)) {
        $facts[] = array(__('Free shipping over', 'vc-merchant'), '$' . $m[1]);
    } elseif ($ship !== '' && stripos($ship, 'free shipping on all') !== false) {
        $facts[] = array(__('Shipping', 'vc-merchant'), __('Free on all orders', 'vc-merchant'));
    }

    $returns = vc_section_meta($post_id, 'return_policy_summary');
    if ($returns !== '' && preg_match('/\b(\d{1,3})[\s-]*day/i', $returns, $m)) {
        $facts[] = array(__('Returns', 'vc-merchant'), sprintf(__('%s days', 'vc-merchant'), $m[1]));
    }

    $age = vc_section_meta($post_id, 'age_verification_required');
    if ($age !== '' && stripos($age, 'not stated') === false) {
        $facts[] = array(__('Age required', 'vc-merchant'), $age);
    }

    $ships_to = vc_section_meta($post_id, 'ships_to_terms');
    if ($ships_to !== '') {
        $names = array_filter(array_map('trim', explode('|', $ships_to)));
        $states = array_filter($names, function ($n) { return $n !== 'United States'; });
        if (!empty($states)) {
            $facts[] = array(__('Ships to', 'vc-merchant'),
                sprintf(_n('%d state', '%d states', count($states), 'vc-merchant'), count($states)));
        }
    }

    $restricted = vc_section_meta($post_id, 'restricted_states');
    if ($restricted !== '') {
        $list = array_filter(array_map('trim', explode('|', $restricted)));
        if (!empty($list)) {
            $facts[] = array(__('Cannot ship to', 'vc-merchant'), implode(', ', $list));
        }
    }

    if (empty($facts)) {
        return '';
    }

    $out = '<ul class="vc-quickfacts">';
    foreach ($facts as $fact) {
        $out .= '<li class="vc-quickfact"><span class="vc-quickfact__label">'
            . esc_html($fact[0]) . '</span><span class="vc-quickfact__value">'
            . esc_html($fact[1]) . '</span></li>';
    }
    $out .= '</ul>';

    return $out;
}
add_shortcode('merchant_quickfacts', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_quickfacts');
    $post_id = vc_section_post_id($atts);

    return $post_id ? vc_merchant_quick_facts($post_id) : '';
});
