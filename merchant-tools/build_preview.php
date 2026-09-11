<?php
/**
 * Generate standalone, styled previews of both page types from an enriched CSV.
 *
 * Produces two self-contained HTML files you can open in a browser -- the
 * stylesheet is inlined, so no WordPress and no web server needed. Useful for
 * judging layout and spotting missing data points before importing anything.
 *
 * Usage:
 *   php build_preview.php enriched.csv OUTPUT_DIR ["Brand Name"] ["Destination"]
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', __DIR__ . '/');

$GLOBALS['meta'] = array();
$GLOBALS['termmeta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['current_id'] = 1;
$GLOBALS['queried'] = null;

// --- WordPress surface ----------------------------------------------------
function get_post_meta($id, $key, $single = false) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['meta'][$id][$k] = $v; return true; }
function get_term_meta($id, $k, $s = false) { return $GLOBALS['termmeta'][$id][$k] ?? ''; }
function update_term_meta($id, $k, $v) { $GLOBALS['termmeta'][$id][$k] = $v; return true; }
function get_the_ID() { return $GLOBALS['current_id']; }
function get_the_title($id = null) { return $GLOBALS['titles'][$id ?: $GLOBALS['current_id']] ?? ''; }
function get_permalink($id = null) { $id = $id ?: $GLOBALS['current_id']; return '#merchant-' . $id; }
function get_post_type($p = null) { return 'merchant'; }
function get_post_status($id = null) { return 'publish'; }
function is_singular() { return true; }
function is_tax($tax = '') { return $GLOBALS['queried'] && $GLOBALS['queried']->taxonomy === $tax; }
function get_queried_object() { return $GLOBALS['queried']; }
function get_term_link($t) { return '#dest-' . sanitize_title($t->name); }
function get_terms($a = array()) { return $GLOBALS['siblings'] ?? array(); }
function apply_filters($tag, $value) { return $value; }
function add_action() {}
function add_filter() {}
function add_shortcode() {}
function do_shortcode($s) { return ''; }
function shortcode_atts($p, $a, $s = '') { return array_merge($p, (array) $a); }
function wpautop($s) { return '<p>' . str_replace("\n\n", '</p><p>', $s) . '</p>'; }
function wp_kses_post($s) { return $s; }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_textarea($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_url($v) { return (string) $v; }
function esc_html__($v, $d = null) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html_e($v, $d = null) { echo htmlspecialchars((string) $v, ENT_QUOTES); }
function __($v, $d = null) { return $v; }
function _e($v, $d = null) { echo $v; }
function _n($s, $p, $n, $d = null) { return $n === 1 ? $s : $p; }
function is_email($v) { return (bool) filter_var($v, FILTER_VALIDATE_EMAIL); }
function date_i18n($f) { return date($f); }
function wp_json_encode($v, $f = 0) { return json_encode($v, $f); }
function current_user_can() { return false; }
function is_wp_error($t) { return false; }
function register_taxonomy() {}
function register_post_meta() {}
function register_activation_hook() {}
function term_exists() { return false; }
function wp_insert_term() { return array('term_id' => 1); }
function get_term_by() { return false; }
function sanitize_title($v) { return strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $v), '-')); }
function add_management_page() {}
function wp_nonce_field() {}
function submit_button() {}
function wp_verify_nonce() { return false; }
function get_posts() { return array(); }
function wp_insert_post() { return 1; }
function wp_update_post() { return 1; }
function wp_set_object_terms() {}
function plugins_url($p, $f = '') { return $p; }
function wp_enqueue_style() {}
function is_main_query() { return true; }
class WP_Error { public function get_error_message() { return ''; } }
class FakeTerm {
    public $term_id, $name, $taxonomy, $count, $parent;
    public function __construct($id, $name, $tax, $count, $parent = 0) {
        $this->term_id = $id; $this->name = $name; $this->taxonomy = $tax;
        $this->count = $count; $this->parent = $parent;
    }
}

require __DIR__ . '/wp-merchant-fields.php';

// --- Inputs ---------------------------------------------------------------
$csvPath = $argv[1] ?? '';
$outDir  = $argv[2] ?? '.';
$wantBrand = $argv[3] ?? '';
$destination = $argv[4] ?? 'California';

if ($csvPath === '' || !is_readable($csvPath)) {
    fwrite(STDERR, "Usage: php build_preview.php enriched.csv OUTPUT_DIR [\"Brand\"] [\"Destination\"]\n");
    exit(1);
}

$fh = fopen($csvPath, 'r');
$header = fgetcsv($fh);
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
$rows = array();
while (($line = fgetcsv($fh)) !== false) {
    if (count($line) === count($header)) {
        $rows[] = array_combine($header, $line);
    }
}
fclose($fh);

if (empty($rows)) {
    fwrite(STDERR, "No rows in $csvPath\n");
    exit(1);
}

// Load every row into the fake meta store, one post id each.
$ids = array();
foreach ($rows as $i => $row) {
    $id = $i + 1;
    $ids[] = $id;
    $GLOBALS['titles'][$id] = $row['brand_name'];
    foreach ($row as $k => $v) {
        $GLOBALS['meta'][$id][$k] = $v;
    }
}

$css = file_get_contents(__DIR__ . '/assets/merchant-pages.css');

function page_shell($title, $css, $body) {
    return "<!doctype html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>" . esc_html($title) . "</title>\n<style>\n"
        . "body{margin:0;background:#fff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}\n"
        . ".wrap{max-width:780px;margin:0 auto;padding:2.5rem 1.25rem 4rem;}\n"
        . "h1{font-size:1.9rem;line-height:1.25;margin:0 0 1.25rem;letter-spacing:-0.02em;}\n"
        . ".preview-note{background:#eef4ff;border-left:4px solid #1d6fa5;padding:.7rem .9rem;"
        . "margin:0 0 1.75rem;font-size:.85rem;color:#31465c;border-radius:3px;}\n"
        . $css . "\n</style>\n</head>\n<body>\n<div class=\"wrap\">\n$body\n</div>\n</body>\n</html>\n";
}

// --- 1. Merchant page -----------------------------------------------------
$target = null;
foreach ($ids as $id) {
    if ($wantBrand === '' || strcasecmp(trim($GLOBALS['titles'][$id]), trim($wantBrand)) === 0) {
        $target = $id;
        break;
    }
}
$target = $target ?: $ids[0];
$GLOBALS['current_id'] = $target;

$name = vc_merchant_display_name($target);
$h1 = esc_html(vc_merchant_seo_title($target));
$metaDesc = esc_html(vc_merchant_meta_description($target));

$body = "<div class=\"preview-note\"><strong>Preview — merchant page.</strong><br>"
      . "Meta description: " . $metaDesc . "</div>\n"
      . "<h1>$h1</h1>\n"
      . vc_merchant_render_page(array('id' => $target));

file_put_contents(rtrim($outDir, '/') . '/preview-merchant.html',
    page_shell(vc_merchant_seo_title($target), $css, $body));

// --- 2. Destination archive ----------------------------------------------
// Pick merchants whose derived terms include the destination.
$matching = array();
foreach ($ids as $id) {
    $terms = (string) get_post_meta($id, 'ships_to_terms', true);
    $list = array_map('trim', explode('|', $terms));
    if (in_array($destination, $list, true)) {
        $matching[] = $id;
    }
}
if (empty($matching)) {
    $matching = array_slice($ids, 0, 6);
}

$term = new FakeTerm(900, $destination, 'ships_to', count($matching), 5);
$GLOBALS['queried'] = $term;
$GLOBALS['siblings'] = array(
    new FakeTerm(901, 'Texas', 'ships_to', 41, 5),
    new FakeTerm(902, 'Florida', 'ships_to', 39, 5),
    new FakeTerm(903, 'New York', 'ships_to', 28, 5),
    new FakeTerm(904, 'Illinois', 'ships_to', 33, 5),
);

$cards = '<ul class="vc-archive-list">';
foreach ($matching as $id) {
    $GLOBALS['current_id'] = $id;
    $cards .= vc_archive_merchant_card($id);
}
$cards .= '</ul>';

$archiveBody = "<div class=\"preview-note\"><strong>Preview — destination archive.</strong><br>"
    . "Title tag: " . esc_html(vc_archive_seo_title($term)) . "<br>"
    . "Meta description: " . esc_html(vc_archive_meta_description($term)) . "</div>\n"
    . "<h1>" . esc_html(vc_archive_title($term)) . "</h1>\n"
    . vc_archive_intro($term)
    . $cards
    . vc_archive_faq_html($term)
    . vc_archive_sibling_links($term);

file_put_contents(rtrim($outDir, '/') . '/preview-archive.html',
    page_shell(vc_archive_seo_title($term), $css, $archiveBody));

echo "Wrote preview-merchant.html (" . $name . ") and preview-archive.html ("
    . $destination . ", " . count($matching) . " merchants)\n";
