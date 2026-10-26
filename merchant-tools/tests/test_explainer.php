<?php
/**
 * The shipping explainer page and the links into it.
 *
 * Three failures are worth pinning down, and none is visible from the
 * admin screen.
 *
 * A link to a page that has been deleted or trashed turns 51 destination
 * pages into 51 links to a 404, so the stored page ID has to be checked on
 * every render rather than trusted.
 *
 * The plugin must never overwrite the page once it exists. The whole point
 * of creating a real WordPress page rather than generating one is that the
 * editor owns it afterwards.
 *
 * And the link has to be followed, not nofollowed: consolidating the
 * explainer onto one page is pointless if the 51 links into it pass
 * nothing.
 *
 *     php tests/test_explainer.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['options'] = array();
$GLOBALS['posts'] = array();
$GLOBALS['filters'] = array();
$GLOBALS['shortcodes'] = array();
$GLOBALS['next_id'] = 500;

function vc_merchant_post_types() { return array('merchant'); }

function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); return true; }

function wp_insert_post($arr, $wp_error = false) {
    $id = $GLOBALS['next_id']++;
    $GLOBALS['posts'][$id] = array(
        'post_title'   => $arr['post_title'] ?? '',
        'post_content' => $arr['post_content'] ?? '',
        'post_status'  => $arr['post_status'] ?? 'draft',
        'post_type'    => $arr['post_type'] ?? 'page',
    );
    return $id;
}
function get_post_status($id) { return $GLOBALS['posts'][$id]['post_status'] ?? false; }
function get_the_title($id) { return $GLOBALS['posts'][$id]['post_title'] ?? ''; }
function get_permalink($id) { return 'https://vapingcheap.com/?p=' . (int) $id; }
function get_edit_post_link($id) { return 'https://vapingcheap.com/wp-admin/post.php?post=' . (int) $id; }
function get_posts($args = array()) {
    $out = array();
    foreach ($GLOBALS['posts'] as $id => $p) {
        if (($args['post_status'] ?? 'publish') !== $p['post_status']) { continue; }
        $out[] = (object) array('ID' => $id, 'post_title' => $p['post_title']);
    }
    return $out;
}

function apply_filters($tag, $value) {
    $args = func_get_args();
    foreach ($GLOBALS['filters'][$tag] ?? array() as $fn) {
        $value = call_user_func_array($fn, array_merge(array($value), array_slice($args, 2)));
    }
    return $value;
}
function add_filter($tag, $fn, $p = 10, $a = 1) { $GLOBALS['filters'][$tag][] = $fn; }
function add_action() {}
function add_shortcode($tag, $fn) { $GLOBALS['shortcodes'][$tag] = $fn; }
function add_submenu_page() {}
function current_user_can() { return true; }
function wp_verify_nonce() { return false; }
function wp_nonce_field() {}
function submit_button() {}
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function esc_attr__($s, $d = null) { return $s; }
function __($s, $d = null) { return $s; }

class WP_Error {
    private $msg;
    public function __construct($code = '', $msg = '') { $this->msg = $msg; }
    public function get_error_message() { return $this->msg; }
}
function is_wp_error($t) { return $t instanceof WP_Error; }

require_once __DIR__ . '/../wp-merchant-explainer.php';

$fails = array();
function check($label, $cond, $detail = '') {
    global $fails;
    if ($cond) {
        echo "PASS: $label\n";
    } else {
        echo "FAIL: $label" . ($detail ? " -- $detail" : "") . "\n";
        $fails[] = $label;
    }
}

/* --- Nothing renders until a page is linked --------------------------- */

check('no page linked means no link', vc_explainer_link_html() === '');
check('and no URL', vc_explainer_url() === '');
check('the shortcode renders nothing either',
    call_user_func($GLOBALS['shortcodes']['merchant_shipping_explainer']) === '');

/* --- Creating the page ------------------------------------------------ */

$id = vc_explainer_create('Why Vape Orders Ship Differently');
check('the page is created', is_int($id) && $id > 0, var_export($id, true));
check('it is published', get_post_status($id) === 'publish');
check('it is a page, not a post', $GLOBALS['posts'][$id]['post_type'] === 'page');
check('the title is the one asked for',
    get_the_title($id) === 'Why Vape Orders Ship Differently');
