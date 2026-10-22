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

print()
if fails:
    print(f"{len(fails)} check(s) FAILED")
    sys.exit(1)
print(f"All {len(INTROS)} state intro checks passed.")
