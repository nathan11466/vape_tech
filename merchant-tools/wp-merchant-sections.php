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

    $shortcode = function_exists('vc_merchant_coupon_shortcode')
        ? vc_merchant_coupon_shortcode($post_id)
        : vc_section_meta($post_id, 'coupon_plugin_shortcode');
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
    $atts = shortcode_atts(array('id' => 0, 'title' => ''), $atts, 'merchant_policies');
    $post_id = vc_section_post_id($atts);
    if (!$post_id) {
        return '';
    }

    // One compact label/value grid rather than seven identical stacked cards.
    // These are reference details people scan for, not prose they read in
    // order, so a table beats a stack of boxes.
    $rows = array(
        __('Shipping', 'vc-merchant')        => vc_section_meta($post_id, 'free_shipping_info'),
        __('Returns', 'vc-merchant')         => vc_section_meta($post_id, 'return_policy_summary'),
        __('Payment', 'vc-merchant')         => vc_section_meta($post_id, 'payment_methods'),
        __('Exclusions', 'vc-merchant')      => vc_section_meta($post_id, 'common_exclusions'),
        __('Stacking codes', 'vc-merchant')  => vc_section_meta($post_id, 'stacking_policy'),
        __('Restrictions', 'vc-merchant')    => vc_section_meta($post_id, 'shipping_restrictions'),
        __('If a code fails', 'vc-merchant') => vc_section_meta($post_id, 'why_code_not_work'),
    );

    $items = '';
    foreach ($rows as $label => $value) {
        if (trim((string) $value) === '') {
            continue;
        }
        $items .= '<div class="vc-policy"><dt>' . esc_html($label) . '</dt><dd>'
            . esc_html($value) . '</dd></div>';
    }

    if ($items === '') {
        return '';
    }

    $heading = trim((string) $atts['title']);
    if ($heading === '') {
        $heading = sprintf(__('%s shipping, returns & terms', 'vc-merchant'),
            vc_merchant_display_name($post_id));
    }

    $out = '<section class="vc-policies"><h2>' . esc_html($heading) . '</h2>'
        . '<dl class="vc-policies__grid">' . $items . '</dl>';

    if (function_exists('vc_merchant_restricted_line')) {
        $out .= vc_merchant_restricted_line($post_id);
    }

    return $out . '</section>';
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
    $logo = (string) apply_filters('vc_merchant_logo_url',
        vc_section_meta($post_id, 'brand_logo_url'), $post_id);
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
 * The headline number in an offer, pulled out for the hero.
 *
 * Only ever returns something the offer text already says. A percentage wins
 * over a dollar amount because it is the convention for coupon pages, and the
 * largest value wins when several appear ("up to 50% off" beats "10% off").
 */
