<?php
/**
 * Links to the site's own state vape-law pages.
 *
 * Those pages already exist at /vape-laws/<two-letter-abbreviation>/ -- one
 * for each of the fifty states and DC, matching our fifty-one ships_to
 * destination terms exactly.
 *
 * The legal note on a destination page is a one-paragraph summary with a
 * citation. The law page is the detail. Linking them is the obvious move and
 * was simply missing: the PACT Act explainer linked to none of them, and
 * neither did any destination page.
 *
 * Why a hardcoded map rather than slugifying the state name: the slug is a
 * postal abbreviation, which cannot be derived from the name. "District of
 * Columbia" is dc, "Mississippi" is ms not mi, and Michigan is mi. A
 * generated slug would silently point Mississippi at Michigan's law page --
 * a wrong page that returns 200, which is the kind of error nobody notices.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * State or district name => the slug its law page actually uses.
 *
 * Taken from the live /vape-laws/ index, not guessed.
 */
function vc_law_slugs() {
    return apply_filters('vc_law_slugs', array(
        'Alabama' => 'al', 'Alaska' => 'ak', 'Arizona' => 'az',
        'Arkansas' => 'ar', 'California' => 'ca', 'Colorado' => 'co',
        'Connecticut' => 'ct', 'Delaware' => 'de',
        'District of Columbia' => 'dc', 'Florida' => 'fl', 'Georgia' => 'ga',
        'Hawaii' => 'hi', 'Idaho' => 'id', 'Illinois' => 'il',
        'Indiana' => 'in', 'Iowa' => 'ia', 'Kansas' => 'ks',
        'Kentucky' => 'ky', 'Louisiana' => 'la', 'Maine' => 'me',
        'Maryland' => 'md', 'Massachusetts' => 'ma', 'Michigan' => 'mi',
        'Minnesota' => 'mn', 'Mississippi' => 'ms', 'Missouri' => 'mo',
        'Montana' => 'mt', 'Nebraska' => 'ne', 'Nevada' => 'nv',
        'New Hampshire' => 'nh', 'New Jersey' => 'nj', 'New Mexico' => 'nm',
        'New York' => 'ny', 'North Carolina' => 'nc', 'North Dakota' => 'nd',
        'Ohio' => 'oh', 'Oklahoma' => 'ok', 'Oregon' => 'or',
        'Pennsylvania' => 'pa', 'Rhode Island' => 'ri',
        'South Carolina' => 'sc', 'South Dakota' => 'sd', 'Tennessee' => 'tn',
        'Texas' => 'tx', 'Utah' => 'ut', 'Vermont' => 'vt', 'Virginia' => 'va',
        'Washington' => 'wa', 'West Virginia' => 'wv', 'Wisconsin' => 'wi',
        'Wyoming' => 'wy',
    ));
}

/**
 * Where the law pages live, with a trailing slash, or '' to link nowhere.
 *
 * Defaults to this site's own /vape-laws/. Settable because the law pages
 * live on the production site while this plugin may be running on staging,
 * and a link to a page that is not there is worse than no link at all.
 */
function vc_law_base() {
    $stored = trim((string) get_option('vc_law_base_url', ''));

    if ($stored === '') {
        $stored = home_url('/vape-laws/');
    }

    $stored = apply_filters('vc_law_base', $stored);
    if (trim((string) $stored) === '') {
        return '';
    }

    return trailingslashit(trim((string) $stored));
}

/**
 * The law page URL for a state name, or '' when there is not one.
 *
 * Unknown names return '' rather than a guess. Our ships_to taxonomy also
 * holds countries and a couple of roll-up terms, and "United States" has no
 * state law page.
 */
function vc_law_url($state) {
    $slugs = vc_law_slugs();
    $state = trim((string) $state);

    if (!isset($slugs[$state])) {
        return '';
    }

    $base = vc_law_base();
    if ($base === '') {
        return '';
    }

    return $base . $slugs[$state] . '/';
}

