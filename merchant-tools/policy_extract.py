#!/usr/bin/env python3
"""
Pull checkable facts out of a store's own policy pages.

The point of this is to widen what compose_sections.py has to work with. Its
whole fact vocabulary is eight slots, most stores fill two or three, and no
amount of rewriting produces 112 distinguishable paragraphs from that. More
facts per store makes every composed section more specific for free.

Three rules, each learned from getting it wrong earlier in this project:

  Only what the store states. A fact is returned with the sentence it came
  from and the URL it was on, or it is not returned. Nothing is inferred from
  a sibling field, a category, or what is usual for the trade.

  Conflicts return nothing. A page that says 14 days in one place and 30 in
  another is a page we do not understand, and guessing produces a confident
  wrong answer on a page about money. Earlier a per-product restriction list
  got merged into "all 50 states" exactly this way.

  Chrome is not prose. Navigation, selects, footers and scripts are stripped
  before anything is read. A nav bar flattened into one line previously put a
  whole state on an exclusion list because the words happened to be adjacent.

No network here, so every extractor is unit-testable against a fixture. The
HTML this reads is untrusted third-party input: it is only ever pattern
matched, never executed or evaluated.
"""

import re

# --- Reading the page ------------------------------------------------------

# Containers whose text is site furniture rather than policy.
_CHROME = ("script", "style", "noscript", "nav", "header", "footer",
           "select", "option", "form", "aside", "template", "svg", "button")


def text_of(html):
    """Readable prose, with markup and chrome taken out."""
    plain = html
    for tag in _CHROME:
        plain = re.sub(r"(?is)<%s\b.*?</%s\s*>" % (tag, tag), " ", plain)
    # Block ends become line breaks so sentences cannot run together across
    # list items -- two adjacent <li> read as one sentence is how unrelated
    # phrases first started matching each other.
    plain = re.sub(r"(?i)</(p|div|li|tr|td|h[1-6]|br)\s*/?>", "\n", plain)
    plain = re.sub(r"(?i)<br\s*/?>", "\n", plain)
    plain = re.sub(r"<[^>]+>", " ", plain)
    for entity, char in (("&nbsp;", " "), ("&amp;", "&"), ("&#039;", "'"),
                         ("&rsquo;", "'"), ("&quot;", '"'), ("&#8217;", "'"),
                         ("&ldquo;", '"'), ("&rdquo;", '"'), ("&mdash;", "-"),
                         ("&ndash;", "-")):
        plain = plain.replace(entity, char)
    plain = plain.replace("’", "'").replace("“", '"')
    plain = plain.replace("”", '"').replace("—", "-")
    plain = re.sub(r"[ \t]+", " ", plain)
    return re.sub(r"\n\s*\n+", "\n", plain).strip()


def sentences(text):
    """
    The text as candidate sentences.

    Split on line breaks as well as sentence enders: policy pages carry half
    their content in bullet lists with no full stops at all.
    """
    out = []
    for line in text.split("\n"):
        line = line.strip()
        if not line:
            continue
        for part in re.split(r"(?<=[.!?])\s+(?=[A-Z0-9])", line):
            part = part.strip()
            # A fragment this short is a label or a menu item.
            if len(part) >= 12:
                out.append(part)
    return out


def _snippet(sentence, limit=200):
    one = re.sub(r"\s+", " ", sentence).strip()
    return one if len(one) <= limit else one[:limit - 1] + "…"


# --- Fact definitions ------------------------------------------------------
#
# Each scalar fact is a trigger (what the sentence must be about) plus a value
# pattern. Both must hit in the SAME sentence: a page mentioning returns in
# one paragraph and "30 days" in another, about shipping, is not stating a
# return window.

_DAYS = r"(\d{1,3})\s*(?:-|\s)?\s*(?:calendar\s+|business\s+|working\s+)?days?"

