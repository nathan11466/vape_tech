#!/usr/bin/env python3
"""
Policy fact extraction.

What this has to get right is not finding facts -- that is easy and a loose
pattern finds plenty. It is refusing to find them. Every false positive here
becomes a specific, confident, wrong claim about someone's money on a live
page, and the three ways it went wrong earlier in this project were: chrome
read as prose, a number in one paragraph attached to a subject in another,
and several values merged into one.

    python3 -I tests/test_policy_extract.py
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from policy_extract import (extract, merge, sentences,  # noqa: E402
                            text_of)

FAIL = 0


def check(label, condition, detail=""):
    global FAIL
    if condition:
        print("PASS: %s" % label)
    else:
        FAIL += 1
        print("FAIL: %s%s" % (label, " -- %s" % detail if detail else ""))


def page(body):
    return "<html><body>%s</body></html>" % body


def facts_of(body, url="https://shop.example/returns"):
    return extract(page(body), url)


# --- Chrome is not prose ---------------------------------------------------

nav = page("""
<nav><ul><li>Returns</li><li>30 days</li><li>Shipping</li></ul></nav>
<p>We sell hardware.</p>
""")
check("a nav bar cannot supply a fact",
      "return_window_days" not in extract(nav, "u")["facts"],
      extract(nav, "u")["facts"])

script = page("""
<script>var returnWindow = "within 45 days of delivery for a refund";</script>
<p>We sell hardware.</p>
""")
check("script contents cannot supply a fact",
      "return_window_days" not in extract(script, "u")["facts"])

dropdown = page("""
<select name="window"><option>within 7 days for a refund</option>
<option>within 90 days for a refund</option></select>
<p>We sell hardware.</p>
""")
check("a dropdown's options cannot supply a fact",
      "return_window_days" not in extract(dropdown, "u")["facts"])

footer = page("<footer><p>Returns accepted within 60 days of purchase.</p></footer>")
check("a footer cannot supply a fact",
      "return_window_days" not in extract(footer, "u")["facts"])

# --- Adjacent list items must not read as one sentence --------------------

run_on = page("<ul><li>Returns are accepted.</li><li>Orders ship in 3 days.</li></ul>")
got = extract(run_on, "u")
check("two list items do not merge into one sentence",
      "return_window_days" not in got["facts"], got["facts"])

# The previous case still splits on its full stops. List items with no
# terminal punctuation at all are the real hazard: run together they form one
# sentence carrying a trigger from one bullet and a number from another.
unpunctuated = page("<ul><li>No restocking fees ever</li>"
                    "<li>Our sales tax rate is 15%</li></ul>")
check("unpunctuated list items do not fuse into one false fact",
      "restocking_fee_percent" not in extract(unpunctuated, "u")["facts"],
      extract(unpunctuated, "u")["facts"])

check("and the shipping fact in the second item is still read",
      got["facts"].get("processing_days", {}).get("value") == 3,
      got["facts"].get("processing_days"))

# --- The number must belong to the subject -------------------------------

split_subject = page("""
<p>Returns and exchanges are handled by our support team.</p>
<p>Orders are delivered within 30 days to remote areas.</p>
""")
check("a number in another paragraph is not a return window",
      "return_window_days" not in extract(split_subject, "u")["facts"],
      extract(split_subject, "u")["facts"])

shipping_days = page("<p>Returns accepted. Standard shipping takes 14 days.</p>")
check("a shipping duration is not a return window",
      "return_window_days" not in extract(shipping_days, "u")["facts"],
      extract(shipping_days, "u")["facts"])

# --- Facts that are plainly stated ---------------------------------------

good = facts_of("""
<p>You may return unopened items within 30 days of delivery for a full refund.</p>
<p>A 15% restocking fee applies to opened hardware.</p>
<p>The customer is responsible for return shipping on all returns.</p>
""")
check("a plain return window is read",
      good["facts"].get("return_window_days", {}).get("value") == 30,
      good["facts"].get("return_window_days"))
check("a restocking fee is read",
      good["facts"].get("restocking_fee_percent", {}).get("value") == 15,
      good["facts"].get("restocking_fee_percent"))
check("who pays return shipping is read",
      good["facts"].get("return_shipping_paid_by", {}).get("value") == "customer",
      good["facts"].get("return_shipping_paid_by"))

check("every fact carries the sentence it came from",
      all(f["evidence"] for f in good["facts"].values()))
check("and the URL it was found on",
      all(f["source_url"] == "https://shop.example/returns"
          for f in good["facts"].values()))
check("the evidence is the real sentence, not a normalized form",
      "unopened items within 30 days"
      in good["facts"]["return_window_days"]["evidence"],
      good["facts"]["return_window_days"]["evidence"])

ship = facts_of("<p>Free shipping on orders over $75 via USPS Priority Mail.</p>")
check("a free-shipping threshold is read",
      ship["facts"].get("free_shipping_threshold_usd", {}).get("value") == 75,
      ship["facts"].get("free_shipping_threshold_usd"))
check("the carrier is read",
      ship["facts"].get("shipping_carriers", {}).get("value") == "USPS",
      ship["facts"].get("shipping_carriers"))

carriers = facts_of("<p>We ship with USPS, UPS and FedEx depending on destination.</p>")
check("several carriers are complementary, not a conflict",
      carriers["facts"].get("shipping_carriers", {}).get("value")
      == "FedEx|UPS|USPS",
      carriers["facts"].get("shipping_carriers"))

age = facts_of("<p>All orders require age verification through AgeChecker "
               "and an adult signature on delivery.</p>")
check("age verification methods are read",
      age["facts"].get("age_verification_method", {}).get("value")
      == "Adult signature on delivery|AgeChecker",
      age["facts"].get("age_verification_method"))

months = facts_of("<p>Hardware carries a 3 month warranty against defects.</p>")
check("a warranty in months is converted to days",
      months["facts"].get("warranty_days", {}).get("value") == 90,
      months["facts"].get("warranty_days"))

# --- A narrow clause is not the general policy ---------------------------

# Element Vape states this, and reading it as a 30-day return window would
# publish a promise the store never made.
defect = facts_of("<p>If the item you have purchased from us is defective "
                  "when you receive it, you have 30 days to return it to us "
                  "from the date it was shipped to you.</p>")
check("a defective-goods window is not read as the return policy",
      "return_window_days" not in defect["facts"], defect["facts"])

check("but an ordinary condition does not disqualify a window",
      facts_of("<p>Unopened items may be returned within 30 days.</p>")
      ["facts"].get("return_window_days", {}).get("value") == 30)

# --- Thresholds stated the other way round ------------------------------

for body, want in (
        ("<p>Spend $50 for free shipping.</p>", 50),
        ("<p>Taxes do not count towards the $95+ free shipping.</p>", 95),
        ("<p>Note: fees do not count towards the $99 free shipping.</p>", 99),
        ("<p>Free shipping on orders over $75.</p>", 75),
        ("<p>Free shipping for orders of $60 or more.</p>", 60)):
    got = facts_of(body)["facts"].get("free_shipping_threshold_usd", {})
    check("threshold read from %r" % body[3:36], got.get("value") == want,
          "got %s want %s" % (got.get("value"), want))

# --- Conflicts return nothing --------------------------------------------

conflict = facts_of("""
<p>Returns are accepted within 14 days of delivery.</p>
<p>Unopened hardware may be returned within 30 days of delivery.</p>
""")
check("two different return windows produce no fact",
      "return_window_days" not in conflict["facts"], conflict["facts"])
check("and the disagreement is recorded for a person to read",
      conflict["conflicts"].get("return_window_days") == [14, 30],
      conflict["conflicts"])

both_ways = facts_of("""
<p>Enjoy free return shipping on every order.</p>
<p>The customer pays return shipping for international orders.</p>
""")
check("a page saying returns are both free and paid produces no fact",
      "return_shipping_paid_by" not in both_ways["facts"],
      both_ways["facts"])

same_twice = facts_of("""
<p>Returns are accepted within 30 days.</p>
<p>Remember: returns must reach us within 30 days of delivery.</p>
""")
check("the same value stated twice is not a conflict",
      same_twice["facts"].get("return_window_days", {}).get("value") == 30,
      same_twice["facts"].get("return_window_days"))

# --- Nothing found is a clean nothing ------------------------------------

empty = facts_of("<p>Welcome to our store. We sell vape hardware and e-liquid.</p>")
check("a page stating no policy yields no facts",
      empty["facts"] == {} and empty["conflicts"] == {}, empty)

check("an empty document does not raise",
      extract("", "u")["facts"] == {})
check("markup with no body does not raise",
      extract("<html><head><title>x</title></head></html>", "u")["facts"] == {})

# --- Merging across a store's pages --------------------------------------

returns_page = ("returns", facts_of("<p>Returns accepted within 30 days.</p>",
                                    "https://shop.example/returns"))
faq_page = ("faq", facts_of("<p>Returns are accepted within 45 days, we think.</p>",
                            "https://shop.example/faq"))

merged = merge([faq_page, returns_page])
check("the page the fact is about wins over the FAQ",
      merged["facts"].get("return_window_days", {}).get("value") == 30,
      merged["facts"].get("return_window_days"))
check("and the winning source URL is the returns page",
      merged["facts"]["return_window_days"]["source_url"].endswith("/returns"),
      merged["facts"]["return_window_days"]["source_url"])

ship_page = ("shipping", facts_of("<p>Free shipping on orders over $50.</p>",
                                  "https://shop.example/shipping"))
terms_page = ("terms", facts_of("<p>Free shipping on orders over $99.</p>",
                                "https://shop.example/terms"))
merged2 = merge([terms_page, ship_page])
check("the shipping page outranks the terms page for a shipping fact",
      merged2["facts"].get("free_shipping_threshold_usd", {}).get("value") == 50,
      merged2["facts"].get("free_shipping_threshold_usd"))

# Two pages of equal standing that disagree: neither is used.
faq_a = ("faq", facts_of("<p>A 10% restocking fee applies.</p>", "a"))
faq_b = ("faq", facts_of("<p>A 20% restocking fee applies.</p>", "b"))
merged3 = merge([faq_a, faq_b])
check("two equal pages that disagree produce no fact",
      "restocking_fee_percent" not in merged3["facts"], merged3["facts"])
check("and the disagreement survives the merge",
      "restocking_fee_percent" in merged3["conflicts"], merged3["conflicts"])

# A conflict found inside one page must not be resurrected by another.
bad_page = ("returns", facts_of("<p>Returns within 14 days.</p>"
                                "<p>Returns within 60 days of delivery.</p>", "a"))
ok_page = ("faq", facts_of("<p>Returns are accepted within 30 days.</p>", "b"))
merged4 = merge([bad_page, ok_page])
check("a conflict on the authoritative page is not papered over by another",
      "return_window_days" not in merged4["facts"], merged4["facts"])

check("merging nothing yields nothing",
      merge([]) == {"facts": {}, "conflicts": {}})

# --- Helpers --------------------------------------------------------------

check("short fragments are dropped as labels",
      "Returns" not in sentences("Returns\nWe accept returns within 30 days."))
check("entities are decoded so patterns can match",
      "don't" in text_of("<p>We don&#039;t accept opened items.</p>"))

print()
if FAIL:
    print("%d check(s) FAILED" % FAIL)
    sys.exit(1)
print("All policy extraction checks passed.")
