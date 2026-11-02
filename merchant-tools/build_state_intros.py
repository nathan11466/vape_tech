#!/usr/bin/env python3
"""
Check the destination-page intros against the merchant data, then write the
import file.

Every intro in content/state_intros.py makes countable claims: how many
shops ship to a state, and which ones will not. Those numbers came from the
merchant CSV, and the CSV changes every time the scraper runs. Copy that
quietly goes stale is worse than no copy, because it reads as researched.

So nothing is written until each claim has been checked against the data:

  * the ship count stated for a state matches the data
  * every store named as excluding a state really does exclude it
  * no store that excludes a state has been left out of its list
  * the spelled-out exclusion count matches the number of names
  * no stray figure appears that belongs to a different state
  * no two intros are near-duplicates of each other

    python3 build_state_intros.py merchants-COMPLETE.csv --out state-intros.csv

A failure prints the state and the mismatch. Fix the copy, or re-run the
build after the data changes, and the numbers go back in step.
"""

import argparse
import csv
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from content.state_intros import INTROS  # noqa: E402
from content.state_legal import CHECKED, NOTES  # noqa: E402
from fetch_state_law import BASE, STATES  # noqa: E402

US_STATES = [
    "Alabama", "Alaska", "Arizona", "Arkansas", "California", "Colorado",
    "Connecticut", "Delaware", "District of Columbia", "Florida", "Georgia",
    "Hawaii", "Idaho", "Illinois", "Indiana", "Iowa", "Kansas", "Kentucky",
    "Louisiana", "Maine", "Maryland", "Massachusetts", "Michigan",
    "Minnesota", "Mississippi", "Missouri", "Montana", "Nebraska", "Nevada",
    "New Hampshire", "New Jersey", "New Mexico", "New York",
    "North Carolina", "North Dakota", "Ohio", "Oklahoma", "Oregon",
    "Pennsylvania", "Rhode Island", "South Carolina", "South Dakota",
    "Tennessee", "Texas", "Utah", "Vermont", "Virginia", "Washington",
    "West Virginia", "Wisconsin", "Wyoming",
]

MIN_LENGTH = 500

NUMBER_WORDS = {
    "one": 1, "two": 2, "three": 3, "four": 4, "five": 5, "six": 6,
    "seven": 7, "eight": 8, "nine": 9, "ten": 10, "eleven": 11,
    "twelve": 12,
}

# What has to follow a number word for it to be counting excluded shops.
# Deliberately narrow. Anything looser read "Not one nicotine vape store
# excludes Idaho" as a claim that one shop does, and "one of the three
# longest exclusion lists" as a claim about three. The apostrophe guard is
# for "one store's policy excludes a single county", which is about a county
# and not a count of stores at all.
COUNTS_SHOPS = re.compile(
    r"(?<!not )\b(?P<word>one|two|three|four|five|six|seven|eight|nine|ten|"
    r"eleven|twelve)\b\s+"
    r"(?:shops?\b(?!')|stores?\b(?!')|retailers?\b(?!')|of the shops\b|"
    r"do not\b|does not\b|will not\b|that do not\b|that decline\b|"
    r"declines?\b|exceptions?\b)",
    re.I)

# Figures that are not derived from the data: the legal age, and "the lower
# 48". Everything else an intro states is counted from the CSV by
# site_figures() below, so a site-wide claim goes stale as loudly as a
# per-state one.
CONSTANTS = {21, 48}


def load(path):
    with open(path, newline="", encoding="utf-8-sig") as fh:
        return list(csv.DictReader(fh))


# A statute reference as the source prints them, e.g. "Ala. Code § 28-11-7"
# or "35 Ill. Comp. Stat. 143/10-10(a)".
CITATION = re.compile(r"(?:\d+\s+)?[A-Z][A-Za-z.&'\- ]*?"
                      r"(?:Code|Stat|Stat\.|Laws|Rev\.? Code|Cons\. Stat\.)"
                      r"[A-Za-z.,&' \-]*?"
                      r"(?:§{1,2}\s*[\w.\-:()––]+|\s\d+/[\w.\-()]+)")

# Claims a note can make that the source has to bear out.
NO_TAX = re.compile(r"\bno state excise tax\b|\bcharges no state excise\b",
                    re.I)
NO_LICENCE = re.compile(r"\brequires no retail licence\b|"
                        r"\bno retail licence requirement\b|"
                        r"\bNo retail licence is required\b", re.I)
FLAVOUR_BAN = re.compile(
    r"prohibits the sale of flavou?red|"
    r"prohibits giving, distributing or selling a flavou?red|"
    r"restricts flavou?red e-cigarettes", re.I)
