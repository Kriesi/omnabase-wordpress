#!/usr/bin/env bash
#
# The local loop for clicking through the plugin by hand: wp-env's WordPress (with WooCommerce, EDD, bbPress and the seed) connected to
# OmnaBase's own local e2e stack (apps/web/e2e/server.ts: a fresh schema on the local test database, Team Kriesi's seed, mail captured, never
# sent). Nothing leaves the Mac; production is not involved. The site is https://wp.omnabase.test only inside the browser this script opens
# (bin/local-browser.ts routes it to wp-env, as the flows do), because the plugin connects only a site on https at port 443, and OmnaBase
# must call the site back: the e2e server's relay carries those calls to wp-env.
#
#   bin/local.sh up              starts what is missing (wp-env, the seed, the e2e stack on 3011 + 4391, the relay) and opens the browser
#   bin/local.sh browser [url]   opens the browser again (the stack keeps running after the window closes)
#   bin/local.sh connect         the browser, signed in, with Connect clicked and approved as the seeded founder (kriesi@example.test)
#   bin/local.sh stack           starts the stack without a browser
#   bin/local.sh check           proves the loop without a window: the settings page through the route, a screenshot under build/
#   bin/local.sh code [address]  the last sign-in code mailed to the address (default kriesi@example.test, the seeded founder)
#   bin/local.sh status          what answers
#   bin/local.sh down            stops the e2e stack (wp-env stays up; `npx wp-env stop` for that)
#
# The e2e stack starts with a fresh schema every time: sites, keys and tickets at OmnaBase's side vanish on `down` + `up` (the WordPress
# keeps its key and says "reconnect needed"; click Connect again). Never run OmnaBase's own Playwright suite or API tests while the stack is up:
# same ports, same test database. Needs Docker Desktop (wp-env, the test database container omnabase-testpg) and the OmnaBase repository
# beside this one (or OMNABASE_REPO).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

NODE22="$HOME/.nvm/versions/node/v22.22.1/bin"
[[ -d "$NODE22" ]] && export PATH="$NODE22:$PATH"
export OMNABASE_LOCAL_SITES=1 # the local OmnaBase lets a site on a .localhost name connect (a LocalWP site; production never does)
export TEST_DATABASE_URL_ADMIN="${TEST_DATABASE_URL_ADMIN:-postgres://postgres:test@127.0.0.1:5434/omnabase_test}" # OmnaBase's local test container
API="http://localhost:${OMNABASE_E2E_PORT:-3011}"
WEB="http://localhost:${OMNABASE_WEB_PORT:-4391}"
WP="http://localhost:8888"
LOG="build/local-e2e.log"

answers() { curl -s -o /dev/null -m 3 "$1"; }
wait_for() { local url=$1 what=$2 n=${3:-120}; for ((i = 0; i < n; i++)); do answers "$url" && return 0; sleep 1; done; echo "ERROR: $what does not answer at $url (see $LOG)" >&2; return 1; }

ensure_wp() {
	if ! answers "$WP"; then
		echo "Starting wp-env (the first start downloads WordPress and the plugins) …"
		npx wp-env start
	fi
	if ! npx wp-env run cli -- wp user get ada --field=user_login >/dev/null 2>&1; then
		echo "Seeding the WordPress …"
		bin/seed.sh
	fi
}

ensure_stack() {
	if answers "$API/health"; then
		echo "OmnaBase's e2e stack is up ($API, $WEB)."
	else
		mkdir -p build
		echo "Starting OmnaBase's e2e stack (a fresh schema and the seed: a minute) …"
		nohup bin/omnabase-e2e.sh >"$LOG" 2>&1 &
		echo $! >build/local-e2e.pid
		wait_for "$API/health" "the e2e API" 180
		wait_for "$WEB" "the connect page (Vite)" 60
	fi
	# OmnaBase's calls to the site (webhooks, customer facts) go through the relay to wp-env; the flows reset it, so it is set every time
	curl -s -X POST "$API/api/test/relay" -H 'content-type: application/json' -d '{"host":"wp.omnabase.test","to":"http://localhost:8888"}' >/dev/null
	echo "Relay set: wp.omnabase.test → $WP"
}

case "${1:-up}" in
	up) ensure_wp; ensure_stack; exec node bin/local-browser.ts ;;
	stack) ensure_wp; ensure_stack ;;
	browser) ensure_stack; exec node bin/local-browser.ts "${2:-}" ;;
	connect) ensure_wp; ensure_stack; exec node bin/local-browser.ts --connect ;;
	check) ensure_wp; ensure_stack; node bin/local-browser.ts --check build/local-check.png ;;
	code) curl -s "$API/api/test/mail?to=$(printf %s "${2:-kriesi@example.test}" | sed 's/@/%40/')" | grep -oE '[0-9]{3} [0-9]{3}' | head -1 || echo "no code yet" ;;
	status)
		answers "$WP" && echo "wp-env: up ($WP)" || echo "wp-env: down"
		answers "$API/health" && echo "e2e API: up ($API)" || echo "e2e API: down"
		answers "$WEB" && echo "connect page: up ($WEB)" || echo "connect page: down"
		curl -s "$API/api/test/relay" -X POST -H 'content-type: application/json' -d '{}' 2>/dev/null | head -c 300; echo ;;
	down)
		for p in "${OMNABASE_E2E_PORT:-3011}" "${OMNABASE_WEB_PORT:-4391}"; do lsof -ti "tcp:$p" | xargs kill 2>/dev/null || true; done
		rm -f build/local-e2e.pid
		echo "e2e stack stopped (wp-env keeps running)." ;;
	*) sed -n 3,20p "$0"; exit 1 ;;
esac
