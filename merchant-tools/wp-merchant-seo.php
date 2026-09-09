<?php
/**
 * Merchant page SEO: titles, meta descriptions, and JSON-LD schema.
 *
 * Loaded by wp-merchant-fields.php. Feeds Rank Math through its own filters
 * rather than printing competing tags, so Rank Math stays the single source of
 * truth for the <head> and there is no duplicate-meta problem.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Current freshness stamp, e.g. "September 2026".
 *
 * Generated at render time rather than stored, so titles never go stale and
 * you are not re-importing 112 rows every month just to bump a date.
 */
function vc_merchant_freshness_stamp() {
    return date_i18n('F Y');
}

/**
 * SEO title. Query-aligned for coupon intent, with a freshness marker.
 *
 * Kept under ~60 characters where the brand name allows, so it is not
 * truncated in the SERP.
 */
function vc_merchant_seo_title($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $name = vc_merchant_display_name($post_id);

    return sprintf(
        /* translators: 1: brand name, 2: month and year */
        __('%1$s Coupon Codes & Promo Codes - %2$s', 'vc-merchant'),
        $name,
        vc_merchant_freshness_stamp()
    );
}

/**
 * Meta description, built from what has actually been confirmed.
 *
 * Mirrors the offer gate: a page with no confirmed code does not promise one
 * in the SERP snippet either, because a description that oversells produces
 * bounce-backs and hurts the page.
 */
function vc_merchant_meta_description($post_id = null) {
    $post_id = $post_id ?: get_the_ID();
    $name  = vc_merchant_display_name($post_id);
    $mode  = trim((string) get_post_meta($post_id, 'offer_display_mode', true));
    $offer = trim((string) get_post_meta($post_id, 'best_offer_summary', true));
    $stamp = vc_merchant_freshness_stamp();

    if ($mode === 'verified_code' && $offer !== '') {
        $text = sprintf(__('Verified %1$s coupon codes for %2$s. %3$s', 'vc-merchant'), $name, $stamp, $offer);
    } elseif ($mode === 'best_deal' && $offer !== '') {
        $text = sprintf(__('Current %1$s deals for %2$s. %3$s', 'vc-merchant'), $name, $stamp, $offer);
    } else {
        $text = sprintf(
            __('%1$s deals and savings tips for %2$s, plus shipping, returns and exclusions.', 'vc-merchant'),
            $name,
            $stamp
        );
    }

    // Trim on a word boundary to keep it under the ~155 char snippet limit.
    if (function_exists('mb_strlen') && mb_strlen($text) > 155) {
        $text = rtrim(mb_substr($text, 0, 152));
        $cut = mb_strrpos($text, ' ');
        if ($cut !== false) {
            $text = mb_substr($text, 0, $cut);
        }
        $text .= '...';
    }

    return $text;
}

/**
 * Focus keyword for Rank Math.
 */
function vc_merchant_focus_keyword($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    return strtolower(vc_merchant_display_name($post_id) . ' coupon code');
}

/* -------------------------------------------------------------------------
 * Rank Math integration
 *
 * Filters rather than printed tags, so Rank Math owns the head and any manual
 * override an editor sets in the Rank Math box still wins.
 * ---------------------------------------------------------------------- */

function vc_merchant_is_merchant_post($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    return in_array(get_post_type($post_id), vc_merchant_post_types(), true);
}

add_filter('rank_math/frontend/title', function ($title) {
    if (!is_singular() || !vc_merchant_is_merchant_post()) {
        return $title;
    }
    // Respect a manually set title.
    $manual = get_post_meta(get_the_ID(), 'rank_math_title', true);
    if (trim((string) $manual) !== '') {
        return $title;
    }

    return vc_merchant_seo_title();
});

add_filter('rank_math/frontend/description', function ($description) {
    if (!is_singular() || !vc_merchant_is_merchant_post()) {
        return $description;
    }
    $manual = get_post_meta(get_the_ID(), 'rank_math_description', true);
    if (trim((string) $manual) !== '') {
        return $description;
    }

    return vc_merchant_meta_description();
});

/**
 * Indexing is left to WordPress and Rank Math.
 *
 * Drafts are not public and are not indexed; a published post is one an editor
 * chose to publish. To noindex a specific merchant, use the Rank Math meta box
 * on that post.
 */

/* -------------------------------------------------------------------------
 * JSON-LD schema
 * ---------------------------------------------------------------------- */

/**
 * Build the schema graph for a merchant page.
 *
 * Emits Organization (the merchant, with aliases and service area), Offer when
 * something concrete is confirmed, FAQPage when real Q&As exist, and
 * BreadcrumbList. Nothing is asserted that the data does not support.
 */
