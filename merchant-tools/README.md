# Merchant Tools

Enrichment and publish-gating for vapingcheap.com coupon/merchant pages.

The goal is entity-rich merchant pages that beat aggregators on specificity —
without publishing thin, duplicated, or unsourced content. Two pieces:

| File | Role |
| --- | --- |
| `enrich_merchants.py` | Grades the merchant CSV, appends provenance columns, decides what each page may claim |
| `wp-merchant-fields.php` | WordPress taxonomy, meta fields, and the display helpers that enforce those decisions |

## Workflow

```bash
python3 enrich_merchants.py brands_for_code_info_filled.csv \
    --out merchants-enriched.csv \
    --review review-queue.csv
```

`merchants-enriched.csv` is the full dataset with new columns. `review-queue.csv`
is only the rows needing work, so it doubles as the editorial task list.

Publish the `Ready` rows first — target a cohort of 15–25 before scaling.

## The two safeguards

**Boilerplate detection.** The four known generic fallbacks are caught by exact
match, *and* any value shared by 3+ merchants in a merchant-specific field is
flagged automatically (`--boilerplate-threshold` to tune). That second rule is
what catches boilerplate nobody has written down yet.

Fields that legitimately repeat — `payment_methods`, `verification_method`,
`brand_category`, `editor_name` — are exempt. Most merchants really do take Visa,
and `verification_method` describes our editorial process, not the merchant.

**Offer display mode.** Derived per merchant, consumed by the template:

| Mode | Meaning | Rendered as |
| --- | --- | --- |
| `verified_code` | A real code **plus** a source or recent test | "Verified Code" |
| `best_deal` | A concrete automatic discount or threshold, no code | "Best Deal" |
| `no_code_confirmed` | Nothing concrete confirmed | "No active code confirmed — see current deals" |

An unset mode falls through to `no_code_confirmed`, so a data gap never becomes a
false claim.

## Grading

`content_confidence` is High / Medium / Low; `publish_status` is Ready / Needs
review / Hold.

- **Hold** — an unsourced reputation claim is present. `company_trust_info`
  requires both `fact_source_url` and `fact_last_verified` before it may render.
- **Needs review** — boilerplate present, or specific claims with no source.
- **Ready** — merchant-specific detail plus a source.

An unset `publish_status` counts as unreviewed, never as approved.

## WordPress side

`wp-merchant-fields.php` registers:

- **`service_location`** — hierarchical taxonomy (North America / Europe /
  Asia-Pacific / International Shipping / Online Only), seeded idempotently on
  activation. This is the merchant's **operating market**. Where they *ship* is
  the separate `ships_to_countries` meta field — routinely different for vape and
  cannabinoid sellers with state and country restrictions.
- **Alias meta** — `alternative_brand_names` (pipe-delimited) and
  `display_brand_name`. Aliases are meta, not a taxonomy: they are naming
  variants, not a browsable classification. Use the canonical `display_brand_name`
  in permalink, title, H1, and schema; let aliases appear naturally in body copy
  and an "Also known as" line.
- **Contact meta** — `contact_email`, `contact_page_url`, `contact_method`,
  `contact_verified_at`, `contact_source_url`. Leave blank when no public contact
  exists; never invent an address or use WHOIS data.

Helpers: `vc_merchant_offer_badge()`, `vc_merchant_info_panel()`,
`vc_merchant_trust_info()`, `vc_merchant_aliases_line()`,
`vc_merchant_display_name()`, `vc_merchant_is_publishable()`.

Point them at your coupon post type via the `vc_merchant_post_types` filter:

```php
add_filter('vc_merchant_post_types', fn() => array('your_coupon_cpt'));
```

## Not yet built

- Fetching merchant contact pages and classifying shipping coverage — needs
  network access and per-merchant verification.
- Splitting merchant editorial records from individual offer records, so an
  expiring coupon stops forcing a rewrite of the whole merchant page.
