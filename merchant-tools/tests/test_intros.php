<?php
/**
 * The term-copy importer, against a fake term store.
 *
 * Two failures matter here and neither is visible from the admin screen.
 *
 * The first is silent destruction: this importer writes into the same fields
 * an editor types into, so a re-run that overwrites by default would delete
 * somebody's work with no warning and no undo. The default has to be to
 * leave an occupied field alone.
 *
 * The second is a crafted CSV writing term meta onto taxonomies this plugin
 * does not own, or creating terms from typos -- which would quietly add a
 * 52nd destination page competing with the real one.
 *
 *     php tests/test_intros.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');

$GLOBALS['terms'] = array();      // term_id => array(name, slug, taxonomy)
$GLOBALS['term_meta'] = array();  // term_id => array(key => value)
$GLOBALS['created'] = array();    // anything the importer tried to create

function vc_merchant_post_types() { return array('merchant'); }
function vc_archive_taxonomies() { return array('ships_to', 'service_location'); }
function vc_archive_term_fields() {
    return array(
        'vc_intro'          => 'Intro paragraph',
        'vc_legal_note'     => 'Local rules / legal note',
        'vc_legal_source_url' => 'Local rules: source URL',
        'vc_legal_checked'    => 'Local rules: date checked',
        'vc_faq_q1'         => 'FAQ 1 question',
        'vc_faq_a1'         => 'FAQ 1 answer',
        'vc_faq_q2'         => 'FAQ 2 question',
        'vc_faq_a2'         => 'FAQ 2 answer',
        'vc_title_override' => 'SEO title override',
    );
}
function vc_archive_has_unique_content($term) {
    foreach (array('vc_intro', 'vc_legal_note', 'vc_faq_q1', 'vc_faq_q2') as $key) {
        if (trim((string) get_term_meta($term->term_id, $key, true)) !== '') {
            return true;
        }
    }
    return false;
}

function make_term($id, $name, $taxonomy, $slug = null) {
    $GLOBALS['terms'][$id] = array(
        'term_id'  => $id,
        'name'     => $name,
        'slug'     => $slug ?: strtolower(str_replace(' ', '-', $name)),
        'taxonomy' => $taxonomy,
    );
}

function get_term_by($field, $value, $taxonomy = '') {
    foreach ($GLOBALS['terms'] as $term) {
        if ($taxonomy !== '' && $term['taxonomy'] !== $taxonomy) { continue; }
        if ($field === 'name' && $term['name'] === $value) { return (object) $term; }
        if ($field === 'slug' && $term['slug'] === $value) { return (object) $term; }
    }
    return false;
}
function get_terms($args = array()) {
    $taxonomy = $args['taxonomy'] ?? '';
    $out = array();
    foreach ($GLOBALS['terms'] as $term) {
        if ($taxonomy === '' || $term['taxonomy'] === $taxonomy) {
            $out[] = (object) $term;
        }
    }
    return $out;
}
function wp_insert_term($name, $taxonomy, $args = array()) {
    $GLOBALS['created'][] = array($name, $taxonomy);
    return new WP_Error('should_not_happen', 'the importer must not create terms');
}
function get_term_meta($id, $key, $single = false) {
    return $GLOBALS['term_meta'][$id][$key] ?? '';
}
function update_term_meta($id, $key, $value) {
    $GLOBALS['term_meta'][$id][$key] = $value;
    return true;
}
function sanitize_title($s) {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($s)));
}
function wp_kses_post($s) { return strip_tags($s, '<p><a><strong><em><ul><li><br>'); }
function __($s, $d = null) { return $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html_e($s, $d = null) { echo esc_html($s); }
function add_action() {}
function add_submenu_page() {}
function current_user_can() { return true; }
function wp_verify_nonce() { return false; }
function wp_nonce_field() {}
function submit_button() {}

class WP_Error {
    public $code; public $message;
    public function __construct($code = '', $message = '') {
        $this->code = $code; $this->message = $message;
    }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

require_once __DIR__ . '/../wp-merchant-intros.php';

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

function write_csv($rows) {
    $path = tempnam(sys_get_temp_dir(), 'intros') . '.csv';
    $fh = fopen($path, 'w');
    foreach ($rows as $row) { fputcsv($fh, $row); }
    fclose($fh);
    return $path;
}

function reset_store() {
    $GLOBALS['terms'] = array();
    $GLOBALS['term_meta'] = array();
    $GLOBALS['created'] = array();
    make_term(1, 'Alabama', 'ships_to');
    make_term(2, 'Alaska', 'ships_to');
    make_term(3, 'United States', 'service_location');
}

/* --- The happy path --------------------------------------------------- */

