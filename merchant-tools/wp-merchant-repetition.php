<?php
/**
 * Repetition report.
 *
 * The page copy came out of a spreadsheet, and a spreadsheet column filled
 * from a template produces 112 stores saying the same sentence. On one page
 * that is fine. Across 112 near-identical pages it is the thin-content
 * pattern search engines discount, and it is invisible from any single page
 * -- you only see it by looking down the column.
 *
 * This measures it. It does NOT rewrite anything, block publishing, or score
 * copy as good or bad: those are editorial calls, made by a person. All it
 * answers is "which columns are boilerplate, and how badly", so the work of
 * rewriting goes where it buys something.
 *
 * Store names are blanked before comparing. "Free shipping over $50 at
 * 2Fdeal" and "Free shipping over $50 at VooPoo" are the same sentence with
 * a name swapped, and counting them as distinct would hide exactly what we
 * are looking for.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The prose columns worth measuring.
 *
 * Only fields that render as sentences on the page. A URL, a date or a
 * payment list repeating across stores is a fact, not duplicated writing --
 * flagging those would bury the real findings in noise.
 *
 * This is the same set as CONTENT_FIELDS in enrich_merchants.py, and it
 * deliberately leaves out everything in that script's REPEATING_BY_DESIGN:
 * payment_methods repeats because most stores really do take the same cards,
 * and verification_method describes our editorial process rather than the
 * store, so both would score near 100% and send the editing effort at a
 * problem that is not one.
 */
function vc_rep_fields() {
    return apply_filters('vc_rep_fields', array(
        'coupon_intro'          => __('Intro', 'vc-merchant'),
        'brand_summary'         => __('About the store', 'vc-merchant'),
        'best_ways_to_save'     => __('Best ways to save', 'vc-merchant'),
        'best_offer_summary'    => __('Best offer', 'vc-merchant'),
        'free_shipping_info'    => __('Shipping', 'vc-merchant'),
        'return_policy_summary' => __('Returns', 'vc-merchant'),
        'common_exclusions'     => __('Exclusions', 'vc-merchant'),
        'stacking_policy'       => __('Stacking codes', 'vc-merchant'),
        'why_code_not_work'     => __('If a code fails', 'vc-merchant'),
        'shipping_restrictions' => __('Restrictions', 'vc-merchant'),
        'company_trust_info'    => __('Company info', 'vc-merchant'),
        'faq_1_answer'          => __('FAQ 1 answer', 'vc-merchant'),
        'faq_2_answer'          => __('FAQ 2 answer', 'vc-merchant'),
        'faq_3_answer'          => __('FAQ 3 answer', 'vc-merchant'),
    ));
}

/**
 * Every name a store goes by, longest first, for blanking.
 *
 * Longest first matters: blanking "Vape" before "Vape Street" would leave
 * a stray "Street" behind and make two identical sentences look different.
 */
