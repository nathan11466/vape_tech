#!/usr/bin/env python3
"""
Merchant CSV enrichment + publish-gating for vapingcheap.com coupon pages.

Takes the draft merchant dataset (e.g. brands_for_code_info_filled.csv), appends
the location / alias / contact / provenance columns, then grades every row so
nothing thin, boilerplate, or unsourced reaches a published page.

Two safeguards do the real work:

1. Boilerplate detection. Known generic fallback sentences are flagged by exact
   match, and ANY value repeated across >= --boilerplate-threshold unrelated
   merchants is flagged automatically. That second rule is what catches new
   boilerplate nobody has written down yet.

2. Offer display mode. Derives whether a page may claim a verified code, may
   only claim a best deal, or must say "no active code confirmed" -- so the
   template can never imply a code exists when one hasn't been confirmed.

Usage:
    python3 enrich_merchants.py INPUT.csv \\
        --out enriched.csv --review review-queue.csv
"""

import argparse
import csv
import os
import re
import sys
from collections import Counter, defaultdict

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import compose_sections

# --- New columns appended to the dataset -----------------------------------

NEW_COLUMNS = [
    "service_locations",
    "primary_service_location",
    "ships_to_countries",
    "alternative_brand_names",
    "display_brand_name",
    "contact_email",
    "contact_page_url",
    "contact_method",
    "contact_verified_at",
    "contact_source_url",
    "fact_source_url",
    "fact_last_verified",
    "content_confidence",
    "publish_status",
]

# Derived columns the WordPress template reads directly.
DERIVED_COLUMNS = [
    "offer_display_mode",
    "content_score",
    "low_confidence_fields",
    "review_notes",
    "section_origins",
]

# --- Fields subject to boilerplate detection -------------------------------
# Free-text editorial fields. Values here should be merchant-specific, so a
# value shared across merchants is boilerplate by definition.

CONTENT_FIELDS = [
    "best_offer_summary",
    "coupon_intro",
    "brand_summary",
    "best_ways_to_save",
    "free_shipping_info",
    "return_policy_summary",
    "common_exclusions",
    "stacking_policy",
    "why_code_not_work",
    "shipping_restrictions",
    "company_trust_info",
    "faq_1_answer",
    "faq_2_answer",
    "faq_3_answer",
]

# Fields that legitimately repeat across merchants -- never flag these.
# payment_methods repeats because most merchants really do take the same cards;
# verification_method describes our editorial process, not the merchant.
REPEATING_BY_DESIGN = {
    "brand_category",
    "editor_name",
    "editor_title",
    "top_offer_type",
    "age_verification_required",
    "last_checked_text",
    "coupon_plugin_shortcode",
    "payment_methods",
    "verification_method",
}

# Known generic fallbacks called out in the project handoff. Matched on a
# normalized form so punctuation and spacing drift don't defeat them.
KNOWN_BOILERPLATE = [
    "sale items, gift cards, and limited-edition releases.",
    "may not stack with other promotions or sale pricing.",
    "codes may fail on excluded items, expired offers, or carts below the minimum threshold.",
    "check brand's return policy page for eligibility and timeframe.",
]

# Claims that must never be published without a source + verification date.
# Unsourced, these are suppressed at render time but do not by themselves make
# the rest of the merchant page unpublishable.
REQUIRES_SOURCE = ["company_trust_info"]

# Negative reputation language is the one category that DOES block the whole
# row. Publishing an unsourced claim that a business is untrustworthy carries
# real risk, so those rows are held outright until sourced or removed.
NEGATIVE_REPUTATION_RE = re.compile(
    r"\b(low[\s-]?trust|scam|fraud|fraudulent|blacklist|black[\s-]?list|"
    r"suspicious|avoid this|poor rating|bad rating|negative review|"
    r"complaint|flag(?:s|ged)?\s+(?:as|for|low)|warning)\b",
    re.I,
)

# --- Specificity signals ---------------------------------------------------

