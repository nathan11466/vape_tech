# Coupon import — what each column does

Read against the importer's own code (`includes/class-import.php`), not from
the docs, so this is what it actually does with each value.

Column order does not matter. Column names are matched case-insensitively,
but they must be spelled exactly as below.

## The columns

| Column | Required | What happens to it |
|---|---|---|
| `title` | **Yes** | Becomes the coupon's name. **A row with no title is skipped** and reported by row number. This is the only required column. |
| `description` | No | The body text on the card. |
| `discount` | No | Free text, shown as the headline figure — `20%`, `$10`, `BOGO`, `Free shipping`. Not parsed as a number, so write it how you want it read. |
| `code` | No | The code revealed on click. Leave blank for an automatic deal. |
| `type` | No | `code` or `deal`. **Anything else silently becomes `code`** — including an empty cell. So a deal with no code needs `deal` here explicitly, or the card offers a reveal button with nothing behind it. |
| `expiration` | No | Use `YYYY-MM-DD`. Leave **blank** for an offer with no end date — do not invent one, because a past date hides the coupon and a far-future one is a claim you did not make. |
| `destination_url` | No | Where the button sends people. Your affiliate link goes here. Run through `esc_url_raw`, so it must be a real URL. |
| `brand` | No | The store. Creates the term if it does not exist. **This is what links a coupon to its merchant page.** |
| `store_category` | No | Taxonomy term. Created if new. |
| `product_category` | No | Taxonomy term. Created if new. |
| `location` | No | Taxonomy term. Created if new. |
| `additional_info` | No | The "need to know" text — exclusions, minimum spend, one-use-per-customer. |
| `featured` | No | **Exactly `1`** to feature it. Anything else — including `yes`, `true`, `Y` — counts as not featured, because the check is `intval(...) === 1`. |

## Several terms in one column

Separate them with a pipe:

```
product_category
E-Liquid|Nicotine Salts
```

Both terms get assigned. This works on `brand`, `store_category`,
`product_category` and `location`.

A term name containing a literal `|` cannot survive — it would read as a
separator. Write it as `/` instead.

## Two things worth knowing before a big import

**Everything imports as published, immediately.** There is no draft option:
`wp_insert_post` is called with `'post_status' => 'publish'`. A 112-row import
puts 112 live coupons on the site at once. Import a handful first and look at
them.

**A row whose column count does not match the header is skipped**, with the
row number and a note about commas. That is almost always a comma inside a
text field that needs quotes around it — any spreadsheet adds those for you
when it saves as CSV, so the usual cause is hand-editing the file in a plain
text editor.

## Linking a coupon to a merchant page

The merchant plugin matches a store to its coupons by the `brand` term,
comparing it against the store's `display_brand_name` and then its post title.
So `brand` in this CSV should be spelled the way the store is named on the
site — `VooPoo`, not `voopoo` or `VooPoo Official Store`.

If a name genuinely differs between the two, the store's editor screen has a
**Linked coupons** box with a brand dropdown; set it there and it overrides the
name match.

Check **Stores → Coupon Integration** after importing. It reports how many
stores matched a brand, and how many have live coupons but would still render
an empty section.
