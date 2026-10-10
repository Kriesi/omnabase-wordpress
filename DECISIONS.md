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

## 2026-10-09 — M13 round three: the forum

The red team's tests came first (`docs/reviews/m13-round3-red-team-report.md`: 56 cases here in 7 files, plus OmnaBase's 6, and four
replaced test-setup files: the integration bootstrap loading bbPress, `phpunit-integration.xml`, `bin/test-integration.sh` with its third
run, `tests/e2e/harness.ts` with the fragment-redirect fix; all copied in). Before: structure 7 of 7 failing, integration 39 of 42, the flow
stopped at the missing forum section. After: all green, two adjustments and one ruling-driven change, listed below with their reasons.
OmnaBase's side (`siteAuthor`) is recorded in its DECISIONS "M13 round three: the forum; siteAuthor". The words: *topic* and *reply*,
bbPress's forum posts; *marked forum*, ticked by the administrator; *hidden forum*, moderators only; *private forum*, every logged-in user;
*support user*, the WordPress user OmnaBase's answers appear under; *echo*, the site's own posting coming back to OmnaBase as new.

**The coordinator's rulings, as built.**

- **Moderators**: every reply by someone with bbPress's moderator rights (the `moderate` capability, a permission) who is not the topic's
  author goes as the team's delivered answer with `siteAuthor { name, email, role }`: the name is the support user's display name (the
  brief's "<support user's name>", pinned by the red team), the email the moderator's own address and the role bbPress's display role
  ("Moderator", "Keymaster"). OmnaBase shows the answer as the member whose address it is, otherwise as "<name> on <host>". The plugin never
  tries to tell members apart.
- **The contract's spellings**: `replyVia: "site"`, the reference `topic-<id>` (replies `reply-<id>`). A 200 with the existing ticket is
  success (its number is kept); a 409 needs attention with OmnaBase's words.
- **Connect asks for `ticket.resolved` too** (`message.sent,ticket.updated,ticket.resolved`): OmnaBase's fan-out sends a resolve as
  `ticket.resolved`, and since round one a connect-made address hears only its own site's tickets. Sites connected before this round keep
  their two events until they connect again (development stage: no migration).
- **Closing on resolve** stays the administrator's switch (off by default, the brief): `ticket.resolved`, or `ticket.updated` whose change
  is the status `resolved`, closes the topic through `bbp_close_topic`. **No reopening**: read in bbPress 2.6, a closed topic takes replies
  only from moderators (its reply form is hidden from participants) and bbPress itself never reopens a topic on a reply, so a customer's
  follow-up after a resolve is a new topic; reopening would need a reply bbPress does not allow. Pinned here; round four may revisit.
- **A reply on a closed topic is posted `publish`** (bbPress would store it "closed" and hide it), by the support user.
- **A guest topic without an email address needs attention** ("no usable email address"), never sent. **No support user**: the receiver
  answers 503 (OmnaBase retries), never 410.
- **A topic edited between a lost answer and the retry**: the row keeps its idempotency key, OmnaBase answers `409 idempotency_mismatch`, and
  the row needs attention with a plain reason ("The post changed after a try whose answer was lost …"). **Retry** gives the row a new key:
  safe, because the site's reference (`topic-<id>`, `reply-<id>`) still makes a resend count once at OmnaBase. `409 idempotency_in_flight`
  waits like a 5xx.

**The parts, by the brief's names.** `Forum\Triggers`, `Forum\TopicSender`, `Forum\ReplySender`, `Forum\ReplyPoster`,
`Forum\SubscriberMails`, `Forum\Closer`, with `Forum\Settings` (the one option `omnabase_forum`), `Forum\Posts` (reading bbPress's posts and
the two post meta keys) and `Forum\PlainText`; `Forums` wires them beside `CustomerData`. `Admin\ForumView`, `Admin\ForumActions`, the
partial `forum.php`. `Outbox\Step` and `Rest\Refusal` are new; the post meta names live in `Options` (the echo marker `_omnabase_message_id`
is written in one file only, as the guard wants).

**Choices the brief left open.**

- **The outbox's jobs say what to do with a row** (`Outbox\Step`: send, wait, skip, attention), decided when the row's turn comes (review
  S3). A skipped row is `discarded` with its reason (kept seven days like sent rows), never "needs attention". Jobs also name what a row is
  about (the screen's new "About" column: a user's name or a topic's title) and receive OmnaBase's answer (`sent( $row, $answer )`: the
  topic keeps the ticket number).
- **The idempotency key** (table version 2, column `idempotency_key`): made once with the row (`obwp-` and 32 random characters) and sent on
  every try, but only by jobs that mark their call idempotent (topics and replies); the user link and the licence refresh are unchanged.
- **Hooks only queue** (hooks: named moments where a plugin's code runs): the six bbPress moments and `transition_post_status` into
  `publish` from any other status (edits, `publish` to `publish`, and deletions queue nothing). The queue keeps one waiting row per post
  (`forum_topic:<id>`, `forum_reply:<id>`). Cheap checks at queue time: published, in a marked forum, not filed already, not the plugin's
  own posting (a flag while it posts) or the support user's, guests switched on. Everything is checked again when the row goes.
- **The ticket**: the connection's first project; the author's address and display name (a guest's from bbPress's `_bbp_anonymous_email`
  and `_bbp_anonymous_name`), the name cut to 120 characters; the title as a one-line subject of at most 200; the first post as plain text
  (paragraphs as new lines, scripts and tags gone, entities decoded) of at most 50 000, the subject when it is empty; `channel: "api"`;
  `public` false only in a hidden forum.
- **A reply**: the topic's author's (the same user, or for a guest's topic a guest with the same address) is the customer's; a moderator's
  the team's; anyone else's is discarded. A reply whose topic has no ticket waits (five minutes at a time) while the topic's own row waits
  or needs attention, and needs attention after 3 days; a reply on a topic that was never sent (published before its forum was marked) is
  discarded: old topics are not filed by a reply.
- **The answer back**: `message.sent` with `direction: out`, no `external` (the site's own) and a ticket reference `topic-<id>`; the body is
  OmnaBase's HTML through `wp_kses_post` (its text through `wpautop` when there is no HTML), then the closing line as its own paragraph
  (`{agent}` the message author's name, `{workspace}` the event's workspace name); the title "Reply To: <topic>" as bbPress's form writes it.
  Before posting, a reply carrying the same message id in any status (trash included) makes the delivery a no-op. Two deliveries of one
  message at the very same moment could both post (OmnaBase retries only after a timeout); accepted.
- **The receiver's refusals**: a listener throws `Rest\Refusal` with its status; the receiver answers it, logs the delivery as "refused"
  (the screen: "Not done yet …, OmnaBase retries") and does not record it as handled, so the retry does the work. A topic trashed, deleted
  or unpublished: 409; no support user: 503; WordPress did not save the reply: 500.
- **Subscriber mails**: a one-off WP-Cron job `omnabase_forum_mails` with the reply's id (WP-Cron woken at the end of the request), which
  hands the reply to bbPress's own `bbp_notify_topic_subscribers` (it leaves out the author, anyone who cannot read the topic, and closed
  topics). Cleared on deactivation and uninstall.
- **The screen**: every forum (public, private, hidden; at most 200, by menu order then id) with its tick and "(public)" or "(hidden: not
  public)"; the support user among keymasters and moderators who can `moderate` (stored only then, else the earlier one stays); the closing
  line as plain text of at most 200 characters; guests on by default; a line when no support user is chosen. The outbox section adds "10
  topics waiting: OmnaBase's daily limit for new tickets, next try …" when OmnaBase's 429 names its daily limit (`api_tickets`).
- **Uninstall** removes `omnabase_forum`, both post meta keys for every post and the mail jobs; deactivation clears the mail jobs.

**Test adjustments (each with its reason in the test's comment).**

1. `ForumReceiverTest`, a closed topic: the red team's last line asked `bbp_is_reply_published`, which bbPress answers true only when the
   reply's topic is open too, so it is false for every reply on a closed topic (also those written before the close). The line now asserts
   what the test means, "visible like any other reply": bbPress counts it among the topic's shown replies.
2. `forum.spec.ts`, the site down: with the relay merely reset, the e2e server's webhook net falls back to its stand-in receiver, which
   answers 200 for any `*.test` host, so the answer read "Delivered". The site's host is now relayed to a loopback port nothing listens on.
3. Round one's `RoundOneTestCase::EVENTS` (and `ConnectFlowTest`'s description) name `ticket.resolved` as the third event: the coordinator's
   ruling, not a weakening; the link test still compares the link's events with the list.

**Open for round four.** Fixing a topic's body at its first try (so an edit between a lost answer and the retry cannot meet
`idempotency_mismatch`); reopening a closed topic if the founder wants follow-ups on resolved topics; topics published before their forum was
marked are never filed (only new publications are); "Send now" on the outbox; the shared connect and sign-in helpers of `facts.spec.ts` and
`forum.spec.ts` (the red team's note 10); the round-two leftovers (a registered user's earlier guest orders, License Manager's guest
licences, health lines for the facts calls and a disabled WP-Cron).

## 2026-10-09 — M13 round four: the shortcode form

The red team's tests came first (`docs/reviews/m13-round4-red-team-report.md`: 42 cases here in 5 files plus the helper
`RoundFourTestCase.php` and the replaced test harness with its "OmnaBase unreachable" switch; OmnaBase's 8 in its own repository; all copied
in as they were). Before: structure 7 of 7 failing, integration 29 of 29, the flow stopped at "[omnabase_form]" shown as text. After: all
green, none adjusted; one addition to the test harness (below). OmnaBase's side (the keyed form route without the hosted form's switch,
`GET /forms/{slug}`) is recorded in its DECISIONS "M13 round four: the shortcode form; the keyed form route". The words: *shortcode*, a tag
like `[omnabase_form]` that WordPress replaces with the plugin's output; *time token*, a one-time ticket the form's script fetches when the
page loads; *honeypot*, a field people never see and bots fill; *idempotency key*, a one-time id that makes a resend count once;
*transient*, a stored value with an expiry; *REST route*, an address under `/wp-json/…` a program calls.

