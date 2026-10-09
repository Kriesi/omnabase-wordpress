#!/usr/bin/env bash
#
# Builds the plugin's installable zip: only what runs on a customer's site, Composer's autoloader without dev packages.
#
#   bin/build-dist.sh              # a development zip: build/dist/omnabase-<version>-dev.zip (the name says "-dev"; the files are the same)
#   bin/build-dist.sh --release    # the customer zip: build/dist/omnabase-<version>.zip
#
# Every build: the versions agree (bin/check-version.sh), `composer install --no-dev -o` into a staging copy, a PHP syntax check of
# every file, and a check that no dev file (tests, tooling, notes, dev packages, the test harness) is inside.
#
# --release adds the release gate:
#   - the zip is named for the version alone (no "-dev"), so this is the zip customers get;
#   - changelog.txt's top section must carry its release date (an "unreleased" section cannot ship; see CLAUDE.md, "Changelog");
#   - the syntax check must run on PHP 8.1, the oldest version the header promises (inside wp-env's cli container, so wp-env must be up).
# A development build checks syntax with the PHP on this machine and says which one.
#
# Round four: the shortcode form's stylesheet is built first from OmnaBase's kit tokens (bin/build-form-css.php) when the OmnaBase
# repository is beside this one (or OMNABASE_REPO); otherwise the committed assets/form.css goes in as it is.
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
ROOT="$(pwd)"

RELEASE=0
for arg in "$@"; do
	case "$arg" in
		--release) RELEASE=1 ;;
		-h|--help) sed -n '2,20p' "$0"; exit 0 ;;
		*) echo "Unknown option: $arg" >&2; exit 2 ;;
	esac
done

SLUG="omnabase"
bin/check-version.sh
php bin/build-form-css.php || echo "    (the form's stylesheet as committed)"
VERSION="$(grep -m1 -E '^[[:space:]/*#@]*Version:' omnabase.php | sed -E 's/^[^:]*:[[:space:]]*//; s/[[:space:]]*$//')"

if [[ $RELEASE -eq 1 ]]; then
	if ! grep -qE "^= ${VERSION//./\\.} — [0-9]{4}-[0-9]{2}-[0-9]{2} =$" changelog.txt; then
		echo "ABORT: --release, but changelog.txt's section for ${VERSION} carries no release date (still unreleased?)." >&2
		exit 1
	fi
	LABEL="$VERSION"
else
	LABEL="${VERSION}-dev"
fi

STAGE="$ROOT/build/stage"
TREE="$STAGE/$SLUG"
DIST="$ROOT/build/dist"
ZIP="$DIST/${SLUG}-${LABEL}.zip"
rm -rf "$STAGE"
mkdir -p "$TREE" "$DIST"

echo "==> Staging ${SLUG} ${LABEL}"
# The runtime files, and nothing else: an allow-list, so a new dev file can never slip in by default.
RUNTIME=(omnabase.php uninstall.php readme.txt changelog.txt src templates languages assets composer.json composer.lock)
for item in "${RUNTIME[@]}"; do
	[[ -e "$item" ]] && cp -Rf "$item" "$TREE/"
done
find "$TREE" -name '.DS_Store' -delete -o -name '.gitkeep' -delete

echo "==> Composer (no dev packages, optimised autoloader)"
composer install --working-dir="$TREE" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$TREE/composer.lock" # composer.json stays: WordPress.org asks for it beside a vendor folder

echo "==> PHP syntax check"
if [[ $RELEASE -eq 1 ]]; then
	if ! npx wp-env run cli --env-cwd="wp-content/plugins/omnabase/build/stage/$SLUG" -- sh -c 'php -v | head -1 && find . -name "*.php" -print0 | xargs -0 -n1 php -l > /dev/null'; then
		echo "ABORT: the PHP 8.1 syntax check failed or wp-env is not running (npx wp-env start)." >&2
		exit 1
	fi
else
	echo "    $(php -r 'echo "PHP " . PHP_VERSION;') on this machine (a release checks on PHP 8.1)"
	find "$TREE" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
fi

echo "==> No dev files inside"
DEV_PATTERN='(^|/)(tests?|node_modules|bin|\.github|paid|build)/|(^|/)(CLAUDE\.md|AGENTS\.md|DECISIONS\.md|README\.md|package(-lock)?\.json|playwright\.config\.ts|phpunit[^/]*\.xml|phpcs\.xml\.dist|\.wp-env[^/]*\.json|\.editorconfig|\.gitignore)$|vendor/(phpunit|brain|mockery|squizlabs|wp-coding-standards|phpcompatibility|phpcsstandards|yoast|sebastian|dealerdirect)/'
LEAKS="$(cd "$STAGE" && find "$SLUG" -print | grep -E "$DEV_PATTERN" || true)"
if [[ -n "$LEAKS" ]]; then
	echo "ABORT: dev files in the zip:" >&2
	echo "$LEAKS" >&2
	exit 1
fi
if grep -rlq 'OMNABASE_TEST_MODE' "$TREE"; then
	echo "ABORT: the test harness's constant appears in the runtime files." >&2
	exit 1
fi

echo "==> Zip"
rm -f "$ZIP"
(cd "$STAGE" && zip -rqX "$ZIP" "$SLUG")
echo " PKG  $ZIP ($(du -h "$ZIP" | cut -f1 | tr -d ' '), $(unzip -Z1 "$ZIP" | grep -vc '/$') files)"
