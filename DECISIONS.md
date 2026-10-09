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

## 2026-10-09 — M13 round one: Connect, the vault, the signed receivers, health, Disconnect

The red team's tests came first (`docs/reviews/m13-round1-red-team-report.md`: 68 cases in 9 files, copied in unchanged; all green, none
adjusted). OmnaBase's side (`DELETE /me`, `POST /me/ping`, `/me`'s slugs, own tickets only, the list's default) is recorded in OmnaBase's
DECISIONS "M13 round one: Connect; the API's additions for the plugin". The coordinator's rulings are taken as given; what follows is what
the brief left open.

- **The parts, by the red team's names**: `Connect\Pkce`, `Connect\Flow` (the link and the way back), `Connect\ConnectState` (each
  administrator's pending connect), `Connect\SiteCheck`, `Connect\ConnectionInfo`, `Connect\Disconnect`; `Security\Vault` and
  `Security\Signature` (under `Security/`, not `Api/`: the red team's names stand); `Api\Client`, `Api\Loopback`; `Rest\Events`,
  `Rest\Customer`, `Rest\SignedCall` (the receivers' shared door), `Rest\ReplayGuard`, `Rest\DeliveryLog`, `Rest\LastPing`;
  `Admin\SettingsScreen`, `Admin\ConnectionView`, `Admin\Actions`, `Admin\Health`, `Admin\Notices`, `Admin\Flash`. Round zero's
  `Api\StoredKey` and its test are gone.
- **The pending connect expires after 10 minutes** (the coordinator's number; the brief said 15; the tests pass 9 and refuse 16). It lives in
  the administrator's own user meta (`omnabase_pending_connect`: the state, the verifier sealed by the vault, the time), one per
  administrator: a second click by the same administrator replaces the first, two administrators keep their own. A way back with another
  state (or another administrator's) changes nothing, so a forged link cannot spoil a real connect; the right state is used once, also when
  the exchange then fails.
- **The vault**: one non-autoloaded option (`omnabase_vault`) holding a check value and the three credentials, each sealed with
  `sodium_crypto_secretbox` and a fresh nonce. The key: `OMNABASE_ENCRYPTION_KEY` (32 bytes in base64 used as they are, any other value
  hashed to 32 bytes), else a BLAKE2b hash of `wp_salt( 'auth' )` (WordPress answers that from `AUTH_KEY`/`AUTH_SALT`, or from salts it keeps
  in the database when those are missing or the placeholder). `keySource()` says `constant`, `salts` or `database`; the screen says honestly
  that a database key adds little and offers a freshly generated line for wp-config.php (with "then connect again": a new key cannot open
  what the old one sealed). The vault also seals the pending verifier (`seal`/`open`), so nothing else touches its key.
- **Messages after an action** travel in a transient for that administrator alone (`omnabase_notice_<user id>`, five minutes) and the
  browser always lands on the clean settings address: the flow pins the address without code, state or message.
- **The screen's address is https on an https site** (`SettingsScreen::url()`): `admin_url()` follows the scheme of the current request,
  and OmnaBase's way back must be https. **Connect also shows beside a connection** (secondary, "Connect again" in its title): connecting again
  is the brief's reconnect, which rotates the key.
- **The receivers**: the signature in the `permission_callback` (the docs' recipe; `hash_equals`; five minutes either way). An empty vault and
  an unreadable one both answer **503** (OmnaBase keeps retrying a fixable gap; never 410, never 2xx); a bad, missing, stale or `v0=`
  signature **401**. The replay key is `X-OmnaBase-Delivery` (the payload's id only when the header is missing): handled ids are kept 30 days
  in one option (at most 5 000), recorded only after the handling ran through; a listener that throws makes the answer 500 and the retry
  handles it again. A handled ping records its time. The log keeps the last 20 rows (time, event, delivery id, ticket number, what the plugin
  did: handled, repeat or failed), never content. `/customer` answers `{}` until round two.
- **Check connection**: `GET /me`, then `POST /me/ping` (OmnaBase answers once it has called the site), then the site's own receiver (the
  loopback, `Api\Loopback`, its address from `rest_url()`: the one call that does not go to OmnaBase, allowed by the coordinator's ruling).
  OmnaBase → site counts as working only when the receiver recorded a ping after the check began; while none has, the screen says
  "waiting" and reloads every 3 seconds for up to 30, then "not working" with the reason; a refused request or OmnaBase's `ok: false` shows
  its reason at once. The loopback reads "unknown" on a network error (some hosts cannot call themselves), "working" only on the receiver's
  own signature refusal.
- **Disconnect** forgets the vault, the kept connection, the last check and the last ping whatever `DELETE /me` answered; a 401 counts as
  "stopped already", anything else adds "revoke this site's key under Settings › Integrations › API keys". With an unreadable vault there is no
  key to revoke with, so the same words show.
- **WordPress's own options** are read without a literal option name (`get_bloginfo( 'name' )`, `$wp_rewrite->using_permalinks()`, dates
  as `Y-m-d H:i`), because round zero's guard counts every literal option name in `src/` as the plugin's own.
- **Uninstall** removes the six options, every administrator's pending connect (`delete_metadata`) and the message transients by prefix: a
  prepared `DELETE … LIKE` in `uninstall.php`, the one place besides the coming outbox table class where SQL is allowed (WordPress has no
  call for transients by prefix).
- **The test layers**: the integration bootstrap lifts WordPress's `_config_wp_home`/`_config_wp_siteurl` filters, because wp-env's tests
  config defines `WP_HOME`/`WP_SITEURL` and a test could otherwise never put the site on https or into a subfolder. The flows' route now
  forwards a redirect that stays on `wp.omnabase.test` through the page itself: Chromium does not hand the redirect of a fulfilled answer
  back to the route, so the clean address after the way back met real DNS (round zero never redirected within the site). The harness's
  `set-key` writes the vault (`--webhooks=`/`--facts=` for the secrets); `round-trip.spec.ts` stores the webhook's secret that way and now
  proves the receiver accepts OmnaBase's signed call (200, where round zero saw WordPress's 404), and revokes its key at the end.
  `bin/omnabase-e2e.sh` starts OmnaBase's web app on Vite (4391) beside the API, as OmnaBase's own Playwright config does; the global setup
  waits up to a minute for it. `.wp-env.json` names `OMNABASE_APP_URL` (`http://localhost:4391`) and defines no `OMNABASE_ENCRYPTION_KEY`
  (the tests rotate the salts).

## 2026-10-09 — M13 round two: customer data, the shaper, the outbox, the licence copy

The red team's tests came first (`docs/reviews/m13-round2-red-team-report.md`: 118 cases in 9 files, the contract oracle with its 58
recorded verdicts, three fakes, and three replaced test-setup files, all copied in). Before: unit and structure 64 of 66 failing, integration
46 of 48, the flow stopped at the missing section. After: all green; four test adjustments, listed below with their reasons. No OmnaBase
change was needed. The words: *facts call*, OmnaBase's signed question to `/wp-json/omnabase/v1/customer`; *block*, the `omnabase` part of
the answer; *usermeta*, extra fields per user; *outbox*, the lasting list of calls still to make; *WP-Cron*, WordPress's timer; *HPOS*,
WooCommerce's newer order tables; *fake*, a stand-in for a paid add-on, test code only.

**The coordinator's rulings, as built.**

- **The contract wins over the brief's wording**: no display name, `found` or currency field; the currency sits inside the total
  (`59.00 EUR`); nobody found is `{}`; guests are found by WooCommerce's billing address and by any address of an EDD customer.
- **License Manager for WooCommerce 3.0.20** fires no action when a licence is edited or activated; its one licence event is
  `lmfwc_event_post_order_license_keys` (an order's keys delivered). Its copy refreshes on that event and on the order's status change; an
  admin's edit in License Manager alone refreshes nothing until the order changes.
- **The red team's choices stand**: licences are left out last when an answer is too large (profile, subscriptions, orders first); a row
  needs attention after 3 days of passing failures, never on a 429; `409 facts_failed` is retried, `409 no_endpoint` needs attention;
  refreshes only for users OmnaBase knows; the banned usermeta list is the brief's parts plus `salt` and `capabilities`. The accepted cost:
  `code` also bans `billing_postcode`, `key` every `*_key` field, `auth` an `author_*` field.
- **The shops' tests are a second integration run** (`OMNABASE_TEST_SHOPS=1`, the group `shops`); round one's tests keep a plain WordPress.
- **The development WordPress loads `tests/fakes`** through the test harness (the must-use plugin, never shipped) at `plugins_loaded`
  priority 1, when WooCommerce or EDD is active and the paid add-on is not.

**The parts, by the brief's names.** `Facts\Responder` (the call → the answer), `Facts\Identity` and `Facts\Person` (the site id first,
else the user with that address, else a guest), `Facts\FactsSource` with seven sources under `Facts\Sources\` (`Profile`, `WooCommerce`,
`WooSubscriptions`, `Edd`, `EddRecurring`, `EddSoftwareLicensing`, `LicenseManager`, plus `EddCustomer` and `Format` as helpers),
`Facts\Shaper` with `Facts\Field` (one field's rules) and `Facts\ContractCheck` (the plugin's copy of the contract, the last word),
`Facts\MetaPolicy` (the one banned list, shared with the picker), `Facts\Switches`, `Facts\FactsLog`; `Outbox\Table` (the one place for
SQL), `Outbox\Policy`, `Outbox\Worker`, `Outbox\Queue`, `Outbox\Schedule`, `Outbox\Job`; `Sync\SiteId`, `Sync\KnownUsers`, `Sync\UserLink`,
`Sync\LicenceRefresh`, `Sync\LicenceHooks`; `Admin\FactsView`, `Admin\OutboxView`, `Admin\DataActions`, `Admin\Guard` (the actions' nonce
and permission check, now shared with round one's `Actions`). `CustomerData` wires them beside `Plugin`.

**Choices the brief left open.**

- **The answer**: `{ "omnabase": { "v": 1, … } }` when a section is present, `{}` otherwise (nothing switched on, nobody found). A user
  found with a switched-on shop source but no records gets an empty list (`orders: []`: "no orders"); a guest without records gets no
  section, so nobody found stays `{}`.
- **Sections from two sources** (orders from WooCommerce and EDD, licences from EDD Software Licensing and License Manager, subscriptions
  from both subscription add-ons) are merged; orders are then sorted by date, newest first, before the shaper keeps 20.
- **WooCommerce orders**: a user's by account (`customer_id`), a guest's by billing address only. A registered user's earlier guest orders
  under the same address are not shown (round three may add them). Orders come through `wc_get_orders` with its default order types
  (never a literal `shop_order`). **The order link is the classic edit address** (`post.php?post=<id>&action=edit`) on both storages, so
  both storages answer alike; WooCommerce forwards it to its newer order screen when HPOS is on. Admin links are https whenever the site is.
- **Dates** are `Y-m-d`: a unix time in the site's time zone, a stored date string by its own date part (WooCommerce Subscriptions' UTC
  dates, EDD Recurring's local ones). **Totals**: `number_format` with the shop's decimals and the currency code (`59.00 EUR`).
- **Licence keys** are masked in the sources already (the full key never leaves the source class) and again in the shaper: `••••-` and
  the last four characters; a key of four characters or fewer becomes `••••`. A lifetime EDD licence has no `expiresAt`. License Manager's
  numeric statuses in words (`sold delivered active inactive`); it has no guest licences (they belong to an account).
- **The shaper's size budget** is 127 KB of the encoded answer (128 KB less 1 KB of room), measured with `wp_json_encode` and no flags, as
  WordPress's REST server sends it. If the licences alone are still too large, the oldest go one by one ("licences (the oldest)" in the
  log). Meta keys over 64 characters are dropped, not cut (a cut name means nothing). An empty profile is `{}`; empty meta is left out.
- **The log line** is a stored list, `omnabase_facts_log` (the last 20 answers: time, user/guest/nobody, the sections, the ones left out;
  never an address or data), and the customer-data section shows the last answer's left-out sections. No `error_log` (WPCS flags it, and
  most hosts hide it).
- **The link** is queued only while a source is switched on (nothing is shared before), once per user (one waiting row per user), and a
  user counts as known once OmnaBase accepted it (`omnabase_known`, user meta) or asked by the site id. **The refresh** is queued only
  while a licence source is on (EDD Software Licensing or License Manager): it copies licences and nothing else.
- **The outbox table** (`{prefix}omnabase_outbox`): `id kind subject_id dedupe_key state attempts next_at claimed_until last_error
  created_at sent_at`, unix times, made on activation and on any load whose `omnabase_db_version` differs (version `1`). No payload column:
  the body is built from the user when the row is sent (the brief), so the table holds no customer data. The dedupe key is not a unique
  index (sent rows keep theirs for seven days); a new row is refused while one with the same key waits.
- **The lock is a claim per row** (`claimed_until`, two minutes): an `UPDATE … WHERE claimed_until < now` that only one run can win, so
  overlapping runs on two visits never send a row twice, and a crashed run frees its rows after two minutes. An option-based lock was not
  atomic (`add_option` writes with `ON DUPLICATE KEY UPDATE`).
- **The immediate try**: a new row schedules a one-off run (`omnabase_outbox_now`; the recurring hook cannot be used: WordPress refuses a
  single event within ten minutes of another with the same hook) and wakes WP-Cron with `spawn_cron()` at the end of the request (not from
  WP-Cron itself or WP-CLI), never inside the shop's own step. The recurring job is scheduled at `init` (WordPress's interval list is
  translated, and translations load from `init` on); deactivation clears both.
- **Retry** sets a row needing attention back to waiting, due now, with its tries and its three days started afresh; **Discard** marks it
  `discarded`, kept seven days with the sent rows, then removed. "Send now" (the brief's third button) waits for round three.
- **A new connection starts afresh**: `Connect\Flow` fires `omnabase_connected` after a successful exchange; every "known" mark is
  forgotten (the workspace on the other side may be another one) and the sent and discarded rows go. Waiting and attention rows stay (never
  dropped without the administrator).
- **The picker** offers the usermeta keys of the 50 newest users plus any ticked before, never `_…`, banned or WordPress's own screen
  preferences (`rich_editing`, `admin_color` …). Keys are stored only when well formed and not banned.
- **The hooks register once per request**: the integration tests boot the plugin a second time (round one's helper), which would have
  hooked a second worker to the same timer; `CustomerData::registerBackground()` keeps one.

**Test adjustments (each with its reason in the test's comment).**

1. `RoundTwoTestCase::askFacts` signs the test's own user in again after the facts call: round one's `deliver` logs out (as OmnaBase's
   call is), so a screen test that asked first then rendered the screen or posted Discard as nobody and met WordPress's refusal.
2. `FactsIdentityProfileTest`, the reused user number: the test's own renumbering by raw SQL makes WordPress warn "Cache key must not be an
   empty string" (traced to `clean_user_cache` and `new WP_User` in the setup lines); the two warnings are declared expected.
3. `RoundTwoScreenTest`: the helper `values()` reads an element's `value` attribute, so the outbox section's text and the descriptions'
   texts came back blank (the first could never find the reason, the 30-word check could never fail); both queries now read text nodes.
4. Round zero's structure guard: "a long random-looking string" skips a name made only of lower-case letters and underscores
   (`woocommerce_order_status_changed`, `lmfwc_event_post_order_license_keys` are hook names, not secrets); its example still proves it.

**Open for round three.** "Send now" on the outbox; a registered user's earlier guest orders; licences of guest orders in License Manager;
health lines for the facts calls and a disabled WP-Cron; "unsupported version" checks per shop; an idempotency key column when topics join
the outbox; the real paid add-ons (WooCommerce Subscriptions, EDD Recurring, EDD Software Licensing) are only proven against their fakes
until the founder drops their zips into `paid/`.