**The coordinator's rulings, as built.**

- **The brief's numbers**: 5 messages an hour per visitor address and 25 an hour for the site (under OmnaBase's 30 per key and project); a
  token is taken from 3 seconds until 2 hours after it was handed out; OmnaBase's 403 and 404 read "This form is not available right now".
- **The outbox takes a network failure and any 5xx** (OmnaBase's proxy answers 502/503 while it switches versions), and OmnaBase still busy
  with the same key (`409 idempotency_in_flight`), the outbox's own reading of "may pass by waiting". **Never a 429**: "Please try again in
  an hour", not queued (queuing would step round OmnaBase's limit). A 400 reads "Your message could not be sent. Please write to us at
  <the site's administration address>" (the filter `omnabase_form_support_email` names another) and forgets the kept form settings.
- **The page never holds a token**: the form's script fetches one on load and only then enables Send. Without JavaScript the button stays
  off and a `<noscript>` sentence says the form needs JavaScript; a post without a token is refused with "This form has expired. Please
  reload the page and send again (the form needs JavaScript)."
- **Amending round two's "the outbox holds no customer data"**: a form message has nothing to be rebuilt from, so its outbox row
  (`form_submission`) carries the project and the body in a new `payload` column (table version 3) while it waits; the payload is emptied
  the moment the row is sent or discarded (rows themselves are purged after seven days as before). Every other kind still builds its body
  when it is sent.
- **A double click**: the same token with the same words answers the first answer again (no second call); with other words it is refused
  (409, "This form was sent already. Please reload the page to send another message."). The token is bound to its project.
- **The privacy link**: the project's `privacyUrl` from `GET /forms/{slug}`, else the site's privacy policy page, else none.
- **A proxy's address only through the filter `omnabase_visitor_address`** (the connection's address in, the address to count out; an
  answer that is not an address is ignored).

