#!/usr/bin/env python3
"""
Compose merchant-specific section copy from each merchant's own field values.

The duplication problem is not solved by rewording a generic sentence -- that
produces unique strings carrying identical (zero) information. It is solved by
building each section from facts that genuinely differ between merchants:
their code names, thresholds, return windows, restricted states, excluded
product categories.

Three outcomes per section, tracked so you can audit them:

  original   - the field was already merchant-specific; left untouched
  composed   - rebuilt from this merchant's own extracted facts
  disclosure - nothing to build from, so the page says so plainly rather than
               padding with filler

Nothing here invents a fact. Every composed sentence is assembled from values
already present in the row.
"""

import re

# --- Fact extraction -------------------------------------------------------

# A coupon code: uppercase, 4-20 chars, containing a digit or long enough to
# read as a deliberate code rather than an ordinary word.
_CODE_RE = re.compile(r"\b[A-Z][A-Z0-9]{3,19}\b")
_CODE_STOPWORDS = {
    "FREE", "SHIP", "SHIPPING", "SALE", "ONLY", "TERMS", "NOTE", "HTTP", "HTTPS",
    "WWW", "COM", "AND", "THE", "FOR", "WITH", "FROM", "USA", "THC", "CBD",
    "FAQ", "PDF", "NEW", "ALL", "OFF", "GET", "BUY", "CODE", "DEAL", "DEALS",
    "PLUS", "OVER", "MORE", "YOUR", "THIS", "THAT", "WHEN", "EACH", "ITEM",
}

_THRESHOLD_RE = re.compile(
    r"(?:over|above|of|minimum(?:\s+of)?|orders?\s+(?:over|above|of))\s*\$\s?(\d[\d,]*(?:\.\d{2})?)",
    re.I,
)
_PERCENT_RE = re.compile(r"(\d{1,2})\s?%\s*off", re.I)
_DAYS_RE = re.compile(r"\b(\d{1,3})[\s-]*day", re.I)
_NOT_COMBINABLE_RE = re.compile(
    r"\b(not\s+combinab\w*|cannot\s+be\s+combined|can'?t\s+be\s+combined|"
    r"non[\s-]?combinable|not\s+stackab\w*|cannot\s+stack)\b",
    re.I,
)

_US_STATES = [
    "Alabama", "Alaska", "Arizona", "Arkansas", "California", "Colorado",
    "Connecticut", "Delaware", "Florida", "Georgia", "Hawaii", "Idaho",
    "Illinois", "Indiana", "Iowa", "Kansas", "Kentucky", "Louisiana", "Maine",
    "Maryland", "Massachusetts", "Michigan", "Minnesota", "Mississippi",
    "Missouri", "Montana", "Nebraska", "Nevada", "New Hampshire", "New Jersey",
    "New Mexico", "New York", "North Carolina", "North Dakota", "Ohio",
    "Oklahoma", "Oregon", "Pennsylvania", "Rhode Island", "South Carolina",
    "South Dakota", "Tennessee", "Texas", "Utah", "Vermont", "Virginia",
    "Washington", "West Virginia", "Wisconsin", "Wyoming",
]

# Product categories worth naming in an exclusions sentence.
_CATEGORY_TERMS = [
    "clearance", "closeout", "markdown", "mystery box", "bundle", "gift card",
    "sale item", "custom mix", "custom-mixed", "disposable", "e-liquid",
    "coil", "cartridge", "subscription", "limited-edition", "final sale",
]


def _text(row, field):
    return (row.get(field) or "").strip()