/**
 * Is this term one of the states we have a law page for?
 */
function vc_law_term_has_page($term) {
    return !empty($term) && !is_wp_error($term)
        && isset(vc_law_slugs()[$term->name]);
}

/* -------------------------------------------------------------------------
 * The link on a destination page
 *
 * Appended to the editorial block through the filter that already exists for
 * this, rather than reaching into the function that builds it.
 * ---------------------------------------------------------------------- */

add_filter('vc_archive_intro_html', function ($html, $term) {
    if (!vc_law_term_has_page($term)) {
        return $html;
    }

    $url = vc_law_url($term->name);
    if ($url === '') {
        return $html;
    }

    $label = sprintf(
        /* translators: %s: state name */
        __('Read the full %s vape laws', 'vc-merchant'), $term->name);

    return $html . '<p class="vc-law-link"><a href="' . esc_url($url) . '">'
        . esc_html($label) . '</a></p>';
}, 10, 2);

/* -------------------------------------------------------------------------
 * Shortcodes, for placing the links in ordinary page content
 * ---------------------------------------------------------------------- */

/**
 * [vape_law_link state="Texas"] -- one link.
 *
 * With no state it uses the destination term being viewed, so the same
 * shortcode works inside a term description.
 */
add_shortcode('vape_law_link', function ($atts) {
    $atts = shortcode_atts(array('state' => '', 'label' => ''), $atts,
        'vape_law_link');

    $state = trim((string) $atts['state']);
    if ($state === '') {
        $term = get_queried_object();
        if (!empty($term) && !is_wp_error($term) && isset($term->name)) {
            $state = $term->name;
        }
    }

    $url = vc_law_url($state);
    if ($url === '') {
        return '';
    }

    $label = trim((string) $atts['label']);
    if ($label === '') {
        $label = sprintf(
            /* translators: %s: state name */
            __('%s vape laws', 'vc-merchant'), $state);
    }

    return '<a class="vc-law-link" href="' . esc_url($url) . '">'
        . esc_html($label) . '</a>';
});

/**
 * [vape_law_links] -- every state, as a list.
 *
 * For the PACT Act explainer, which talks about rules varying by state and
 * linked to none of them.
 */
add_shortcode('vape_law_links', function ($atts) {
    $atts = shortcode_atts(array('title' => ''), $atts, 'vape_law_links');

    $base = vc_law_base();
    if ($base === '') {
        return '';
    }

    $items = '';
    foreach (vc_law_slugs() as $state => $slug) {
        $items .= '<li><a href="' . esc_url($base . $slug . '/') . '">'
            . esc_html(sprintf(
                /* translators: %s: state name */
                __('%s vape laws', 'vc-merchant'), $state))
            . '</a></li>';
    }

    $heading = trim((string) $atts['title']);
    $out = '<section class="vc-law-list">';
    if ($heading !== '') {
        $out .= '<h2>' . esc_html($heading) . '</h2>';
    }

    return $out . '<ul class="vc-law-list__items">' . $items . '</ul></section>';
});