**The parts, by the brief's names.** `Forms\Shortcode` (the shortcode, the template `templates/form.php`, the script and stylesheet),
`Forms\Token` (the time tokens), `Forms\Guard` (the honeypot `omnabase_hp`, the limits, the visitor's address), `Rest\Form` (the two routes),
with `Forms\Projects` (which project), `Forms\Settings` (`GET /forms/{slug}`, kept), `Forms\Fields` (the checks), `Forms\Intake` (the order
of the checks), `Forms\Sender` (the immediate try, the outbox behind it), `Forms\Answer` (every sentence a visitor reads) and
`Forms\FormJob` (the outbox row); `Forms` wires them beside `CustomerData` and `Forums`. `Api\Client` gains `formSettings` and
`submitForm`; `Outbox\Table` gains `carry` and the payload.

**Choices the brief left open.**

- **The routes**: `GET /wp-json/omnabase/v1/form-token?project=<slug>` answers `{ token }` with `Cache-Control: no-store`, only for a project
  of the site's key (else 404, no token); its permission check is the limit of 30 tokens per 10 minutes per address (429). `POST
  /wp-json/omnabase/v1/form` takes JSON (`project, token, name, email, subject, message, product?, licence?, omnabase_hp`); its permission
  check is a connected site (503 otherwise). Answers carry `message` (the sentence), `reference` on success and `fields.<name>` for a wrong
  field, and are never cached.
- **The order of the checks** (`Forms\Intake`): the honeypot (filled: "Thanks. Your message was sent; we answer by email." and nothing goes,
  nothing counts), the project, the fields (each named; the token is kept so the corrected message can go), the token, the limits, then
  OmnaBase. A refused message counts towards no limit; a message refused by a limit burns its token (the answer is kept with it, so a
  repeat reads the same sentence).
- **The fields as OmnaBase checks them**: a name of 1 to 120 characters, a valid address, a subject of 1 to 200, a message of 1 to 20 000,
  a product of at most 60 and a licence of 4 to 200 when given; empty optional fields are left out. The product is not checked against the
  list (OmnaBase does; a stale list then reads "could not be sent" once and the settings are asked again).
- **The token** (`Forms\Token`): 32 letters and digits, kept as a transient under a hash of itself (`omnabase_form_t_…`) with its project,
  the time it was handed out and, once used, the message's fingerprint and the answer. The Idempotency-Key is `obwp-form-` and 40 characters
  of a hash of the token, so every try of one message (the immediate one, the outbox's, a double click) is one ticket.
- **The limits are transients** under a hash of the address with the site's salt (`omnabase_form_a_…`, `…ta_…`, `…site`), each a list of
  times within the window; not atomic, which a burst of parallel requests could overshoot by a few (OmnaBase's own limits stand behind).
- **The form's settings are kept an hour** (the brief), five minutes when OmnaBase could not be asked (the form then shows its four fields
  without product list or licence field), and forgotten when OmnaBase refuses a message as wrong.
- **The project**: the attribute (`project="<slug>"`, one of the key's), else the key's single project; otherwise only an administrator
  reads a notice naming the problem ("not connected", "not one of this site's projects", "several projects"), everyone else nothing.
- **A signed-in user**: name and email filled in, and the form carries WordPress's REST nonce (a short-lived token against forged
  requests) in `data-nonce`, only for a signed-in user, so a visitor's page is the same for everyone and a page cache may keep it.
- **The stylesheet** (choice 2): `bin/build-form-css.php` reads the light block of OmnaBase's `packages/ui/src/styles/tokens.css` (the
  OmnaBase repository beside this one, or `OMNABASE_REPO`), copies only the variables the form's rules use (and those they refer to) under
  `.ob-wp`, without the dark block and the kit's typeface, and puts the rules from `styles/form.css` after them into `assets/form.css`.
  Unlike the front page's copy the result is **committed**: a checkout without the OmnaBase repository (CI, the zip's build elsewhere) still
  has it; `bin/build-dist.sh` builds it first when the tokens are there. The filter `omnabase_form_styles` (false) leaves the form to the
  theme alone; the settings screen's switch "theme styles only" (Q4) waits for the shortcodes section (round five or six).
- **The script** (`assets/form.js`, vanilla, deferred in the footer): no key, no OmnaBase address; it calls the site's two routes only.
- **Uninstall** deletes every `omnabase_form_…` transient; waiting form messages go with the outbox's table.

**The builder's addition to the test harness** (`tests/wp-env/mu-plugins/omnabase-test-harness.php`, not a test): WordPress fixes the
content folder's address from `localhost:8888` before must-use plugins load, so plugins' scripts and styles pointed at
`https://localhost:8888`, which the browser's route does not take; the form's script never loaded in the flow. The harness now moves
`content_url` and `plugins_url` to the test host like every other link (WooCommerce's front-end scripts load in the flows too now).

**Left for later rounds.** A "Shortcodes" section on the settings screen (`[omnabase_form]`, round five's `[omnabase_messages]`) with the
"theme styles only" switch; the privacy-policy text naming form messages (round six).

## 2026-10-09 — M13 round five: the message centre

The red team's tests came first (`docs/reviews/m13-round5-red-team-report.md`: 50 cases here in 7 files plus the helper
`RoundFiveTestCase.php`: structure 7, integration 35 in the plain run, the flow 8; OmnaBase's 7 in its own repository; all copied in as they
were). Before (the report's run): structure 7 of 7 failing, integration 35 of 35, the flow stopped at its first test (no settings section)
with 7 not run. After: all green, none adjusted, no existing test changed. OmnaBase's side (this site's forum topics in the portal after
confirmation, a different address clearing the confirmation, an old link no longer confirming) is recorded in its DECISIONS "M13 round
five: the message centre; the portal's forum topics and a changed address". The words: *message centre*, the page where a signed-in
customer reads and answers their own tickets; *shortcode*, a tag like `[omnabase_messages]` that WordPress replaces with the plugin's
output; *site id*, the plugin's id for a WordPress user as OmnaBase knows them (`<user id>-<8 characters>`, round two); *confirm prompt*,
the box asking a customer to prove their address through a link OmnaBase mails; *nonce*, a short-lived form token against forged requests;
*hook*, a named moment where a plugin's code runs (`send_headers`: just before WordPress sends a page's headers; `template_redirect`:
before the page is built; `profile_update`: after a user was saved); *usermeta*, extra fields stored per user; *outbox*, the plugin's lasting
list of calls still to make.

**The coordinator's rulings, as built.**

- **The API wins where the prompt differed**: each view first sends `PUT /customers/by-external/<site id> { email, name }` (the user's
  current address and display name, nothing else), then every portal call names the visitor with `OmnaBase-Customer: <site id>`; the
  identity is always the current WordPress user, never the address bar, a posted field or a cookie (the structure guard: the one file that
  names the header, `Portal\PortalApi`, reads nothing from the request, and `src/Portal/` reads no `$_REQUEST` or `$_COOKIE`).
- **`guest="form"`** (the brief's): a visitor who is not signed in gets round four's contact form; without it, a login link whose return
  address is the message centre's page.
- **The brief's refusal sentences**: `409 address_taken` reads "This email address belongs to another account in our support. Please write
  to us by email.", `409 forgotten` "Your support history was deleted at your request. Write to us to start again."; nothing else is shown
  and nothing is read for a refused user.
- **The red team's choices stand**: the option `omnabase_portal` (`[ 'page' => <page id> ]`); three link requests a day per user, OmnaBase's
  own number, the fourth never leaving the site ("You asked for three links today …"); while OmnaBase says unconfirmed every view asks again
  (OmnaBase never tells the site when the link is opened); counts on a plain view in the tests are "at least one" (the harness boots the
  plugin twice; `Portal` registers its hooks once per request, as `CustomerData` does).
- **No attachments and no product select on New ticket**: the portal's create route takes `{ project?, subject, message }` only.
- **A WordPress site cannot hold two accounts with one address**, so the red team's second-account case needs no rule on this side.

**The parts, by the brief's names.** `Portal\Shortcode` (the shortcode and where it may show), `Portal\Controller` (the three form posts at
`template_redirect`, redirected back), `Portal\Views` (the template `templates/messages.php` with the model in words), `Portal\NoCache`
(`send_headers`), `Sync\ProfileWatcher` (`profile_update`); with `Portal\PickedPage` (the option, the request match, the page's address),
`Portal\PortalApi` (the six calls, the header), `Portal\Visitor` (the `PUT` and its fingerprint), `Portal\Centre` (the signed-in view's
model), `Portal\Requests` (New ticket, Reply, the link), `Portal\LinkLimit` and `Portal\Notices` (every sentence a customer reads);
`Portal` wires them beside `Forms`; `Admin\PortalView` and `Admin\PortalActions` are the settings section. `Forms\Projects` gains `idOf`.

**Choices the brief left open.**

- **The fingerprint** (the brief's "a stored fingerprint"): a hash of the trimmed, lower-cased address and the display name in the usermeta
  `omnabase_portal_fingerprint`, kept only while OmnaBase answered `confirmed: true` and only for a user the plugin counts as known (a new
  connection forgets every known user, and with them every fingerprint's effect). Same fingerprint: no `PUT`. A list that answers 404 (OmnaBase
  no longer knows the visitor) forgets it, so the next view tells OmnaBase again.
- **The New ticket form's token is printed in the page**, not fetched by a script as round four's form does (the coordinator's "reuse the
  form's token plumbing" not taken): the message centre's page is never cached, so a printed token is never shared, and its forms then work
  without JavaScript; round four's token also refuses a form sent within 3 seconds, which a returning customer's short answer can be. The
  Idempotency-Key is `obwp-portal-` and 40 characters of a hash of the token, the site id and the words, so the same form sent twice is one
  ticket and a fresh form (or other words) a new question. `assets/messages.js` only turns a form's button off after the first submit.
- **The posts**: the forms post back to the picked page itself with `omnabase_do` (`new_ticket`, `reply`, `confirm`), the nonce
  `omnabase_nonce` (action `omnabase_portal`), the token `omnabase_token`, round four's trap field `omnabase_hp` (New ticket; filled, nothing
  goes and nothing is said), `subject` and `message`, and for Reply the ticket number `omnabase_ticket`. A post without the nonce, or by
  nobody signed in, sends nothing. The redirect carries only what to show: `omnabase_ticket=<n>` and `omnabase_done=<notice>` (a code;
  unknown codes show nothing).
- **The address bar** may carry `omnabase_ticket` (only digits reach OmnaBase; anything else reads "not found" without a call),
  `omnabase_after` (OmnaBase's cursor, checked for its characters) and `omnabase_done`.
- **What a customer reads**: the list's status words (open "Open", pending "Answered", resolved "Resolved", archived "Closed") and the last
  change as a date with its year (`j M Y`, translatable); a thread's messages as text (an HTML-only message loses its markup; a message whose
  kind is not `you`, `team` or `other` is never shown); the refusals of a write in plain words (429 "could not be sent just now", 403 or a
  gone project "cannot be sent here right now … by email", 400 "could not be sent … check it"), never a plan, a code or OmnaBase's text; the
  link's sentence "We sent a link to a…@example.com. Open it within 24 hours." (the first character and the domain).
- **The no-cache marking matches the request, not the content**: at `send_headers` the picked page is recognised from WordPress's parsed
  request (`page_id`, `p`, `pagename`, or the static front page through WordPress's own Reading settings), then `nocache_headers()`,
  `Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0`, `DONOTCACHEPAGE` and `litespeed_control_set_nocache`. The
  shortcode on any other page, or with no page picked, shows a customer nothing (that page was never marked) and an administrator a note.
- **A changed address** (`Sync\ProfileWatcher`): `profile_update` with a new address (other letters or spaces are no change) forgets the
  fingerprint and, for a user OmnaBase knows, queues round two's `user_link` row (one waiting row per user), whose `PUT` is built from the
  user when it is sent; WooCommerce's account edit saves through the same function, so it needs no hook of its own. OmnaBase clears the
  confirmation, and the next view shows the prompt.
- **The stylesheet**: the message centre's rules join `styles/form.css` and the one build (`bin/build-form-css.php` → `assets/form.css`,
  the kit's tokens under `.ob-wp`), enqueued under the same handle and switched off by the same filter `omnabase_form_styles`; the prompt
  mirrors the kit's `PortalConfirm` (a bordered box, its title, one line, the button).
- **The settings section** (`.ob-wp-settings__portal`): the page select (published pages, at most 200, the current pick always listed),
  saved through `admin-post.php` with `omnabase_save_portal` (nonce and `manage_options`; anything but a published page stores none), and
  the shortcodes' help as a list (`[omnabase_messages]`, `guest="form"`, `[omnabase_form]`, its `project` and `subject`).
- **The repository guard's option scan**: WordPress's own Reading settings (`show_on_front`, `page_on_front`) are read through constants in
  `Portal\PickedPage`, since the guard counts every quoted option name as the plugin's own.
- **Uninstall** removes the option `omnabase_portal` and the usermeta `omnabase_portal_fingerprint` and `omnabase_portal_links`.

**Left for round six.** WooCommerce's My Account "Support" link (the brief's "when turned on", a switch on the screen); the "theme styles
only" switch (Q4); a refused New ticket keeps nothing of what was typed (the customer writes it again); the privacy-policy text naming the
message centre; the `.pot`.

## 2026-10-09 — M13 round six: ready to hand out

The red team's tests came first (`docs/reviews/m13-round6-red-team-report.md`: 36 cases in 11 files plus the helper `RoundSixTestCase.php`:
structure 12, integration 15 (plain run 10, shops run 5), the Playwright-run checks 7 (`release` 3, `fresh` 2, `uninstall` 1, `cron-health` 1);
OmnaBase's 2 in its own repository; all copied in). Before (the report's run): structure 9 of 12 failing (three guards pass by design), plain
integration 10 of 10, shops 5 of 5, `release` 3 of 3, `uninstall` 1 of 1, `cron-health` 1 of 1, `fresh` stopped at the missing config. After:
all green, one adjusted (below). No OmnaBase API change. The words: *Plugin Check*, WordPress.org's checker (an error blocks a listing, a
warning advises); *`.pot`*, the template translators start from; *text domain*, the name translations are filed under (`omnabase`);
*exporter* and *eraser*, the pieces WordPress's Tools › Export Personal Data and Erase Personal Data call; *uninstall*, what WordPress runs
when the plugin is deleted; *WP-Cron*, WordPress's built-in scheduler; *My Account*, WooCommerce's page for a signed-in customer.

**The coordinator's rulings, as built.**

- **The version lives in `package.json`, the plugin header and readme.txt's Stable tag** (and `OMNABASE_VERSION`, the changelog's top
  section): `package.json` now carries `"version": "0.1.0"`; `bin/check-version.sh` reads it, and composer.json's version when it declares
  one (it does not, as Composer advises).
