<?php
/**
 * Plugin Name: Age Verification & Privacy Compliance
 * Plugin URI: https://github.com/nathan11466/vape_tech
 * Description: A comprehensive age verification and GDPR/privacy compliance plugin with cookie consent management, multiple verification methods, and data protection tools.
 * Version: 2.1.0
 * Author: Your Name
 * Author URI: https://github.com/nathan11466
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: age-verification
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AGE_VERIFICATION_VERSION', '2.1.0');
define('AGE_VERIFICATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AGE_VERIFICATION_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once AGE_VERIFICATION_PLUGIN_DIR . 'includes/class-age-verification-settings.php';
require_once AGE_VERIFICATION_PLUGIN_DIR . 'includes/class-age-verification-frontend.php';
require_once AGE_VERIFICATION_PLUGIN_DIR . 'includes/class-age-verification-gdpr.php';
require_once AGE_VERIFICATION_PLUGIN_DIR . 'includes/class-age-verification-gdpr-frontend.php';

/**
 * Main Age Verification Class
 */
class Age_Verification {

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
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialize plugin
        add_action('plugins_loaded', array($this, 'init'));
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Initialize settings
        Age_Verification_Settings::get_instance();

        // Initialize GDPR settings
        Age_Verification_GDPR::get_instance();

        // Initialize frontend only if plugin is enabled
        if (get_option('age_verification_enabled', 1)) {
            Age_Verification_Frontend::get_instance();
        }

        // Initialize GDPR frontend if enabled
        if (get_option('av_cookie_consent_enabled', 0)) {
            Age_Verification_GDPR_Frontend::get_instance();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options for age verification
        $defaults = array(
            'age_verification_enabled' => 1,
            'age_verification_method' => 'simple_buttons',
            'age_verification_minimum_age' => 21,
            'age_verification_cookie_duration' => 30,
            'age_verification_headline' => 'Age Verification Required',
            'age_verification_message' => 'You must be of legal age to enter this website.',
            'age_verification_underage_message' => 'Sorry, you are not old enough to access this website.',
            'age_verification_redirect_url' => 'https://www.google.com',
            'age_verification_excluded_pages' => array(),
            'age_verification_background_overlay' => '#000000',
            'age_verification_overlay_opacity' => 90,
            'age_verification_modal_background' => '#ffffff',
            'age_verification_button_color' => '#4CAF50',
            'age_verification_button_text_color' => '#ffffff',
            'age_verification_deny_button_color' => '#f44336',
        );

        // Set default options for GDPR/Privacy
        $gdpr_defaults = array(
            'av_cookie_consent_enabled' => 0,
            'av_cookie_banner_position' => 'bottom',
            'av_cookie_banner_message' => 'We use cookies to ensure you get the best experience on our website. By continuing to browse, you agree to our use of cookies.',
            'av_privacy_policy_page' => 0,
            'av_cookie_policy_page' => 0,
            'av_cookies_necessary' => 'age_verified, PHPSESSID, wp-settings-*, wordpress_logged_in_*',
            'av_cookies_analytics' => '_ga, _gid, _gat, _ga_*',
            'av_cookies_marketing' => '_fbp, fr, IDE, test_cookie',
            'av_cookies_preferences' => 'av_cookie_consent',
            'av_enable_consent_log' => 1,
            'av_consent_expiry' => 365,
            'av_show_privacy_widget' => 1,
            'av_enable_dnt' => 0,
            'av_data_retention_days' => 730,
            'av_auto_delete_logs' => 0,
        );

        $all_defaults = array_merge($defaults, $gdpr_defaults);

        foreach ($all_defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }

        // Create GDPR consent table
        Age_Verification_GDPR::get_instance()->create_consent_table();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Cleanup if needed
    }
}

// Initialize the plugin
Age_Verification::get_instance();
