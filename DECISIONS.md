# Decisions

Dated entries, newest last. A choice the brief leaves open is decided here instead of asked; a change to a decided behaviour updates its
entry's successor and the affected test in the same change. OmnaBase-side changes are recorded in the OmnaBase repository's
`DECISIONS.md`.

## 2026-10-09 — M13: the brief's decisions, summarised (the founder's Go with the defaults)

The brief is OmnaBase's `docs/briefs/m13-wordpress-plugin.md`, draft 2 after its red-team review; the founder: "go with the defaults,
private repo under Kriesi, start round zero." What it decided, in short:

- **The repository and the plugin**: `Kriesi/omnabase-wordpress` (private), slug and text domain `omnabase`, namespace
  `OmnaBase\WordPress\`. PHP 8.1+ (73.9 % of WordPress sites on 2026-10-09; WooCommerce's minimum from February 2027), "Requires at
  least: 6.8", tested on the newest WordPress only. It talks to OmnaBase's public API alone and shares no code with OmnaBase or Omnalingo.
- **One admin screen, PHP-rendered** (Settings › OmnaBase, administrators only): the connection, the forums, the facts switches, the
  shortcodes, the message-centre page, the outbox, health, the last webhook deliveries. No Vue or Vite in v1.
- **Connect** (need 1): a full-page redirect to OmnaBase's connect page with PKCE (the plugin keeps a secret and sends only its hash), the
  callback is the settings page itself, one server-side exchange; refused on an http site, plain permalinks or a subfolder install. **The
  vault**: the key and both secrets encrypted (libsodium's secretbox) under `OMNABASE_ENCRYPTION_KEY` or the salts; honest when the salts
  live in the database; a salt rotation detected and answered with "reconnect needed" and 503s (never 410). The two receivers
  (`/wp-json/omnabase/v1/events` and `/customer`) check OmnaBase's signature before any handler runs.
- **The outbox**: a custom table for every call that matters (topics, replies, user links, licence refreshes), worked by WP-Cron every
  minute; it never drops a row (a 429 waits its `Retry-After`, errors back off, a hopeless answer goes to "needs attention" with Retry and
  Discard).
- **Customer data** (need 2): every source off until the admin turns it on (profile and chosen usermeta, WooCommerce, Woo Subscriptions,
  EDD, EDD Recurring and Software Licensing, License Manager for WooCommerce); the site's id for a user is `<user id>-<8 random
  characters>`, fixed once released; every answer shaped to OmnaBase's contract before sending; licence changes queue a refresh.
- **bbPress** (need 3): marked forums; every forum but a hidden one is public; topics and the author's and moderators' replies go as
  tickets and messages; OmnaBase's replies come back as forum replies by one support user, never echoed back.
- **Forms** (need 4): `[omnabase_form]`, independent of OmnaBase's hosted web form, a single-use time token as the idempotency key, a
  honeypot, 5 an hour per address and 25 an hour per site.
- **The message centre** (need 5): `[omnabase_messages]` on a page the admin picks, the visitor always the current user's site id, the
  confirm prompt before mail conversations show, the prompt again after an e-mail change, no caching of that page.
- **Tests**: PHPUnit 9.6 with brain/monkey; wp-env integration tests with WordPress's own test library; Playwright against wp-env with
  OmnaBase's e2e server; structure guards; phpcs with PHPCompatibilityWP and WPCS's security sniffs; Plugin Check. CI by hand only.
- **The order**: a round zero (this workshop) and six rounds: connect, customer facts, the forum, the form, the message centre, release
  readiness.

## 2026-10-09 — M13 round zero: the workshop

Built in this repository (no red-team tests in round zero; the builder wrote the tests), plus OmnaBase's relay (OmnaBase's DECISIONS
"M13 round zero: the plugin repository and the relay hook").

- **PHPUnit 9.6, not 10 or 11.** The brief names 9.6, and WordPress's own test library (the integration layer) runs on PHPUnit 9 through
  yoast/phpunit-polyfills; PHPUnit 11 also needs PHP 8.2, while Composer is pinned to the promised 8.1. Revisit when WordPress's test
  library supports newer PHPUnit.
- **phpcs checks compatibility and security, not style**: PHPCompatibilityWP (`8.1-`), WPCS's `WordPress.Security`, prepared SQL, the
  i18n text domain, prefixed globals, the alternative and development functions. The code is written in WordPress's formatting (tabs,
  spaces inside parentheses) with camelCase methods, as Omnalingo does; style is not enforced.
- **Two PHPUnit configurations**: `phpunit.xml` (the suites `unit` and `structure`, no WordPress; `composer test`) and
  `phpunit-integration.xml` (inside wp-env's tests container with WordPress's test library; `composer test:integration`), because the
  integration layer needs its own bootstrap. One integration test already runs (Settings › OmnaBase for administrators only), so round
  one's red team starts from a working layer.
- **wp-env 11**: the third-party plugins are mapped from WordPress.org's `latest-stable` zips into folders with their real names
  (`wp-content/plugins/woocommerce` …) and activated after start: listed under `plugins` they landed in folders like
  `woocommerce.latest-stable`, and a plain `bbpress.zip` is bbPress's development version (2.7 alpha). wp-env 11 still starts the tests
  environment on 8889 by default and warns that this is deprecated; kept as is until wp-env removes it, then a `.wp-env.tests.json` with
  `--config`.
- **The harness's network, as the brief planned, proved**: the browser opens `https://wp.omnabase.test`, Playwright's route hands each
  request to `http://localhost:8888` through Node's fetch (no cookie jar of its own; the browser keeps the cookies for the https host) with
  `X-Forwarded-Host`/`-Proto`; a must-use plugin in wp-env (`tests/wp-env/mu-plugins/omnabase-test-harness.php`, test mode on a `local`
  environment only) then sets https and the host and filters `home`/`siteurl` for such requests only, so a plain visit to localhost:8888
  still works. **WordPress reaches OmnaBase's e2e server at `host.docker.internal:3011` while it listens on 127.0.0.1** (Docker Desktop
  hands the name to the Mac's loopback; proved with a `/health` call from the container), so the brief's fallback `HOST` setting is not
  needed and not built. No local TLS front.
