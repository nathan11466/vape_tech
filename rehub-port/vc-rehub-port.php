<?php
/**
 * Plugin Name: ReHub Port Helper
 * Description: Finishes the Astra-to-ReHub migration: rebuilds the footer navigation as ReHub footer widgets, applies the handful of CSS overrides that differ between the two themes, and reports what still has to be done by hand.
 * Version: 1.0.0
 * Author: Vaping Cheap
 *
 * Why this exists
 * ---------------
 * The site's content moved from Astra to ReHub; its configuration did not.
 * Four things broke at once and none of them was a bug: the footer links
 * lived in Astra's footer builder, the homepage was built with Spectra
 * blocks, the banner ads were placed through Astra's hooks, and the content
 * box and footer type size are simply ReHub's design.
 *
 * Content ports by itself. Configuration does not, and clicking it back
 * together by hand is how it gets missed next time. So the parts that can
 * be rebuilt from data are rebuilt here, once, repeatably -- and the parts
 * that cannot are listed on screen with the exact field to put them in,
 * rather than left to memory.
 *
 * Delete this plugin once the migration is finished. It is scaffolding,
 * not a permanent part of the site: everything it writes (menus, widgets)
 * is ordinary WordPress data that stays behind when it goes.
 *
 * What it deliberately does not do
 * --------------------------------
 * It never writes to ReHub's own settings array. rehub_option holds some
 * two hundred keys in one blob; merging into that blind risks losing
 * somebody's work for the sake of saving two clicks. Ad code and the
 * content-width setting are reported with their field names instead.
 *
 * It never removes a widget, a menu or a menu item. Every write is an
 * addition, and running it twice does nothing the second time.
 */

if (!defined('ABSPATH')) {
    exit;
}

const VC_PORT_OPTION = 'vc_rehub_port';
const VC_PORT_VERSION = '1.0.0';

/* -------------------------------------------------------------------------
 * The footer, as data
 *
 * Recovered from the live Astra footer on 2026-10-25, with the
 * ?customize_changeset_uuid=... query strings stripped -- three of the live
 * URLs had them, which means those links were copied out of the Customizer
 * preview and point at a draft view.
 *
 * Grouped more sensibly than the original, which had five policy links in
 * one column and two in another.
 * ---------------------------------------------------------------------- */

function vc_port_footer_plan() {
    return array(
        'footerfirst' => array(
            'title' => __('Company', 'vc-rehub-port'),
            'links' => array(
                array('About Us', '/about/'),
                array('Contact', '/contact/'),
                array('Advertise', '/advertise-vaping-cheap/'),
            ),
        ),
        'footersecond' => array(
            'title' => __('Editorial', 'vc-rehub-port'),
            'links' => array(
                array('Editorial Policy', '/editorial-policy/'),
                array('Review Policy', '/review-policy/'),
                array('Transparency Statement', '/transparency-statement/'),
            ),
        ),
        'footerthird' => array(
            'title' => __('Legal', 'vc-rehub-port'),
            'links' => array(
                array('Privacy Policy', '/privacy-policy/'),
                array('Terms &amp; Conditions', '/terms-and-conditions/'),
                array('Health &amp; Legal Disclaimer', '/health-legal-disclaimer/'),
                array('Youth Prevention Policy', '/youth-prevention-policy/'),
                array('Prop 65 Warnings', 'https://www.p65warnings.ca.gov/'),
            ),
        ),
    );
}

/**
 * ReHub's footer widget areas, read from the theme's own
 * register_sidebar() calls rather than guessed.
 *
 * ReHub registers no footer *menu* location -- only primary, top and
 * logged-in -- which is why the footer navigation has to be widgets.
 */
function vc_port_footer_sidebars() {
    return array('footerfirst', 'footersecond', 'footerthird', 'footercustom');
}

function vc_port_settings() {
    $saved = get_option(VC_PORT_OPTION, array());

    return wp_parse_args(is_array($saved) ? $saved : array(), array(
        'footer_type'  => 0,   // restore Astra-ish footer text size
        'footer_px'    => 15,
        'full_width'   => 0,   // drop ReHub's boxed content container
    ));
}

/* -------------------------------------------------------------------------
 * Building the footer
 * ---------------------------------------------------------------------- */

/**
 * A menu item pointing at a real page where one exists, a plain link
 * otherwise.
 *
 * Resolving to the page means renaming or re-slugging it later keeps the
 * footer link working; a hard-coded URL would quietly 404.
 */
