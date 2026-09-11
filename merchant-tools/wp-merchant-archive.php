<?php
/**
 * Destination archive pages -- "online vape shops that ship to X".
 *
 * These are landing pages in their own right, not a byproduct of the taxonomy.
 * A default WordPress archive (bare title + excerpt list) will not rank for a
 * commercial query like that, so this module gives each destination term a
 * query-aligned title, a real intro, a useful merchant listing, ItemList
 * schema, and links out to neighbouring destinations.
 *
 * Per-term editorial copy lives in term meta and is editable in wp-admin, so
 * state-specific legal detail is written by a human rather than generated.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taxonomies this module dresses up.
 */
function vc_archive_taxonomies() {
    return apply_filters('vc_archive_taxonomies', array('ships_to', 'merchant_category'));
}

function vc_is_merchant_archive() {
    foreach (vc_archive_taxonomies() as $tax) {
        if (is_tax($tax)) {
            return true;
        }
    }
    return false;
}

/**
 * How many merchants are in this term.
 */
function vc_archive_merchant_count($term = null) {
    $term = $term ?: get_queried_object();
    if (!$term || is_wp_error($term) || !isset($term->count)) {
        return 0;
    }
    return (int) $term->count;
}

/* -------------------------------------------------------------------------
 * Per-term editorial fields
 * ---------------------------------------------------------------------- */

function vc_archive_term_fields() {
    return array(
        'vc_intro'        => __('Intro paragraph', 'vc-merchant'),
        'vc_legal_note'   => __('Local rules / legal note', 'vc-merchant'),
        'vc_faq_q1'       => __('FAQ 1 question', 'vc-merchant'),
        'vc_faq_a1'       => __('FAQ 1 answer', 'vc-merchant'),
        'vc_faq_q2'       => __('FAQ 2 question', 'vc-merchant'),
        'vc_faq_a2'       => __('FAQ 2 answer', 'vc-merchant'),
        'vc_title_override' => __('SEO title override', 'vc-merchant'),
    );
}

/**
 * Render the editorial fields on the term edit screen.
 */
