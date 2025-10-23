# Age Verification & Privacy Compliance WordPress Plugin

A comprehensive dual-purpose plugin that handles both age verification and GDPR/privacy compliance for WordPress. Perfect for websites selling age-restricted products (vapes, alcohol, adult content) that also need to comply with privacy regulations.

## Features

### Age Verification
- **Multiple Verification Methods**
  - Simple Buttons: Quick "I'm over/under [age]" buttons
  - Age Slider: Interactive slider for selecting age (13-100)
  - Birthdate Entry: Full birthdate verification with month/day/year dropdowns

- **Comprehensive Settings**
  - Enable/disable age verification
  - Choose verification method
  - Set minimum age requirement (default: 21)
  - Configure cookie duration
  - Exclude specific pages from verification
  - Customizable messaging and styling
  - Full color customization

### GDPR & Privacy Compliance
- **Cookie Consent Banner**
  - Customizable position (top, bottom, modal)
  - Granular cookie category controls
  - "Accept All" and "Cookie Settings" options
  - Links to privacy and cookie policies
  - Fully customizable messaging

- **Cookie Categories**
  - Necessary (always enabled)
  - Analytics (Google Analytics, etc.)
  - Marketing (Facebook Pixel, ads, etc.)
  - Preferences (user settings)
  - Wildcard support for cookie patterns

- **Compliance Features**
  - Consent logging with audit trail
  - IP address and user agent tracking
  - Configurable consent expiry
  - Do Not Track (DNT) support
  - Data retention policies
  - Auto-delete old logs

- **Data Subject Rights (GDPR)**
  - Export user data by IP address
  - Delete user data by IP address
  - View consent logs with pagination
  - Downloadable consent records

- **User Experience**
  - Privacy preferences widget (floating button)
  - Cookie settings modal
  - Granular category controls
  - Responsive design
  - Dark mode support

## Installation

1. Download the plugin folder
2. Upload the `age-verification` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Configure age verification: Settings > Age Verification
5. Configure privacy/GDPR: Settings > Privacy & GDPR

## Configuration

### Age Verification Setup

1. Navigate to **Settings > Age Verification**
2. In the **General** tab:
   - Enable age verification
   - Select verification method (buttons, slider, or birthdate)
   - Set minimum age (default: 21)
   - Set cookie duration
   - Exclude specific pages

3. In the **Content** tab:
   - Customize headline and messages
   - Set underage redirect URL
   - Add optional logo

4. In the **Styling** tab:
   - Customize colors and opacity
   - Match your brand design

### Privacy & GDPR Setup

1. Navigate to **Settings > Privacy & GDPR**

2. **Cookie Consent Tab**:
   - Enable cookie consent banner
   - Choose banner position (bottom/top/modal)
   - Customize banner message
   - Link to privacy and cookie policy pages

3. **Cookie Management Tab**:
   - Define cookie categories
   - List cookies for each category
   - Use wildcards (e.g., `_ga_*`)
   - Separate with commas

4. **Compliance Tab**:
   - Enable consent logging (recommended)
   - Set consent expiry (default: 365 days)
   - Enable privacy widget
   - Honor Do Not Track
   - Configure data retention
   - Enable auto-delete old logs

5. **Data Requests Tab**:
   - Export user data by IP
   - Delete user data by IP
   - Handle GDPR data requests

6. **Consent Logs Tab**:
   - View all logged consents
   - Audit trail for compliance
   - Paginated results
   - Search and filter

## File Structure

```
age-verification/
├── age-verification.php                          # Main plugin file
├── README.md                                      # Documentation
├── includes/
│   ├── class-age-verification-settings.php       # Age verification settings
│   ├── class-age-verification-frontend.php       # Age verification frontend
│   ├── class-age-verification-gdpr.php           # GDPR backend
│   └── class-age-verification-gdpr-frontend.php  # GDPR frontend
└── assets/
    ├── css/
    │   ├── admin.css                              # Admin styling
    │   ├── frontend.css                           # Age verification modal
    │   └── gdpr.css                               # Cookie consent banner
    └── js/
        ├── admin.js                               # Admin functionality
        ├── frontend.js                            # Age verification logic
        └── gdpr.js                                # Cookie consent logic
```

## How It Works

### Age Verification Flow
1. Visitor arrives at site
2. Plugin checks for `age_verified` cookie
3. If no cookie, display verification modal
4. User verifies age using selected method
5. Plugin validates age against minimum
6. If valid, set cookie and grant access
7. If invalid, show error and redirect
8. Cookie prevents re-verification for configured duration

### Cookie Consent Flow
1. Visitor arrives at site
2. Plugin checks for `av_cookie_consent` cookie
3. If no cookie, display consent banner
4. User can "Accept All" or customize via "Cookie Settings"
5. User selects which cookie categories to allow
6. Consent is saved and logged (if enabled)
7. Non-consented cookies are blocked/deleted
8. Privacy widget allows changing preferences anytime

### GDPR Compliance
The plugin provides tools to comply with GDPR requirements:

1. **Lawful Basis**: Cookie consent before setting non-essential cookies
2. **Transparency**: Clear information about cookie usage
3. **User Control**: Granular category-level controls
4. **Data Access**: Export user data on request
5. **Right to Erasure**: Delete user data on request
6. **Audit Trail**: Consent logging for accountability
7. **Data Minimization**: Only essential data collected
8. **Retention**: Configurable data retention periods

## Cookie Information

### Age Verification
- **Name**: `age_verified`
- **Value**: `1` (when verified)
- **Duration**: Configurable (default: 30 days)
- **Purpose**: Remember verified visitors

### Privacy Consent
- **Name**: `av_cookie_consent`
- **Value**: JSON object with consent preferences
- **Duration**: Configurable (default: 365 days)
- **Purpose**: Remember cookie preferences