MONEY_RE = re.compile(r"\$\s?\d")
PERCENT_RE = re.compile(r"\d+\s?%")
TIMEFRAME_RE = re.compile(r"\b\d+\s*(day|days|week|weeks|month|months|year|years)\b", re.I)
THRESHOLD_RE = re.compile(r"\b(over|above|minimum|min\.?|orders? of)\b.{0,20}\$?\d", re.I)
# A coupon code: 4-20 chars, uppercase alnum, containing at least one digit or
# being fully uppercase -- e.g. SAVE20, WELCOME10, VAPE15.
CODE_RE = re.compile(r"\b[A-Z0-9][A-Z0-9\-]{3,19}\b")
CODE_STOPWORDS = {
    "FAQ", "FREE", "SHIP", "SHIPPING", "SALE", "NEW", "USA", "US", "UK", "EU",
    "COD", "THC", "CBD", "NICOTINE", "ONLY", "TERMS", "NOTE", "PDF", "HTTP",
    "HTTPS", "WWW", "COM", "AND", "THE", "FOR", "WITH", "FROM",
}

US_STATES = [
    "alabama", "alaska", "arizona", "arkansas", "california", "colorado",
    "connecticut", "delaware", "florida", "georgia", "hawaii", "idaho",
    "illinois", "indiana", "iowa", "kansas", "kentucky", "louisiana", "maine",
    "maryland", "massachusetts", "michigan", "minnesota", "mississippi",
    "missouri", "montana", "nebraska", "nevada", "new hampshire", "new jersey",
    "new mexico", "new york", "north carolina", "north dakota", "ohio",
    "oklahoma", "oregon", "pennsylvania", "rhode island", "south carolina",
    "south dakota", "tennessee", "texas", "utah", "vermont", "virginia",
    "washington", "west virginia", "wisconsin", "wyoming",
]

COUNTRIES = [
    "united states", "usa", "canada", "mexico", "united kingdom", "uk",
    "germany", "france", "european union", "australia", "new zealand", "japan",
]


def normalize(value):
    """Collapse case, whitespace and trailing punctuation for comparison."""
    if value is None:
        return ""
    text = re.sub(r"\s+", " ", str(value)).strip().lower()
    return text.rstrip(" .;,")


def looks_specific(value):
    """True when the text carries a concrete, checkable detail."""
    if not value:
        return False
    text = str(value)
    if MONEY_RE.search(text) or PERCENT_RE.search(text):
        return True
    if TIMEFRAME_RE.search(text) or THRESHOLD_RE.search(text):
        return True
    lowered = text.lower()
    if any(state in lowered for state in US_STATES):
        return True
    if any(country in lowered for country in COUNTRIES):
        return True
    return False


def extract_code(value):
    """Return the first plausible coupon code in the text, else None."""
    if not value:
        return None
    for candidate in CODE_RE.findall(str(value)):
        if candidate in CODE_STOPWORDS:
            continue
        if candidate.isdigit():
            continue
        # Require a digit or a mixed-case-free all-caps word of decent length.
        if any(ch.isdigit() for ch in candidate) or len(candidate) >= 5:
            return candidate
    return None


def find_boilerplate(rows, threshold):
    """
    Map field -> set of normalized values considered boilerplate.

    A value is boilerplate if it matches a known generic fallback, or if it is
    shared by at least `threshold` merchants in a field that should be
    merchant-specific.
    """
    # Normalize the known list the same way row values are normalized, or the
    # trailing periods here would stop any of them ever matching.
    known = {normalize(v) for v in KNOWN_BOILERPLATE}
    flagged = defaultdict(set)

    for field in CONTENT_FIELDS:
        if field in REPEATING_BY_DESIGN:
            continue
        counts = Counter()
        for row in rows:
            value = normalize(row.get(field, ""))
            if value:
                counts[value] += 1
        for value, count in counts.items():
            if value in known:
                flagged[field].add(value)
                continue
            # A concrete, checkable value is a fact, not filler -- several
            # merchants can genuinely share a $75 free-shipping threshold. Only
            # vague text is treated as boilerplate on frequency alone.
            if count >= threshold and not looks_specific(value):
                flagged[field].add(value)

    return flagged


def decide_offer_display(row):
    """
    Decide what the page is permitted to claim about an offer.

    Returns one of:
      verified_code      -- a real code AND evidence it was tested/sourced
      best_deal          -- a concrete automatic discount / threshold, no code
      no_code_confirmed  -- say "No active code confirmed - see current deals"
    """
    offer = row.get("best_offer_summary", "") or ""
    verification = row.get("verification_method", "") or ""
    checked = row.get("last_checked_text", "") or ""
    source = row.get("fact_source_url", "") or ""

    code = extract_code(offer)
    has_evidence = bool(source.strip()) or bool(checked.strip()) or bool(verification.strip())

    if code and has_evidence:
        return "verified_code"

    deal_signals = " ".join([
        offer,
        row.get("free_shipping_info", "") or "",
        row.get("top_offer_type", "") or "",
    ])
    if looks_specific(deal_signals):
        return "best_deal"

    return "no_code_confirmed"


