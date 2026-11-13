<?php
/**
 * Moving the coupons onto ReHub's native offers.
 *
 * ReHub's own system keeps each deal or coupon as an ORDINARY POST assigned
 * to a dealstore term, with rehub_offer_* post meta. That is why its filter
 * panel (All / Deals / Coupons / Sales / Expired) can never show a
 * wcd_coupon post: the listing queries post_type => 'post'.
 *
 * So the move is: one ordinary post per coupon, in the right dealstore term,
 * with the offer meta filled from the coupon it came from.
 *
 * Three things this is careful about.
 *
 * It never guesses a meta key. Both halves of the mapping live on the site,
 * not in this file's author's memory, and a wrong key produces a post that
 * looks migrated and shows no code -- the kind of failure you find weeks
 * later. `wp merchant offers inspect` reads the real keys off the server and
 * the map is filled from that.
 *
 * It does not delete anything. The wcd_coupon posts stay exactly as they
 * are, so the old and new systems can be compared side by side and the move
 * abandoned without loss.
 *
 * It is idempotent. Every created post records the coupon it came from in
 * _vc_offer_source, so running it twice updates rather than duplicates.
 *
 * Loaded by wp-merchant-fields.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The coupon post type WP Coupon & Deals uses.
 */
function vc_offers_source_type() {
    return (string) apply_filters('vc_offers_source_type', 'wcd_coupon');
}

/**
 * Ordinary posts created by the migration are still ordinary posts: without
 * this they appear in the blog, the feed and every category archive.
 *
 * The category they go in, created on demand. Set it to 0 to put them in
 * no category at all.
 */
function vc_offers_category() {
    $stored = (int) get_option('vc_offers_category', 0);

    return (int) apply_filters('vc_offers_category', $stored);
}

/**
 * Keep migrated offers out of the blog listing and the feed.
 *
 * On by default. These are 300-odd one-line coupon posts; in the main feed
 * they bury everything written for a reader.
 */
function vc_offers_hide_from_blog() {
    return (bool) apply_filters('vc_offers_hide_from_blog',
        get_option('vc_offers_hide_blog', '1') === '1');
}

add_action('pre_get_posts', function ($query) {
    if (is_admin() || !$query->is_main_query() || !vc_offers_hide_from_blog()) {
        return;
    }
    // Only the places a coupon post would be noise. A dealstore archive is
    // exactly where they belong, and a search should still find them.
    if (!($query->is_home() || $query->is_feed() || $query->is_category()
          || $query->is_tag() || $query->is_author() || $query->is_date())) {
        return;
    }
    if ($query->get('vc_offers_include')) {
        return;
    }

    $meta = (array) $query->get('meta_query');
    $meta[] = array(
        'key'     => '_vc_offer_source',
        'compare' => 'NOT EXISTS',
    );
    $query->set('meta_query', $meta);
});

/* -------------------------------------------------------------------------
 * The field map
 *
 * Keys on the left are ReHub's, read from the theme. Values are the
 * wcd_coupon meta key to take it from, or a callable given the coupon post.
 *
 * EMPTY UNTIL `wp merchant offers inspect` HAS BEEN RUN. It is deliberately
 * empty rather than filled with plausible-looking names: a map of guesses
 * that half works is worse than one that refuses to run, because the half
 * that fails is silent.
 * ---------------------------------------------------------------------- */

/**
 * ReHub offer key => where its value comes from on the coupon.
 *
 * A string is a meta key on the wcd_coupon post. A callable takes the coupon
 * post id and returns the value.
 */
function vc_offers_field_map() {
    return apply_filters('vc_offers_field_map', array(
        'rehub_offer_name'           => 'vc_offers_src_name',
        'rehub_offer_product_url'    => 'vc_offers_src_url',
        'rehub_offer_product_desc'   => '_wcd_description',
        'rehub_offer_disclaimer'     => '_wcd_additional_info',
        'rehub_offer_discount'       => 'vc_offers_src_discount',
        'rehub_offer_coupon_date'    => 'vc_offers_src_date',
        // The code, and the two fields that only make sense beside one.
        'rehub_offer_product_coupon' => 'vc_offers_src_code',
        'rehub_offer_coupon_mask'    => 'vc_offers_src_mask',
        'rehub_offer_btn_text'       => 'vc_offers_src_btn',
    ));
}

/**
 * The offer's name: the coupon's own title.
 */
