#!/usr/bin/env python3
"""
Find and fetch a store's own policy pages.

Separated from extraction on purpose: this half touches the network and
cannot be unit-tested honestly, so it is kept small and dull, and everything
that makes a judgement lives in policy_extract.py against fixtures.

Politeness is not optional here. This visits 112 third-party shops that owe
us nothing: it honours robots.txt, identifies itself, waits between requests
to the same host, and caches every response so a re-run costs nobody a
second fetch. Discovery follows the store's own footer links rather than
guessing at URLs, which is both kinder and more accurate -- /returns,
/return-policy, /refund-policy and /pages/returns are all in use.

The HTML that comes back is untrusted input. It is cached to disk and
pattern matched; it is never executed, evaluated, or used to build a path.
"""

import argparse
import hashlib
import os
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import urllib.robotparser

UA = ("Mozilla/5.0 (compatible; VapingCheapFactBot/1.0; "
      "+https://vapingcheap.com/about; contact via site)")

# What we are looking for, and the link text or href that signals it. Ordered:
# the first match wins, so "return-policy" is not claimed by the terms rule.
PAGE_KINDS = (
    # \brma\b, not bare rma: unbounded it matches inside "info-rma-tion",
    # which classified every "Shipping Information" link as a returns page.
    ("returns", r"return|refund|\brma\b|exchange"),
    ("shipping", r"shipping|delivery|postage|fulfil"),
    ("age", r"age[- ]?verif|age[- ]?restrict|21\+|adult"),
    ("faq", r"\bfaq\b|frequently asked|help cent|support cent"),
    ("terms", r"terms|conditions|\btos\b"),
)

MAX_PAGES_PER_KIND = 1


def cache_path(cache_dir, url):
    """A cache filename derived from a hash, never from the URL's own text."""
    digest = hashlib.sha256(url.encode("utf-8")).hexdigest()[:40]
    return os.path.join(cache_dir, digest + ".html")


def fetch(url, cache_dir, delay=2.0, timeout=25):
    """
    The page body, from cache when present. Returns (body, from_cache).

    Returns ('', False) on any failure: a store that blocks us, times out or
    has moved is a gap to report, not an exception to crash the run.
    """
    if cache_dir:
        path = cache_path(cache_dir, url)
        if os.path.exists(path):
            with open(path, encoding="utf-8", errors="replace") as fh:
                return fh.read(), True

    request = urllib.request.Request(url, headers={
        "User-Agent": UA,
        "Accept": "text/html,application/xhtml+xml",
        "Accept-Language": "en-US,en;q=0.9",
    })
    body = ""
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            ctype = response.headers.get("Content-Type", "")
            if "html" in ctype or not ctype:
                # Cap the read: a store serving a 50MB page should not stall
                # the whole run or fill the disk.
                body = response.read(3_000_000).decode("utf-8", errors="replace")
    except (urllib.error.URLError, urllib.error.HTTPError, OSError,
            UnicodeError, ValueError):
        body = ""

    if cache_dir and body:
        with open(cache_path(cache_dir, url), "w", encoding="utf-8") as fh:
            fh.write(body)
    time.sleep(delay)
    return body, False


def robots_for(base, cache_dir, delay):
    """A robots.txt reader for this host, permissive if it cannot be read."""
    parser = urllib.robotparser.RobotFileParser()
    robots_url = urllib.parse.urljoin(base, "/robots.txt")
    body, _ = fetch(robots_url, cache_dir, delay=delay)
    if body:
        parser.parse(body.splitlines())
    else:
        # No robots.txt is permission by convention, not a reason to stop.
        parser.parse([])
    return parser


def discover(home_html, base):
    """
    {kind: url} for the policy pages the store links to itself.

    Reads the store's own links rather than guessing paths, so it follows
    whatever the shop actually calls them.
    """
    by_path = {}
    by_text = {}

    for match in re.finditer(r"(?is)<a\b([^>]*)>(.*?)</a>", home_html):
        attrs, label = match.group(1), match.group(2)
        href = re.search(r"""(?i)href\s*=\s*["']([^"']+)["']""", attrs)
        if not href:
            continue
        target = href.group(1).strip()
        if target.startswith(("#", "mailto:", "tel:", "javascript:")):
            continue

        text = re.sub(r"<[^>]+>", " ", label)
        text = re.sub(r"\s+", " ", text).strip().lower()
        absolute = urllib.parse.urljoin(base, target)

        parts = urllib.parse.urlparse(absolute)
        # Same site only. An affiliate link to a carrier's own returns page
        # is not this store stating its policy.
        if parts.netloc != urllib.parse.urlparse(base).netloc:
            continue

        path = parts.path.lower()

        for kind, pattern in PAGE_KINDS:
            if re.search(pattern, path, re.I):
                by_path.setdefault(kind, absolute)
                break
            # Link text is a weaker signal, kept separately. A storefront
            # banner reading "Free shipping over $50" pointing at a product
            # category otherwise claims the shipping slot and the real
            # shipping policy is never fetched -- which is how a promo
            # becomes the cited source for a policy.
            if re.search(pattern, text, re.I):
                by_text.setdefault(kind, absolute)
                break

    found = dict(by_text)
    found.update(by_path)

    return found


def pages_for_store(base, cache_dir, delay=2.0, respect_robots=True):
    """
    Every policy page we could reach for one store, as [(kind, url, html)].

    The homepage is included as a last-resort source: some small shops state
    their shipping threshold in a banner and nowhere else.
    """
    out = []
    robots = robots_for(base, cache_dir, delay) if respect_robots else None

    def allowed(url):
        return True if robots is None else robots.can_fetch(UA, url)

    if not allowed(base):
        return out, "robots.txt disallows the homepage"

    home, _ = fetch(base, cache_dir, delay=delay)
    if not home:
        return out, "homepage unreachable"

    out.append(("home", base, home))

    for kind, url in discover(home, base).items():
        if not allowed(url):
            continue
        body, _ = fetch(url, cache_dir, delay=delay)
        if body:
            out.append((kind, url, body))

    return out, ""


def main():
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("url", help="store homepage, e.g. https://example.com/")
    ap.add_argument("--cache", default="policy-cache",
                    help="directory for fetched pages (default: policy-cache)")
    ap.add_argument("--delay", type=float, default=2.0,
                    help="seconds between requests to a host (default: 2)")
    ap.add_argument("--ignore-robots", action="store_true",
                    help="fetch even where robots.txt disallows it")
    args = ap.parse_args()

    os.makedirs(args.cache, exist_ok=True)
    pages, problem = pages_for_store(args.url, args.cache, args.delay,
                                     respect_robots=not args.ignore_robots)
    if problem:
        print("problem: %s" % problem, file=sys.stderr)
    for kind, url, body in pages:
        print("%-9s %7d bytes  %s" % (kind, len(body), url))


if __name__ == "__main__":
    main()
