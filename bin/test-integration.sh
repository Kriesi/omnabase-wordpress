#!/usr/bin/env bash
#
# The integration layer: PHPUnit inside wp-env's tests environment (port 8889, PHP 8.1, its own database), with WordPress's own test
# library (/wordpress-phpunit in the container) and yoast/phpunit-polyfills. Needs `npx wp-env start` and `composer install`.
#
# Two runs (M13 round two): the plain WordPress (every test outside the group "shops"), then WordPress with WooCommerce, EDD and License
# Manager for WooCommerce loaded (OMNABASE_TEST_SHOPS=1, the group "shops"). Both run; the script fails when either fails.
#
#   bin/test-integration.sh                 # every integration test
#   bin/test-integration.sh --filter Menu   # PHPUnit's options pass through (to both runs)
#
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
npx wp-env run tests-cli --env-cwd=wp-content/plugins/omnabase -- vendor/bin/phpunit -c phpunit-integration.xml "$@"
plain=$?
npx wp-env run tests-cli --env-cwd=wp-content/plugins/omnabase -- env OMNABASE_TEST_SHOPS=1 vendor/bin/phpunit -c phpunit-integration.xml --group shops "$@"
shops=$?
exit $(( plain || shops ))