### Database Tables

The plugin creates one database table:

- **wp_av_consent_log**: Stores consent audit trail
  - `id`: Unique identifier
  - `ip_address`: Visitor IP
  - `user_agent`: Browser info
  - `consent_type`: Type of consent
  - `consent_value`: JSON consent data
  - `timestamp`: When consent was given

## GDPR Data Requests

### Exporting User Data

1. Go to Settings > Privacy & GDPR > Data Requests
2. Enter the visitor's IP address
3. Click "Export Data"
4. Download JSON file with all consent records

### Deleting User Data

1. Go to Settings > Privacy & GDPR > Data Requests
2. Enter the visitor's IP address
3. Click "Delete Data"
4. Confirm deletion
5. All consent records for that IP are permanently deleted

## Advanced Features

### Do Not Track (DNT)

When enabled, the plugin automatically:
- Detects DNT browser setting
- Blocks non-essential cookies
- Logs DNT-based consent
- Skips showing consent banner

### Cookie Blocking

The plugin actively blocks/deletes cookies based on consent:
- Checks cookie categories
- Deletes cookies when consent withdrawn
- Supports wildcard patterns
- Triggers on consent changes

### Consent Logging

Every consent action is logged with:
- IP address (for data requests)
- User agent (browser/device info)
- Consent type (cookie_consent, age_verification, etc.)
- Consent value (detailed preferences)
- Timestamp (ISO 8601 format)

### Privacy Widget

A floating button that allows users to:
- Re-open cookie preferences
- Change their consent choices
- View current settings
- Positioned bottom-left by default

## Customization

### Custom Styling

Override plugin CSS in your theme:

```css
/* Change cookie banner background */
.av-cookie-banner {
    background-color: #your-color !important;
}

/* Customize accept button */
.av-cookie-accept-btn {
    background-color: #your-brand-color !important;
}

/* Style privacy widget */
.av-privacy-widget-btn {
    background-color: #your-color !important;
    bottom: 30px !important;
    left: 30px !important;
}
```

### JavaScript Events

Listen for consent changes:

```javascript
jQuery(document).on('av_consent_updated', function(event, consent) {
    console.log('Consent updated:', consent);
    // {necessary: true, analytics: true, marketing: false, ...}

    // Load your scripts based on consent
    if (consent.analytics) {
        // Load Google Analytics
    }
    if (consent.marketing) {
        // Load Facebook Pixel
    }
});
```

### Cookie Category Management

Add custom cookies to categories in Settings > Privacy & GDPR > Cookie Management:

```
Necessary: age_verified, PHPSESSID, wp-settings-*, wordpress_logged_in_*
Analytics: _ga, _gid, _gat, _ga_*, _gat_*, __utma, __utmb
Marketing: _fbp, _fbq, fr, IDE, test_cookie, DSID
Preferences: av_cookie_consent, wp-wpml_current_language
```

## Frequently Asked Questions

### Q: Is this plugin GDPR compliant?
A: The plugin provides tools for GDPR compliance including consent management, data export/deletion, and audit logging. However, full GDPR compliance also depends on your privacy policy, data processing practices, and overall website configuration.

### Q: Does this work with caching plugins?
A: Yes, both age verification and cookie consent use client-side JavaScript and cookies, so they work with most caching plugins.

### Q: Can users bypass the age verification?
A: Tech-savvy users can manipulate cookies to bypass client-side verification. For legal compliance purposes, this is typically acceptable. For stricter requirements, consider server-side verification with account systems.

### Q: What happens to existing cookies when consent is withdrawn?
A: The plugin automatically deletes cookies from categories where consent was withdrawn.

### Q: How long are consent logs kept?
A: Configurable under Settings > Privacy & GDPR > Compliance. Default is 730 days (2 years).

### Q: Does this block Google Analytics automatically?
A: Yes, if analytics cookies are not consented to, the plugin will delete Google Analytics cookies. However, you should also conditionally load the GA script based on consent.

### Q: Can I customize which cookies are in each category?
A: Yes, go to Settings > Privacy & GDPR > Cookie Management and define your cookie lists.

### Q: What is the privacy widget?
A: A floating button that allows users to reopen cookie preferences and change their consent at any time.

### Q: How do I handle a GDPR data request?
A: Use Settings > Privacy & GDPR > Data Requests to export or delete user data by IP address.

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)
- Dark mode support

## Legal Disclaimer

This plugin provides technical tools for age verification and privacy compliance but does not constitute legal advice. You are responsible for:

1. Creating appropriate privacy and cookie policies
2. Ensuring compliance with applicable laws (GDPR, CCPA, COPPA, etc.)
3. Properly configuring the plugin for your jurisdiction
4. Regularly reviewing and updating consent mechanisms
5. Consulting with legal counsel for compliance requirements

The plugin helps facilitate compliance but does not guarantee it.

## Support

For issues, questions, or suggestions:
- Create an issue on GitHub
- Check existing issues for solutions
- Review code comments for implementation details

## Changelog

### Version 2.0.0
- Added comprehensive GDPR/privacy compliance features
- Cookie consent banner with granular controls
- Cookie categorization (necessary, analytics, marketing, preferences)
- Consent logging and audit trail
- Data export and deletion tools
- Privacy preferences widget
- Do Not Track support
- Data retention policies
- Admin dashboard for consent management
- View consent logs with pagination
- Updated UI and styling

### Version 1.0.0
- Initial release
- Three verification methods (Simple Buttons, Slider, Birthdate)
- Comprehensive admin settings
- Customizable styling
- Page exclusion feature
- Cookie-based verification
- Responsive design
- Multi-language ready

## License

GPL v2 or later

## Credits

Developed for age-restricted e-commerce websites with privacy compliance requirements.
