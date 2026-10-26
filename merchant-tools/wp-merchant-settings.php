<?php
/**
 * Page layout settings.
 *
 * Every layout change used to mean editing the plugin and reinstalling it.
 * This moves the decisions into wp-admin: which blocks appear, what order they
 * run in, which column they sit in, and the handful of visual values worth
 * changing. Defaults reproduce the built-in layout exactly, so saving nothing
 * changes nothing.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VC_LAYOUT_OPTION = 'vc_merchant_layout';

/**
 * Every block the merchant page can render, in default order.
 *
 * 'where' is main | side | off.
 */
function vc_layout_defaults() {
    return array(
        'title'      => array('label' => 'Page title (H1)',             'where' => 'main'),
        'hero'       => array('label' => 'Hero (logo, discount, CTA)', 'where' => 'main'),
        'coupon'     => array('label' => 'Coupon widget',              'where' => 'main'),
        'quickfacts' => array('label' => 'Quick facts strip',          'where' => 'main'),
        'about'      => array('label' => 'About the store',            'where' => 'main'),
        'save'       => array('label' => 'Best ways to save',          'where' => 'main'),
        'policies'   => array('label' => 'Shipping, returns & terms',  'where' => 'main'),
        'destinations' => array('label' => 'Where this store ships',    'where' => 'main'),
        'tags'       => array('label' => 'Serves / sells (taxonomy)',   'where' => 'side'),
        'faqs'       => array('label' => 'FAQs',                       'where' => 'main'),
        'info'       => array('label' => 'Merchant information panel', 'where' => 'side'),
        'trust'      => array('label' => 'Company information',        'where' => 'side'),
        'links'      => array('label' => 'Store pages & social',       'where' => 'side'),
        'related'    => array('label' => 'Similar stores',             'where' => 'side'),
        'internal'   => array('label' => 'Our review / deals / seasonal links', 'where' => 'main'),
        'editorial'  => array('label' => 'Reviewed by / how we checked', 'where' => 'main'),
        // Off until you put something in them, so adding these changes
        // nothing on a page that was working before.
        'custom1'    => array('label' => 'Custom block 1',               'where' => 'off'),
        'custom2'    => array('label' => 'Custom block 2',               'where' => 'off'),
        'custom3'    => array('label' => 'Custom block 3',               'where' => 'off'),
    );
}

/**
 * Default heading for each block that prints one.
 *
 * {brand} is replaced with the store's display name. A block absent from
 * this list has no editable heading -- either it prints none, or its heading
 * belongs to another plugin.
 */
function vc_layout_heading_defaults() {
    return array(
        'coupon'       => __('Best current deal', 'vc-merchant'),
        'about'        => __('About {brand}', 'vc-merchant'),
        'save'         => __('Best ways to save at {brand}', 'vc-merchant'),
        'policies'     => __('Shipping, returns & terms', 'vc-merchant'),
        'faqs'         => __('{brand} FAQs', 'vc-merchant'),
        'destinations' => __('Where this store ships', 'vc-merchant'),
        'trust'        => __('Company information', 'vc-merchant'),
        'related'      => __('Similar stores', 'vc-merchant'),
        'internal'     => __('More from us', 'vc-merchant'),
        'expired'      => __('Recently expired', 'vc-merchant'),
        'custom1'      => '',
        'custom2'      => '',
        'custom3'      => '',
    );
}

/**
 * The heading to print for a block, with {brand} resolved.
 *
 * Returns '' when an editor has deliberately cleared it, which is how a
 * section is rendered without a heading.
 */
function vc_layout_heading($key, $post_id = null) {
    $defaults = vc_layout_heading_defaults();
    if (!array_key_exists($key, $defaults)) {
        return '';
    }

    $saved = vc_layout_get()['headings'];
    // A cleared heading is stored as '' and must survive, so the default is
    // only used when the key was never saved at all.
    $text = array_key_exists($key, $saved) ? $saved[$key] : $defaults[$key];

    if (strpos($text, '{brand}') !== false) {
        $name = function_exists('vc_merchant_display_name')
            ? vc_merchant_display_name($post_id) : '';
        $text = str_replace('{brand}', $name, $text);
    }

    return trim($text);
}

/**
 * The content of a custom block, shortcodes expanded.
 */
function vc_layout_custom_content($key) {
    $custom = vc_layout_get()['custom'];

    return isset($custom[$key]) ? (string) $custom[$key] : '';
}

