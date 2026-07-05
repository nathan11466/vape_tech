<?php
/**
 * Age Verification Frontend Class
 *
 * v2.1.0: The modal's CSS and JavaScript are inlined with the markup and the
 * age_verified cookie is checked client-side. This keeps the age gate working
 * on sites with page caching (WP Rocket etc.) and CDN URL rewriting
 * (ShortPixel etc.), where external asset files or server-side cookie checks
 * are unreliable.
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
        add_action('wp_footer', array($this, 'render_modal'), 5);
    }

    /**
     * Check if current page should render the verification markup.
     *
     * Note: the cookie is NOT checked here on purpose. On cached pages PHP
     * never runs, so the server-side cookie check is unreliable. The markup
     * is always rendered (except on excluded pages) and the inline script
     * removes it instantly for verified visitors.
     */
    private function should_render_verification() {
        // Check if current page is excluded
        $excluded_pages = get_option('age_verification_excluded_pages', array());
        if (!is_array($excluded_pages)) {
            $excluded_pages = array();
        }

        if (is_page() && in_array(get_the_ID(), $excluded_pages)) {
            return false;
        }

        // Never block logged-in administrators
        if (current_user_can('manage_options')) {
            return false;
        }

        return true;
    }

    /**
     * Render the age verification modal with inline styles and script
     */
    public function render_modal() {
        if (!$this->should_render_verification()) {
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

        // Settings consumed by the inline script
        $js_settings = array(
            'method' => $method,
            'minimumAge' => $minimum_age,
            'cookieDuration' => intval(get_option('age_verification_cookie_duration', 30)),
            'redirectUrl' => esc_url(get_option('age_verification_redirect_url', 'https://www.google.com')),
            'underageMessage' => wp_strip_all_tags(get_option('age_verification_underage_message', 'Sorry, you are not old enough to access this website.')),
        );

        $this->render_inline_css($overlay_bg, $overlay_opacity, $modal_bg, $button_color, $button_text_color, $deny_button_color);
        ?>
        <div id="age-verification-overlay"></div>

        <div id="age-verification-modal">
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
                        <?php $this->render_simple_buttons($minimum_age); ?>
                    <?php elseif ($method === 'slider') : ?>
                        <?php $this->render_slider($minimum_age); ?>
                    <?php elseif ($method === 'birthdate') : ?>
                        <?php $this->render_birthdate(); ?>
                    <?php endif; ?>
                </div>

                <div id="age-verification-error" class="age-verification-error" style="display: none;"></div>
            </div>
        </div>

        <script id="age-verification-inline-js">
        (function() {
            'use strict';

            var settings = <?php echo wp_json_encode($js_settings); ?>;
            var overlay = document.getElementById('age-verification-overlay');
            var modal = document.getElementById('age-verification-modal');
            var errorBox = document.getElementById('age-verification-error');

            if (!overlay || !modal) {
                return;
            }

            function getCookie(name) {
                var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
                return match ? decodeURIComponent(match[1]) : null;
            }

            function setCookie(name, value, days) {
                var expires = new Date(Date.now() + days * 24 * 60 * 60 * 1000).toUTCString();
                document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
            }

            function removeGate() {
                if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
                if (modal.parentNode) { modal.parentNode.removeChild(modal); }
                document.body.classList.remove('age-verification-active');
            }

            // Cache-safe client-side check: if this visitor already verified,
            // remove the gate immediately (the HTML may come from a page cache).
            if (getCookie('age_verified') === '1') {
                removeGate();
                return;
            }

            document.body.classList.add('age-verification-active');

            function showError(text) {
                if (errorBox) {
                    errorBox.textContent = text;
                    errorBox.style.display = 'block';
                }
            }

            function hideError() {
                if (errorBox) {
                    errorBox.style.display = 'none';
                }
            }

            function grantAccess() {
                setCookie('age_verified', '1', settings.cookieDuration);
                removeGate();
            }

            function denyAccess() {
                showError(settings.underageMessage);
                setTimeout(function() {
                    window.location.href = settings.redirectUrl;
                }, 3000);
            }

            function calculateAge(year, month, day) {
                var today = new Date();
                var birthDate = new Date(year, month - 1, day);
                var age = today.getFullYear() - birthDate.getFullYear();
                var monthDiff = today.getMonth() - birthDate.getMonth();
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                    age--;
                }
                return age;
            }

            // Wire up accept buttons
            var acceptButtons = modal.querySelectorAll('.age-verification-button-accept');
            Array.prototype.forEach.call(acceptButtons, function(btn) {
                btn.addEventListener('click', function() {
                    hideError();
                    var btnMethod = btn.getAttribute('data-method');

                    if (btnMethod === 'slider') {
                        var slider = document.getElementById('age-slider');
                        var age = slider ? parseInt(slider.value, 10) : 0;
                        if (age >= settings.minimumAge) {
                            grantAccess();
                        } else {
                            denyAccess();
                        }
                        return;
                    }

                    if (btnMethod === 'birthdate') {
                        var month = document.getElementById('birth-month');
                        var day = document.getElementById('birth-day');
                        var year = document.getElementById('birth-year');
                        var m = month ? parseInt(month.value, 10) : NaN;
                        var d = day ? parseInt(day.value, 10) : NaN;
                        var y = year ? parseInt(year.value, 10) : NaN;

                        if (!m || !d || !y) {
                            showError('Please enter your complete birthdate.');
                            return;
                        }

                        var date = new Date(y, m - 1, d);
                        if (date.getMonth() + 1 !== m || date.getDate() !== d) {
                            showError('Please enter a valid date.');
                            return;
                        }

                        if (calculateAge(y, m, d) >= settings.minimumAge) {
                            grantAccess();
                        } else {
                            denyAccess();
                        }
                        return;
                    }

                    // Simple buttons: data-verified="true"
                    if (btn.getAttribute('data-verified') === 'true') {
                        grantAccess();
                    }
                });
            });

            // Wire up deny buttons
            var denyButtons = modal.querySelectorAll('.age-verification-button-deny');
            Array.prototype.forEach.call(denyButtons, function(btn) {
                btn.addEventListener('click', denyAccess);
            });

            // Live slider value display
            var slider = document.getElementById('age-slider');
            var sliderDisplay = document.getElementById('age-slider-display-value');
            if (slider && sliderDisplay) {
                slider.addEventListener('input', function() {
                    sliderDisplay.textContent = slider.value;
                });
            }
        })();
        </script>
        <?php
    }

    /**
     * Render inline critical CSS so the modal displays correctly even if
     * external stylesheets fail to load (CDN rewrites, 404s, caching).
     */
    private function render_inline_css($overlay_bg, $overlay_opacity, $modal_bg, $button_color, $button_text_color, $deny_button_color) {
        ?>
        <style id="age-verification-inline-css">
            #age-verification-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 999998;
                background-color: <?php echo esc_attr($overlay_bg); ?>;
                opacity: <?php echo esc_attr($overlay_opacity); ?>;
            }
            #age-verification-modal {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                z-index: 999999;
                max-width: 500px;
                width: 90%;
                padding: 40px 30px;
                border-radius: 10px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
                text-align: center;
                background-color: <?php echo esc_attr($modal_bg); ?>;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .age-verification-logo {
                margin-bottom: 20px;
                max-height: 120px;
                overflow: hidden;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .age-verification-logo img {
                max-width: 180px;
                max-height: 100px;
                width: auto;
                height: auto;
                object-fit: contain;
            }
            .age-verification-headline {
                font-size: 28px;
                font-weight: bold;
                margin: 0 0 15px 0;
                color: #333;
            }
            .age-verification-message {
                font-size: 16px;
                color: #666;
                margin: 0 0 30px 0;
                line-height: 1.5;
            }
            .age-verification-form {
                margin: 30px 0;
            }
            .age-verification-buttons {
                display: flex;
                gap: 15px;
                justify-content: center;
                flex-wrap: wrap;
            }
            .age-verification-button {
                padding: 15px 30px;
                font-size: 16px;
                font-weight: 600;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                transition: all 0.3s ease;
                min-width: 150px;
            }
            .age-verification-button:hover {
                opacity: 0.9;
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            }
            #age-verification-modal .age-verification-button-accept {
                background-color: <?php echo esc_attr($button_color); ?>;
                color: <?php echo esc_attr($button_text_color); ?>;
            }
            #age-verification-modal .age-verification-button-deny {
                background-color: <?php echo esc_attr($deny_button_color); ?>;
                color: <?php echo esc_attr($button_text_color); ?>;
            }
            .age-verification-label {
                display: block;
                font-size: 16px;
                font-weight: 600;
                color: #333;
                margin-bottom: 20px;
            }
            .age-slider-wrapper {
                display: flex;
                align-items: center;
                gap: 15px;
                margin-bottom: 15px;
            }
            .age-slider-value,
            .age-slider-max {
                font-size: 14px;
                font-weight: 600;
                color: #666;
                min-width: 30px;
            }
            .age-slider {
                flex: 1;
                height: 8px;
                border-radius: 5px;
                background: #ddd;
            }
            .age-slider-display {
                text-align: center;
                margin-bottom: 20px;
            }
            .age-slider-display span {
                font-size: 18px;
                color: #333;
            }
            #age-slider-display-value {
                font-weight: bold;
                font-size: 24px;
                margin-left: 5px;
            }
            .birthdate-inputs {
                display: flex;
                gap: 10px;
                justify-content: center;
                margin-bottom: 25px;
                flex-wrap: wrap;
            }
            .birthdate-field {
                display: flex;
                flex-direction: column;
                gap: 5px;
            }
            .birthdate-field label {
                font-size: 12px;
                font-weight: 600;
                color: #666;
                text-transform: uppercase;
            }
            .birthdate-input {
                padding: 10px;
                font-size: 14px;
                border: 2px solid #ddd;
                border-radius: 5px;
                background-color: #fff;
                color: #333;
                cursor: pointer;
            }
            .age-verification-error {
                margin-top: 20px;
                padding: 15px;
                background-color: #ffebee;
                color: #c62828;
                border-radius: 5px;
                font-size: 14px;
                font-weight: 600;
            }
            body.age-verification-active {
                overflow: hidden;
            }
            @media (max-width: 600px) {
                #age-verification-modal {
                    padding: 30px 20px;
                    max-width: 95%;
                }
                .age-verification-headline {
                    font-size: 24px;
                }
                .age-verification-message {
                    font-size: 14px;
                }
                .age-verification-button {
                    padding: 12px 20px;
                    font-size: 14px;
                    min-width: 120px;
                }
                .age-verification-logo img {
                    max-width: 150px;
                    max-height: 80px;
                }
                .birthdate-inputs {
                    flex-direction: column;
                    gap: 15px;
                }
                .birthdate-input {
                    width: 100%;
                }
            }
        </style>
        <?php
    }

    /**
     * Render simple buttons method
     */
    private function render_simple_buttons($minimum_age) {
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
    private function render_slider($minimum_age) {
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
    private function render_birthdate() {
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
