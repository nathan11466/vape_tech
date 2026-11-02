# ReHub theme — architecture notes

Read from WPSoul's documentation on 2026-10-18, then **verified against the
theme source** (ReHub + rehub-framework, ThemeForest package) on the same
day. Everything below is now read out of the code rather than inferred,
except where noted. Source paths are relative to the theme or the framework
plugin.

Sources are listed at the bottom.

## The architecture, and why it matters here

**ReHub does not use a coupon post type.** Coupons and deals are ordinary
WordPress **posts** carrying an "offer" meta section, displayed through deal
or coupon layouts. *(Stated, in two places.)*

**Stores are a taxonomy on posts, not a post type.** Enabled in Theme Options
→ Affiliate → "Enable Affiliate Store taxonomy for posts". *(Stated.)*

- Taxonomy: **`dealstore`**, registered on `post` in
  `rehub-framework/includes/taxonomy_cpt.php:28`. Hierarchical, public,
  `show_in_rest`, `show_admin_column`. The rewrite slug is the
  `rehub_deal_store_tag` theme option, defaulting to `dealstore`.
  **Registration is gated on the `enable_brand_taxonomy` option being 1** —
  without that, the taxonomy does not exist at all. *(Verified in source.)*
- **`offerexpiration`**, also on `post`, holds the expired state.
  *(Verified.)*
- Store archive template: **`taxonomy-dealstore.php`** in the theme root.
  *(Verified.)*
- Store term fields: link, description, bottom description, **SEO title**,
  Cashback Notice, **logo**. *(Stated in the cashback article.)*
- Store archives ship a **filter panel with tabs: All / Deals / Coupons /
  Sales / Expired**, driven by each post's offer section. *(Stated.)*
- Widget area: "Deal store archive sidebar". Brand listings via
  `[wpsm_tax_archive]`. *(Stated.)*

This is a genuinely different shape from both of the other systems here:

| | Stores | Coupons |
|---|---|---|
| ReHub | `dealstore` taxonomy | ordinary posts + offer meta |
| WP Coupon & Deals | `wcd_brand` taxonomy | `wcd_coupon` post type |
| vc-merchant-fields | `merchant` post type | — (reads the above) |

## Offer meta keys — the full set

Read from `rehub-framework/includes/theme_metabox.php`. These are the post
meta keys behind the Post Offer section, and they map onto our CSV columns
almost one-to-one.

| ReHub key | Holds | Our equivalent |
|---|---|---|
| `rehub_offer_name` | offer/store name | `display_brand_name` |
| `rehub_offer_product_url` | destination / affiliate link | `brand_url` |
| `rehub_offer_product_coupon` | the coupon code | coupon plugin's `_wcd_code` |
| `rehub_offer_coupon_date` | expiry date | `_wcd_expiration` |
| `rehub_offer_coupon_mask` | reveal mask | — |
| `rehub_offer_discount` | discount figure | `vc_merchant_max_discount()` |
| `rehub_offer_product_desc` | description | `coupon_intro` / `brand_summary` |
| `rehub_offer_product_thumb` | offer image | — |
| `rehub_offer_logo_url` | **logo URL** | `brand_logo_url` |
| `rehub_offer_product_price`, `..._price_old` | price, was-price | — |
| `rehub_offer_btn_text` | CTA label | — |
| `rehub_offer_verify_label` | **"verified" label** | `vc_merchant_offer_mode()` |
| `rehub_offer_disclaimer` | terms / exclusions | `_wcd_additional_info` |
| `rehub_offer_shortcode_generate` | generated shortcode | — |
| `rehub_woo_coupon_code`, `..._date`, `..._mask`, `..._coupon_img_url` | the WooCommerce-product equivalents | — |

Two of these matter more than the rest. `rehub_offer_logo_url` is exactly
what the logo sideloading work produces, and `rehub_offer_verify_label` is
exactly what the offer gate decides — so the honesty rules and the logo
pipeline both have somewhere to land.

From the cashback article, not the theme source: `_notice_custom` (per-post
cashback notice) and the Cashback Tracker point keys
`cbtrkr_approved_usd` / `cbtrkr_pending_usd`. That article also prints
`cbtkr_approved_usd` once — one spelling is a typo, so check the install if
cashback is ever wanted.

**Expiry moved from meta to a taxonomy term** in current versions, and the
expired state is stored as a term. The expiry check runs lazily — only when a
visitor opens the page does the post get marked expired. *(Stated.)*

## The one shortcode that matters most for us

ReHub registers **131 shortcodes** via a map in
`rehub-framework/includes/shortcodes.php`, loaded in `class-rehub.php:77`.
The integration hinge is this one, handled by `wpsm_get_custom_value()` at
`rehub-theme/shortcodes/shortcodes.php:3663`:

```
[wpsm_custom_meta field="free_shipping_info" type="custom" label="Shipping"]
```