- **The test door stays out of the plugin**: the key is written by a WP-CLI command of the wp-env harness (`wp omnabase-test set-key`),
  not by a REST route in `src/`; the structure guard refuses `OMNABASE_TEST_MODE` in the runtime code and the build refuses it in the zip.
- **Round zero keeps the key in a plain option** (`omnabase_connection`, not autoloaded) behind one small class (`Api\StoredKey`); round one
  replaces it with the vault, and the guard "credentials read only by the vault" starts then.
- **The flow proves both directions** without Connect (round one): a test key for the site `wp.omnabase.test` makes the screen read
  "Connected to Team Kriesi" from `GET /me` (WordPress → OmnaBase), and a webhook for `ticket.created` on the site's address, with the
  relay set, arrives at wp-env and gets WordPress's own `rest_no_route` 404 (OmnaBase → WordPress; round one adds the receiver). The brief's
  "connect, then a ping" needs round one's Connect and `/me/ping`, so it becomes round one's flow.
- **The seed** (`bin/seed.sh` → `bin/seed.php` through `wp eval-file`, safe to run again): permalinks; Ada and Ben (customers), Mike (a
  moderator who is an OmnaBase member: `mike@example.test` is in Team Kriesi's seed), Nina (a moderator who is not), the support user;
  WooCommerce's Ada order and a guest order; License Manager's key on Ada's order; EDD's Ada order with a second address on her EDD
  customer; forums Support (public), Members (private), Staff (hidden) and Ben's topic. Paid add-ons absent: skipped with a line saying
  so. Seeded users' password: `test-password` (local test values, written in the seed file).
- **`bin/build-dist.sh`**: an allow-list of runtime files, `composer install --no-dev -o` in a staging copy, a syntax check, a refusal of
  any dev file or the harness's constant. A plain build is a development zip (`omnabase-0.1.0-dev.zip`: only the name says so; the
  header keeps the real version, because Plugin Check refuses a header that disagrees with readme.txt's Stable tag). `--release` names the
  zip for the version alone and adds the release gate: the changelog's section must carry its date, and the syntax check runs on PHP 8.1
  inside wp-env (this Mac's PHP is 8.2). Plugin Check on the built tree (`wp plugin check <path of build/stage/omnabase>` in wp-env):
  no errors on 2026-10-09; on the checkout it flags the dev files, as expected.
- **The OmnaBase e2e server for the flows**: OmnaBase's own (`apps/web/e2e/server.ts`) on its usual port 3011, started by
  `bin/omnabase-e2e.sh` (Playwright's `webServer`, reused when already up). The brief's "its own instance" is met by never running it
  beside OmnaBase's suite (same port, same test database): the simplest arrangement that works; a second port waits until both suites must
  run at once.
- Found on the way, for OmnaBase: `GET /api/public/v1/tickets` without `view` answers `400 No such view` (`view: "all"`), while
  `docs/public-api.md` says `all` is the default; the flow passes `view=all-open`.