- **The changelog stays `= 0.1.0 — unreleased =`**: the date goes on when the founder ships the first zip; the release build still refuses
  an undated section. readme.txt's "Changelog" carries the same lines under `= 0.1.0 =` (the date lives in changelog.txt alone).
- **The uninstall test runs against the development WordPress and the real e2e server** (`tests/e2e/uninstall.spec.ts`), WordPress's way
  (deactivate, then `uninstall_plugin()`, never deleting this checkout).
- **The fresh site is `.wp-env.fresh.json`** on 8890 (PHP 8.1, no plugins, `testsEnvironment: false`, only the harness's must-use plugin
  mapped, test host `fresh.omnabase.test`), so uploading and deleting the zip there can never touch the source.
- **An exporter and an eraser both** (`Privacy\Exporter`, `Privacy\Eraser`, reading through `Privacy\Holdings`). The exporter lists the
  user's site id, the "known to OmnaBase" mark, the message centre's link times and kept words, the outbox's open rows about the person (their
  account's user-link and licence rows, and form messages whose stored address is theirs, with the stored fields) and their forum topics'
  ticket numbers (as a member, or as a guest by bbPress's stored address). The eraser removes the site id and the known mark, and also the
  message centre's fingerprint, link times and kept words (personal data held per user, beyond the ruling's two, so an erasure leaves none);
  it **anonymises** the outbox rows: discarded, payload and subject emptied, the reason "the person's data was erased" kept. Forum topics keep
  their ticket numbers (a number alone is no personal data once WordPress or bbPress erased the author). Neither touches OmnaBase: the
  eraser's message and the privacy text say OmnaBase keeps its own copy, deleted in OmnaBase.