function vc_port_menu_item_args($label, $url) {
    $args = array(
        'menu-item-title'  => wp_specialchars_decode($label),
        'menu-item-status' => 'publish',
    );

    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $host = (string) parse_url($url, PHP_URL_HOST);
    $internal = ($host === '' || $host === (string) parse_url(home_url(), PHP_URL_HOST));

    if ($internal && $path !== '') {
        $page = get_page_by_path($path);
        if ($page) {
            $args['menu-item-type'] = 'post_type';
            $args['menu-item-object'] = $page->post_type;
            $args['menu-item-object-id'] = (int) $page->ID;

            return $args;
        }
    }

    $args['menu-item-type'] = 'custom';
    $args['menu-item-url'] = $internal ? home_url('/' . $path . '/') : $url;

    return $args;
}

/**
 * The next free index in a widget type's instance store.
 */
function vc_port_next_widget_index($type) {
    $instances = get_option('widget_' . $type, array());
    $max = 0;
    foreach (array_keys(is_array($instances) ? $instances : array()) as $key) {
        if (is_numeric($key)) {
            $max = max($max, (int) $key);
        }
    }

    return $max + 1;
}

/**
 * Does this sidebar already hold a nav_menu widget pointing at this menu?
 */
function vc_port_sidebar_has_menu($sidebar, $menu_id) {
    $sidebars = get_option('sidebars_widgets', array());
    $instances = get_option('widget_nav_menu', array());

    foreach ((array) ($sidebars[$sidebar] ?? array()) as $widget_id) {
        if (strpos((string) $widget_id, 'nav_menu-') !== 0) {
            continue;
        }
        $index = (int) substr($widget_id, strlen('nav_menu-'));
        if ((int) ($instances[$index]['nav_menu'] ?? 0) === (int) $menu_id) {
            return true;
        }
    }

    return false;
}

/**
 * Put a Navigation Menu widget in a sidebar, with a title.
 *
 * The title is the heading: ReHub styles .footer_widget .widget .title at
 * 18px, which is where the footer column headings come from. Appended, so
 * anything already in that sidebar is left exactly as it is.
 */
function vc_port_place_menu_widget($sidebar, $menu_id, $title, $dry_run = false) {
    if (vc_port_sidebar_has_menu($sidebar, $menu_id)) {
        return 'already there';
    }
    if ($dry_run) {
        return 'would add';
    }

    $index = vc_port_next_widget_index('nav_menu');

    $instances = get_option('widget_nav_menu', array());
    if (!is_array($instances)) {
        $instances = array();
    }
    $instances[$index] = array('title' => $title, 'nav_menu' => (int) $menu_id);
    $instances['_multiwidget'] = 1;
    update_option('widget_nav_menu', $instances);

    $sidebars = get_option('sidebars_widgets', array());
    if (!is_array($sidebars)) {
        $sidebars = array();
    }
    if (!isset($sidebars[$sidebar]) || !is_array($sidebars[$sidebar])) {
        $sidebars[$sidebar] = array();
    }
    $sidebars[$sidebar][] = 'nav_menu-' . $index;
    update_option('sidebars_widgets', $sidebars);

    return 'added';
}

/**
 * Build the footer. Returns a list of lines describing what happened.
 */
function vc_port_build_footer($dry_run = true) {
    $report = array();

    foreach (vc_port_footer_plan() as $sidebar => $group) {
        $name = $group['title'];
        $menu = wp_get_nav_menu_object($name);

        if (!$menu) {
            if ($dry_run) {
                $report[] = sprintf(
                    /* translators: 1: menu name, 2: number of links */
                    __('Would create the menu "%1$s" with %2$d links.', 'vc-rehub-port'),
                    $name, count($group['links']));
            } else {
                $menu_id = wp_create_nav_menu($name);
                if (is_wp_error($menu_id)) {
                    $report[] = sprintf(
                        __('Could not create the menu "%1$s": %2$s', 'vc-rehub-port'),
                        $name, $menu_id->get_error_message());
                    continue;
                }
                $menu = wp_get_nav_menu_object($menu_id);
                foreach ($group['links'] as $link) {
                    wp_update_nav_menu_item((int) $menu->term_id, 0,
                        vc_port_menu_item_args($link[0], $link[1]));
                }
                $report[] = sprintf(
                    __('Created the menu "%1$s" with %2$d links.', 'vc-rehub-port'),
                    $name, count($group['links']));
            }
        } else {
            $report[] = sprintf(
                __('The menu "%s" already exists - left alone.', 'vc-rehub-port'), $name);
        }

        if (!$menu) {
            $report[] = sprintf(
                __('%1$s: cannot place a widget until the menu exists.', 'vc-rehub-port'),
                $sidebar);
            continue;
        }

        $what = vc_port_place_menu_widget($sidebar, (int) $menu->term_id, $name, $dry_run);
        $report[] = sprintf(
            /* translators: 1: sidebar id, 2: menu name, 3: outcome */
            __('%1$s: "%2$s" widget - %3$s.', 'vc-rehub-port'),
            $sidebar, $name, $what);
    }

    return $report;
}

