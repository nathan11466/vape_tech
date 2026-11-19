# What is still missing from the merchant data, and why

Status of the 111-merchant dataset after the full policy pass on
2026-10-21. The point of this file is to separate what a machine can still
get from what genuinely needs a person.

## Filled by the scraper

Run `fetch_merchant_data.py` with no `--skip-policies`. It reads each
store's homepage, then up to fourteen candidate policy pages.

| Column | Filled | Where it comes from |
|---|---|---|
| `fact_source_url` / `fact_last_verified` | 100 / 111 | the policy page the facts were read from |
| `restricted_states` | 24 | an exclusion list on a shipping or FAQ page |
| `do_they_id_on_delivery` | 24 | adult-signature wording in a policy |
| `social_links` | 81 | footer links |
| `shipping_policy_url` | 93 | footer links, then Shopify's fixed paths |
| `returns_policy_url` | 98 | same |
| `contact_page_url` | 97 | same |
| `contact_email` | 69 | `mailto:` links and page text, infrastructure addresses filtered out |
| `brand_logo_url` | 102 | schema.org, `og:image`, then markup |
| `trust_source_url` / `trust_verified_at` | 11 | a review-site profile the store links to itself |

`fact_source_url` filling 100 rows is what moved **67 merchants from
"Needs review" to "Ready"**. Nothing on a page can be presented as a
checked fact without a page to cite for it, so the sourcing gate was
holding back almost the whole set.

## Cannot be filled automatically, and why

**`trust_source_url` for the other 100.** Trustpilot answers 403 to
anything that is not a browser, so a profile URL built from a domain
cannot be verified before being written — and an unverified profile link
is a claim nobody checked. Only profiles a store links to from its own
site are taken. The rest is a manual lookup, two minutes each.

**Eight stores could not be read at all.** Diagnosed individually:

| Store | HTTP | What it means |
|---|---|---|
| `vape-street.com` | 403 | bot-blocked; a normal browser gets through |
| `dhgate.com` | 403 | same |
| `cigavette.co` | 403 | same |
| `vapes.com` | 200, empty body | bot defence serving nothing to non-browsers |
| `smokespotvape.com` | 402 | Payment Required — the shop is suspended |
| `bellavapor.com` | 526 | the origin's TLS certificate is invalid |
| `beyouonline.co.uk` | 404 | **domain is dead** |
| `vapeclutch.com` | 404 | **domain is dead** |

The first four will work from a home connection. The last four are not a
scraping problem: two are broken and two are gone, and an affiliate page
for a dead store is worth less than no page.

**The 51 state intros.** Writing, not data. Until each has one, its page
stays `noindex` by design.

## Judgement calls the scraper deliberately leaves alone

`restrictions-to-verify.csv` lists all 24 finds with the page each came
from, flagged where a human should look:

- **Six long lists (15–29 states).** Hemp and delta-8 stores really are
  banned in that many states, so these are plausible — but a list that
  long is often *per product line*, not store-wide. One store's page reads
  "All cannabinoid products: not available in Alabama, California, Idaho,
  New Jersey. THCa: not available in [38 more]". Merging those says the
  store ships nowhere. Lists over 30 states are now rejected outright for
  that reason; 15–30 are kept and flagged.
- **D8 Super Store.** Its list is real but covers kratom and amanita
  muscaria specifically, not the store's whole catalogue. Whether that
  belongs in a store-level field is an editorial decision.

## False positives found and fixed

Each of these had written a wrong state into a live column:

1. **Inline JavaScript read as prose.** Stripping tags left every line of
   a theme's scripts behind as text, full of state names in option lists.
   Scripts and styles are now removed first.
2. **`ban` matched inside Albany, banner and urban.** The loose cue words
   (`ban`, `restrict`, `prohibit`) now need word boundaries *and* a
   sentence that is actually about shipping or selling.
3. **A nav bar is not a sentence.** Menus carry no punctuation, so forty
   link labels flattened into one sentence — which put Pennsylvania into a
   store's exclusions out of the blog headline "Pennsylvania Vape Ban
   2026" sitting near the words "Free Shipping". Each link's text is now
   its own unit.
4. **Counties read as states.** "Sarasota County (FL)", "Denver (CO)",
   "Union County, Florida" — six counties and towns had removed six whole
   states from one store.
5. **A product collection cited as the shipping policy.** A nav link
   reading "Free shipping over $50" pointing at `/collections/all` won the
   `shipping_policy_url` slot on 18 rows across 13 stores, and that URL is
   published on the page as the source. Collection, product, blog, cart
   and account paths are now never policy pages, and a policy-shaped path
   beats policy-sounding link text.
6. **A placeholder contradicting a real find.** `shipping_restrictions`
   said "could not confirm any state shipping restrictions" while
   `restricted_states` listed seven. The placeholder — and only the
   placeholder — is now replaced when a real list turns up.

Two of these (`ban` and the nav bar) were only visible by checking the
outliers against the live pages. A fill rate is not a correctness check.

## Re-running

The output is rewritten after every merchant and each one finished is
recorded in `<out>.done`, so a run that dies resumes:

```
python3 fetch_merchant_data.py merchants.csv --out filled.csv --resume
```

`--cache DIR` keeps every page fetched. With a warm cache, re-running
after a parser change takes seconds instead of an hour, which is how the
six fixes above were tested against all 111 stores. Delete the directory
to go back to the live sites.
