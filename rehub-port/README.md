# ReHub Port Helper

Finishes the Astra-to-ReHub migration on vapingcheap.com.

The site's content moved themes; its configuration did not. Four things
broke at once in late October and none of them was a bug:

| Symptom | Cause |
|---|---|
| Footer links keep vanishing | they lived in Astra's footer builder, which ReHub cannot read |
| A box around the content | ReHub's `rh-container`; Astra ran `ast-full-width-layout` |
| Huge icons on the homepage | the homepage is built with Spectra blocks, and Spectra was not active |
| Banner ads not displaying | Ads Pro's placements were hooked into Astra |

Content ports itself. Configuration does not.

## What it does

**Rebuilds the footer.** Three menus — Company, Editorial, Legal — placed as
Navigation Menu widgets in ReHub's `footerfirst`, `footersecond` and
`footerthird`. The widget title becomes the column heading, which ReHub
styles at 18px. Links resolve to the page where one exists, so renaming a
page later keeps the footer working.

**The two CSS differences**, both off by default because both are ReHub
design decisions rather than faults: footer type size (ReHub 13–14px,
Astra inherited ~16px) and the content container.

**Reports what it cannot do**: which depended-on plugins are inactive, and
which of ReHub's own ad fields are empty, read out of `rehub_option`.

## What it will not do

It never writes to `rehub_option`. That array holds two hundred keys in one
blob and merging into it blind risks losing settings to save two clicks. Ad
code and the width setting are reported with their field names instead.

It never removes a widget, a menu or a menu item. Every write is an
addition; running it twice does nothing the second time. Preview first.

## Delete it when the migration is done

This is scaffolding. The menus and widgets it creates are ordinary
WordPress data and stay behind when the plugin goes.

## Tests

    php tests/test_port.php

41 checks. The ones that matter cover the `sidebars_widgets` rules, because
getting those wrong silently unassigns widgets — which is the failure that
started this.