SCALAR_FACTS = {
    "return_window_days": {
        "trigger": r"\b(?:return(?:s|ed|ing)?|refund(?:s|ed|able)?"
                   r"|exchange(?:s|d)?|rma)\b",
        "value": r"(?:within|up to|allow|have|period of|for)\s+" + _DAYS
                 + r"|\b" + _DAYS + r"\s+(?:return|refund|exchange|window|policy|period|of (?:delivery|receipt|purchase))",
        # Narrow on purpose: "within 30 days of delivery" IS a return window,
        # so excluding every sentence mentioning delivery discarded the most
        # common phrasing there is. Only a duration governing transit is out.
        # A window that applies only to broken goods is not the store's
        # return policy. Element Vape states "if the item ... is defective
        # ... you have 30 days", and recording that as a 30-day return
        # window would publish a promise the store never made.
        "exclude": r"(?:shipping|delivery|transit)\s+(?:takes|time)"
                   r"|to\s+(?:arrive|ship)\b|\bwarranty\b"
                   r"|defect|damaged|faulty|malfunction|\bdoa\b",
        "unit": "days",
    },
    "restocking_fee_percent": {
        "trigger": r"\brestocking|restock fee|re-?stocking\b",
        "value": r"(\d{1,2}(?:\.\d+)?)\s*%",
        "unit": "percent",
    },
    "warranty_days": {
        "trigger": r"\bwarranty|guarantee\b",
        "value": r"" + _DAYS + r"|(\d{1,2})\s*(?:-|\s)?months?",
        "exclude": r"\b(?:return|refund|shipping)\b",
        "unit": "days",
    },
    "free_shipping_threshold_usd": {
        "trigger": r"free\s+(?:standard\s+|ground\s+|domestic\s+)?(?:shipping|delivery)",
        "value": r"(?:over|above|of|orders?\s+(?:over|above|of)|spend|minimum of)"
                 r"\s*\$\s*(\d{1,4})"
                 r"|\$\s*(\d{1,4})\s*\+"
                 r"|\$\s*(\d{1,4})\s+(?:or more|free\s+ship)",
        "unit": "usd",
    },
    "min_order_usd": {
        "trigger": r"minimum\s+(?:order|purchase|cart|subtotal)",
        "value": r"\$\s*(\d{1,4})",
        "exclude": r"free\s+shipping",
        "unit": "usd",
    },
    "processing_days": {
        "trigger": r"\b(?:process|processed|processing|ship(?:s|ped)?|dispatch(?:ed)?|handling)\b",
        "value": r"(?:within|in)\s+" + _DAYS + r"|\b" + _DAYS
                 + r"\s+(?:to (?:process|ship)|processing|handling)",
        "exclude": r"\b(?:return|refund|warranty|transit time)\b",
        "unit": "days",
    },
}

# List facts: several values on one page are complementary, not contradictory.
LIST_FACTS = {
    "shipping_carriers": {
        "trigger": r"\b(?:ship|shipping|deliver|delivery|carrier|courier|tracking)\b",
        "values": {
            "USPS": r"\b(?:usps|united states postal|priority mail|first[- ]class mail)\b",
            "UPS": r"\bups\b",
            "FedEx": r"\bfedex\b",
            "DHL": r"\bdhl\b",
        },
    },
    "age_verification_method": {
        "trigger": r"\b(?:age|adult|21|id|identity|verif)\w*\b",
        "values": {
            "AgeChecker": r"\bagechecker\b",
            "Veratad": r"\bveratad\b",
            "BlueCheck": r"\bbluecheck\b",
            "IDology": r"\bidology\b",
            "Jumio": r"\bjumio\b",
            "Adult signature on delivery": r"adult\s+signature",
            "ID checked at delivery": r"(?:id|identification|photo id)\s+"
                                      r"(?:is\s+)?(?:required|checked|presented|shown)"
                                      r"\s+(?:up)?on\s+delivery",
            "ID upload at checkout": r"(?:upload|submit|provide)\s+"
                                     r"(?:a\s+)?(?:copy of\s+)?(?:your\s+)?"
                                     r"(?:photo\s+)?id\b",
        },
    },
}

# A few facts are a choice between named outcomes rather than a number.
CHOICE_FACTS = {
    "return_shipping_paid_by": {
        "trigger": r"\breturn(?:s|ed|ing)?\b",
        "choices": {
            "customer": r"(?:customer|buyer|you)\s+(?:is|are|will be)?\s*"
                        r"(?:responsible for|pay(?:s|ing)?|cover(?:s|ing)?)\s+"
                        r"(?:the\s+)?return\s+(?:shipping|postage|freight)"
                        r"|return\s+shipping\s+(?:is\s+)?(?:at\s+)?"
                        r"(?:the\s+)?(?:customer|buyer|your)",
            "store": r"(?:free|prepaid|pre-paid|complimentary)\s+"
                     r"return\s+(?:shipping|label|postage)"
                     r"|we\s+(?:will\s+)?(?:provide|cover|pay for)\s+"
                     r"(?:a\s+)?(?:prepaid\s+)?return\s+(?:shipping|label)",
        },
    },
}


