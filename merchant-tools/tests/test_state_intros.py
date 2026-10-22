"""
The destination-page intros, as far as can be checked without the data.

build_state_intros.py does the fact-checking -- every count and store name
against a merchant CSV -- but that CSV is not in the repository, so it
cannot run here. What this suite pins instead are the properties that hold
regardless of the data, and the one that is easiest to lose: 51 pages
written to one pattern would satisfy the noindex gate while being exactly
what the gate exists to catch.

    python3 -I tests/test_state_intros.py
"""
import os
import re
import sys

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..")
sys.path.insert(0, ROOT)
from content.state_intros import INTROS  # noqa: E402
from content.state_legal import CHECKED, NOTES  # noqa: E402
from build_state_intros import (  # noqa: E402
    MIN_LENGTH, US_STATES, shingles)

fails = []


def check(label, cond, detail=""):
    if cond:
        print("PASS:", label)
    else:
        print("FAIL:", label, "--", detail)
        fails.append(label)


# --- Coverage ----------------------------------------------------------

check("all 51 destinations have an intro",
      len(US_STATES) == 51 and all(s in INTROS for s in US_STATES),
      f"{len(INTROS)} written, {len(US_STATES)} destinations")

check("nothing is written for a place that is not a destination",
      set(INTROS) <= set(US_STATES),
      str(set(INTROS) - set(US_STATES)))

# --- Each one earns the page its indexing ------------------------------

short = {s: len(t) for s, t in INTROS.items() if len(t) < MIN_LENGTH}
check(f"every intro is at least {MIN_LENGTH} characters", not short, str(short))

unnamed = [s for s, t in INTROS.items() if s not in t]
check("every intro names its own state", not unnamed, str(unnamed))

# --- Not templated -----------------------------------------------------
#
# The names are blanked before comparing: two states excluded by the same
# six retailers share that list as a matter of fact, not as a matter of
# writing. What is measured is the prose around it.

NAMES = set()
for text in INTROS.values():
    NAMES |= set(re.findall(r"\b(?:[A-Z][a-z]+ )*[A-Z][a-z]+ (?:CBD|Farms|"
                            r"Wellness|Depot|Store|Head|Hemp)\b", text))

grams = {s: shingles(t, NAMES) for s, t in INTROS.items()}
worst = (0, "", "")
for i, a in enumerate(sorted(grams)):
    for b in sorted(grams)[i + 1:]:
        shared = len(grams[a] & grams[b])
        if shared > worst[0]:
            worst = (shared, a, b)

check("no two intros share more than six eight-word runs",
      worst[0] <= 6, f"{worst[1]} and {worst[2]} share {worst[0]}")

# The guard has to be able to fail, or it is decoration. Two identical
# paragraphs must trip it.
twins = dict(INTROS)
twins["Ohio"] = twins["Texas"]
tgrams = {s: shingles(t, NAMES) for s, t in twins.items()}
check("the duplicate guard would catch a copy-paste",
      len(tgrams["Ohio"] & tgrams["Texas"]) > 6,
      "identical text must share more than six runs")

# --- No invented authority ---------------------------------------------
#
# These pages carry no legal claims on purpose: state law changes, and
# nothing here has a citation behind it. Legal copy belongs in the
# per-term vc_legal_note field, sourced, which is a separate job.

LEGAL_CLAIM = re.compile(
    r"\b(is illegal|is banned|state law prohibits|law requires|"
    r"is prohibited by law|flavou?r ban|excise tax|is unlawful)\b", re.I)
legal = {s: LEGAL_CLAIM.search(t).group(0)
         for s, t in INTROS.items() if LEGAL_CLAIM.search(t)}
check("no intro states what the law is", not legal, str(legal))

# A claim about a shop has to be about what the shop itself published.
check("no intro claims a shop is untrustworthy",
      not any(re.search(r"\b(scam|fraudulent|disreputable|rip-?off|"
                        r"untrustworthy|avoid this)\b", t, re.I)
              for t in INTROS.values()))

# --- The legal notes --------------------------------------------------
#
# The claims in these are checked against the fetched source by
# build_state_intros.py, which needs that JSON. What can be checked here is
# that all 51 exist, that none of them gives advice, and that none of them
# carries a citation to a jurisdiction other than its own -- a copy-paste
# between two states would otherwise cite Alabama's code on Alaska's page.

