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
    $atts = shortcode_atts(array('id' => 0), $atts, 'merchant_page');
    $post_id = (int) $atts['id'] ?: get_the_ID();

    if (!$post_id) {
        return '';
    }

    // The page always renders. WordPress post status is the publish gate --
    // a draft is not public, and a post you published is one you decided to
    // publish. Grading is advisory only, surfaced to editors as a notice.
    $notice = '';
    if (current_user_can('edit_posts')) {
        $notes = trim((string) get_post_meta($post_id, 'review_notes', true));
        if ($notes !== '') {
            $notice = '<div class="vc-editor-notice" style="background:#fff8e5;border-left:4px solid #dba617;'
                . 'padding:10px;margin-bottom:15px;font-size:13px;">'
                . '<strong>' . esc_html__('Review notes (visible to editors only):', 'vc-merchant') . '</strong> '
                . esc_html($notes) . '</div>';
        }
    }

    $m = function ($key) use ($post_id) {
        return trim((string) get_post_meta($post_id, $key, true));
    };

    $name = vc_merchant_display_name($post_id);
    $out  = '<div class="vc-merchant-page">' . $notice;

    // Offer status -- the gated claim.
    $out .= '<div class="vc-offer-status">' . vc_merchant_offer_badge($post_id) . '</div>';

    $out .= vc_merchant_section(__('Best current deal', 'vc-merchant'), $m('best_offer_summary'));

    // The coupon plugin's own widget.
    $shortcode = $m('coupon_plugin_shortcode');
    if ($shortcode !== '') {
        $out .= '<div class="vc-coupon-widget">' . do_shortcode($shortcode) . '</div>';
    }

    $out .= vc_merchant_section(sprintf(__('About %s', 'vc-merchant'), $name), $m('brand_summary'));
    $out .= vc_merchant_section(sprintf(__('Best ways to save at %s', 'vc-merchant'), $name), $m('best_ways_to_save'));
    $out .= vc_merchant_section(__('Shipping', 'vc-merchant'), $m('free_shipping_info'));
    $out .= vc_merchant_section(__('Returns', 'vc-merchant'), $m('return_policy_summary'));
    $out .= vc_merchant_section(__('Payment methods', 'vc-merchant'), $m('payment_methods'));
    $out .= vc_merchant_section(__('Exclusions', 'vc-merchant'), $m('common_exclusions'));
    $out .= vc_merchant_section(__('Stacking codes', 'vc-merchant'), $m('stacking_policy'));
    $out .= vc_merchant_section(__('If your code will not work', 'vc-merchant'), $m('why_code_not_work'));
    $out .= vc_merchant_section(__('Shipping restrictions', 'vc-merchant'), $m('shipping_restrictions'));

    // Explicit "cannot ship to" list, when destinations were resolved.
    if (function_exists('vc_merchant_restricted_line')) {
        $out .= vc_merchant_restricted_line($post_id);
    }

    // FAQs -- these also feed the FAQPage schema.
    $faqs = '';
    for ($i = 1; $i <= 3; $i++) {
        $q = $m("faq_{$i}_question");
        $a = $m("faq_{$i}_answer");
        if ($q !== '' && $a !== '') {
            $faqs .= '<div class="vc-faq"><h3>' . esc_html($q) . '</h3>' . wpautop(esc_html($a)) . '</div>';
        }
    }
    if ($faqs !== '') {
        $out .= '<section class="vc-faqs"><h2>'
            . esc_html(sprintf(__('%s FAQs', 'vc-merchant'), $name)) . '</h2>' . $faqs . '</section>';
    }

    // Merchant info panel and trust block, both self-gating.
    $out .= vc_merchant_info_panel($post_id);

    $trust = vc_merchant_trust_info($post_id);
    if ($trust !== '') {
        $out .= '<section class="vc-trust-section"><h2>'
            . esc_html__('Company information', 'vc-merchant') . '</h2>' . $trust . '</section>';
    }

    // Editorial transparency.
    $editor = $m('editor_name');
    $method = $m('verification_method');
    if ($editor !== '' || $method !== '') {
        $out .= '<section class="vc-editorial">';
        if ($editor !== '') {
            $title = $m('editor_title');
            $out .= '<p>' . esc_html__('Reviewed by', 'vc-merchant') . ' ' . esc_html($editor)
                . ($title !== '' ? ', ' . esc_html($title) : '') . '</p>';
        }
        if ($method !== '') {
            $out .= '<p>' . esc_html__('How we checked:', 'vc-merchant') . ' ' . esc_html($method) . '</p>';
        }
        $verified = $m('fact_last_verified');
        if ($verified !== '') {
            $out .= '<p>' . esc_html__('Last verified:', 'vc-merchant') . ' ' . esc_html($verified) . '</p>';
        }
        $out .= '</section>';
    }

    // Internal links.
    $links = array(
        __('Read our review', 'vc-merchant') => $m('brand_review_url'),
        __('All deals', 'vc-merchant')       => $m('deals_hub_url'),
        __('Seasonal deals', 'vc-merchant')  => $m('seasonal_deals_url'),
    );
    $items = '';
    foreach ($links as $label => $href) {
        if ($href !== '') {
            $items .= '<li><a href="' . esc_url($href) . '">' . esc_html($label) . '</a></li>';
        }
    }
    if ($items !== '') {
        $out .= '<nav class="vc-related"><ul>' . $items . '</ul></nav>';
    }

    $out .= '</div>';

    return $out;
}
add_shortcode('merchant_page', 'vc_merchant_render_page');