def _value_in(sentence, spec):
    """Every distinct value the spec finds in one sentence."""
    if spec.get("exclude") and re.search(spec["exclude"], sentence, re.I):
        return []
    if not re.search(spec["trigger"], sentence, re.I):
        return []

    found = []
    for match in re.finditer(spec["value"], sentence, re.I):
        raw = next((g for g in match.groups() if g), None)
        if raw is None:
            continue
        value = int(float(raw))
        # A months figure in the warranty pattern needs converting.
        if spec.get("unit") == "days" and re.search(
                re.escape(raw) + r"\s*(?:-|\s)?months?", match.group(0), re.I):
            value *= 30
        if value > 0 and value not in found:
            found.append(value)
    return found


def extract(html, url):
    """
    Facts this page states, as {key: {value, source_url, evidence}}.

    A key is absent unless the page says it plainly. A key whose value the
    page contradicts itself about is absent too, with the disagreement noted
    under conflicts so it can be read by a person rather than guessed at.
    """
    text = text_of(html)
    lines = sentences(text)

    facts = {}
    conflicts = {}

    # --- scalars
    for key, spec in SCALAR_FACTS.items():
        hits = {}
        for sentence in lines:
            for value in _value_in(sentence, spec):
                hits.setdefault(value, sentence)

        if not hits:
            continue
        if len(hits) > 1:
            conflicts[key] = sorted(hits)
            continue

        value, sentence = next(iter(hits.items()))
        facts[key] = {
            "value": value,
            "unit": spec.get("unit", ""),
            "source_url": url,
            "evidence": _snippet(sentence),
        }

    # --- lists
    for key, spec in LIST_FACTS.items():
        found = {}
        for sentence in lines:
            if not re.search(spec["trigger"], sentence, re.I):
                continue
            for label, pattern in spec["values"].items():
                if label not in found and re.search(pattern, sentence, re.I):
                    found[label] = sentence
        if found:
            facts[key] = {
                "value": "|".join(sorted(found)),
                "unit": "",
                "source_url": url,
                "evidence": _snippet(found[sorted(found)[0]]),
            }

    # --- choices
    for key, spec in CHOICE_FACTS.items():
        found = {}
        for sentence in lines:
            if not re.search(spec["trigger"], sentence, re.I):
                continue
            for label, pattern in spec["choices"].items():
                if label not in found and re.search(pattern, sentence, re.I):
                    found[label] = sentence
        if len(found) == 1:
            label, sentence = next(iter(found.items()))
            facts[key] = {
                "value": label,
                "unit": "",
                "source_url": url,
                "evidence": _snippet(sentence),
            }
        elif len(found) > 1:
            # Both "customer pays" and "free returns" on one page is a page
            # we have not understood.
            conflicts[key] = sorted(found)

    return {"facts": facts, "conflicts": conflicts}


def merge(per_page):
    """
    Facts from several pages of one store into one set.

    A fact stated on the page that is about it wins: a return window on the
    returns page beats the same words in a FAQ. Where two pages give
    different values for the same key, neither is used -- the store has not
    told us plainly, and picking the first page crawled is arbitrary.
    """
    # Lower sorts first, so the returns page is preferred for return facts.
    priority = {"returns": 0, "shipping": 1, "age": 2, "terms": 3, "faq": 4,
                "home": 5}

    best = {}
    conflicts = {}

    for kind, result in sorted(per_page, key=lambda p: priority.get(p[0], 9)):
        for key, found in result["facts"].items():
            if key in conflicts:
                continue
            if key not in best:
                best[key] = (kind, found)
                continue
            seen_kind, seen = best[key]
            if seen["value"] == found["value"]:
                continue
            # A more authoritative page already answered this.
            if priority.get(seen_kind, 9) < priority.get(kind, 9):
                continue
            conflicts[key] = [seen["value"], found["value"]]
            del best[key]

        for key, detail in result["conflicts"].items():
            conflicts.setdefault(key, detail)
            best.pop(key, None)

    return {"facts": {k: v for k, (_, v) in best.items()},
            "conflicts": conflicts}
