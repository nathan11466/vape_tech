#!/usr/bin/env python3
"""
Derive shipping-destination terms from the merchant data.

Vape and hemp sellers usually describe shipping as an exception list --
"ships nationwide except California and Utah" -- rather than an inclusion list.
This turns that prose into concrete terms the WordPress `ships_to` taxonomy can
use, so pages become filterable by destination.

Every result carries a confidence marker, because the inference is not equally
safe in every case:

  stated    - the source explicitly claims nationwide or all-states shipping,
              with an exception list we subtracted
  inferred  - the source names states it will NOT ship to, which implies it
              ships to the rest; probable but worth a spot check
  unknown   - nothing explicit, so no terms are assigned rather than guessed

A boilerplate non-statement ("no shipping restrictions listed - confirm at
checkout") is deliberately NOT treated as a nationwide claim.
"""

import re

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

# Common shorthand seen in merchant copy.
_STATE_ALIASES = {
    "washington dc": "District of Columbia",
    "washington d.c.": "District of Columbia",
    "d.c.": "District of Columbia",
    "dc": "District of Columbia",
}

COUNTRIES = [
    "United States", "Canada", "Mexico", "United Kingdom", "Germany", "France",
    "Netherlands", "Spain", "Italy", "Ireland", "Australia", "New Zealand",
    "Japan", "European Union",
]

_COUNTRY_ALIASES = {
    "usa": "United States", "u.s.": "United States", "us": "United States",
    "united states of america": "United States", "america": "United States",
    "uk": "United Kingdom", "great britain": "United Kingdom",
    "eu": "European Union", "nz": "New Zealand",
}

# An explicit claim of nationwide / all-states coverage.
_NATIONWIDE_RE = re.compile(
    r"\b(?:ships?\s+)?(?:nationwide|all\s+(?:50\s+)?states|every\s+state|"
    r"all\s+US\s+states|throughout\s+the\s+(?:US|United\s+States))\b",
    re.I,
)

# Phrases introducing a list of places the merchant will NOT serve.
_EXCLUSION_RES = [
    re.compile(r"\bexcept(?:\s+for)?\b(?P<list>[^.;]+)", re.I),
    re.compile(r"\b(?:does\s+not|do\s+not|cannot|can'?t|won'?t|unable\s+to)\s+ship\b"
               r"(?:[^.;]*?)\bto\b(?P<list>[^.;]+)", re.I),
    re.compile(r"\bnot\s+available\s+(?:in|to)\b(?P<list>[^.;]+)", re.I),
    re.compile(r"\b(?:no|excluding|excludes)\s+(?:shipping|delivery)\s+to\b(?P<list>[^.;]+)", re.I),
    re.compile(r"\brestrict(?:ed|ions?)?\s+(?:in|for|to)\b(?P<list>[^.;]+)", re.I),
]

# Text that looks like a restriction statement but confirms nothing.
_NON_STATEMENT_RE = re.compile(
    r"no\s+(?:explicit\s+)?(?:shipping\s+)?restrictions?\s+(?:listed|found|stated)|"
    r"confirm\s+at\s+checkout|"
    r"details\s+vary",
    re.I,
)


def _find_states(text):
    """Return state names mentioned in the text, in canonical form."""
    found = []
    for state in US_STATES:
        if re.search(r"\b" + re.escape(state) + r"\b", text, re.I):
            if state not in found:
                found.append(state)

    lowered = text.lower()
    for alias, canonical in _STATE_ALIASES.items():
        if re.search(r"\b" + re.escape(alias) + r"\b", lowered) and canonical not in found:
            found.append(canonical)

    return found


def _find_countries(text):
    found = []
    for country in COUNTRIES:
        if re.search(r"\b" + re.escape(country) + r"\b", text, re.I):
            if country not in found:
                found.append(country)

    lowered = text.lower()
    for alias, canonical in _COUNTRY_ALIASES.items():
        if re.search(r"\b" + re.escape(alias) + r"\b", lowered) and canonical not in found:
            found.append(canonical)

    return found


def derive(row):
    """
    Work out shipping destinations for one merchant row.

    Returns (ships_to_terms, restricted_states, confidence) where the first two
    are lists of names and confidence is stated | inferred | unknown.
    """
    restrictions = (row.get("shipping_restrictions") or "").strip()
    declared = (row.get("ships_to_countries") or "").strip()
    free_ship = (row.get("free_shipping_info") or "").strip()
    summary = (row.get("brand_summary") or "").strip()

    # An explicit ships_to_countries value always wins -- it was entered
    # deliberately rather than parsed out of prose.
    if declared:
        names = [n.strip() for n in re.split(r"[|,]", declared) if n.strip()]
        if names:
            return names, [], "stated"

    haystack = " ".join([restrictions, free_ship])

    # Pull out anything named after an exclusion phrase.
    restricted = []
    for pattern in _EXCLUSION_RES:
        for match in pattern.finditer(haystack):
            fragment = match.group("list")
            for state in _find_states(fragment):
                if state not in restricted:
                    restricted.append(state)

    is_non_statement = bool(_NON_STATEMENT_RE.search(restrictions))
    claims_nationwide = bool(_NATIONWIDE_RE.search(haystack))

    # Case 1: explicit nationwide claim, minus any exceptions.
    if claims_nationwide and not is_non_statement:
        states = [s for s in US_STATES if s not in restricted]
        return ["United States"] + states, restricted, "stated"

    # Case 2: named exclusions imply coverage of the rest of the US.
    if restricted and not is_non_statement:
        states = [s for s in US_STATES if s not in restricted]
        return ["United States"] + states, restricted, "inferred"

    # Case 3: other countries named somewhere useful.
    countries = _find_countries(" ".join([restrictions, summary]))
    if countries and not is_non_statement:
        return countries, restricted, "inferred"

    # Nothing we can stand behind.
    return [], restricted, "unknown"


def apply_to_row(row):
    """Write the derived columns onto the row and return the confidence."""
    ships_to, restricted, confidence = derive(row)

    row["ships_to_terms"] = "|".join(ships_to)
    row["restricted_states"] = "|".join(restricted)
    row["shipping_confidence"] = confidence

    # Keep ships_to_countries populated for the merchant info panel, using the
    # country-level names only so the panel does not print 50 states.
    if not (row.get("ships_to_countries") or "").strip() and ships_to:
        countries = [n for n in ships_to if n in COUNTRIES]
        row["ships_to_countries"] = "|".join(countries)

    return confidence