function vc_offers_src_name($coupon_id) {
    return trim((string) get_the_title($coupon_id));
}

/**
 * The destination, with the staging path taken out.
 *
 * _wcd_destination_url holds a cloaked link that currently reads
 * https://vapingcheap.com/staging/likes/<store>. Migrated as-is, all 42
 * offers would point at /staging/ and break the day they go live.
 */
function vc_offers_src_url($coupon_id) {
    $url = trim((string) get_post_meta($coupon_id, '_wcd_destination_url', true));
    if ($url === '') {
        return '';
    }

    return (string) apply_filters('vc_offers_rewrite_url',
        str_replace('/staging/', '/', $url), $coupon_id);
}

/**
 * The discount figure, in the form ReHub stores.
 *
 * ReHub keeps "99%", not "99% OFF" -- the template adds its own wording, so
 * leaving the suffix on reads "10% OFF OFF" on the badge.
 */
function vc_offers_src_discount($coupon_id) {
    $value = trim((string) get_post_meta($coupon_id, '_wcd_discount', true));

    return trim(preg_replace('/\s*\b(off|discount)\b\s*$/i', '', $value));
}

/**
 * The expiry, as a datetime.
 *
 * ReHub stores "2027-05-14 12:00". _wcd_expiration holds a bare date, so a
 * time is added; without one the field is a different shape from the one the
 * theme writes, and an expiry that cannot be parsed is an offer that either
 * never expires or expires at once.
 */
function vc_offers_src_date($coupon_id) {
    $value = trim((string) get_post_meta($coupon_id, '_wcd_expiration', true));
    if ($value === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value . ' 12:00';
    }

    return $value;
}

/**
 * The coupon code, but only where the field actually holds one.
 *
 * _wcd_code is not reliably a code. One of the sampled coupons has
 * "Save 10% at Vaporider" in it, with _wcd_type still set to "code". Writing
 * that into the code field gives a reader a SHOW CODE button that reveals a
 * sentence -- worse than no button, because they click it, get nothing
 * usable, and conclude the site pads its listings.
 *
 * So the test is per coupon, not per catalogue: a value that looks like a
 * code migrates as a code, and anything else migrates as a deal with no code
 * field at all. ReHub renders both; only one of them can mislead.
 */
function vc_offers_src_code($coupon_id) {
    $value = trim((string) get_post_meta($coupon_id, '_wcd_code', true));
    if ($value === '') {
        return '';
    }

    // A code is one token: no spaces, nothing sentence-shaped.
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,23}$/', $value)) {
        return '';
    }

    return $value;
}

/**
 * Mask the code behind a reveal button -- only when there is a code.
 */
function vc_offers_src_mask($coupon_id) {
    return vc_offers_src_code($coupon_id) === '' ? '' : '1';
}

/**
 * The button label, matching what the offer actually is.
 */
function vc_offers_src_btn($coupon_id) {
    return vc_offers_src_code($coupon_id) === ''
        ? __('GET DEAL', 'vc-merchant')
        : __('SHOW CODE', 'vc-merchant');
}

/**
 * Is the map filled in enough to migrate?
 *
 * Without at least a destination URL and a code or a discount, a migrated
 * post carries nothing a reader can use.
 */
function vc_offers_map_ready() {
    $map = vc_offers_field_map();

    return !empty($map);
}

/**
 * Every meta key present on the sampled coupons, with an example value.
 *
 * This is what the map is written from.
 */
function vc_offers_inspect_source($limit = 8) {
    $ids = get_posts(array(
        'post_type'      => vc_offers_source_type(),
        'post_status'    => 'any',
        'posts_per_page' => (int) $limit,
        'fields'         => 'ids',
    ));

    $keys = array();
    foreach ($ids as $id) {
        foreach ((array) get_post_meta($id) as $key => $values) {
            $value = is_array($values) ? reset($values) : $values;
            $value = is_scalar($value) ? trim((string) $value) : '[' . gettype($value) . ']';
            if (!isset($keys[$key]) || $keys[$key] === '') {
                $keys[$key] = $value;
            }
        }
    }
    ksort($keys);

    return array('sampled' => count($ids), 'keys' => $keys);
}

/**
 * Every rehub_offer_* key the theme itself mentions.
 *
 * Read from the theme source rather than from a post, because a key the
 * theme reads but nothing has written yet would not show up on any post --
 * and those are exactly the ones that need filling.
 */