def extract_facts(row, skip_fields=frozenset()):
    """
    Pull concrete, checkable values out of a merchant row.

    Fields listed in `skip_fields` are ignored as fact sources. This matters:
    those are the boilerplate fields being replaced, and mining them would let
    generic text ("sale items, gift cards") be extracted and rebuilt into a
    sentence that only looks specific. That is spintax with extra steps, so
    facts are only ever taken from copy that was already merchant-specific.
    """
    def src(field):
        return "" if field in skip_fields else _text(row, field)

    offer = src("best_offer_summary")
    shipping = src("free_shipping_info")
    returns = src("return_policy_summary")
    exclusions = src("common_exclusions")
    stacking = src("stacking_policy")
    restrictions = src("shipping_restrictions")
    ways = src("best_ways_to_save")

    haystack = " ".join([offer, shipping, stacking, exclusions, ways])

    codes = []
    for candidate in _CODE_RE.findall(haystack):
        if candidate in _CODE_STOPWORDS or candidate.isdigit():
            continue
        if any(ch.isdigit() for ch in candidate) or len(candidate) >= 6:
            if candidate not in codes:
                codes.append(candidate)

    # The shipping threshold specifically, then any other money threshold.
    ship_threshold = None
    match = _THRESHOLD_RE.search(shipping)
    if match:
        ship_threshold = match.group(1)

    thresholds = [m.group(1) for m in _THRESHOLD_RE.finditer(haystack)]

    percents = [m.group(1) for m in _PERCENT_RE.finditer(offer)]

    return_days = None
    match = _DAYS_RE.search(returns)
    if match:
        return_days = match.group(1)

    states = [s for s in _US_STATES if re.search(r"\b" + re.escape(s) + r"\b", restrictions)]

    categories = []
    lowered = (exclusions + " " + stacking).lower()
    for term in _CATEGORY_TERMS:
        if term in lowered and term not in categories:
            categories.append(term)

    return {
        "codes": codes,
        "ship_threshold": ship_threshold,
        "thresholds": thresholds,
        "percents": percents,
        "return_days": return_days,
        "states": states,
        "categories": categories,
        "not_combinable": bool(_NOT_COMBINABLE_RE.search(stacking + " " + offer)),
    }


# --- Sentence helpers ------------------------------------------------------

def _join(items, conjunction="and"):
    """Oxford-comma list: a, b and c."""
    items = [i for i in items if i]
    if not items:
        return ""
    if len(items) == 1:
        return items[0]
    if len(items) == 2:
        return f"{items[0]} {conjunction} {items[1]}"
    return ", ".join(items[:-1]) + f" {conjunction} " + items[-1]


def _plural_categories(categories):
    """Turn category keywords into readable noun phrases."""
    mapping = {
        "clearance": "clearance stock",
        "closeout": "closeout stock",
        "markdown": "markdown items",
        "mystery box": "Mystery Boxes",
        "bundle": "bundles",
        "gift card": "gift cards",
        "sale item": "sale items",
        "custom mix": "custom-mixed orders",
        "custom-mixed": "custom-mixed orders",
        "disposable": "disposables",
        "e-liquid": "e-liquid",
        "coil": "coils",
        "cartridge": "cartridges",
        "subscription": "subscription pricing",
        "limited-edition": "limited-edition releases",
        "final sale": "final-sale items",
    }
    seen, out = set(), []
    for c in categories:
        phrase = mapping.get(c, c)
        if phrase not in seen:
            seen.add(phrase)
            out.append(phrase)
    return out


# --- Section composers -----------------------------------------------------
# Each returns (text, origin) where origin is composed | disclosure.

def compose_exclusions(brand, facts):
    categories = _plural_categories(facts["categories"])
    codes = facts["codes"]

    if categories and codes:
        return (
            f"{_join(categories).capitalize()} are excluded from {brand}'s discount codes. "
            f"Applying {codes[0]} to those items will not reduce the price.",
            "composed",
        )
    if categories:
        return (
            f"{brand} excludes {_join(categories)} from its discount codes.",
            "composed",
        )
    return (
        f"{brand} does not publish a list of excluded products. "
        f"Check the conditions shown with each code before checking out.",
        "disclosure",
    )


def compose_stacking(brand, facts):
    codes = facts["codes"]

    if facts["not_combinable"] and codes:
        primary = codes[0]
        others = f" or {codes[1]}" if len(codes) > 1 else " or another code"
        return (
            f"{primary} is marked as not combinable, so applying it alongside{others} "
            f"will cause one of them to fail. Use whichever gives the larger discount.",
            "composed",
        )
    if facts["not_combinable"]:
        return (
            f"{brand} marks its discount codes as not combinable, so only one applies per order.",
            "composed",
        )
    if len(codes) > 1:
        return (
            f"{brand} does not state whether {codes[0]} and {codes[1]} can be used together. "
            f"Apply one at a time to see which is worth more on your cart.",
            "disclosure",
        )
    return (
        f"{brand} does not publish a stacking policy. "
        f"Apply one code at a time and keep the one that discounts your cart the most.",
        "disclosure",
    )


