# OmnaBase for WordPress — Agent Instructions

The founder's global rules in `~/.claude/CLAUDE.md` apply (no commits or pushes unless asked, PM-speak plans and tests, SRP/SLAP and the
size budgets, non-interactive shell flags, the customer changelog). This file adds the plugin's layer. The OmnaBase repository's own
`CLAUDE.md` does not apply here; the rules below that come from it are carried over on purpose.

## What this is

The WordPress plugin `omnabase` (M13 of OmnaBase; the brief lives in the OmnaBase repository, `docs/briefs/m13-wordpress-plugin.md`). It
connects a WordPress site to an OmnaBase workspace and carries five needs: Connect from the WordPress admin, customer data (users,
WooCommerce, EDD, licences), bbPress forums to tickets and back, a shortcode form (since the forms round, 2026-10-25: a form built in
OmnaBase, drawn by OmnaBase's embed `forms.js` from the app host, with a prefill token for signed-in visitors), a message centre for
logged-in customers.

- It **talks to OmnaBase's public API alone** (`/api/public/v1`, the contract: OmnaBase's `docs/public-api.md`). It shares no code with
  OmnaBase's TypeScript apps or with Omnalingo; Omnalingo is a pattern to look at, never a source to copy from.
- PHP 8.1+, WordPress 6.8+, tested on the newest WordPress. Namespace `OmnaBase\WordPress\` under `src/` (PSR-4, Composer's autoloader).
  Slug and text domain `omnabase`.
- One admin screen, PHP-rendered (Settings › OmnaBase, `manage_options`), in OmnaBase's own settings look (M14 round four: white cards,
  the kit's line tabs, rows and buttons) and a little vanilla JS. No Vue. Its stylesheet `assets/admin.css` is built from OmnaBase's kit
  tokens and `styles/admin.css` (the source: the kit's rules for the reused class names, every rule under `.ob-wp-settings`, no raw colour,
  no typeface, no dark block); edit the source and run `php bin/build-css.php`, never the built file. WordPress's notices keep their look.
  Six tabs since 2026-10-22: Connection, Customer data, Forums, Forms and messages, Appearance, Activity.
- **The front end's look (2026-10-22)**: the form and the message centre wear the kit's pieces (`ob-btn`, `ob-input`, `ob-textarea`,
  `ob-select`, `ob-pill`, `ob-notice`, `ob-portal-confirm`) under the wrapper `.ob-wp`, which carries the owner's choices from the Appearance
  tab (`data-theme="light|dark"`, none for the visitor's system; `data-accent="custom"` with `--ob-accent-user`/`--ob-on-accent-user`).
  `assets/form.css` is built from `styles/form.css` with the kit's light block, its dark block twice (`.ob-wp[data-theme="dark"]` and, in the
  device's dark setting, `.ob-wp:not([data-theme="light"])`) and the accent blocks after them; semantic tokens only (a new colour goes into
  OmnaBase's `tokens.css` first, with its dark values). `assets/fonts.css` and `assets/fonts/` (OmnaBase's typeface and its OFL licence) are
  built too and queued only when the owner loads OmnaBase's font. Text 4.5 to 1 and focus rings 3 to 1 in both themes.

## Layout

- `omnabase.php` the header and the start; `uninstall.php` removes everything the plugin stores (a list that grows with every option, table,
  meta key and scheduled job: add yours there in the same change).
- `src/` by concern: `Api Connect Security Rest Outbox Facts Sync Forum Forms Portal Look Admin Privacy` (`Look`: the Appearance choices, the
  wrapper's marks and the front end's stylesheets). `Plugin` is the hand-wired
  container (with `CustomerData`, `Forums`, `Forms`, `Portal`, `Privacy`, and `AdminParts` for the admin); each part registers its own hooks. `Options::ALL` lists every
  option name; the user and post meta names and transient prefixes are on `Options` too; `UserNotes` keeps the per-site notes on users.
- `templates/` the PHP templates (escape everything on output); `assets/` CSS and JS; `languages/omnabase.pot` the translation template
  (committed; regenerate it with `bin/make-pot.sh` after changing any translated sentence: the structure guard and the release check
  compare it with the code).
- `tests/Unit` (brain/monkey), `tests/Structure` (file-level guards), `tests/Integration` (inside wp-env), `tests/e2e` (Playwright),
  `tests/wp-env/mu-plugins` the local test harness (never shipped), `tests/fakes` the paid add-ons' fakes (from round two; the harness
  loads them in the development WordPress when the real add-on is absent).
- `bin/` the scripts: `lane.sh` (the browser flows' lane switch, below), `seed.sh`, `build-dist.sh` (`--release` for the customer zip), `check-version.sh` (the header, `OMNABASE_VERSION`,
  readme.txt's Stable tag, the changelog's top section, `package.json`'s version), `make-pot.sh` (the `.pot` through `wp i18n make-pot` in
  wp-env; also `composer i18n:pot`, `npm run pot`), `test-integration.sh`, `omnabase-e2e.sh`, `build-css.php` (both stylesheets from OmnaBase's
  kit tokens: the form's `assets/form.css` from `styles/form.css`, the settings screen's `assets/admin.css` from `styles/admin.css`, the
  font's `assets/fonts.css` and `assets/fonts/`; its token reading lives in `bin/lib/css-tokens.php`; the results are committed,
  `build-dist.sh` rebuilds them).
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
  check, call a service, answer. Logic lives in services; SQL only in the outbox's table class (always `$wpdb->prepare`; reading the site's
  prefix with `$wpdb->get_blog_prefix()` elsewhere runs no query and is allowed).
- **Security** (WPCS's sniffs check it): escape on output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`), sanitise and unslash input,
  a nonce and `current_user_can( 'manage_options' )` on every admin action, a real `permission_callback` on every REST route (the named
  exceptions: the two signed receivers, each with its own check; the Classic form's two routes left with the plugin's forms round). The key and the secrets are read only
  by the vault (from round one) and never reach HTML, JS or a log.
