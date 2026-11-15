# What this plugin still does, and what it no longer needs to

The plugin was built to render its own store pages at `/stores/<slug>/`. It
is now an adapter that feeds ReHub's store pages at `/coupon-codes/<slug>/`.
Those are different jobs, and most of the first one is still present.

Current surface: **21 modules, ~11,565 lines, 28 shortcodes, 14 options,
12 admin screens, 8 WP-CLI commands**, plus 9 Python data scripts.

`wp merchant doctor` reads this install and says which features are live,
idle, inert or causing a problem. This file is the part that can be decided
without looking at a database.

## How much of this is actually dead

Very little, and it is worth saying so before the list of candidates below.
Of 227 functions, **2** are never referenced anywhere else
(`vc_merchant_is_publishable`, `vc_layout_has_sidebar`). All 28 shortcodes
are referenced. The plugin is not rotting.

What it has instead is code sized for jobs that have since ended. That is a
different problem and it needs a different fix: deciding, not hunting.

## The decision everything else hung on — now made

**Are `/stores/` pages still a destination?** No. Checked live:

    /staging/stores/2fdeal/        301 -> /staging/coupon-codes/2fdeal/
    /staging/stores/central-vapors/ 301 -> /staging/coupon-codes/central-vapors/
    /staging/stores/vape-com/      301 -> /staging/coupon-codes/vape-com/

The handover is on. That unblocks everything this file previously held
back: the rendering half is now genuinely dead rather than merely unused.

One leftover: **`/staging/stores/` itself still returns 200** (the post-type
archive index). It carries `noindex, nofollow`, so it is not an SEO problem,
but it is a live page nothing links to and nothing needs.

## Live, and load-bearing

| Module | Why it stays |
| --- | --- |
| `fields`, `import`, `editor` | the CPT, the CSV pipeline, the edit screens |
| `rehub` | writes everything ReHub's templates read |
| `storepage` | puts our blocks into ReHub's three slots |
| `render`, `settings` | the block system and the layout screen |
| `sections` | the blocks themselves |
| `seo` | titles, descriptions, schema |
| `archive` | the 51 destination pages, which have no ReHub equivalent |
| `storestats` | counted stats and the offer table |
| `offers` | the migration to native offers |
| `repetition` | measures duplicate copy across the catalogue |
| `lawlinks`, `explainer` | the law pages and the PACT Act page |
| `shipping`, `logos`, `freshness`, `intros` | data the above depend on |

## Inert where you are now

Not broken — simply unreachable while ReHub renders the store page.

- **`title` and `hero` blocks** — ReHub draws its own H1 and its own offer
  cards. They stand down automatically and still work at `/stores/`.
- **H1 wording, sidebar width** — settings that only affect our own
  template. Labelled as such on the Layout screen.
- **The two-column split layout** (`.vc-merchant-page--split`) — the store
  page passes `sidebar => 'no'` and uses ReHub's column.

That day has come: `/stores/` single pages 301 to ReHub, so these are
deletable now. They are listed here rather than deleted because removing a
rendering path is the kind of change that wants one deliberate pass with the
tests, not a line here and there.

## Legacy of the coupon plugin

Being retired with WP Coupon & Deals itself:

- **`coupons` module** (1,077 lines) — brand-term linking, the coupon
  shortcode, the AJAX handler. Once offers are native, only the
  `wcd_brand_term_id` link matters, and only until the migration runs.
- **`vc_rehub_embed_coupons`** — embeds the coupon plugin's shortcode.
  Should stay off for native offers.
- **`migrate/`** — the exporter and its sample CSV, for importing into the
  coupon plugin. Pointless once that plugin goes.

Keep until the 42 coupons are migrated and verified. Those posts are
currently the only copy of that data.

## Already finished its job

- **`rehub-port/`** — the Astra-to-ReHub migration helper. It was written to
  be deleted once the theme change was done. It is.

## Worth a decision, not a deletion

- **`intros`** — imports term copy for the 51 destination pages. Still
  useful if that copy is ever re-imported; dead weight if not.
- **`freshness`** — the month stamp in titles. Live, but check it is not
  fighting ReHub's own title.
- **12 admin screens** is a lot of menu for one plugin. Several could fold
  into one tabbed screen without losing anything.

## How to retire something safely

1. `wp merchant doctor` — confirm it reads as inert, not idle.
2. Search for the shortcode or function in post content and term meta, not
   just in the plugin: `wp db query "SELECT ID FROM wp_posts WHERE
   post_content LIKE '%shortcode_name%'"`. A block removed from the layout
   can still be sitting in a page somebody built.
3. Remove the module and its tests together, and run the suite. A module
   that nothing references will not take anything with it.
4. Keep the shortcode registered returning `''` for one release if it was
   ever documented, so a page using it degrades to nothing rather than
   printing raw text.

## Over-building, as distinct from dead code

Three places where the machinery outgrew the job:

- **The layout system.** `render` + `settings` is about 1,000 lines giving
  every block an order, a column, an on/off switch, an editable heading and
  a set of visual tokens. In practice the arrangement is now one button:
  "Arrange for a ReHub store page". The blocks themselves (`sections`) earn
  their keep; the machinery around choosing between them mostly does not.
- **Twelve admin screens** for one plugin, several of them a single form
  that runs once (Import Logos, Import Page Copy, Law links). These could be
  tabs on one screen, or CLI-only, without losing anything.
- **`coupons` (1,077 lines) and `migrate/`** exist to talk to a plugin that
  is being retired. They stay only until the 42 coupons are migrated, and
  that migration is the thing blocking their removal.

## The honest risk

Most of this is inert rather than harmful: dead code in a plugin costs
maintenance, not correctness. What is actually outstanding:

- **law links pointing at `/staging/`**, which break on going live, and
- **42 coupons still in the old plugin**, which is what holds `coupons`
  and `migrate/` in place and leaves store pages with an empty middle.

Those two are worth fixing before any deletion. Deleting code does not make
a page work; those two do.
