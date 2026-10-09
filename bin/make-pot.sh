#!/usr/bin/env bash
#
# Regenerates languages/omnabase.pot, the template translators start from, with WP-CLI's `wp i18n make-pot` inside wp-env's cli
# container (needs `npx wp-env start`). It scans what the zip ships (the main file, uninstall.php, src/, templates/, assets/) and nothing
# of the workshop, so the release check (tests/e2e/release.spec.ts) finds the same sentences in the built zip. Commit the result.
#
#   bin/make-pot.sh        # or: composer i18n:pot, npm run pot
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
EXCLUDE="vendor,node_modules,tests,bin,build,paid,docs,styles,test-results,playwright-report,blob-report,languages"
npx wp-env run cli --env-cwd=wp-content/plugins/omnabase -- \
	wp i18n make-pot . languages/omnabase.pot --slug=omnabase --domain=omnabase --exclude="$EXCLUDE"
grep -c '^msgid "' languages/omnabase.pot | sed 's/^/msgids: /'
