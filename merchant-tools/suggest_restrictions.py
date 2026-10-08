#!/usr/bin/env python3
"""
Suggest likely shipping restrictions from the consensus in your own data.

Only a minority of merchants publish a state exclusion list. The ones that do
name largely the same states, because they are all reacting to the same state
laws -- so their lists are evidence about the ones that stay silent.

This reads the merchants who DO publish, reports which states they agree on,
and writes a SUGGESTED list for the merchants who do not.

What it deliberately does not do:

  * It never writes to restricted_states. Suggestions land in a separate
    suggested_restricted_states column, so nothing downstream treats a guess as
    a fact -- derive_shipping keeps ignoring it, and no page changes until you
    promote a suggestion yourself.
  * It never suggests for a merchant outside the US, or one whose own policy
    was already read.

Published third-party lists of "states that ban online vape sales" disagree
with each other and are mostly retailer blogs, so none is used here. Your own
merchants' published policies are the better evidence.

    python suggest_restrictions.py merchants-filled.csv --out merchants-suggested.csv
    python suggest_restrictions.py merchants-filled.csv --report-only
"""

import argparse
import csv
import sys
from collections import Counter


def split_states(value):
    return [s.strip() for s in (value or "").split("|") if s.strip()]


def main():
    parser = argparse.ArgumentParser(description=__doc__,
                                     formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("input")
    parser.add_argument("--out", help="CSV to write (omit with --report-only)")
    parser.add_argument("--report-only", action="store_true",
                        help="show the consensus without writing anything")
    parser.add_argument("--threshold", type=float, default=0.5,
                        help="a state is suggested when this share of publishing "
                             "merchants name it (default 0.5)")
    args = parser.parse_args()

    if not args.report_only and not args.out:
        parser.error("--out is required unless --report-only is used")

    with open(args.input, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        rows = list(reader)
        fieldnames = list(reader.fieldnames or [])

    publishers = [r for r in rows if split_states(r.get("restricted_states"))]
    if not publishers:
        print("No merchant in this file publishes a restricted-states list yet.")
        print("Run fetch_merchant_data.py first, or fill some by hand.")
        return 1

    counts = Counter()
    for row in publishers:
        for state in set(split_states(row.get("restricted_states"))):
            counts[state] += 1

    total = len(publishers)
    cutoff = max(2, int(round(args.threshold * total)))
    consensus = [s for s, n in counts.most_common() if n >= cutoff]

    print(f"{total} of {len(rows)} merchants publish a restricted-states list.")
    print()
    print(f"{'State':<22} named by   share")
    print("-" * 44)
    for state, count in counts.most_common():
        share = count / total
        mark = "  <- consensus" if count >= cutoff else ""
        print(f"{state:<22} {count:>5}/{total}   {share:>5.0%}{mark}")

    print()
    print(f"Consensus (named by at least {cutoff} of {total}): "
          f"{', '.join(consensus) if consensus else 'none'}")

    if args.report_only:
        print()
        print("Report only - nothing written.")
        return 0

    if not consensus:
        print()
        print("No state reached the threshold, so there is nothing to suggest.")
        print("Collect more published lists first.")
        return 0

    for column in ("suggested_restricted_states", "suggestion_basis"):
        if column not in fieldnames:
            fieldnames.append(column)
        for row in rows:
            row.setdefault(column, "")

    suggested = 0
    for row in rows:
        if split_states(row.get("restricted_states")):
            continue  # already has real data
        # Only for merchants that look US-based.
        blob = " ".join([
            row.get("ships_to_countries", ""), row.get("primary_service_location", ""),
            row.get("brand_summary", ""), row.get("shipping_restrictions", ""),
        ]).lower()
        if "united kingdom" in blob or "australia" in blob or " uk " in blob:
            continue

        row["suggested_restricted_states"] = "|".join(consensus)
        row["suggestion_basis"] = (
            f"consensus of {total} merchants in this dataset that publish a list; "
            f"NOT verified for this merchant"
        )
        suggested += 1

    with open(args.out, "w", newline="", encoding="utf-8") as fh:
        writer = csv.DictWriter(fh, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    print()
    print(f"Wrote {args.out}")
    print(f"  {suggested} merchants given a suggestion")
    print()
    print("These are SUGGESTIONS in suggested_restricted_states. Nothing on your")
    print("site changes until you check one against the merchant's own policy and")
    print("copy it into restricted_states yourself.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