function vc_merchant_headline_discount($post_id) {
    $offer = vc_section_meta($post_id, 'best_offer_summary');
    if ($offer === '') {
        return array('', '');
    }

    $percents = array();
    if (preg_match_all('/(\d{1,2})\s?%/', $offer, $m)) {
        $percents = array_map('intval', $m[1]);
    }
    if (!empty($percents)) {
        $best = max($percents);
        $prefix = preg_match('/\bup to\b/i', $offer) ? __('up to', 'vc-merchant') : '';
        return array($best . '%', $prefix);
    }

    $amounts = array();
    if (preg_match_all('/\$\s?([\d,]+(?:\.\d{2})?)/', $offer, $m)) {
        foreach ($m[1] as $raw) {
            $amounts[$raw] = (float) str_replace(',', '', $raw);
        }
    }
    if (!empty($amounts)) {
        arsort($amounts);
        $label = array_key_first($amounts);
        // A threshold ("free shipping over $49") is not a discount.
        if (!preg_match('/\b(over|above|minimum|orders? of)\b[^.]{0,24}\$\s?'
            . preg_quote($label, '/') . '/i', $offer)) {
            $prefix = preg_match('/\bup to\b/i', $offer) ? __('up to', 'vc-merchant') : '';
            return array('$' . $label, $prefix);
        }
    }

    return array('', '');
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

    // The number, pulled out large. Only shown when the offer text contains
    // one -- nothing is invented to fill the space.
    list($figure, $prefix) = vc_merchant_headline_discount($post_id);
    if ($figure !== '') {
        $out .= '<p class="vc-hero__figure">';
        if ($prefix !== '') {
            $out .= '<span class="vc-hero__figure-prefix">' . esc_html($prefix) . '</span>';
        }
        $out .= '<span class="vc-hero__figure-value">' . esc_html($figure) . '</span>';
        $out .= '<span class="vc-hero__figure-suffix">' . esc_html__('off', 'vc-merchant') . '</span>';
        $out .= '</p>';
    }

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
    $ship_conf = vc_section_meta($post_id, 'shipping_confidence');
    if ($ships_to !== '') {
        $names = array_filter(array_map('trim', explode('|', $ships_to)));
        $states = array_filter($names, function ($n) { return $n !== 'United States'; });
        if (!empty($states)) {
            if ($ship_conf === 'assumed') {
                // The merchant published no restrictions, so coverage is our
                // working assumption rather than their claim. Saying "51
                // states" here would put a number in their mouth.
                $facts[] = array(__('Ships to', 'vc-merchant'),
                    __('US nationwide*', 'vc-merchant'));
            } else {
                $facts[] = array(__('Ships to', 'vc-merchant'),
                    sprintf(_n('%d state', '%d states', count($states), 'vc-merchant'),
                        count($states)));
            }
        }
    }

    $id_delivery = vc_section_meta($post_id, 'do_they_id_on_delivery');
    if ($id_delivery !== '' && stripos($id_delivery, 'not stated') === false
        && stripos($id_delivery, 'unknown') === false) {
        $facts[] = array(__('ID on delivery', 'vc-merchant'), $id_delivery);
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

/* -------------------------------------------------------------------------
 * Merchant links: social profiles, policy pages, anything else useful
 * ---------------------------------------------------------------------- */

/**
 * Social platforms recognised from a URL's host.
 */
function vc_social_platforms() {
    return array(
        'instagram.com' => 'Instagram',
        'facebook.com'  => 'Facebook',
        'fb.com'        => 'Facebook',
        'x.com'         => 'X',
        'twitter.com'   => 'X',
        'youtube.com'   => 'YouTube',
        'youtu.be'      => 'YouTube',
        'tiktok.com'    => 'TikTok',
        'reddit.com'    => 'Reddit',
        'pinterest.com' => 'Pinterest',
        'linkedin.com'  => 'LinkedIn',
        'discord.gg'    => 'Discord',
        'discord.com'   => 'Discord',
        't.me'          => 'Telegram',
        'threads.net'   => 'Threads',
    );
}

/**
 * Label a social URL by its host, falling back to the bare domain so an
 * unrecognised network still renders something meaningful.
 */
function vc_social_label($url) {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        return '';
    }
    $host = preg_replace('/^www\./', '', $host);

    foreach (vc_social_platforms() as $domain => $label) {
        if ($host === $domain || substr($host, -strlen('.' . $domain)) === '.' . $domain) {
            return $label;
        }
    }

    return $host;
}

/**
 * [merchant_links] -- social profiles, policy pages and any extra links.
 *
 * Policy links are named columns because they are predictable; everything else
 * comes from useful_links as "Label :: URL" pairs, so the sheet does not need
 * a column per link.
 */
function vc_merchant_links($post_id) {
    $out = '';

    // --- Policy and support pages ---
    $pages = array(
        __('Shipping policy', 'vc-merchant') => vc_section_meta($post_id, 'shipping_policy_url'),
        __('Returns policy', 'vc-merchant')  => vc_section_meta($post_id, 'returns_policy_url'),
        __('Age policy', 'vc-merchant')      => vc_section_meta($post_id, 'age_policy_url'),
        __('Contact', 'vc-merchant')         => vc_section_meta($post_id, 'contact_page_url'),
    );

    // --- Anything else, as "Label :: URL" pairs ---
    foreach (array_filter(array_map('trim', explode('|', vc_section_meta($post_id, 'useful_links')))) as $pair) {
        if (strpos($pair, '::') === false) {
            // Bare URL with no label: use its path as the label.
            $label = trim((string) parse_url($pair, PHP_URL_PATH), '/');
            $pages[$label !== '' ? ucfirst(str_replace(array('-', '_'), ' ', $label)) : $pair] = $pair;
            continue;
        }
        list($label, $url) = array_map('trim', explode('::', $pair, 2));
        if ($label !== '' && $url !== '') {
            $pages[$label] = $url;
        }
    }

    $items = '';
    foreach ($pages as $label => $url) {
        if (trim((string) $url) === '') {
            continue;
        }
        $items .= '<li><a href="' . esc_url($url) . '" rel="nofollow noopener" target="_blank">'
            . esc_html($label) . '</a></li>';
    }

    if ($items !== '') {
        $out .= '<div class="vc-links__group"><h3>' . esc_html__('Store pages', 'vc-merchant')
            . '</h3><ul class="vc-links__list">' . $items . '</ul></div>';
    }

    // --- Social profiles ---
    $social = '';
    foreach (array_filter(array_map('trim', explode('|', vc_section_meta($post_id, 'social_links')))) as $url) {
        $label = vc_social_label($url);
        if ($label === '') {
            continue;
        }
        $social .= '<li><a href="' . esc_url($url) . '" rel="nofollow noopener" target="_blank">'
            . esc_html($label) . '</a></li>';
    }

    if ($social !== '') {
        $out .= '<div class="vc-links__group"><h3>' . esc_html__('Social', 'vc-merchant')
            . '</h3><ul class="vc-links__list vc-links__list--social">' . $social . '</ul></div>';
    }

    if ($out === '') {
        return '';
    }

    return '<section class="vc-links"><h2>'
        . esc_html(sprintf(__('%s links', 'vc-merchant'), vc_merchant_display_name($post_id)))
        . '</h2><div class="vc-links__groups">' . $out . '</div></section>';
}
add_shortcode('merchant_links', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_links');
    $post_id = vc_section_post_id($atts);

    return $post_id ? vc_merchant_links($post_id) : '';
});