- **Plugin Check's two errors** (`suppress_filters => true` in the forum screen's and the forum posts' `get_posts`): both are `WP_Query` now
  (`no_found_rows`, filters run). On the built zip on 2026-10-09: **no errors, no warnings**.
- **The untranslated customer-data labels**: the shaper names "the oldest licences left out" with the key `licences_oldest`
  (`Shaper::OLDEST_LICENCES`), and the screen words every left-out section through `__()` (`profile`, `orders`, `subscriptions`, `licences`,
  "licences (the oldest)").
- **The kept words** (round five's leftover): a New ticket that OmnaBase refuses (or that cannot be sent, or whose form expired) keeps its
  subject and message in that user's meta `omnabase_portal_draft` for an hour, shown escaped in their New ticket form; a successful New
  ticket forgets them; a filled trap field or a refused user keeps nothing. Never in the address bar, never shown to another user.
- **The `.pot`**: `languages/omnabase.pot`, made by `wp i18n make-pot` in wp-env's cli container from what the zip ships (`bin/make-pot.sh`,
  also `composer i18n:pot` and `npm run pot`; the workshop's folders excluded), committed; 290 sentences on 2026-10-09.

**The parts.** `Privacy` (the wiring, once per request) with `Privacy\PolicyText`, `Privacy\Holdings`, `Privacy\Exporter`, `Privacy\Eraser`;
`Forms\Display` (the option `omnabase_display`: "theme styles only" and the My Account link); `Portal\MyAccountLink`; `Portal\Draft`;
`Admin\ShortcodesView` and `Admin\DisplayActions` (the Shortcodes section, `partials/shortcodes.php`); `Admin\HealthNotes`;
`Facts\ShopVersions`; `bin/make-pot.sh`; `.wp-env.fresh.json`. `Outbox\Table` gains `sendNow`, `openOf` and `anonymise`, `Outbox\Queue`
gains `sendNow`, `Admin\DataActions` the action `omnabase_outbox_send_now`.

**Choices the brief left open.**

- **The Shortcodes section** (`.ob-wp-settings__shortcodes`, its own h2, after the message centre): both shortcodes and each attribute
  (`project`, `subject`; `guest`, `project`) in one line each, then one form with the two front-end switches and one Save. The switches live
  there, not in the message centre's page form: that form's select (`omnabase_portal[page]`) would be sent along with every switch change,
  and the two are saved apart. The My Account switch is offered only while WooCommerce is active; saved without WooCommerce, its stored value
  is kept.
- **"Theme styles only"**: off by default; on, neither `[omnabase_form]` nor the message centre queues `assets/form.css`. Round four's filter
  `omnabase_form_styles` still has the last word (its default is now the switch).
- **The My Account link**: the menu item `omnabase-support` reading "Support", placed before "Log out", whose address
  (`woocommerce_get_endpoint_url`) is the picked page's permalink; nothing while the switch is off or no published page is picked.
- **Round five's landing after a new question changed**: a successful New ticket now leads back to the list (the thanks notice, the new
  question on top, an empty form), no longer to the new thread; the red team's kept-words test pins an empty form after a success, and round
  five's own tests only ask that the subject shows.
