#!/usr/bin/env bash
#
# Starts OmnaBase's e2e stack for the plugin's browser flows, the way OmnaBase's own Playwright config does (apps/web/playwright.config.ts):
#
# - OmnaBase's web app on Vite, port 4391 (OMNABASE_WEB_PORT): the connect page Connect sends the administrator to (`OMNABASE_APP_URL` in
#   .wp-env.json); Vite proxies /api to the API below. Started in the background and stopped with this script.
# - OmnaBase's API on port 3011 (OMNABASE_E2E_PORT): a fresh schema on OmnaBase's local test database, Team Kriesi's seed, a capture mail
#   transport and the test hooks (`/api/test/apikey`, `/api/test/relay`, `/api/test/tick` …). In the foreground.
#
# Playwright starts it by itself when nothing answers on 3011 yet (and waits for 4391 in its global setup).
#
#   TEST_DATABASE_URL_ADMIN=… bin/omnabase-e2e.sh
#
# Needs: the OmnaBase repository beside this one (or OMNABASE_REPO), its `pnpm install`, Node 22, and TEST_DATABASE_URL_ADMIN (OmnaBase's
# local test database container, see OmnaBase's own notes). Never run it while OmnaBase's own Playwright suite or API tests run: they
# use the same ports and the same test database.
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OMNABASE_REPO="${OMNABASE_REPO:-$(cd "$HERE/../.." && pwd)/omnabase}"
PORT="${OMNABASE_E2E_PORT:-3011}"
WEB_PORT="${OMNABASE_WEB_PORT:-4391}"

if [[ -z "${TEST_DATABASE_URL_ADMIN:-}" ]]; then
	echo "ERROR: TEST_DATABASE_URL_ADMIN is not set (OmnaBase's local test database)." >&2
	exit 1
fi
if [[ ! -f "$OMNABASE_REPO/apps/web/e2e/server.ts" ]]; then
	echo "ERROR: OmnaBase's e2e server not found under $OMNABASE_REPO (set OMNABASE_REPO)." >&2
	exit 1
fi
NODE_MAJOR="$(node -p 'process.versions.node.split(".")[0]')"
if (( NODE_MAJOR < 22 )); then
	echo "ERROR: Node 22 or newer is needed (found $(node -v)); export the nvm path of Node 22 first." >&2
	exit 1
fi
for p in "$PORT" "$WEB_PORT"; do
	if lsof -ti "tcp:$p" >/dev/null 2>&1; then
		echo "ERROR: something already listens on port $p (a stale e2e stack? lsof -ti tcp:$p | xargs kill)." >&2
		exit 1
	fi
done

cd "$OMNABASE_REPO/apps/web"

# The connect page: OmnaBase's web app on Vite, its /api proxied to the API's port; stopped when this script ends.
API_PORT="$PORT" node_modules/.bin/vite --port "$WEB_PORT" --strictPort > /dev/null 2>&1 &
VITE_PID=$!
trap 'kill "$VITE_PID" 2>/dev/null || true' EXIT INT TERM

NODE_ENV=test PORT="$PORT" APP_URL="http://localhost:$WEB_PORT" node_modules/.bin/tsx e2e/server.ts
