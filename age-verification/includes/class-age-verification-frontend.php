<?php
/**
 * Age Verification Frontend Class
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Age_Verification_Frontend {

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
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'render_modal'));
    }

    /**
     * Check if current page should show verification
     */
    private function should_show_verification() {
        // Check if user has already verified
        if (isset($_COOKIE['age_verified']) && $_COOKIE['age_verified'] === '1') {
            return false;
        }

        // Check if current page is excluded
        $excluded_pages = get_option('age_verification_excluded_pages', array());
        if (!is_array($excluded_pages)) {
            $excluded_pages = array();
        }

        if (is_page() && in_array(get_the_ID(), $excluded_pages)) {
            return false;
        }

        return true;
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        if (!$this->should_show_verification()) {
            return;
        }

        wp_enqueue_style(
            'age-verification-frontend',
            AGE_VERIFICATION_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            AGE_VERIFICATION_VERSION
        );

        wp_enqueue_script(
            'age-verification-frontend',
            AGE_VERIFICATION_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            AGE_VERIFICATION_VERSION,
            true
        );

        // Pass settings to JavaScript
        wp_localize_script('age-verification-frontend', 'ageVerificationSettings', array(
            'method' => get_option('age_verification_method', 'simple_buttons'),
            'minimumAge' => intval(get_option('age_verification_minimum_age', 21)),
            'cookieDuration' => intval(get_option('age_verification_cookie_duration', 30)),
            'redirectUrl' => esc_url(get_option('age_verification_redirect_url', 'https://www.google.com')),
            'underageMessage' => esc_html(get_option('age_verification_underage_message', 'Sorry, you are not old enough to access this website.')),
        ));
    }

    /**
     * Render the age verification modal
     */
    public function render_modal() {
        if (!$this->should_show_verification()) {
            return;
        }

        $method = get_option('age_verification_method', 'simple_buttons');
        $minimum_age = intval(get_option('age_verification_minimum_age', 21));
        $headline = get_option('age_verification_headline', 'Age Verification Required');
        $message = get_option('age_verification_message', 'You must be of legal age to enter this website.');
        $logo_url = get_option('age_verification_logo_url', '');

        // Get styling options
        $overlay_bg = get_option('age_verification_background_overlay', '#000000');
        $overlay_opacity = intval(get_option('age_verification_overlay_opacity', 90)) / 100;
        $modal_bg = get_option('age_verification_modal_background', '#ffffff');
        $button_color = get_option('age_verification_button_color', '#4CAF50');
        $button_text_color = get_option('age_verification_button_text_color', '#ffffff');
        $deny_button_color = get_option('age_verification_deny_button_color', '#f44336');

        ?>
        <div id="age-verification-overlay" style="background-color: <?php echo esc_attr($overlay_bg); ?>; opacity: <?php echo esc_attr($overlay_opacity); ?>;"></div>

        <div id="age-verification-modal" style="background-color: <?php echo esc_attr($modal_bg); ?>;">
            <div class="age-verification-content">
                <?php if (!empty($logo_url)) : ?>
                    <div class="age-verification-logo">
                        <img src="<?php echo esc_url($logo_url); ?>" alt="Logo" />
                    </div>
                <?php endif; ?>

                <h2 class="age-verification-headline"><?php echo esc_html($headline); ?></h2>
                <p class="age-verification-message"><?php echo esc_html($message); ?></p>

                <div class="age-verification-form">
                    <?php if ($method === 'simple_buttons') : ?>
                        <?php $this->render_simple_buttons($minimum_age, $button_color, $button_text_color, $deny_button_color); ?>
                    <?php elseif ($method === 'slider') : ?>
                        <?php $this->render_slider($minimum_age, $button_color, $button_text_color, $deny_button_color); ?>
                    <?php elseif ($method === 'birthdate') : ?>
                        <?php $this->render_birthdate($button_color, $button_text_color, $deny_button_color); ?>
                    <?php endif; ?>
                </div>

                <div id="age-verification-error" class="age-verification-error" style="display: none;"></div>
            </div>
        </div>

        <style>
            #age-verification-modal .age-verification-button-accept {
                background-color: <?php echo esc_attr($button_color); ?>;
                color: <?php echo esc_attr($button_text_color); ?>;
            }
            #age-verification-modal .age-verification-button-accept:hover {
                opacity: 0.9;
            }
            #age-verification-modal .age-verification-button-deny {
                background-color: <?php echo esc_attr($deny_button_color); ?>;
                color: <?php echo esc_attr($button_text_color); ?>;
            }
            #age-verification-modal .age-verification-button-deny:hover {
                opacity: 0.9;
            }
        </style>
        <?php
    }

    /**
     * Render simple buttons method
     */
    private function render_simple_buttons($minimum_age, $button_color, $button_text_color, $deny_button_color) {
        ?>
        <div class="age-verification-buttons">
            <button type="button" class="age-verification-button age-verification-button-accept" data-verified="true">
                <?php echo sprintf(__("I'm over %d", 'age-verification'), $minimum_age); ?>
            </button>
            <button type="button" class="age-verification-button age-verification-button-deny" data-verified="false">
                <?php echo sprintf(__("I'm under %d", 'age-verification'), $minimum_age); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Render slider method
     */
    private function render_slider($minimum_age, $button_color, $button_text_color, $deny_button_color) {
        ?>
        <div class="age-verification-slider-container">
            <label for="age-slider" class="age-verification-label">
                <?php _e('Select your age:', 'age-verification'); ?>
            </label>
            <div class="age-slider-wrapper">
                <span class="age-slider-value">13</span>
                <input type="range" id="age-slider" class="age-slider" min="13" max="100" value="13" step="1" />
                <span class="age-slider-max">100</span>
            </div>
            <div class="age-slider-display">
                <span><?php _e('Age:', 'age-verification'); ?></span>
                <span id="age-slider-display-value">13</span>
            </div>
            <div class="age-verification-buttons">
                <button type="button" class="age-verification-button age-verification-button-accept" data-method="slider">
                    <?php _e('Confirm', 'age-verification'); ?>
                </button>
                <button type="button" class="age-verification-button age-verification-button-deny" data-verified="false">
                    <?php _e('Cancel', 'age-verification'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render birthdate method
     */
    private function render_birthdate($button_color, $button_text_color, $deny_button_color) {
        ?>
        <div class="age-verification-birthdate-container">
            <label class="age-verification-label">
                <?php _e('Enter your birthdate:', 'age-verification'); ?>
            </label>
            <div class="birthdate-inputs">
                <div class="birthdate-field">
                    <label for="birth-month"><?php _e('Month', 'age-verification'); ?></label>
                    <select id="birth-month" class="birthdate-input">
                        <option value=""><?php _e('Month', 'age-verification'); ?></option>
                        <?php for ($i = 1; $i <= 12; $i++) : ?>
                            <option value="<?php echo $i; ?>"><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="birthdate-field">
                    <label for="birth-day"><?php _e('Day', 'age-verification'); ?></label>
                    <select id="birth-day" class="birthdate-input">
                        <option value=""><?php _e('Day', 'age-verification'); ?></option>
                        <?php for ($i = 1; $i <= 31; $i++) : ?>
                            <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="birthdate-field">
                    <label for="birth-year"><?php _e('Year', 'age-verification'); ?></label>
                    <select id="birth-year" class="birthdate-input">
                        <option value=""><?php _e('Year', 'age-verification'); ?></option>
                        <?php
                        $current_year = date('Y');
                        for ($i = $current_year; $i >= $current_year - 100; $i--) :
                        ?>
                            <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
            <div class="age-verification-buttons">
                <button type="button" class="age-verification-button age-verification-button-accept" data-method="birthdate">
                    <?php _e('Verify', 'age-verification'); ?>
                </button>
                <button type="button" class="age-verification-button age-verification-button-deny" data-verified="false">
                    <?php _e('Cancel', 'age-verification'); ?>
                </button>
            </div>
        </div>
        <?php
    }
}