- **"Send now"** on every waiting row of the outbox (rows needing attention keep Retry and Discard): due at once, its tries and idempotency
  key kept (a resend counts once), and the one-off run woken; the nonce and `manage_options` as every action.
- **Health**: a line "Last customer-data call from OmnaBase: <time>." (or "No customer-data call from OmnaBase yet.") from the facts log,
  and while `DISABLE_WP_CRON` is set a warning that WP-Cron is turned off and a real cron job must call wp-cron.php every minute.
- **"Unsupported version"** beside the source's switch in the customer-data section, with the installed and the needed version. The minimums
  (the oldest versions whose public functions the sources use): WooCommerce 8.2, WooCommerce Subscriptions 5.0, Easy Digital Downloads 3.0,
  EDD Recurring 2.11, EDD Software Licensing 3.8, License Manager for WooCommerce 2.2. A version the plugin cannot read (the fakes publish
  none for the EDD add-ons) is not judged.
- **The privacy text** (`wp_add_privacy_policy_content` at `admin_init`, under "OmnaBase"): a note for the owner, then sentences naming
  OmnaBase as Kriesi Media GmbH's service and the site's processor, forum topics and replies, form messages, the message centre, customer data
  and licence changes, what the site receives, and OmnaBase's privacy policy. **The legal addresses** are OmnaBase's own pages,
  `https://app.omnabase.com/legal/privacy` and `/legal/terms` (served by OmnaBase's API since its legal round seven), in `Api\ApiUrl`
  beside the pinned host (the guard keeps web addresses there) and in readme.txt, whose placeholders are gone.
- **Uninstall's `DELETE /me`**: first, before anything is removed, through the plugin's own autoloader and `Api\Client` (the plugin is not
  loaded during uninstall), only when the vault holds a readable key; whatever OmnaBase answers, the removal goes on. A never-connected site
  makes no call. Uninstall also removes the option `omnabase_display` and the user meta `omnabase_portal_draft`.
- **readme.txt**: the description, a Shortcodes subsection, installation, seven FAQs, "External services" per piece (what is sent and when,
  what comes back, nothing before you connect, the terms and privacy links), "Privacy" (what is stored on the site, the export and erase
  tools, OmnaBase's own copy), the changelog.

**Test adjustments (each with its reason in the test's comment).**

1. `cron-health.spec.ts`: a 2.5-second wait after each wp-config.php change before the page view. wp-env's WordPress container keeps
   compiled PHP for 2 seconds (`opcache.revalidate_freq = 2`), so in the full suite the page right after removing `DISABLE_WP_CRON` still ran
   the old wp-config.php and showed the warning; alone it passed. The assertions are unchanged.

**Left for later** (see "M13: done" for the whole list): nothing of round six's own.

## 2026-10-09 — M13: done

M13 (OmnaBase's brief `docs/briefs/m13-wordpress-plugin.md`, draft 2, the founder's Go with the defaults on 2026-10-09) is built: a round
zero (the workshop) and six rounds, in this repository, with OmnaBase-side changes in rounds zero, one, three, four and five (recorded in
OmnaBase's DECISIONS, "M13: done" there points here). Red-team tests over the six rounds: 370 in this repository (68, 118, 56, 42, 50, 36)
and 31 in OmnaBase (rounds one to six: 8, 0, 6, 8, 7, 2); all green; in this repository eight were adjusted, each with its reason
in its round's entry. Not deployed anywhere, not released: the first zip waits for the founder's test.

**What the plugin does** (version 0.1.0, PHP 8.1+, WordPress 6.8+):

- **Connect** from Settings › OmnaBase in one approval (PKCE, one server-side exchange, no key to copy); the key and both secrets encrypted in
  the vault; two signed receivers (`/wp-json/omnabase/v1/events`, `/customer`); Check connection both ways; Disconnect stops the key.
- **Customer data on every ticket** from the sources the owner switches on (the profile with picked user fields, WooCommerce and EDD orders,
  WooCommerce Subscriptions and EDD Recurring, EDD Software Licensing and License Manager licences), shaped to OmnaBase's facts contract,
  keys masked; licence changes refresh OmnaBase's copy through the outbox.
- **bbPress forums**: topics in marked forums become tickets, the author's and moderators' replies reach them, the team's answers come back
  as forum replies by the support user, optionally closing the topic on resolve.
- **`[omnabase_form]`**: the contact form with the project's products, licence field and privacy link, a time token, a honeypot and limits.
- **`[omnabase_messages]`**: the message centre on the picked page (never cached), the visitor always the signed-in user; the confirm prompt
  before mail conversations and forum topics show; a "Support" link in WooCommerce's My Account when switched on.
- **The outbox** for every call that matters, worked by WP-Cron every minute, never dropping a row (Send now, Retry, Discard).
- **Ready to hand out**: the readme, the privacy text, an exporter and an eraser, every string translatable with the `.pot`, Plugin Check
  clean on the zip, uninstall removing everything after a best-effort `DELETE /me`, the zip proven on a clean WordPress.

**The founder's test on a real site** (the brief's last step; the plugin's flows only prove wp-env against OmnaBase's e2e server):

1. Deploy OmnaBase first: the plugin needs M12's API and M13's OmnaBase-side changes (`DELETE /me`, `/me/ping`, `siteAuthor`, the keyed form
   route and `GET /forms/{slug}`, the portal's forum topics and the cleared confirmation), all built and not yet deployed.
2. A real WordPress with a **public https address**, at the domain's root, pretty permalinks, and **PHP 8.1 or newer** (Tools › Site Health ›
   Info › Server shows the PHP version; check before installing).
3. Build the zip here (`bin/build-dist.sh`; the development zip has the release's files), upload it under Plugins › Add New Plugin › Upload,
   activate, open Settings › OmnaBase and Connect to Team Kriesi (or a test workspace).
4. Try each piece once: Check connection both ways; switch on a customer-data source and open a buyer's ticket; mark a forum, post a topic as a
   customer, answer it in OmnaBase and see the reply on the forum; put `[omnabase_form]` on a page and send; pick a message-centre page, sign
   in as a customer, ask a question, confirm the mailed link; read Settings › Privacy › Policy Guide; export a test customer's personal data.
5. Deactivate and delete the plugin: the site's key reads as revoked under Settings › Integrations › API keys in OmnaBase.
6. Then the release: date the changelog's section (`= 0.1.0 — <date> =`), `bin/build-dist.sh --release` (checks PHP 8.1 syntax in wp-env)
   gives `build/dist/omnabase-0.1.0.zip`; copy the dated heading's lines into readme.txt's Changelog if they changed.

**Open items for later** (none blocks the founder's test):

- From round two: a registered user's earlier guest orders are not shown; License Manager's guest licences are not read; an administrator's
  edit inside License Manager alone refreshes nothing until the order changes (License Manager 3.0.20 fires no event for it).
- From round three: a topic edited between a lost answer and the retry meets `idempotency_mismatch` (Retry fixes it; fixing the body at the
  first try would avoid it); closed topics are never reopened (a follow-up is a new topic); topics published before their forum was marked are
  never filed; two deliveries of one message at the same moment could both post.
- From round four: the form's limits are transients, not atomic (a burst could overshoot by a few; OmnaBase's own limits stand behind).
- The browser flows: the shared connect and sign-in helpers of `facts.spec.ts` and `forum.spec.ts` are still separate; wp-env's tests site
  on 8889 moves to `--config .wp-env.tests.json` once wp-env stops starting it by default; the e2e server shares OmnaBase's port 3011.
- Out of scope by the brief: Gutenberg blocks, multisite, the WordPress.org submission, other forum plugins, attachments, translations beyond
  the `.pot`, an update server.

**The paid add-ons' fakes**: WooCommerce Subscriptions, EDD Recurring and EDD Software Licensing are proven only against the fakes in
`tests/fakes/` (loaded by the wp-env harness when the real add-on is absent, never shipped). When the founder drops the real zips into
`paid/`, map them in `.wp-env.override.json` (git-ignored), and run `composer test:integration` and `npx playwright test tests/e2e/facts.spec.ts`
against them; the sources read only the add-ons' public functions, and "unsupported version" names any that is too old.

## 2026-10-10 — A local loop for clicking through by hand

The founder asked whether the connector works on the local wp-env WordPress. Against production it cannot: the plugin connects only a site
on https at port 443 (SiteCheck) and OmnaBase makes the key only for a site it can call back from the internet (the exchange's reachability
check); a local site would need a public https tunnel, which is not built. Against OmnaBase's own e2e stack it works the way the browser
flows prove it, so `bin/local.sh` (up / browser / stack / check / code / status / down) and `bin/local-browser.ts` wrap exactly that: wp-env,
the seed, the e2e stack on 3011 and 4391, the relay, and a headed Playwright Chromium whose route makes `https://wp.omnabase.test` reach
wp-env (no port 443: LocalWP's router holds 80 and 443; no certificate; the Mac's hosts file untouched), with the e2e server's captured mails
printed in the terminal. Development-only scripts under `bin/`, never in the zip; the profile under `tests/e2e/.auth/` (git-ignored).

## 2026-10-10 — M14 Settings cleanup: ordered (rounds one and two here)

The founder's order and the brief live in the OmnaBase repository (`docs/briefs/m14-settings-cleanup.md` draft 2; DECISIONS "2026-10-10 —
M14 Settings cleanup: ordered"). Here: round one (five tabs, one save per settings tab, the status line with Details and Advanced, the
fourth state "Disconnected in OmnaBase", the plain words under one banned list, the setup line, the Settings link) and round two (the
shortcode builder dialog with Copy and "Create the page for me" published at once, the first-display marks, the Activity count and the
menu bubble). Defaults the founder took: publish at once; the bubble yes; no daily site report. A Gutenberg block stays a later option:
two tags do not justify a build step and a block-editor dependency, and the builder serves classic and block editors alike.

## 2026-10-10 — M14 round one: tabs and words

Built: Settings › OmnaBase in five tabs (`?page=omnabase&tab=connection|data|forums|pages|activity`, WordPress's `nav-tab` row, only the
open tab built and printed; `Admin\Tabs` holds the list, the open tab and the row), one save form with one primary "Save" per settings tab
(`omnabase_save_facts`, `omnabase_save_forum`, `omnabase_save_pages`; the old portal and display handlers merged into `Admin\PagesActions`),
every save and action back on its tab (`Guard::backToScreen( $tab )`), the status line (`Admin\StatusLine`) in the kept
`ob-wp-settings__health`, the "Details" and "Advanced" blocks, the permissions in words (`Admin\PermissionWords`), the fourth state
`revoked` ("Disconnected in OmnaBase"), the setup line (`Admin\SetupLine`), the Activity tab in words with its count and the menu bubble,
the Plugins list's Settings link (`Admin\PluginLinks`) and the header's "Set it up under Settings › OmnaBase.", readme, changelog and `.pot`.
No `admin.js`: the 3-second reload while a check waits and the Disconnect confirmation stay inline. None of the red team's pinned names was
renamed (report "Shapes pinned").

Rulings (the coordinator's, recorded here):

- **Disconnected in OmnaBase pauses everything.** OmnaBase's 401 to any call made with the key (only a missing, wrong or revoked key gets
  one: `docs/public-api.md`) marks the vault; from then on the client makes no call with the key at all (the screen's `GET /me`, Check
  connection, the outbox, the form, the message centre) until Connect saves a new key or Disconnect clears the vault. The mark lives inside
  the vault's own option beside the key, so it can never outlive the key it is about, and no new option exists for it. **The outbox pauses
  too**: a row that meets the 401 goes back to waiting, due at once, its tries untouched (`Policy::paused()`), the run stops at once, and the
  timer's later runs return before trying anything; after Connect the rows go with the next run (`RevokedOutboxTest`, the builder's test of
  this ruling). A form message sent meanwhile waits like one sent during an outage. OmnaBase reporting that the site's own receiver answered
  401 (inside a 200 ping answer) never counts: that is the site's side, not a revoked key.
- **The Forums step is left out of the setup line where bbPress is not active** (the Forums tab stays and says what to install); with bbPress
  it is the third of four steps.

Decided where the brief left it open:

- **One "Details" on the Connection tab**, inside the status block: what the key may do (in words), its last four, OmnaBase's own words when
  it did not answer, the three directions with their `data-check`/`data-result` marks, the last customer-data call. The brief names one
  disclosure, and the red team's `openDetails()` in `connect.spec.ts` and `settings-tabs.spec.ts` clicks the closed ones by position (a
  second one shifts the positions and the flow waits for ever). The connection box keeps, visible, "Connected to …", the site's name in
  OmnaBase (`round-trip.spec.ts` reads it) and "Messages go to: Enfold" (`connect.spec.ts` reads the project).
- **Which states show what**: the status block in the connected and the "reconnect needed" states (as before, minus the new revoked state,
  whose last check means nothing); the "Advanced" block in every state, last on the tab; in the revoked state only the Connect button (no
  Check connection, no Disconnect: there is nothing to check, and OmnaBase already stopped the key).
- **The setup line**: Connect is done while the vault holds a key OmnaBase has not refused; Customer data and Forums once their tab was saved
  (their option exists); the form step once `omnabase_display['first_shown']` holds a mark (round two writes it; saving the switches keeps it).
  Dismiss stores `omnabase_setup` (`Options::ALL`, `uninstall.php`). Each step's link has the accessible name "Setup step: …", so it never
  shares a name with the tab of the same words (`settings-tabs.spec.ts` finds the tabs by exact name).
- **The tab address**: every tab link names its tab, Connection included (the tests read the slug from each link); `Guard::backToScreen()`
  always names the tab; the return address Connect registers at OmnaBase stays the bare `options-general.php?page=omnabase`.
- **The Activity count**: one small `COUNT(*)` of rows needing attention per admin page for the menu's bubble (WordPress's `awaiting-mod`
  markup after an unchanged "OmnaBase", the number repeated for screen readers); the settings screen's tab row counts once more on its own
  view ("Activity · 2"), because the tests (rightly) expect a fresh number after rows change within one request.
