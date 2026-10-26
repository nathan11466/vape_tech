<?php
/**
 * EXAMPLE two-column merchant template.
 *
 * You do NOT need this file. By default each imported post contains
 * [merchant_page], and your theme renders it like any other post -- no
 * template work required.
 *
 * Use this only if you want a layout your theme cannot give you. To install:
 *
 *   1. Copy this file into your CHILD theme as single-merchant.php
 *      (a child theme, so a theme update does not delete it). If you pointed
 *      the plugin at a different post type, name it single-<that-type>.php.
 *
 *   2. Re-import with "Page content" set to "Leave empty". Otherwise the post
 *      body still holds [merchant_page] and the whole page renders twice --
 *      once from this template and once from the content.
 *
 * Everything here is ordinary WordPress. Rearrange, delete, or add your own
 * markup freely; the shortcodes each render one section and can go anywhere.
 */

get_header();
?>

<div class="vc-layout">
    <main class="vc-layout__main">
        <?php while (have_posts()) : the_post(); ?>

            <article <?php post_class(); ?>>

                <h1><?php
                    // Query-aligned H1 rather than the bare store name.
                    echo esc_html(function_exists('vc_merchant_seo_title')
                        ? vc_merchant_seo_title(get_the_ID())
                        : get_the_title());
                ?></h1>

                <?php
                // The offer claim. Never states a code the data has not confirmed.
                echo do_shortcode('[merchant_offer]');

                echo do_shortcode('[merchant_about]');
                echo do_shortcode('[merchant_policies]');
                echo do_shortcode('[merchant_faqs]');

                // Anything of your own goes here -- an affiliate disclosure, an
                // ad slot, a newsletter form.

                echo do_shortcode('[merchant_editorial]');
                ?>

            </article>

        <?php endwhile; ?>
    </main>

    <aside class="vc-layout__sidebar vc-sidebar">
        <?php
        // The info panel and related stores both collapse to a single column
        // inside .vc-sidebar, so they fit a narrow column without extra CSS.
        echo do_shortcode('[merchant_info]');
        echo do_shortcode('[merchant_trust]');
        echo do_shortcode('[merchant_related limit="6" title="Similar stores"]');

        // A theme sidebar underneath, if you have one registered.
        if (is_active_sidebar('sidebar-1')) {
            dynamic_sidebar('sidebar-1');
        }
        ?>
    </aside>
</div>

<style>
/* Minimal two-column shell. Delete this if your theme already provides one. */
.vc-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 320px;
    gap: 2.5rem;
    max-width: var(--vc-max-width, 1100px);
    margin: 0 auto;
    padding: 2rem 1.25rem 4rem;
}
.vc-layout__sidebar { min-width: 0; }
@media (max-width: 900px) {
    .vc-layout { grid-template-columns: 1fr; gap: 2rem; }
}
</style>

<?php
get_footer();
