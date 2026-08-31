<?php
/**
 * Age Verification GDPR Frontend Class
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Age_Verification_GDPR_Frontend {

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
        add_action('wp_enqueue_scripts', array($this, 'enqueue_gdpr_assets'));
        add_action('wp_footer', array($this, 'render_cookie_banner'));
        add_action('wp_footer', array($this, 'render_privacy_widget'));
    }

    /**
     * Check if user has given consent
     */
    private function has_consent() {
        return isset($_COOKIE['av_cookie_consent']);
    }

    /**
     * Enqueue GDPR assets
     */
    public function enqueue_gdpr_assets() {
        if (!get_option('av_cookie_consent_enabled', 0)) {
            return;
        }

        wp_enqueue_style(
            'age-verification-gdpr',
            AGE_VERIFICATION_PLUGIN_URL . 'assets/css/gdpr.css',
            array(),
            AGE_VERIFICATION_VERSION
        );

        wp_enqueue_script(
            'age-verification-gdpr',
            AGE_VERIFICATION_PLUGIN_URL . 'assets/js/gdpr.js',
            array('jquery'),
            AGE_VERIFICATION_VERSION,
            true
        );

        // Pass settings to JavaScript
        wp_localize_script('age-verification-gdpr', 'avGdprSettings', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('av-consent-nonce'),
            'consentExpiry' => intval(get_option('av_consent_expiry', 365)),
            'enableDnt' => get_option('av_enable_dnt', 0),
            'enableLog' => get_option('av_enable_consent_log', 1),
        ));
    }

    /**
     * Render cookie consent banner
     */
    public function render_cookie_banner() {
        if (!get_option('av_cookie_consent_enabled', 0)) {
            return;
        }

        if ($this->has_consent()) {
            return;
        }

        $position = get_option('av_cookie_banner_position', 'bottom');
        $message = get_option('av_cookie_banner_message', 'We use cookies to ensure you get the best experience on our website. By continuing to browse, you agree to our use of cookies.');
        $privacy_page = get_option('av_privacy_policy_page', 0);
        $cookie_page = get_option('av_cookie_policy_page', 0);

        $privacy_link = $privacy_page ? get_permalink($privacy_page) : '';
        $cookie_link = $cookie_page ? get_permalink($cookie_page) : '';

        ?>
        <div id="av-cookie-banner" class="av-cookie-banner av-cookie-banner-<?php echo esc_attr($position); ?>" style="display: none;">
            <div class="av-cookie-banner-content">
                <div class="av-cookie-banner-message">
                    <p><?php echo esc_html($message); ?></p>
                    <div class="av-cookie-banner-links">
                        <?php if ($privacy_link) : ?>
                            <a href="<?php echo esc_url($privacy_link); ?>" target="_blank"><?php _e('Privacy Policy', 'age-verification'); ?></a>
                        <?php endif; ?>
                        <?php if ($cookie_link) : ?>
                            <a href="<?php echo esc_url($cookie_link); ?>" target="_blank"><?php _e('Cookie Policy', 'age-verification'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="av-cookie-banner-actions">
                    <button type="button" class="av-cookie-btn av-cookie-settings-btn" id="av-cookie-settings">
                        <?php _e('Cookie Settings', 'age-verification'); ?>
                    </button>
                    <button type="button" class="av-cookie-btn av-cookie-accept-btn" id="av-cookie-accept-all">
                        <?php _e('Accept All', 'age-verification'); ?>
                    </button>
                </div>
            </div>
        </div>

        <?php $this->render_cookie_settings_modal(); ?>
        <?php
    }

    /**
     * Render cookie settings modal
     */
    private function render_cookie_settings_modal() {
        ?>
        <div id="av-cookie-settings-modal" class="av-cookie-modal" style="display: none;">
            <div class="av-cookie-modal-overlay"></div>
            <div class="av-cookie-modal-content">
                <div class="av-cookie-modal-header">
                    <h2><?php _e('Cookie Preferences', 'age-verification'); ?></h2>
                    <button type="button" class="av-cookie-modal-close">&times;</button>
                </div>
                <div class="av-cookie-modal-body">
                    <p><?php _e('We use cookies to enhance your browsing experience and analyze our traffic. You can choose which categories of cookies you want to allow.', 'age-verification'); ?></p>

                    <div class="av-cookie-category">
                        <div class="av-cookie-category-header">
                            <label class="av-cookie-category-label">
                                <input type="checkbox" class="av-cookie-toggle" data-category="necessary" checked disabled />
                                <span class="av-cookie-category-title"><?php _e('Necessary Cookies', 'age-verification'); ?></span>
                                <span class="av-cookie-category-badge"><?php _e('Always Active', 'age-verification'); ?></span>
                            </label>
                        </div>
                        <div class="av-cookie-category-description">
                            <p><?php _e('These cookies are essential for the website to function properly. They enable core functionality such as security, network management, and accessibility.', 'age-verification'); ?></p>
                            <p class="av-cookie-list"><strong><?php _e('Cookies:', 'age-verification'); ?></strong> <?php echo esc_html(get_option('av_cookies_necessary', 'age_verified, PHPSESSID')); ?></p>
                        </div>
                    </div>

                    <div class="av-cookie-category">
                        <div class="av-cookie-category-header">
                            <label class="av-cookie-category-label">
                                <input type="checkbox" class="av-cookie-toggle" data-category="analytics" />
                                <span class="av-cookie-category-title"><?php _e('Analytics Cookies', 'age-verification'); ?></span>
                            </label>
                        </div>
                        <div class="av-cookie-category-description">
                            <p><?php _e('These cookies help us understand how visitors interact with our website by collecting and reporting information anonymously.', 'age-verification'); ?></p>
                            <p class="av-cookie-list"><strong><?php _e('Cookies:', 'age-verification'); ?></strong> <?php echo esc_html(get_option('av_cookies_analytics', '_ga, _gid, _gat')); ?></p>
                        </div>
                    </div>

                    <div class="av-cookie-category">
                        <div class="av-cookie-category-header">
                            <label class="av-cookie-category-label">
                                <input type="checkbox" class="av-cookie-toggle" data-category="marketing" />
                                <span class="av-cookie-category-title"><?php _e('Marketing Cookies', 'age-verification'); ?></span>
                            </label>
                        </div>
                        <div class="av-cookie-category-description">
                            <p><?php _e('These cookies are used to track visitors across websites to display relevant advertisements and marketing campaigns.', 'age-verification'); ?></p>
                            <p class="av-cookie-list"><strong><?php _e('Cookies:', 'age-verification'); ?></strong> <?php echo esc_html(get_option('av_cookies_marketing', '_fbp, fr, IDE')); ?></p>
                        </div>
                    </div>

                    <div class="av-cookie-category">
                        <div class="av-cookie-category-header">
                            <label class="av-cookie-category-label">
                                <input type="checkbox" class="av-cookie-toggle" data-category="preferences" />
                                <span class="av-cookie-category-title"><?php _e('Preference Cookies', 'age-verification'); ?></span>
                            </label>
                        </div>
                        <div class="av-cookie-category-description">
                            <p><?php _e('These cookies enable the website to remember choices you make and provide enhanced, personalized features.', 'age-verification'); ?></p>
                            <p class="av-cookie-list"><strong><?php _e('Cookies:', 'age-verification'); ?></strong> <?php echo esc_html(get_option('av_cookies_preferences', 'av_cookie_consent')); ?></p>
                        </div>
                    </div>
                </div>
                <div class="av-cookie-modal-footer">
                    <button type="button" class="av-cookie-btn av-cookie-accept-selected" id="av-cookie-save-preferences">
                        <?php _e('Save Preferences', 'age-verification'); ?>
                    </button>
                    <button type="button" class="av-cookie-btn av-cookie-accept-btn" id="av-cookie-accept-all-modal">
                        <?php _e('Accept All', 'age-verification'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render privacy widget
     */
    public function render_privacy_widget() {
        if (!get_option('av_show_privacy_widget', 1)) {
            return;
        }

        if (!get_option('av_cookie_consent_enabled', 0)) {
            return;
        }

        ?>
        <div id="av-privacy-widget" class="av-privacy-widget">
            <button type="button" class="av-privacy-widget-btn" id="av-privacy-widget-toggle" title="<?php _e('Privacy Settings', 'age-verification'); ?>">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L4 5V11.09C4 16.14 7.41 20.85 12 22C16.59 20.85 20 16.14 20 11.09V5L12 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
        <?php
    }
}
