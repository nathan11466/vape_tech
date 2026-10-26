<?php
/**
 * Keeping the month in cached pages current.
 *
 * Store titles carry the current month, generated at render time, but a page
 * cache stores the finished HTML -- so without this every page keeps saying
 * last month. The edge cases are what matter: it must not purge on a first
 * run, must not purge twice in a month, and must still record the month if a
 * purge throws.
 *
 *     php tests/test_freshness.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

$GLOBALS['options'] = array();
$GLOBALS['scheduled'] = array();
$GLOBALS['now'] = '2026-10-15 09:00:00';
$GLOBALS['purges'] = 0;
$GLOBALS['terms'] = array(50, 51);
$GLOBALS['term_meta'] = array();

function current_time($format) {
    return $format === 'mysql' ? $GLOBALS['now'] : date($format, strtotime($GLOBALS['now']));
}
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['options'][$k] = $v; return true; }
function wp_next_scheduled($h) { return $GLOBALS['scheduled'][$h] ?? false; }
function wp_schedule_event($t, $r, $h) { $GLOBALS['scheduled'][$h] = $t; return true; }
function wp_unschedule_event($t, $h) { unset($GLOBALS['scheduled'][$h]); return true; }
function add_action() {}
function add_filter() {}
function add_submenu_page() {}
function has_action($t, $f = false) { return false; }
function do_action($t) {
    if ($t !== 'vc_merchant_caches_purged') { return; }
    if (!empty($GLOBALS['throw_on_purge'])) {
        throw new Exception('cache plugin blew up');
    }
    $GLOBALS['purges']++;
}
function taxonomy_exists($t) { return $t === 'wcd_brand'; }
function get_terms($a = array()) { return $GLOBALS['terms']; }
function delete_term_meta($id, $k) { unset($GLOBALS['term_meta'][$id][$k]); return true; }
function is_wp_error($t) { return false; }
function current_user_can() { return false; }
function wp_verify_nonce() { return false; }
function wp_nonce_field() {}
function submit_button() {}
function date_i18n($f, $ts = null) { return date($f, $ts ?: strtotime($GLOBALS['now'])); }
function esc_html($v) { return htmlspecialchars((string) $v, ENT_QUOTES); }
function esc_html__($v, $d = null) { return $v; }
function esc_html_e($v, $d = null) { echo $v; }
function __($v, $d = null) { return $v; }
function vc_merchant_post_types() { return array('merchant'); }

require __DIR__ . '/../wp-merchant-freshness.php';

$fail = 0;
function check($label, $cond, $detail = '') {
    global $fail;
    if ($cond) { echo "PASS: $label\n"; }
    else { echo "FAIL: $label" . ($detail ? " -- $detail" : '') . "\n"; $fail++; }
}

/* -------------------------------------------------------------------------
 * The first run records the month without purging
 *
 * A purge the moment the plugin is installed is a surprise on a large site,
 * and nothing is stale yet.
 * ---------------------------------------------------------------------- */

check('the first check does not purge', vc_freshness_check() === false);
check('but it records the month',
    get_option('vc_merchant_freshness_month') === '2026-10',
    (string) get_option('vc_merchant_freshness_month'));
check('and nothing was cleared', $GLOBALS['purges'] === 0);

/* -------------------------------------------------------------------------
 * Within the same month, nothing happens
 * ---------------------------------------------------------------------- */

$GLOBALS['now'] = '2026-10-31 23:59:00';
check('a later day in the same month does not purge', vc_freshness_check() === false);
check('still nothing cleared', $GLOBALS['purges'] === 0);

/* -------------------------------------------------------------------------
 * The month rolls over
 * ---------------------------------------------------------------------- */

$GLOBALS['now'] = '2026-11-01 00:30:00';
check('the first check in a new month purges', vc_freshness_check() === true);
check('caches were cleared once', $GLOBALS['purges'] === 1);
check('and the new month is recorded',
    get_option('vc_merchant_freshness_month') === '2026-11');

check('a second check the same month does not purge again',
    vc_freshness_check() === false);
check('so caches are not cleared twice', $GLOBALS['purges'] === 1);

/* -------------------------------------------------------------------------
 * A missed month must still catch up
 *
 * WP-Cron only fires on a visit, so a quiet site can skip the 1st entirely.
 * This is the case a cron scheduled for the 1st gets wrong.
 * ---------------------------------------------------------------------- */

$GLOBALS['now'] = '2027-02-14 12:00:00';
check('a check months later still purges', vc_freshness_check() === true);
check('and records the month it actually ran in',
    get_option('vc_merchant_freshness_month') === '2027-02');

/* -------------------------------------------------------------------------
 * The month is recorded before purging
 *
 * If a purge fatals partway through, the month must already be stored --
 * otherwise every subsequent request retries the purge.
 * ---------------------------------------------------------------------- */

$GLOBALS['now'] = '2027-03-01 00:10:00';
$GLOBALS['options']['vc_merchant_freshness_month'] = '2027-02';
$GLOBALS['throw_on_purge'] = true;
try {
    vc_freshness_check();
} catch (Exception $e) {
    // expected
}
check('the month is stored even when a purge throws',
    get_option('vc_merchant_freshness_month') === '2027-03',
    (string) get_option('vc_merchant_freshness_month'));
$GLOBALS['throw_on_purge'] = false;

/* -------------------------------------------------------------------------
 * Forcing, and the cached brand figures
 * ---------------------------------------------------------------------- */

$before = $GLOBALS['purges'];
check('a forced check purges within the same month',
    vc_freshness_check(true) === true);
check('and did clear the caches', $GLOBALS['purges'] === $before + 1);

$GLOBALS['term_meta'] = array(
    50 => array('_vc_figures_updated' => '2027-02-01 00:00:00', '_vc_max_discount' => '25'),
    51 => array('_vc_figures_updated' => '2027-02-01 00:00:00'),
);
vc_freshness_clear_brand_figures();
check('the cached brand figures are dropped so they recompute',
    !isset($GLOBALS['term_meta'][50]['_vc_figures_updated'])
    && !isset($GLOBALS['term_meta'][51]['_vc_figures_updated']),
    json_encode($GLOBALS['term_meta']));
check('without discarding the figures themselves',
    ($GLOBALS['term_meta'][50]['_vc_max_discount'] ?? '') === '25');

/* -------------------------------------------------------------------------
 * Scheduling
 * ---------------------------------------------------------------------- */

$GLOBALS['scheduled'] = array();
vc_freshness_schedule();
check('a daily check is scheduled', isset($GLOBALS['scheduled']['vc_merchant_freshness_check']));

$first = $GLOBALS['scheduled']['vc_merchant_freshness_check'];
vc_freshness_schedule();
check('scheduling twice does not stack a second event',
    $GLOBALS['scheduled']['vc_merchant_freshness_check'] === $first);

vc_freshness_unschedule();
check('deactivation leaves nothing scheduled',
    !isset($GLOBALS['scheduled']['vc_merchant_freshness_check']));
// A second unschedule must not resurrect or error.
vc_freshness_unschedule();
check('unscheduling twice leaves it unscheduled',
    wp_next_scheduled('vc_merchant_freshness_check') === false);

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All freshness checks passed.\n";