function vc_archive_term_edit_fields($term) {
    foreach (vc_archive_term_fields() as $key => $label) {
        $value = get_term_meta($term->term_id, $key, true);
        $multiline = in_array($key, array('vc_intro', 'vc_legal_note', 'vc_faq_a1', 'vc_faq_a2'), true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <?php if ($multiline) : ?>
                    <textarea name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>"
                              rows="4" class="large-text"><?php echo esc_textarea($value); ?></textarea>
                <?php else : ?>
                    <input type="text" name="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($key); ?>"
                           value="<?php echo esc_attr($value); ?>" class="large-text" />
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

function vc_archive_save_term_fields($term_id) {
    if (!current_user_can('manage_categories')) {
        return;
    }
    foreach (array_keys(vc_archive_term_fields()) as $key) {
        if (isset($_POST[$key])) {
            update_term_meta($term_id, $key, wp_kses_post(wp_unslash($_POST[$key])));
        }
    }
}

add_action('init', function () {
    foreach (vc_archive_taxonomies() as $tax) {
        add_action("{$tax}_edit_form_fields", 'vc_archive_term_edit_fields');
        add_action("edited_{$tax}", 'vc_archive_save_term_fields');
        add_action("created_{$tax}", 'vc_archive_save_term_fields');
    }
}, 20);

/* -------------------------------------------------------------------------
 * Titles and descriptions
 * ---------------------------------------------------------------------- */

/**
 * The query people actually type.
 *
 * Filterable, because the right phrasing depends on the niche -- change it
 * once here rather than editing 50 terms.
 */
function vc_archive_title_template($term) {
    $template = ($term && $term->taxonomy === 'ships_to')
        ? __('Online Vape Shops That Ship to %s', 'vc-merchant')
        : __('%s Vape Deals & Coupons', 'vc-merchant');

    return apply_filters('vc_archive_title_template', $template, $term);
}

function vc_archive_title($term = null) {
    $term = $term ?: get_queried_object();
    if (!$term || is_wp_error($term)) {
        return '';
    }

    $override = trim((string) get_term_meta($term->term_id, 'vc_title_override', true));
    if ($override !== '') {
        return $override;
    }

    return sprintf(vc_archive_title_template($term), $term->name);
}

/**
 * Title with a freshness stamp, for the <title> tag only. The on-page H1 keeps
 * the clean form.
 */
function vc_archive_seo_title($term = null) {
    $term = $term ?: get_queried_object();
    $base = vc_archive_title($term);
    if ($base === '') {
        return '';
    }

    $stamp = function_exists('vc_merchant_freshness_stamp')
        ? vc_merchant_freshness_stamp()
        : date_i18n('F Y');

    return sprintf('%s (%s)', $base, $stamp);
}

function vc_archive_meta_description($term = null) {
    $term = $term ?: get_queried_object();
    if (!$term || is_wp_error($term)) {
        return '';
    }

    $count = vc_archive_merchant_count($term);
    $stamp = function_exists('vc_merchant_freshness_stamp')
        ? vc_merchant_freshness_stamp()
        : date_i18n('F Y');

    if ($term->taxonomy === 'ships_to') {
        return sprintf(
            /* translators: 1: count, 2: destination, 3: month year */
            _n(
                '%1$d online vape shop that ships to %2$s, with current deals and shipping details. Updated %3$s.',
                '%1$d online vape shops that ship to %2$s, compared on deals, shipping and returns. Updated %3$s.',
                max(1, $count),
                'vc-merchant'
            ),
            $count, $term->name, $stamp
        );
    }

    return sprintf(
        __('%1$d %2$s retailers with current deals and coupon codes. Updated %3$s.', 'vc-merchant'),
        $count, $term->name, $stamp
    );
}

// Replace the theme's archive title ("Ships To: California") with the query form.
add_filter('get_the_archive_title', function ($title) {
    if (!vc_is_merchant_archive()) {
        return $title;
    }
    $custom = vc_archive_title();

    return $custom !== '' ? $custom : $title;
});

// Feed Rank Math.
add_filter('rank_math/frontend/title', function ($title) {
    if (!vc_is_merchant_archive()) {
        return $title;
    }
    $custom = vc_archive_seo_title();

    return $custom !== '' ? $custom : $title;
});

add_filter('rank_math/frontend/description', function ($description) {
    if (!vc_is_merchant_archive()) {
        return $description;
    }
    $custom = vc_archive_meta_description();

    return $custom !== '' ? $custom : $description;
});

/* -------------------------------------------------------------------------
 * Archive intro content
 * ---------------------------------------------------------------------- */

/**
 * Build the intro shown above the merchant list.
 *
 * The generated part states only what the data supports -- how many merchants,
 * and how many were confirmed versus inferred. Anything about local law comes
 * from the term's own editorial field, written by a human.
 */
function vc_archive_intro($term = null) {
    $term = $term ?: get_queried_object();
    if (!$term || is_wp_error($term)) {
        return '';
    }

    $count = vc_archive_merchant_count($term);
    $intro = trim((string) get_term_meta($term->term_id, 'vc_intro', true));
    $legal = trim((string) get_term_meta($term->term_id, 'vc_legal_note', true));

    $out = '<div class="vc-archive-intro">';

    if ($intro !== '') {
        $out .= wpautop(wp_kses_post($intro));
    } elseif ($term->taxonomy === 'ships_to') {
        // Data-only fallback. Deliberately makes no claim about local law.
        $out .= '<p>' . esc_html(sprintf(
            _n(
                'We track %1$d online vape shop confirmed to ship to %2$s. Each listing below shows its current deal, shipping threshold and any product restrictions we could verify.',
                'We track %1$d online vape shops confirmed to ship to %2$s. Each listing below shows its current deal, shipping threshold and any product restrictions we could verify.',
                max(1, $count),
                'vc-merchant'
            ),
            $count, $term->name
        )) . '</p>';
    }

    if ($legal !== '') {
        $out .= '<div class="vc-archive-legal">' . wpautop(wp_kses_post($legal)) . '</div>';
    }

    $out .= '</div>';

    return $out;
}

/**
 * FAQs for the archive, from term meta.
 */
function vc_archive_faqs($term = null) {
    $term = $term ?: get_queried_object();
    if (!$term || is_wp_error($term)) {
        return array();
    }

    $faqs = array();
    for ($i = 1; $i <= 2; $i++) {
        $q = trim((string) get_term_meta($term->term_id, "vc_faq_q{$i}", true));
        $a = trim((string) get_term_meta($term->term_id, "vc_faq_a{$i}", true));
        if ($q !== '' && $a !== '') {
            $faqs[] = array($q, $a);
        }
    }

    return $faqs;
}

function vc_archive_faq_html($term = null) {
    $faqs = vc_archive_faqs($term);
    if (empty($faqs)) {
        return '';
    }

    $out = '<section class="vc-archive-faqs"><h2>' . esc_html__('Frequently asked questions', 'vc-merchant') . '</h2>';
    foreach ($faqs as $faq) {
        $out .= '<h3>' . esc_html($faq[0]) . '</h3>' . wpautop(esc_html($faq[1]));
    }
    $out .= '</section>';

    return $out;
}

/**
 * Sibling destinations, for internal linking.
 *
 * Links to other states under the same country (or other top-level
 * destinations), so the network is crawlable and link equity moves between the
 * 50 state pages instead of each being an island.
 */
function vc_archive_sibling_links($term = null, $limit = 12) {
    $term = $term ?: get_queried_object();
    if (!$term || is_wp_error($term)) {
        return '';
    }

    $siblings = get_terms(array(
        'taxonomy'   => $term->taxonomy,
        'parent'     => $term->parent,
        'hide_empty' => true,
        'exclude'    => array($term->term_id),
        'number'     => $limit,
        'orderby'    => 'count',
        'order'      => 'DESC',
    ));

    if (is_wp_error($siblings) || empty($siblings)) {
        return '';
    }

    $heading = ($term->taxonomy === 'ships_to')
        ? __('Shops shipping to other destinations', 'vc-merchant')
        : __('Other categories', 'vc-merchant');

    $out = '<nav class="vc-archive-siblings"><h2>' . esc_html($heading) . '</h2><ul>';
    foreach ($siblings as $sibling) {
        $out .= '<li><a href="' . esc_url(get_term_link($sibling)) . '">'
            . esc_html(sprintf(vc_archive_title_template($sibling), $sibling->name))
            . '</a> <span class="vc-count">(' . (int) $sibling->count . ')</span></li>';
    }
    $out .= '</ul></nav>';

    return $out;
}

/**
 * Inject intro, FAQs and sibling links into the archive description, which is
 * where themes already print term copy -- no template file required.
 */
add_filter('get_the_archive_description', function ($description) {
    if (!vc_is_merchant_archive()) {
        return $description;
    }

    return vc_archive_intro() . $description;
});

/**
 * Append FAQs and internal links after the loop.
 */
add_action('loop_end', function ($query) {
    if (!is_main_query() || !vc_is_merchant_archive()) {
        return;
    }
    echo vc_archive_faq_html();
    echo vc_archive_sibling_links();
});

/* -------------------------------------------------------------------------
 * Listing excerpts
 * ---------------------------------------------------------------------- */

/**
 * Merchant posts hold only a shortcode as content, so a theme excerpt would
 * print "[merchant_page]" or the whole rendered page. Give the listing a
 * useful summary instead: the current offer plus the shipping threshold.
 */
add_filter('get_the_excerpt', function ($excerpt, $post = null) {
    if (!$post || !in_array(get_post_type($post), vc_merchant_post_types(), true)) {
        return $excerpt;
    }
    if (is_singular()) {
        return $excerpt;
    }

    $post_id = $post->ID;
    $offer = trim((string) get_post_meta($post_id, 'best_offer_summary', true));
    $ship  = trim((string) get_post_meta($post_id, 'free_shipping_info', true));

    $parts = array_filter(array($offer, $ship));
    if (empty($parts)) {
        return $excerpt;
    }

    return esc_html(implode(' - ', $parts));
}, 10, 2);

/* -------------------------------------------------------------------------
 * Archive schema
 * ---------------------------------------------------------------------- */

/**
 * ItemList describing the merchants on this archive, plus FAQPage when the
 * term has real Q&As. Helps the page be understood as a curated list rather
 * than an incidental tag archive.
 */
function vc_archive_schema_graph() {
    $term = get_queried_object();
    if (!$term || is_wp_error($term)) {
        return array();
    }

    global $wp_query;
    $items = array();
    $position = 1;

    if (!empty($wp_query->posts)) {
        foreach ($wp_query->posts as $post) {
            $items[] = array(
                '@type'    => 'ListItem',
                'position' => $position++,
                'url'      => get_permalink($post->ID),
                'name'     => function_exists('vc_merchant_display_name')
                    ? vc_merchant_display_name($post->ID)
                    : get_the_title($post->ID),
            );
        }
    }

    $graph = array();

    if (!empty($items)) {
        $graph[] = array(
            '@type'           => 'ItemList',
            'name'            => vc_archive_title($term),
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
        );
    }

    $faqs = vc_archive_faqs($term);
    if (!empty($faqs)) {
        $entities = array();
        foreach ($faqs as $faq) {
            $entities[] = array(
                '@type' => 'Question',
                'name'  => $faq[0],
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $faq[1]),
            );
        }
        $graph[] = array('@type' => 'FAQPage', 'mainEntity' => $entities);
    }

    return $graph;
}

add_filter('rank_math/json_ld', function ($data, $jsonld) {
    if (!vc_is_merchant_archive()) {
        return $data;
    }
    foreach (vc_archive_schema_graph() as $i => $node) {
        $data['vc_archive_' . $i] = $node;
    }

    return $data;
}, 10, 2);

add_action('wp_head', function () {
    if (class_exists('RankMath') || !vc_is_merchant_archive()) {
        return;
    }
    $graph = vc_archive_schema_graph();
    if (empty($graph)) {
        return;
    }
    echo "\n<script type=\"application/ld+json\">"
        . wp_json_encode(array('@context' => 'https://schema.org', '@graph' => $graph),
                         JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}, 20);
