# OmnaBase for WordPress — Agent Instructions

The founder's global rules in `~/.claude/CLAUDE.md` apply (no commits or pushes unless asked, PM-speak plans and tests, SRP/SLAP and the
size budgets, non-interactive shell flags, the customer changelog). This file adds the plugin's layer. The OmnaBase repository's own
`CLAUDE.md` does not apply here; the rules below that come from it are carried over on purpose.

## What this is

The WordPress plugin `omnabase` (M13 of OmnaBase; the brief lives in the OmnaBase repository, `docs/briefs/m13-wordpress-plugin.md`). It
connects a WordPress site to an OmnaBase workspace and carries five needs: Connect from the WordPress admin, customer data (users,
WooCommerce, EDD, licences), bbPress forums to tickets and back, a shortcode form, a message centre for logged-in customers.

- It **talks to OmnaBase's public API alone** (`/api/public/v1`, the contract: OmnaBase's `docs/public-api.md`). It shares no code with
  OmnaBase's TypeScript apps or with Omnalingo; Omnalingo is a pattern to look at, never a source to copy from.
- PHP 8.1+, WordPress 6.8+, tested on the newest WordPress. Namespace `OmnaBase\WordPress\` under `src/` (PSR-4, Composer's autoloader).
  Slug and text domain `omnabase`.
- One admin screen, PHP-rendered (Settings › OmnaBase, `manage_options`), WordPress's own admin styles and a little vanilla JS. No Vue,
  no build step for the admin.

## Layout

- `omnabase.php` the header and the start; `uninstall.php` removes everything the plugin stores (a list that grows with every option, table,
  meta key and scheduled job: add yours there in the same change).
- `src/` by concern: `Api Connect Security Rest Outbox Facts Sync Forum Forms Portal Admin Privacy` (most arrive in later rounds).
  `Plugin` is the hand-wired container; each part registers its own hooks. `Options::ALL` lists every option name.
- `templates/` the PHP templates (escape everything on output); `assets/` CSS and JS; `languages/` the `.pot`.
- `tests/Unit` (brain/monkey), `tests/Structure` (file-level guards), `tests/Integration` (inside wp-env), `tests/e2e` (Playwright),
  `tests/wp-env/mu-plugins` the local test harness (never shipped), `tests/fakes` the paid add-ons' fakes (from round two; the harness
  loads them in the development WordPress when the real add-on is absent).
- `bin/` the scripts: `seed.sh`, `build-dist.sh`, `check-version.sh`, `test-integration.sh`, `omnabase-e2e.sh`.
- `paid/` (git-ignored): the founder's paid add-on zips when he has them (WooCommerce Subscriptions, EDD Software Licensing, EDD Recurring).

## The rounds and the red team

- **Red-team tests first** from round one on: each round opens with failing tests written by the red-team agent (Opus), then the builder
  makes them pass. Never delete or weaken a failing test to get green; fix the code or record the decision in `DECISIONS.md`.
- **Every new or changed test carries the PM-speak description** above it (What it checks / Passes when / Fails when), written for a
  technical PM who has not read the code: no function names or file paths, WordPress words explained.
- **Every WordPress term in a brief, plan or report gets its plain meaning inline** the first time (hook: a named moment where a plugin's
  code runs; option: a stored setting; nonce: a short-lived form token against forged requests; capability: a permission; …).
- **Decisions**: when you choose something the brief leaves open, append a dated entry to `DECISIONS.md` instead of asking. OmnaBase-side
  changes are recorded in OmnaBase's own `DECISIONS.md` and `docs/progress.md` (the M13 row and section).

## Stage: development, not yet beta

Until OmnaBase's beta (the founder decides when) the plugin follows the API: when an address, field or shape changes on OmnaBase's side,
the plugin simply changes with it. No compatibility code, no "old and new" paths, no migrations of a site's stored data. From the plugin's
first public release on, a site's stored data is migrated and nothing a site holds breaks.

## PHP rules

- **SRP and SLAP** as in the global rules; a class at most ~300 lines, a method ~30. **REST callbacks and hook callbacks stay thin**: they
  check, call a service, answer. Logic lives in services; SQL only in the outbox's table class (always `$wpdb->prepare`).
- **Security** (WPCS's sniffs check it): escape on output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), sanitise and unslash input,
  a nonce and `current_user_can( 'manage_options' )` on every admin action, a real `permission_callback` on every REST route (the named
  exceptions: the two signed receivers, the form and its time-token route, each with its own check). The key and the secrets are read only
  by the vault (from round one) and never reach HTML, JS or a log.
- Every runtime file starts with `defined( 'ABSPATH' ) || exit;`. Strings are translatable with the `omnabase` text domain.
- Outgoing calls go to one pinned host (`https://app.omnabase.com`; `OMNABASE_API_URL` points a development site elsewhere, and the screen
  says so), certificates checked, no redirects.
