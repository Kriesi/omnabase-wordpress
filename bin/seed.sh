#!/usr/bin/env bash
#
# Seeds wp-env's development WordPress (port 8888) with the brief's fixtures: pretty permalinks, the customers Ada and Ben, a member and a
# non-member moderator, the support user, WooCommerce and EDD orders, a License Manager licence, three bbPress forums (public, private,
# hidden) and Ben's topic. Paid add-ons that are not installed are skipped with a line saying so. Safe to run again.
#
#   npx wp-env start && bin/seed.sh
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
npx wp-env run cli -- wp eval-file wp-content/plugins/omnabase/bin/seed.php