/* -------------------------------------------------------------------------
 * The CSS differences between the two themes
 *
 * Two, and only two, are worth overriding globally. Both are ReHub design
 * decisions rather than faults, so both are off by default and switched on
 * deliberately.
 * ---------------------------------------------------------------------- */

function vc_port_css() {
    $settings = vc_port_settings();
    $css = '';

    if (!empty($settings['footer_type'])) {
        // ReHub sets .footer-bottom .footer_widget to 14px and its list
        // items to 13px. Astra inherited the body size, usually 16px.
        $px = max(11, min(20, (int) $settings['footer_px']));
        $css .= '.footer-bottom .footer_widget,'
             . '.footer-bottom .footer_widget p{font-size:' . $px . 'px;line-height:1.6;}'
             . '.footer-bottom .footer_widget ul li,'
             . '.footer-bottom .footer_widget .widget_categories ul li,'
             . '.footer-bottom .footer_widget .widget_nav_menu ul li{font-size:' . $px . 'px;}';
    }

    if (!empty($settings['full_width'])) {
        // The "box" around the content is ReHub's rh-container. Astra was
        // running ast-full-width-layout with no container at all.
        $css .= '.rh-container{max-width:100%;width:100%;}';
    }

    return $css;
}

add_action('wp_head', function () {
    $css = vc_port_css();
    if ($css !== '') {
        echo "\n<style id=\"vc-rehub-port\">" . $css . "</style>\n";
    }
}, 99);

/* -------------------------------------------------------------------------
 * What still has to be done by hand
 * ---------------------------------------------------------------------- */

/**
 * Plugins this site's pages depend on, and whether they are running.
 *
 * Checked by path rather than by name: a deactivated plugin stops
 * enqueuing its assets, which is what left the homepage with Spectra
 * markup and no Spectra styles.
 */
function vc_port_plugin_state() {
    $checks = array(
        'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php' => array(
            'Spectra (Ultimate Addons for Gutenberg)',
            __('The homepage is built with its blocks. Without it the markup stays and the styling does not, which is why icons render at full size.', 'vc-rehub-port'),
        ),
        'wp-coupons-and-deals/wp-coupons-and-deals.php' => array(
            'WP Coupon & Deals',
            __('Supplies the coupon cards on every store page. Without it the coupon block renders empty.', 'vc-rehub-port'),
        ),
        'ap-plugin-scripteo/ap-plugin-scripteo.php' => array(
            'Ads Pro',
            __('Loaded, but it is placing no ad zones. Its placements were wired to Astra hooks and need redoing, or move the banners to ReHub\'s own ad slots.', 'vc-rehub-port'),
        ),
    );

    $out = array();
    foreach ($checks as $file => $about) {
        $out[] = array(
            'name'   => $about[0],
            'why'    => $about[1],
            'active' => function_exists('is_plugin_active') && is_plugin_active($file),
        );
    }

    return $out;
}

/**
 * ReHub's own ad slots, and whether anything is in them.
 *
 * Read straight out of rehub_option. These are the fields to paste banner
 * code into now that Astra's hooks are gone.
 */
function vc_port_ad_slots() {
    $option = get_option('rehub_option', array());
    $option = is_array($option) ? $option : array();

    $slots = array(
        'rehub_ads_top'           => __('Theme Options -> Ads -> Top of site', 'vc-rehub-port'),
        'rehub_ads_megatop'       => __('Theme Options -> Ads -> Mega top', 'vc-rehub-port'),
        'rehub_ads_infooter'      => __('Theme Options -> Ads -> In footer', 'vc-rehub-port'),
        'rehub_single_after_title' => __('Theme Options -> Ads -> After post title', 'vc-rehub-port'),
        'rehub_single_before_post' => __('Theme Options -> Ads -> Before post content', 'vc-rehub-port'),
        'rehub_ads_coupon_area'   => __('Theme Options -> Ads -> Coupon area', 'vc-rehub-port'),
    );

    $out = array();
    foreach ($slots as $key => $where) {
        $out[] = array(
            'key'    => $key,
            'where'  => $where,
            'filled' => trim((string) ($option[$key] ?? '')) !== '',
        );
    }

    return $out;
}

