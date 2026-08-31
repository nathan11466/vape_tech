/**
 * Age Verification Admin JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        /**
         * Initialize color pickers
         */
        if ($.fn.wpColorPicker) {
            $('.color-picker').wpColorPicker();
        }

        /**
         * Update opacity value display
         */
        $('#age_verification_overlay_opacity').on('input', function() {
            var value = $(this).val();
            $('#overlay_opacity_value').text(value + '%');
        });

        /**
         * Show/hide settings based on verification method
         */
        function toggleMethodSettings() {
            var method = $('#age_verification_method').val();

            // This could be extended to show/hide method-specific settings
            // For now, all settings are visible for all methods
        }

        $('#age_verification_method').on('change', toggleMethodSettings);
        toggleMethodSettings();

        /**
         * Validate minimum age
         */
        $('#age_verification_minimum_age').on('change', function() {
            var age = parseInt($(this).val());
            if (age < 1) {
                $(this).val(1);
            } else if (age > 100) {
                $(this).val(100);
            }
        });

        /**
         * Validate cookie duration
         */
        $('#age_verification_cookie_duration').on('change', function() {
            var duration = parseInt($(this).val());
            if (duration < 1) {
                $(this).val(1);
            } else if (duration > 365) {
                $(this).val(365);
            }
        });

        /**
         * Form validation before submit
         */
        $('form').on('submit', function(e) {
            var redirectUrl = $('#age_verification_redirect_url').val();

            if (redirectUrl && !isValidUrl(redirectUrl)) {
                alert('Please enter a valid redirect URL.');
                e.preventDefault();
                return false;
            }

            var logoUrl = $('#age_verification_logo_url').val();

            if (logoUrl && !isValidUrl(logoUrl)) {
                alert('Please enter a valid logo URL.');
                e.preventDefault();
                return false;
            }
        });

        /**
         * Validate URL format
         */
        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        }

        /**
         * Add settings saved notice enhancement
         */
        if (window.location.search.indexOf('settings-updated=true') > -1) {
            var notice = $('<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>');
            $('.wrap h1').after(notice);

            setTimeout(function() {
                notice.fadeOut();
            }, 3000);
        }

    });

})(jQuery);