- **Plain words outside the screen's text**: an outbox row's last answer (it carries OmnaBase's status number, such as 409) sits behind a
  per-row "Details"; delivery events in words with "Something else" for an event the plugin does not know; the deliveries section is always
  printed (with "Nothing yet."), its class on the wrapper instead of the table; the permalinks refusal reads "Connect needs page addresses
  other than "Plain": …"; the check rows' messages say "test message" for the ping; the permissions read "Read and write tickets", "Read
  and write customers", "Send contact forms", "Run the message centre" (an unknown code is shown as it is).
- **The guest choice** is two radio buttons, "Members only" (`0`) and "Guests too" (`1`); the stored yes/no is unchanged.

Tests adjusted by the builder (each with the reason in its comment):

- `ShortcodesSectionTest::test_the_theme_styles_switch_is_offered_and_off_by_default` and `MyAccountLinkTest::test_off_by_default_no_support_link`:
  the red team read the switch "with its form" from the Shortcodes block's own HTML, but the tab's one save form wraps the whole block
  (pinned by `PagesTabTest`), so that excerpt can hold no form. The switch is now read with its form from the whole tab, and a second
  assertion pins it inside the Shortcodes block. Not weaker: both facts are still asserted.
- `ForumsTabTest::test_labels_say_what_each_setting_does`: the forum run's fixture leaves `guests` unset, which means "guests too" (M13's
  decided default), so "Members only" could not be the picked choice the test expected. The test stores guests off first, the state its
  assertion describes; every assertion is unchanged.
- `round-trip.spec.ts`: the menu link is found inside the admin menu by a name starting with "OmnaBase" (the red team's point 11: the count
  bubble extends the link's accessible name); its address is checked as before.

Left for round two (as ordered): the shortcode builder dialog with Copy and "Create the page for me", the `<noscript>` fallback, recording
the first-display marks when a tag renders.
