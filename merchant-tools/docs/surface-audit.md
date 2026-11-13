# What this plugin still does, and what it no longer needs to

The plugin was built to render its own store pages at `/stores/<slug>/`. It
is now an adapter that feeds ReHub's store pages at `/coupon-codes/<slug>/`.
Those are different jobs, and most of the first one is still present.

Current surface: **20 modules, ~11,300 lines, 28 shortcodes, 15 options,
12 admin screens, 7 WP-CLI commands.**

`wp merchant doctor` reads this install and says which features are live,
idle, inert or causing a problem. This file is the part that can be decided
without looking at a database.

## The decision everything else hangs on

**Are `/stores/` pages still a destination, or not?**

While they are, roughly a third of this plugin has to stay: the `merchant`
post type's own single template, the two-column layout, the title and hero
blocks, H1 wording, sidebar width, the archive pages. While they are *also*
live alongside ReHub's pages, every shop has two indexable URLs with the
same content.

Switching the handover on (ReHub Sync screen) 301s them to ReHub. After
that, the rendering half becomes genuinely dead rather than merely unused,
and can go.

Until that call is made, nothing below can be deleted safely.

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

These become deletable the day `/stores/` stops serving. Not before.

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

## The honest risk

Most of this is inert rather than harmful: dead code in a plugin costs
maintenance, not correctness. The two things that are actively causing a
problem both show up in `wp merchant doctor`:

- **two live URLs per shop** while the handover is off, and
- **law links pointing at `/staging/`**, which break on going live.

Those are worth fixing this week. The deletions can wait for the `/stores/`
decision.