function vc_layout_visual_defaults() {
    return array(
        'max_width'     => 1100,
        'sidebar_width' => 320,
        'accent'        => '#137a4e',
        'figure_size'   => 3.1,
        'h1_suffix'     => 'Coupon Codes',
        'radius'        => 10,
        'surface'       => '#ffffff',
        'border'        => '#e5e7eb',
        'text'          => '#1f2328',
        'heading_size'  => 1.35,
        'text_size'     => 1.0,
        'section_gap'   => 1.75,
    );
}

/**
 * Stored layout, merged over the defaults so a new block added in a future
 * version appears rather than vanishing.
 */
function vc_layout_get() {
    $saved = get_option(VC_LAYOUT_OPTION, array());
    $defaults = vc_layout_defaults();

    $order = array();
    if (!empty($saved['order']) && is_array($saved['order'])) {
        foreach ($saved['order'] as $key) {
            if (isset($defaults[$key])) {
                $order[] = $key;
            }
        }
    }
    foreach (array_keys($defaults) as $key) {
        if (!in_array($key, $order, true)) {
            $order[] = $key;
        }
    }

    $where = array();
    foreach ($order as $key) {
        $saved_where = $saved['where'][$key] ?? null;
        $where[$key] = in_array($saved_where, array('main', 'side', 'off'), true)
            ? $saved_where
            : $defaults[$key]['where'];
    }

    $visual = array_merge(vc_layout_visual_defaults(),
        is_array($saved['visual'] ?? null) ? $saved['visual'] : array());

    // Headings are NOT merged over the defaults. A heading an editor cleared
    // is stored as '' and merging would quietly restore the default text.
    $headings = array();
    if (is_array($saved['headings'] ?? null)) {
        foreach (vc_layout_heading_defaults() as $key => $unused) {
            if (array_key_exists($key, $saved['headings'])) {
                $headings[$key] = (string) $saved['headings'][$key];
            }
        }
    }

    $custom = array();
    if (is_array($saved['custom'] ?? null)) {
        foreach (array('custom1', 'custom2', 'custom3') as $key) {
            if (isset($saved['custom'][$key])) {
                $custom[$key] = (string) $saved['custom'][$key];
            }
        }
    }

    return array('order' => $order, 'where' => $where, 'visual' => $visual,
                 'headings' => $headings, 'custom' => $custom);
}

/**
 * Blocks for one column, in order.
 */
function vc_layout_blocks($column) {
    $layout = vc_layout_get();
    $out = array();
    foreach ($layout['order'] as $key) {
        if ($layout['where'][$key] === $column) {
            $out[] = $key;
        }
    }

    return $out;
}

function vc_layout_has_sidebar() {
    return !empty(vc_layout_blocks('side'));
}

/* -------------------------------------------------------------------------
 * Visual values as CSS custom properties
 * ---------------------------------------------------------------------- */

add_action('wp_head', function () {
    if (!function_exists('vc_merchant_post_types')) {
        return;
    }
    $visual = vc_layout_get()['visual'];
    $defaults = vc_layout_visual_defaults();

    $rules = array();
    if ((int) $visual['max_width'] !== (int) $defaults['max_width']) {
        $rules[] = '--vc-max-width:' . (int) $visual['max_width'] . 'px';
    }
    if ((int) $visual['sidebar_width'] !== (int) $defaults['sidebar_width']) {
        $rules[] = '--vc-sidebar-width:' . (int) $visual['sidebar_width'] . 'px';
    }
    if (strtolower($visual['accent']) !== strtolower($defaults['accent'])) {
        $rules[] = '--vc-accent:' . sanitize_hex_color($visual['accent']);
    }
    if ((float) $visual['figure_size'] !== (float) $defaults['figure_size']) {
        $rules[] = '--vc-figure-size:' . (float) $visual['figure_size'] . 'rem';
    }

    // Only emitted when changed, so the stylesheet's own value (and its dark
    // mode handling) stays in charge of anything left alone.
    foreach (array('radius' => '--vc-radius', 'heading_size' => '--vc-heading-size',
                   'text_size' => '--vc-text-size', 'section_gap' => '--vc-section-gap') as $k => $prop) {
        if ((float) $visual[$k] !== (float) $defaults[$k]) {
            $unit = $k === 'radius' ? 'px' : 'rem';
            $rules[] = $prop . ':' . (float) $visual[$k] . $unit;
        }
    }
    foreach (array('surface' => '--vc-surface', 'border' => '--vc-border',
                   'text' => '--vc-text') as $k => $prop) {
        if (strtolower($visual[$k]) !== strtolower($defaults[$k])) {
            $hex = sanitize_hex_color($visual[$k]);
            if ($hex) {
                $rules[] = $prop . ':' . $hex;
            }
        }
    }

    if (empty($rules)) {
        return;
    }

    echo "\n<style id=\"vc-merchant-layout\">:root{" . esc_html(implode(';', $rules)) . ";}</style>\n";
}, 5);

