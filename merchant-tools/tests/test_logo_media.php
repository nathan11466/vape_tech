<?php
/**
 * Pulling merchant logos into the media library. The risks are re-downloading
 * on every run, clobbering a logo someone chose by hand, and losing track of
 * where the file came from.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/fakewp/');
@mkdir('/tmp/fakewp/wp-admin/includes', 0777, true);
foreach (array('media.php','file.php','image.php') as $f) {
    if (!file_exists('/tmp/fakewp/wp-admin/includes/' . $f)) {
        file_put_contents('/tmp/fakewp/wp-admin/includes/' . $f, '<?php');
    }
}

$GLOBALS['meta'] = array();
$GLOBALS['titles'] = array();
$GLOBALS['attachments'] = array();
$GLOBALS['thumbs'] = array();
$GLOBALS['sideloads'] = array();
$GLOBALS['sideload_fail'] = false;
$GLOBALS['next_attachment'] = 500;

function home_url($p = '') { return 'https://vapingcheap.com' . $p; }
function get_post_meta($id,$k,$s=false){ return $GLOBALS['meta'][$id][$k] ?? ''; }
function update_post_meta($id,$k,$v){ $GLOBALS['meta'][$id][$k]=$v; return true; }
function get_post($id){ return isset($GLOBALS['attachments'][$id]) ? (object)array('ID'=>$id) : null; }
function get_the_title($id=null){ return $GLOBALS['titles'][$id] ?? ''; }
function wp_get_attachment_url($id){ return $GLOBALS['attachments'][$id] ?? false; }
function has_post_thumbnail($id){ return !empty($GLOBALS['thumbs'][$id]); }
function set_post_thumbnail($id,$att){ $GLOBALS['thumbs'][$id]=$att; return true; }
function media_sideload_image($url,$post_id,$desc='',$return='html'){
    if ($GLOBALS['sideload_fail']) return new WP_Error('http_404','Not Found');
    $id = $GLOBALS['next_attachment']++;
    $GLOBALS['sideloads'][] = $url;
    $GLOBALS['attachments'][$id] = 'https://vapingcheap.com/wp-content/uploads/2026/10/logo-' . $id . '.png';
    return $id;
}
function get_posts($a=array()){ return $GLOBALS['query_ids'] ?? array(); }
function is_wp_error($t){ return $t instanceof WP_Error; }
function vc_merchant_post_types(){ return array('merchant'); }
function vc_merchant_display_name($id=null){
    $d = trim((string) get_post_meta($id,'display_brand_name',true));
    return $d !== '' ? $d : get_the_title($id);
}
function add_action(){} function add_submenu_page(){}
function current_user_can(){ return true; }
function wp_verify_nonce(){ return false; }
function wp_nonce_field(){} function submit_button(){}
function esc_html($v){ return htmlspecialchars((string)$v, ENT_QUOTES); }
function esc_html_e($v,$d=null){ echo $v; }
function esc_html__($v,$d=null){ return $v; }
function esc_attr($v){ return $v; }
function __($v,$d=null){ return $v; }
function _n($s,$p,$n,$d=null){ return $n===1?$s:$p; }
class WP_Error {
    private $m;
    function __construct($c='',$m=''){ $this->m=$m; }
    function get_error_message(){ return $this->m; }
}

require __DIR__ . '/../wp-merchant-logos.php';

$fail = 0;
function check($l,$c,$d=''){
    global $fail;
    if ($c) echo "PASS: $l\n"; else { echo "FAIL: $l".($d?" -- $d":'')."\n"; $fail++; }
}

// --- Local detection ----------------------------------------------------
check('own domain counts as local', vc_logo_is_local('https://vapingcheap.com/wp-content/x.png'));
check('www variant counts as local', vc_logo_is_local('https://www.vapingcheap.com/x.png'));
check('root-relative path counts as local', vc_logo_is_local('/wp-content/uploads/x.png'));
check('merchant CDN does not count as local',
    !vc_logo_is_local('https://cdn.shopify.com/s/files/logo.png'));
check('empty is not local', !vc_logo_is_local(''));

// --- Sideload -----------------------------------------------------------
$GLOBALS['titles'][10] = 'VooPoo';
$GLOBALS['meta'][10] = array('brand_logo_url' => 'https://cdn.shopify.com/s/files/logo.png?v=123');
check('an off-site logo is imported', vc_logo_sideload(10) === 'imported');
check('brand_logo_url now points at this site',
    strpos(get_post_meta(10,'brand_logo_url',true), 'vapingcheap.com/wp-content') !== false,
    get_post_meta(10,'brand_logo_url',true));
check('the original URL is kept for provenance',
    get_post_meta(10,'brand_logo_source_url',true) === 'https://cdn.shopify.com/s/files/logo.png?v=123');
check('the attachment id is recorded',
    (int) get_post_meta(10,'brand_logo_attachment_id',true) > 0);
check('alt text is set for the hero image',
    get_post_meta((int) get_post_meta(10,'brand_logo_attachment_id',true),
        '_wp_attachment_image_alt', true) === 'VooPoo logo');
check('the logo becomes the featured image', !empty($GLOBALS['thumbs'][10]));

// Re-running must not download it again.
$before = count($GLOBALS['sideloads']);
check('a second run skips an already-imported logo', vc_logo_sideload(10) === 'skipped');
check('and makes no further download', count($GLOBALS['sideloads']) === $before);

// --- Things it must leave alone -----------------------------------------
$GLOBALS['titles'][11] = 'Hand Picked';
$GLOBALS['meta'][11] = array('brand_logo_url' => 'https://vapingcheap.com/wp-content/uploads/mine.png');
check('a logo already on this site is left alone', vc_logo_sideload(11) === 'local');
check('and is not rewritten',
    get_post_meta(11,'brand_logo_url',true) === 'https://vapingcheap.com/wp-content/uploads/mine.png');

$GLOBALS['meta'][12] = array();
check('a store with no logo URL is a no-op', vc_logo_sideload(12) === 'none');

$GLOBALS['meta'][13] = array('brand_logo_url' => 'ftp://example.com/logo.png');
check('a non-http URL is refused', is_wp_error(vc_logo_sideload(13)));

$GLOBALS['meta'][14] = array('brand_logo_url' => 'https://example.com/terms.pdf');
check('a non-image URL is refused', is_wp_error(vc_logo_sideload(14)));

// A resizing endpoint with no extension must still be attempted.
$GLOBALS['titles'][15] = 'CDN Store';
$GLOBALS['meta'][15] = array('brand_logo_url' => 'https://cdn.example.com/image/fetch/w_200/brandlogo');
check('an extensionless CDN URL is still attempted',
    vc_logo_sideload(15) === 'imported');

// A featured image already chosen must not be replaced.
$GLOBALS['titles'][16] = 'Has Thumb';
$GLOBALS['meta'][16] = array('brand_logo_url' => 'https://cdn.x.com/l.png');
$GLOBALS['thumbs'][16] = 999;
vc_logo_sideload(16);
check('an existing featured image is not overwritten', $GLOBALS['thumbs'][16] === 999);

// --- Failure handling ---------------------------------------------------
$GLOBALS['titles'][17] = 'Dead Link';
$GLOBALS['meta'][17] = array('brand_logo_url' => 'https://cdn.x.com/gone.png');
$GLOBALS['sideload_fail'] = true;
$err = vc_logo_sideload(17);
check('a failed download returns the error', is_wp_error($err), '');
check('and does NOT clobber brand_logo_url',
    get_post_meta(17,'brand_logo_url',true) === 'https://cdn.x.com/gone.png');
check('and records no attachment id',
    get_post_meta(17,'brand_logo_attachment_id',true) === '');
$GLOBALS['sideload_fail'] = false;

// --- Batching -----------------------------------------------------------
$GLOBALS['query_ids'] = array();
for ($i = 20; $i < 35; $i++) {
    $GLOBALS['query_ids'][] = $i;
    $GLOBALS['titles'][$i] = "Store $i";
    $GLOBALS['meta'][$i] = array('brand_logo_url' => "https://cdn.x.com/logo$i.png");
}
$batch = vc_logo_sideload_batch(5);
check('a batch stops at the limit', $batch['imported'] === 5, json_encode($batch));
check('and reports what is left', $batch['remaining'] === 10, json_encode($batch));

$batch2 = vc_logo_sideload_batch(20);
check('the next batch picks up the rest',
    $batch2['imported'] === 10 && $batch2['skipped'] === 5, json_encode($batch2));
check('nothing is left afterwards', $batch2['remaining'] === 0);

$GLOBALS['sideload_fail'] = true;
$GLOBALS['query_ids'] = array(40);
$GLOBALS['titles'][40] = 'Broken';
$GLOBALS['meta'][40] = array('brand_logo_url' => 'https://cdn.x.com/no.png');
$batch3 = vc_logo_sideload_batch(5);
check('batch collects errors instead of aborting',
    count($batch3['errors']) === 1 && strpos($batch3['errors'][0], 'Broken') === 0,
    json_encode($batch3['errors']));

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All logo media-library checks passed.\n";
