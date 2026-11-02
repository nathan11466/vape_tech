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
| `wp-merchant-fields.php` | **Main plugin.** Service-location taxonomy, meta fields, display helpers. Loads the rest. |
| `wp-merchant-shipping.php` | `ships_to` taxonomy — countries plus all 50 US states, DC and Canadian provinces |
| `derive_shipping.py` | Turns restriction prose into destination terms |
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

Everything imports as a **draft** so you can review before publishing. Tick
"Publish immediately" to skip that. A merchant you have already published is
never demoted back to draft by a re-import.

The import matches on `externalMerchantKey`, so re-running updates existing
pages rather than duplicating them — run it as often as the data improves.

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

Content is rendered live from meta via `[merchant_page]`, so changing the layout
or the gating rules applies to every merchant at once with no re-import.

## Fixing duplicate content: `--compose`

```bash
python3 enrich_merchants.py brands.csv --out enriched.csv --review queue.csv --compose
```

Spintax does not solve duplication. Rewording a generic sentence produces a
unique string carrying identical (zero) information, and modern search systems
evaluate meaning rather than surface text — so you would ship 112 pages that
still have no reason to rank, while taking on site-wide scaled-content risk.

`--compose` rebuilds the duplicated sections from each merchant's **own
extracted facts** — code names, shipping thresholds, return windows, restricted
states, excluded categories. The text differs because the underlying facts
differ, not because it was reworded.

Each section ends up in one of three states, tracked in `section_origins`:

| Origin | Meaning |
| --- | --- |
| `original` | Already merchant-specific; left untouched |
| `composed` | Rebuilt from this merchant's real values |
| `disclosure` | Nothing to build from, so the page says so plainly |

Composed, from Vape Street's own `DEVICE15` / `$70` / state-restriction fields:

> Codes at Vape Street usually fail for one of these reasons: DEVICE15 is marked
> as not combinable, so it fails when another code is already applied; FREESHIP
> requires a cart of $70 or more; orders shipping to California and
> Massachusetts may be blocked by state product restrictions.

Two rules keep this honest:

- **Boilerplate is never mined as a fact source.** Extracting "gift cards, sale
  items" out of the generic string and rebuilding a sentence from it would be
  spintax with extra steps, so fields being replaced are excluded as inputs.
- **A merchant with 3+ unconfirmed sections is flagged in `review_notes`** as
  likely to read thin. Advisory only — the call is yours.

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

## Grading is advisory

The grade does not gate anything. **WordPress post status is the publish gate** —
drafts are not public, and a post you published is one you decided to publish.
The columns below exist to sort your review queue, nothing more.

- **Hold** — carries an unsourced **negative** reputation claim ("flagged low
  trust", "scam score"). Worth looking at first.
- **Needs review** — boilerplate present, or specific claims with no source.
- **Ready** — merchant-specific detail plus a source, no flagged fields.

`content_score` ranks merchant-specific substance independent of sourcing. The
review queue is sorted by it — that is a sensible order to work in.

`review_notes` is shown at the top of each page in wp-admin to logged-in
editors only, so you can see what was flagged while reading the page itself.

## Filtering by shipping destination

`ships_to` is a hierarchical, public taxonomy: **United States** with all 50
states plus DC beneath it, **Canada** with its provinces, **United Kingdom**
with its nations, and the other countries alongside. Because it has archives,
`/ships-to/california/` becomes a browsable page listing every merchant that
ships there — useful for filtering and as a landing page in its own right.

Assigning a state also assigns its parent country, so country archives stay
complete.

`derive_shipping.py` resolves the terms from your existing restriction prose,
and marks how far the result can be trusted:

| Confidence | Source | Example |
| --- | --- | --- |
| `stated` | An explicit nationwide/all-states claim, minus its exceptions | "Ships nationwide except California and Massachusetts" |
| `inferred` | Named exclusions imply coverage of the rest | "Does not ship to Utah" |
| `unknown` | Nothing explicit — **no terms assigned** | "No shipping restrictions listed — confirm at checkout" |

That last row matters: a boilerplate non-statement is never treated as a
nationwide claim, so no merchant is listed as shipping somewhere on the strength
of filler text. Sort the enriched CSV by `shipping_confidence` to spot-check the
`inferred` rows.

Places a merchant will **not** ship are kept in `restricted_states` as meta
rather than terms — an archive of "merchants that cannot ship here" is not a
page anyone wants — and rendered on the merchant page as a "Cannot ship to:"
line.

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
