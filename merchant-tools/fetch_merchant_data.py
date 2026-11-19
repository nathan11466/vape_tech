#!/usr/bin/env python3
"""
Fill the blank merchant columns automatically by reading each store's site.

Most of what is missing is published in predictable places: social profiles and
policy links live in site footers, and Shopify stores -- which most of these
are -- expose /policies/shipping-policy and friends at fixed URLs. This fetches
the homepage, mines the footer, then reads the shipping and returns policies.

Run it on your own machine rather than a server: age gates and bot filters are
far more forgiving of a normal browser from a normal address.

SAFETY: only ever fills BLANK cells. Anything you have already written is left
alone, so it is safe to re-run as your data improves.

    python fetch_merchant_data.py merchants-enriched.csv --out merchants-filled.csv

    --limit 10          try ten merchants first
    --start 40          skip the first forty; with --limit, one chunk
    --only "VooPoo"     a single merchant by name
    --delay 2.0         seconds between merchants (default 1.5)
    --skip-policies     homepage only; much faster, finds links but no states
    --skip-logos        do not look for brand_logo_url
    --resume            carry on where the last run stopped

The full policy pass reads up to eight pages per store, so a 110-store run
takes a while and may not survive the machine going to sleep. The output is
therefore rewritten after every merchant, and each one finished is recorded
in a sidecar file next to it. Re-run the same command with --resume and the
ones already done are skipped:

    python fetch_merchant_data.py merchants.csv --out filled.csv --resume

That is safe to repeat as often as you like. Point --resume runs at the same
--out each time, and the file is replaced whole, so an interrupted run leaves
the previous version intact rather than a half-written one.
"""

import argparse
import csv
import gzip
import hashlib
import io
import json
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

VERSION = "1.4 (resumable policy pass)"

BROWSER_UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36"
)

# Cookies commonly set by vape-store age gates. Sending them up front gets the
# real page instead of the interstitial on a good number of sites.
AGE_COOKIES = "; ".join([
    "age_verified=true", "ageVerified=true", "age_gate=1", "isAdult=true",
    "adult=1", "verified=yes", "av_verified=1", "age_ok=1",
])

SOCIAL_DOMAINS = {
    "instagram.com": "Instagram", "facebook.com": "Facebook", "x.com": "X",
    "twitter.com": "X", "youtube.com": "YouTube", "tiktok.com": "TikTok",
    "reddit.com": "Reddit", "pinterest.com": "Pinterest",
    "linkedin.com": "LinkedIn", "discord.gg": "Discord", "t.me": "Telegram",
    "threads.net": "Threads",
}

# Review sites, for trust_source_url. Only ever read from a link the store
# publishes itself -- see find_review_pages.
REVIEW_DOMAINS = {
    "trustpilot.com": "Trustpilot",
    "sitejabber.com": "Sitejabber",
    "resellerratings.com": "ResellerRatings",
    "bbb.org": "BBB",
    "reviews.io": "Reviews.io",
    "yotpo.com": "Yotpo",
}

# Policy pages, matched on URL path first and link text second.
POLICY_PATTERNS = {
    "shipping_policy_url": (
        re.compile(r"/(policies/)?(shipping|delivery)[-_a-z]*", re.I),
        re.compile(r"\bshipping\b|\bdelivery\b", re.I),
    ),
    "returns_policy_url": (
        re.compile(r"/(policies/)?(refund|return|exchange)[-_a-z]*", re.I),
        re.compile(r"\breturns?\b|\brefunds?\b", re.I),
    ),
    "age_policy_url": (
        re.compile(r"/(policies/)?(age|verification|compliance|pact)[-_a-z]*", re.I),
        re.compile(r"age verification|age policy|\bpact act\b", re.I),
    ),
    "contact_page_url": (
        re.compile(r"/(pages/)?contact[-_a-z]*", re.I),
        re.compile(r"\bcontact\b|\bsupport\b|\bhelp\b", re.I),
    ),
}

# Shopify's fixed policy URLs, tried when the footer yields nothing.
SHOPIFY_FALLBACKS = {
    "shipping_policy_url": ["/policies/shipping-policy", "/pages/shipping-policy"],
    "returns_policy_url": ["/policies/refund-policy", "/pages/return-policy"],
    "contact_page_url": ["/pages/contact", "/pages/contact-us"],
}

