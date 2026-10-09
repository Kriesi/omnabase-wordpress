#!/usr/bin/env bash
#
# The integration layer: PHPUnit inside wp-env's tests environment (port 8889, PHP 8.1, its own database), with WordPress's own test
# library (/wordpress-phpunit in the container) and yoast/phpunit-polyfills. Needs `npx wp-env start` and `composer install`.
#
#   bin/test-integration.sh                 # every integration test
#   bin/test-integration.sh --filter Menu   # PHPUnit's options pass through
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
npx wp-env run tests-cli --env-cwd=wp-content/plugins/omnabase -- vendor/bin/phpunit -c phpunit-integration.xml "$@"