function vc_port_footer_widget_counts() {
    $sidebars = get_option('sidebars_widgets', array());
    $out = array();
    foreach (vc_port_footer_sidebars() as $id) {
        $out[$id] = count((array) ($sidebars[$id] ?? array()));
    }
    $out['wp_inactive_widgets'] = count((array) ($sidebars['wp_inactive_widgets'] ?? array()));

    return $out;
}

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_theme_page(
        __('ReHub Port', 'vc-rehub-port'),
        __('ReHub Port', 'vc-rehub-port'),
        'manage_options',
        'vc-rehub-port',
        'vc_port_screen'
    );
});

function vc_port_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';
    $report = array();

    if (!empty($_POST['vc_port_nonce'])
        && wp_verify_nonce($_POST['vc_port_nonce'], 'vc_port_run')) {

        if (isset($_POST['vc_port_preview'])) {
            $report = vc_port_build_footer(true);
            $notice = __('Preview only - nothing was written.', 'vc-rehub-port');
        } elseif (isset($_POST['vc_port_build'])) {
            $report = vc_port_build_footer(false);
            $notice = __('Footer rebuilt.', 'vc-rehub-port');
        } elseif (isset($_POST['vc_port_css'])) {
            update_option(VC_PORT_OPTION, array(
                'footer_type' => empty($_POST['footer_type']) ? 0 : 1,
                'footer_px'   => max(11, min(20, (int) ($_POST['footer_px'] ?? 15))),
                'full_width'  => empty($_POST['full_width']) ? 0 : 1,
            ));
            $notice = __('Saved.', 'vc-rehub-port');
        }
    }

    $settings = vc_port_settings();
    $counts = vc_port_footer_widget_counts();
    $theme = wp_get_theme();
    $is_rehub = stripos((string) $theme->get('Name'), 'rehub') !== false
        || stripos((string) $theme->get_template(), 'rehub') !== false;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('ReHub Port', 'vc-rehub-port'); ?></h1>

        <p class="description" style="max-width:48em;">
            <?php esc_html_e('The site\'s content moved from Astra to ReHub; its settings did not. This rebuilds the parts that can be rebuilt from data and lists the rest. Delete this plugin when the migration is done - the menus and widgets it creates stay behind.', 'vc-rehub-port'); ?>
        </p>

        <?php if ($notice) : ?>
            <div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div>
        <?php endif; ?>

        <?php if (!$is_rehub) : ?>
            <div class="notice notice-warning"><p>
                <?php echo esc_html(sprintf(
                    __('The active theme is "%s", not ReHub. The footer widget areas this writes to belong to ReHub, so building the footer now would park the widgets in the inactive list.', 'vc-rehub-port'),
                    $theme->get('Name')
                )); ?>
            </p></div>
        <?php endif; ?>

        <?php if ($report) : ?>
            <h2><?php esc_html_e('What happened', 'vc-rehub-port'); ?></h2>
            <pre style="background:#fff;padding:12px;border:1px solid #ccd0d4;max-width:60em;overflow:auto;"><?php
                echo esc_html(implode("\n", $report));
            ?></pre>
        <?php endif; ?>

        <h2><?php esc_html_e('1. The footer', 'vc-rehub-port'); ?></h2>
        <p class="description" style="max-width:48em;">
            <?php esc_html_e('Three menus, placed as Navigation Menu widgets in ReHub\'s three footer areas. The widget title becomes the column heading, which ReHub styles at 18px. Nothing already in those areas is touched, and running it twice does nothing the second time.', 'vc-rehub-port'); ?>
        </p>

        <table class="widefat striped" style="max-width:40em;margin-bottom:1em;">
            <thead><tr>
                <th><?php esc_html_e('Footer area', 'vc-rehub-port'); ?></th>
                <th><?php esc_html_e('Widgets in it now', 'vc-rehub-port'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (vc_port_footer_sidebars() as $id) : ?>
                <tr><td><code><?php echo esc_html($id); ?></code></td>
                    <td><?php echo (int) $counts[$id]; ?></td></tr>
            <?php endforeach; ?>
                <tr><td><em><?php esc_html_e('Inactive widgets', 'vc-rehub-port'); ?></em></td>
                    <td><?php echo (int) $counts['wp_inactive_widgets']; ?>
                        <?php if ($counts['wp_inactive_widgets'] > 0) : ?>
                            <span class="description"> &mdash; <?php esc_html_e('left over from Astra; safe to delete once the footer looks right', 'vc-rehub-port'); ?></span>
                        <?php endif; ?>
                    </td></tr>
            </tbody>
        </table>

        <form method="post">
            <?php wp_nonce_field('vc_port_run', 'vc_port_nonce'); ?>
            <?php submit_button(__('Preview', 'vc-rehub-port'), 'secondary', 'vc_port_preview', false); ?>
            <?php submit_button(__('Rebuild the footer', 'vc-rehub-port'), 'primary', 'vc_port_build', false); ?>
        </form>

        <h2><?php esc_html_e('2. The two CSS differences', 'vc-rehub-port'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('vc_port_run', 'vc_port_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Footer text size', 'vc-rehub-port'); ?></th>
                    <td>
                        <label><input type="checkbox" name="footer_type" value="1"
                            <?php checked($settings['footer_type']); ?> />
                            <?php esc_html_e('Override ReHub\'s footer type size', 'vc-rehub-port'); ?></label>
                        <input type="number" name="footer_px" min="11" max="20" class="small-text"
                               value="<?php echo (int) $settings['footer_px']; ?>" /> px
                        <p class="description"><?php esc_html_e('ReHub sets footer text to 14px and footer list items to 13px. Astra inherited the body size, usually 16px.', 'vc-rehub-port'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Content container', 'vc-rehub-port'); ?></th>
                    <td>
                        <label><input type="checkbox" name="full_width" value="1"
                            <?php checked($settings['full_width']); ?> />
                            <?php esc_html_e('Remove the box around the content', 'vc-rehub-port'); ?></label>
                        <p class="description"><?php esc_html_e('The box is ReHub\'s rh-container; Astra ran full width with no container. There is also a width_layout setting in ReHub Theme Options, which is the cleaner place to change it if it does what you want.', 'vc-rehub-port'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save', 'vc-rehub-port'), 'primary', 'vc_port_css'); ?>
        </form>

        <h2><?php esc_html_e('3. What this cannot do for you', 'vc-rehub-port'); ?></h2>

        <h3><?php esc_html_e('Plugins the pages depend on', 'vc-rehub-port'); ?></h3>
        <table class="widefat striped" style="max-width:60em;margin-bottom:1.5em;">
            <tbody>
            <?php foreach (vc_port_plugin_state() as $p) : ?>
                <tr>
                    <td style="width:16em;"><strong><?php echo esc_html($p['name']); ?></strong></td>
                    <td style="width:7em;">
                        <?php if ($p['active']) : ?>
                            <span style="color:#0a5c33;font-weight:600;"><?php esc_html_e('active', 'vc-rehub-port'); ?></span>
                        <?php else : ?>
                            <span style="color:#8a2424;font-weight:600;"><?php esc_html_e('NOT active', 'vc-rehub-port'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="description"><?php echo esc_html($p['why']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3><?php esc_html_e('Banner ads: ReHub\'s own slots', 'vc-rehub-port'); ?></h3>
        <p class="description" style="max-width:48em;">
            <?php esc_html_e('Ads Pro is placing nothing because its placements hooked into Astra. Either redo them in Ads Pro, or paste the banner code straight into these ReHub fields - read out of your rehub_option settings, so this is their real state.', 'vc-rehub-port'); ?>
        </p>
        <table class="widefat striped" style="max-width:60em;">
            <thead><tr>
                <th><?php esc_html_e('Field', 'vc-rehub-port'); ?></th>
                <th><?php esc_html_e('Where', 'vc-rehub-port'); ?></th>
                <th><?php esc_html_e('State', 'vc-rehub-port'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach (vc_port_ad_slots() as $slot) : ?>
                <tr>
                    <td><code><?php echo esc_html($slot['key']); ?></code></td>
                    <td class="description"><?php echo esc_html($slot['where']); ?></td>
                    <td><?php echo $slot['filled']
                        ? esc_html__('has code', 'vc-rehub-port')
                        : esc_html__('empty', 'vc-rehub-port'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h3><?php esc_html_e('The homepage', 'vc-rehub-port'); ?></h3>
        <p class="description" style="max-width:48em;">
            <?php esc_html_e('Still an Astra page built with Spectra blocks. Activating Spectra makes it render correctly and is the right short-term move, but it is a page built for the old theme. Rebuilding it with ReHub\'s own modules is the end state, and nothing here can do that for you.', 'vc-rehub-port'); ?>
        </p>
    </div>
    <?php
}
