#!/usr/bin/env bash
#
# Starts OmnaBase's e2e server for the plugin's browser flows: OmnaBase's API on port 3011 (OMNABASE_E2E_PORT), a fresh schema on
# OmnaBase's local test database, Team Kriesi's seed, a capture mail transport and the test hooks (`/api/test/apikey`, `/api/test/relay`,
# `/api/test/tick` …). It runs in the foreground; Playwright starts it by itself when nothing answers on the port yet.
#
#   TEST_DATABASE_URL_ADMIN=… bin/omnabase-e2e.sh
#
# Needs: the OmnaBase repository beside this one (or OMNABASE_REPO), its `pnpm install`, Node 22, and TEST_DATABASE_URL_ADMIN (OmnaBase's
# local test database container, see OmnaBase's own notes). Never run it while OmnaBase's own Playwright suite or API tests run: they
# use the same port and the same test database.
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OMNABASE_REPO="${OMNABASE_REPO:-$(cd "$HERE/../.." && pwd)/omnabase}"
PORT="${OMNABASE_E2E_PORT:-3011}"

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
if lsof -ti "tcp:$PORT" >/dev/null 2>&1; then
	echo "ERROR: something already listens on port $PORT (a stale e2e server? lsof -ti tcp:$PORT | xargs kill)." >&2
	exit 1
fi

cd "$OMNABASE_REPO/apps/web"
exec env NODE_ENV=test PORT="$PORT" APP_URL="http://localhost:4391" node_modules/.bin/tsx e2e/server.ts
