#!/usr/bin/env python3
"""
Collect each state's e-cigarette law summary from the Public Health Law
Center's 50-State Review.

Why this source and not a vape blog. The per-state pages at
publichealthlawcenter.org cite the statute behind every statement, they
cover all 50 states plus DC in one consistent format, and they are
maintained by a public-health law centre rather than a retailer with an
interest in the answer. Searching for "vape laws by state" returns a dozen
retailer blogs that disagree with each other on which states restrict
flavours -- this does not.

What it keeps, and why only these sections:

  tax         what the state charges on e-cigarettes, which is the part a
              shopper feels in the price
  retail      age, flavour restrictions, and internet or delivery sale
              rules -- the section that decides whether a shop can post to
              an address at all
  licence     whether selling into the state needs a permit, which is why
              some retailers refuse whole states
  packaging   child-resistant and warning requirements

The definition sections are left out: useful to a lawyer, no use to someone
deciding where to buy. Smoke-free rules are about where you may vape, not
where you may order from.

    python3 fetch_state_law.py --out state-law.json

Pages are cached, so a re-run costs nothing. The output records the URL and
the date fetched for every state, because a legal note published without a
source and a date is just an assertion.
"""

import argparse
import json
import os
import re
import sys
import time
import urllib.error
import urllib.request

BASE = "https://www.publichealthlawcenter.org/resources/us-e-cigarette-regulations-50-state-review/"

BROWSER_UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36"
)

# state name -> the code used in the page URL
STATES = {
    "Alabama": "al", "Alaska": "ak", "Arizona": "az", "Arkansas": "ar",
    "California": "ca", "Colorado": "co", "Connecticut": "ct",
    "Delaware": "de", "District of Columbia": "dc", "Florida": "fl",
    "Georgia": "ga", "Hawaii": "hi", "Idaho": "id", "Illinois": "il",
    "Indiana": "in", "Iowa": "ia", "Kansas": "ks", "Kentucky": "ky",
    "Louisiana": "la", "Maine": "me", "Maryland": "md",
    "Massachusetts": "ma", "Michigan": "mi", "Minnesota": "mn",
    "Mississippi": "ms", "Missouri": "mo", "Montana": "mt",
    "Nebraska": "ne", "Nevada": "nv", "New Hampshire": "nh",
    "New Jersey": "nj", "New Mexico": "nm", "New York": "ny",
    "North Carolina": "nc", "North Dakota": "nd", "Ohio": "oh",
    "Oklahoma": "ok", "Oregon": "or", "Pennsylvania": "pa",
    "Rhode Island": "ri", "South Carolina": "sc", "South Dakota": "sd",
    "Tennessee": "tn", "Texas": "tx", "Utah": "ut", "Vermont": "vt",
    "Virginia": "va", "Washington": "wa", "West Virginia": "wv",
    "Wisconsin": "wi", "Wyoming": "wy",
}

# The page's own question headings, mapped to the keys we keep.
SECTIONS = {
    "tax": "excise or special tax",
    "packaging": "e-cigarette packaging",
    "retail": "retail or youth access",
    "licence": "retail license or permit",
}


def fetch(url, cache_dir):
    """The page, from cache if it is there."""
    if cache_dir:
        path = os.path.join(cache_dir, re.sub(r"[^a-z0-9]+", "-", url.lower())[-80:])
        if os.path.exists(path):
            with open(path, encoding="utf-8", errors="replace") as fh:
                return fh.read() or None

    request = urllib.request.Request(url, headers={
        "User-Agent": BROWSER_UA,
        "Accept": "text/html,application/xhtml+xml",
        "Accept-Language": "en-US,en;q=0.9",
    })
    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            body = response.read().decode("utf-8", errors="replace")
    except Exception:
        body = ""

    if cache_dir:
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(body)
    return body or None


def text_of(html):
    """Readable text, with the markup and the chrome taken out."""
    plain = re.sub(r"(?is)<(script|style|noscript)\b.*?</\1\s*>", " ", html)
    plain = re.sub(r"(?is)<nav\b.*?</nav\s*>", " ", plain)
    plain = re.sub(r"(?i)</(p|div|li|tr|h[1-6])\s*>", "\n", plain)
    plain = re.sub(r"<[^>]+>", " ", plain)
    plain = plain.replace("&nbsp;", " ").replace("&amp;", "&")
    plain = plain.replace("&#039;", "'").replace("&quot;", '"')
    plain = plain.replace("’", "'").replace("“", '"').replace("”", '"')
    plain = re.sub(r"[ \t]+", " ", plain)
    return re.sub(r"\n\s*\n+", "\n", plain)


def split_sections(html):
    """
    The page as {our key: that section's text}.

    Sections are delimited by the page's own question headings, so this
    follows the document's structure rather than guessing at offsets.

    h2 and h3 both, because the pages are not consistent: most use h2, and
    North Dakota uses h3. Matching only h2 returned that state with every
    section empty -- which is the quiet kind of gap that ends up published
    as a confident note with nothing behind it. The caller's MISSING report
    is what caught it.
    """
    headings = [(m.start(), m.end(),
                 re.sub(r"\s+", " ", re.sub(r"<[^>]+>", "", m.group(2))).strip())
                for m in re.finditer(r"(?is)<(h2|h3)[^>]*>(.*?)</\1>", html)]

    out = {}
    for index, (_, end, label) in enumerate(headings):
        stop = headings[index + 1][0] if index + 1 < len(headings) else len(html)
        for key, needle in SECTIONS.items():
            if needle in label.lower():
                out[key] = text_of(html[end:stop]).strip()
    return out


def main():
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--out", default="state-law.json")
    parser.add_argument("--cache", default="",
                        help="directory to keep fetched pages in")
    parser.add_argument("--delay", type=float, default=1.0)
    parser.add_argument("--only", default="", help="one state, by name")
    args = parser.parse_args()

    if args.cache:
        os.makedirs(args.cache, exist_ok=True)

    targets = {n: c for n, c in STATES.items()
               if not args.only or args.only.lower() in n.lower()}

    collected = {}
    for index, (name, code) in enumerate(sorted(targets.items()), 1):
        url = BASE + code
        print(f"[{index}/{len(targets)}] {name:<22} ", end="", flush=True)
        html = fetch(url, args.cache)
        if not html:
            print("unreachable")
            continue

        sections = split_sections(html)
        missing = [k for k in SECTIONS if k not in sections or not sections[k]]
        collected[name] = {
            "source_url": url,
            "fetched": time.strftime("%Y-%m-%d"),
            "sections": sections,
        }
        print(f"ok  ({', '.join(f'{k} {len(v)}c' for k, v in sections.items())})"
              + (f"  MISSING: {', '.join(missing)}" if missing else ""))
        if index < len(targets):
            time.sleep(args.delay)

    with open(args.out, "w", encoding="utf-8") as fh:
        json.dump(collected, fh, indent=1, sort_keys=True)

    print()
    print(f"Wrote {args.out} - {len(collected)} of {len(targets)} states")
    return 0 if len(collected) == len(targets) else 1


if __name__ == "__main__":
    sys.exit(main())