function vc_rep_store_names($post_ids) {
    $names = array();

    foreach ($post_ids as $post_id) {
        $candidates = array(
            get_the_title($post_id),
            get_post_meta($post_id, 'display_brand_name', true),
            get_post_meta($post_id, '2nd_Display Name', true),
            get_post_meta($post_id, 'brand_dot_com', true),
        );

        $alt = (string) get_post_meta($post_id, 'alternative_brand_names', true);
        foreach (preg_split('/[|,]/', $alt) as $one) {
            $candidates[] = $one;
        }

        foreach ($candidates as $name) {
            $name = trim((string) $name);
            // Two characters or fewer matches inside ordinary words.
            if (strlen($name) > 2) {
                $names[$name] = true;
            }
        }
    }

    $names = array_keys($names);
    usort($names, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    return $names;
}

/**
 * A value reduced to comparable prose: names blanked, case and punctuation
 * and whitespace dropped.
 */
function vc_rep_normalize($text, $names = array()) {
    $text = (string) $text;

    foreach ($names as $name) {
        $text = preg_replace('/(?<![A-Za-z0-9])' . preg_quote($name, '/')
            . '(?![A-Za-z0-9])/i', ' ', $text);
    }

    $words = preg_split('/[^A-Za-z0-9]+/', strtolower($text), -1,
        PREG_SPLIT_NO_EMPTY);

    return implode(' ', $words);
}

/**
 * Overlapping word runs of a normalized string.
 *
 * Catches partial reuse -- the same opening clause with a different tail --
 * which an exact comparison misses.
 */
function vc_rep_shingles($normalized, $size = 8) {
    $words = $normalized === '' ? array() : explode(' ', $normalized);
    $out = array();

    for ($i = 0; $i + $size <= count($words); $i++) {
        $out[implode(' ', array_slice($words, $i, $size))] = true;
    }

    return $out;
}

/**
 * Measure one field across the given stores.
 *
 * Returns filled/distinct counts plus the identical clusters, biggest first.
 */
function vc_rep_field_report($key, $post_ids, $names) {
    $groups = array();
    $filled = 0;

    foreach ($post_ids as $post_id) {
        $raw = trim((string) get_post_meta($post_id, $key, true));
        if ($raw === '') {
            continue;
        }
        $filled++;

        $norm = vc_rep_normalize($raw, $names);
        if ($norm === '') {
            // A value that was nothing but the store's own name.
            continue;
        }

        if (!isset($groups[$norm])) {
            $groups[$norm] = array('count' => 0, 'stores' => array(), 'sample' => $raw);
        }
        $groups[$norm]['count']++;
        if (count($groups[$norm]['stores']) < 6) {
            $groups[$norm]['stores'][] = get_the_title($post_id);
        }
    }

    uasort($groups, function ($a, $b) {
        return $b['count'] - $a['count'];
    });

    $shared = 0;
    foreach ($groups as $group) {
        if ($group['count'] > 1) {
            $shared += $group['count'];
        }
    }

    return array(
        'key'       => $key,
        'filled'    => $filled,
        'distinct'  => count($groups),
        'shared'    => $shared,
        'clusters'  => $groups,
    );
}

/**
 * Pairs of stores whose copy overlaps without being identical.
 *
 * Built from an inverted index rather than comparing every pair: 112 stores
 * is 6,216 pairs per field, and almost all of them share nothing.
 */
function vc_rep_near_pairs($key, $post_ids, $names, $min_shared = 3) {
    $index = array();
    $sets  = array();

    foreach ($post_ids as $post_id) {
        $norm = vc_rep_normalize(get_post_meta($post_id, $key, true), $names);
        $grams = vc_rep_shingles($norm);
        if (empty($grams)) {
            continue;
        }
        $sets[$post_id] = array('norm' => $norm, 'n' => count($grams));
        foreach (array_keys($grams) as $gram) {
            $index[$gram][] = $post_id;
        }
    }

    $counts = array();
    foreach ($index as $holders) {
        // A run shared by half the catalogue is the house style, not a pair.
        if (count($holders) > 1 && count($holders) < 12) {
            foreach ($holders as $i => $a) {
                foreach (array_slice($holders, $i + 1) as $b) {
                    $pair = $a < $b ? "$a:$b" : "$b:$a";
                    $counts[$pair] = ($counts[$pair] ?? 0) + 1;
                }
            }
        }
    }

    $pairs = array();
    foreach ($counts as $pair => $shared) {
        if ($shared < $min_shared) {
            continue;
        }
        list($a, $b) = array_map('intval', explode(':', $pair));

        // Identical copy is already reported as a cluster.
        if (($sets[$a]['norm'] ?? '') === ($sets[$b]['norm'] ?? '')) {
            continue;
        }

        $smaller = max(1, min($sets[$a]['n'], $sets[$b]['n']));
        $pairs[] = array(
            'a'        => get_the_title($a),
            'b'        => get_the_title($b),
            'shared'   => $shared,
            'fraction' => $shared / $smaller,
        );
    }

    usort($pairs, function ($x, $y) {
        return $y['shared'] - $x['shared'];
    });

    return $pairs;
}

/**
 * The published stores to measure.
 */
function vc_rep_post_ids() {
    return get_posts(array(
        'post_type'      => vc_merchant_post_types(),
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ));
}

/**
 * The whole report: one entry per field, worst first.
 */
function vc_rep_report($fields = null) {
    $post_ids = vc_rep_post_ids();
    if (empty($post_ids)) {
        return array('stores' => 0, 'fields' => array());
    }

    $names  = vc_rep_store_names($post_ids);
    $fields = $fields ?: array_keys(vc_rep_fields());
    $out    = array();

    foreach ($fields as $key) {
        $report = vc_rep_field_report($key, $post_ids, $names);
        if ($report['filled'] === 0) {
            continue;
        }
        $report['near'] = vc_rep_near_pairs($key, $post_ids, $names);
        $out[] = $report;
    }

    // Worst first: the share of stores repeating someone else's wording.
    usort($out, function ($a, $b) {
        $ra = $a['filled'] ? $a['shared'] / $a['filled'] : 0;
        $rb = $b['filled'] ? $b['shared'] / $b['filled'] : 0;
        if ($ra === $rb) {
            return $b['shared'] - $a['shared'];
        }

        return $ra < $rb ? 1 : -1;
    });

    return array('stores' => count($post_ids), 'fields' => $out);
}

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

add_action('admin_menu', function () {
    add_submenu_page('edit.php?post_type=merchant',
        __('Repetition report', 'vc-merchant'),
        __('Repetition report', 'vc-merchant'),
        'edit_posts', 'vc-merchant-repetition', 'vc_rep_screen');
});

function vc_rep_screen() {
    if (!current_user_can('edit_posts')) {
        return;
    }

    $labels = vc_rep_fields();
    $report = vc_rep_report();

    echo '<div class="wrap"><h1>' . esc_html__('Repetition report', 'vc-merchant')
        . '</h1>';

    if (!$report['stores']) {
        echo '<p>' . esc_html__('No published stores to measure yet.', 'vc-merchant')
            . '</p></div>';

        return;
    }

    echo '<p>' . esc_html(sprintf(
        /* translators: %d: number of stores */
        __('Measured across %d published stores. Store names are blanked before comparing, so the same sentence with a different brand in it counts as repeated. Nothing here changes your copy.', 'vc-merchant'),
        $report['stores'])) . '</p>';

    echo '<table class="widefat striped"><thead><tr>'
        . '<th>' . esc_html__('Column', 'vc-merchant') . '</th>'
        . '<th>' . esc_html__('Filled', 'vc-merchant') . '</th>'
        . '<th>' . esc_html__('Distinct', 'vc-merchant') . '</th>'
        . '<th>' . esc_html__('Repeating', 'vc-merchant') . '</th>'
        . '<th>' . esc_html__('Worst shared wording', 'vc-merchant') . '</th>'
        . '</tr></thead><tbody>';

    foreach ($report['fields'] as $field) {
        $share = $field['filled']
            ? round(100 * $field['shared'] / $field['filled']) : 0;
        $label = $labels[$field['key']] ?? $field['key'];

        $worst = '';
        foreach ($field['clusters'] as $cluster) {
            if ($cluster['count'] < 2) {
                break;
            }
            $worst .= '<p style="margin:0 0 .6em"><strong>'
                . esc_html(sprintf(
                    /* translators: %d: number of stores sharing one value */
                    _n('%d store', '%d stores', $cluster['count'], 'vc-merchant'),
                    $cluster['count']))
                . ':</strong> <code>'
                . esc_html(mb_substr($cluster['sample'], 0, 150))
                . (mb_strlen($cluster['sample']) > 150 ? '&hellip;' : '')
                . '</code><br /><span class="description">'
                . esc_html(implode(', ', $cluster['stores']))
                . (count($cluster['stores']) < $cluster['count'] ? '&hellip;' : '')
                . '</span></p>';
            if (substr_count($worst, '<p ') >= 3) {
                break;
            }
        }

        if ($worst === '') {
            $worst = '<span class="description">'
                . esc_html__('No two stores share this wording.', 'vc-merchant')
                . '</span>';
        }

        printf('<tr><td><strong>%s</strong><br /><code>%s</code></td>'
            . '<td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
            esc_html($label), esc_html($field['key']),
            (int) $field['filled'], (int) $field['distinct'],
            esc_html($share . '%'), $worst);
    }

    echo '</tbody></table>';

    // Partial reuse, which the identical clusters above do not show.
    $near = array();
    foreach ($report['fields'] as $field) {
        foreach (array_slice($field['near'], 0, 3) as $pair) {
            $pair['field'] = $labels[$field['key']] ?? $field['key'];
            $near[] = $pair;
        }
    }

    if (!empty($near)) {
        echo '<h2>' . esc_html__('Partly reused wording', 'vc-merchant') . '</h2>';
        echo '<p class="description">' . esc_html__('Pairs that are not identical but share long runs of words -- usually one value edited into another.', 'vc-merchant') . '</p>';
        echo '<table class="widefat striped"><thead><tr>'
            . '<th>' . esc_html__('Column', 'vc-merchant') . '</th>'
            . '<th>' . esc_html__('Stores', 'vc-merchant') . '</th>'
            . '<th>' . esc_html__('Shared 8-word runs', 'vc-merchant') . '</th>'
            . '</tr></thead><tbody>';
        foreach (array_slice($near, 0, 25) as $pair) {
            printf('<tr><td>%s</td><td>%s &harr; %s</td><td>%d (%d%%)</td></tr>',
                esc_html($pair['field']), esc_html($pair['a']), esc_html($pair['b']),
                (int) $pair['shared'], (int) round(100 * $pair['fraction']));
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}

/* -------------------------------------------------------------------------
 * WP-CLI: wp merchant repetition [--field=<key>] [--csv]
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant repetition', function ($args, $assoc) {
        $fields = isset($assoc['field'])
            ? array_filter(array_map('trim', explode(',', $assoc['field'])))
            : null;

        $report = vc_rep_report($fields);
        if (!$report['stores']) {
            WP_CLI::error('No published stores to measure.');
        }

        $labels = vc_rep_fields();
        WP_CLI::log(sprintf('%d published stores', $report['stores']));
        WP_CLI::log('');

        if (!empty($assoc['csv'])) {
            WP_CLI::log('field,filled,distinct,repeating,repeating_pct');
            foreach ($report['fields'] as $field) {
                WP_CLI::log(sprintf('%s,%d,%d,%d,%d', $field['key'],
                    $field['filled'], $field['distinct'], $field['shared'],
                    $field['filled']
                        ? round(100 * $field['shared'] / $field['filled']) : 0));
            }

            return;
        }

        foreach ($report['fields'] as $field) {
            $share = $field['filled']
                ? round(100 * $field['shared'] / $field['filled']) : 0;
            WP_CLI::log(sprintf('%-24s %3d filled  %3d distinct  %3d%% repeating',
                $field['key'], $field['filled'], $field['distinct'], $share));

            $shown = 0;
            foreach ($field['clusters'] as $cluster) {
                if ($cluster['count'] < 2 || $shown >= 2) {
                    break;
                }
                WP_CLI::log(sprintf('    x%-3d %s', $cluster['count'],
                    substr(preg_replace('/\s+/', ' ', $cluster['sample']), 0, 96)));
                $shown++;
            }
        }
    });
}
