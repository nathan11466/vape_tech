# Merchant Tools

A pipeline for publishing SEO-optimised coupon/merchant pages to WordPress from
a merchant CSV — without publishing thin, duplicated, or unsourced content.

```
merchant CSV
   |
   |  enrich_merchants.py          grade, gate, rank
   v
enriched CSV
   |
   |  Tools > Import Merchants     create/update WordPress posts
   v
WordPress pages  ->  rendered live by [merchant_page] + JSON-LD schema
```

## Files

| File | Role |
| --- | --- |
| `enrich_merchants.py` | Grades the CSV, appends provenance columns, decides what each page may claim |
| `wp-merchant-fields.php` | **Main plugin.** Taxonomy, meta fields, display helpers. Loads the rest. |
| `wp-merchant-render.php` | The `[merchant_page]` shortcode that renders each page from meta |
| `wp-merchant-seo.php` | SEO titles, meta descriptions, JSON-LD schema, Rank Math integration |
| `wp-merchant-import.php` | CSV importer — admin screen plus a WP-CLI command |
| `preview_page.php` | Render a page at the command line before importing |

## Install

1. Upload the whole folder to `/wp-content/plugins/vc-merchant-fields/`
2. **Plugins → Activate** "VapingCheap Merchant Fields"
3. Point it at your coupon post type — without this, nothing attaches:

```php
add_filter('vc_merchant_post_types', function () {
    return array('your_coupon_cpt');
});
```

Find that value by editing any coupon and reading `post_type=` in the URL.
Deactivate and reactivate once the filter is in place, so the `service_location`
term tree gets seeded.

## Publish

```bash
python3 enrich_merchants.py brands_for_code_info_filled.csv \
    --out merchants-enriched.csv --review review-queue.csv
```

Then **Tools → Import Merchants**, upload `merchants-enriched.csv`, and run it
with **Dry run** ticked first to see what would happen. Untick to import.

Only merchants graded `Ready` are published. Everything else is imported as a
**draft**, so it is editable in wp-admin but never publicly reachable. The
import matches on `externalMerchantKey`, so re-running updates existing pages
rather than duplicating them — run it as often as the data improves.

With WP-CLI:

```bash
wp merchant import merchants-enriched.csv --dry-run
wp merchant import merchants-enriched.csv --limit=25
```

## What makes the pages SEO-optimised

- **Title** — `[Brand] Coupon Codes & Promo Codes - Month Year`, generated at
  render time so freshness never goes stale and you never re-import to bump a date
- **Meta description** — built from what is actually confirmed, so a page with no
  confirmed code does not promise one in the SERP snippet either
- **JSON-LD** — Organization (with `alternateName` aliases and `areaServed`),
  Offer when a deal is confirmed, FAQPage from real Q&A pairs, WebPage with
  `dateModified`
- **Rank Math** — fed through its own filters rather than competing tags, so
  Rank Math owns the `<head>` and any manual override an editor sets still wins
- **noindex on held merchants** — a page the gate refuses to render would
  otherwise be a thin empty URL

Content is rendered live from meta via `[merchant_page]`, so changing the layout
or the gating rules applies to every merchant at once with no re-import.

## The two safeguards

**Boilerplate detection.** The known generic fallbacks are caught by exact
match, *and* any value shared by 3+ merchants in a merchant-specific field is
flagged automatically (`--boilerplate-threshold` to tune). That second rule
catches boilerplate nobody has catalogued yet.

Fields that legitimately repeat — `payment_methods`, `verification_method`,
`brand_category`, `editor_name` — are exempt. Concrete values are exempt too:
four merchants can genuinely share a `$75` free-shipping threshold, and that is
a coinciding fact rather than copied filler.

**Offer display mode.** Derived per merchant, consumed by the template and the
schema:

| Mode | Meaning | Rendered as |
| --- | --- | --- |
| `verified_code` | A real code **plus** a source or recent test | "Verified Code" |
| `best_deal` | A concrete automatic discount or threshold, no code | "Best Deal" |
| `no_code_confirmed` | Nothing concrete confirmed | "No active code confirmed — see current deals" |

An unset mode falls through to `no_code_confirmed`, so a data gap never becomes
a false claim.

## Grading

- **Hold** — an unsourced **negative** reputation claim ("flagged low trust",
  "scam score"). Held merchants render nothing and are set to `noindex`.
- **Needs review** — boilerplate present, or specific claims with no source.
- **Ready** — merchant-specific detail plus a source, no flagged fields.

An unsourced *neutral* claim (a Trustpilot score) is not row-blocking: the field
is suppressed at render and the page is judged on its own merits. An unset
`publish_status` counts as unreviewed, never as approved.

`content_score` ranks merchant-specific substance independent of sourcing. The
review queue is sorted by it — that is the order to do sourcing work in.

## Data model notes

- **`service_location`** is the merchant's *operating market*. Where they ship is
  the separate `ships_to_countries` field — routinely different for vape and
  cannabinoid sellers with state and country restrictions.
- **Aliases** are meta, not a taxonomy: naming variants, not a classification.
  Use `display_brand_name` in permalink, title, H1 and schema; let aliases appear
  in body copy and the "Also known as" line.
- **Contact** fields stay blank when no public contact exists. Never invent an
  address or use WHOIS data.

## Not yet built

- Fetching merchant contact pages and classifying shipping coverage. The
  importer creates the columns; filling them is research the script does not do.
- Splitting merchant editorial records from individual offer records, so an
  expiring coupon stops forcing a rewrite of the whole merchant page.