/* -------------------------------------------------------------------------
 * Admin screen
 *
 * The base URL needs a field, not just a filter. On staging home_url() is
 * .../staging, so the default resolves to /staging/vape-laws/tx/ -- a page
 * that is not there. Without somewhere to type the production URL, the only
 * way to correct that is editing PHP, which is not a setting.
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=merchant',
        __('Law links', 'vc-merchant'), __('Law links', 'vc-merchant'),
        'manage_options', 'vc-merchant-lawlinks', 'vc_law_screen');
});

function vc_law_screen() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $notice = '';
    if (isset($_POST['vc_law_submit'])
        && check_admin_referer('vc_law_save', 'vc_law_nonce')) {
        // Stored raw-ish but sanitised as a URL, and an empty value is
        // meaningful: it turns every link off rather than falling back.
        $value = isset($_POST['base']) ? trim(wp_unslash($_POST['base'])) : '';
        update_option('vc_law_base_url',
            $value === '' ? '' : esc_url_raw($value));
        $notice = __('Saved.', 'vc-merchant');
    }

    $stored = trim((string) get_option('vc_law_base_url', ''));
    $base = vc_law_base();

    echo '<div class="wrap"><h1>' . esc_html__('Law links', 'vc-merchant') . '</h1>';

    if ($notice !== '') {
        echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
    }

    echo '<p>' . esc_html__('Where the state vape-law pages live. Each state shipping page links to its own page here, and the [vape_law_links] shortcode lists all of them.', 'vc-merchant') . '</p>';

    echo '<form method="post"><table class="form-table"><tr>'
        . '<th scope="row"><label for="vc_law_base">'
        . esc_html__('Law page base URL', 'vc-merchant') . '</label></th><td>';
    wp_nonce_field('vc_law_save', 'vc_law_nonce');
    echo '<input type="url" class="regular-text code" id="vc_law_base" name="base" value="'
        . esc_attr($stored) . '" placeholder="' . esc_attr(home_url('/vape-laws/')) . '" />'
        . '<p class="description">'
        . esc_html__('Leave empty to use this site\'s own /vape-laws/. Set it to the production URL when running on staging, where the default would point at pages that are not there. Clearing it to a single space removes every law link.', 'vc-merchant')
        . '</p></td></tr></table>';
    submit_button(__('Save', 'vc-merchant'), 'primary', 'vc_law_submit');
    echo '</form>';

    if ($base === '') {
        echo '<p><strong>' . esc_html__('Law links are currently switched off.', 'vc-merchant')
            . '</strong></p></div>';

        return;
    }

    echo '<h2>' . esc_html__('Where each state points', 'vc-merchant') . '</h2>';
    echo '<p class="description">' . esc_html__('Open a few to confirm they resolve. To check all fifty-one at once, run: wp merchant lawlinks --check', 'vc-merchant') . '</p>';
    echo '<table class="widefat striped"><thead><tr><th>'
        . esc_html__('State', 'vc-merchant') . '</th><th>'
        . esc_html__('Law page', 'vc-merchant') . '</th></tr></thead><tbody>';
    foreach (vc_law_slugs() as $state => $slug) {
        $url = $base . $slug . '/';
        printf('<tr><td>%s</td><td><a href="%s" target="_blank" rel="noopener">%s</a></td></tr>',
            esc_html($state), esc_url($url), esc_html($url));
    }
    echo '</tbody></table></div>';
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp merchant lawlinks [--check]
 *
 * --check requests every URL. A link to a 404 is worse than no link, and the
 * slugs are the one thing here that cannot be derived or validated locally.
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant lawlinks', function ($args, $assoc) {
        $base = vc_law_base();
        if ($base === '') {
            WP_CLI::error('No law page base URL is set.');
        }
        WP_CLI::log('base: ' . $base);

        $bad = 0;
        foreach (vc_law_slugs() as $state => $slug) {
            $url = $base . $slug . '/';
            if (empty($assoc['check'])) {
                WP_CLI::log(sprintf('%-22s %s', $state, $url));
                continue;
            }

            $response = wp_remote_head($url, array(
                'timeout' => 15, 'redirection' => 3));
            if (is_wp_error($response)) {
                $bad++;
                WP_CLI::warning(sprintf('%-22s %s -- %s', $state, $url,
                    $response->get_error_message()));
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $bad++;
                WP_CLI::warning(sprintf('%-22s %s -- HTTP %d', $state, $url, $code));
            }
        }

        if (!empty($assoc['check'])) {
            if ($bad) {
                WP_CLI::error(sprintf('%d of %d law pages did not answer 200.',
                    $bad, count(vc_law_slugs())));
            }
            WP_CLI::success(sprintf('all %d law pages answered 200.',
                count(vc_law_slugs())));
        }
    });
}
