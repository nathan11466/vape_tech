# The local-rules notes: what they say and how they are checked

The 51 destination pages each carry a `vc_legal_note`. These are the only
copy on the site that says anything about law, so they are built
differently from the rest.

## The source

All of it comes from one place: the Public Health Law Center's **U.S.
E-Cigarette Regulation: A 50-State Review**, read per state on
2026-10-22 and cached in `docs/state-law-source.json`.

It was chosen over the obvious alternatives for three reasons. It cites the
statute behind every entry, so a claim can be traced. It covers all 50
states plus DC in one consistent format, so the notes are comparable. And
it is maintained by a public-health law centre rather than by someone
selling vapes.

That last point is not theoretical. Searching "vape laws by state" returns
a dozen retailer blogs, and on statewide flavour restrictions alone, three
of them give three different lists — one puts Colorado on it, another says
Colorado has local rules only, a third omits it. None cites a statute.

**The review's statutes are marked 2025.** It carries some forward-dated
provisions (Alabama's tax from 1 October 2026, Kentucky's licensing from 1
January 2026), but it is not a live feed. The archive template prints the
source link and the checked date under every note for that reason.

## What the notes deliberately do not cover

**The PACT Act**, which is the reason vape mail works the way it does at
all, is federal and identical in all 51 places. Repeating it on 51 pages
would be 51 copies of the same paragraph in the one field whose purpose is
to carry what the other pages do not. It belongs on a single explainer the
destination pages link to.

**Product directory and registry laws.** A wave of these arrived after the
review's cut-off — Pennsylvania, Virginia, Iowa, Hawaii, West Virginia and
others now require manufacturers to certify products before they may be
sold in the state, which directly affects what a shop may lawfully send.
The trade sources tracking it disagree on the count (one says 15 states,
another 17) and on the enforcement dates. None of it is in these notes.
It is worth adding per state, from each state's own attorney-general or
revenue page, as a second pass.

**Hemp and cannabinoid law.** The review is about nicotine e-cigarettes.
Roughly a third of the merchants here sell delta-8, THC-A and CBD, which
is governed separately and moves faster. The intros handle that side
factually — by naming the retailers that exclude a state in their own
published policies — and the legal notes stay silent on it rather than
guess.

## The five states that matter most

The review records that direct-to-consumer remote sales are prohibited, or
confined to licensed trade, in:

| State | What the source says |
|---|---|
| **Nebraska** | Delivery sales of ENDS by mail, internet or telephone prohibited to any Nebraska consumer |
| **Virginia** | Mail order and internet sales of nicotine vapor products direct to consumer prohibited |
| **Vermont** | Mail, phone and internet sales restricted to sales to licensed wholesalers and retailers |
| **Oregon** | Remote sales and shipment of inhalant delivery systems restricted to distributors and retailers |
| **Utah** | Internet and other remote sales restricted to "licensed persons" |

This matters commercially, because it cuts against the intros. The
Nebraska page counts 104 shops that say they will post there; the statute
says consumer vape mail is barred. Both statements are true about
different things, and the Nebraska and Virginia intros were rewritten to
point at the note rather than imply the address is never a problem.

**Worth getting a lawyer's eye on these five before publishing**, more
than on the other forty-six.

## Seven states restrict flavours

California, the District of Columbia, Maryland (tobacco and menthol only),
Massachusetts (with an exception for out-of-state purchasers), New York,
Rhode Island and Utah.

Three states have flavour provisions that are **not** sales bans, and the
notes say so explicitly, because a careless reading turns them into bans:
Alabama restricts billboard advertising, New Jersey restricts discounting
flavoured products, and North Dakota restricts selling them to under-21s.

## Fifteen states levy no vape excise tax at all

Alaska, Arizona, Arkansas, Florida, Idaho, Iowa, Michigan, Mississippi,
Missouri, Montana, North Dakota, Oklahoma, South Carolina, South Dakota and
Texas. At the other end, Minnesota takes 95% of wholesale and Vermont 92%.
That range is the most useful thing on these pages for a price-led shopper,
and it is why the notes lead with tax wherever nothing more consequential
applies.

## Four states still have a pre-2019 age on their books

Alaska says 19; Missouri, Montana and Wisconsin say 18. All four are
superseded: federal law has set 21 for every tobacco product including
e-cigarettes since 20 December 2019. The notes for those four say so
plainly rather than leaving a reader to think a lower age applies.

## How a wrong claim gets caught before it publishes

`build_state_intros.py --law docs/state-law-source.json` refuses to write
the import file unless, for every state:

- every statute cited in the note appears in that state's source text
- a claim of "no state excise tax" matches a source entry of N/A
- a claim of "no retail licence" matches a source answer of No
- a claimed flavour ban is one the source supports
- a flavour restriction the source *does* record is not omitted
- a claimed bar on consumer vape mail is one the source states
- a bar the source *does* state is not omitted — silence on the most
  consequential fact on the page is treated as a failure
- the note describes rules rather than advising on them
- no two notes share more than six eight-word runs

`tests/test_state_intros.py` adds a check that no note cites another
state's code, which is what a copy-paste between two pages looks like.

These checks have already earned their place. Writing the first draft of
these notes produced a flavour ban claimed for Kansas, thirty
mis-normalised citations, a Washington note that read a conditional
licensing rule as an outright bar, and four pairs of notes close enough to
be templated. The checker found all of them. One of its own rules was also
wrong — it read "W. Va. Code" as a Virginia citation — which is worth
remembering: a check that has never failed has not been tested.

## Re-running

```
python3 fetch_state_law.py --out docs/state-law-source.json --cache /tmp/lawcache
python3 build_state_intros.py merchants-COMPLETE.csv \
    --law docs/state-law-source.json --out state-pages.csv
```

If the review has been updated and a note no longer matches it, the build
fails and names the state and the claim. That is the point: the notes
cannot quietly go stale, because the thing that publishes them checks them
first.
