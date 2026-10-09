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
    --only "VooPoo"     a single merchant by name
    --delay 2.0         seconds between merchants (default 1.5)
    --skip-policies     homepage only; much faster, finds links but no states
    --skip-logos        do not look for brand_logo_url
"""

import argparse
import csv
import gzip
import io
import json
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

VERSION = "1.3 (logo discovery)"

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

# Sentences that introduce places a merchant will NOT serve.
EXCLUSION_CUE = re.compile(
    r"(cannot|can't|do not|don't|unable to|not)\s+ship|"
    r"\bexcept\b|not available in|no longer ship|restrict|prohibit|ban",
    re.I,
)

ADULT_SIG = re.compile(
    r"adult signature|signature required|21\+?\s*signature|"
    r"someone (?:aged )?21|photo id (?:is )?required|id (?:will be )?checked",
    re.I,
)


def fetch(url, timeout=20):
    """GET a URL as a browser would. Returns text, or None."""
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


def find_policies(links, base_url):
    found = {}
    for field, (path_re, text_re) in POLICY_PATTERNS.items():
        for url, text in links:
            parsed = urllib.parse.urlparse(url)
            # Same-site links only.
            if parsed.netloc and parsed.netloc.replace("www.", "") not in base_url:
                continue
            if path_re.search(parsed.path) or text_re.search(text):
                found[field] = url
                break
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


def find_logo(html, base_url):
    """
    The store's logo, with the source that supplied it.

    Returns (absolute_url, source). Ordered by how explicit the claim is:
    structured data, then the head, then markup that names an image "logo".

    og:image is deliberately NOT consulted. On a store it is usually a product
    photo or a seasonal promo banner, and writing one of those into
    brand_logo_url would publish a picture that is not the logo -- and feed it
    to Google as Organization.logo.
    """
    for source, finder in (("schema", _logo_from_jsonld),
                           ("head", _logo_from_head),
                           ("markup", _logo_from_markup)):
        url = finder(html)
        if url:
            absolute = urllib.parse.urljoin(base_url, url)
            if absolute.startswith(("http://", "https://")):
                return absolute, source
    return "", ""


def find_restrictions(text):
    """States named in a sentence that reads like an exclusion."""
    plain = re.sub(r"<[^>]+>", " ", text)
    plain = re.sub(r"\s+", " ", plain)
    restricted = []
    for sentence in re.split(r"(?<=[.;!?])\s+", plain):
        if not EXCLUSION_CUE.search(sentence):
            continue
        if len(sentence) > 600:
            continue
        for state in US_STATES:
            if re.search(r"\b" + re.escape(state) + r"\b", sentence) and state not in restricted:
                restricted.append(state)
    return restricted


def process(row, args, log):
    """Fill blanks on one row. Returns a short status string."""
    name = (row.get("brand_name") or "").strip()
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

    if not args.skip_logos and not (row.get("brand_logo_url") or "").strip():
        logo, logo_source = find_logo(html, base)
        if logo:
            put("brand_logo_url", logo, f"logo({logo_source})")
            if LOGO_LIGHT_VARIANT.search(logo):
                # Worth a look: a white logo disappears on a light page, so
                # say so rather than leaving it to be noticed on the page.
                log.append(f"{name}: CHECK logo looks like a white/light "
                           f"variant - {logo}")

    if not args.skip_policies:
        # Restrictions are not always on the shipping policy. Stores commonly
        # put them on a dedicated page, or inside the returns or age policy.
        candidates = [
            (row.get("shipping_policy_url") or "").strip(),
            (row.get("age_policy_url") or "").strip(),
            (row.get("returns_policy_url") or "").strip(),
        ]
        for path in ("/pages/shipping-restrictions", "/pages/restricted-states",
                     "/pages/pact-act", "/pages/shipping-information"):
            candidates.append(urllib.parse.urljoin(base, path))

        seen = set()
        for url in candidates:
            if not url or url in seen:
                continue
            seen.add(url)

            policy = fetch(url)
            if not policy:
                continue

            if args.debug:
                plain = re.sub(r"<[^>]+>", " ", policy)
                plain = re.sub(r"\s+", " ", plain)
                named = [st for st in US_STATES if re.search(r"\b" + st + r"\b", plain)]
                cues = len(EXCLUSION_CUE.findall(plain))
                print(f"\n    [debug] {url}")
                print(f"    [debug] {len(plain)} chars, {cues} exclusion cue(s), "
                      f"states named: {named or 'none'}")
                for m in list(EXCLUSION_CUE.finditer(plain))[:3]:
                    lo, hi = max(0, m.start() - 90), min(len(plain), m.end() + 220)
                    print(f"    [debug] ...{plain[lo:hi]}...")

            restricted = find_restrictions(policy)
            if restricted and not (row.get("restricted_states") or "").strip():
                page = urllib.parse.urlparse(url).path or url
                put("restricted_states", "|".join(restricted),
                    f"restricted({len(restricted)} from {page})")
                # A named exclusion list implies nationwide coverage otherwise
                # -- phrase it the way derive_shipping reads.
                put("shipping_restrictions",
                    "Ships nationwide except " + ", ".join(restricted))

            if ADULT_SIG.search(policy):
                put("do_they_id_on_delivery", "Yes - adult signature required")

            put("fact_source_url", url)
            put("fact_last_verified", time.strftime("%Y-%m-%d"))

    log.append(f"{name}: {', '.join(filled) if filled else 'nothing new'}")

    return "ok" if filled else "no new data"


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("input")
    parser.add_argument("--out", required=True)
    parser.add_argument("--limit", type=int, default=0)
    parser.add_argument("--only", default="")
    parser.add_argument("--delay", type=float, default=1.5)
    parser.add_argument("--skip-policies", action="store_true")
    parser.add_argument("--skip-logos", action="store_true")
    parser.add_argument("--debug", action="store_true",
                        help="print what each policy page actually contained, "
                             "so a miss can be diagnosed rather than guessed at")
    args = parser.parse_args()

    print(f"fetch_merchant_data {VERSION}")
    print()

    with open(args.input, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        rows = list(reader)
        fieldnames = list(reader.fieldnames or [])

    for column in ("social_links", "useful_links", "shipping_policy_url",
                   "returns_policy_url", "age_policy_url", "do_they_id_on_delivery",
                   "restricted_states", "shipping_restrictions", "contact_email",
                   "contact_page_url", "fact_source_url", "fact_last_verified"):
        if column not in fieldnames:
            fieldnames.append(column)
        for row in rows:
            row.setdefault(column, "")

    targets = rows
    if args.only:
        targets = [r for r in rows if args.only.lower() in (r.get("brand_name") or "").lower()]
    if args.limit > 0:
        targets = targets[:args.limit]

    log = []
    stats = {"ok": 0, "no new data": 0, "homepage unreachable": 0, "no brand_url": 0}
    total = len(targets)

    for index, row in enumerate(targets, 1):
        name = (row.get("brand_name") or "?")[:34]
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
        if index < total:
            time.sleep(args.delay)

    with open(args.out, "w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

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