/* -------------------------------------------------------------------------
 * Where a store ships, from the taxonomy
 *
 * The quick-facts strip summarises shipping from the ships_to_terms meta
 * string ("US nationwide", "48 states"). That is a count, not the
 * destinations, and it never linked anywhere -- so the assigned terms were
 * invisible on the page and the destination pages got no links from the
 * stores that serve them.
 *
 * This renders the terms themselves. It deliberately does NOT list every
 * state for a nationwide store: 112 stores times 51 states is 5,600 links,
 * which dilutes the ones that mean something. A nationwide store links to the
 * country page and names its exclusions, because the exclusions are the part
 * a reader actually needs.
 * ---------------------------------------------------------------------- */

/**
 * At or above this many US child terms, a store counts as nationwide.
 */
function vc_destinations_nationwide_threshold() {
    return (int) apply_filters('vc_destinations_nationwide_threshold', 45);
}

/**
 * Linked term name, or the plain name when the term has no usable link.
 */
function vc_destination_link($term) {
    $link = get_term_link($term);
    if (is_wp_error($link)) {
        return esc_html($term->name);
    }

    return '<a href="' . esc_url($link) . '">' . esc_html($term->name) . '</a>';
}

/**
 * The destinations block: where this store ships, and where it does not.
 */
function vc_merchant_destinations($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    if (!taxonomy_exists('ships_to')) {
        return '';
    }

    $terms = get_the_terms($post_id, 'ships_to');
    if (!$terms || is_wp_error($terms)) {
        return '';
    }

    // Split the country roll-ups from the places inside them.
    $parents = array();
    $children = array();
    foreach ($terms as $term) {
        if ((int) $term->parent === 0) {
            $parents[$term->term_id] = $term;
        } else {
            $children[(int) $term->parent][] = $term;
        }
    }

    $rows = '';

    foreach ($parents as $parent_id => $parent) {
        $group = $children[$parent_id] ?? array();

        if (count($group) >= vc_destinations_nationwide_threshold()) {
            // Nationwide: one link to the country, not fifty-one to states.
            $rows .= '<li class="vc-destinations__item">'
                . vc_destination_link($parent) . ' &mdash; '
                . esc_html(sprintf(
                    /* translators: %d: number of destinations */
                    _n('%d destination', 'all %d destinations', count($group), 'vc-merchant'),
                    count($group)))
                . '</li>';
            continue;
        }

        if (empty($group)) {
            $rows .= '<li class="vc-destinations__item">' . vc_destination_link($parent) . '</li>';
            continue;
        }

        // A specific subset is worth listing in full: it is genuinely
        // distinguishing information, and there are few enough links for each
        // to carry weight.
        $links = array();
        foreach ($group as $child) {
            $links[] = vc_destination_link($child);
        }
        $rows .= '<li class="vc-destinations__item"><strong>'
            . esc_html($parent->name) . ':</strong> ' . implode(', ', $links) . '</li>';
    }

    if ($rows === '') {
        return '';
    }

    $out = '<section class="vc-section vc-destinations">';
    $out .= '<h2>' . esc_html__('Where this store ships', 'vc-merchant') . '</h2>';
    $out .= '<ul class="vc-destinations__list">' . $rows . '</ul>';

    // Exclusions, which are the part a reader is actually checking for.
    $restricted = array_filter(array_map('trim',
        explode('|', (string) vc_section_meta($post_id, 'restricted_states'))));
    if (!empty($restricted)) {
        $linked = array();
        foreach ($restricted as $name) {
            $term = get_term_by('name', $name, 'ships_to');
            $linked[] = ($term && !is_wp_error($term))
                ? vc_destination_link($term) : esc_html($name);
        }
        $out .= '<p class="vc-destinations__except"><strong>'
            . esc_html__('Cannot ship to:', 'vc-merchant') . '</strong> '
            . implode(', ', $linked) . '</p>';
    }

    // Where coverage is our working assumption rather than the merchant's
    // claim, say so here too rather than only in the quick facts.
    if (vc_section_meta($post_id, 'shipping_confidence') === 'assumed') {
        $out .= '<p class="vc-destinations__note">'
            . esc_html__('* This store publishes no delivery restrictions, so nationwide coverage is our assumption rather than a claim they make. Check at checkout.', 'vc-merchant')
            . '</p>';
    }

    $out .= '</section>';

    return $out;
}
add_shortcode('merchant_destinations', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_destinations');

    return vc_merchant_destinations(vc_section_post_id($atts));
});

/**
 * Service locations and product categories, as assigned terms.
 */
function vc_merchant_taxonomy_tags($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $out = '';

    $sets = array(
        'service_location'  => __('Serves', 'vc-merchant'),
        'merchant_category' => __('Sells', 'vc-merchant'),
    );

    foreach ($sets as $taxonomy => $label) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }
        $terms = get_the_terms($post_id, $taxonomy);
        if (!$terms || is_wp_error($terms)) {
            continue;
        }
        $links = array();
        foreach ($terms as $term) {
            $links[] = vc_destination_link($term);
        }
        $out .= '<p class="vc-tags__row"><strong>' . esc_html($label) . ':</strong> '
            . implode(', ', $links) . '</p>';
    }

    return $out === '' ? '' : '<section class="vc-section vc-tags">' . $out . '</section>';
}
add_shortcode('merchant_tags', function ($atts) {
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_tags');

    return vc_merchant_taxonomy_tags(vc_section_post_id($atts));
});
