/**
 * Age Verification Frontend JavaScript
 */

(function($) {
    'use strict';

    // Add class to body to prevent scrolling
    $('body').addClass('age-verification-active');

    // Get settings from localized script
    var settings = ageVerificationSettings || {};
    var method = settings.method || 'simple_buttons';
    var minimumAge = parseInt(settings.minimumAge) || 21;
    var cookieDuration = parseInt(settings.cookieDuration) || 30;
    var redirectUrl = settings.redirectUrl || 'https://www.google.com';
    var underageMessage = settings.underageMessage || 'Sorry, you are not old enough to access this website.';

    /**
     * Set a cookie
     */
    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + (value || '') + expires + '; path=/';
    }

    /**
     * Calculate age from birthdate
     */
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

    /**
     * Show error message
     */
    function showError(message) {
        $('#age-verification-error')
            .html(message)
            .fadeIn();
    }

    /**
     * Hide error message
     */
    function hideError() {
        $('#age-verification-error').fadeOut();
    }

    /**
     * Grant access
     */
    function grantAccess() {
        setCookie('age_verified', '1', cookieDuration);
        $('#age-verification-overlay').fadeOut();
        $('#age-verification-modal').fadeOut();
        $('body').removeClass('age-verification-active');
    }

    /**
     * Deny access
     */
    function denyAccess(message) {
        showError(message || underageMessage);
        setTimeout(function() {
            window.location.href = redirectUrl;
        }, 3000);
    }

    /**
     * Handle simple buttons method
     */
    function handleSimpleButtons() {
        $('.age-verification-button-accept').on('click', function() {
            var verified = $(this).data('verified');
            if (verified === true || verified === 'true') {
                grantAccess();
            }
        });

        $('.age-verification-button-deny').on('click', function() {
            denyAccess();
        });
    }

    /**
     * Handle slider method
     */
    function handleSlider() {
        var $slider = $('#age-slider');
        var $displayValue = $('#age-slider-display-value');

        // Update display value when slider changes
        $slider.on('input', function() {
            var value = $(this).val();
            $displayValue.text(value);
        });

        // Handle confirmation
        $('.age-verification-button-accept[data-method="slider"]').on('click', function() {
            hideError();
            var age = parseInt($slider.val());

            if (age >= minimumAge) {
                grantAccess();
            } else {
                denyAccess();
            }
        });

        // Handle cancel
        $('.age-verification-button-deny').on('click', function() {
            denyAccess();
        });
    }

    /**
     * Handle birthdate method
     */
    function handleBirthdate() {
        // Handle verification
        $('.age-verification-button-accept[data-method="birthdate"]').on('click', function() {
            hideError();

            var month = $('#birth-month').val();
            var day = $('#birth-day').val();
            var year = $('#birth-year').val();

            // Validate inputs
            if (!month || !day || !year) {
                showError('Please enter your complete birthdate.');
                return;
            }

            // Validate date
            var date = new Date(year, month - 1, day);
            if (date.getMonth() + 1 !== parseInt(month)) {
                showError('Please enter a valid date.');
                return;
            }

            // Calculate age
            var age = calculateAge(year, month, day);

            if (age >= minimumAge) {
                grantAccess();
            } else {
                denyAccess();
            }
        });

        // Handle cancel
        $('.age-verification-button-deny').on('click', function() {
            denyAccess();
        });
    }

    /**
     * Initialize based on method
     */
    $(document).ready(function() {
        if (method === 'simple_buttons') {
            handleSimpleButtons();
        } else if (method === 'slider') {
            handleSlider();
        } else if (method === 'birthdate') {
            handleBirthdate();
        }

        // Prevent closing modal by clicking overlay
        $('#age-verification-overlay').on('click', function(e) {
            e.preventDefault();
            return false;
        });

        // Prevent right-click on modal
        $('#age-verification-modal').on('contextmenu', function(e) {
            e.preventDefault();
            return false;
        });

        // Prevent escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                e.preventDefault();
                return false;
            }
        });
    });

})(jQuery);
