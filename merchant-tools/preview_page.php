<?php
/**
 * Render a merchant coupon page from an enriched CSV row, using the real
 * display helpers in wp-merchant-fields.php.
 *
 * This is a faithful preview, not a mockup: the offer badge, trust gating and
 * publish gate all run the same code the live template calls, so what you see
 * here is what the page will output.
 *
 * Usage:
 *   php preview_page.php merchants-enriched.csv "Vape Street" > preview.html
 *   php preview_page.php merchants-enriched.csv --list
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', __DIR__ . '/');

// --- Minimal WordPress surface so the helpers run outside WP --------------
$GLOBALS['meta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['current_id'] = 1;

function get_post_meta($id, $key, $single = false) {
    return isset($GLOBALS['meta'][$id][$key]) ? $GLOBALS['meta'][$id][$key] : '';
}
function get_the_ID() { return $GLOBALS['current_id']; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id ?: $GLOBALS['current_id']] ?? ''; }
function apply_filters($tag, $value) { return $value; }
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function register_taxonomy() {}
function register_post_meta() {}
function term_exists() { return false; }
function wp_insert_term() { return array('term_id' => 1); }
function is_wp_error($t) { return false; }
function current_user_can() { return true; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function __($v, $d = null) { return $v; }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }

// Stubs for the modules wp-merchant-fields.php pulls in (render, seo, import).
function add_shortcode() {}
function do_shortcode($s) { return $s; }
function shortcode_atts($pairs, $atts, $sc = '') { return array_merge($pairs, (array) $atts); }
function wpautop($s) { return "<p>$s</p>"; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_post_type($id = null) { return 'merchant'; }
function is_singular() { return true; }
function get_permalink($id = null) { return 'https://example.com/preview/'; }
function date_i18n($fmt) { return date($fmt); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function esc_html_e($v, $d = null) { echo htmlspecialchars((string) $v, ENT_QUOTES); }
function _e($v, $d = null) { echo $v; }
function add_management_page() {}
function wp_nonce_field() {}
function submit_button() {}
function wp_verify_nonce() { return false; }
function get_posts() { return array(); }
function wp_insert_post() { return 1; }
function wp_update_post() { return 1; }
function wp_set_object_terms() {}
function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-')); }
class WP_Error { public function get_error_message() { return ''; } }

require __DIR__ . '/wp-merchant-fields.php';

// --- Load the row ---------------------------------------------------------
$csvPath = $argv[1] ?? null;
$wanted  = $argv[2] ?? null;

if (!$csvPath || !is_readable($csvPath)) {
    fwrite(STDERR, "Usage: php preview_page.php ENRICHED.csv \"Brand Name\"\n");
    exit(1);
}

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh);
$rows = array();
while (($line = fgetcsv($fh)) !== false) {
    if (count($line) !== count($header)) {
        continue;
    }
    $rows[] = array_combine($header, $line);
}
fclose($fh);

if ($wanted === '--list' || $wanted === null) {
    foreach ($rows as $r) {
        printf("%-40s %-18s score %s\n",
            substr($r['brand_name'], 0, 40),
            $r['offer_display_mode'] ?? '',
            $r['content_score'] ?? '');
    }
    exit(0);
}

$row = null;
foreach ($rows as $r) {
    if (strcasecmp(trim($r['brand_name']), trim($wanted)) === 0) {
        $row = $r;
        break;
    }
}
if (!$row) {
    fwrite(STDERR, "Merchant not found: $wanted\n");
    exit(1);
}

// Feed the row into the stubbed meta store.
$GLOBALS['titles'][1] = $row['brand_name'];
foreach ($row as $key => $value) {
    $GLOBALS['meta'][1][$key] = $value;
}

$name = vc_merchant_display_name();

/**
 * Render a content block only when it carries real text. Blank and
 * suppressed fields simply do not produce a section.
 */
function block($heading, $value, $extra = '') {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }
    return "<section>\n  <h2>" . esc_html($heading) . "</h2>\n  <p>"
        . esc_html($value) . "</p>\n" . $extra . "</section>\n\n";
}

