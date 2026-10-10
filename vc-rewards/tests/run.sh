#!/bin/sh
# Runs the suite against a real WordPress on SQLite.
#
#   VC_WP_DIR=/path/to/wordpress VC_WP_PRISTINE=/path/to/clean.sqlite ./run.sh
#
# See README.md ("Running the tests") for setting that WordPress up.
cd "$(dirname "$0")" || exit 1
status=0
for f in ../vc-rewards.php ../includes/*.php; do
    php -l "$f" >/dev/null || status=1
done
php test_rewards.php || status=1
php test_wpforo.php || status=1
exit $status
