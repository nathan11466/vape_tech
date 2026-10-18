# ReHub theme — architecture notes

Read from WPSoul's own documentation and articles on 2026-10-18. Confidence
is marked on each point, because several important facts are **inferred from
shortcode examples rather than stated outright** — the docs name very few
exact meta keys. Anything marked *inferred* must be confirmed against the
installed theme before code depends on it.

Sources are listed at the bottom.

## The architecture, and why it matters here

**ReHub does not use a coupon post type.** Coupons and deals are ordinary
WordPress **posts** carrying an "offer" meta section, displayed through deal
or coupon layouts. *(Stated, in two places.)*

**Stores are a taxonomy on posts, not a post type.** Enabled in Theme Options
→ Affiliate → "Enable Affiliate Store taxonomy for posts". *(Stated.)*

- Taxonomy slug: **`dealstore`**, changeable. *(Inferred — appears as
  `taxonomy="dealstore"` in shortcode examples and in URLs like
  `/dealstore/amazon/` and `/dealstore/gogreen-com/`, across two separate
  docs. Strong, but never stated as "the slug is dealstore".)*
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

## Meta keys

The docs name only a handful. **Do not guess the rest.**

| Key | What | Confidence |
|---|---|---|
| `rehub_offer_product_thumb` | offer image on a post | stated |
| `rehub_woo_coupon_coupon_img_url` | image for WooCommerce coupons | stated |
| `_notice_custom` | per-post cashback notice | stated |
| `cbtrkr_approved_usd`, `cbtrkr_pending_usd` | Cashback Tracker points | stated, but the article also prints `cbtkr_approved_usd` — one is a typo, verify in the install |

Offer fields that exist but whose storage keys are **not documented**: URL,
coupon text, coupon code, expiration date, reveal option, price.

**Expiry moved from meta to a taxonomy term** in current versions, and the
expired state is stored as a term. The expiry check runs lazily — only when a
visitor opens the page does the post get marked expired. *(Stated.)*

## The one shortcode that matters most for us

```
[wpsm_custom_meta field="_notice_custom" type="custom"]
```

ReHub can print **arbitrary post meta** on a page. *(Stated.)* That is the
hinge for any integration: if merchant data lands as post meta on
ReHub-shaped posts, ReHub's own templates can display it with no custom
template code.

Other shortcodes seen: `[wpsm_tax_archive]`, `[RH_ELEMENTOR]`,
`[wpsm_button_popup]`, plus review/scorebox/comparison shortcodes. There is
**no dedicated coupon shortcode** — coupon blocks come from the "Offerbox"
block in the theme's shortcode generator.

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

## Still unknown — needs the theme files or a live-site inspection

1. The real `dealstore` taxonomy slug as registered.
2. Every offer meta key: URL, coupon text, code, expiration, reveal, price.
3. The Offerbox block's generated shortcode and its attributes.
4. Whether ReHub registers anything that collides with our `ships_to`
   taxonomy or `merchant` post type.
5. Which template file renders a store archive, if a custom layout is wanted.

None of these should be guessed. Earlier in this project a coupon shortcode
was guessed as `[wcd_coupons]` when it was really `[coupon_deals]`, which
would have rendered an empty section silently.

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
