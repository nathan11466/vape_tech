# Collecting policy facts

## Why

`compose_sections.py` builds page copy from a fact vocabulary. Before this it
had eight slots, most stores filled two or three, and 112 stores drawn from
that produce a handful of distinct paragraphs. Measured: `compose_returns`
could emit **2** distinct outputs, and `compose_troubleshooting` **16** across
its entire fact space.

The ceiling was information, not phrasing, so rewriting could not lift it.
Rephrasing one fact 112 ways is spintax. Collecting more facts per store
raises the ceiling without rewriting a word: `compose_returns` now emits
**13** distinct outputs from the new columns alone.

## Running it

    python3 -I enrich_policies.py merchants.csv --out merchants-policies.csv

Start with a trial:

    python3 -I enrich_policies.py merchants.csv --limit 5 --delay 2

Flags: `--only "Vapor DNA,Eightvape"`, `--overwrite` (replace values already
in the sheet — off by default, because a value already there is someone's own
work), `--cache DIR`, `--delay SECONDS`, `--ignore-robots`.

Every page is cached, so re-running visits nobody. Extraction changes can be
re-run over the cache for free — which is why fetching and extracting are
separate files.

## What it writes

Nine fact columns, each with a `_source` and `_evidence` sibling:

| column | example |
| --- | --- |
| `return_window_days` | `45` |
| `restocking_fee_percent` | `15` |
| `return_shipping_paid_by` | `customer` or `store` |
| `warranty_days` | `90` |
| `free_shipping_threshold_usd` | `95` |
| `min_order_usd` | `20` |
| `processing_days` | `2` |
| `shipping_carriers` | `FedEx|UPS|USPS` |
| `age_verification_method` | `AgeChecker|Adult signature on delivery` |

Plus `policy_facts_checked`, `policy_facts_conflicts`, `policy_facts_problem`.

`_evidence` holds the verbatim sentence the value was read from, and `_source`
the URL it was on. **Read those before publishing.** Nothing here has been
seen by a person.

## The three rules

**Only what the store states.** A fact arrives with its sentence and URL or it
does not arrive. Nothing is inferred from a sibling field or from trade norms.

**Conflicts return nothing.** A page saying 14 days in one place and 30 in
another is a page we do not understand; the disagreement goes in
`policy_facts_conflicts` for a person to read. Guessing here would publish a
confident wrong claim about someone's money.

**Chrome is not prose.** Scripts, nav, footers, selects and forms are stripped
first, and block tags become line breaks so bullets cannot fuse.

## Expect a modest yield

A trial over three large stores produced about two facts each. These pages
genuinely do not state much in extractable prose: carriers, for instance, were
absent from all three, correctly returning nothing rather than a guess. Treat
anything above zero as a gain over the templated sentence it replaces.

## Known precision traps, each pinned by a test

- A **defective-goods** window is not a return policy. Element Vape states "if
  the item ... is defective ... you have 30 days"; recording that as a 30-day
  return window publishes a promise the store never made.
- `rma` unbounded matches inside "info**rma**tion", which classified every
  "Shipping Information" link as a returns page.
- A **promo banner** reading "Free shipping over $50" pointing at a product
  category otherwise claims the shipping slot, so the real policy page is never
  fetched and a promo becomes the cited source. URL paths beat link text.
- Transit durations are not return windows, but `delivery` cannot simply be
  excluded — "within 30 days **of delivery**" is the commonest phrasing there
  is.

## Politeness

This visits third-party shops that owe us nothing: robots.txt is honoured, the
bot identifies itself, requests to a host are spaced by `--delay`, responses
are capped at 3MB and cached. Four stores are already known dead or blocking:
`beyouonline.co.uk` and `vapeclutch.com` (404), `smokespotvape.com` (402),
`bellavapor.com` (526).

Run the Python with `-I`: it parses pages fetched from 112 third-party sites,
and isolated mode stops a planted module being imported in place of the
standard library.