Its attributes: `post_id`, `field`, `subfield`, `subsubfield`, `attrfield`,
`type` (default `custom`), `show_empty`, `label`, `posttext`, `icon`,
`list`, `labelclass`, `labelblock`, `posttextclass`, `showtoggle`,
`symbollimit`, `spanvalue`, `imageMapper`. *(Verified.)*

That is richer than the docs suggest — it prints **arbitrary post meta with
a label, an icon and trailing text**. So all 48 CSV columns could be
rendered on a ReHub page **with no custom template code at all**, which is
the single biggest finding in favour of letting ReHub own the page.

The offer and store shortcodes, verified from the map: `wpsm_offerbox`,
`quick_offer`, `wpsm_bigoffer`, `wpsm_offer_list`, `wpsm_add_deal_popup`,
`wpsm_tax_archive`, `wpsm_custom_meta`.

## Bulk import — worse than what we already have

ReHub has **no native CSV importer** for offers. It points at **WP All
Import** plus ReHub add-ons which the docs themselves describe as *"no longer
updated"*. Coupomated is named as a third-party auto-pilot alternative.
*(Stated.)*

So our CSV pipeline is the better tool for the 112-merchant dataset. Whatever
else changes, the pipeline is worth keeping.

## Schema — a real three-way conflict

ReHub outputs: **Review**, **Product** (WooCommerce only), **Event sales**
(experimental), **LocalBusiness** (BuddyPress profiles only). *(Stated.)* It
does **not** output Organization or a coupon/Offer type.

Only review schema can be switched off, via Theme Options → Reviews. There is
no documented way to disable Product, Event or LocalBusiness. *(Stated.)*

Overlaps to resolve:

- **Review / AggregateRating** — ReHub *and* WP Coupon & Deals both emit
  these. Two Review blocks on one page is the live conflict.
- **Organization** — ours and the coupon plugin's already share an `@id` so
  they merge. ReHub adds none, so no new conflict there.
- **Offer** — ours is already suppressed when real coupons exist.

WPSoul's own SEO article says plainly: do not add schema plugins on top of a
theme that already outputs schema, because it "will create errors". Their
docs also warn that Google can remove rich results for markup that does not
match the page. Both point the same way: **one system owns each schema type.**

## Their SEO guidance, where it bears on this project

- They recommend **Yoast**, not Rank Math. Our SEO module feeds Rank Math
  through its filters; that work would need re-pointing if the site moves to
  Yoast. Rank Math can stay — nothing in ReHub requires Yoast.
- **Noindex thin taxonomy archives**, and keep them indexed only once
  optimised — which is exactly the gate already built for the 51 destination
  pages.
- **Category descriptions of 500+ characters.** Same requirement as the state
  intros already outstanding.
- Paginated archives: `noindex, follow`.
- Internal linking in clusters with descriptive anchors, and a related block
  at the end of each post.
- The SEO article is from **2020** and parts are stale; verify settings
  against current plugin docs before following it.

## Name collisions — none

Checked every name our two plugins register against the whole theme and
framework source:

| Name | Result |
|---|---|
| `ships_to` | no collision |
| `service_location` | no collision |
| `merchant_category` | no collision |
| `merchant` (post type) | **no collision** — the string appears in 23 files, but only ever as a Content Egg array key (`$item['merchant']`), never registered as a post type or taxonomy |
| `wcd_coupon`, `wcd_brand` | no collision |

So all three systems can run side by side without WordPress-level clashes.
The remaining conflicts are editorial and schema-level, not structural.

## Still worth checking on the live install

1. Whether `enable_brand_taxonomy` is switched on — `dealstore` does not
   exist until it is.
2. Whether `rehub_deal_store_tag` has been changed from `dealstore`, which
   would move every store URL.
3. The Cashback Tracker point key spelling, if cashback is ever wanted.

## Sources

- https://rehubdocs.wpsoul.com/docs/rehub-theme/
- https://rehubdocs.wpsoul.com/docs/rehub-theme/affiliate-settings/coupon-functions/
- https://rehubdocs.wpsoul.com/docs/rehub-theme/affiliate-settings/how-to-add-brand-archive-page/
- https://rehubdocs.wpsoul.com/docs/rehub-theme/affiliate-settings/bulk-import-of-offers/
- https://rehubdocs.wpsoul.com/docs/rehub-theme/affiliate-settings/schema-types-for-deal-sites/
- https://wpsoul.com/how-to-make-cashback-site-on-wordpress-and-rehub-theme/
- https://wpsoul.com/optimize-seo-wordpress/

Not yet read, kept for reference:

- https://wpsoul.com/directory-review-classified-on-woocommerce/
- https://wpsoul.com/how-to-make-user-driven-community-with-rehub-theme/
- https://wpsoul.com/how-optimize-speed-of-wordpress/
- https://wpsoul.com/make-smart-profitable-deal-affiliate-comparison-site-woocommerce/
