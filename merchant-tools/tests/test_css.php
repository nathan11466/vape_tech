<?php
/**
 * The stylesheet's own invariants, read out of the file itself.
 *
 * Two bugs on the live store pages had one cause. A block lays itself out as
 * a grid with a minimum track width -- 230px for the related-store links,
 * 170px for the merchant facts -- and ReHub's sidebar is about 220px. When
 * the track does not fit, CSS does not wrap: it overflows. The facts panel
 * printed "United States" one letter per line, and the similar-store links
 * hung 28px outside the column.
 *
 * Both already had a fix. Both fixes were scoped to .vc-sidebar and .widget,
 * and ReHub's wrapper is .vc-storepage--side, which is neither. So the rule
 * existed, was correct, and never ran. Enumerating container classes by hand
 * is what failed, twice, and it will fail again the next time a grid or a
 * wrapper is added.
 *
 * So this does not test rendering. It reads the stylesheet and asserts that
 * every grid which sizes its own columns is collapsed to one column in every
 * narrow container. A new grid, or a new wrapper, fails here rather than on
 * a published page.
 *
 *     php tests/test_css.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);

$css = file_get_contents(__DIR__ . '/../assets/merchant-pages.css');
if ($css === false) {
    echo "cannot read assets/merchant-pages.css\n";
    exit(1);
}

$fail = 0;
function check($what, $ok, $detail = '') {
    global $fail;
    if ($ok) {
        echo "PASS: $what\n";
        return;
    }
    $fail++;
    echo "FAIL: $what" . ($detail !== '' ? " -- $detail" : '') . "\n";
}

/* The containers that are roughly one sidebar wide. .vc-storepage--side is
 * ReHub's; the other two are ours. A new one belongs in this list. */
$narrow = array('.vc-sidebar', '.widget', '.vc-storepage--side');

/* The widest a narrow column gets. ReHub's measures 220px at a 1440px
 * viewport; ours is set by --vc-sidebar-width. A track wider than this
 * cannot fit, so the grid must be collapsed. */
$column_px = 260;

/* --- Strip comments, so prose in them is never mistaken for a rule ----- */

$code = preg_replace('!/\*.*?\*/!s', '', $css);

/* --- Every rule in the file, as selector => declarations --------------- */

preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $code, $rules, PREG_SET_ORDER);

/* --- Which grids size their own columns, and how wide they ask for ----- */

$auto = array();   // selector => minimum track width in px
foreach ($rules as $rule) {
    $selector = trim($rule[1]);
    $body = $rule[2];

    if (!preg_match('/grid-template-columns\s*:\s*repeat\(\s*auto-(?:fit|fill)\s*,\s*minmax\(\s*(\d+)px/i',
                    $body, $m)) {
        continue;
    }

    // "a, b" declares the same grid for both, so each is its own entry.
    foreach (explode(',', $selector) as $one) {
        $one = trim(preg_replace('/\s+/', ' ', $one));
        if ($one === '') {
            continue;
        }
        // Only the base rules matter. A rule already scoped to a narrow
        // container is the fix, not the problem.
        foreach ($narrow as $container) {
            if (strpos($one, $container) !== false) {
                continue 2;
            }
        }
        $auto[$one] = (int) $m[1];
    }
}

check('the stylesheet declares at least one self-sizing grid',
    count($auto) > 0, 'found ' . count($auto));

/* --- Each of them must be collapsed in each narrow container ----------- */

/* The selectors that are set to a single column somewhere in the file. */
$collapsed = array();
foreach ($rules as $rule) {
    if (!preg_match('/grid-template-columns\s*:\s*1fr\s*(?:;|$)/i', $rule[2])) {
        continue;
    }
    foreach (explode(',', $rule[1]) as $one) {
        $collapsed[] = trim(preg_replace('/\s+/', ' ', $one));
    }
}

foreach ($auto as $selector => $min) {
    if ($min <= 150) {
        // A track this narrow fits any column we have, so auto-fit simply
        // gives one column by itself. Nothing to collapse.
        check("$selector asks for only {$min}px, so it fits a narrow column",
            true);
        continue;
    }

    foreach ($narrow as $container) {
        $want = $container . ' ' . $selector;
        check("$selector is collapsed to one column in $container",
            in_array($want, $collapsed, true),
            "no rule '$want { grid-template-columns: 1fr }'");
    }
}

/* --- The track widths themselves must be plausible -------------------- */

foreach ($auto as $selector => $min) {
    check("$selector asks for a track a page can give it",
        $min <= 400, $min . 'px');
}

/* --- The two-column definition lists have the same problem ------------- */

/* .vc-merchant-info dl and .vc-policy are "label | value" grids with a fixed
 * first column. In a narrow container they must stack instead. */
$pairs = array();
foreach ($rules as $rule) {
    if (!preg_match('/grid-template-columns\s*:\s*minmax\(\s*(\d+)px\s*,\s*(\d+)px\s*\)\s+1fr/i',
                    $rule[2], $m)) {
        continue;
    }
    foreach (explode(',', $rule[1]) as $one) {
        $one = trim(preg_replace('/\s+/', ' ', $one));
        foreach ($narrow as $container) {
            if (strpos($one, $container) !== false) {
                continue 2;
            }
        }
        // The label column plus a readable value column.
        $pairs[$one] = (int) $m[2];
    }
}

check('the stylesheet declares at least one label/value grid',
    count($pairs) > 0, 'found ' . count($pairs));

foreach ($pairs as $selector => $label_px) {
    foreach ($narrow as $container) {
        $stacked = in_array($container . ' ' . $selector, $collapsed, true);
        // display:block is the other way to stop a grid laying out in
        // columns, and is what the sidebar rules use.
        $blocked = (bool) preg_match(
            '/' . preg_quote($container, '/') . '[^{}]*' . preg_quote($selector, '/')
            . '[^{}]*\{[^{}]*display\s*:\s*block/i', $code);

        check("$selector stacks rather than columns in $container",
            $stacked || $blocked,
            "label column is {$label_px}px wide and nothing stacks it");
    }
}

/* --- The dark-mode opt-in must stay an opt-in -------------------------- */

/* :root:not([data-theme="light"]) matches every page that does not set the
 * attribute, which is every page on this site. It is how the store pages
 * came to have dark boxes with white text. */
check('dark mode is not applied by a :not() that matches everything',
    strpos($code, ':root:not([data-theme="light"])') === false,
    'found :root:not([data-theme="light"])');

echo "\n";
if ($fail) { echo "$fail check(s) FAILED\n"; exit(1); }
echo "All stylesheet invariant checks passed.\n";
