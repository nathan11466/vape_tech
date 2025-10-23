<?php
/**
 * Plugin Name: Age Verification
 * Plugin URI: https://github.com/nathan11466/vape_tech
 * Description: A comprehensive age verification plugin with multiple verification methods (slider, birthdate, simple buttons) to restrict website access.
 * Version: 1.0.0
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
define('AGE_VERIFICATION_VERSION', '1.0.0');
define('AGE_VERIFICATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AGE_VERIFICATION_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once AGE_VERIFICATION_PLUGIN_DIR . 'includes/class-age-verification-settings.php';
require_once AGE_VERIFICATION_PLUGIN_DIR . 'includes/class-age-verification-frontend.php';

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

        // Initialize frontend only if plugin is enabled
        if (get_option('age_verification_enabled', 1)) {
            Age_Verification_Frontend::get_instance();
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
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

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
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
