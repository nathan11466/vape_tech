"""
Destination derivation, and the columns it fills.

service_locations and primary_service_location were declared as columns and
never written, so the Service Locations boxes on every store stayed unticked
no matter how often the CSV was imported. These check they are filled, and
filled honestly.

    python3 -I tests/test_derive_shipping.py
"""
import os
import sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), ".."))
import derive_shipping  # noqa: E402

fails = []


def check(label, cond, detail=""):
    if cond:
        print("PASS:", label)
    else:
        print("FAIL:", label, "--", detail)
        fails.append(label)


def row(**kw):
    base = {
        "brand_name": "VooPoo",
        "brand_url": "https://voopoo.com",
        "shipping_info": "",
        "shipping_restrictions": "",
        "restricted_states": "",
        "ships_to_countries": "",
        "service_locations": "",
        "primary_service_location": "",
        "primary_market": "United States",
    }
    base.update(kw)
    return base


# --- A US merchant with nothing stated ---------------------------------
r = row()
conf = derive_shipping.apply_to_row(r)
check("a US merchant gets destinations", r["ships_to_terms"] != "", r["ships_to_terms"])
check("marked as an assumption, not a claim", conf == "assumed", conf)
check("service_locations is filled", r["service_locations"] != "", repr(r["service_locations"]))
check("with the country, not fifty-one states",
      r["service_locations"] == "United States", repr(r["service_locations"]))
check("primary_service_location is filled",
      r["primary_service_location"] == "United States", repr(r["primary_service_location"]))

# --- A value already present is never overwritten ----------------------
r = row(service_locations="United States|Canada", primary_service_location="Canada")
derive_shipping.apply_to_row(r)
check("an existing service_locations value is left alone",
      r["service_locations"] == "United States|Canada", repr(r["service_locations"]))
check("and an existing primary is left alone",
      r["primary_service_location"] == "Canada", repr(r["primary_service_location"]))

# --- Nothing derivable means nothing invented --------------------------
r = row(brand_url="https://vapeshop.co.uk", primary_market="United Kingdom",
        shipping_info="We deliver across the UK only.")
derive_shipping.apply_to_row(r)
check("a non-US merchant is not given US destinations",
      "Texas" not in r["ships_to_terms"], r["ships_to_terms"])
check("and service_locations is not invented when no country resolved",
      r["service_locations"] == "" or "United States" not in r["service_locations"],
      repr(r["service_locations"]))

# --- Stated restrictions are still honoured ----------------------------
r = row(restricted_states="Utah|Vermont")
derive_shipping.apply_to_row(r)
check("stated restrictions survive", "Utah" in r["restricted_states"], r["restricted_states"])
check("and the restricted state is not also listed as served",
      "|Utah|" not in "|" + r["ships_to_terms"] + "|", r["ships_to_terms"])

# --- service_locations must only ever hold country-level names ---------
r = row()
derive_shipping.apply_to_row(r)
names = [n for n in r["service_locations"].split("|") if n]
check("every service_location is a known country",
      all(n in derive_shipping.COUNTRIES for n in names), repr(names))

print()
if fails:
    print(f"{len(fails)} check(s) FAILED")
    sys.exit(1)
print("All shipping derivation checks passed.")