function vc_merchant_schema_graph($post_id = null) {
    $post_id = $post_id ?: get_the_ID();

    $name = vc_merchant_display_name($post_id);
    $url  = trim((string) get_post_meta($post_id, 'brand_url', true));
    $logo = trim((string) get_post_meta($post_id, 'brand_logo_url', true));
    $permalink = get_permalink($post_id);

    $graph = array();

    // --- Organization: the merchant itself ---
    $org = array(
        '@type' => 'Organization',
        '@id'   => $permalink . '#merchant',
        'name'  => $name,
    );
    if ($url !== '') {
        $org['url'] = $url;
    }
    if ($logo !== '') {
        $org['logo'] = $logo;
    }

    $aliases = array_filter(array_map('trim',
        explode('|', (string) get_post_meta($post_id, 'alternative_brand_names', true))));
    if (!empty($aliases)) {
        $org['alternateName'] = array_values($aliases);
    }

    $ships = array_filter(array_map('trim',
        explode('|', (string) get_post_meta($post_id, 'ships_to_countries', true))));
    if (!empty($ships)) {
        $org['areaServed'] = array_map(function ($place) {
            return array('@type' => 'Country', 'name' => $place);
        }, array_values($ships));
    }

    $contactUrl = trim((string) get_post_meta($post_id, 'contact_page_url', true));
    $contactEmail = trim((string) get_post_meta($post_id, 'contact_email', true));
    if ($contactEmail !== '' && is_email($contactEmail)) {
        $org['contactPoint'] = array(
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'email' => $contactEmail,
        );
    } elseif ($contactUrl !== '') {
        $org['contactPoint'] = array(
            '@type' => 'ContactPoint',
            'contactType' => 'customer support',
            'url' => $contactUrl,
        );
    }

    $graph[] = $org;

    // --- Offer: only when something concrete is confirmed ---
    $mode = trim((string) get_post_meta($post_id, 'offer_display_mode', true));
    $offerText = trim((string) get_post_meta($post_id, 'best_offer_summary', true));
    if (($mode === 'verified_code' || $mode === 'best_deal') && $offerText !== '') {
        $offer = array(
            '@type' => 'Offer',
            '@id'   => $permalink . '#offer',
            'name'  => $offerText,
            'offeredBy' => array('@id' => $permalink . '#merchant'),
            'availability' => 'https://schema.org/InStock',
        );
        if ($url !== '') {
            $offer['url'] = $url;
        }
        $graph[] = $offer;
    }

    // --- FAQPage: only from real question/answer pairs ---
    $faqs = array();
    for ($i = 1; $i <= 3; $i++) {
        $q = trim((string) get_post_meta($post_id, "faq_{$i}_question", true));
        $a = trim((string) get_post_meta($post_id, "faq_{$i}_answer", true));
        if ($q !== '' && $a !== '') {
            $faqs[] = array(
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => array('@type' => 'Answer', 'text' => $a),
            );
        }
    }
    if (!empty($faqs)) {
        $graph[] = array(
            '@type' => 'FAQPage',
            '@id'   => $permalink . '#faq',
            'mainEntity' => $faqs,
        );
    }

    // --- WebPage with freshness ---
    $verified = trim((string) get_post_meta($post_id, 'fact_last_verified', true));
    $page = array(
        '@type' => 'WebPage',
        '@id'   => $permalink . '#webpage',
        'url'   => $permalink,
        'name'  => vc_merchant_seo_title($post_id),
        'description' => vc_merchant_meta_description($post_id),
        'about' => array('@id' => $permalink . '#merchant'),
    );
    if ($verified !== '') {
        $page['dateModified'] = $verified;
    }
    $graph[] = $page;

    return $graph;
}

/**
 * Merge our nodes into Rank Math's own schema graph so there is one JSON-LD
 * block on the page rather than two competing ones.
 */
add_filter('rank_math/json_ld', function ($data, $jsonld) {
    if (!is_singular() || !vc_merchant_is_merchant_post()) {
        return $data;
    }

    foreach (vc_merchant_schema_graph() as $index => $node) {
        $key = 'vc_merchant_' . $index;
        $data[$key] = $node;
    }

    return $data;
}, 10, 2);

/**
 * Standalone JSON-LD output, for sites not running Rank Math.
 *
 * Only fires when Rank Math is absent, so the two never double up.
 */
add_action('wp_head', function () {
    if (class_exists('RankMath')) {
        return;
    }
    if (!is_singular() || !vc_merchant_is_merchant_post()) {
        return;
    }

    $graph = vc_merchant_schema_graph();
    if (empty($graph)) {
        return;
    }

    $payload = array('@context' => 'https://schema.org', '@graph' => $graph);
    echo "\n<script type=\"application/ld+json\">"
        . wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        . "</script>\n";
}, 20);
