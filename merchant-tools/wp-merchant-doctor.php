<?php
/**
 * What is actually in use on this site.
 *
 * This plugin was built to render its own /stores/ pages and has become an
 * adapter for ReHub's. Those are different jobs, and a lot of the first one
 * is still here. Reading the file list cannot tell dead code from code that
 * simply has not been switched on, so this reads the site instead and says
 * which it is.
 *
 * It changes nothing. Every line is a fact about this install, with a
 * verdict attached, so a feature can be retired on evidence rather than on
 * a guess about whether anything still depends on it.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One finding.
 *
 * $state is 'live', 'idle', 'dead' or 'warn':
 *   live  in use, leave it alone
 *   idle  switched off, but it would work -- a choice, not dead code
 *   dead  nothing can reach it in this configuration
 *   warn  live and causing a problem, or a half-finished state
 */
function vc_doctor_row($area, $state, $what, $detail = '') {
    return compact('area', 'state', 'what', 'detail');
}

/**
 * How many posts of a type exist, or -1 when the type is not registered.
 */
function vc_doctor_count($post_type, $status = 'publish') {
    if (!post_type_exists($post_type)) {
        return -1;
    }

    return count(get_posts(array(
        'post_type' => $post_type, 'post_status' => $status,
        'posts_per_page' => -1, 'fields' => 'ids',
    )));
}

/**
 * Every finding, in the order a person would want to read them.
 */
