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

## 2026-10-10 — M14 round two: the shortcode builder and the marks

Built against the red team's failing tests (`docs/reviews/m14-round2-red-team-report.md`), copied in unchanged; none adjusted, none of the
pinned names renamed. Brief: OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2, choice 2 and "Order of work › Round two".

What was built: "Build a shortcode" in the Shortcodes block opens the browser's own `<dialog class="ob-wp-builder">`, which sits after the
Forms and messages tab's save form (never inside it) and holds "Create the page for me" as its own form (`omnabase_create_page`, its own
nonce: a short-lived form token). `assets/admin.js` (plain JavaScript) keeps the read-only tag in step with the choices, strips `"`, `[` and
`]` from the subject as it is typed, copies with the browser's clipboard (falling back to a selected field), and closes on Escape or Close
with the keyboard back on the button. Without JavaScript the button stays hidden and the six lines of round six show inside `<noscript>`.
New small classes: `Admin\ShortcodeTag` (the tag's pure rules, the same as the script's), `Admin\BuilderChoices` (the posted choices,
checked), `Admin\PageMaker` (the page), `Admin\BuilderActions` (the thin handler), `Admin\TaggedPages` (the offer of an existing page),
`Admin\BuilderView` (the dialog's facts), `Admin\AdminAssets` (the script and its small stylesheet), `Forms\FirstShown` (the marks); the
templates `partials/builder.php` and `partials/forget.php`. No new option: the marks live in `omnabase_display` (already in `Options::ALL`
and `uninstall.php`), the flash in the existing notice transient.

The coordinator's rulings on the open points (recorded here):

- **(a) An existing page is offered first.** When a published page the person may edit already carries the tag, the dialog shows
  "“<title>” already shows the contact form / the message centre" with the title linked to its editor, and its primary action becomes
  "Use this page"; "Create the page for me" turns into a grey "Create a new page anyway". For the contact form, "Use this page" opens that
  page's editor; for the message centre it picks the page, by setting the tab's own page picker and sending the tab's save form (the same as
  choosing it and clicking Save, so no extra handler). The page is rendered with "Create the page for me" primary (the tests pin that
  shape); the script swaps the words and the colour for the chosen kind. The server still makes a new page when Create is posted with an
  existing one: that is "Create a new page anyway".
- **(b) Unknown values are refused, never dropped.** A `kind` other than `form` / `messages`, a `guest` other than empty / `form`, a field
  that is not plain text, or a project not among the key's slugs makes no page, and the next view says "The builder sent something this site
  does not know: try again." A guest choice posted with the contact form, or a subject posted with the message centre, is a known value of
  the other kind and is ignored.