- **The vault's key (2026-10-11)**: chosen once per save and recorded beside the encrypted values (`source` `constant`|`file`|`salts`, a
  16-character fingerprint `kid`): `OMNABASE_ENCRYPTION_KEY` when defined, else a random key the plugin makes once in a guarded key file
  (`omnabase/key.php` under wp-content, or the main site's uploads folder; `exit` before the key, `index.php` and a `.htaccess` deny beside
  it, mode 0600, made in one step, never rewritten, one per network), else the salts. Reading uses the recorded source, never makes a file
  and caches no key. A lost key is "reconnect needed" with the reason (`Vault::unreadableReason()`: `file_gone`, `keys_changed`). Disconnect
  keeps the file; uninstall removes it once. **No screen speaks of how the connection is locked** (no wp-config.php, no constant, no
  "Advanced"): a customer is never asked to edit wp-config.php.
- Every runtime file starts with `defined( 'ABSPATH' ) || exit;`. Every word a person reads goes through `__()`/`esc_html__()`/… with the
  `omnabase` text domain, also labels built in `src/` and shown later (the structure guard scans for bare strings and prose).
- Outgoing calls go to one pinned host (`https://app.omnabase.com`; `OMNABASE_API_URL` points a development site elsewhere, and the screen
  says so), certificates checked, no redirects.