function vc_doctor_report() {
    $rows = array();

    /* --- Which store page is the real one ----------------------------- */

    $rehub = function_exists('vc_rehub_active') && vc_rehub_active();
    $split = function_exists('vc_storepage_split') && vc_storepage_split();
    $handover = function_exists('vc_rehub_handover') && vc_rehub_handover();
    $merchants = vc_doctor_count('merchant');

    $rows[] = vc_doctor_row('Destination', $rehub ? 'live' : 'dead',
        "ReHub dealstore taxonomy",
        $rehub ? 'present, so ReHub renders the store pages'
               : 'NOT registered -- every ReHub feature here is inert');

    if ($rehub && !$handover && $merchants > 0) {
        $rows[] = vc_doctor_row('Destination', 'warn',
            sprintf('%d merchant pages still public at /stores/', $merchants),
            'ReHub renders a second page for each of these. Two indexable '
            . 'URLs per shop is duplicate content. Either switch the handover '
            . 'on (ReHub Sync screen) so they 301, or accept the duplication '
            . 'deliberately.');
    }

    $rows[] = vc_doctor_row('Destination', $handover ? 'live' : 'idle',
        'Handover (our pages 301 to ReHub)',
        $handover ? 'on' : 'off -- our pages still serve');

    $rows[] = vc_doctor_row('Destination', $split ? 'live' : 'idle',
        'Three-slot split around ReHub\'s offers',
        $split ? 'on' : 'off -- the whole profile renders in one piece');

    /* --- Are the slots actually wired up ------------------------------ */

    if ($rehub && $split) {
        $wired = 0;
        $terms = get_terms(array('taxonomy' => 'dealstore', 'hide_empty' => false,
                                 'fields' => 'ids'));
        $terms = is_wp_error($terms) ? array() : $terms;
        foreach ($terms as $term_id) {
            $below = (string) get_term_meta($term_id, 'brand_second_description', true);
            if (strpos($below, '[merchant_page_below]') !== false) {
                $wired++;
            }
        }

        $rows[] = vc_doctor_row('Destination',
            ($wired && $wired === count($terms)) ? 'live' : 'warn',
            sprintf('%d of %d stores wired for the split', $wired, count($terms)),
            $wired === count($terms)
                ? 'every store carries the shortcodes'
                : 'the rest render whole instead of split. Re-run ReHub Sync '
                  . 'with "Replace everything" ticked.');
    }

    /* --- Coupons: which system is really feeding the pages ------------ */

    $wcd = vc_doctor_count('wcd_coupon', 'any');
    $native = 0;
    if (taxonomy_exists('dealstore')) {
        $native = count(get_posts(array(
            'post_type' => 'post', 'post_status' => 'publish',
            'posts_per_page' => -1, 'fields' => 'ids',
            'tax_query' => array(array('taxonomy' => 'dealstore',
                                       'operator' => 'EXISTS')),
        )));
    }

    $rows[] = vc_doctor_row('Coupons', $wcd > 0 ? 'live' : 'dead',
        sprintf('WP Coupon & Deals: %s', $wcd < 0 ? 'not installed' : $wcd . ' coupons'),
        $wcd > 0 ? 'still the only copy of this data' : '');

    $rows[] = vc_doctor_row('Coupons', $native > 0 ? 'live' : 'dead',
        sprintf('ReHub native offers: %d', $native),
        $native === 0 ? 'no offers on any store page yet' : '');

    if ($wcd > 0 && $native <= 1) {
        $rows[] = vc_doctor_row('Coupons', 'warn',
            'Migration not done',
            sprintf('%d coupons are in the old plugin and %d offers exist in '
                . 'ReHub. Until that runs, every store page shows an empty '
                . 'middle.', $wcd, $native));
    }

    $embed = get_option('vc_rehub_embed_coupons', '') === '1';
    $rows[] = vc_doctor_row('Coupons', $embed ? 'live' : 'dead',
        'Coupon shortcode embedding',
        $embed ? 'on' : 'off -- and for native offers it should stay off');

    /* --- Which blocks never render ------------------------------------ */

    if (function_exists('vc_layout_get')) {
        $layout = vc_layout_get();
        $off = array();
        foreach ($layout['where'] as $key => $where) {
            if ($where === 'off') {
                $off[] = $key;
            }
        }
        if ($off) {
            $rows[] = vc_doctor_row('Layout', 'idle',
                sprintf('%d blocks switched off', count($off)),
                implode(', ', $off) . ' -- their code never runs');
        }

        if ($rehub && $split && function_exists('vc_storepage_theme_blocks')) {
            $rows[] = vc_doctor_row('Layout', 'dead',
                'Title and hero blocks on ReHub pages',
                'ReHub draws its own heading and offer cards, so these stand '
                . 'down there. They still render at /stores/.');
        }
    }

    /* --- Settings that do nothing where you are ----------------------- */

    if ($rehub && $split) {
        $rows[] = vc_doctor_row('Appearance', 'dead',
            'H1 wording, sidebar width',
            'overridden on a ReHub store page; live only at /stores/');
    }

    $law = function_exists('vc_law_base') ? vc_law_base() : '';
    if ($law !== '' && strpos($law, '/staging/') !== false) {
        $rows[] = vc_doctor_row('Law links', 'warn',
            'Law links point at /staging/',
            'Set the base URL on Stores -> Law links to the production '
            . '/vape-laws/, or all 51 links break when this goes live.');
    }

    /* --- Half-finished data ------------------------------------------- */

    if ($rehub && function_exists('vc_rehub_unmatched_count')) {
        $unmatched = vc_rehub_unmatched_count();
        if ($unmatched > 0) {
            $rows[] = vc_doctor_row('Data', 'warn',
                sprintf('%d merchants have no ReHub store', $unmatched),
                'these render nothing on any store page');
        }
    }

    if (function_exists('vc_offers_source_ids') && $wcd > 0) {
        $orphans = 0;
        foreach (vc_offers_source_ids() as $cid) {
            if (!vc_offers_merchant_of($cid)) {
                $orphans++;
            }
        }
        if ($orphans) {
            $rows[] = vc_doctor_row('Data', 'warn',
                sprintf('%d of %d coupons match no merchant', $orphans, $wcd),
                'they would all be skipped by the migration. The link is '
                . 'wcd_brand_term_id on each merchant.');
        }
    }

    return apply_filters('vc_doctor_report', $rows);
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp merchant doctor
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant doctor', function () {
        $rows = vc_doctor_report();

        $marks = array('live' => '[live]', 'idle' => '[idle]',
                       'dead' => '[dead]', 'warn' => '[WARN]');

        $area = '';
        foreach ($rows as $row) {
            if ($row['area'] !== $area) {
                $area = $row['area'];
                WP_CLI::log('');
                WP_CLI::log('=== ' . $area);
            }
            WP_CLI::log(sprintf('  %-6s %s', $marks[$row['state']] ?? '[?]',
                $row['what']));
            if ($row['detail'] !== '') {
                WP_CLI::log(sprintf('         %s', wordwrap($row['detail'], 68,
                    "\n         ", false)));
            }
        }

        $warn = count(array_filter($rows, function ($r) {
            return $r['state'] === 'warn';
        }));
        $dead = count(array_filter($rows, function ($r) {
            return $r['state'] === 'dead';
        }));

        WP_CLI::log('');
        WP_CLI::log(sprintf('%d thing(s) to look at, %d inert in this setup.',
            $warn, $dead));
        WP_CLI::success('Nothing was changed.');
    });
}