- **(c) Several projects and none chosen is refused**, with a notice naming the projects ("This site has several projects (enfold,
  omnalingo): choose one in the builder."). Decided here: **for both kinds**, not only the message centre, since a contact form without a
  project on such a site shows visitors nothing (only the administrators' note). The project select offers "Choose a project" first, so the
  owner chooses on purpose.

Decided where the brief left it open:

- **The script is queued at `admin_enqueue_scripts`**, comparing the page's hook name with the screen's own
  (`get_plugin_page_hookname()`), not inside the screen's load hook as the round's order put it: the red team's tests fire WordPress's
  moment for admin scripts with `settings_page_omnabase` and expect it there (and the structure guard looks for `admin_enqueue_scripts`), and
  the effect is the same, the files load on Settings › OmnaBase alone. A small `assets/admin.css` styles the dialog's frame, the two cards and
  the offer; everything else is WordPress's admin look.
- **The page's content** is the tag alone inside the block editor's shortcode block (`<!-- wp:shortcode -->`); the classic editor shows the
  bare tag. Titles "Support" (message centre) and "Contact support" (form), published at once (the founder's answer to question 1), written by
  the person who clicked. The subject is cleaned on the server as in the browser (`"`, `[`, `]` removed, one line, at most 150 characters).
- **Who may use Create**: `manage_options` and `publish_pages`. A person with the settings permission alone sees the dialog, the tag and
  Copy, but no "Create the page for me" button; a post by them is refused (WordPress's own "not allowed" page).
- **The offer** shows one page per kind: for the message centre the picked page first when it qualifies, else the newest published page
  carrying the tag; only published pages, only pages the person may edit (`edit_post`), looked up among the 20 newest pages whose content
  mentions the tag (WordPress's search limited to the content).
- **The notice after Create** names the page ("“Support” is published and is now the message centre page.") with an "Edit the page" link;
  `Flash` now carries at most one link beside its words.
- **The dialog's message centre line** shows the page picked when the dialog opens (read from the tab's picker, so an unsaved change shows
  too), or "none yet".
- **The first-display marks**: the form and the message centre announce each real render through the internal action `omnabase_shown`
  (`form` / `messages`), and `Forms\FirstShown` notes the first one, once, keeping the two switches. An action instead of a new constructor
  argument: the message centre's shortcode already takes six. Not counted: a note only administrators see (site not connected, unknown
  project, message centre on another page), a render inside wp-admin, inside a request for WordPress's own API (the block editor loads a
  page's content that way) or a preview of a draft. Once set, later views read the mark and write nothing. The message centre with
  `guest="form"` shows a visitor the real contact form, so it sets the form's mark too.
- **"Forget this connection"** (the coordinator's ruling after round one): in "Disconnected in OmnaBase" a grey button beside Connect
  (also when the site cannot connect right now, where Connect is replaced by the reason) posts Disconnect's own action. Disconnect now makes
  no call at all when OmnaBase had ended the connection, clears what the site kept, and says "This site forgot the connection OmnaBase had
  ended." (no advice to revoke a key OmnaBase already stopped). No confirmation prompt: nothing live is lost.
- **Readme**: the Shortcodes part opens with "Build a shortcode" and "Create the page for me", a new FAQ answers "How do I put the contact
  form or the message centre on a page?", and the "disconnected in OmnaBase" answer names "Forget this connection". Three changelog lines
  under `= 0.1.0 — unreleased =`. `.pot` regenerated (363 entries).
- **Later option (the brief's alternative, rejected for now)**: a block for each tag in the block editor. It needs a build step and the
  block editor; the builder serves classic and block editors alike.

## 2026-10-10 — M14 round four: the screen's look

The founder's order, after seeing the five tabs in WordPress's default admin look: "still horrible design. Use a styling similar to the
OmnaBase settings where you render the settings nicely in white background cards, and use the canonical tab styling we have in OmnaBase,
in the WP backend." The red team's failing tests (`docs/reviews/m14-round4-red-team-report.md`) came first; all of them pass unchanged.

The coordinator's rulings:

- **The kit's spelling wins**: the card is `section.ob-form__section` with `div.ob-form__head` › `h2.ob-form__title` and `div.ob-form__intro`
  (OmnaBase's `FormSection`), not the order's `ob-form-section`. Every other reused name is the kit's own: `ob-tabs ob-tabs--line`,
  `ob-tabs__item` (`--active`), `ob-tabs__count`, `ob-btn` (`--primary`, `--outline`, `--sm`), `ob-form-row` and its parts, `ob-help-list`,
  `ob-more`, `ob-page__title`/`__subtitle`, `ob-dialog`, `ob-switch`, `ob-dtable`/`ob-dtable-card`, `ob-settings__cards`. New names carry
  the plugin's prefix (`ob-wp-settings__…`).
- **Every `<details>` is a kit More** (Details, Advanced, "Choose user fields", an outbox row's details).
- **"Use this page" and Dismiss are outline buttons**; the primaries are Save, Check connection, Build a shortcode, Create the page for me,
  and Connect while the site is not connected.
- **WordPress's button classes and `submit_button()` leave the admin templates** with the tab and table classes; every button is a plain
  `<button>` (or a link) with the kit's classes. The forum's Save keeps its field name.
- **A changelog line** for the new look (under `= 0.1.0 — unreleased =`).
- **No typeface shipped**: the kit's typeface is a placeholder the founder has not decided; the screen keeps WordPress's system font and no
  `--ob-font` variable is copied.
- **No dark mode this round**: only the kit's light tokens are built in.
- **`CLAUDE.md`** now describes the built admin stylesheet instead of "WordPress's own admin styles … no build step for the admin".

Decided here:

- **One build script for both stylesheets**: `bin/build-form-css.php` became `bin/build-css.php` and builds pairs (`styles/form.css` →
  `assets/form.css` under `.ob-wp`, `styles/admin.css` → `assets/admin.css` under `.ob-wp-settings`), both by default or one by name
  (`php bin/build-css.php admin`). Same rules as before: the light block only, only the variables the rules use (with the ones they refer
  to), a header naming the script, the tokens and the source. `bin/build-dist.sh` runs it before staging. `styles/` is not shipped (the zip
  carries the built files). The release test's fixture list in `RoundSixStructureTest` names the new script (a path in a copy list, no
  assertion changed).
- **The page is white, like the kit's** (`--ob-bg-page` is `#ffffff`): the wrapper takes the page token and fills WordPress's content area
  (it cancels WordPress's left padding and the `.wrap` margins), so the white cards with their gray border sit on a white page as in
  OmnaBase's settings picture, beside WordPress's dark menu. Below a short page on a tall admin menu, WordPress's gray shows under the white
  area, where WordPress's footer sits; the stylesheet does not reach outside the wrapper to paint it.
- **The kit's rules are copied, then adapted** in the same file under a second rule where WordPress's admin styles would win: the tabs are
  links (each tab is its own address), so they lose a link's colour and underline; the strip's hairline is an inset shadow on the strip (the
  kit draws it on a parent, and the strip scrolls sideways, which would clip the kit's `margin-bottom: -1px`); buttons reset a `<button>`'s
  font and margin; `p` takes the wrapper's size (WordPress sets 13px on every paragraph); WordPress's tick box gives way to the kit's switch.
  Not copied: the kit's `.ob-form__section + .ob-form__section` margin (the column's gap spaces the cards, as in the kit's
  `ob-settings__cards`) and the kit's `.ob-dtable` variable overrides (only the root block may set variables).
- **The switch for on/off settings**: the sources, "Theme styles only", "My Account menu" and "Resolved tickets" are the kit's `ob-switch`
  (a tick box drawn as a switch, `role="switch"`), each its own row. Lists of ticks (the forums to send, the user fields) keep WordPress's tick
  box in a two-column list (one column on a phone): the kit's own tick box draws its mark from an image, and the stylesheet loads none.
- **Rows**: each source a row (its name as the label, "(unsupported version)" inside the label so the warning stays on the source's line);
  the forum's four settings rows with their old help lines; "Who may send topics" names its two choices by `aria-labelledby`; the message
  centre's page a row with the shortcode in its help; the two front-end switches got short labels ("Theme styles only", "My Account menu")
  with their old sentences as help.
- **Cards per tab**: Connection: Set up (when steps are open), Connection, Status (when connected), "Stored connection" with Advanced;
  Customer data: Customer data (the sources), User fields; Forums: Forums; Forms and messages: Message centre, Shortcodes; Activity: On its way
  to OmnaBase, What OmnaBase sent here. Save sits under the cards, left, as a primary button.
- **The page head**: "OmnaBase" and one line, "Connects this site to your OmnaBase workspace: customer data for tickets, forum topics, the
  contact form and the message centre." A hidden `wp-header-end` line after it, so notices other plugins print land under the head and not
  inside it.
- **WordPress's notices** (the development addresses, the message after an action) stay WordPress's, under the tab strip and above the
  cards, as wide as the cards. The timer warning stays a WordPress notice inside the Status card.
- **The setup line**: a card with a numbered list; an open step shows its number in a circle (drawn by the stylesheet), a done step a filled
  circle with the tick, its words muted; Dismiss is a small outline button beside the heading.
- **State dots**: the status line, "Connected to …" and the red lines ("Disconnected in OmnaBase", "OmnaBase: reconnect needed") carry a dot.
  The kit has no success or danger token (its interface is monochrome), so the dots use the kit's own `--ob-green-500` and `--ob-red-500`
  (amber while a check waits, the strong border before the first check).
- **Tables**: the kit's `ob-dtable` inside `ob-dtable-card`; the outbox and deliveries tables keep a 640px minimum, so on a phone their card
  scrolls sideways instead of crushing the columns. The tab count is the kit's `span.ob-tabs__count` (no "·" any more).
- **The dialog**: the kit's dialog (head, body, a gray foot holding Create and Close), the overlay the kit's dim (`--ob-scrim` at 28 %).
- **The More's chevron**: the summary hides the browser's triangle and draws a chevron that turns down when open (the kit's More keeps the
  browser's triangle; the red team's flow asks for a drawn one).

## 2026-10-11 — Sites in a subfolder; a multisite's sub-sites

The founder (2026-10-10): "isn't that a bug? A user installing his WordPress in a subfolder could not use it." The coordinator's design: a
site's address becomes its home address with its folder (`https://example.com/blog`); OmnaBase (fixed in parallel) calls
`<address>/wp-json/omnabase/v1/events` and `/customer` and sends the administrator back only under the address. On a *multisite* (a
WordPress network: several sites sharing one installation) each site connects on its own. The red team's failing tests
(`docs/reviews/subfolder-sites-red-team-report.md`) came first; all of them pass unchanged.

What changed:

- **The site's address.** `Connect\SiteCheck::base()` replaces the host-only `origin()` (gone): the home address with the scheme and host in
  lower case, port 443 dropped, doubled slashes collapsed, no trailing slash, the folder's letters and encoding as written. The connect
  link's `site`, the exchange's `site` and the kept connection's `site.url` carry it (the kept address is OmnaBase's answer, the sent base
  when the answer names none); the link's name still falls back to the host alone.
- **The refusals.** `problem()` refuses only: no https or another port than 443; "Plain" permalinks; a REST address (the `/wp-json/`
  address WordPress answers programs at) that is not `<address>/wp-json/` after the same normalising, so a doubled slash from a home address
  stored with a trailing slash is accepted and index permalinks, a renamed prefix, another host or REST at the root of a folder site are
  refused ("OmnaBase cannot reach this site at its address: …"); and a way back outside the address ("Connect needs the WordPress admin under
  this site's address: …"). No refusal speaks of a subfolder; the old sentence is gone from the code and the `.pot` (371 entries).
- **The way-back check reads the settings page's own address** (`SettingsScreen::url()`, the `return` the link sends), not `admin_url()`:
  outside an https request (WP-CLI, the test layer) `admin_url()` answers http even on an https site, which refused every site in the
  integration run; the settings page's address follows the home address's https exactly as the link does. Home at `/blog` with WordPress at
  `/wordpress` is refused as pinned (contingent on OmnaBase's way-back rule, as the red team noted).
- **The five notes that were shared across a network are per site now**: the "known to OmnaBase" mark, an administrator's pending Connect,
  the message centre's fingerprint, its confirmation-link times and a refused question's kept words. User meta (notes on a user) is shared
  by every site of a network, so site B read site A's marks, a new connection on A wiped B's, and a network administrator connecting A then
  B lost A's pending Connect.
- **Decided: WordPress user options, through one small class `UserNotes`** (beside `Options`), not site options. A user option is user meta
  stored under the current site's table prefix (`wp_omnabase_known` on a single site and the main site, `wp_2_omnabase_known` on site 2);
  `get_user_option`, `update_user_option` and `delete_user_option` follow `switch_to_blog()`. Site options were the other choice: a list of
  known user ids or a per-site map would grow with every customer in one autoloaded row, and the privacy tools would need their own
  pruning. "Forget every mark" on a new connection deletes `get_blog_prefix() . 'omnabase_known'` for every user, so only that site starts
  afresh. The customer's site id (`omnabase_site_id`) stays shared user meta: it names the person; one erasure removes it for every site
  (accepted, the red team's note).
- **The privacy exporter and eraser** read and erase this site's notes (`Holdings::userMeta()` keeps its plain names; the eraser forgets a
  per-site note as a user option and the site id as user meta). An administrator's pending Connect is still not in the export.
- **The outbox table per site.** `Outbox\Table::name()` already read `$wpdb->prefix` at call time (no static). Activation now takes
  WordPress's network-wide flag: `Bootstrap::activate( true )` installs the table on every site; a site added later, or a sub-site activated
  on its own, gets it at its own activation or on its first load (the plugin already makes a missing table on load).
- **The timers per site.** `Bootstrap::deactivate( bool $network_wide )` clears the outbox's two timers and the forum's mail jobs on every
  site after a network deactivation, only the current site's otherwise (before, a network deactivation left every sub-site's timers firing).
- **The uninstall loops every site.** `uninstall.php` wraps one site's removal in a closure (the key's stop at OmnaBase with that site's own
  key, the options, the per-site notes under the site's prefix, post meta, jobs, transients, the table) and runs it on each site when
  `is_multisite()`, then deletes the shared site id once. **The 100-site paging**: WordPress's `get_sites()` lists 100 sites unless told
  otherwise; the loops (uninstall, activation, deactivation) ask `array( 'fields' => 'ids', 'number' => 0 )`, every site's id in one light
  query (the red team's 150-site test). A single site never asks for a network's sites.
- **Network activation is allowed; each site keeps its own connection** (its own Connect, key, settings, table and timers).
- **Not changed (the red team's finding 6, accepted):** a customer changing their email address on a network tells only the site where it
  changed at once; the other sites resend at the customer's next message-centre visit, because their fingerprint no longer matches.
- **Readme**: the FAQ "Does it work in a subfolder or on a multisite?" (yes; one connection per site); the requirements line drops the root
  of the domain; the uninstall answer says it cleans every site of a network. One changelog line under `= 0.1.0 — unreleased =`.

Tests adjusted (the reason is also at the head of each file):

- **`tests/Structure/RoundTwoStructureTest.php`, two guards, adjusted, not weakened.** (1) "SQL only in the outbox's table class" refused
  any `$wpdb` outside `Outbox\Table`; `UserNotes` needs the site's table prefix (`$wpdb->get_blog_prefix()`, which runs no query) to forget a
  note for every user of one site. The guard now ignores `global $wpdb;` and `$wpdb->get_blog_prefix()` and still refuses every other use.
  (2) The uninstall guard wanted two plain user meta names deleted for every user; the red team's new guard forbids the per-site notes'
  plain names, so it now asks for the site id under its plain name and the per-site notes (the pending connect among them) under the
  site's prefix.
- The red team's own adjustments (`ConnectFlowTest`, `uninstall.spec.ts`) were copied in as parked. `PrivacyTest`'s bare `omnabase_known`
  plant was left alone: a user option read falls back to the bare name, and that test checks only the site id.

Also: the parked test harness (`omnabase_test_path`, a WordPress in a subfolder for a browser flow) is copied in; no flow uses it yet (the
browser's route would have to strip the folder and send `X-Forwarded-Prefix`), a follow-up when a subfolder flow is wanted.

The flows' harness (`tests/e2e/harness.ts`): its two calls to OmnaBase's e2e server (`omnabaseHook`, `publicApi`) are tried a second time
when the first meets an already-closed connection (`ECONNRESET`). Why: on a loaded machine (load average 23–43 during this round) two whole
runs failed at the same spots, each a fetch right after a long WP-CLI step: `php()`/`wp()` block Node while WP-CLI runs, the server closes
the idle kept-alive connection after its 5 seconds unseen, and the next fetch reuses it. The assertions just before passed (the uninstall
left nothing and made exactly one `DELETE /me`). No check changed; only a connection that was already closed is retried, once.

## 2026-10-11 — The vault's key file; the Stored connection card gone

The founder (2026-10-11): "go with the file-based key, remove the block"; a customer without coding knowledge must never be asked to add
something to wp-config.php (WordPress's own settings file). Red team: `docs/reviews/vault-key-red-team-report.md`; builder:
`docs/reviews/vault-key-builder-report.md`.

- **The source order, chosen once per save** (`Security\VaultKey::choose()`): `OMNABASE_ENCRYPTION_KEY` when defined (32 bytes base64, any
  other text hashed into a key, as before); else the random 32-byte key in the plugin's key file, made on the first save; else the salts
  (the secret phrases WordPress mixes into its cookies), also when those live in the database, silently.
- **The record**: the vault's option holds `v` 2, `source` (`constant`|`file`|`salts`), `kid` (16 hex characters of BLAKE2b keyed with the
  key over a fixed label: tells keys apart, gives nothing of the key away) and the two sealed values. **Reading uses only the recorded
  source**: it never re-chooses, never creates a file, never caches a key (each call reads the small file again; the tests lose the file
  mid-process). A record without `source` (saved before this round) reads as unreadable: no migration at this stage, the site connects again.
- **The key file's place and guards** (`Security\KeyFile`): `omnabase/key.php` under wp-content, else under the main site's uploads folder
  (on a network the main site's, never a sub-site's: one file per network). Beside it `index.php` (no folder listing) and a `.htaccess`
  refusing every request (Apache 2.4 and 2.2 forms). The file starts `<?php`, a comment, `exit;`, then `// omnabase-key <base64>`: run by a
  web server it prints nothing (nginx ignores `.htaccess`, so this is the guard that always holds). **Made in one step, private from the
  start**: a temporary file opened with `fopen( 'xb' )`, set to 0600 before the key is written into it, then `link()`ed into place (a hard
  link fails when the file exists, so two requests at once keep one key); on a file system without hard links, `rename()` while the file is
  still absent. Never rewritten once present; a present file this process cannot read is never replaced (the save falls back to the salts).
  A file anyone on the server may read still works and is noted once per request in PHP's error log (a code line, no key), never on screen.
- **The reconnect reasons**: `Vault::unreadableReason()` gives `file_gone` (recorded source `file`, the file missing or holding another
  key), `keys_changed` (source `salts`, today's salts give another key id), else null (a damaged value, or a removed constant). The words
  (`Admin\ReconnectWords`, one sentence per reason for the connection card and for the "OmnaBase: reconnect needed" notice): "…because the
  file this site locks its connection with is gone", "…because the site's secret keys changed", or no reason.
- **The pending connect seals like a save**: `Vault::seal()` chooses the key the same way (so the first Connect click may create the key
  file) and prefixes `<source>.<kid>.`; `open()` finds that source's key and checks the key id.
- **The screen**: `templates/admin/partials/advanced.php` deleted; the Connection tab ends with the Status card (the connection card while
  not connected); `Health::view()` no longer reports a key source; `Vault::keySource()` removed (nothing called it). No new option.
- **Uninstall** removes the key file, its guards, any leftover temporary file and the folder, once, after the per-site loop (every site's
  stop at OmnaBase still needs the key). Disconnect keeps the file; a reconnect reuses it.
- **Direct file calls with `phpcs:ignore`**: `fopen`/`chmod`/`link`/`rename`/`file_put_contents`/`file_get_contents`/`rmdir` in `KeyFile`.
  WP_Filesystem cannot create a file only when absent and may ask for FTP credentials; the reason sits on each line.
- **Two texts as nowdocs** (`KeyFile`): the key file's PHP template and the `.htaccess` directives. The prose guard (words for people go
  through translation) would read them as untranslated prose; they are file contents for servers and for an administrator opening the file,
  never shown on a screen and not translatable (a translated `.htaccess` breaks).
- **The log line** is a code (`OmnaBase: key_file_readable_by_others (mode 644) <path>`), not a translated sentence: the vault may run before
  WordPress loads translations, and a log is for the server's administrator.
- **Customer texts**: the changelog line about reconnect needed now says "if the stored connection can no longer be read … and why" (also
  its copy in readme.txt's Changelog); the "Details and Advanced" line reads "…waits behind "Details"; the Advanced block is gone" (the red
  team's guard asks for that line); a new line "Changed: The stored connection is locked with a key the plugin keeps in a protected file;
  nothing to set up." The FAQ "What happens when I delete the plugin?" names the key file. `.pot` regenerated.

Tests: the red team's 12 parked files copied in unchanged. One test helper adjusted, not weakened: **`tests/Unit/Support/FakeNetwork.php`**
gets a small file system (wp-content as the unit layer's stand-in folder, emptied of the key folder at each install, and fakes for
`get_main_site_id`, `wp_upload_dir`, `wp_mkdir_p`, `wp_is_writable`, `wp_delete_file`, `untrailingslashit`), because the vault now reaches
for the key file and the multisite tests' saves and uninstalls called WordPress functions the fake did not have. Its `traces()` now also
reports a key folder left behind, so the uninstall tests' "nothing of the plugin is left" covers the key file too (stricter). No assertion
changed.

## 2026-10-22 — The front end's design: light and dark, the Appearance tab, the form and the message centre in the kit's look

The founder's order of 2026-10-21 ("option for light and dark themes, better design for the forms and for the message centre …; should use
the styling of our OmnaBase app"). Brief: OmnaBase `docs/briefs/plugin-frontend-design.md` with its section "After the red team's review"
(final); red team: `docs/reviews/plugin-frontend-design-red-team-report.md`; builder: `docs/reviews/plugin-frontend-design-builder-report.md`.

- **Six tabs.** The settings screen gains a sixth tab, **Appearance** (slug `appearance`), after Forms and messages and before Activity, as
  the brief asks; the five-tab tests were adjusted by the red team to six (nothing weakened). One card, "Look on your site", in one save form
  (`omnabase_save_appearance`, `Admin\AppearanceActions`) with four rows: Theme (Light, Dark, Follow the visitor's system; default Light),
  Accent (OmnaBase's blue, or "Your own colour" with a colour field), Font (Inherit the theme's font, default; Load OmnaBase's font) and
  "Theme styles only". The radio groups are labelled by their row (`role="radiogroup"` with `aria-labelledby`, finding 19).
- **One option, `Options::APPEARANCE` (`omnabase_appearance`)**: `theme`, `accent` ('' for OmnaBase's blue or `#rrggbb` in lower case),
  `font`, `themeOnly`; read and written by `Look\Appearance` alone. **Only a real colour is kept** (finding 13): anything but `#rrggbb` leaves
  the blue, and choosing OmnaBase's own blue as one's own colour stores the blue (as the app does). On the uninstall list.
- **"Theme styles only" moved** (finding 3): its value lives in the new option and only the Appearance save writes it; the Forms and messages
  save stores the page and the My Account switch alone (`Display::save( $my_account )`), and drops the old `theme_only` key from
  `omnabase_display` when it saves (development stage: no migration; a site that had the switch on turns it on again on the new tab).
  The switch now also keeps the font's stylesheet away, and the `omnabase_form_styles` filter drops both (finding 23).
- **The wrapper's marks as the app writes them** (`Look\WrapperMarks`): `data-theme="light|dark"`, none for the visitor's system;
  `data-accent="custom"` with `--ob-accent-user` and `--ob-on-accent-user` in the style. Kept as `data-theme` (finding 11): every plugin rule
  stays under `.ob-wp`. The marks stay with "Theme styles only" on, so a theme may style by them.
- **Text on the accent: the readable choice** (the founder's question, decided under the standing autonomy rule): whichever of white and
  `#1a1a1a` has the higher contrast with the owner's colour. The app's own rule (white below a brightness of one half) is not changed.
- **The stylesheet** (`bin/build-css.php`, its reading in `bin/lib/css-tokens.php`): the kit's light block on `.ob-wp`, the accent block on
  `.ob-wp[data-accent]`, then inside `@media (prefers-color-scheme: dark)` the dark block on `.ob-wp:not([data-theme="light"])` and its
  accent block, then the dark block on `.ob-wp[data-theme="dark"]` and its accent block: the kit's own order, so an own colour wins in both
  themes (finding 4b). Both dark blocks say `color-scheme: dark` (4c). The primitives a dark value refers to are copied into the light block
  (they have the same value everywhere). The wrapper paints its own surface, text, border and padding (4a). The stale muted grey is gone by
  the rebuild (finding 1). Not done: the recommended `styles/tokens.lock` record for CI; the structure test against the live file stays the
  guard on the Mac (CI has only this repository).
- **New tokens in OmnaBase's kit** (finding 14; recorded in OmnaBase's DECISIONS too): `--ob-error` (the error's words: red-700 #b42318 by
  day, 6.6 to 1; #f28b82 at night), `--ob-error-soft`, `--ob-success-soft`, `--ob-warning`, `--ob-warning-soft`, each with dark values in
  both dark blocks; and in both dark accent blocks the focus ring is the accent mixed 45 % towards white, so a dark accent such as Black
  still shows on the night ground (finding 5). The plugin's rules use no raw palette token any more.
- **Readable everywhere** (finding 5): visitors' secondary text (privacy line, dates, pills) is `--ob-text-secondary`, never the muted grey;
  the status pill shows its word in readable text and the status colour only on its dot (Closed: a hollow dot); subjects in the text colour.
- **The form**: the kit's `ob-input ob-input--text` box around an `ob-input__field` (finding 10), `ob-select`, `ob-textarea`, `ob-btn
  ob-btn--primary`; labels above with `for`; each field's error line under it with the kit's error icon, its id named by the field's
  `aria-describedby`, `aria-invalid="true"` while it shows (finding 8). No field help (finding 17: no project setting supplies one). Field
  names, the honeypot, the token and the limits are unchanged.
- **The success card** (findings 6 and 7): printed hidden beside the form, outside it, with `role="status"`; its words are translated in the
  template (`data-sent` "Thanks. Your reference is #%d", `data-queued` for a message on its way, `data-taken` for the trap's "sent"), so the
  card never shows "#undefined"; focus moves to its first line. A refusal keeps the form and its words. "Send another" empties the message,
  turns Send off and fetches a fresh time token. The REST answers and their sentences are unchanged.
- **The message centre**: the kit's notice (`ob-notice--ok|--error`, an icon, both themes); rows with the subject, the pill (`ob-pill
  ob-pill--<status>`: Open, Answered, Resolved, Closed) and the date; paging as `ob-btn--outline`; the back link `ob-btn--ghost`; the thread
  with the visitor's messages tinted with the muted surface and set in from the left, the team's on the surface, a third person's dashed,
  each naming its author in words (finding 9); the reply box as the composer (the textarea and a foot with the primary button); the prompt
  as `.ob-portal-confirm`. No logo picture: the API gives none.
- **The font** (finding 12): the kit's two variable subsets and `OFL.txt` copied into `assets/fonts/` by the build, `assets/fonts.css` (the
  kit's `@font-face` rules, `font-display: swap`, the family on `.ob-wp`) queued only with "Load OmnaBase's font", never on the admin. The
  readme names Plus Jakarta Sans and the SIL Open Font License.
- **Phones**: one column, the wrapper's padding 16 px under 480 px, 16 px field text (iPhone zooms into smaller, finding 21), Send full width.
- **Release notes** (finding 2): a new `= 0.2.0 — unreleased =` section above the frozen 0.1.0; the version bumped in the header,
  `OMNABASE_VERSION`, readme.txt's Stable tag and package.json; CLAUDE.md's changelog paragraph corrected.
- **The lane switch** (finding 16): `bin/lane.sh set <api> <web> | reset | show`, run by the flows' global setup when Playwright is given
  other ports than `.wp-env.json`'s and put back by its teardown. `round-trip.spec.ts` now reads the version from package.json and the
  development notice's address from the lane (adjusted, not weakened).
- **`AdminParts` split out of `Plugin`**: the admin wiring (the settings screen, its actions, the notices) moved from `Plugin` into its own
  container part, `src/AdminParts.php`, like `Forms` and `Portal`. Why now: Plugin Check looks for the direct-access stop only in a file's
  first 50 lines, and the Appearance tab's three imports pushed `Plugin.php`'s to line 54 (an error in the release check). `Plugin` keeps
  the parts every request needs and is 64 lines.
- **The form's field ids** are `ob-wp-form-<project>-<field>`, the same on every view (a page cache may keep the page; the cache-safety
  test renders the form twice and wants the same HTML); when one page shows the same project's form twice, the form's script renames the
  second copy's ids and the references to them.
- **Not done** (not pinned): a sample card on the Appearance tab (finding 20); the light focus ring for a pale own colour on white (the
  app's light accent block keeps the owner's colour as the ring).

Tests: the red team's five new files copied in; its 19 adjusted files kept as adjusted. The builder adjusted six more, none weakened (the
reason sits beside each change):

- **`tests/Integration/PagesTabTest.php`** (a red-team adjustment): its XPath wrote the second label spelling as `'theme\'s styles only'`, which
  XPath 1.0 cannot read, so the query failed before looking; now quoted with double quotes. Both spellings still looked for.
- **`tests/e2e/frontend-look.spec.ts`** (two): the font flow watches only the plugin's own font files (the test WordPress's theme serves its own
  Manrope font, which the flow counted although the page used the theme's font as asked); the message-centre flow chooses OmnaBase's blue, as
  the form's flow does (the flow before it leaves the accent on Black, and the prompt's button rightly wore it).
- **`tests/e2e/round-trip.spec.ts`**: the version line read from package.json and the development notice's address from the lane (a written
  0.1.0 and 3011 broke with the bump and the lane switch).
- **`tests/Unit/PluginInfoTest.php`** and **`tests/Structure/RoundSixStructureTest.php`** (the `.pot`'s header): the version read from
  package.json and from the plugin header instead of a written 0.1.0; both checks stay exact.

## 2026-10-24 — The message centre names OmnaBase's third refusal, `team_member`

Since OmnaBase's "members never customers" round (2026-10-23) the customer sync answers `409 team_member` for an address that belongs to a
member of the workspace. The plugin knew only `address_taken` and `forgotten`, so the founder, testing his own page on omnalingo.com as the
site's administrator, read "Your messages cannot be shown right now. Please try again later." (an outage that never clears). Chosen: a third
refusal with its own sentence (the person is on the support team, the customers' messages are in the OmnaBase inbox) and a primary button to
the workspace's inbox in the app (`<app>/<workspace slug>/inbox`, the slug from Connect); no portal call is made, as for the other two.

## 2026-10-25 — The plugin's forms round: the shortcode shows a form built in OmnaBase, through the embed

Brief: OmnaBase's `docs/briefs/plugin-forms-round.md` (its last section "After the red team's review", final); report:
`docs/reviews/plugin-forms-round-red-team-report.md`. The Classic form, its two site routes (`omnabase/v1/form`, `omnabase/v1/form-token`), its
settings read (`GET /forms/{project}`: products, licence field, privacy link), its outbox job and its tests are gone; OmnaBase's round three
had already retired the API it sent to.

- **The shortcode** `[omnabase_form id="<form slug>" project="<slug>" subject="…"]` prints the look's wrapper (`.ob-wp.ob-wp-form-wrap`, the
  Appearance marks) around one container `data-omnabase-form="<workspace>/<slug>" data-project="<project>"` and queues OmnaBase's embed once,
  `ApiUrl::app()` + `/forms.js`, no version query (OmnaBase caches it an hour), in the footer, deferred. The workspace's slug comes from
  Connect. Without `id`: the project's default form, from the list's `defaultFor`. The template stays `templates/form.php` (now the container).
- **Which form** (`Forms\FormPick`): a named form the list lacks, or a slug of the wrong shape, is the administrator's note ("Only
  administrators see this note.", `manage_options`, as every other note; the brief said "editors") and nothing for visitors. OmnaBase
  unreachable: a named form still shows (the embed asks OmnaBase itself); a bare shortcode cannot know its form and stays empty for visitors.
- **The list** (`Forms\FormList`, `GET /forms`): kept an hour under `omnabase_form_list`, a failure five minutes. A form or default the kept
  list lacks asks again once the list is over a minute old, so a form published a moment ago shows without waiting an hour. The settings tab
  always asks afresh. A new connection (`omnabase_connected`) forgets it.
- **The prefill token** (`Forms\PrefillToken`, `Forms\Vouched`): only for a signed-in visitor; the server asks `POST /forms/prefill` with
  `{ form, project, values }` (by mapping: `email`, the display name as `name`, the newest WooCommerce order's number with the WooCommerce
  switch on, a licence key only when the user holds exactly one among License Manager and EDD Software Licensing with that shop's switch on;
  no EDD orders this round). Kept an hour per person, form, project and values (`omnabase_form_p_<hash>`; OmnaBase's token lives two hours
  and may be used again), a refusal or outage five minutes; a 3-second timeout. A signed-in view defines `DONOTCACHEPAGE` and sends
  LiteSpeed's no-cache action; a visitor's page carries no token and is the same for everyone. The container carries it as
  `data-prefill-token` (the red team's name; the brief's `data-omnabase-prefill` was not used).
- **`subject`** becomes `data-prefill-subject`: it pre-words the form's field keyed "subject" when the form has one, unverified.
- **The privacy line**: the site's privacy page (`get_privacy_policy_url()`), when there is one, as a "Privacy policy" link under the container.
- **The look**: `styles/form.css` sets the embed's variables under `.ob-wp` from the kit's tokens (accent and its text from the primary
  button, border, background, field, text, muted, error, focus from `--ob-focus`, radius), so light, dark, the device's setting and the owner's
  colour reach the embed with no new mechanism. "Theme styles only" keeps the stylesheet away: the embed shows its own plain look. The
  Classic form's rules went; `FrontendDesignStructureTest`'s error-colour check now reads the token behind `--omnabase-form-error` when no
  `.ob-wp-form__error` rule is left (an adjustment, the 4.5-to-1 check unchanged).
- **The embed's words stay English** ("Send", "Thanks, we have your message."): OmnaBase's `forms.js` has no translated words yet (question 6;
  recorded in OmnaBase's DECISIONS too). A German shop shows English buttons on the form until the embed reads words from the container.
- **The settings screen**: the Forms and messages tab opens with a Forms card (the forms with their shortcodes, "the project's default form"
  beside a default, a "Build forms in OmnaBase" link to `<app>/<workspace>/settings/forms`; "no forms yet", "could not be asked" and "not
  connected" each said in one line). "Build a shortcode" offers the forms (`omnabase_builder[form]`, "The project's default form" first);
  the server accepts only a form the list names and refuses any while OmnaBase cannot be asked. No block (question 1).
- **Waiting messages of the old form** (outbox kind `form_submission`): the worker no longer knows the kind, so a row queued before the update
  shows as needing attention and leaves with the usual clean-up (question 8); until then the personal-data tools still find it by its address.
- **The message centre's guest form** (`[omnabase_messages guest="form"]`) is the project's default form through the same shortcode; when
  no form can be shown (no default form, OmnaBase unreachable for a bare shortcode) the visitor gets the sign-in link instead of an empty page.
- **The message centre's trap field** moved from the retired `Forms\Guard` into `Portal\Trap` (same field name `omnabase_hp`). The filter
  `omnabase_visitor_address` left with the form's per-address limits (OmnaBase limits the embed's submissions per network address itself).
- **Not this round**: a block with a form picker, EDD orders in the prefill, the message centre link for a signed-in customer's form ticket
  (question 10), translated embed words.
- **Tests**: the red team's eight new files moved in and its three adjusted files kept. Deleted with the Classic form, as the report's list
  names them: `FormTokenTest`, `FormSubmitTest`, `FormShortcodeTest`, the Classic `form.spec.ts` (replaced), three Classic tests of
  `FrontendLookTest` and the seven form flows of `frontend-look.spec.ts` with the form half of its phone flow. The flows' sign-in code reader
  is one helper in `tests/e2e/harness.ts` accepting "231191" and "123 456". Adjusted, none weakened, each with its reason beside it:
  `RoundSixLeftoversTest` (its "Send now" row is now a customer's link, not a form message), `FirstShownTest`, `ShortcodesSectionTest` and
  `MessageCentreViewTest` (the form shows as the embed's container, not a `<form>`), `FrontendDesignStructureTest` (the error colour read
  from the embed's variable), `forum.spec.ts` (the app's ticket page holds its site pill twice), `shortcode-builder.spec.ts` (the guest view
  is the sign-in link while the seed's Enfold has no default form); the Chromium flag for Local Network Access went into
  `playwright.config.ts`. The two structure checks that pinned the name "OmnaBase" follow
  the founder's rename to "OmnaBase Connector" (the `.pot` header's name now read from the plugin header).

## 2026-10-28 — The message-centre HTML round: the thread and the forum post print a message's HTML through the plugin's fence

Brief: OmnaBase's `docs/briefs/plugin-message-centre-html-round.md` (its last section "After the red team's review", final); reports:
OmnaBase's `docs/reviews/plugin-message-centre-html-round-{red-team-report,builder-report}.md`. The app side (the portal's `bodyHtml` in the
display shape with no pictures) is in OmnaBase's DECISIONS of the same day. No version change (0.2.0 stays unreleased).

- **One helper, a fence, not a second display rule** (`Look\MessageHtml`): `of( $html, $text )` removes script and style blocks with their
  content (an unclosed one to the end), prints `h1`/`h2` as `h3` (nothing inside a message outranks the thread's subject, an `h2`; question
  4), cleans with `wp_kses` and its allow-list under the protocols http, https and mailto, then one pass with `WP_HTML_Tag_Processor`: an
  http(s) link gets `target="_blank"` and `rel="noopener noreferrer"` whatever was sent, a mailto link loses both, any other link loses its
  address and keeps its words. When nothing with words is left (no HTML, or a message that was only pictures) it answers the text as
  paragraphs (`wpautop` of the escaped text). `allowed()` is the list: p, br, b, strong, i, em, u, s, a (href, title, target, rel), ul, ol,
  li, blockquote, pre, code, h3, h4, table, thead, tbody, tr, td and th (colspan), hr, span, div; no class, id or style anywhere. It is the
  only file in `src/` that names the list (a structure guard). No quoted-history logic and no picture handling beyond "none": OmnaBase's
  rule decides those.
- **The thread**: `Centre::message()` carries `html` (the helper's result) instead of `text`; the template echoes it through
  `wp_kses( $omnabase_message['html'], MessageHtml::allowed() )` (every echo begins with an escaping function; cleaning twice changes
  nothing).
- **The forum poster** uses the same helper instead of `wp_kses_post`: a team answer on a public forum loses pictures, inline styles and
  classes; the closing line stays last (question 5). The webhook carries OmnaBase's stored HTML, so this fence is the only cleaning before
  bbPress's and WordPress's own filters.
- **The stylesheet**: `.ob-wp-messages__body` lost `white-space: pre-line` (the HTML carries its breaks) and every message tag is reached
  through `.ob-wp .ob-wp-messages__body <tag>` (the fence adds no class): paragraphs spaced, none after the last; lists with their markers
  set back (the thread's own `ol` has none, and themes remove them) and inline padding; quotes as a left rule in the strong border colour
  with a theme's `::before`/`::after` quote marks reset; code and pre on the muted background in the system's monospace family (the kit has
  no monospace token; a typeface list, not a colour), pre scrolling sideways; tables `width: auto`, collapsed borders, cells padded and not
  broken mid-word; rules; h3 and h4 at the kit's md and base sizes. The box scrolls sideways for a wide table, with 4 px of inline padding
  taken back by its margin so a link's focus ring is not clipped; the message `li` got `min-width: 0` so the thread's grid does not widen
  with the table. Links keep the wrapper's rule, the text colour underlined (the 2026-10-22 contrast rule; question 3), not the accent.
- **"Theme styles only"**: the plugin's stylesheet is absent, so the theme styles the message's HTML (its quote marks, table widths, list
  markers). The owner's choice; nothing done about it.
- **Not this round** (questions 1 and 2): no signature and no files in the message centre, not even a "this message had files" line.
- **Older rows**: messages typed into the message centre before OmnaBase's df1552dc were stored as HTML without links, so their addresses
  stay unlinked; WordPress's own `:)` character swap stays (development stage).
- **Tests**: the red team's four new files moved in, its two adjusted files kept (`RoundThreeStructureTest`, `frontend-look.spec.ts`). The
  builder adjusted `forum.spec.ts` twice, not weakened: since the app's ticket style clean-up (2026-10-26/27) a site's mark on the first
  message is the web icon alone with "Public on <host>" / "Via <host>" as its accessible name, so the public topic's check reads the name
  instead of the text, and the hidden topic's "no public mark" check first waits for the "Via <host>" mark and then counts names (the old
  class count would have passed on any page). No `.pot` change: no translated sentence was added or changed.

## 2026-10-29 — The pictures-in-the-text round: the message centre shows the team's own portal pictures, the forum none

OmnaBase's pictures-in-the-text round (OmnaBase's `docs/briefs/pictures-in-the-text-round.md`, rule 4, and its red team's findings 7 and 8):
a screenshot the team pasted into a reply reaches the message centre as `<img src="<API>/api/public/v1/portal/files/<token>" alt width>`, a
signed address OmnaBase made for this visitor and this picture (a web address carrying its own proof, valid about a day and checked again by
OmnaBase at every load). The visitor's browser loads it from OmnaBase directly: no new WordPress route, no bytes through the site's server,
none of the visitor's minute allowance spent.

- **The fence**: `MessageHtml::of( $html, $text, $pictures )` and `MessageHtml::allowed( $pictures )`; the message centre passes `true`, the
  forum poster nothing. With pictures on, `PortalPictures::onlyPortal()` removes, before `wp_kses`, every `img` whose `src` is not exactly
  `ApiUrl::publicApi( '/portal/files/' )` followed by one token of letters, digits and `._~-` (so another host, another path on OmnaBase's
  host, a look-alike host such as `<host>.evil.example`, a climbing `..` and a `data:` picture all leave); `wp_kses` then keeps `src`, `alt`
  and `width` only (no handler, style or class); `PortalPictures::settle()` checks the address once more and drops a `width` that is not a
  whole number of 1 to 4 digits. A message whose only content is such a picture shows it (it is not "without words").
- **The forum** never shows a picture, not even a portal one: the address is made for one signed-in visitor and a forum is public.
  OmnaBase's webhook says "[Picture: name]" there anyway.
- **The look**: `.ob-wp .ob-wp-messages__body img` is a block, at most the bubble's width, its height following (`styles/form.css`, rebuilt
  into `assets/form.css`).
- **Not this round**: a customer's own mailed pictures (OmnaBase still sends none to the message centre); no version change (0.2.0 stays
  unreleased).

## 2026-10-29 — The site-fields round: Post fields travel with a forum post to OmnaBase and back onto the team's reply

OmnaBase's brief `docs/briefs/site-fields-round.md` (rule 5 and its last section), the red team's report and the builder's report
`docs/reviews/site-fields-round-{red-team-report,builder-report}.md` in the OmnaBase repository. The founder's ask: kriesi.at's "Private
data" box (AviaSupport's post meta `_av_supp_private_info`) reaches the team, and the team's own private data lands in the same box of its
forum reply. Generic: any post meta (a named extra value WordPress keeps beside a post) the administrator names.

- **The setting**: Settings › OmnaBase › Forums › "Post fields", rows of Field (the meta key, with a datalist: the browser's suggestions
  under the input) and Name (what the team reads, at most 60 characters); the saved rows plus one empty row, so adding one field needs no
  JavaScript; "Add a field" and Remove are small conveniences of `assets/admin.js`. Stored in the forum option as `fields` (key ⇒ name),
  cleaned by `PostFields::cleanRows`: keys `a-z 0-9 _ -` (1 to 100), **a key with capitals is dropped, never lower-cased** (WordPress reads
  meta by its exact name), `_bbp_`, `_omnabase_`, `_wp_` and `_edit_` refused (OmnaBase must never write bbPress's counters, the plugin's own
  marks or WordPress's), at most 10; the dropped rows are named in the notice after the save. The filter `omnabase_forum_fields` (key ⇒ name)
  is merged after the setting under the same rules.
- **The auto-complete**: one bounded, prepared read of the distinct meta keys of the 500 newest topics and replies (`_bbp_` and the refused
  keys left out, at most 100, the declared keys added), kept in the transient `omnabase_forum_meta_keys` for an hour, dropped on every save of
  the forum settings and at uninstall. **The one named exception to "SQL only in the outbox's table class"**: no WordPress function lists
  distinct meta keys; the structure guard names `src/Forum/PostFields.php` beside `src/Outbox/Table.php` (adjusted, not weakened).
- **Sending**: a forum with declared fields sends `replyFields` (every declared field) on every topic and `fields` only for values that are
  not empty; a forum without declared fields sends exactly the old body. Replies (the customer's and a moderator's) send `fields` the same
  way. A value: the meta through `omnabase_forum_field_value( $value, $key, $post_id )` (asked for every declared key, also with an empty
  meta, so a developer can supply a value kept elsewhere), then `PlainText::of` cut at 10,000 characters.
- **Receiving**: `ReplyPoster` writes, right after `bbp_insert_reply` and before the posted mark (a redelivery that finds the mark posts
  nothing more), each field of the message whose key is declared at that moment, stored with `wp_kses( $value, array() )` (tags out, line
  breaks and `%2F`-style sequences kept; `sanitize_textarea_field` would break `wp-login.php?redirect_to=%2Fwp-admin`), then the action
  `omnabase_forum_field_saved( $reply_id, $key, $value )`. Any other key writes nothing. AviaSupport's own `wptexturize` on display turns
  straight quotes into typographic ones for any private data, ours or the customer's: noted for the founder, not ours to change.
- **Records**: readme.txt (the setting in the description, two FAQ entries with the three hooks), changelog.txt 0.2.0 unreleased (one line,
  no version bump), `languages/omnabase.pot` regenerated, uninstall removes the transient (the values on forum replies are the site's own
  post meta and stay). `AdminParts` names `Forum\PostFields` without a new `use` line: Plugin Check reads only a file's first 50 lines for
  the direct-access guard, and one more import pushed it to line 52.
