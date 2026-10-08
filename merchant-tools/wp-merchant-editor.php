<?php
/**
 * Merchant data panel on the post edit screen.
 *
 * Two problems this solves. Fixing one merchant's typo meant editing the CSV
 * and re-importing everything; and the field shortcodes were undiscoverable
 * unless you already knew the column name.
 *
 * Every field is editable here and saves to the same meta the importer writes,
 * so a hand edit and a re-import are interchangeable. Each field also shows
 * the shortcode that prints it, click-to-copy, for dropping a single value
 * into the post content alongside [merchant_page].
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fields grouped as they are edited, not as they are stored.
 *
 * 'big' marks a value that needs a textarea rather than a single line.
 */
function vc_editor_field_groups() {
    return array(
        __('Offer', 'vc-merchant') => array(
            'best_offer_summary'      => array('label' => 'Best offer summary', 'big' => true),
            'top_offer_type'          => array('label' => 'Offer type'),
            'coupon_plugin_shortcode' => array('label' => 'Coupon widget shortcode'),
            'last_checked_text'       => array('label' => 'Last checked'),
            'offer_display_mode'      => array('label' => 'Offer display mode',
                'help' => 'verified_code | best_deal | no_code_confirmed'),
        ),
        __('Content', 'vc-merchant') => array(
            'display_brand_name'      => array('label' => 'Display name (used in H1)'),
            'brand_summary'           => array('label' => 'About the store', 'big' => true),
            'best_ways_to_save'       => array('label' => 'Best ways to save', 'big' => true),
            'coupon_intro'            => array('label' => 'Intro', 'big' => true),
            'alternative_brand_names' => array('label' => 'Also known as', 'help' => 'Separate with |'),
        ),
        __('Policies', 'vc-merchant') => array(
            'free_shipping_info'    => array('label' => 'Shipping', 'big' => true),
            'return_policy_summary' => array('label' => 'Returns', 'big' => true),
            'payment_methods'       => array('label' => 'Payment methods'),
            'common_exclusions'     => array('label' => 'Exclusions', 'big' => true),
            'stacking_policy'       => array('label' => 'Stacking codes', 'big' => true),
            'why_code_not_work'     => array('label' => 'If a code fails', 'big' => true),
            'shipping_restrictions' => array('label' => 'Shipping restrictions', 'big' => true),
            'restricted_states'     => array('label' => 'Cannot ship to', 'help' => 'Separate with |'),
            'do_they_id_on_delivery'=> array('label' => 'ID on delivery'),
            'age_verification_required' => array('label' => 'Minimum age'),
        ),
        __('FAQs', 'vc-merchant') => array(
            'faq_1_question' => array('label' => 'FAQ 1 question'),
            'faq_1_answer'   => array('label' => 'FAQ 1 answer', 'big' => true),
            'faq_2_question' => array('label' => 'FAQ 2 question'),
            'faq_2_answer'   => array('label' => 'FAQ 2 answer', 'big' => true),
            'faq_3_question' => array('label' => 'FAQ 3 question'),
            'faq_3_answer'   => array('label' => 'FAQ 3 answer', 'big' => true),
        ),
        __('Links', 'vc-merchant') => array(
            'brand_url'           => array('label' => 'Store URL'),
            'brand_logo_url'      => array('label' => 'Logo URL'),
            'social_links'        => array('label' => 'Social profiles', 'big' => true,
                'help' => 'Full URLs, separated with |'),
            'useful_links'        => array('label' => 'Other store pages', 'big' => true,
                'help' => 'Label :: URL | Label :: URL'),
            'shipping_policy_url' => array('label' => 'Shipping policy URL'),
            'returns_policy_url'  => array('label' => 'Returns policy URL'),
            'age_policy_url'      => array('label' => 'Age policy URL'),
            'brand_review_url'    => array('label' => 'Our review (internal)'),
            'deals_hub_url'       => array('label' => 'Our deals hub (internal)'),
            'seasonal_deals_url'  => array('label' => 'Our seasonal page (internal)'),
        ),
        __('Contact', 'vc-merchant') => array(
            'contact_email'       => array('label' => 'Support email'),
            'contact_page_url'    => array('label' => 'Contact page'),
            'contact_method'      => array('label' => 'Contact method'),
            'contact_verified_at' => array('label' => 'Contact verified'),
        ),
        __('Trust & provenance', 'vc-merchant') => array(
            'company_trust_info' => array('label' => 'Review scores / company info', 'big' => true),
            'trust_source_url'   => array('label' => 'Source for the above',
                'help' => 'Required before review scores render. Must be where the score was read.'),
            'trust_verified_at'  => array('label' => 'Score verified on', 'help' => 'YYYY-MM-DD'),
            'fact_source_url'    => array('label' => 'General fact source'),
            'fact_last_verified' => array('label' => 'Facts verified on'),
            'editor_name'        => array('label' => 'Reviewed by'),
            'editor_title'       => array('label' => 'Reviewer title'),
            'verification_method'=> array('label' => 'How we checked', 'big' => true),
        ),
    );
}