NO_FLAVOUR_RULE = re.compile(
    r"no statewide flavou?r restriction is recorded|"
    r"No statewide flavou?r restriction applies|"
    r"no statewide flavou?r restriction and", re.I)
# Only the unconditional bars. Washington prohibits delivery sales *unless*
# the seller is licensed and verifies age, which is a condition to meet and
# not a closed door -- an earlier version read the two as the same thing.
DTC_BARRED = re.compile(
    r"prohibits delivery sales of electronic nicotine delivery systems by "
    r"mail|prohibits mail order and internet sales|"
    r"restricts mail order, phone order and internet sales|"
    r"restricts remote sales and shipment|"
    r"internet and other remote sales are restricted to", re.I)


def source_flags(law):
    """
    What each state's source text actually supports, as flags.

    Derived from the fetched pages rather than written down anywhere, so a
    note cannot drift away from the source without this noticing.
    """
    flags = {}
    for state, record in law.items():
        sections = record.get("sections", {})
        tax = re.sub(r"\s+", " ", sections.get("tax", "")).strip()
        licence = sections.get("licence", "").strip()
        retail = sections.get("retail", "")
        whole = " ".join(sections.values())

        # A flavour rule is only a sales ban if it prohibits selling them.
        # Three states have flavour provisions that are not bans: Alabama
        # restricts billboard advertising, New Jersey restricts discounting
        # them, and North Dakota restricts selling them to under-21s. An
        # earlier version of this check read all three as bans.
        flavour_ban = False
        for line in retail.split("\n"):
            if not re.search(r"flavou?r", line, re.I):
                continue
            if re.search(r"billboard|discounted sales|under age 21", line, re.I):
                continue
            if re.search(r"prohibited|restricted|may not (give|distribute|sell)",
                         line, re.I):
                flavour_ban = True

        flags[state] = {
            "no_tax": tax.upper() in ("N/A", "NO", ""),
            "no_licence": bool(re.match(r"^No\b", licence, re.I)),
            "flavour_ban": flavour_ban,
            "dtc_barred": bool(re.search(
                r"prohibited to any [A-Za-z ]+ consumer"
                r"|direct to consumer are prohibited"
                r"|restricted to sales to licensed"
                r"|restricted to distributors and retailers"
                r"|restricted to \"licensed persons\"", retail, re.I)),
            "text": re.sub(r"\s+", " ", whole),
        }
    return flags


def check_legal(law):
    """Every claim in a legal note, against that state's source page."""
    problems = []
    flags = source_flags(law)

    missing = [s for s in US_STATES if s not in NOTES]
    if missing:
        problems.append(f"no legal note for: {', '.join(missing)}")

    for state in US_STATES:
        note = NOTES.get(state)
        if not note:
            continue
        where = f"{state} legal:"

        if state not in flags:
            problems.append(f"{where} no source page was fetched for it")
            continue
        flag = flags[state]
        source = flag["text"]

        if len(note) < 250:
            problems.append(f"{where} {len(note)} characters, too thin to "
                            f"be worth publishing")

        # Statute citations have to exist in the source.
        flat_source = re.sub(r"\s+", "", source).replace("\u00a7\u00a7", "\u00a7")
        for citation in CITATION.findall(note):
            cite = re.sub(r"\s+", " ", citation).strip().strip(",.;: ")
            # A citation written inside a parenthetical picks up the closing
            # bracket of the sentence, but brackets are also part of a
            # section number -- § 105-113.38A(1). Drop only the unbalanced
            # ones. Stripping every trailing bracket mangled four citations
            # into "§ 105-113.38A(1".
            while cite.count("(") < cite.count(")"):
                cite = cite[:cite.rindex(")")] + cite[cite.rindex(")") + 1:]
            flat = re.sub(r"\s+", "", cite).replace("\u00a7\u00a7", "\u00a7")

            if flat in flat_source:
                continue
            # The source prints a run of sections as "§§ A, B", so a single
            # one of them never matches whole. Check the code and the
            # section number separately.
            parts = flat.split("\u00a7", 1)
            if len(parts) == 2 and parts[0] in flat_source and parts[1] in flat_source:
                continue
            problems.append(f"{where} cites \"{cite}\", which does not "
                            f"appear in the source page")

        if NO_TAX.search(note) and not flag["no_tax"]:
            problems.append(f"{where} says there is no state excise tax; the "
                            f"source gives one")
        if NO_LICENCE.search(note) and not flag["no_licence"]:
            problems.append(f"{where} says no retail licence is required; the "
                            f"source says one is")
        if FLAVOUR_BAN.search(note) and not flag["flavour_ban"]:
            problems.append(f"{where} claims a flavour sales ban the source "
                            f"does not support")
        if flag["flavour_ban"] and not FLAVOUR_BAN.search(note):
            problems.append(f"{where} the source records a flavour sales "
                            f"restriction and the note does not mention it")
        if NO_FLAVOUR_RULE.search(note) and flag["flavour_ban"]:
            problems.append(f"{where} says no flavour restriction is "
                            f"recorded, but the source records one")
        if DTC_BARRED.search(note) and not flag["dtc_barred"]:
            problems.append(f"{where} says consumer vape mail is barred or "
                            f"confined; the source does not say that")
        if flag["dtc_barred"] and not DTC_BARRED.search(note):
            problems.append(f"{where} the source bars or confines "
                            f"direct-to-consumer sales and the note is "
                            f"silent on it - that is the most consequential "
                            f"fact on the page")

        # These notes describe law. They must not advise on it.
        for phrase in ("you may legally", "it is legal for you",
                       "we recommend", "you are allowed to", "legal advice is",
                       "this means you can"):
            if phrase in note.lower():
                problems.append(f"{where} reads as advice, not description: "
                                f"\"{phrase}\"")

    # Duplicate guard, as for the intros.
    grams = {s: shingles(t) for s, t in NOTES.items()}
    states = sorted(grams)
    for i, a in enumerate(states):
        for b in states[i + 1:]:
            shared = len(grams[a] & grams[b])
            if shared > 6:
                problems.append(f"{a} and {b} legal notes share {shared} "
                                f"eight-word runs - too close to templated")

    return problems


