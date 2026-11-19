# Importing coupons into ReHub as offers

A ReHub offer is an ordinary **post** with two things attached:

1. the **`dealstore` taxonomy term** naming the store, and
2. **`rehub_offer_*` post meta** holding the code, discount and dates.

The November import set neither. It brought across titles and body text, so
the coupons exist as posts but belong to no store page, and the one that did
get a store renders as a plain article with a "READ MORE +" link instead of a
card with a reveal button.

Both are mapping problems in the importer, not missing data. This file says
exactly what to map.

## The spreadsheet

`rehub-offer-import-sample.csv` beside this file has five filled rows using
store names that exist on the site today, so a test import matches real
stores rather than silently creating new ones.

### The ones that must be right

| column | maps to | notes |
| --- | --- | --- |
| `id` | — | **Your own stable key**, any format, as long as a given coupon keeps the same one forever. It is not a ReHub field. It is what lets a second import UPDATE a coupon instead of adding a duplicate: without it, re-importing a corrected sheet of 40 leaves you with 80 posts. Add it even if nothing reads it yet. |
| `store` | **taxonomy `dealstore`** | Must match an existing term EXACTLY, including capitals and spaces: `Binoid CBD`, not `binoid-cbd`. This is the field whose absence hid all 39 coupons. |
| `title` | post title | What the card shows as the offer's heading. |
| `destination_url` | `rehub_offer_product_url` | The affiliate link the button opens. |
| `coupon_code` | `rehub_offer_product_coupon` | Required for coupons. Leave EMPTY for a deal with no code. |

### Worth filling

| column | maps to | notes |
| --- | --- | --- |
| `discount` | `rehub_offer_discount` | `10%`, `$15`, `Free shipping`. Only a per-cent value is comparable, so only those are ranked when the stats panel works out a store's best discount. |
| `expires` | `rehub_offer_coupon_date` | Format `Y-m-d H:i`, e.g. `2027-03-31 23:59`. Empty means no expiry. A wrong format is read as no expiry rather than failing, so it fails quietly. |
| `verified` | `rehub_offer_verify_label` | **Drives the "Verified" count in the sidebar stats panel** — that panel counts offers whose label is non-empty. A date works well. Only fill it for an offer somebody actually checked; an unchecked offer leaves it empty, because the figure is published as a claim about the shop. |
| `status` | post status | `publish` or `draft`. Importing as `draft` lets a batch land for review before any of it is public. Row 4 of the sample is a draft so the shape is visible. |
| `button_text` | `rehub_offer_btn_text` | `Get Code` for a coupon, `Shop Now` for a deal. |
| `description` | `rehub_offer_product_desc` | One or two lines under the title. |
| `disclaimer` | `rehub_offer_disclaimer` | Small print: minimum spend, exclusions. |

### Only for some offers

| column | maps to | notes |
| --- | --- | --- |
| `logo_url` | `rehub_offer_logo_url` | A per-offer logo. Usually unnecessary: the logo pipeline already sets a logo per shop, and this would override it for one card. |
| `image_url` | `rehub_offer_product_thumb` | An image on the card. Worth it for a named-product deal, not for a sitewide code. |
| `price` / `was_price` | `rehub_offer_product_price` / `rehub_offer_product_price_old` | For a specific product at a specific price — the card then shows the new price struck through the old. Meaningless on a sitewide code, so leave both empty there. Row 6 of the sample is this shape. |
| `offer_type` | — | Not imported. It is there so a human can sort the sheet, and as a reminder that a row with no code is a deal. |

### Deliberately not in the sheet

- **`offerexpiration`** is a ReHub taxonomy with a single term, `yes`. The
  theme adds it ITSELF once the expiry date passes, lazily, the first time a
  visitor opens the page. Do not map it; setting it by hand marks a live
  offer expired.
- **`rehub_offer_name`** duplicates the post title in practice.
- **`rehub_offer_shortcode_generate`** is internal to the theme's own editor.

## The one field that is not in the spreadsheet

**`rehub_offer_coupon_mask` must be set to `1`** on every row that has a
coupon code. It is what turns the card into a reveal button; without it the
code is either shown in the clear or not shown at all, and the click that
fires the affiliate link never happens.

It is a constant rather than a column, so in WP All Import set it as a fixed
value on the custom-fields step rather than mapping it from the sheet. If you
would rather have it in the sheet, add a `mask` column holding `1` on coupon
rows and empty on deal rows.

## Deals versus coupons

ReHub tells them apart by whether a code is present, so:

- **coupon** — `coupon_code` filled, `rehub_offer_coupon_mask` = 1,
  `button_text` something like `Get Code`
- **deal** — `coupon_code` empty, no mask, `button_text` something like
  `Shop Now`

Rows 3 and 4 of the sample are deals, so you can see both shapes.

## Checking an import worked

A correctly imported coupon has all three of these. Miss any one and it looks
broken in a different way:

| check | if it fails |
| --- | --- |
| The post carries a `dealstore` term | the coupon appears on no store page at all |
| `rehub_offer_product_coupon` is set | the card has no code |
| `rehub_offer_coupon_mask` is `1` | the card has a code but no reveal button |

`wp merchant doctor` reports the first of these across the whole site, and
says how many offers exist against how many stores.

## A store that does not exist yet

Fourteen of the November coupons name shops that are in neither the merchant
list nor the `dealstore` terms: Element Vape, Giant Vapes, 3Avape, Lightning
Vapes, Volcano Ecigs, NicQuid, 3FVape, Apollo Ecigs, EcigExpress, Medterra,
Fuggin Vapor among them.

Their coupons have nowhere to go. Add the shop to the merchant spreadsheet
and run ReHub Sync first, which creates the `dealstore` term; then the
coupon import will find it.

Do NOT let the importer create `dealstore` terms it does not recognise. Each
new term is a new store page, and a term created from a slightly different
spelling gives you two pages for one shop — which is how the `ships_to`
taxonomy ended up with ninety-two empty duplicates.
