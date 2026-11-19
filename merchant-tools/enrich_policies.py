#!/usr/bin/env python3
"""
Add policy facts to the merchant CSV from each store's own pages.

Why this exists: compose_sections.py builds the page copy from a fact
vocabulary of eight slots, most stores fill two or three, and 112 stores
drawn from that produce at most a handful of distinct paragraphs. The
repetition report measures the result; this widens the input. More facts per
store makes every composed section more specific without rewriting a word.

Every value written here carries the URL it came from and the sentence it was
read in, in a sibling column. Nothing is inferred, and a fact the store does
not state plainly is left blank -- including when the store contradicts
itself, which is recorded in the conflicts column instead of guessed at.

    python3 -I enrich_policies.py merchants.csv --out merchants-policies.csv

Re-running is cheap: every page is cached, so a second pass re-reads from
disk and visits nobody. Extraction changes can be re-run over the cache for
free, which is the point of keeping fetch and extract apart.
"""

import argparse
import csv
import datetime
import os
import sys

# Run this with -I: it parses pages fetched from 112 third-party sites, and
# isolated mode stops a planted module in the working directory being
# imported instead of the standard library. -I also drops the script's own
# directory from sys.path, so the siblings are put back explicitly -- that is
# this file's own directory, never the directory the pages were cached in.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from policy_extract import extract, merge  # noqa: E402
from policy_fetch import pages_for_store  # noqa: E402

# The columns written. Each gets a _source and _evidence sibling.
FACT_COLUMNS = (
    "return_window_days",
    "restocking_fee_percent",
    "return_shipping_paid_by",
    "warranty_days",
    "free_shipping_threshold_usd",
    "min_order_usd",
    "processing_days",
    "shipping_carriers",
    "age_verification_method",
)

META_COLUMNS = ("policy_facts_checked", "policy_facts_conflicts",
                "policy_facts_problem")


def store_url(row):
    for field in ("brand_url", "brand_dot_com"):
        value = (row.get(field) or "").strip()
        if value:
            if not value.startswith(("http://", "https://")):
                value = "https://" + value.lstrip("/")
            return value
    return ""


def label(row):
    for field in ("display_brand_name", "externalMerchantKey"):
        value = (row.get(field) or "").strip()
        if value:
            return value
    return store_url(row) or "?"


def enrich_row(row, cache_dir, delay, respect_robots, overwrite):
    """Fills the fact columns on one row. Returns a short status string."""
    url = store_url(row)
    if not url:
        row["policy_facts_problem"] = "no store URL in the row"
        return "skipped"

    pages, problem = pages_for_store(url, cache_dir, delay, respect_robots)
    if problem:
        row["policy_facts_problem"] = problem
        return problem

    results = [(kind, extract(html, page_url))
               for kind, page_url, html in pages]
    found = merge(results)

    written = 0
    for key in FACT_COLUMNS:
        detail = found["facts"].get(key)
        if not detail:
            continue
        # A value already in the sheet is someone's own work. Never clobber
        # it without being asked: blank means "no opinion", not "replace me".
        if (row.get(key) or "").strip() and not overwrite:
            continue
        row[key] = str(detail["value"])
        row[key + "_source"] = detail["source_url"]
        row[key + "_evidence"] = detail["evidence"]
        written += 1

    row["policy_facts_checked"] = datetime.date.today().isoformat()
    if found["conflicts"]:
        row["policy_facts_conflicts"] = "; ".join(
            "%s: %s" % (key, ", ".join(str(v) for v in values))
            for key, values in sorted(found["conflicts"].items()))
    row["policy_facts_problem"] = ""

    return "%d fact(s) from %d page(s)" % (written, len(pages))


def main():
    ap = argparse.ArgumentParser(description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("csv_path", help="the merchant CSV to read")
    ap.add_argument("--out", help="where to write (default: alongside, -policies)")
    ap.add_argument("--cache", default="policy-cache",
                    help="directory for fetched pages (default: policy-cache)")
    ap.add_argument("--delay", type=float, default=2.0,
                    help="seconds between requests (default: 2)")
    ap.add_argument("--limit", type=int, default=0,
                    help="only process the first N rows, for a trial run")
    ap.add_argument("--only", default="",
                    help="comma-separated display names to process")
    ap.add_argument("--overwrite", action="store_true",
                    help="replace fact values already in the sheet")
    ap.add_argument("--ignore-robots", action="store_true",
                    help="fetch even where robots.txt disallows it")
    args = ap.parse_args()

    with open(args.csv_path, encoding="utf-8-sig", newline="") as fh:
        rows = list(csv.DictReader(fh))
    if not rows:
        sys.exit("no rows in %s" % args.csv_path)

    fieldnames = list(rows[0].keys())
    for key in FACT_COLUMNS:
        for column in (key, key + "_source", key + "_evidence"):
            if column not in fieldnames:
                fieldnames.append(column)
    for column in META_COLUMNS:
        if column not in fieldnames:
            fieldnames.append(column)

    only = {name.strip().lower()
            for name in args.only.split(",") if name.strip()}

    os.makedirs(args.cache, exist_ok=True)
    out_path = args.out or (os.path.splitext(args.csv_path)[0] + "-policies.csv")

    done = 0
    for row in rows:
        for column in fieldnames:
            row.setdefault(column, "")

        if only and label(row).lower() not in only:
            continue
        if args.limit and done >= args.limit:
            break

        status = enrich_row(row, args.cache, args.delay,
                            not args.ignore_robots, args.overwrite)
        done += 1
        print("%-28s %s" % (label(row)[:28], status), flush=True)

    with open(out_path, "w", encoding="utf-8", newline="") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    filled = {key: sum(1 for r in rows if (r.get(key) or "").strip())
              for key in FACT_COLUMNS}
    conflicted = sum(1 for r in rows if (r.get("policy_facts_conflicts") or "").strip())

    print("\n%d stores processed -> %s" % (done, out_path))
    for key in FACT_COLUMNS:
        print("  %-30s %3d" % (key, filled[key]))
    print("  %-30s %3d" % ("rows with a conflict to read", conflicted))
    print("\nEvery value has a _source and _evidence column beside it. "
          "Read those before publishing: nothing here has been seen by a person yet.")


if __name__ == "__main__":
    main()
