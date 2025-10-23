# Age Verification WordPress Plugin

A comprehensive and customizable age verification plugin for WordPress that restricts website access until visitors verify their age. Perfect for websites selling age-restricted products like vapes, alcohol, or adult content.

## Features

### Multiple Verification Methods
- **Simple Buttons**: Quick "I'm over/under [age]" buttons
- **Age Slider**: Interactive slider for selecting age
- **Birthdate Entry**: Full birthdate verification with month/day/year dropdowns

### Comprehensive Settings
- **General Settings**
  - Enable/disable age verification
  - Choose verification method
  - Set minimum age requirement (default: 21)
  - Configure cookie duration (how long verification is remembered)
  - Exclude specific pages from verification

- **Content Settings**
  - Customizable headline
  - Customizable verification message
  - Customizable underage message
  - Set redirect URL for underage visitors
  - Optional logo display

- **Styling Settings**
  - Overlay background color
  - Overlay opacity control
  - Modal background color
  - Accept button color
  - Button text color
  - Deny button color

### User-Friendly Features
- Modal cannot be closed without verification
- Prevents body scrolling when modal is active
- Cookie-based "remember me" functionality
- Responsive design for all devices
- Clean, modern interface
- Right-click prevention on modal
- Escape key prevention

## Installation

1. Download the plugin folder
2. Upload the `age-verification` folder to `/wp-content/plugins/`
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to Settings > Age Verification to configure

## Configuration

### Basic Setup

1. Navigate to **Settings > Age Verification** in your WordPress admin
2. In the **General** tab:
   - Ensure "Enable Age Verification" is checked
   - Select your preferred verification method
   - Set the minimum age (default is 21)
   - Set how long to remember verified users (in days)

3. In the **Content** tab:
   - Customize the headline and messages
   - Set where underage visitors should be redirected
   - Optionally add a logo URL

4. In the **Styling** tab:
   - Customize colors to match your brand
   - Adjust overlay opacity
   - Customize button colors

### Excluding Pages

To exclude specific pages from age verification (e.g., privacy policy, terms of service):

1. Go to **Settings > Age Verification**
2. Click the **General** tab
3. In the "Excluded Pages" dropdown, select the pages you want to exclude
4. Hold Ctrl (Cmd on Mac) to select multiple pages
5. Click "Save Changes"

## Verification Methods

### Simple Buttons
The easiest method for users. Displays two buttons:
- "I'm over [age]" - Grants access
- "I'm under [age]" - Denies access and redirects

### Age Slider
An interactive slider allowing users to select their age:
- User drags slider to select their age (13-100)
- Display shows selected age in real-time
- User clicks "Confirm" to verify
- Automatic validation against minimum age

### Birthdate Entry
The most secure method with full date validation:
- Three dropdowns for Month, Day, and Year
- Validates date correctness (e.g., no February 30th)
- Calculates exact age
- User clicks "Verify" to submit

## File Structure

```
age-verification/
├── age-verification.php                          # Main plugin file
├── README.md                                      # This file
├── includes/
│   ├── class-age-verification-settings.php       # Admin settings class
│   └── class-age-verification-frontend.php       # Frontend display class
└── assets/
    ├── css/
    │   ├── admin.css                              # Admin styling
    │   └── frontend.css                           # Frontend modal styling
    └── js/
        ├── admin.js                               # Admin functionality
        └── frontend.js                            # Verification logic
```

## How It Works

1. **First Visit**: When a visitor arrives at your site, the plugin checks for the `age_verified` cookie
2. **No Cookie**: If no cookie exists, the age verification modal is displayed
3. **Verification**: User verifies their age using the selected method
4. **Age Check**: Plugin validates the user's age against the minimum requirement
5. **Success**: If verified, a cookie is set for the configured duration and access is granted
6. **Failure**: If underage, an error message is shown and the user is redirected after 3 seconds
7. **Return Visits**: Cookie prevents re-verification for the configured duration

## Cookie Information

The plugin uses a single cookie:
- **Name**: `age_verified`
- **Value**: `1` (when verified)
- **Duration**: Configurable (default: 30 days)
- **Purpose**: Remember verified visitors

## Customization

### Changing Default Values

Edit the activation hook in `age-verification.php` to change default settings:

```php
public function activate() {
    $defaults = array(
        'age_verification_minimum_age' => 21,      // Change minimum age
        'age_verification_cookie_duration' => 30,  // Change cookie duration
        // ... other defaults
    );
}
```

### Custom Styling

Add custom CSS to override plugin styles. Use your theme's `style.css` or the WordPress Customizer:

```css
/* Example: Change modal border radius */
#age-verification-modal {
    border-radius: 20px !important;
}

/* Example: Change button hover effect */
.age-verification-button:hover {
    transform: scale(1.05) !important;
}
```

### Translations

The plugin is translation-ready with the text domain `age-verification`. Use a plugin like Loco Translate to create translations.

## Frequently Asked Questions

### Q: Will this affect my SEO?
A: The age verification is client-side only and doesn't prevent search engines from crawling your site. However, it will affect user experience metrics.

### Q: Can users bypass this?
A: Like all client-side verification, tech-savvy users can bypass it by manipulating cookies. This plugin is designed for legal compliance, not absolute security. For stricter requirements, consider server-side verification with account systems.

### Q: Does it work with caching plugins?
A: Yes, the plugin works with most caching plugins since it uses JavaScript and cookies for verification.

### Q: Can I use this for GDPR compliance?
A: This plugin is for age verification only, not GDPR compliance. For GDPR, you'll need a separate cookie consent plugin.

### Q: How do I reset my age verification?
A: Clear your browser cookies for the site, specifically the `age_verified` cookie.

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Support

For issues, questions, or suggestions:
- Create an issue on GitHub
- Check existing issues for solutions
- Review the code comments for implementation details

## License

GPL v2 or later

## Changelog

### Version 1.0.0
- Initial release
- Three verification methods (Simple Buttons, Slider, Birthdate)
- Comprehensive admin settings
- Customizable styling
- Page exclusion feature
- Cookie-based verification
- Responsive design
- Multi-language ready

## Credits

Developed for vape and age-restricted product websites.

## Disclaimer

This plugin provides age verification but does not guarantee complete age restriction. Users can potentially bypass client-side verification. This plugin should be used as part of a broader age verification strategy and in compliance with your local laws and regulations.