/* -------------------------------------------------------------------------
 * Settings screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    $types = function_exists('vc_merchant_post_types') ? vc_merchant_post_types() : array('merchant');
    add_submenu_page(
        'edit.php?post_type=' . reset($types),
        __('Page Layout', 'vc-merchant'),
        __('Page Layout', 'vc-merchant'),
        'manage_options',
        'vc-merchant-layout',
        'vc_layout_screen'
    );
}, 20);

function vc_layout_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $saved_notice = '';

    if (!empty($_POST['vc_layout_nonce'])
        && wp_verify_nonce($_POST['vc_layout_nonce'], 'vc_layout_save')) {

        if (isset($_POST['vc_layout_reset'])) {
            delete_option(VC_LAYOUT_OPTION);
            $saved_notice = __('Layout reset to defaults.', 'vc-merchant');
        } else {
            $defaults = vc_layout_defaults();

            $positions = array();
            foreach (array_keys($defaults) as $key) {
                $positions[$key] = isset($_POST['order'][$key])
                    ? (float) $_POST['order'][$key] : 999;
            }
            asort($positions);

            $where = array();
            foreach (array_keys($defaults) as $key) {
                $value = $_POST['where'][$key] ?? $defaults[$key]['where'];
                $where[$key] = in_array($value, array('main', 'side', 'off'), true)
                    ? $value : $defaults[$key]['where'];
            }

            $vd = vc_layout_visual_defaults();
            $visual = array(
                'max_width'     => max(600, min(2000, (int) ($_POST['max_width'] ?? $vd['max_width']))),
                'sidebar_width' => max(200, min(600, (int) ($_POST['sidebar_width'] ?? $vd['sidebar_width']))),
                'accent'        => sanitize_hex_color($_POST['accent'] ?? '') ?: $vd['accent'],
                'figure_size'   => max(1.5, min(6.0, (float) ($_POST['figure_size'] ?? $vd['figure_size']))),
                'h1_suffix'     => sanitize_text_field($_POST['h1_suffix'] ?? $vd['h1_suffix']),
                'radius'        => max(0, min(40, (int) ($_POST['radius'] ?? $vd['radius']))),
                'surface'       => sanitize_hex_color($_POST['surface'] ?? '') ?: $vd['surface'],
                'border'        => sanitize_hex_color($_POST['border'] ?? '') ?: $vd['border'],
                'text'          => sanitize_hex_color($_POST['text'] ?? '') ?: $vd['text'],
                'heading_size'  => max(0.9, min(3.0, (float) ($_POST['heading_size'] ?? $vd['heading_size']))),
                'text_size'     => max(0.8, min(1.6, (float) ($_POST['text_size'] ?? $vd['text_size']))),
                'section_gap'   => max(0.5, min(5.0, (float) ($_POST['section_gap'] ?? $vd['section_gap']))),
            );

            // Headings. A cleared one is stored as '' on purpose -- that is
            // how a section renders without a heading -- so every key that
            // was submitted is kept, empty or not.
            $headings = array();
            foreach (array_keys(vc_layout_heading_defaults()) as $key) {
                if (isset($_POST['heading'][$key])) {
                    $headings[$key] = sanitize_text_field(wp_unslash($_POST['heading'][$key]));
                }
            }

            // Custom block content. kses rather than sanitize_text_field,
            // because the point is to allow markup; shortcodes survive it.
            $custom = array();
            foreach (array('custom1', 'custom2', 'custom3') as $key) {
                if (isset($_POST['custom'][$key])) {
                    $custom[$key] = wp_kses_post(wp_unslash($_POST['custom'][$key]));
                }
            }

            update_option(VC_LAYOUT_OPTION, array(
                'order'    => array_keys($positions),
                'where'    => $where,
                'visual'   => $visual,
                'headings' => $headings,
                'custom'   => $custom,
            ));
            $saved_notice = __('Layout saved.', 'vc-merchant');
        }
    }

    $layout = vc_layout_get();
    $defaults = vc_layout_defaults();
    $visual = $layout['visual'];
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Merchant Page Layout', 'vc-merchant'); ?></h1>

        <?php if ($saved_notice) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html($saved_notice); ?></p></div>
        <?php endif; ?>

        <p><?php esc_html_e('Controls every merchant page at once. Lower numbers appear first; change a number and save to reorder. Sidebar blocks sit in a sticky right column, and the sidebar disappears entirely if nothing is assigned to it.', 'vc-merchant'); ?></p>

        <form method="post">
            <?php wp_nonce_field('vc_layout_save', 'vc_layout_nonce'); ?>

            <table class="wp-list-table widefat striped" style="max-width:760px;">
                <thead>
                    <tr>
                        <th style="width:90px;"><?php esc_html_e('Order', 'vc-merchant'); ?></th>
                        <th><?php esc_html_e('Block', 'vc-merchant'); ?></th>
                        <th style="width:170px;"><?php esc_html_e('Shows in', 'vc-merchant'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php $position = 0; foreach ($layout['order'] as $key) : $position += 10; ?>
                    <tr>
                        <td>
                            <input type="number" name="order[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr($position); ?>" step="1" min="0"
                                   style="width:70px;" />
                        </td>
                        <td><?php echo esc_html($defaults[$key]['label']); ?></td>
                        <td>
                            <select name="where[<?php echo esc_attr($key); ?>]">
                                <option value="main" <?php selected($layout['where'][$key], 'main'); ?>>
                                    <?php esc_html_e('Main column', 'vc-merchant'); ?></option>
                                <option value="side" <?php selected($layout['where'][$key], 'side'); ?>>
                                    <?php esc_html_e('Sidebar', 'vc-merchant'); ?></option>
                                <option value="off" <?php selected($layout['where'][$key], 'off'); ?>>
                                    <?php esc_html_e('Hidden', 'vc-merchant'); ?></option>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e('Section headings', 'vc-merchant'); ?></h2>
            <p class="description" style="max-width:760px;">
                <?php esc_html_e('The H2 above each section, on every store page. Use {brand} for the store name. Clear a box to render that section with no heading at all.', 'vc-merchant'); ?>
            </p>
            <table class="form-table" style="max-width:760px;">
                <?php foreach (vc_layout_heading_defaults() as $key => $default) : ?>
                    <?php if (strpos($key, 'custom') === 0) { continue; } ?>
                    <tr>
                        <th scope="row">
                            <label for="heading_<?php echo esc_attr($key); ?>">
                                <?php echo esc_html($defaults[$key]['label'] ?? $key); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" id="heading_<?php echo esc_attr($key); ?>"
                                   name="heading[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr(
                                       array_key_exists($key, $layout['headings'])
                                           ? $layout['headings'][$key] : $default); ?>"
                                   class="large-text" />
                            <p class="description">
                                <?php echo esc_html(sprintf(
                                    __('Default: %s', 'vc-merchant'),
                                    $default !== '' ? $default : __('(none)', 'vc-merchant'))); ?>
                            </p>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <h2><?php esc_html_e('Custom blocks', 'vc-merchant'); ?></h2>
            <p class="description" style="max-width:760px;">
                <?php esc_html_e('Your own content on every store page. Text, HTML and any shortcode work here, including [merchant_field], [merchant_fact] and [merchant_name] - so a block can mix your writing with that store\'s own data. Set its position and column in the table above; a block stays hidden until you move it out of Hidden.', 'vc-merchant'); ?>
            </p>
            <table class="form-table" style="max-width:760px;">
                <?php foreach (array('custom1', 'custom2', 'custom3') as $key) : ?>
                    <tr>
                        <th scope="row">
                            <label for="custom_<?php echo esc_attr($key); ?>">
                                <?php echo esc_html($defaults[$key]['label']); ?>
                            </label>
                            <p class="description" style="font-weight:400;">
                                <?php echo esc_html(sprintf(
                                    __('Currently: %s', 'vc-merchant'),
                                    $layout['where'][$key] === 'off'
                                        ? __('hidden', 'vc-merchant')
                                        : ($layout['where'][$key] === 'side'
                                            ? __('sidebar', 'vc-merchant')
                                            : __('main column', 'vc-merchant')))); ?>
                            </p>
                        </th>
                        <td>
                            <input type="text"
                                   name="heading[<?php echo esc_attr($key); ?>]"
                                   value="<?php echo esc_attr(
                                       array_key_exists($key, $layout['headings'])
                                           ? $layout['headings'][$key] : ''); ?>"
                                   class="large-text"
                                   placeholder="<?php esc_attr_e('Optional heading, {brand} allowed', 'vc-merchant'); ?>" />
                            <textarea id="custom_<?php echo esc_attr($key); ?>"
                                      name="custom[<?php echo esc_attr($key); ?>]"
                                      rows="5" class="large-text code"
                                      style="margin-top:6px;"><?php
                                echo esc_textarea(vc_layout_custom_content($key));
                            ?></textarea>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>

            <h2><?php esc_html_e('Appearance', 'vc-merchant'); ?></h2>
            <table class="form-table" style="max-width:760px;">
                <tr>
                    <th scope="row"><label for="h1_suffix"><?php esc_html_e('H1 wording', 'vc-merchant'); ?></label></th>
                    <td>
                        <code><?php esc_html_e('[store name]', 'vc-merchant'); ?></code>
                        <input type="text" id="h1_suffix" name="h1_suffix"
                               value="<?php echo esc_attr($visual['h1_suffix']); ?>" class="regular-text" />
                        <p class="description"><?php esc_html_e('The H1 is the store\'s display name followed by this. Leave empty for the name alone.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="max_width"><?php esc_html_e('Content width', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" id="max_width" name="max_width"
                               value="<?php echo esc_attr($visual['max_width']); ?>" min="600" max="2000" step="10" /> px
                        <p class="description"><?php esc_html_e('Your theme still wins if it constrains content more tightly than this.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sidebar_width"><?php esc_html_e('Sidebar width', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" id="sidebar_width" name="sidebar_width"
                               value="<?php echo esc_attr($visual['sidebar_width']); ?>" min="200" max="600" step="10" /> px
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="accent"><?php esc_html_e('Accent colour', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="color" id="accent" name="accent"
                               value="<?php echo esc_attr($visual['accent']); ?>" />
                        <p class="description"><?php esc_html_e('Buttons, the verified badge and the discount figure.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="figure_size"><?php esc_html_e('Discount figure size', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" id="figure_size" name="figure_size"
                               value="<?php echo esc_attr($visual['figure_size']); ?>"
                               min="1.5" max="6" step="0.1" /> rem
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="heading_size"><?php esc_html_e('Section heading size', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" id="heading_size" name="heading_size"
                               value="<?php echo esc_attr($visual['heading_size']); ?>"
                               min="0.9" max="3" step="0.05" /> rem
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="text_size"><?php esc_html_e('Body text size', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" id="text_size" name="text_size"
                               value="<?php echo esc_attr($visual['text_size']); ?>"
                               min="0.8" max="1.6" step="0.05" /> rem
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="section_gap"><?php esc_html_e('Space between sections', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" id="section_gap" name="section_gap"
                               value="<?php echo esc_attr($visual['section_gap']); ?>"
                               min="0.5" max="5" step="0.25" /> rem
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="radius"><?php esc_html_e('Corner rounding', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="number" id="radius" name="radius"
                               value="<?php echo esc_attr($visual['radius']); ?>"
                               min="0" max="40" step="1" /> px
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="surface"><?php esc_html_e('Card background', 'vc-merchant'); ?></label></th>
                    <td><input type="color" id="surface" name="surface"
                               value="<?php echo esc_attr($visual['surface']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="border"><?php esc_html_e('Border colour', 'vc-merchant'); ?></label></th>
                    <td><input type="color" id="border" name="border"
                               value="<?php echo esc_attr($visual['border']); ?>" /></td>
                </tr>
                <tr>
                    <th scope="row"><label for="text"><?php esc_html_e('Body text colour', 'vc-merchant'); ?></label></th>
                    <td>
                        <input type="color" id="text" name="text"
                               value="<?php echo esc_attr($visual['text']); ?>" />
                        <p class="description"><?php esc_html_e('Leave the three colours alone to keep the stylesheet\'s own values, including its dark-mode handling.', 'vc-merchant'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php submit_button(__('Save layout', 'vc-merchant'), 'primary', 'submit', false); ?>
                <?php submit_button(__('Reset to defaults', 'vc-merchant'), 'secondary', 'vc_layout_reset', false); ?>
            </p>
        </form>
    </div>
    <?php
}