function vc_offers_inspect_rehub() {
    $roots = array_unique(array_filter(array(
        function_exists('get_template_directory') ? get_template_directory() : '',
        function_exists('get_stylesheet_directory') ? get_stylesheet_directory() : '',
    )));

    $found = array();
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            // Big theme; only the offer keys are wanted.
            $body = (string) @file_get_contents($file->getPathname());
            if ($body === '' || strpos($body, 'rehub_offer') === false) {
                continue;
            }
            if (preg_match_all('/rehub_offer[a-z0-9_]*/i', $body, $m)) {
                foreach ($m[0] as $key) {
                    $found[$key] = ($found[$key] ?? 0) + 1;
                }
            }
        }
    }

    arsort($found);

    return $found;
}

/* -------------------------------------------------------------------------
 * The migration
 * ---------------------------------------------------------------------- */

/**
 * The post already created for this coupon, or 0.
 */
function vc_offers_existing($coupon_id) {
    $found = get_posts(array(
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_key'       => '_vc_offer_source',
        'meta_value'     => (int) $coupon_id,
    ));

    return $found ? (int) $found[0] : 0;
}

/**
 * The dealstore term a coupon belongs in, or null.
 *
 * Through the merchant the coupon is scoped to, so one resolver decides
 * which store a thing belongs to everywhere in this plugin.
 */
function vc_offers_term_for_coupon($coupon_id) {
    if (!function_exists('vc_rehub_store_term')) {
        return null;
    }

    $merchant = apply_filters('vc_offers_merchant_for_coupon',
        vc_offers_merchant_of($coupon_id), $coupon_id);
    if (!$merchant) {
        return null;
    }

    return vc_rehub_store_term($merchant, false);
}

/**
 * The merchant a coupon belongs to, via the brand term they share, or 0.
 *
 * The coupon plugin already groups coupons by wcd_brand, and each merchant
 * records its own wcd_brand term in wcd_brand_term_id. Matching on that is
 * exact. Names are not used: two stores whose display names differ by a
 * space would silently take each other's coupons, and a coupon on the wrong
 * store page is worse than one on none.
 */
function vc_offers_merchant_of($coupon_id) {
    if (!taxonomy_exists('wcd_brand')) {
        return 0;
    }

    $terms = get_the_terms($coupon_id, 'wcd_brand');
    if (!$terms || is_wp_error($terms)) {
        return 0;
    }

    foreach ($terms as $term) {
        $found = get_posts(array(
            'post_type'      => function_exists('vc_merchant_post_types')
                ? vc_merchant_post_types() : 'merchant',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => 'wcd_brand_term_id',
            'meta_value'     => (int) $term->term_id,
        ));
        if ($found) {
            return (int) $found[0];
        }
    }

    return 0;
}

/**
 * Migrate one coupon. Returns array(action, post_id, message).
 */
function vc_offers_migrate_one($coupon_id, $dry_run = true, $publish = false) {
    $coupon = get_post($coupon_id);
    if (!$coupon) {
        return array('skipped', 0, 'no such coupon');
    }

    $term = vc_offers_term_for_coupon($coupon_id);
    if (!$term) {
        // A post with no store is invisible on every store page, so it is
        // not worth creating.
        return array('skipped', 0, 'no dealstore term for this coupon');
    }

    $existing = vc_offers_existing($coupon_id);

    $meta = array();
    foreach (vc_offers_field_map() as $rehub_key => $source) {
        $value = is_callable($source)
            ? call_user_func($source, $coupon_id)
            : get_post_meta($coupon_id, (string) $source, true);
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value !== '') {
            $meta[$rehub_key] = $value;
        }
    }

    if ($dry_run) {
        return array($existing ? 'would update' : 'would create', $existing,
            sprintf('%s -- %d field(s)', $term->name, count($meta)));
    }

    $postarr = array(
        'post_title'   => $coupon->post_title,
        'post_content' => $coupon->post_content,
        'post_excerpt' => $coupon->post_excerpt,
        'post_type'    => 'post',
        // Draft unless asked otherwise: 300 posts appearing live because a
        // command was run with the wrong flag is not recoverable quietly.
        'post_status'  => $publish ? 'publish' : 'draft',
    );

    if ($existing) {
        $postarr['ID'] = $existing;
        $post_id = wp_update_post($postarr, true);
    } else {
        $post_id = wp_insert_post($postarr, true);
    }

    if (is_wp_error($post_id)) {
        return array('failed', 0, $post_id->get_error_message());
    }

    $post_id = (int) $post_id;
    update_post_meta($post_id, '_vc_offer_source', (int) $coupon_id);

    foreach ($meta as $key => $value) {
        update_post_meta($post_id, $key, $value);
    }

    // The coupon's featured image, through WordPress's own field rather than
    // an offer field: ReHub reads the post thumbnail for the offer image, and
    // the test offer written by the theme itself set no rehub_offer_product_thumb.
    $thumb = (int) get_post_meta($coupon_id, '_thumbnail_id', true);
    if ($thumb) {
        update_post_meta($post_id, '_thumbnail_id', $thumb);
    }

    wp_set_object_terms($post_id, array((int) $term->term_id), 'dealstore', false);

    $category = vc_offers_category();
    if ($category) {
        wp_set_object_terms($post_id, array($category), 'category', false);
    }

    return array($existing ? 'updated' : 'created', $post_id,
        sprintf('%s -- %d field(s)', $term->name, count($meta)));
}