reset_store();
$path = write_csv(array(
    array('taxonomy', 'term', 'vc_intro'),
    array('ships_to', 'Alabama', 'Almost every shop ships to Alabama.'),
    array('ships_to', 'Alaska', 'Shipping to Alaska is the exception.'),
));
$result = vc_intro_import_csv($path);
check('both rows written', $result['tally']['written'] === 2,
      json_encode($result['tally']));
check('the intro lands on the term',
      get_term_meta(1, 'vc_intro', true) === 'Almost every shop ships to Alabama.',
      get_term_meta(1, 'vc_intro', true));
check('and on the second term',
      get_term_meta(2, 'vc_intro', true) === 'Shipping to Alaska is the exception.');
check('indexable count is reported', $result['indexable'] === 2,
      (string) $result['indexable']);
check('the fields read are reported', $result['fields'] === array('vc_intro'),
      json_encode($result['fields']));

/* --- A dry run must write nothing ------------------------------------- */

reset_store();
$result = vc_intro_import_csv($path, true);
check('a dry run still reports what it would write',
      $result['tally']['written'] === 2, json_encode($result['tally']));
check('but writes nothing', get_term_meta(1, 'vc_intro', true) === '',
      get_term_meta(1, 'vc_intro', true));

/* --- Existing copy is never destroyed by default ----------------------- */

reset_store();
update_term_meta(1, 'vc_intro', 'Copy I wrote by hand in the editor.');
$result = vc_intro_import_csv($path);
check('an occupied field is left alone',
      get_term_meta(1, 'vc_intro', true) === 'Copy I wrote by hand in the editor.',
      get_term_meta(1, 'vc_intro', true));
check('and it is reported rather than passed over in silence',
      $result['tally']['kept'] === 1, json_encode($result['tally']));
check('the untouched term still imports',
      get_term_meta(2, 'vc_intro', true) !== '');

$result = vc_intro_import_csv($path, false, true);
check('--overwrite does replace it',
      get_term_meta(1, 'vc_intro', true) === 'Almost every shop ships to Alabama.',
      get_term_meta(1, 'vc_intro', true));

/* --- A blank cell means "no opinion", not "delete" --------------------- */

reset_store();
update_term_meta(1, 'vc_faq_q1', 'Does anyone ship here?');
$blank = write_csv(array(
    array('term', 'vc_intro', 'vc_faq_q1'),
    array('Alabama', 'An intro and nothing else.', ''),
));
vc_intro_import_csv($blank, false, true);
check('a blank cell does not wipe an existing value',
      get_term_meta(1, 'vc_faq_q1', true) === 'Does anyone ship here?',
      get_term_meta(1, 'vc_faq_q1', true));
check('while the filled cell is written',
      get_term_meta(1, 'vc_intro', true) === 'An intro and nothing else.');

/* --- Typos are reported, never created -------------------------------- */

reset_store();
$typo = write_csv(array(
    array('taxonomy', 'term', 'vc_intro'),
    array('ships_to', 'Alabhama', 'A state that does not exist here.'),
));
$result = vc_intro_import_csv($typo);
check('an unknown term is skipped', $result['tally']['skipped'] === 1,
      json_encode($result['tally']));
check('and nothing is created', $GLOBALS['created'] === array(),
      json_encode($GLOBALS['created']));
check('the message names the term',
      strpos(implode(' ', $result['messages']), 'Alabhama') !== false,
      implode(' ', $result['messages']));

/* --- Only this plugin's taxonomies may be written --------------------- */

reset_store();
make_term(9, 'Uncategorised', 'category');
$foreign = write_csv(array(
    array('taxonomy', 'term', 'vc_intro'),
    array('category', 'Uncategorised', 'Writing onto a core taxonomy.'),
));
$result = vc_intro_import_csv($foreign);
check('a foreign taxonomy is refused', $result['tally']['skipped'] === 1,
      json_encode($result['tally']));
check('and nothing was written to it',
      get_term_meta(9, 'vc_intro', true) === '');

/* --- Columns that are not editorial fields are ignored ---------------- */

reset_store();
$sneaky = write_csv(array(
    array('term', 'vc_intro', 'description', 'term_id'),
    array('Alabama', 'A real intro.', 'not a field we write', '999'),
));
$result = vc_intro_import_csv($sneaky);
check('an unknown column is not written',
      get_term_meta(1, 'description', true) === '');
check('the known column still is',
      get_term_meta(1, 'vc_intro', true) === 'A real intro.');
check('only editorial fields are reported as read',
      $result['fields'] === array('vc_intro'), json_encode($result['fields']));

