"""
Logo discovery in fetch_merchant_data.py.

The failure that matters is a false positive: a payment mark, review badge or
product photo written into brand_logo_url is published on the page and fed to
Google as Organization.logo.

    python3 -I tests/test_logo_discovery.py
"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
from fetch_merchant_data import (  # noqa: E402
    find_logo, _logo_candidate_ok, LOGO_LIGHT_VARIANT)

BASE = "https://vapestore.example.com/"
fails = []


def check(label, cond, detail=""):
    if cond:
        print("PASS:", label)
    else:
        print("FAIL:", label, "--", detail)
        fails.append(label)


# --- Structured data is the most explicit claim -------------------------
html = '''<html><head>
<script type="application/ld+json">
{"@context":"https://schema.org","@type":"OnlineStore","name":"VapeStore",
 "logo":"https://cdn.example.com/files/brand-logo.png"}
</script>
<meta property="og:image" content="https://cdn.example.com/products/mango-pod.jpg">
</head><body><img class="site-logo" src="/assets/header-logo.svg"></body></html>'''
url, src = find_logo(html, BASE)
check("declared schema logo wins over markup", src == "schema"
      and url == "https://cdn.example.com/files/brand-logo.png", f"{src} {url}")

html = '''<script type="application/ld+json">
{"@type":"Organization","logo":{"@type":"ImageObject","url":"https://cdn.x.com/logo-dark.png"}}
</script>'''
check("schema logo as ImageObject is read",
      find_logo(html, BASE)[0] == "https://cdn.x.com/logo-dark.png")

html = '''<script type="application/ld+json">
{"@graph":[{"@type":"WebSite"},{"@type":"OnlineStore",
 "logo":"https://cdn.x.com/files/store_logo.webp"}]}
</script>'''
check("schema logo inside @graph is found",
      find_logo(html, BASE)[0].endswith("store_logo.webp"))

html = '''<script type="application/ld+json">{"@type":"Org", "logo": </script>
<link rel="apple-touch-icon" href="/touch-icon-180.png">'''
check("invalid JSON-LD falls through instead of raising",
      find_logo(html, BASE)[1] == "head")

# --- og:image is never a logo -------------------------------------------
# On a store it is a product shot or a promo banner, not the brand mark.
for label, content in (
        ("product photo", "https://cdn.example.com/products/strawberry-ice-30ml.jpg"),
        ("promo banner", "https://cdn.example.com/banners/black-friday-2026.jpg")):
    html = f'<head><meta property="og:image" content="{content}"></head>'
    check(f"og:image {label} is NOT used as the logo", find_logo(html, BASE)[0] == "")

# --- Head sources -------------------------------------------------------
html = '<head><link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png"></head>'
url, src = find_logo(html, BASE)
check("apple-touch-icon is accepted and made absolute",
      url == "https://vapestore.example.com/apple-touch-icon.png" and src == "head", url)

html = '<head><meta itemprop="logo" content="https://cdn.x.com/logo.svg"></head>'
check("itemprop=logo is accepted", find_logo(html, BASE)[0] == "https://cdn.x.com/logo.svg")

# --- Markup -------------------------------------------------------------
html = '<header><img class="header__logo-image" src="//cdn.x.com/files/mylogo.png?v=2"></header>'
url, src = find_logo(html, BASE)
check("protocol-relative src resolves to https",
      url == "https://cdn.x.com/files/mylogo.png?v=2" and src == "markup", url)

html = '<header><img class="logo" data-src="/files/real-logo.png" src="/placeholder.gif"></header>'
check("lazy-loaded logo prefers the real file over the placeholder",
      find_logo(html, BASE)[0].endswith("/files/real-logo.png"))

html = ('<header><img alt="Store logo" src="/head-logo.png"></header>'
        '<footer><img alt="logo" src="/footer-logo-mono.png"></footer>')
check("header logo is preferred over the footer variant",
      find_logo(html, BASE)[0].endswith("/head-logo.png"))

# --- alt text matching the store name -----------------------------------
html = '<header><a href="/"><img alt="Juice Head" src="/files/jh.png"></a></header>'
check("an img whose alt is the store name is accepted",
      find_logo(html, BASE, "Juice Head")[1] == "alt")

html = '<body><img alt="Juice Head Mango 100ml" src="/products/mango.jpg"></body>'
check("a product whose alt merely contains the name is ignored",
      find_logo(html, BASE, "Juice Head")[0] == "")

# --- False positives ----------------------------------------------------
traps = [
    ("payment mark", '<footer><img class="logo" src="/icons/visa-logo.svg"></footer>'),
    ("review badge", '<footer><img class="logo" src="/img/trustpilot-logo.png"></footer>'),
    ("social icon", '<footer><img class="logo" src="/social/instagram-logo.svg"></footer>'),
    ("trust seal", '<footer><img class="logo" src="/norton-secured-logo.png"></footer>'),
    ("sprite sheet", '<header><img class="logo" src="/assets/sprite-logo.png"></header>'),
    ("tracking pixel", '<header><img class="logo" src="/1x1-logo.gif"></header>'),
]
for label, markup in traps:
    check(f"{label} is rejected", find_logo(markup, BASE)[0] == "")

html = '<header><img class="logo" width="16" height="16" src="/tiny-logo.png"></header>'
check("a 16px image is rejected as too small", find_logo(html, BASE)[0] == "")

html = '<header><img class="hero-banner" src="/banners/summer-hero.jpg"></header>'
check("an image nothing calls a logo is ignored", find_logo(html, BASE)[0] == "")

# --- White / light variants ---------------------------------------------
# A white logo is invisible on the merchant page's light card.
html = ('<header>'
        '<img class="logo logo--white" src="/files/logo-white.svg">'
        '<img class="logo logo--colour" src="/files/logo-full-color.svg">'
        '</header>')
check("a full-colour logo is preferred over the white variant",
      find_logo(html, BASE)[0].endswith("logo-full-color.svg"))

html = '<header><img class="logo" src="/files/logo-inverse.png"></header>'
check("a white logo is still used when it is the only one",
      find_logo(html, BASE)[0].endswith("logo-inverse.png"))

check("the white variant is detectable for flagging",
      bool(LOGO_LIGHT_VARIANT.search("/GiantLogo_white_510x.png"))
      and bool(LOGO_LIGHT_VARIANT.search("/logos/logo-white.svg"))
      and not LOGO_LIGHT_VARIANT.search("/files/brand-logo.png"))

# --- Nothing found ------------------------------------------------------
check("a page with no logo returns empty, not a guess",
      find_logo("<html><body><p>hi</p></body></html>", BASE) == ("", ""))
check("data: URIs are rejected", not _logo_candidate_ok("data:image/png;base64,iVBOR"))

print()
if fails:
    print(f"{len(fails)} check(s) FAILED")
    sys.exit(1)
print("All logo discovery checks passed.")