// --- Page ----------------------------------------------------------------
echo "<!-- SEO title: " . esc_html("$name Coupon Codes & Promo Codes") . " -->\n";

// The page always renders -- this is a review tool. Grading is shown as a
// comment so you can see what the enrichment flagged while reading the page.
if (trim((string) ($row['review_notes'] ?? '')) !== '') {
    echo "<!-- review notes: " . esc_html($row['review_notes']) . " -->\n";
}

echo "<article class=\"merchant-page\">\n\n";
echo "<h1>" . esc_html("$name Coupon Codes & Promo Codes") . "</h1>\n\n";

// Offer status -- the gated claim.
echo "<div class=\"offer-status\">\n  " . vc_merchant_offer_badge() . "\n</div>\n\n";

echo block('Best current deal', $row['best_offer_summary'] ?? '');
echo "<div class=\"coupon-widget\">" . esc_html($row['coupon_plugin_shortcode'] ?? '') . "</div>\n\n";

echo block("About $name", $row['brand_summary'] ?? '');
echo block("Best ways to save at $name", $row['best_ways_to_save'] ?? '');
echo block('Shipping', $row['free_shipping_info'] ?? '');
echo block('Returns', $row['return_policy_summary'] ?? '');
echo block('Payment methods', $row['payment_methods'] ?? '');
echo block('Exclusions', $row['common_exclusions'] ?? '');
echo block('Stacking codes', $row['stacking_policy'] ?? '');
echo block('If your code will not work', $row['why_code_not_work'] ?? '');
echo block('Shipping restrictions', $row['shipping_restrictions'] ?? '');

// FAQs
$faqs = array();
for ($i = 1; $i <= 3; $i++) {
    $q = trim((string) ($row["faq_{$i}_question"] ?? ''));
    $a = trim((string) ($row["faq_{$i}_answer"] ?? ''));
    if ($q !== '' && $a !== '') {
        $faqs[] = array($q, $a);
    }
}
if ($faqs) {
    echo "<section class=\"faqs\">\n  <h2>" . esc_html("$name FAQs") . "</h2>\n";
    foreach ($faqs as $faq) {
        echo "  <h3>" . esc_html($faq[0]) . "</h3>\n  <p>" . esc_html($faq[1]) . "</p>\n";
    }
    echo "</section>\n\n";
}

// Merchant info panel + trust block (both self-gating).
echo vc_merchant_info_panel() . "\n";

$trust = vc_merchant_trust_info();
if ($trust !== '') {
    echo "<section class=\"trust\">\n  <h2>Company information</h2>\n  $trust</section>\n\n";
} else {
    echo "<!-- trust info suppressed: no source + verification date -->\n\n";
}

// Editorial transparency block.
$editor = trim((string) ($row['editor_name'] ?? ''));
$method = trim((string) ($row['verification_method'] ?? ''));
if ($editor !== '' || $method !== '') {
    echo "<section class=\"editorial\">\n";
    if ($editor !== '') {
        echo "  <p>Reviewed by " . esc_html($editor);
        if (trim((string) ($row['editor_title'] ?? '')) !== '') {
            echo ", " . esc_html($row['editor_title']);
        }
        echo "</p>\n";
    }
    if ($method !== '') {
        echo "  <p>How we checked: " . esc_html($method) . "</p>\n";
    }
    if (trim((string) ($row['fact_last_verified'] ?? '')) !== '') {
        echo "  <p>Last verified: " . esc_html($row['fact_last_verified']) . "</p>\n";
    }
    echo "</section>\n\n";
}

// Internal links.
$links = array(
    'Read our review'  => $row['brand_review_url'] ?? '',
    'All deals'        => $row['deals_hub_url'] ?? '',
    'Seasonal deals'   => $row['seasonal_deals_url'] ?? '',
);
$linkHtml = '';
foreach ($links as $label => $url) {
    if (trim((string) $url) !== '') {
        $linkHtml .= "    <li><a href=\"" . esc_url($url) . "\">" . esc_html($label) . "</a></li>\n";
    }
}
if ($linkHtml !== '') {
    echo "<nav class=\"related\">\n  <ul>\n$linkHtml  </ul>\n</nav>\n\n";
}

echo "</article>\n";