def grade_row(row, boilerplate):
    """
    Grade one merchant. Returns (confidence, status, low_fields, notes).

    Tiers follow the handoff:
      High   - specific, source-supported detail
      Medium - accurate but non-specific brand/category guidance
      Low    - generic fallbacks or unsourced claims
    """
    low_fields = []
    notes = []

    # 1. Boilerplate sweep.
    for field, bad_values in boilerplate.items():
        if normalize(row.get(field, "")) in bad_values and normalize(row.get(field, "")):
            low_fields.append(field)
            notes.append(f"{field}: generic/duplicated text - rewrite or hide")

    # 2. Claims that require a source before they may be published.
    #
    # An unsourced neutral claim (a rating, "no public rating found") only
    # suppresses that field -- the WordPress helper renders nothing for it, and
    # the rest of the page is still judged on its own merits. An unsourced
    # NEGATIVE claim blocks the whole row, since publishing an unsupported
    # assertion that a business is untrustworthy is the real risk.
    has_source = bool((row.get("fact_source_url") or "").strip())
    has_verified_date = bool((row.get("fact_last_verified") or "").strip())
    blocking = False
    for field in REQUIRES_SOURCE:
        value = (row.get(field) or "").strip()
        if not value or (has_source and has_verified_date):
            continue

        if field not in low_fields:
            low_fields.append(field)

        if NEGATIVE_REPUTATION_RE.search(value):
            notes.append(f"{field}: UNSOURCED NEGATIVE claim - remove or source before this merchant can publish")
            blocking = True
        else:
            notes.append(f"{field}: unsourced - field suppressed on the page; add fact_source_url + fact_last_verified to show it")

    # 3. Specificity: does this row carry any concrete, checkable detail?
    specific_fields = [
        f for f in ("best_offer_summary", "free_shipping_info", "return_policy_summary",
                    "shipping_restrictions", "common_exclusions")
        if f not in low_fields and looks_specific(row.get(f, ""))
    ]

    # 4. Contact + location completeness (advisory, not blocking).
    if not (row.get("contact_email") or row.get("contact_page_url") or "").strip():
        notes.append("contact: no public email or contact page recorded")
    if not (row.get("primary_service_location") or "").strip():
        notes.append("location: primary_service_location unset")

    # 5. Content strength: how much genuinely merchant-specific material this
    #    row carries, independent of whether it has been sourced yet. This is
    #    the triage signal -- source the highest scorers first.
    score = 2 * len(specific_fields)
    for field in CONTENT_FIELDS:
        if field in low_fields:
            continue
        if (row.get(field) or "").strip():
            score += 1

    # 6. Roll up.
    if blocking:
        confidence, status = "Low", "Hold"
    elif specific_fields and has_source and not low_fields:
        confidence, status = "High", "Ready"
    elif low_fields:
        confidence = "Low"
        status = "Needs review"
    elif specific_fields:
        confidence = "High"
        status = "Needs review"
        notes.append("specific claims present but fact_source_url is empty - add a source to mark Ready")
    else:
        confidence = "Medium"
        status = "Needs review"
        notes.append("no concrete offer/policy detail found - broad description only")

    return confidence, status, low_fields, notes, score


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("input", help="source merchant CSV")
    parser.add_argument("--out", required=True, help="enriched CSV to write")
    parser.add_argument("--review", help="review-queue CSV for rows needing work")
    parser.add_argument("--compose", action="store_true",
                        help="rebuild boilerplate sections from each merchant's own facts; "
                             "where there is nothing to build from, state that plainly")
    parser.add_argument("--boilerplate-threshold", type=int, default=3,
                        help="a value shared by this many merchants is boilerplate (default 3)")
    args = parser.parse_args()

    with open(args.input, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        rows = list(reader)
        fieldnames = list(reader.fieldnames or [])

    if not rows:
        print(f"No rows found in {args.input}", file=sys.stderr)
        return 1

    boilerplate = find_boilerplate(rows, args.boilerplate_threshold)

    for column in NEW_COLUMNS + DERIVED_COLUMNS:
        if column not in fieldnames:
            fieldnames.append(column)

    review_rows = []
    tally = Counter()
    display_tally = Counter()
    compose_tally = Counter()

    for row in rows:
        for column in NEW_COLUMNS + DERIVED_COLUMNS:
            row.setdefault(column, "")

        # Default the public display name to the brand name when unset.
        if not (row.get("display_brand_name") or "").strip():
            row["display_brand_name"] = row.get("brand_name", "")

        # Rebuild boilerplate sections from this merchant's own facts BEFORE
        # grading, so the grade reflects the copy that will actually publish.
        if args.compose:
            flagged = {
                field for field, bad in boilerplate.items()
                if normalize(row.get(field, "")) in bad and normalize(row.get(field, ""))
            }
            origins = compose_sections.compose_row(row, flagged)
            row["section_origins"] = "|".join(f"{f}:{o}" for f, o in sorted(origins.items()))
            compose_tally.update(origins.values())
            row["_disclosure_count"] = sum(1 for o in origins.values() if o == "disclosure")

        confidence, status, low_fields, notes, score = grade_row(row, boilerplate)

        # Flag pages that are mostly "we could not confirm this" so they are
        # easy to spot in review. Advisory only -- the decision is yours.
        disclosures = int(row.pop("_disclosure_count", 0) or 0)
        if disclosures >= 3:
            notes.append(f"{disclosures} of 5 sections could not be confirmed - page will read thin")

        row["content_confidence"] = confidence
        row["publish_status"] = status
        row["content_score"] = str(score)
        row["low_confidence_fields"] = "|".join(low_fields)
        row["review_notes"] = "; ".join(notes)
        row["offer_display_mode"] = decide_offer_display(row)

        tally[status] += 1
        display_tally[row["offer_display_mode"]] += 1

        if status != "Ready":
            review_rows.append({
                "brand_name": row.get("brand_name", ""),
                "content_score": score,
                "publish_status": status,
                "content_confidence": confidence,
                "offer_display_mode": row["offer_display_mode"],
                "low_confidence_fields": row["low_confidence_fields"],
                "review_notes": row["review_notes"],
            })

    # Strongest content first -- that is the order to do sourcing work in.
    review_rows.sort(key=lambda r: -r["content_score"])

    with open(args.out, "w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    if args.review:
        with open(args.review, "w", newline="", encoding="utf-8") as fh:
            cols = ["brand_name", "content_score", "publish_status", "content_confidence",
                    "offer_display_mode", "low_confidence_fields", "review_notes"]
            writer = csv.DictWriter(fh, fieldnames=cols)
            writer.writeheader()
            writer.writerows(review_rows)

    total = len(rows)
    print(f"Processed {total} merchants -> {args.out}")
    print()
    print("Publish status:")
    for status in ("Ready", "Needs review", "Hold"):
        print(f"  {status:<13} {tally[status]:>4}")
    print()
    print("Offer display mode:")
    for mode in ("verified_code", "best_deal", "no_code_confirmed"):
        print(f"  {mode:<19} {display_tally[mode]:>4}")

    if args.compose and compose_tally:
        print()
        print("Section copy:")
        for origin in ("original", "composed", "disclosure"):
            if compose_tally[origin]:
                label = {
                    "original": "already merchant-specific",
                    "composed": "rebuilt from this merchant's facts",
                    "disclosure": "stated as not confirmed",
                }[origin]
                print(f"  {compose_tally[origin]:>4}  {label}")

    flagged_total = sum(len(v) for v in boilerplate.values())
    if flagged_total:
        print()
        print(f"Boilerplate values detected ({flagged_total} across {len(boilerplate)} fields):")
        for field, values in sorted(boilerplate.items()):
            print(f"  {field} ({len(values)})")

    if args.review:
        print()
        print(f"{len(review_rows)} merchants need work -> {args.review}")
        top = [r for r in review_rows if r["publish_status"] != "Hold"][:15]
        if top:
            print()
            print("Strongest content -- source these first:")
            for r in top:
                print(f"  [{r['content_score']:>3}] {r['brand_name'][:38]:<38} {r['offer_display_mode']}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