add_action('add_meta_boxes', function () {
    foreach (vc_merchant_post_types() as $post_type) {
        add_meta_box(
            'vc-merchant-data',
            __('Merchant data & shortcodes', 'vc-merchant'),
            'vc_editor_meta_box',
            $post_type,
            'normal',
            'high'
        );
    }
});

function vc_editor_meta_box($post) {
    wp_nonce_field('vc_editor_save', 'vc_editor_nonce');
    ?>
    <p class="description">
        <?php esc_html_e('Edits here save to the same fields the importer writes, so hand edits and re-imports are interchangeable. A re-import will overwrite these, so put lasting corrections in the CSV too.', 'vc-merchant'); ?>
    </p>

    <p class="description">
        <?php esc_html_e('Click any shortcode to copy it, then paste into the post content to print that single value anywhere.', 'vc-merchant'); ?>
        <code>[merchant_name]</code>
        <?php esc_html_e('prints the display name.', 'vc-merchant'); ?>
    </p>

    <?php foreach (vc_editor_field_groups() as $group => $fields) : ?>
        <h3 style="margin:1.5em 0 .5em;border-bottom:1px solid #dcdcde;padding-bottom:.4em;">
            <?php echo esc_html($group); ?>
        </h3>
        <table class="form-table" role="presentation">
            <?php foreach ($fields as $key => $meta) :
                $value = get_post_meta($post->ID, $key, true);
                $shortcode = '[merchant_field key="' . $key . '"]';
                ?>
                <tr>
                    <th scope="row" style="width:210px;">
                        <label for="vc_<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($meta['label']); ?>
                        </label>
                        <button type="button" class="button-link vc-copy"
                                data-shortcode="<?php echo esc_attr($shortcode); ?>"
                                title="<?php esc_attr_e('Copy shortcode', 'vc-merchant'); ?>"
                                style="display:block;font-size:11px;color:#2271b1;cursor:pointer;text-align:left;margin-top:.2em;">
                            <?php echo esc_html($shortcode); ?>
                        </button>
                    </th>
                    <td>
                        <?php if (!empty($meta['big'])) : ?>
                            <textarea id="vc_<?php echo esc_attr($key); ?>"
                                      name="vc_fields[<?php echo esc_attr($key); ?>]"
                                      rows="3" class="large-text"><?php echo esc_textarea($value); ?></textarea>
                        <?php else : ?>
                            <input type="text" id="vc_<?php echo esc_attr($key); ?>"
                                   name="vc_fields[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr($value); ?>" class="large-text" />
                        <?php endif; ?>
                        <?php if (!empty($meta['help'])) : ?>
                            <p class="description"><?php echo esc_html($meta['help']); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>

    <script>
    (function () {
        document.querySelectorAll('.vc-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var text = btn.getAttribute('data-shortcode');
                var done = function () {
                    var original = btn.textContent;
                    btn.textContent = '<?php echo esc_js(__('copied', 'vc-merchant')); ?> ✓';
                    setTimeout(function () { btn.textContent = original; }, 1200);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, done);
                    return;
                }
                // Fallback for non-secure contexts, where the clipboard API is
                // unavailable.
                var tmp = document.createElement('textarea');
                tmp.value = text;
                document.body.appendChild(tmp);
                tmp.select();
                try { document.execCommand('copy'); } catch (e) {}
                document.body.removeChild(tmp);
                done();
            });
        });
    })();
    </script>
    <?php
}

add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    if (empty($_POST['vc_editor_nonce'])
        || !wp_verify_nonce($_POST['vc_editor_nonce'], 'vc_editor_save')) {
        return;
    }
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    if (empty($_POST['vc_fields']) || !is_array($_POST['vc_fields'])) {
        return;
    }

    // Only keys this panel declares, so the form cannot be used to write
    // arbitrary post meta.
    $allowed = array();
    foreach (vc_editor_field_groups() as $fields) {
        $allowed = array_merge($allowed, array_keys($fields));
    }

    foreach ($_POST['vc_fields'] as $key => $value) {
        if (!in_array($key, $allowed, true)) {
            continue;
        }
        update_post_meta($post_id, $key, sanitize_textarea_field(wp_unslash($value)));
    }
});
