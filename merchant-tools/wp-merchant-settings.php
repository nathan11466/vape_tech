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
    );
}

function vc_layout_visual_defaults() {
    return array(
        'max_width'     => 1100,
        'sidebar_width' => 320,
        'accent'        => '#137a4e',
        'figure_size'   => 3.1,
        'h1_suffix'     => 'Coupon Codes',
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

    return array('order' => $order, 'where' => $where, 'visual' => $visual);
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
            );

            update_option(VC_LAYOUT_OPTION, array(
                'order'  => array_keys($positions),
                'where'  => $where,
                'visual' => $visual,
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
            </table>

            <p class="submit">
                <?php submit_button(__('Save layout', 'vc-merchant'), 'primary', 'submit', false); ?>
                <?php submit_button(__('Reset to defaults', 'vc-merchant'), 'secondary', 'vc_layout_reset', false); ?>
            </p>
        </form>
    </div>
    <?php
}
