#!/usr/bin/env python3
"""
Policy page discovery.

The fetching itself is not testable without a network, so it is kept dull
and the judgement lives here: which of a store's links is its returns page.
Getting this wrong is quiet -- you extract a real fact from the wrong
document and publish a confident claim sourced to a page that never said it.

    python3 -I tests/test_policy_fetch.py
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from policy_fetch import cache_path, discover  # noqa: E402

FAIL = 0


def check(label, condition, detail=""):
    global FAIL
    if condition:
        print("PASS: %s" % label)
    else:
        FAIL += 1
        print("FAIL: %s%s" % (label, " -- %s" % detail if detail else ""))


BASE = "https://shop.example"

FOOTER = """
<footer>
  <a href="/pages/return-policy">Returns &amp; Refunds</a>
  <a href="/pages/shipping-info">Shipping Information</a>
  <a href="/pages/faq">FAQ</a>
  <a href="/pages/terms-of-service">Terms of Service</a>
  <a href="/pages/age-verification">Age Verification</a>
</footer>
"""

found = discover(FOOTER, BASE)

check("the returns page is found",
      found.get("returns") == BASE + "/pages/return-policy", found.get("returns"))
check("the shipping page is found",
      found.get("shipping") == BASE + "/pages/shipping-info", found.get("shipping"))
check("the FAQ is found", found.get("faq") == BASE + "/pages/faq", found.get("faq"))
check("the terms page is found",
      found.get("terms") == BASE + "/pages/terms-of-service", found.get("terms"))
check("the age page is found",
      found.get("age") == BASE + "/pages/age-verification", found.get("age"))

# "return-policy" must not be claimed by the terms rule, and vice versa.
check("returns and terms do not collide",
      found["returns"] != found["terms"])

# --- Off-site links ------------------------------------------------------

offsite = discover(
    '<a href="https://www.usps.com/returns">USPS Returns</a>'
    '<a href="/pages/our-returns">Our Returns</a>', BASE)
check("a carrier's own returns page is not treated as the store's",
      offsite.get("returns") == BASE + "/pages/our-returns",
      offsite.get("returns"))

check("an affiliate link to another shop is ignored",
      discover('<a href="https://other.example/returns">Returns</a>',
               BASE).get("returns") is None)

# --- Links that are not pages -------------------------------------------

for href, why in (("#returns", "an anchor"),
                  ("mailto:returns@shop.example", "a mailto"),
                  ("tel:+15550000", "a telephone link"),
                  ("javascript:void(0)", "a javascript link")):
    got = discover('<a href="%s">Returns</a>' % href, BASE)
    check("%s is not followed" % why, got.get("returns") is None, got)

check("a link with no href is skipped",
      discover("<a>Returns</a>", BASE).get("returns") is None)

# --- Matching on the URL as well as the text ----------------------------

check("a link whose text is unhelpful still matches on its URL",
      discover('<a href="/pages/refund-policy">Click here</a>',
               BASE).get("returns") == BASE + "/pages/refund-policy")

check("a link with nested markup is read",
      discover('<a href="/returns"><span>Return</span> <b>Policy</b></a>',
               BASE).get("returns") == BASE + "/returns")

# --- First match wins, deterministically --------------------------------

twice = discover('<a href="/returns-a">Returns</a>'
                 '<a href="/returns-b">Returns</a>', BASE)
check("the first returns link wins rather than the last",
      twice.get("returns") == BASE + "/returns-a", twice.get("returns"))

# --- A promo banner must not outrank the real policy URL ----------------

promo = discover(
    '<a href="/e-liquids">Free shipping on all e-liquids!</a>'
    '<a href="/pages/shipping-policy">Delivery</a>', BASE)
check("the policy URL beats a promo link whose text mentions shipping",
      promo.get("shipping") == BASE + "/pages/shipping-policy",
      promo.get("shipping"))

# Text is still used when nothing matches on the path at all.
check("link text is still used when no URL matches",
      discover('<a href="/p/1234">Shipping Information</a>',
               BASE).get("shipping") == BASE + "/p/1234")

# A short token matched unbounded is how "Shipping Information" became a
# returns page: "rma" sits inside "info-rma-tion".
check("a short keyword does not match inside a longer word",
      discover('<a href="/pages/shipping-info">Shipping Information</a>',
               BASE).get("returns") is None,
      discover('<a href="/pages/shipping-info">Shipping Information</a>', BASE))

check("a page with no policy links yields nothing",
      discover("<p>Welcome to the shop.</p>", BASE) == {})

# --- The cache path must not be built from attacker-controlled text ------

evil = "https://shop.example/../../etc/passwd?x=/../../"
path = cache_path("/tmp/cache", evil)
check("the cache filename is a hash, so a hostile URL cannot escape the dir",
      os.path.dirname(path) == "/tmp/cache" and ".." not in os.path.basename(path),
      path)
check("the cache filename is stable for the same URL",
      cache_path("/tmp/cache", evil) == path)
check("and different URLs get different files",
      cache_path("/tmp/cache", evil) != cache_path("/tmp/cache", evil + "2"))

print()
if FAIL:
    print("%d check(s) FAILED" % FAIL)
    sys.exit(1)
print("All policy fetch checks passed.")