US_STATES = [
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

# Most stores write their exclusions as codes, not names: "We cannot ship to
# AR, HI, SD, UT, VT". Reading only full names missed nearly all of them.
STATE_ABBR = {
    "AL": "Alabama", "AK": "Alaska", "AZ": "Arizona", "AR": "Arkansas",
    "CA": "California", "CO": "Colorado", "CT": "Connecticut",
    "DE": "Delaware", "FL": "Florida", "GA": "Georgia", "HI": "Hawaii",
    "ID": "Idaho", "IL": "Illinois", "IN": "Indiana", "IA": "Iowa",
    "KS": "Kansas", "KY": "Kentucky", "LA": "Louisiana", "ME": "Maine",
    "MD": "Maryland", "MA": "Massachusetts", "MI": "Michigan",
    "MN": "Minnesota", "MS": "Mississippi", "MO": "Missouri",
    "MT": "Montana", "NE": "Nebraska", "NV": "Nevada",
    "NH": "New Hampshire", "NJ": "New Jersey", "NM": "New Mexico",
    "NY": "New York", "NC": "North Carolina", "ND": "North Dakota",
    "OH": "Ohio", "OK": "Oklahoma", "OR": "Oregon", "PA": "Pennsylvania",
    "RI": "Rhode Island", "SC": "South Carolina", "SD": "South Dakota",
    "TN": "Tennessee", "TX": "Texas", "UT": "Utah", "VT": "Vermont",
    "VA": "Virginia", "WA": "Washington", "WV": "West Virginia",
    "WI": "Wisconsin", "WY": "Wyoming",
}

# Sentences that introduce places a merchant will NOT serve.
#
# Two tiers, because the loose words cost more than they earn. "ban" with no
# boundaries matched Albany, banner and urban; it put Pennsylvania into one
# store's exclusions out of a nav link reading "Pennsylvania Vape Ban 2026".
# So ban/prohibit/restrict now need both word boundaries and a sentence that
# is actually about shipping or selling.
STRONG_CUE = re.compile(
    r"(cannot|can't|do not|don't|unable to|not)\s+ship|"
    r"\bexcept\b|not available in|no longer ship|"
    r"(do not|don't|cannot|can't|unable to)\s+(sell|deliver)",
    re.I,
)

WEAK_CUE = re.compile(
    r"\brestrict(ed|ions?|s)?\b|\bprohibit(ed|ion|s)?\b|\bban(ned|s)?\b",
    re.I,
)

WEAK_CUE_CONTEXT = re.compile(
    r"\bship(ping|ped|ment)?\b|\bdeliver(y|ed|ies)?\b|\bsell\b|\bsold\b|"
    r"\bsale\b|\bavailable\b|\border(s|ed)?\b",
    re.I,
)

# A page that yields more than this many states is not one exclusion list.
# It is a state-by-state legality table, or several per-product lists run
# together -- one store's page reads "All cannabinoid products: not available
# in Alabama, California, Idaho, New Jersey. THCa: not available in [38 more]"
# and merging those says the store ships nowhere, which is false and would
# pull it off every state page on the site.
MAX_PLAUSIBLE_EXCLUSIONS = 30

# Worth a human look, even though it is kept: at this size the list may be
# for one product line rather than the whole store.
CHECK_EXCLUSIONS_OVER = 14

# What the enrichment step writes into shipping_restrictions when nothing was
# known. Once a real exclusion list turns up, that sentence is not merely
# incomplete -- it contradicts restricted_states, and it is the sentence
# derive_shipping reads to decide which state pages a store belongs on.
STALE_NO_RESTRICTIONS = re.compile(
    r"could not confirm any state shipping restrictions", re.I)

ADULT_SIG = re.compile(
    r"adult signature|signature required|21\+?\s*signature|"
    r"someone (?:aged )?21|photo id (?:is )?required|id (?:will be )?checked",
    re.I,
)


# Set by --cache. Every page fetched is kept here, and read from here on the
# next run, so improving the parser costs one re-run of the parser rather
# than a second hour of requests to 110 shops. Delete the directory to go
# back to the live sites.
CACHE_DIR = ""


def _cache_path(url):
    digest = hashlib.sha256(url.encode("utf-8")).hexdigest()[:40]
    return os.path.join(CACHE_DIR, digest)


def fetch(url, timeout=20):
    """GET a URL as a browser would. Returns text, or None."""
    if CACHE_DIR:
        path = _cache_path(url)
        if os.path.exists(path):
            with open(path, encoding="utf-8", errors="replace") as fh:
                body = fh.read()
            # An empty cache entry records a page that did not load, which is
            # as much a result as a body and should not be retried.
            return body or None

    body = _fetch_live(url, timeout)

    if CACHE_DIR:
        with open(_cache_path(url), "w", encoding="utf-8") as fh:
            fh.write(body or "")
    return body


def _fetch_live(url, timeout=20):
    request = urllib.request.Request(url, headers={
        "User-Agent": BROWSER_UA,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        "Accept-Language": "en-US,en;q=0.9",
        "Accept-Encoding": "gzip",
        "Cookie": AGE_COOKIES,
    })
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read()
            if response.headers.get("Content-Encoding") == "gzip":
                try:
                    raw = gzip.decompress(raw)
                except OSError:
                    pass
            charset = response.headers.get_content_charset() or "utf-8"
            return raw.decode(charset, errors="replace")
    except Exception:
        return None


def links_from(html, base_url):
    """Every (absolute url, link text) pair in the page."""
    out = []
    for match in re.finditer(
            r'<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>', html, re.I | re.S):
        href, text = match.group(1), re.sub(r"<[^>]+>", " ", match.group(2))
        text = re.sub(r"\s+", " ", text).strip()
        if href.startswith(("mailto:", "tel:", "javascript:", "#")):
            continue
        out.append((urllib.parse.urljoin(base_url, href), text))
    return out


def find_socials(links):
    found = {}
    for url, _ in links:
        host = (urllib.parse.urlparse(url).netloc or "").lower().replace("www.", "")
        for domain, label in SOCIAL_DOMAINS.items():
            if host == domain or host.endswith("." + domain):
                # Skip share/intent links -- they are not the store's profile.
                if re.search(r"/(share|intent|sharer)", url, re.I):
                    continue
                found.setdefault(label, url)
    return found


# Never a policy page, however the link happens to be labelled. A nav item
# reading "Free shipping over $50" pointing at a product collection used to
# win the shipping_policy_url slot, and that URL is published on the page as
# the source for the shipping facts.
NOT_A_POLICY = re.compile(
    r"/(collections|products|blogs|blog|cart|account|search|checkout)(/|$)",
    re.I)


def find_policies(links, base_url):
    found = {}
    for field, (path_re, text_re) in POLICY_PATTERNS.items():
        by_path, by_text = "", ""
        for url, text in links:
            parsed = urllib.parse.urlparse(url)
            # Same-site links only.
            if parsed.netloc and parsed.netloc.replace("www.", "") not in base_url:
                continue
            if NOT_A_POLICY.search(parsed.path):
                continue
            if path_re.search(parsed.path):
                by_path = url
                break
            if text_re.search(text) and not by_text:
                by_text = url
        # A URL that looks like a policy beats a link that merely reads like
        # one. Link text is written for shoppers; paths are written by the
        # platform.
        chosen = by_path or by_text
        if chosen:
            found[field] = chosen
    return found


# Infrastructure hosts whose addresses are error-tracking or platform
# plumbing, never a merchant's support address.
INFRA_DOMAINS = re.compile(
    r"(sentry|myshopline|shopify|wixpress|wix\.com|cloudflare|googleapis|"
    r"google\.com|gstatic|cdn|amazonaws|akamai|jsdelivr|bugsnag|datadog|"
    r"newrelic|segment|intercom|hotjar|klaviyo|mailchimp|sendgrid|example)",
    re.I)


def find_email(html, site_host=""):
    """
    A plausible support address.

    Prefers one on the merchant's own domain. Rejects platform and
    error-tracking addresses -- a Sentry DSN looks exactly like an email and
    would otherwise be published as the store's contact.
    """
    site_host = (site_host or "").lower().replace("www.", "")
    own_domain = []
    generic = []

    for match in re.finditer(r"[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}", html):
        email = match.group(0)
        local, _, domain = email.partition("@")
        domain = domain.lower()

        if re.search(r"\.(png|jpg|jpeg|gif|webp|svg|css|js)$", email, re.I):
            continue
        if INFRA_DOMAINS.search(domain):
            continue
        if re.match(r"no-?reply", local, re.I):
            continue
        # A 24+ character hex local part is a tracking key, not a mailbox.
        if re.fullmatch(r"[0-9a-f]{24,}", local, re.I):
            continue

        if site_host and (domain == site_host or domain.endswith("." + site_host)
                          or site_host.endswith("." + domain)):
            own_domain.append(email)
        else:
            generic.append(email)

    if own_domain:
        return own_domain[0]

    return generic[0] if generic else ""


# Images that sit in headers and footers but are never the brand's logo,
# however they are declared. Payment marks, review-platform badges, trust
# seals and social icons are the usual false positives.
LOGO_REJECT_ALWAYS = re.compile(
    r"(sprite|placeholder|spinner|loader|blank|pixel|1x1|transparent|lazy|"
    r"visa|mastercard|maestro|amex|american-?express|discover|paypal|klarna|"
    r"afterpay|affirm|sezzle|applepay|apple-?pay|googlepay|google-?pay|"
    r"shop-?pay|venmo|diners|jcb|unionpay|bitcoin|crypto|"
    r"trustpilot|yotpo|judge\.?me|stamped|reviews?\.io|okendo|loox|"
    r"norton|mcafee|geotrust|verisign|bbb-|"
    r"facebook|instagram|twitter|x-logo|tiktok|youtube|pinterest|snapchat|"
    r"linkedin|reddit|whatsapp|telegram|discord)",
    re.I)

# Generic interface-icon naming. Only applied when the logo is being INFERRED
# from markup -- an apple-touch-icon is literally named "...-icon" and is a
# legitimate brand mark, so this must not be used against a source that
# declares itself.
LOGO_REJECT_INFERRED = re.compile(
    r"(flag|/icons?/|icon-|-icon|badge|star|rating|avatar|arrow|chevron|"
    r"cart|search|menu|hamburger|close|burger)",
    re.I)

IMAGE_EXT = re.compile(r"\.(png|jpe?g|svg|webp|avif)(\?|#|$)", re.I)

# Stores with a dark header ship a white logo for it. On a merchant page, which
# has a light background, that renders invisible -- so a full-colour variant is
# preferred whenever the markup offers both.
LOGO_LIGHT_VARIANT = re.compile(
    r"(white|inverse|inverted|-light|_light|mono|negative|reverse)", re.I)


def _logo_candidate_ok(url, inferred=True):
    """
    A URL that could plausibly be a logo image.

    `inferred` is False when the page declared this image as its logo
    (structured data, itemprop, apple-touch-icon). Declared sources skip the
    interface-icon filter, which would otherwise reject apple-touch-icon.png
    on the "-icon" in its own conventional filename.
    """
    if not url or url.startswith("data:"):
        return False
    if LOGO_REJECT_ALWAYS.search(url):
        return False
    if inferred and LOGO_REJECT_INFERRED.search(url):
        return False
    # Shopify and friends serve logos through resizing params, so an extension
    # is not always at the end -- accept a CDN path that mentions the logo.
    return bool(IMAGE_EXT.search(url)) or "logo" in url.lower()


def _logo_from_jsonld(html):
    """
    The logo the store declares in its own structured data.

    This is the only source that says "this image is our logo" outright, so it
    is trusted ahead of anything inferred from markup.
    """
    for match in re.finditer(
            r'<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>',
            html, re.I | re.S):
        try:
            data = json.loads(match.group(1).strip())
        except (ValueError, TypeError):
            continue

        stack = [data]
        while stack:
            node = stack.pop()
            if isinstance(node, list):
                stack.extend(node)
                continue
            if not isinstance(node, dict):
                continue
            stack.extend(v for v in node.values() if isinstance(v, (dict, list)))

            logo = node.get("logo")
            if isinstance(logo, dict):
                logo = logo.get("url") or logo.get("contentUrl")
            if isinstance(logo, str) and _logo_candidate_ok(logo.strip(), inferred=False):
                return logo.strip()
    return ""


def _logo_from_head(html):
    """A logo declared in the document head."""
    patterns = [
        # itemprop="logo" is an explicit declaration, like the JSON-LD one.
        r'<[^>]+itemprop=["\']logo["\'][^>]+(?:content|src|href)=["\']([^"\']+)',
        r'<meta[^>]+property=["\']og:logo["\'][^>]+content=["\']([^"\']+)',
        # An apple-touch-icon is a square brand mark by convention -- a usable
        # logo, unlike a favicon, which is too small to publish.
        r'<link[^>]+rel=["\'][^"\']*apple-touch-icon[^"\']*["\'][^>]+href=["\']([^"\']+)',
    ]
    for pattern in patterns:
        found = re.search(pattern, html, re.I)
        if found:
            url = found.group(1).strip()
            if _logo_candidate_ok(url, inferred=False):
                return url
    return ""


def _logo_from_markup(html):
    """
    An <img> the markup itself calls a logo.

    Restricted to images whose class, id, alt or filename says "logo", so a
    hero banner or product shot is never picked up.
    """
    # The header holds the real logo; a footer copy is often a mono variant.
    head = re.split(r"</header>", html, maxsplit=1, flags=re.I)[0]

    for region in (head, html):
        candidates = []
        for tag in re.finditer(r"<img\b[^>]*>", region, re.I):
            attrs = tag.group(0)
            if not re.search(r"logo", attrs, re.I):
                continue

            # Reject anything declared smaller than a favicon.
            size = re.search(r'\b(?:width|height)=["\']?(\d+)', attrs, re.I)
            if size and int(size.group(1)) < 32:
                continue

            # Lazy-loaded images keep the real file in a data-* attribute.
            for attr in ("data-src", "data-original", "data-lazy-src", "src", "srcset"):
                found = re.search(attr + r'=["\']([^"\']+)', attrs, re.I)
                if not found:
                    continue
                url = found.group(1).split()[0].strip().rstrip(",")
                if _logo_candidate_ok(url):
                    candidates.append(url)
                    break

        if not candidates:
            continue
        for url in candidates:
            if not LOGO_LIGHT_VARIANT.search(url):
                return url
        # Only light variants on offer. Better than nothing, and process()
        # flags it for review.
        return candidates[0]

    return ""


def _logo_from_alt(html, brand_name):
    """
    An <img> whose alt or title is the store's own name.

    Most themes label the header logo with the store name rather than the word
    "logo". The comparison is deliberately near-exact: a product shot with
    alt="Juice Head Mango 100ml" contains the brand name too, and matching on
    containment would pick up the first product on the page.
    """
    wanted = re.sub(r"[^a-z0-9]+", "", (brand_name or "").lower())
    if len(wanted) < 3:
        return ""

    head = re.split(r"</header>", html, maxsplit=1, flags=re.I)[0]

    for region in (head, html):
        for tag in re.finditer(r"<img\b[^>]*>", region, re.I):
            attrs = tag.group(0)

            label = ""
            for attr in ("alt", "title"):
                found = re.search(attr + r'=["\']([^"\']*)', attrs, re.I)
                if found:
                    label = found.group(1)
                    break
            if not label:
                continue

            # Allow a trailing "logo" and the usual separators, nothing more.
            normalized = re.sub(r"[^a-z0-9]+", "",
                                re.sub(r"\blogos?\b", "", label.lower()))
            if normalized != wanted:
                continue

            size = re.search(r'\b(?:width|height)=["\']?(\d+)', attrs, re.I)
            if size and int(size.group(1)) < 32:
                continue

            for attr in ("data-src", "data-original", "data-lazy-src", "src"):
                found = re.search(attr + r'=["\']([^"\']+)', attrs, re.I)
                if not found:
                    continue
                url = found.group(1).split()[0].strip().rstrip(",")
                if _logo_candidate_ok(url):
                    return url
    return ""


def find_logo(html, base_url, brand_name=""):
    """
    The store's logo, with the source that supplied it.

    Returns (absolute_url, source). Ordered by how explicit the claim is:
    structured data, then the head, then markup that names an image "logo".

    og:image is deliberately NOT consulted. On a store it is usually a product
    photo or a seasonal promo banner, and writing one of those into
    brand_logo_url would publish a picture that is not the logo -- and feed it
    to Google as Organization.logo.
    """
    finders = (
        ("schema", lambda h: _logo_from_jsonld(h)),
        ("head", lambda h: _logo_from_head(h)),
        ("markup", lambda h: _logo_from_markup(h)),
        ("alt", lambda h: _logo_from_alt(h, brand_name)),
    )
    for source, finder in finders:
        url = finder(html)
        if url:
            absolute = urllib.parse.urljoin(base_url, url)
            if absolute.startswith(("http://", "https://")):
                return absolute, source
    return "", ""


def abbrevs_in(sentence):
    """
    State codes in a sentence -- but only from a list of three or more.

    Taken alone, half the codes are ordinary English words: OR, IN, ME, OK,
    HI, DE, LA, MS, MD, PA, AL, CO, ID, MI, MO, NE, OH, SC. And "CA" in
    "we do not ship to the US, UK or CA" is Canada, not California. Writing
    a state into restricted_states takes a store off that state's page, so a
    false positive costs real traffic and states something untrue about a
    business.

    Three or more codes together is a list, and a list is unambiguous. One
    or two are not worth the risk, so they are dropped -- a genuine
    single-state exclusion is almost always spelled out in full anyway, and
    the full-name scan catches that.
    """
    # A lone code in brackets qualifies a city or county -- "Denver (CO)" --
    # rather than naming an excluded state. A bracketed list of several, as
    # in "(AR, HI, SD)", is a real list and is left alone.
    sentence = re.sub(r"\(\s*[A-Z]{2}\s*\)", " ", sentence)

    found = []
    for code in re.findall(r"\b([A-Z]{2})\b", sentence):
        state = STATE_ABBR.get(code)
        if state and state not in found:
            found.append(state)
    return found if len(found) >= 3 else []


def visible_text(html):
    """
    Page text with the code stripped out first.

    Stripping tags alone leaves every line of inline JavaScript behind as
    prose, and a theme's scripts are full of words like "return", "ban" and
    state names in option lists. Scripts and styles have to go before the
    tags do.
    """
    plain = re.sub(r"(?is)<(script|style|noscript)\b.*?</\1\s*>", " ", html)
    # Each link's text is its own unit. A nav bar carries no punctuation, so
    # forty unrelated link labels flatten into one enormous "sentence" --
    # which is how the blog headline "Pennsylvania Vape Ban 2026" came to
    # sit in the same sentence as the words "Free Shipping" and put
    # Pennsylvania into a store's exclusions. A pipe is a sentence break.
    plain = re.sub(r"(?i)</a\s*>", " | ", plain)
    plain = re.sub(r"<[^>]+>", " ", plain)
    return re.sub(r"\s+", " ", plain)


# "Sarasota County (FL)", "Union County, Florida" -- a restriction on one
# county is not a restriction on the state. Reading those as states took one
# store off the whole of Florida, North Carolina, Colorado, California,
# Illinois and Mississippi over six counties and towns.
SUBSTATE_QUALIFIER = re.compile(r"\b(county|parish|city of|cities of)\b"
                                r"[\s,of]*$", re.I)


def is_exclusion(sentence):
    """Does this sentence introduce places the store will not serve?"""
    if STRONG_CUE.search(sentence):
        return True
    # The loose words only count where the sentence is about shipping.
    return bool(WEAK_CUE.search(sentence)
                and WEAK_CUE_CONTEXT.search(sentence))


def find_restrictions(text):
    """States named in a sentence that reads like an exclusion."""
    plain = visible_text(text)
    restricted = []

    def add(state):
        if state not in restricted:
            restricted.append(state)

    for sentence in re.split(r"(?<=[.;!?|])\s+", plain):
        if not is_exclusion(sentence):
            continue
        # An exclusion list runs long -- a bulleted list of twenty states
        # flattens into one sentence of well over a thousand characters, and
        # the old 600-character cap threw exactly those away. The cap is here
        # only to stop a whole untagged page counting as one sentence.
        if len(sentence) > 2500:
            continue
        for state in US_STATES:
            for match in re.finditer(r"\b" + re.escape(state) + r"\b", sentence):
                if SUBSTATE_QUALIFIER.search(sentence[:match.start()][-30:]):
                    continue
                add(state)
                break
        for state in abbrevs_in(sentence):
            add(state)

    if len(restricted) > MAX_PLAUSIBLE_EXCLUSIONS:
        return []
    return restricted


def find_review_pages(links):
    """
    Review-site profiles the store links to itself.

    Only a page the merchant publishes counts. A Trustpilot URL guessed from
    the domain cannot be verified from here -- Trustpilot returns 403 to
    anything that is not a browser -- and an unverified profile link is a
    claim nobody checked. A link in the store's own footer is the store
    saying the profile is theirs.
    """
    found = {}
    for url, _ in links:
        host = (urllib.parse.urlparse(url).netloc or "").lower().replace("www.", "")
        for domain, label in REVIEW_DOMAINS.items():
            if host == domain or host.endswith("." + domain):
                found.setdefault(label, url)
    return found


def process(row, args, log):
    """Fill blanks on one row. Returns a short status string."""
    name = (row.get("brand_name") or row.get("display_brand_name") or "").strip()
    base = (row.get("brand_url") or "").strip()
    if not base:
        return "no brand_url"

    if not base.startswith(("http://", "https://")):
        base = "https://" + base

    html = fetch(base)
    if not html:
        return "homepage unreachable"

    filled = []

    def put(field, value, note=None):
        """Only ever fill a blank."""
        if not value:
            return
        if (row.get(field) or "").strip():
            return
        row[field] = value
        filled.append(note or field)

    links = links_from(html, base)

    socials = find_socials(links)
    if socials:
        put("social_links", "|".join(socials.values()), f"social({len(socials)})")

    policies = find_policies(links, base)
    for field, url in policies.items():
        put(field, url)

    # Shopify's fixed paths, for anything the footer missed.
    for field, paths in SHOPIFY_FALLBACKS.items():
        if (row.get(field) or "").strip():
            continue
        for path in paths:
            candidate = urllib.parse.urljoin(base, path)
            if fetch(candidate) is not None:
                put(field, candidate)
                break

    put("contact_email", find_email(html, urllib.parse.urlparse(base).netloc))

    reviews = find_review_pages(links)
    if reviews:
        # Whichever the store links to. Trustpilot first if they have one,
        # since that is the one the Company Information section expects.
        for label in ("Trustpilot", "BBB", "Sitejabber", "ResellerRatings",
                      "Reviews.io", "Yotpo"):
            if label in reviews:
                put("trust_source_url", reviews[label], f"trust({label})")
                put("trust_verified_at", time.strftime("%Y-%m-%d"))
                break

    if not args.skip_logos and not (row.get("brand_logo_url") or "").strip():
        logo, logo_source = find_logo(html, base, name)
        if logo:
            put("brand_logo_url", logo, f"logo({logo_source})")
            if LOGO_LIGHT_VARIANT.search(logo):
                # Worth a look: a white logo disappears on a light page, so
                # say so rather than leaving it to be noticed on the page.
                log.append(f"{name}: CHECK logo looks like a white/light "
                           f"variant - {logo}")
        else:
            # Name the page that was actually read. A miss is usually a
            # brand_url pointing somewhere other than the storefront, and
            # without the URL there is no way to tell that from a site that
            # genuinely does not label its logo.
            log.append(f"{name}: no logo found on {base}")

    if not args.skip_policies:
        # Restrictions are not always on the shipping policy. Stores commonly
        # put them on a dedicated page, or inside the returns or age policy.
        candidates = [
            (row.get("shipping_policy_url") or "").strip(),
            (row.get("age_policy_url") or "").strip(),
            (row.get("returns_policy_url") or "").strip(),
        ]
        for path in ("/pages/shipping-restrictions", "/pages/restricted-states",
                     "/pages/pact-act", "/pages/shipping-information",
                     "/pages/pact-act-compliance", "/pages/shipping",
                     "/pages/shipping-and-returns", "/pages/faq",
                     "/pages/faqs", "/pages/shipping-faq",
                     "/policies/shipping-policy"):
            candidates.append(urllib.parse.urljoin(base, path))

        seen = set()
        first_loaded = ""
        restriction_page = ""
        for url in candidates:
            if not url or url in seen:
                continue
            seen.add(url)

            policy = fetch(url)
            if not policy:
                continue
            if not first_loaded:
                first_loaded = url

            if args.debug:
                plain = visible_text(policy)
                named = [st for st in US_STATES if re.search(r"\b" + st + r"\b", plain)]
                cues = len(STRONG_CUE.findall(plain))
                print(f"\n    [debug] {url}")
                print(f"    [debug] {len(plain)} chars, {cues} strong cue(s), "
                      f"states named: {named or 'none'}")
                for m in list(STRONG_CUE.finditer(plain))[:3]:
                    lo, hi = max(0, m.start() - 90), min(len(plain), m.end() + 220)
                    print(f"    [debug] ...{plain[lo:hi]}...")

            restricted = find_restrictions(policy)
            if len(restricted) > CHECK_EXCLUSIONS_OVER:
                log.append(f"{name}: CHECK {len(restricted)} states excluded "
                           f"on {url} - long lists are often per-product "
                           f"rather than store-wide")
            if restricted and not (row.get("restricted_states") or "").strip():
                page = urllib.parse.urlparse(url).path or url
                restriction_page = url
                put("restricted_states", "|".join(restricted),
                    f"restricted({len(restricted)} from {page})")
                # A named exclusion list implies nationwide coverage otherwise
                # -- phrase it the way derive_shipping reads. This one is
                # allowed to replace the "could not confirm" placeholder,
                # which is now demonstrably wrong; anything else in the cell
                # was written by hand and is left alone.
                summary = "Ships nationwide except " + ", ".join(restricted)
                current = (row.get("shipping_restrictions") or "").strip()
                if not current:
                    put("shipping_restrictions", summary)
                elif STALE_NO_RESTRICTIONS.search(current):
                    row["shipping_restrictions"] = summary
                    filled.append("shipping_restrictions(replaced)")

            if ADULT_SIG.search(policy):
                put("do_they_id_on_delivery", "Yes - adult signature required")

        # The page the exclusion list was actually read from, if there was
        # one. fact_source_url is published on the merchant page as the
        # source for these facts, so it has to point at the page that states
        # them -- not at whichever policy page happened to load first.
        source = restriction_page or first_loaded
        if source:
            put("fact_source_url", source)
            # No date without a page to go with it: the page template prints
            # "last verified" as a link, and a date on its own is a claim
            # with nothing behind it.
            put("fact_last_verified", time.strftime("%Y-%m-%d"))

    log.append(f"{name}: {', '.join(filled) if filled else 'nothing new'}")

    return "ok" if filled else "no new data"


def row_key(row):
    """What identifies a merchant in the progress file."""
    for field in ("externalMerchantKey", "brand_url", "display_brand_name",
                  "brand_name"):
        value = (row.get(field) or "").strip()
        if value:
            return value
    return ""


def write_csv(path, fieldnames, rows):
    """
    Replace the output file whole.

    Written to a temporary name and moved into place, so a run killed
    mid-write leaves the previous version readable rather than a file
    truncated halfway through a row. That also makes it safe to pass the
    same file as both input and output on a --resume run, since the input
    has already been read into memory by then.
    """
    temp = path + ".tmp"
    with open(temp, "w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)
    os.replace(temp, path)


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("input")
    parser.add_argument("--out", required=True)
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--start", type=int, default=0,
                        help="skip this many merchants before starting; with "
                             "--limit, runs one chunk of the file")
    parser.add_argument("--cache", default="",
                        help="keep every page fetched in this directory, and "
                             "read from it next time. Delete the directory "
                             "to fetch the live sites again.")
    parser.add_argument("--resume", action="store_true",
                        help="skip merchants already recorded as done in "
                             "<out>.done, and keep appending to it")
    parser.add_argument("--only", default="")
    parser.add_argument("--delay", type=float, default=1.5)
    parser.add_argument("--skip-policies", action="store_true")
    parser.add_argument("--skip-logos", action="store_true")
    parser.add_argument("--debug", action="store_true",
                        help="print what each policy page actually contained, "
                             "so a miss can be diagnosed rather than guessed at")
    args = parser.parse_args()

    print(f"fetch_merchant_data {VERSION}")
    if args.cache:
        global CACHE_DIR
        CACHE_DIR = args.cache
        os.makedirs(CACHE_DIR, exist_ok=True)
        print(f"page cache: {CACHE_DIR} "
              f"({len(os.listdir(CACHE_DIR))} page(s) held)")
    print()

    with open(args.input, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        rows = list(reader)
        fieldnames = list(reader.fieldnames or [])

    for column in ("social_links", "useful_links", "shipping_policy_url",
                   "returns_policy_url", "age_policy_url", "do_they_id_on_delivery",
                   "restricted_states", "shipping_restrictions", "contact_email",
                   "contact_page_url", "fact_source_url", "fact_last_verified",
                   "trust_source_url", "trust_verified_at"):
        if column not in fieldnames:
            fieldnames.append(column)
        for row in rows:
            row.setdefault(column, "")

    targets = rows
    if args.only:
        targets = [r for r in rows if args.only.lower() in
                   ((r.get("brand_name") or "") + " " + (r.get("display_brand_name") or "")).lower()]
    if args.start > 0:
        targets = targets[args.start:]
    if args.limit > 0:
        targets = targets[:args.limit]

    done_path = args.out + ".done"
    already = set()
    if args.resume and os.path.exists(done_path):
        with open(done_path, encoding="utf-8") as fh:
            already = {line.strip() for line in fh if line.strip()}
        print(f"resuming - {len(already)} merchant(s) already recorded in "
              f"{done_path}")
        print()
    elif not args.resume and os.path.exists(done_path):
        # Without this the next --resume run would skip everything this run
        # was about to redo.
        os.remove(done_path)

    log = []
    stats = {"ok": 0, "no new data": 0, "homepage unreachable": 0, "no brand_url": 0}
    total = len(targets)

    for index, row in enumerate(targets, 1):
        name = (row.get("brand_name") or row.get("display_brand_name") or "?")[:34]
        key = row_key(row)
        if key and key in already:
            print(f"[{index}/{total}] {name:<34} already done")
            continue
        print(f"[{index}/{total}] {name:<34} ", end="", flush=True)
        try:
            status = process(row, args, log)
        except KeyboardInterrupt:
            print("\ninterrupted - writing what we have")
            break
        except Exception as exc:
            status = f"error: {exc.__class__.__name__}"
        stats[status] = stats.get(status, 0) + 1
        print(status)

        # Write after every merchant, not at the end. A full policy pass runs
        # for the better part of an hour, and a run that dies at merchant 45
        # used to lose all 45.
        write_csv(args.out, fieldnames, rows)
        if key:
            with open(done_path, "a", encoding="utf-8") as fh:
                fh.write(key + "\n")

        if index < total:
            time.sleep(args.delay)

    write_csv(args.out, fieldnames, rows)

    print()
    print(f"Wrote {args.out}")
    for key, count in sorted(stats.items(), key=lambda kv: -kv[1]):
        if count:
            print(f"  {key:<22} {count}")
    print()
    print("Detail:")
    for line in log:
        print("  " + line)

    return 0


if __name__ == "__main__":
    sys.exit(main())