- Test-only code lives under `tests/` (the wp-env harness), never in `src/`: the structure guard refuses `OMNABASE_TEST_MODE` there.
- **Subfolders and networks (2026-10-11)**: the site's address is `SiteCheck::base()` (the home address with its folder, written one way),
  never the host alone. On a multisite every site has its own connection: per-site state lives in options or, for a note on a user, in a
  user option through `UserNotes` (plain user meta is shared by every site; only the customer's site id stays plain user meta); the table
  name is read from `$wpdb->prefix` at call time; anything done "for the network" (activation, deactivation, uninstall) loops
  `get_sites( array( 'fields' => 'ids', 'number' => 0 ) )` (WordPress lists only 100 otherwise) with `switch_to_blog()`/`restore_current_blog()`.

## Settings copy

Every description on the settings screen (a section's intro, a row's help) is at most **30 words**, one sentence that says what the thing
is. Rules and options are a list, never a sentence with commas. Sentence case for labels and buttons; the product says "email".

## Changelog

`changelog.txt` is the customer changelog. The top section is `= X.Y.Z — unreleased =` while it is being built and gets its date on the
release day; a dated section is frozen and never changed again (0.1.0 was released on 2026-10-13). New work after a release goes into a new
`= X.Y.Z — unreleased =` section above it, with the version bumped everywhere `bin/check-version.sh` checks (the plugin header,
`OMNABASE_VERSION`, readme.txt's Stable tag, package.json). At release the top section is copied into `readme.txt`'s "Changelog". One line
per customer-visible change, no class or file names. The current unreleased section is 0.2.1 (the post-as-member round); 0.2.0 was released on 2026-10-29.

## The three test layers

- **Unit and structure** (no WordPress, seconds): `composer test` (both suites; `composer test:unit`, `composer test:structure` alone).
  `composer lint` runs phpcs (PHPCompatibilityWP for PHP 8.1+, WPCS's security and i18n sniffs). Both must pass before a round is done.
- **Integration** (inside wp-env's tests environment, port 8889, WordPress's own test library): `composer test:integration`
  (`bin/test-integration.sh`, PHPUnit's options pass through). Needs `npx wp-env start`. Three runs: a plain WordPress, the group
  `shops` with WooCommerce, EDD, License Manager and the paid add-ons' fakes loaded (`OMNABASE_TEST_SHOPS=1`), and the group `forum`
  with bbPress loaded; all three must pass.
- **Browser flows** (Playwright against wp-env with OmnaBase's e2e server as the backend): `npx playwright test`. Needs `npx wp-env start`,
  `bin/seed.sh`, Node 22 and OmnaBase's e2e server (below).
- **The lane switch (2026-10-22)**: agents never run the flows against the founder's stack (3011/4391). Give Playwright a lane by its ports,
  e.g. lane 3: `set -a; source <lane3-db.env>; set +a; OMNABASE_E2E_PORT=3031 OMNABASE_WEB_PORT=4411 OMNABASE_APP_URL=http://localhost:4411
  npx playwright test <file> --workers=1`. The global setup then points the development WordPress at the same ports (`bin/lane.sh set 3031
  4411`: `OMNABASE_API_URL`/`OMNABASE_APP_URL` in wp-config.php) and its teardown puts `.wp-env.json`'s values back (`bin/lane.sh reset`; run
  it by hand after a killed run; `bin/lane.sh show` tells what WordPress uses). One Playwright run at a time, never beside an integration run.
- **Review pictures**: `frontend-look.spec.ts` writes the form and the message centre (light and dark, desktop and phone) into
  `test-results/frontend-look/`, `admin-look.spec.ts` the tabs into `test-results/admin-look/`; records for the founder's eye, never baselines.
- **The release checks** run with the flows (they need wp-env): `release.spec.ts` builds the zip, checks its contents, runs Plugin Check
  on it (no error allowed; warnings listed) and compares the `.pot`; `uninstall.spec.ts` deletes the plugin's data from the development
  WordPress the way WordPress does and expects one `DELETE /me`; `fresh.spec.ts` installs the zip on the fresh WordPress (below);
  `cron-health.spec.ts` sets `DISABLE_WP_CRON` for one page view.
- Finish a round with: `composer lint`, `composer test`, `composer test:integration`, `npm run typecheck` (the flows' TypeScript),
  `npx playwright test`, `bin/build-dist.sh`. Plugin Check by hand: unzip the zip into `build/check/` and run
  `npx wp-env run cli -- wp plugin check /var/www/html/wp-content/plugins/omnabase/build/check/omnabase`.
- CI (`.github/workflows/checks.yml`) runs only when started by hand (workflow_dispatch): phpcs and the unit and structure tests.

## The local WordPress (wp-env) and the ports

- `export PATH="$HOME/.nvm/versions/node/v22.22.1/bin:$PATH"` (Node 22), Docker Desktop running, then `npx wp-env start` (the first start
  downloads WordPress and the plugins: minutes). `npx wp-env stop` / `destroy`. `bin/seed.sh` seeds the fixtures (safe to run again).
- **8888**: the development WordPress (admin `admin` / `password`, wp-env's default), with WooCommerce, Easy Digital Downloads, bbPress,
  License Manager for WooCommerce and Plugin Check from WordPress.org, the plugin mapped as `wp-content/plugins/omnabase`, the harness as a
  must-use plugin. **8889**: the tests environment (its own database) for the integration layer. wp-env 11 still starts both by default and
  warns that this is deprecated; when it stops, the tests environment moves to `--config .wp-env.tests.json` (DECISIONS).
- WP-CLI: `npx wp-env run cli -- wp <command>`; the harness adds `wp omnabase-test set-key <key>` and `forget-key` (test mode only).
- **Clicking through by hand (2026-10-10): `bin/local.sh up`.** It starts what is missing (wp-env, the seed, OmnaBase's e2e stack on 3011 and 4391, the relay) and opens a Playwright Chromium window with the flows' route, so the site is `https://wp.omnabase.test` inside that window only: no port 443, no certificate, LocalWP's router untouched. The terminal prints every mail the e2e server captures (sign-in codes, confirmation links). `bin/local.sh code` prints the founder's last code, `status`, `check` (a screenshot without a window), `down` (the stack; wp-env stays). The stack starts with a fresh schema: OmnaBase's side forgets the site on a restart (click Connect again). Never together with OmnaBase's own Playwright suite or API tests (same ports and test database).
- **The LocalWP site (2026-10-11), the founder's fast one**: his LocalWP multisite "localhost" has the sub-site `https://localhost/omnabase` with this repository linked in as `wp-content/plugins/omnabase`, bbPress, WooCommerce and EDD active there, the seed run, and `OMNABASE_API_URL`/`OMNABASE_APP_URL` in its `wp-config.php` pointing at the local e2e stack; `bin/local.sh stack` sets its relay (`localhost` → LocalWP's router at 127.0.0.1) and runs the ticker. He opens it in his own Chrome (`&autologin=true` on a wp-admin address signs in through his dev-only must-use plugin). WP-CLI against it: LocalWP's shell environment (`MYSQL_HOME`/`PHPRC` under `~/Library/Application Support/Local/run/LekZhO4dB/conf`, PHP from `lightning-services/php-8.2.27+1`, `wp` from `/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/posix`), `--url=localhost/omnabase`. Never touch its other sub-sites.
- **8890: the fresh WordPress** (`.wp-env.fresh.json`, its own containers and database): PHP 8.1, no plugins, only the harness's must-use
  plugin mapped (test host `https://fresh.omnabase.test`), never this repository, so uploading and deleting the zip there cannot touch the
  source. `fresh.spec.ts` starts it when it does not answer and resets it with `npx wp-env clean all --config=.wp-env.fresh.json --force`
  (without `--force` wp-env waits for y/N). By hand: `npm run env:fresh`; WP-CLI there: `npx wp-env run --config=.wp-env.fresh.json cli -- wp
  …`; stop it with `npx wp-env stop --config=.wp-env.fresh.json`.
- wp-env's PHP keeps compiled files for 2 seconds (`opcache.revalidate_freq`): after changing wp-config.php through WP-CLI, wait that long
  before the next page view.
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
