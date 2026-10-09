<?php
/**
 * The [merchant_page] shortcode.
 *
 * Imported posts store data in meta and carry only this shortcode as their
 * content, so the page is rendered live from the meta on every view. Changing
 * the layout or the gating rules takes effect immediately across every
 * merchant without re-importing anything.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render one content section, or nothing when the field is empty.
 */
function vc_merchant_section($heading, $value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    return '<section class="vc-section"><h2>' . esc_html($heading) . '</h2>'
        . wpautop(esc_html($value)) . '</section>';
}

function vc_merchant_render_page($atts = array()) {
    $atts = shortcode_atts(array('id' => 0, 'related' => 'yes', 'sidebar' => 'yes'), $atts, 'merchant_page');
    $post_id = (int) $atts['id'] ?: get_the_ID();

    if (!$post_id) {
        return '';
    }

    // Review notes, for editors only.
    $notice = '';
    if (current_user_can('edit_posts')) {
        $notes = trim((string) get_post_meta($post_id, 'review_notes', true));
        if ($notes !== '') {
            $notice = '<div class="vc-editor-notice"><strong>'
                . esc_html__('Review notes (visible to editors only):', 'vc-merchant')
                . '</strong> ' . esc_html($notes) . '</div>';
        }
    }

    $main = '';
    $side = '';
    foreach (array('main' => &$main, 'side' => &$side) as $column => &$buffer) {
        foreach (vc_layout_blocks($column) as $block) {
            $buffer .= vc_merchant_block($block, $post_id, $atts);
        }
    }
    unset($buffer);

    $has_sidebar = ($atts['sidebar'] !== 'no') && trim($side) !== '';

    $out = '<div class="vc-merchant-page' . ($has_sidebar ? ' vc-merchant-page--split' : '') . '">';
    $out .= $notice;

    if ($has_sidebar) {
        $out .= '<div class="vc-col-main">' . $main . '</div>';
        $out .= '<aside class="vc-col-side vc-sidebar">' . $side . '</aside>';
    } else {
        $out .= $main . $side;
    }

    return $out . '</div>';
}

/**
 * Render one named block. Order and column come from the layout settings.
 */
function vc_merchant_block($block, $post_id, $atts = array()) {
    $m = function ($key) use ($post_id) {
        return trim((string) get_post_meta($post_id, $key, true));
    };
    $name = vc_merchant_display_name($post_id);

    switch ($block) {
        case 'title':
            // The H1. Uses display_brand_name, which is the canonical public
            // name -- the post title may be the raw CSV brand_name.
            $suffix = '';
            if (function_exists('vc_layout_get')) {
                $suffix = trim((string) (vc_layout_get()['visual']['h1_suffix'] ?? ''));
            }
            $heading = $suffix === '' ? $name : $name . ' ' . $suffix;

            return '<h1 class="vc-page-title">' . esc_html($heading) . '</h1>';

        case 'internal':
            // Links to our own pages. Deliberately NOT nofollow -- these are
            // internal links and should pass equity, unlike the merchant's
            // outbound links.
            $links = array(
                sprintf(__('Read our %s review', 'vc-merchant'), $name) => $m('brand_review_url'),
                __('All deals', 'vc-merchant')      => $m('deals_hub_url'),
                __('Seasonal sales', 'vc-merchant') => $m('seasonal_deals_url'),
            );
            $items = '';
            foreach ($links as $label => $href) {
                if (trim((string) $href) !== '') {
                    $items .= '<li><a href="' . esc_url($href) . '">' . esc_html($label) . '</a></li>';
                }
            }

            return $items === '' ? '' : '<nav class="vc-related"><ul>' . $items . '</ul></nav>';

        case 'hero':
            return function_exists('vc_merchant_hero')
                ? vc_merchant_hero($post_id)
                : '<div class="vc-offer-status">' . vc_merchant_offer_badge($post_id) . '</div>';

        case 'coupon':
            if (function_exists('vc_merchant_coupon_section')) {
                return vc_merchant_coupon_section($post_id);
            }
            $shortcode = $m('coupon_plugin_shortcode');
            return $shortcode === ''
                ? ''
                : '<div class="vc-coupon-widget">' . do_shortcode($shortcode) . '</div>';

        case 'destinations':
            return function_exists('vc_merchant_destinations')
                ? vc_merchant_destinations($post_id) : '';

        case 'tags':
            return function_exists('vc_merchant_taxonomy_tags')
                ? vc_merchant_taxonomy_tags($post_id) : '';

        case 'quickfacts':
            return function_exists('vc_merchant_quick_facts')
                ? vc_merchant_quick_facts($post_id) : '';

        case 'about':
            return vc_merchant_section(sprintf(__('About %s', 'vc-merchant'), $name),
                $m('brand_summary'));

        case 'save':
            return vc_merchant_section(
                sprintf(__('Best ways to save at %s', 'vc-merchant'), $name),
                $m('best_ways_to_save'));

        case 'policies':
            return shortcode_exists('merchant_policies')
                ? do_shortcode('[merchant_policies id="' . $post_id . '"]') : '';

        case 'faqs':
            return shortcode_exists('merchant_faqs')
                ? do_shortcode('[merchant_faqs id="' . $post_id . '"]') : '';

        case 'info':
            return vc_merchant_info_panel($post_id);

        case 'trust':
            $trust = vc_merchant_trust_info($post_id);
            return $trust === '' ? '' : '<section class="vc-trust-section"><h2>'
                . esc_html__('Company information', 'vc-merchant') . '</h2>' . $trust . '</section>';

        case 'links':
            return function_exists('vc_merchant_links') ? vc_merchant_links($post_id) : '';

        case 'related':
            if (($atts['related'] ?? 'yes') === 'no' || !shortcode_exists('merchant_related')) {
                return '';
            }
            return do_shortcode('[merchant_related id="' . $post_id . '" limit="6"]');

        case 'editorial':
            return shortcode_exists('merchant_editorial')
                ? do_shortcode('[merchant_editorial id="' . $post_id . '"]') : '';
    }

    return '';
}

add_shortcode('merchant_page', 'vc_merchant_render_page');
