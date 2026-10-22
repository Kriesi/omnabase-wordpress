#!/usr/bin/env bash
#
# The browser flows' lane switch: points the development WordPress (wp-env, port 8888) at one OmnaBase e2e stack, or puts it back.
#
#   bin/lane.sh set <api-port> <web-port>   # e.g. bin/lane.sh set 3031 4411 (lane 3): OMNABASE_API_URL and OMNABASE_APP_URL in wp-config.php
#   bin/lane.sh reset                       # back to .wp-env.json's values (the founder's stack, 3011 and 4391)
#   bin/lane.sh show                        # what WordPress uses now
#
# Why: wp-env writes OMNABASE_API_URL=http://host.docker.internal:3011 and OMNABASE_APP_URL=http://localhost:4391 into wp-config.php, the
# founder's own stack. Moving Playwright and OmnaBase's e2e server to another lane by environment variables alone leaves WordPress calling
# 3011. The flows' global setup calls `set` with the ports it was given (OMNABASE_E2E_PORT, OMNABASE_WEB_PORT) and its teardown calls
# `reset`, so a run never reaches a stack it was not given; after a run that was killed, `bin/lane.sh reset` by hand.
# wp-env's PHP keeps compiled files for 2 seconds, so the script waits that long after a change.
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

wpcli() { npx wp-env run cli -- wp "$@" 2>/dev/null; }
default_of() { node -e "process.stdout.write(String(require('./.wp-env.json').config['$1']))"; }

case "${1:-}" in
	set)
		api="${2:?the API port, e.g. 3031}"
		web="${3:?the web port, e.g. 4411}"
		wpcli config set OMNABASE_API_URL "http://host.docker.internal:${api}" --type=constant >/dev/null
		wpcli config set OMNABASE_APP_URL "http://localhost:${web}" --type=constant >/dev/null
		sleep 2
		echo "lane: WordPress talks to OmnaBase on ${api} (connect page ${web})"
		;;
	reset)
		wpcli config set OMNABASE_API_URL "$(default_of OMNABASE_API_URL)" --type=constant >/dev/null
		wpcli config set OMNABASE_APP_URL "$(default_of OMNABASE_APP_URL)" --type=constant >/dev/null
		sleep 2
		echo "lane: WordPress back to .wp-env.json's OmnaBase ($(default_of OMNABASE_API_URL))"
		;;
	show)
		echo "OMNABASE_API_URL=$(wpcli config get OMNABASE_API_URL)"
		echo "OMNABASE_APP_URL=$(wpcli config get OMNABASE_APP_URL)"
		;;
	*)
		echo "usage: bin/lane.sh set <api-port> <web-port> | reset | show" >&2
		exit 2
		;;
esac
