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
"""

import argparse
import csv
import gzip
import io
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

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

    if not args.skip_policies:
        shipping_url = (row.get("shipping_policy_url") or "").strip()
        if shipping_url:
            policy = fetch(shipping_url)
            if policy:
                restricted = find_restrictions(policy)
                if restricted:
                    put("restricted_states", "|".join(restricted),
                        f"restricted({len(restricted)})")
                    # A named exclusion list implies nationwide coverage
                    # otherwise -- phrase it the way derive_shipping reads.
                    put("shipping_restrictions",
                        "Ships nationwide except " + ", ".join(restricted))
                if ADULT_SIG.search(policy):
                    put("do_they_id_on_delivery", "Yes - adult signature required")
                put("fact_source_url", shipping_url)
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
    args = parser.parse_args()

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
