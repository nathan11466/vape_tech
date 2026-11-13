#!/bin/sh
# Run every test suite. Exits non-zero if any fails.
cd "$(dirname "$0")" || exit 1
status=0
php ../preflight.php || status=1
for t in *.php; do
    [ "$t" = "run.sh" ] && continue
    echo "--- $t"
    php "$t" || status=1
done
for t in *.py; do
    echo "--- $t"
    python3 -I "$t" || status=1
done
exit $status