check('and it is linked', vc_explainer_page_id() === $id);

$content = $GLOBALS['posts'][$id]['post_content'];
check('the shipped copy went in', strlen($content) > 3000, strlen($content));
check('it cites the statute that brought vapes under the PACT Act',
    strpos($content, '375(2)(A)(ii)(II)') !== false);
check('it explains the adult signature rule',
    strpos($content, '376a(b)(4)(A)(ii)') !== false);
check('it cites the USPS exceptions page',
    strpos($content, 'pub52c4_028') !== false);
check('it says plainly that it is not legal advice',
    strpos($content, 'not legal advice') !== false);
check('every source link is nofollowed',
    substr_count($content, '<a href="http') === substr_count($content, 'rel="nofollow noopener"'),
    substr_count($content, '<a href="http') . ' links, '
    . substr_count($content, 'rel="nofollow noopener"') . ' nofollowed');

/* --- The link itself -------------------------------------------------- */

$link = vc_explainer_link_html();
check('the link renders once a page is linked', $link !== '');
check('it points at the page', strpos($link, 'p=' . $id) !== false, $link);
check('it is followed, not nofollowed',
    strpos($link, 'nofollow') === false, $link);
check('the shortcode renders it too',
    call_user_func($GLOBALS['shortcodes']['merchant_shipping_explainer']) === $link);

add_filter('vc_explainer_link_text', function () { return 'How vape shipping works'; });
check('the anchor text is filterable',
    strpos(vc_explainer_link_html(), 'How vape shipping works') !== false);
$GLOBALS['filters']['vc_explainer_link_text'] = array();

/* --- A deleted or trashed page must not leave 51 links to a 404 ------- */

$GLOBALS['posts'][$id]['post_status'] = 'trash';
check('a trashed page is not treated as linked', vc_explainer_page_id() === 0);
check('and no link renders', vc_explainer_link_html() === '');

unset($GLOBALS['posts'][$id]);
check('a deleted page is not treated as linked', vc_explainer_page_id() === 0);
check('and still no link renders', vc_explainer_link_html() === '');

/* --- Where the link lands in the archive intro ------------------------ */

$id = vc_explainer_create();
check('a second page can be created', vc_explainer_page_id() === $id);
check('the default title is used when none is given',
    strpos(get_the_title($id), 'PACT Act') !== false, get_the_title($id));

$intro = '<div class="vc-archive-intro"><p>Utah copy.</p>'
       . '<div class="vc-archive-legal"><p>Utah law.</p></div></div>';
$out = apply_filters('vc_archive_intro_html', $intro, null);
check('the link is added inside the intro wrapper',
    substr(trim($out), -6) === '</div>', substr($out, -80));
check('it comes after the legal note, not before it',
    strpos($out, 'vc-archive-legal') < strpos($out, 'vc-archive-explainer'),
    $out);
check('the original copy survives', strpos($out, 'Utah copy.') !== false);

// A wrapper that is not there at all must not lose the link.
$out = apply_filters('vc_archive_intro_html', '<p>Bare copy.</p>', null);
check('with no wrapper the link is appended',
    strpos($out, 'vc-archive-explainer') !== false, $out);

// An empty intro stays empty: a destination page with no copy of its own
// is held at noindex, and a lone federal link is not copy of its own.
check('an empty intro is left empty',
    apply_filters('vc_archive_intro_html', '', null) === '');

/* --- The page is never overwritten ----------------------------------- */

$GLOBALS['posts'][$id]['post_content'] = 'My own rewritten copy.';
vc_explainer_link_html();
vc_explainer_url();
vc_explainer_page_id();
check('rendering the link never touches the page content',
    $GLOBALS['posts'][$id]['post_content'] === 'My own rewritten copy.',
    $GLOBALS['posts'][$id]['post_content']);

/* --- Unlinking leaves the page alone --------------------------------- */

delete_option(VC_EXPLAINER_OPTION);
check('unlinking stops the links', vc_explainer_link_html() === '');
check('but the page is still there', get_post_status($id) === 'publish');

echo "\n";
if ($fails) {
    echo count($fails) . " check(s) FAILED: " . implode(', ', $fails) . "\n";
    exit(1);
}
echo "All shipping explainer checks passed.\n";
