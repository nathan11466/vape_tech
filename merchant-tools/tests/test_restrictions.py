"""
Shipping-restriction and review-link parsing in fetch_merchant_data.py.

The failure that costs money is a false positive. A state written into
restricted_states takes that store off the state's archive page, so a
misread "CA" (Canada) or "OR" (the word) silently removes a merchant from
one of the pages this whole project exists to rank.

The failure that costs coverage is the opposite: an exclusion list long
enough to be thrown away, which is what the old 600-character cap did to
every store that used a bulleted list.

    python3 -I tests/test_restrictions.py
"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
from fetch_merchant_data import (  # noqa: E402
    abbrevs_in, find_policies, find_restrictions, find_review_pages,
    is_exclusion, row_key, visible_text, MAX_PLAUSIBLE_EXCLUSIONS,
    STALE_NO_RESTRICTIONS, US_STATES)

fails = []


def check(label, cond, detail=""):
    if cond:
        print("PASS:", label)
    else:
        print("FAIL:", label, "--", detail)
        fails.append(label)


# --- Codes, which is how stores actually write it ----------------------

found = find_restrictions(
    "<p>Due to state law we cannot ship to AR, HI, SD, UT or VT.</p>")
check("a list of codes is read", found == ["Arkansas", "Hawaii",
      "South Dakota", "Utah", "Vermont"], str(found))

found = find_restrictions(
    "We do not ship to the following states: AK, CA, MA, NY, OR, RI, VT, UT.")
check("a colon-introduced list is read",
      "California" in found and "Oregon" in found and "Rhode Island" in found,
      str(found))

# --- Codes that are also English words, or other countries -------------

check("a lone CA is not California",
      find_restrictions("We cannot ship outside the US, UK or CA.") == [],
      str(find_restrictions("We cannot ship outside the US, UK or CA.")))

check("a lone OR is not Oregon",
      find_restrictions("We do not ship to PO boxes OR freight forwarders.")
      == [])

check("two codes are not enough",
      find_restrictions("We cannot ship to HI or AK.") == [],
      "two is ambiguous; three makes it a list")

check("abbrevs_in needs three", abbrevs_in("not to HI, AK") == [])
check("abbrevs_in accepts three",
      abbrevs_in("not to HI, AK, UT") == ["Hawaii", "Alaska", "Utah"],
      str(abbrevs_in("not to HI, AK, UT")))

check("lower-case words are not codes",
      abbrevs_in("we cannot ship in or to me, ok?") == [],
      "matching must be case-sensitive")

# --- Full names still work ---------------------------------------------

found = find_restrictions(
    "Orders are shipped nationwide except to Utah and Vermont.")
check("full names in an 'except' clause",
      found == ["Utah", "Vermont"], str(found))

check("a single full name is enough",
      find_restrictions("We are prohibited from shipping to Utah.")
      == ["Utah"])

# --- A sentence with no exclusion cue must not contribute --------------

check("shipping FROM a state is not a restriction",
      find_restrictions("All orders ship from our California warehouse.")
      == [])

check("a state named in an unrelated sentence is ignored",
      find_restrictions("We cannot ship to Utah. Our office is in Nevada.")
      == ["Utah"],
      str(find_restrictions(
          "We cannot ship to Utah. Our office is in Nevada.")))

# --- The long-list regression -------------------------------------------

sentence = (
    "In accordance with the federal PACT Act and with various state and "
    "local regulations governing the sale and delivery of vapor products, "
    "we regret that we are currently unable to ship any vapor products, "
    "e-liquids, disposable devices, replacement coils, pods, tanks, "
    "batteries or related accessories to any customer whose billing "
    "address or shipping address is located in one of the following "
    "states and territories, and please note that this list is reviewed "
    "regularly as legislation changes at both the state and the federal "
    "level, so do check back before placing an order with us: AR, CA, "
    "HI, IN, ME, MA, NV, NJ, NY, ND, OR, RI, SD, UT, VT, WA")
check("the long list is over the old 600-character cap",
      len(sentence) > 600, f"{len(sentence)} chars - test proves nothing")

found = find_restrictions(
    f"<p>{sentence}. Customers in all other states may order normally.</p>")
check("a long exclusion list is not discarded", len(found) == 16, str(found))
check("the long list is read as codes",
      "Arkansas" in found and "Washington" in found, str(found))

# A whole untagged page is still rejected, or every state on a legal page
# would be read as excluded.
page = "We cannot ship everywhere. " + ("filler words here. " * 400)
page = page.replace(". ", " ")  # one giant sentence
check("a whole page as one sentence is rejected",
      find_restrictions(page + " Utah Vermont") == [], "cap must still bite")

# --- Review links: only what the store publishes -----------------------

links = [
    ("https://www.trustpilot.com/review/vapestore.com", "Read our reviews"),
    ("https://www.facebook.com/vapestore", "Facebook"),
]
check("a linked Trustpilot profile is found",
      find_review_pages(links).get("Trustpilot")
      == "https://www.trustpilot.com/review/vapestore.com")

check("no review link means none is invented",
      find_review_pages([("https://www.facebook.com/x", "fb")]) == {})

check("BBB counts too",
      find_review_pages([("https://www.bbb.org/us/ca/x/profile/1", "BBB")])
      .get("BBB", "").endswith("/profile/1"))

# --- Resume keys --------------------------------------------------------

check("the merchant key is preferred",
      row_key({"externalMerchantKey": "m-91", "brand_url": "https://x.com"})
      == "m-91")
check("brand_url is the fallback",
      row_key({"externalMerchantKey": "", "brand_url": "https://x.com"})
      == "https://x.com")
check("a row with nothing to key on returns empty",
      row_key({"externalMerchantKey": "", "brand_url": ""}) == "")

# --- Script and style content is not prose -----------------------------

page = ('<p>Shipping is free over $50.</p>'
        '<script>var banner = {state: "Utah", msg: "cannot ship"};</script>'
        '<style>.banner { color: red }</style>')
check("script bodies are stripped before parsing",
      "banner" not in visible_text(page) and "Utah" not in visible_text(page),
      visible_text(page))
check("visible prose survives", "Shipping is free" in visible_text(page))
check("a state only mentioned in JavaScript is not an exclusion",
      find_restrictions(page) == [], str(find_restrictions(page)))

# --- Loose cue words need shipping context -----------------------------

check("a blog headline is not an exclusion",
      find_restrictions(
          '<a href="/blog">Pennsylvania Vape Ban 2026</a>') == [],
      str(find_restrictions('<a href="/blog">Pennsylvania Vape Ban 2026</a>')))

check("'ban' inside another word is not a cue",
      not is_exclusion("Our Albany warehouse ships the same day."),
      "boundary-less 'ban' matched Albany, banner and urban")

check("a real ban sentence about shipping still counts",
      is_exclusion("Shipping to Utah is banned under state law."))

check("a restriction sentence about shipping still counts",
      is_exclusion("Delivery is restricted in some states."))

check("'restricted' with no shipping context is not a cue",
      not is_exclusion("Access to this area is restricted to staff."))

found = find_restrictions(
    "State law bans the sale of these products in AR, UT and VT.")
check("a weak cue with context still reads its list",
      found == ["Arkansas", "Utah", "Vermont"], str(found))

# --- A nav bar is not one sentence -------------------------------------

nav = ('<nav><a href="/a">Best Vapes</a><a href="/b">Pennsylvania Vape Ban '
       '2026</a><a href="/c">Top 10 Disposables</a><a href="/d">Free '
       'Shipping</a></nav>')
check("link labels do not combine into one exclusion",
      find_restrictions(nav) == [], str(find_restrictions(nav)))
check("each link becomes its own unit", visible_text(nav).count("|") == 4,
      visible_text(nav))

# --- County and city restrictions are not state restrictions -----------

sentence = ("We do not ship to the following US locations where Kratom is "
            "restricted: Alabama, Arkansas, Indiana, Rhode Island, Vermont, "
            "Wisconsin, Sarasota County (FL), Union County (NC), Denver "
            "(CO), San Diego (CA), Oceanside (CA), Jerseyville (IL), Union "
            "County (MS).")
found = find_restrictions(sentence)
check("state-level names in a mixed list are kept",
      found == ["Alabama", "Arkansas", "Indiana", "Rhode Island", "Vermont",
                "Wisconsin"], str(found))
check("a county's state is not excluded",
      not {"Florida", "North Carolina", "Colorado", "California", "Illinois",
           "Mississippi"} & set(found),
      f"one county must not remove a whole state: {found}")

check("a county named with the full state is not the state",
      find_restrictions("We cannot ship to Miami-Dade County, Florida.")
      == [], str(find_restrictions(
          "We cannot ship to Miami-Dade County, Florida.")))

check("the state itself still counts when named on its own",
      find_restrictions("We cannot ship to Broward County, Florida, or to "
                        "Utah.") == ["Utah"],
      str(find_restrictions("We cannot ship to Broward County, Florida, or "
                            "to Utah.")))

check("a bracketed list of codes is still a list",
      find_restrictions("We cannot ship to the restricted states (AR, HI, "
                        "SD).") == ["Arkansas", "Hawaii", "South Dakota"],
      str(find_restrictions("We cannot ship to the restricted states (AR, "
                            "HI, SD).")))

# --- The merged per-product list ---------------------------------------

merged = (
    "Shipping restrictions All cannabinoid products : not available in "
    "Alabama, California, Idaho, New Jersey THCa : not available in "
    + ", ".join(US_STATES[:45]))
check("a page yielding more than the cap is rejected entirely",
      find_restrictions(merged) == [],
      f"{len(find_restrictions(merged))} states - a store that excludes "
      f"45 states is not a national shop")
check("the cap is where it says it is", MAX_PLAUSIBLE_EXCLUSIONS == 30)

ok = "We cannot ship to " + ", ".join(US_STATES[:20]) + "."
check("a long but plausible list is kept",
      len(find_restrictions(ok)) == 20, str(len(find_restrictions(ok))))

# --- Which link becomes the published source URL -----------------------

BASE = "https://kingkanine.example.com/"
links = [
    ("https://kingkanine.example.com/collections/cbd-oil", "Free shipping CBD oil"),
    ("https://kingkanine.example.com/policies/shipping-policy", "Our terms"),
]
found = find_policies(links, BASE)
check("a product collection is never the shipping policy",
      found.get("shipping_policy_url")
      == "https://kingkanine.example.com/policies/shipping-policy",
      str(found.get("shipping_policy_url")))

links = [
    ("https://x.example.com/pages/delivery-info", "Read this"),
    ("https://x.example.com/pages/about", "Shipping and delivery"),
]
check("a policy-shaped path beats policy-sounding link text",
      find_policies(links, "https://x.example.com/").get("shipping_policy_url")
      == "https://x.example.com/pages/delivery-info",
      str(find_policies(links, "https://x.example.com/")))

check("link text alone still works when no path matches",
      find_policies([("https://x.example.com/pages/info", "Returns policy")],
                    "https://x.example.com/").get("returns_policy_url")
      == "https://x.example.com/pages/info")

check("a blog post is not a policy page",
      find_policies([("https://x.example.com/blogs/news/our-shipping-times",
                      "Shipping")], "https://x.example.com/") == {},
      str(find_policies([("https://x.example.com/blogs/news/our-shipping-times",
                          "Shipping")], "https://x.example.com/")))

# --- The placeholder that contradicts a real find ----------------------

placeholder = ("We could not confirm any state shipping restrictions for "
               "Juice Head. Vape and hemp product rules vary by state, so "
               "check at checkout.")
check("the placeholder sentence is recognised",
      bool(STALE_NO_RESTRICTIONS.search(placeholder)))
check("a hand-written sentence is not mistaken for it",
      not STALE_NO_RESTRICTIONS.search(
          "Ships to all states except Utah; call us about Vermont."))

print()
if fails:
    print(f"{len(fails)} FAILED: " + ", ".join(fails))
    sys.exit(1)
print("All restriction checks passed.")