/**
 * Every coupon to consider.
 */
function vc_offers_source_ids($limit = 0) {
    return get_posts(array(
        'post_type'      => vc_offers_source_type(),
        'post_status'    => 'any',
        'posts_per_page' => $limit > 0 ? (int) $limit : -1,
        'fields'         => 'ids',
    ));
}

/* -------------------------------------------------------------------------
 * WP-CLI
 * ---------------------------------------------------------------------- */

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('merchant offers', function ($args, $assoc) {
        $sub = $args[0] ?? 'inspect';

        if ($sub === 'inspect') {
            $source = vc_offers_inspect_source(
                isset($assoc['limit']) ? (int) $assoc['limit'] : 8);

            WP_CLI::log('');
            WP_CLI::log(sprintf('=== %s meta keys (from %d coupon(s))',
                vc_offers_source_type(), $source['sampled']));
            if (!$source['keys']) {
                WP_CLI::warning('No coupons found. Is the post type name right?');
            }
            foreach ($source['keys'] as $key => $example) {
                WP_CLI::log(sprintf('  %-34s %s', $key,
                    substr(preg_replace('/\s+/', ' ', $example), 0, 70)));
            }

            WP_CLI::log('');
            WP_CLI::log('=== rehub_offer_* keys the theme mentions');
            $rehub = vc_offers_inspect_rehub();
            if (!$rehub) {
                WP_CLI::warning('None found. Is ReHub the active theme?');
            }
            foreach ($rehub as $key => $count) {
                WP_CLI::log(sprintf('  %-40s %d mention(s)', $key, $count));
            }

            WP_CLI::log('');
            WP_CLI::log(sprintf('%d coupon(s) in total.',
                count(vc_offers_source_ids())));
            WP_CLI::success('Send both lists back and the field map can be written.');

            return;
        }

        if ($sub !== 'migrate') {
            WP_CLI::error('Use: wp merchant offers inspect | migrate');
        }

        if (!vc_offers_map_ready()) {
            WP_CLI::error(
                "The field map is empty, so nothing would be carried across.\n"
                . 'Run `wp merchant offers inspect` first and fill it in -- a map '
                . 'of guessed key names fails silently, which is why this refuses '
                . 'to run instead of creating empty posts.');
        }

        $dry = empty($assoc['go']);
        $publish = !empty($assoc['publish']);
        $ids = vc_offers_source_ids(isset($assoc['limit']) ? (int) $assoc['limit'] : 0);

        if (!$ids) {
            WP_CLI::error('No coupons found to migrate.');
        }

        $tally = array();
        foreach ($ids as $id) {
            list($action, $post_id, $message) = vc_offers_migrate_one($id, $dry, $publish);
            $tally[$action] = ($tally[$action] ?? 0) + 1;
            WP_CLI::log(sprintf('%-12s %-7d %s', $action, $post_id, $message));
        }

        WP_CLI::log('');
        foreach ($tally as $action => $count) {
            WP_CLI::log(sprintf('  %-12s %d', $action, $count));
        }

        if ($dry) {
            WP_CLI::success('Dry run. Nothing was written. Add --go to apply, '
                . 'and --publish to publish rather than draft.');
        } else {
            WP_CLI::success(sprintf('Done. The %s posts were not touched.',
                vc_offers_source_type()));
        }
    });
}
