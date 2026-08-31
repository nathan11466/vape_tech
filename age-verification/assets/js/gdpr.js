/**
 * Age Verification GDPR & Cookie Consent JavaScript
 */

(function($) {
    'use strict';

    // Get settings from localized script
    var settings = avGdprSettings || {};
    var consentExpiry = parseInt(settings.consentExpiry) || 365;
    var enableDnt = settings.enableDnt || false;
    var enableLog = settings.enableLog || false;

    /**
     * Check if Do Not Track is enabled
     */
    function isDntEnabled() {
        return navigator.doNotTrack === '1' ||
               navigator.doNotTrack === 'yes' ||
               navigator.msDoNotTrack === '1' ||
               window.doNotTrack === '1';
    }

    /**
     * Get cookie value
     */
    function getCookie(name) {
        var value = '; ' + document.cookie;
        var parts = value.split('; ' + name + '=');
        if (parts.length === 2) {
            return parts.pop().split(';').shift();
        }
        return null;
    }

    /**
     * Set cookie
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
     * Delete cookie
     */
    function deleteCookie(name) {
        document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    }

    /**
     * Get current consent
     */
    function getConsent() {
        var consentCookie = getCookie('av_cookie_consent');
        if (consentCookie) {
            try {
                return JSON.parse(decodeURIComponent(consentCookie));
            } catch (e) {
                return null;
            }
        }
        return null;
    }

    /**
     * Save consent
     */
    function saveConsent(consent) {
        // Save cookie
        var consentJson = JSON.stringify(consent);
        setCookie('av_cookie_consent', encodeURIComponent(consentJson), consentExpiry);

        // Log consent via AJAX if enabled
        if (enableLog) {
            $.ajax({
                url: settings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'av_update_consent',
                    nonce: settings.nonce,
                    consent: consent
                }
            });
        }

        // Apply consent
        applyConsent(consent);

        // Hide banner
        $('#av-cookie-banner').fadeOut();
    }

    /**
     * Apply consent settings
     */
    function applyConsent(consent) {
        // Block/unblock cookies based on consent
        if (!consent.analytics) {
            blockCookieCategory('analytics');
        }
        if (!consent.marketing) {
            blockCookieCategory('marketing');
        }
        if (!consent.preferences) {
            blockCookieCategory('preferences');
        }

        // Trigger custom event for other scripts
        $(document).trigger('av_consent_updated', [consent]);
    }

    /**
     * Block cookies from a category
     */
    function blockCookieCategory(category) {
        // Get cookie list for this category
        var cookieList = [];

        if (category === 'analytics') {
            cookieList = '_ga,_gid,_gat,_ga_*'.split(',');
        } else if (category === 'marketing') {
            cookieList = '_fbp,fr,IDE,test_cookie'.split(',');
        } else if (category === 'preferences') {
            cookieList = 'av_cookie_consent'.split(',');
        }

        // Delete cookies
        cookieList.forEach(function(cookieName) {
            cookieName = cookieName.trim();

            // Handle wildcards
            if (cookieName.includes('*')) {
                var prefix = cookieName.replace('*', '');
                var allCookies = document.cookie.split(';');

                allCookies.forEach(function(cookie) {
                    var cookieKey = cookie.split('=')[0].trim();
                    if (cookieKey.startsWith(prefix)) {
                        deleteCookie(cookieKey);
                    }
                });
            } else {
                deleteCookie(cookieName);
            }
        });
    }

    /**
     * Accept all cookies
     */
    function acceptAll() {
        var consent = {
            necessary: true,
            analytics: true,
            marketing: true,
            preferences: true,
            timestamp: new Date().toISOString()
        };
        saveConsent(consent);
    }

    /**
     * Save selected preferences
     */
    function savePreferences() {
        var consent = {
            necessary: true, // Always true
            analytics: $('#av-cookie-settings-modal input[data-category="analytics"]').is(':checked'),
            marketing: $('#av-cookie-settings-modal input[data-category="marketing"]').is(':checked'),
            preferences: $('#av-cookie-settings-modal input[data-category="preferences"]').is(':checked'),
            timestamp: new Date().toISOString()
        };
        saveConsent(consent);
        closeSettingsModal();
    }

    /**
     * Open settings modal
     */
    function openSettingsModal() {
        // Load current consent if exists
        var currentConsent = getConsent();
        if (currentConsent) {
            if (currentConsent.analytics) {
                $('#av-cookie-settings-modal input[data-category="analytics"]').prop('checked', true);
            }
            if (currentConsent.marketing) {
                $('#av-cookie-settings-modal input[data-category="marketing"]').prop('checked', true);
            }
            if (currentConsent.preferences) {
                $('#av-cookie-settings-modal input[data-category="preferences"]').prop('checked', true);
            }
        }

        $('#av-cookie-settings-modal').fadeIn();
        $('body').css('overflow', 'hidden');
    }

    /**
     * Close settings modal
     */
    function closeSettingsModal() {
        $('#av-cookie-settings-modal').fadeOut();
        $('body').css('overflow', '');
    }

    /**
     * Export user data
     */
    function exportUserData(ip) {
        $.ajax({
            url: settings.ajaxUrl,
            type: 'POST',
            data: {
                action: 'av_export_user_data',
                nonce: $('#export-user-data').data('nonce'),
                ip: ip
            },
            success: function(response) {
                if (response.success) {
                    // Download as JSON
                    var dataStr = JSON.stringify(response.data, null, 2);
                    var dataBlob = new Blob([dataStr], {type: 'application/json'});
                    var url = URL.createObjectURL(dataBlob);
                    var link = document.createElement('a');
                    link.href = url;
                    link.download = 'user-data-' + ip + '.json';
                    link.click();

                    $('#data-request-result').html('<div class="notice notice-success"><p>Data exported successfully!</p></div>');
                } else {
                    $('#data-request-result').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
            }
        });
    }

    /**
     * Delete user data
     */
    function deleteUserData(ip) {
        if (!confirm('Are you sure you want to delete all data for IP: ' + ip + '? This action cannot be undone.')) {
            return;
        }

        $.ajax({
            url: settings.ajaxUrl,
            type: 'POST',
            data: {
                action: 'av_delete_user_data',
                nonce: $('#delete-user-data').data('nonce'),
                ip: ip
            },
            success: function(response) {
                if (response.success) {
                    $('#data-request-result').html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                } else {
                    $('#data-request-result').html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
            }
        });
    }

    /**
     * Initialize
     */
    $(document).ready(function() {
        // Check if DNT is enabled and honor it
        if (enableDnt && isDntEnabled()) {
            var dntConsent = {
                necessary: true,
                analytics: false,
                marketing: false,
                preferences: false,
                timestamp: new Date().toISOString(),
                dnt: true
            };
            saveConsent(dntConsent);
            return; // Don't show banner
        }

        // Check if user has already given consent
        var existingConsent = getConsent();
        if (!existingConsent) {
            // Show cookie banner
            $('#av-cookie-banner').fadeIn();
        } else {
            // Apply existing consent
            applyConsent(existingConsent);
        }

        // Accept all button
        $('#av-cookie-accept-all, #av-cookie-accept-all-modal').on('click', function() {
            acceptAll();
        });

        // Cookie settings button
        $('#av-cookie-settings').on('click', function() {
            openSettingsModal();
        });

        // Save preferences button
        $('#av-cookie-save-preferences').on('click', function() {
            savePreferences();
        });

        // Close modal button
        $('.av-cookie-modal-close').on('click', function() {
            closeSettingsModal();
        });

        // Close modal on overlay click
        $('.av-cookie-modal-overlay').on('click', function() {
            closeSettingsModal();
        });

        // Privacy widget button
        $('#av-privacy-widget-toggle').on('click', function() {
            openSettingsModal();
        });

        // Admin: Export user data
        $('#export-user-data').on('click', function() {
            var ip = $('#export-ip').val().trim();
            if (!ip) {
                alert('Please enter an IP address');
                return;
            }
            $(this).data('nonce', $('input[name="_wpnonce"]').val());
            exportUserData(ip);
        });

        // Admin: Delete user data
        $('#delete-user-data').on('click', function() {
            var ip = $('#delete-ip').val().trim();
            if (!ip) {
                alert('Please enter an IP address');
                return;
            }
            $(this).data('nonce', $('input[name="_wpnonce"]').val());
            deleteUserData(ip);
        });

        // Prevent closing modal with Escape if no consent given
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#av-cookie-settings-modal').is(':visible')) {
                closeSettingsModal();
            }
        });
    });

})(jQuery);