def state_facts(rows):
    """Per-state ship counts and exclusion lists, read from the data."""
    ships = {state: 0 for state in US_STATES}
    excluded = {state: set() for state in US_STATES}
    us_shipping = 0

    for row in rows:
        name = (row.get("display_brand_name") or "").strip()
        terms = [t.strip() for t in (row.get("ships_to_terms") or "").split("|")]
        if "United States" in terms:
            us_shipping += 1
        for term in terms:
            if term in ships:
                ships[term] += 1
        for term in (row.get("restricted_states") or "").split("|"):
            term = term.strip()
            if term in excluded and name:
                excluded[term].add(name)

    return ships, excluded, us_shipping


def site_figures(rows, us_shipping):
    """
    Every site-wide number the copy is allowed to state, counted from the
    data.

    An intro saying "97 of these retailers publish a contact page" is as
    much a factual claim as a per-state count, and it rots the same way. So
    the figures are not allow-listed -- they are counted here, and a figure
    in the copy that is not one of these fails the build.
    """
    def filled(column):
        return sum(1 for r in rows if (r.get(column) or "").strip())

    def mode(value):
        return sum(1 for r in rows
                   if (r.get("offer_display_mode") or "").strip() == value)

    return {
        len(rows),
        us_shipping,
        filled("do_they_id_on_delivery"),
        filled("shipping_policy_url"),
        filled("returns_policy_url"),
        filled("contact_page_url"),
        filled("contact_email"),
        filled("fact_source_url"),
        filled("trust_source_url"),
        filled("brand_logo_url"),
        mode("verified_code"),
        mode("best_deal"),
        mode("no_code_confirmed"),
    } | CONSTANTS


def names_in(text, known):
    """Which merchant names appear in the text.

    Longest first, blanking each match as it is found, so "Vape Juice Depot"
    is not also counted as a mention of "Vape.com" or "Juice Head".
    """
    found = set()
    haystack = text
    for name in sorted(known, key=len, reverse=True):
        pattern = re.compile(r"(?<![A-Za-z0-9])" + re.escape(name)
                             + r"(?![A-Za-z0-9])")
        if pattern.search(haystack):
            found.add(name)
            haystack = pattern.sub(" " * len(name), haystack)
    return found


def shingles(text, known=(), size=8):
    """
    Overlapping word runs, for spotting two intros written to a pattern.

    Store names are blanked out first. Two states excluded by the same six
    hemp retailers share that list of names as a matter of fact, and
    counting it as duplicated writing flagged pairs that had nothing in
    common but the truth. What is being measured here is the prose.
    """
    for name in sorted(known, key=len, reverse=True):
        text = re.sub(r"(?<![A-Za-z0-9])" + re.escape(name)
                      + r"(?![A-Za-z0-9])", " NAME ", text)
    words = [w for w in re.findall(r"[A-Za-z0-9]+", text) if w != "NAME"]
    words = [w.lower() for w in words]
    return {" ".join(words[i:i + size]) for i in range(len(words) - size + 1)}