- Test-only code lives under `tests/` (the wp-env harness), never in `src/`: the structure guard refuses `OMNABASE_TEST_MODE` there.

## Settings copy

Every description on the settings screen (a section's intro, a row's help) is at most **30 words**, one sentence that says what the thing
is. Rules and options are a list, never a sentence with commas. Sentence case for labels and buttons; the product says "email".

## Changelog

`changelog.txt` is the customer changelog: `= 0.1.0 — unreleased =` until the release day, then dated and frozen (a new version above
it, bumped everywhere `bin/check-version.sh` checks). At release the top section is copied into `readme.txt`'s "Changelog". One line per
customer-visible change, no class or file names.

## The three test layers

- **Unit and structure** (no WordPress, seconds): `composer test` (both suites; `composer test:unit`, `composer test:structure` alone).
  `composer lint` runs phpcs (PHPCompatibilityWP for PHP 8.1+, WPCS's security and i18n sniffs). Both must pass before a round is done.
- **Integration** (inside wp-env's tests environment, port 8889, WordPress's own test library): `composer test:integration`
  (`bin/test-integration.sh`, PHPUnit's options pass through). Needs `npx wp-env start`. Two runs: a plain WordPress, then the group
  `shops` with WooCommerce, EDD, License Manager and the paid add-ons' fakes loaded (`OMNABASE_TEST_SHOPS=1`); both must pass.
- **Browser flows** (Playwright against wp-env with OmnaBase's e2e server as the backend): `npx playwright test`. Needs `npx wp-env start`,
  `bin/seed.sh`, Node 22 and OmnaBase's e2e server (below).
- Finish a round with: `composer lint`, `composer test`, `composer test:integration`, `npm run typecheck` (the flows' TypeScript),
  `npx playwright test`, `bin/build-dist.sh`.
- CI (`.github/workflows/checks.yml`) runs only when started by hand (workflow_dispatch): phpcs and the unit and structure tests.

## The local WordPress (wp-env) and the ports

- `export PATH="$HOME/.nvm/versions/node/v22.22.1/bin:$PATH"` (Node 22), Docker Desktop running, then `npx wp-env start` (the first start
  downloads WordPress and the plugins: minutes). `npx wp-env stop` / `destroy`. `bin/seed.sh` seeds the fixtures (safe to run again).
- **8888**: the development WordPress (admin `admin` / `password`, wp-env's default), with WooCommerce, Easy Digital Downloads, bbPress,
  License Manager for WooCommerce and Plugin Check from WordPress.org, the plugin mapped as `wp-content/plugins/omnabase`, the harness as a
  must-use plugin. **8889**: the tests environment (its own database) for the integration layer. wp-env 11 still starts both by default and
  warns that this is deprecated; when it stops, the tests environment moves to `--config .wp-env.tests.json` (DECISIONS).
- WP-CLI: `npx wp-env run cli -- wp <command>`; the harness adds `wp omnabase-test set-key <key>` and `forget-key` (test mode only).
- **The harness's network**: the browser opens `https://wp.omnabase.test`; Playwright's route hands each request to `http://localhost:8888`
  with `X-Forwarded-Host`/`-Proto`, and the must-use plugin makes WordPress answer as https at that host. WordPress (in Docker) reaches
  OmnaBase's e2e server at `http://host.docker.internal:3011` (`OMNABASE_API_URL` in `.wp-env.json`). OmnaBase reaches WordPress through
  the e2e server's relay (`POST /api/test/relay { host: "wp.omnabase.test", to: "http://localhost:8888" }`). LocalWP and its
  `kapitan.localhost` router are never touched.

## The OmnaBase e2e server

The browser flows' backend is OmnaBase's own e2e server (`apps/web/e2e/server.ts` in the OmnaBase repository): OmnaBase's API on
**3011**, a fresh schema on OmnaBase's local test database, Team Kriesi's seed, a capture mail transport, the test hooks.

- Start it: `TEST_DATABASE_URL_ADMIN=… bin/omnabase-e2e.sh` (Node 22; the OmnaBase repository beside this one or `OMNABASE_REPO`).
  Playwright starts it itself when nothing answers on 3011 and the variable is set; a running one is reused.
- Kill a stale one first: `lsof -ti tcp:3011 | xargs kill`. **Never run it while OmnaBase's own Playwright suite or its API tests run**:
  same port, same test database.
- The hooks the flows use: `/api/test/apikey` (a site key), `/api/test/relay` (OmnaBase → this WordPress), `/api/test/tick` (`webhooks`,
  `sender`), `/api/test/webhooks`, the mail capture (`/api/test/mail`, `/api/test/outbox`), `/api/test/clock`, `/api/test/limits`.
- From round one, Connect needs OmnaBase's web app too (the connect page on Vite, port 4391): `bin/omnabase-e2e.sh` starts it beside the e2e API (port 3011).