check("all 51 destinations have a legal note",
      all(s in NOTES for s in US_STATES),
      str([s for s in US_STATES if s not in NOTES]))

check("the checked date is a plain ISO date",
      re.fullmatch(r"\d{4}-\d{2}-\d{2}", CHECKED) is not None, CHECKED)

thin = {s: len(t) for s, t in NOTES.items() if len(t) < 250}
check("no legal note is too thin to be worth publishing", not thin, str(thin))

ADVICE = re.compile(
    r"\b(you may legally|it is legal for you|we recommend|"
    r"you are allowed to|this means you can|is legal to buy)\b", re.I)
advice = {s: ADVICE.search(t).group(0) for s, t in NOTES.items()
          if ADVICE.search(t)}
check("no legal note gives advice rather than describing", not advice,
      str(advice))

# Each state's own name, or an abbreviation of it, should be what the
# citations in its note refer to. A wholesale copy-paste shows up as
# another state's statutory prefix.
PREFIX = {
    "Alabama": "Ala.", "Alaska": "Alaska", "Arizona": "Ariz.",
    "Arkansas": "Ark.", "California": "Cal.", "Colorado": "Colo.",
    "Connecticut": "Conn.", "Delaware": "Del.", "Florida": "Fla.",
    "Georgia": "Ga.", "Hawaii": "Haw.", "Idaho": "Idaho",
    "Illinois": "Ill.", "Indiana": "Ind.", "Iowa": "Iowa",
    "Kansas": "Kan.", "Kentucky": "Ky.", "Louisiana": "La.",
    "Maine": "Me.", "Maryland": "Md.", "Massachusetts": "Mass.",
    "Michigan": "Mich.", "Minnesota": "Minn.", "Mississippi": "Miss.",
    "Missouri": "Mo.", "Montana": "Mont.", "Nebraska": "Neb.",
    "Nevada": "Nev.", "New Hampshire": "N.H.", "New Jersey": "N.J.",
    "New Mexico": "N.M.", "New York": "N.Y.", "North Carolina": "N.C.",
    "North Dakota": "N.D.", "Ohio": "Ohio", "Oklahoma": "Okla.",
    "Oregon": "Or.", "Pennsylvania": "Pa.", "Rhode Island": "R.I.",
    "South Carolina": "S.C.", "South Dakota": "S.D.",
    "Tennessee": "Tenn.", "Texas": "Tex.", "Utah": "Utah",
    "Vermont": "Vt.", "Virginia": "Va.", "Washington": "Wash.",
    "West Virginia": "W. Va.", "Wisconsin": "Wis.", "Wyoming": "Wyo.",
    "District of Columbia": "D.C.",
}
wrong = []
for state, note in NOTES.items():
    mine = PREFIX[state]
    # The state's own prefix comes out first. Without that, "W. Va. Code"
    # reads as a Virginia citation on West Virginia's page, because "Va."
    # is a substring of it -- which is what this check reported the first
    # time it ran.
    masked = note.replace(mine, " ")
    for other, prefix in PREFIX.items():
        if other == state or prefix == mine:
            continue
        if re.search(r"(?<![A-Za-z.])" + re.escape(prefix) + r"\s*(Code|Stat|"
                     r"Rev|Gen|Cent|Comp|Admin|Bus|Health)", masked):
            wrong.append(f"{state} cites {prefix}")
check("no legal note cites another state's code", not wrong, str(wrong))

ngrams = {s: shingles(t) for s, t in NOTES.items()}
worst = (0, "", "")
for i, a in enumerate(sorted(ngrams)):
    for b in sorted(ngrams)[i + 1:]:
        shared = len(ngrams[a] & ngrams[b])
        if shared > worst[0]:
            worst = (shared, a, b)
check("no two legal notes share more than six eight-word runs",
      worst[0] <= 6, f"{worst[1]} and {worst[2]} share {worst[0]}")

print()
if fails:
    print(f"{len(fails)} check(s) FAILED")
    sys.exit(1)
print(f"All {len(INTROS)} state intro and {len(NOTES)} legal note "
      f"checks passed.")