def compose_troubleshooting(brand, facts):
    """The 'why won't my code work' section -- the most duplicated field."""
    reasons = []

    codes = facts["codes"]
    if facts["not_combinable"] and codes:
        reasons.append(
            f"{codes[0]} is marked as not combinable, so it fails when another code is already applied"
        )

    if facts["ship_threshold"]:
        ship_code = None
        for code in codes:
            if "SHIP" in code or "FREE" in code:
                ship_code = code
                break
        subject = ship_code if ship_code else "the free-shipping offer"
        reasons.append(f"{subject} requires a cart of ${facts['ship_threshold']} or more")

    categories = _plural_categories(facts["categories"])
    if categories:
        reasons.append(f"{_join(categories)} are excluded from percent-off codes")

    if facts["states"]:
        reasons.append(
            f"orders shipping to {_join(facts['states'])} may be blocked by state product restrictions"
        )

    if len(reasons) == 1:
        return (f"At {brand}, {reasons[0]}.", "composed")

    if reasons:
        # Several causes read as an unusable run-on when joined into one
        # sentence, so they are listed instead.
        body = "; ".join(reasons[:4])
        return (
            f"Codes at {brand} usually fail for one of these reasons: {body}.",
            "composed",
        )

    return (
        f"{brand} does not publish guidance on failed codes, and we have not been able to "
        f"confirm the specific conditions that apply. If a code is rejected, check its own "
        f"terms at checkout.",
        "disclosure",
    )


def compose_returns(brand, facts, original):
    if facts["return_days"]:
        return (
            f"{brand} accepts returns within {facts['return_days']} days of delivery. "
            f"Opened consumables are commonly excluded, so confirm eligibility before shipping anything back.",
            "composed",
        )
    return (
        f"{brand} does not publish return terms we could confirm. "
        f"Contact its support team before returning any item.",
        "disclosure",
    )


def compose_shipping_restrictions(brand, facts):
    if facts["states"]:
        return (
            f"{brand} restricts shipping to {_join(facts['states'])}. "
            f"Confirm at checkout, since state product rules change frequently.",
            "composed",
        )
    return (
        f"We could not confirm any state shipping restrictions for {brand}. "
        f"Vape and hemp product rules vary by state, so check at checkout.",
        "disclosure",
    )


# --- Entry point -----------------------------------------------------------

# field -> composer. Each composer takes (brand, facts) except returns.
SECTIONS = {
    "common_exclusions": compose_exclusions,
    "stacking_policy": compose_stacking,
    "why_code_not_work": compose_troubleshooting,
    "shipping_restrictions": compose_shipping_restrictions,
}


def compose_row(row, boilerplate_fields, brand=None):
    """
    Rewrite the boilerplate sections of one merchant row in place.

    `boilerplate_fields` is the set of field names flagged as generic for this
    row. A field that is already merchant-specific is left untouched.

    Returns a dict of field -> origin describing what happened.
    """
    brand = brand or (row.get("display_brand_name") or row.get("brand_name") or "This merchant").strip()
    # Boilerplate fields are excluded as fact sources so generic phrasing is
    # never mined and re-emitted as if it were merchant-specific.
    facts = extract_facts(row, skip_fields=set(boilerplate_fields))
    origins = {}

    for field, composer in SECTIONS.items():
        current = _text(row, field)
        if current and field not in boilerplate_fields:
            origins[field] = "original"
            continue
        text, origin = composer(brand, facts)
        row[field] = text
        origins[field] = origin

    # Returns takes the original text so it can keep a confirmed window.
    field = "return_policy_summary"
    current = _text(row, field)
    if current and field not in boilerplate_fields:
        origins[field] = "original"
    else:
        text, origin = compose_returns(brand, facts, current)
        row[field] = text
        origins[field] = origin

    return origins