/* --- Malformed files fail loudly -------------------------------------- */

$no_term = write_csv(array(
    array('taxonomy', 'vc_intro'),
    array('ships_to', 'Nothing says which term this is for.'),
));
check('a file with no term column is an error',
      is_wp_error(vc_intro_import_csv($no_term)));

$no_fields = write_csv(array(
    array('taxonomy', 'term'),
    array('ships_to', 'Alabama'),
));
check('a file with no editorial column is an error',
      is_wp_error(vc_intro_import_csv($no_fields)));

check('an unreadable path is an error',
      is_wp_error(vc_intro_import_csv('/tmp/definitely-not-here-' . uniqid() . '.csv')));

reset_store();
$ragged = tempnam(sys_get_temp_dir(), 'ragged') . '.csv';
file_put_contents($ragged, "term,vc_intro\nAlabama,one,two,three\n");
$result = vc_intro_import_csv($ragged);
check('a row with the wrong column count is skipped, not guessed at',
      $result['tally']['skipped'] === 1, json_encode($result['tally']));
check('and the message explains why',
      strpos(implode(' ', $result['messages']), 'quotes') !== false,
      implode(' ', $result['messages']));

/* --- A BOM must not make the first column unmatchable ----------------- */

reset_store();
$bom = tempnam(sys_get_temp_dir(), 'bom') . '.csv';
file_put_contents($bom, "\xEF\xBB\xBFterm,vc_intro\nAlabama,\"Excel wrote this file.\"\n");
$result = vc_intro_import_csv($bom);
check('a file saved by Excel still imports',
      get_term_meta(1, 'vc_intro', true) === 'Excel wrote this file.',
      json_encode($result['tally']));

/* --- The taxonomy column may be left out ------------------------------ */

reset_store();
$bare = write_csv(array(
    array('term', 'vc_intro'),
    array('Alabama', 'No taxonomy column at all.'),
));
$result = vc_intro_import_csv($bare);
check('a file with no taxonomy column defaults to the first destination one',
      get_term_meta(1, 'vc_intro', true) === 'No taxonomy column at all.',
      json_encode($result['tally']));

/* --- Matching by slug as well as name --------------------------------- */

reset_store();
make_term(4, 'District of Columbia', 'ships_to', 'district-of-columbia');
$by_slug = write_csv(array(
    array('term', 'vc_intro'),
    array('district-of-columbia', 'Matched on the slug.'),
));
vc_intro_import_csv($by_slug);
check('a term can be matched on its slug',
      get_term_meta(4, 'vc_intro', true) === 'Matched on the slug.',
      get_term_meta(4, 'vc_intro', true));

/* --- Markup is filtered on the way in --------------------------------- */

reset_store();
$nasty = write_csv(array(
    array('term', 'vc_intro'),
    array('Alabama', 'Fine copy.<script>alert(1)</script>'),
));
vc_intro_import_csv($nasty);
check('script tags are stripped before storage',
      strpos(get_term_meta(1, 'vc_intro', true), '<script') === false,
      get_term_meta(1, 'vc_intro', true));

/* --- A legal note arrives with its source, or not at all -------------- */

reset_store();
$legal = write_csv(array(
    array('term', 'vc_legal_note', 'vc_legal_source_url', 'vc_legal_checked'),
    array('Alabama', 'Alabama requires a delivery-sale permit.',
          'https://www.publichealthlawcenter.org/resources/us-e-cigarette-regulations-50-state-review/al',
          '2026-10-22'),
));
$result = vc_intro_import_csv($legal);
check('a legal note imports', get_term_meta(1, 'vc_legal_note', true)
      === 'Alabama requires a delivery-sale permit.');
check('its source URL imports alongside it',
      get_term_meta(1, 'vc_legal_source_url', true)
      === 'https://www.publichealthlawcenter.org/resources/us-e-cigarette-regulations-50-state-review/al',
      get_term_meta(1, 'vc_legal_source_url', true));
check('and the date it was checked',
      get_term_meta(1, 'vc_legal_checked', true) === '2026-10-22');
check('a legal note makes the page indexable too', $result['indexable'] === 1,
      (string) $result['indexable']);

/* --- Re-running changes nothing --------------------------------------- */

reset_store();
vc_intro_import_csv($path);
$result = vc_intro_import_csv($path);
check('a second run reports no work to do',
      $result['tally']['written'] === 0 && $result['tally']['unchanged'] === 2,
      json_encode($result['tally']));

echo "\n";
if ($fails) {
    echo count($fails) . " check(s) FAILED: " . implode(', ', $fails) . "\n";
    exit(1);
}
echo "All term-copy importer checks passed.\n";