def check(path):
    """Returns a list of problems; empty means the copy matches the data."""
    rows = load(path)
    ships, excluded, us_shipping = state_facts(rows)
    known = {(r.get("display_brand_name") or "").strip() for r in rows}
    known.discard("")
    figures = site_figures(rows, us_shipping)
    problems = []

    missing = [s for s in US_STATES if s not in INTROS]
    if missing:
        problems.append(f"no intro written for: {', '.join(missing)}")
    extra = [s for s in INTROS if s not in US_STATES]
    if extra:
        problems.append(f"intro for something that is not a destination: "
                        f"{', '.join(extra)}")

    for state in US_STATES:
        text = INTROS.get(state)
        if not text:
            continue
        where = f"{state}:"

        if len(text) < MIN_LENGTH:
            problems.append(f"{where} {len(text)} characters, under the "
                            f"{MIN_LENGTH} minimum")

        if state not in text:
            # A page about Utah has to say Utah; the archive title carries it
            # but the copy should too.
            problems.append(f"{where} the intro never names the state")

        # Every figure in the copy must be this state's ship count, or a
        # site-wide figure that the data actually bears out.
        allowed = {ships[state]} | figures
        for figure in re.findall(r"\b(\d{2,3})\b", text):
            if int(figure) not in allowed:
                problems.append(f"{where} the figure {figure} matches "
                                f"nothing in the data ({ships[state]} ship "
                                f"to this state)")

        # The ship count has to actually appear.
        if str(ships[state]) not in text:
            problems.append(f"{where} does not state the ship count "
                            f"({ships[state]})")

        # Names: both directions.
        named = names_in(text, known)
        truth = excluded[state]
        wrong = named - truth
        if wrong:
            problems.append(f"{where} names {', '.join(sorted(wrong))} as "
                            f"excluding the state, and they do not")
        absent = truth - named
        if absent:
            problems.append(f"{where} leaves out {', '.join(sorted(absent))}, "
                            f"which do exclude the state")

        # A spelled-out count of excluded shops must match the names.
        for match in COUNTS_SHOPS.finditer(text):
            stated = NUMBER_WORDS[match.group("word").lower()]
            if stated != len(truth):
                problems.append(
                    f"{where} says \"{match.group('word')}\" shops do not ship "
                    f"here, but {len(truth)} do not")

    # Near-duplicate guard.
    grams = {s: shingles(t, known) for s, t in INTROS.items() if t}
    states = sorted(grams)
    for i, a in enumerate(states):
        for b in states[i + 1:]:
            shared = grams[a] & grams[b]
            if len(shared) > 6:
                problems.append(f"{a} and {b} share {len(shared)} eight-word "
                                f"runs - too close to templated")

    return problems, ships, excluded


def main():
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("input", help="merchant CSV the claims are checked against")
    parser.add_argument("--law", default="",
                        help="state-law JSON from fetch_state_law.py. Given, "
                             "the legal notes are checked against it and "
                             "written into the import file too.")
    parser.add_argument("--out", help="import CSV to write")
    parser.add_argument("--force", action="store_true",
                        help="write the file even if checks fail")
    args = parser.parse_args()

    problems, ships, excluded = check(args.input)

    law = {}
    if args.law:
        with open(args.law, encoding="utf-8") as fh:
            law = json.load(fh)
        problems += check_legal(law)

    if problems:
        print(f"{len(problems)} problem(s):")
        for problem in problems:
            print("  " + problem)
        print()
        if not args.force:
            print("Nothing written. Fix the copy, or re-run after the data "
                  "settles.")
            return 1
        print("--force given, writing anyway.")
    else:
        print(f"All {len(INTROS)} intros match the data.")

    lengths = sorted(len(t) for t in INTROS.values())
    print(f"Length: {lengths[0]}-{lengths[-1]} characters, "
          f"median {lengths[len(lengths) // 2]}")

    if not args.out:
        return 0

    columns = ["taxonomy", "term", "vc_intro"]
    if law:
        columns += ["vc_legal_note", "vc_legal_source_url", "vc_legal_checked"]

    with open(args.out, "w", newline="", encoding="utf-8") as fh:
        writer = csv.writer(fh)
        writer.writerow(columns)
        for state in US_STATES:
            row = ["ships_to", state, INTROS[state]]
            if law:
                # The source URL is built from the same table the pages were
                # fetched with, so the citation cannot point somewhere other
                # than the page the note was written against.
                row += [NOTES.get(state, ""),
                        BASE + STATES[state] if state in NOTES else "",
                        CHECKED if state in NOTES else ""]
            writer.writerow(row)
    print(f"Wrote {args.out}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
