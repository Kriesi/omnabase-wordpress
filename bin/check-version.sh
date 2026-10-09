#!/usr/bin/env bash
#
# Checks that the plugin's version and PHP minimum agree everywhere they are written: the header and OMNABASE_VERSION in omnabase.php,
# readme.txt's "Stable tag" and "Requires PHP", and the top section of changelog.txt. Exits 1 and names the place that disagrees.
# The structure guard (composer test) checks the same; this script is what bin/build-dist.sh runs before it builds anything.
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

field() { grep -m1 -E "^[[:space:]/*#@]*$1:" "$2" | sed -E "s/^[^:]*:[[:space:]]*//; s/[[:space:]]*$//"; }

HEADER="$(field 'Version' omnabase.php)"
CONSTANT="$(sed -nE "s/.*define\([[:space:]]*'OMNABASE_VERSION',[[:space:]]*'([^']+)'.*/\1/p" omnabase.php | head -1)"
STABLE="$(field 'Stable tag' readme.txt)"
CHANGELOG="$(sed -nE 's/^= ([0-9]+\.[0-9]+\.[0-9]+) — .*=$/\1/p' changelog.txt | head -1)"
PHP_HEADER="$(field 'Requires PHP' omnabase.php)"
PHP_README="$(field 'Requires PHP' readme.txt)"

status=0
check() { if [[ "$2" != "$3" ]]; then echo "MISMATCH: $1 is '$3', the header says '$2'" >&2; status=1; fi; }
check "OMNABASE_VERSION in omnabase.php" "$HEADER" "$CONSTANT"
check "readme.txt Stable tag" "$HEADER" "$STABLE"
check "changelog.txt's top section" "$HEADER" "$CHANGELOG"
check "readme.txt Requires PHP" "$PHP_HEADER" "$PHP_README"

if [[ $status -eq 0 ]]; then echo "versions agree: $HEADER (PHP $PHP_HEADER)"; fi
exit $status
