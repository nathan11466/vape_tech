<?php
/**
 * Age Verification Settings Class
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Age_Verification_Settings {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance of this class
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            __('Age Verification Settings', 'age-verification'),
            __('Age Verification', 'age-verification'),
            'manage_options',
            'age-verification',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // General Settings
        register_setting('age_verification_general', 'age_verification_enabled');
        register_setting('age_verification_general', 'age_verification_method');
        register_setting('age_verification_general', 'age_verification_minimum_age');
        register_setting('age_verification_general', 'age_verification_cookie_duration');
        register_setting('age_verification_general', 'age_verification_excluded_pages');

        // Content Settings
        register_setting('age_verification_content', 'age_verification_headline');
        register_setting('age_verification_content', 'age_verification_message');
        register_setting('age_verification_content', 'age_verification_underage_message');
        register_setting('age_verification_content', 'age_verification_redirect_url');
        register_setting('age_verification_content', 'age_verification_logo_url');

        // Styling Settings
        register_setting('age_verification_styling', 'age_verification_background_overlay');
        register_setting('age_verification_styling', 'age_verification_overlay_opacity');
        register_setting('age_verification_styling', 'age_verification_modal_background');
        register_setting('age_verification_styling', 'age_verification_button_color');
        register_setting('age_verification_styling', 'age_verification_button_text_color');
        register_setting('age_verification_styling', 'age_verification_deny_button_color');
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_age-verification') {
            return;
        }

        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        wp_enqueue_style(
            'age-verification-admin',
            AGE_VERIFICATION_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            AGE_VERIFICATION_VERSION
        );

        wp_enqueue_script(
            'age-verification-admin',
            AGE_VERIFICATION_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery', 'wp-color-picker'),
            AGE_VERIFICATION_VERSION,
            true
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get current tab
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=age-verification&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('General', 'age-verification'); ?>
                </a>
                <a href="?page=age-verification&tab=content" class="nav-tab <?php echo $active_tab === 'content' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Content', 'age-verification'); ?>
                </a>
                <a href="?page=age-verification&tab=styling" class="nav-tab <?php echo $active_tab === 'styling' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Styling', 'age-verification'); ?>
                </a>
            </h2>

            <form method="post" action="options.php">
                <?php
                if ($active_tab === 'general') {
                    settings_fields('age_verification_general');
                    $this->render_general_settings();
                } elseif ($active_tab === 'content') {
                    settings_fields('age_verification_content');
                    $this->render_content_settings();
                } elseif ($active_tab === 'styling') {
                    settings_fields('age_verification_styling');
                    $this->render_styling_settings();
                }
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render general settings
     */
    private function render_general_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="age_verification_enabled"><?php _e('Enable Age Verification', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="age_verification_enabled" name="age_verification_enabled" value="1" <?php checked(1, get_option('age_verification_enabled', 1)); ?> />
                    <p class="description"><?php _e('Enable or disable age verification on your site.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_method"><?php _e('Verification Method', 'age-verification'); ?></label>
                </th>
                <td>
                    <select id="age_verification_method" name="age_verification_method">
                        <option value="simple_buttons" <?php selected('simple_buttons', get_option('age_verification_method', 'simple_buttons')); ?>>
                            <?php _e('Simple Buttons (Yes/No)', 'age-verification'); ?>
                        </option>
                        <option value="slider" <?php selected('slider', get_option('age_verification_method', 'simple_buttons')); ?>>
                            <?php _e('Age Slider', 'age-verification'); ?>
                        </option>
                        <option value="birthdate" <?php selected('birthdate', get_option('age_verification_method', 'simple_buttons')); ?>>
                            <?php _e('Birthdate Entry', 'age-verification'); ?>
                        </option>
                    </select>
                    <p class="description"><?php _e('Choose how visitors will verify their age.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_minimum_age"><?php _e('Minimum Age', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="number" id="age_verification_minimum_age" name="age_verification_minimum_age" value="<?php echo esc_attr(get_option('age_verification_minimum_age', 21)); ?>" min="1" max="100" />
                    <p class="description"><?php _e('Minimum age required to access the site.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_cookie_duration"><?php _e('Cookie Duration (days)', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="number" id="age_verification_cookie_duration" name="age_verification_cookie_duration" value="<?php echo esc_attr(get_option('age_verification_cookie_duration', 30)); ?>" min="1" max="365" />
                    <p class="description"><?php _e('How long to remember the visitor after they verify their age.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_excluded_pages"><?php _e('Excluded Pages', 'age-verification'); ?></label>
                </th>
                <td>
                    <?php
                    $pages = get_pages();
                    $excluded_pages = get_option('age_verification_excluded_pages', array());
                    if (!is_array($excluded_pages)) {
                        $excluded_pages = array();
                    }
                    ?>
                    <select id="age_verification_excluded_pages" name="age_verification_excluded_pages[]" multiple size="10" style="width: 300px;">
                        <?php foreach ($pages as $page) : ?>
                            <option value="<?php echo esc_attr($page->ID); ?>" <?php echo in_array($page->ID, $excluded_pages) ? 'selected' : ''; ?>>
                                <?php echo esc_html($page->post_title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e('Select pages to exclude from age verification. Hold Ctrl (Cmd on Mac) to select multiple.', 'age-verification'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render content settings
     */
    private function render_content_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="age_verification_headline"><?php _e('Headline', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="text" id="age_verification_headline" name="age_verification_headline" value="<?php echo esc_attr(get_option('age_verification_headline', 'Age Verification Required')); ?>" class="regular-text" />
                    <p class="description"><?php _e('The main headline displayed on the verification modal.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_message"><?php _e('Message', 'age-verification'); ?></label>
                </th>
                <td>
                    <textarea id="age_verification_message" name="age_verification_message" rows="4" class="large-text"><?php echo esc_textarea(get_option('age_verification_message', 'You must be of legal age to enter this website.')); ?></textarea>
                    <p class="description"><?php _e('The message shown to visitors before they verify.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_underage_message"><?php _e('Underage Message', 'age-verification'); ?></label>
                </th>
                <td>
                    <textarea id="age_verification_underage_message" name="age_verification_underage_message" rows="3" class="large-text"><?php echo esc_textarea(get_option('age_verification_underage_message', 'Sorry, you are not old enough to access this website.')); ?></textarea>
                    <p class="description"><?php _e('Message shown when visitor is underage.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_redirect_url"><?php _e('Redirect URL (Underage)', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="url" id="age_verification_redirect_url" name="age_verification_redirect_url" value="<?php echo esc_attr(get_option('age_verification_redirect_url', 'https://www.google.com')); ?>" class="regular-text" />
                    <p class="description"><?php _e('Where to redirect underage visitors.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_logo_url"><?php _e('Logo URL', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="url" id="age_verification_logo_url" name="age_verification_logo_url" value="<?php echo esc_attr(get_option('age_verification_logo_url', '')); ?>" class="regular-text" />
                    <p class="description"><?php _e('Optional: Add a logo to display on the verification modal.', 'age-verification'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render styling settings
     */
    private function render_styling_settings() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="age_verification_background_overlay"><?php _e('Overlay Background Color', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="text" id="age_verification_background_overlay" name="age_verification_background_overlay" value="<?php echo esc_attr(get_option('age_verification_background_overlay', '#000000')); ?>" class="color-picker" />
                    <p class="description"><?php _e('Background color of the overlay.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_overlay_opacity"><?php _e('Overlay Opacity', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="range" id="age_verification_overlay_opacity" name="age_verification_overlay_opacity" value="<?php echo esc_attr(get_option('age_verification_overlay_opacity', 90)); ?>" min="0" max="100" step="5" />
                    <span id="overlay_opacity_value"><?php echo esc_html(get_option('age_verification_overlay_opacity', 90)); ?>%</span>
                    <p class="description"><?php _e('Opacity of the overlay background (0-100%).', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_modal_background"><?php _e('Modal Background Color', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="text" id="age_verification_modal_background" name="age_verification_modal_background" value="<?php echo esc_attr(get_option('age_verification_modal_background', '#ffffff')); ?>" class="color-picker" />
                    <p class="description"><?php _e('Background color of the modal.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_button_color"><?php _e('Accept Button Color', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="text" id="age_verification_button_color" name="age_verification_button_color" value="<?php echo esc_attr(get_option('age_verification_button_color', '#4CAF50')); ?>" class="color-picker" />
                    <p class="description"><?php _e('Color of the accept/confirm button.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_button_text_color"><?php _e('Button Text Color', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="text" id="age_verification_button_text_color" name="age_verification_button_text_color" value="<?php echo esc_attr(get_option('age_verification_button_text_color', '#ffffff')); ?>" class="color-picker" />
                    <p class="description"><?php _e('Text color of buttons.', 'age-verification'); ?></p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="age_verification_deny_button_color"><?php _e('Deny Button Color', 'age-verification'); ?></label>
                </th>
                <td>
                    <input type="text" id="age_verification_deny_button_color" name="age_verification_deny_button_color" value="<?php echo esc_attr(get_option('age_verification_deny_button_color', '#f44336')); ?>" class="color-picker" />
                    <p class="description"><?php _e('Color of the deny/cancel button.', 'age-verification'); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }
}
