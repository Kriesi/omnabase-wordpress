# M13 round one — red-team report (failing tests for Connect)

2026-10-09, the red team (Opus 5.5). The tests the builder makes pass in round one: Connect, the vault, the two receivers, health, Disconnect and reconnect in the plugin; `DELETE /me`, `/me/ping`, the slugs in `/me`, the default view and "own tickets only" in OmnaBase. They are **parked**, in neither repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m13-round1-parked/` (`plugin/…` and `omnabase/…` mirror each repository's paths; copy them in as they are).

**Counts**: plugin 68 test cases in 9 files (unit 26, structure 7, wp-env integration 29 plus a helper class, Playwright 6); OmnaBase 8 in 2 files. **Each fails today for its reason** (run 2026-10-09): unit, the classes do not exist; structure, files, routes, the app address and the changelog line are missing; integration 29 of 31 fail (Connect redirects nowhere, no vault), the two older tests pass; Playwright stops at the missing "Connect" button (the other five are serial, skipped); OmnaBase 5/5 (`DELETE /me` and `/me/ping` 404, `/me` bare ids, no `view` 400, a connect-made address hears tickets 2, 3 and 4 where only 2 is its own) and structure 3/3. The unit tests went green against a throwaway implementation in a scratch copy, so they can be met.

Words: *vault*, the one encrypted store for the key and secrets; *salts*, wp-config's secret keys; *nonce*, a short-lived form token; *REST route*, an address under `/wp-json/`. Each entry is What it checks / Passes when / Fails when, grouped where tests are variations of one idea.

## Plugin — `tests/Unit/` `PkceTest`, `SignatureTest`, `VaultTest` (`composer test`)
**The PKCE pair** — the secret the site keeps and the fingerprint it sends are made OmnaBase's way.
Passes: a known secret gives exactly OmnaBase's fingerprint; fresh secrets and states are URL-safe, the right length, never repeated.
Fails: OmnaBase refuses every exchange (no site connects), or a repeated secret lets an old code or return link be replayed.
**The signature** — a call counts only with OmnaBase's signature over exactly the bytes received, made within five minutes.
Passes: the documented example and 300 seconds pass; one changed byte, another secret, 301 seconds, `v0=`, no header all fail.
Fails: anyone can post a fake team reply into the forum, or OmnaBase's genuine calls are refused.
**The vault keeps, forgets and fails closed** — values come back exactly, never readable in the database; other salts or junk read "unreadable".
Passes: key and both secrets round-trip, none in the stored options, not autoloaded; cleared is "empty"; changed salts or junk are "unreadable".
Fails: a dump shows the key, Disconnect leaves it behind, or scrambled values are used as the key and no "reconnect needed" ever shows.
**Where the vault's key comes from** — `OMNABASE_ENCRYPTION_KEY` wins; real salt constants count; the placeholder or none means the database.
Passes: key source "constant" (a salt change no longer locks it), "salts", and "database" for the placeholder and for no constants.
Fails: the dedicated key is ignored, or the screen claims protection a site with database salts does not have.
## Plugin — `tests/Structure/RoundOneStructureTest.php` (`composer test`)
**Parts, one store, one way out** — the brief's classes exist, `StoredKey` is gone, only the vault touches its option, calls leave from `src/Api/` only.
Passes: files present; secretbox used; the option named only in the vault, the list, uninstall; no `wp_remote_*` or web address outside `src/Api/`; every REST route has a real `permission_callback`.
Fails: another class reads or prints the credentials, customer data leaves for another host, or the customer receiver answers anyone.
**Uninstall, the flows, the records** — uninstall clears user meta and transients; the flows reach OmnaBase's page; changelog and decisions.
Passes: options listed and removed; `.wp-env.json` app `http://localhost:4391`; `bin/omnabase-e2e.sh` starts Vite on 4391; a "Connect" changelog line; "M13 round one" in DECISIONS.
Fails: a pending connect's secret stays after deletion, the flows cannot reach the connect page, or site owners are not told Connect exists.
## Plugin — `tests/Integration/ConnectFlowTest.php` (`composer test:integration`; helpers `RoundOneTestCase.php`)
**The link** — Connect sends the admin to OmnaBase's `/connect#` page with exactly the fields OmnaBase's `connect.ts` reads.
Passes: site (https origin), title, the admin's address, the six permissions, the two events, return (this page), state, challenge; no call yet.
Fails: OmnaBase shows "This link cannot connect a site", grants other permissions, or sends the admin elsewhere.
**The way back** — the code is traded once, with the secret (never in the browser), and everything lands in the vault.
Passes: one exchange with exactly code, verifier (matching the challenge) and site, no key; vault filled; clean address; a reload trades nothing.
Fails: OmnaBase refuses the exchange, a stolen code suffices to take the key, or the code stays in the browser history.
**The screen** — "Connected to Team Kriesi", project Enfold, the six permissions, the key as `obw_…7Q2X`; names and permissions kept offline.
Passes: all shown, the full key never; with OmnaBase unreachable the workspace and permissions still show.
Fails: the admin cannot tell where the site is connected, or the full key is printed into the page.
**Whose way back, and how late** — a forged state or another admin's return is refused; two admins keep their own secrets; minutes, not hours.
Passes: no exchange for a forged state or another admin, the right admin then connects; A and B trade their own secrets; fine at 9 minutes, refused at 16.
Fails: an attacker's link connects the victim's site to the attacker's workspace, a second click spoils the first, or a leaked link works days later.
**Cancel, a refused exchange, sites that cannot connect** — nothing stored, the reason in words; only an admin with the form's token starts.
Passes: `error=access_denied` and a 410 store nothing and say so; http, plain permalinks and a subfolder never reach OmnaBase and name the reason; subscriber or token-less clicks never start.
Fails: a half-made connection stays, the admin approves a site OmnaBase can never call, or a forged link starts a connect.
## Plugin — `tests/Integration/ReceiversTest.php`
**Turned away at the door** — `/omnabase/v1/events` and `/customer` exist for POST; badly signed calls reach no handler.
Passes: both routes listed; unsigned, other-secret, 301-second-old, `v0=` and facts-secret calls answer 401; nothing hears them, the log is empty.
Fails: replies and customer questions meet "no route", or anyone can post fake replies.
**Handled once, recorded after, logged without people** — 200 and handled once per delivery id; a crash is retried; the last 20, no personal data.
Passes: three sends, one handling; a broken handling is redone on the retry; 20 rows after 25, newest first, ids, event and ticket number, no address, name or text.
Fails: a reply is posted twice or never, the log grows without end, or customer data is copied into the site's database.
**The customer receiver, and never 410** — its own secret, an empty object in round one; unreadable credentials 503; not connected never 410.
Passes: facts-signed → 200 `{}`, webhook-signed or unsigned → 401; after a salt change both receivers 503; an empty vault never answers 410.
Fails: OmnaBase's card errors for every customer, or OmnaBase switches the address off for good during a fixable outage.
## Plugin — `tests/Integration/DisconnectAndHealthTest.php`, `SecretsNeverRenderedTest.php`
**Disconnect and reconnect** — `DELETE /me` with the key, then forget, even on failure; admins with the token only; a reconnect stores the new key.
Passes: one `DELETE /me` with the stored key, vault empty, Connect offered; on failure empty and "API keys" named; forged clicks change nothing; new key's last four shown.
Fails: the key keeps working at OmnaBase, the site is stuck "connected", a forged link disconnects it, or the site keeps the stopped key.
**Check connection** — `GET /me` and `POST /me/ping` with the key; both results on the screen; a refused key shows as failed.
Passes: "me" ok at once, "ping" not ok until a signed ping arrives, then ok; with a 401, "me" is not ok.
Fails: a security plugin blocking OmnaBase goes unnoticed, or a dead key looks healthy.
**Reconnect needed** — unreadable credentials warn administrators on every admin page, nobody else, only while unreadable.
Passes: "OmnaBase: reconnect needed" on an admin's dashboard after a salt change, not for a subscriber, gone with the salts back.
Fails: replies stop for days while the warning sits on a page nobody opens.
**No leak** — the key and both secrets (the facts one has no prefix) never in pages, scripts, REST answers or the error log.
Passes: screen (connected, checked, offline), notices, admin and front head and footer, receivers, REST index, the log after failures: none hold them.
Fails: a visitor, extension, cache or log reader copies the key and acts as the site in OmnaBase.
## Plugin — `tests/e2e/connect.spec.ts` (`npx playwright test`; wp-env, OmnaBase's e2e server and web app)
**Connect end to end** — Connect → OmnaBase's page → the founder signs in by mailed code, approves Team Kriesi › Enfold → back connected.
Passes: the clean settings address reads "Connected to Team Kriesi", Enfold, the six permissions; OmnaBase lists one live key, its last four shown.
Fails: a site owner must copy a key, the way back loses the connection, or the key is printed into the page.
**Check connection green** — both directions work through the real relay.
Passes: "me" and "ping" turn ok within 30 seconds; OmnaBase's call log shows the ping accepted at `/wp-json/omnabase/v1/events`.
Fails: OmnaBase's calls are blocked while the screen looks healthy.
**Reconnect, Disconnect** — connecting again leaves one new live key; Disconnect leaves none.
Passes: one live key with new last four, shown; after Disconnect "not connected" with Connect and no live key at OmnaBase.
Fails: two working keys, the site keeps the stopped one, or the key outlives the owner's Disconnect.
**Cancel and a forged way back** — both store nothing.
Passes: Cancel returns "not connected" mentioning the cancel; a made-up code and state show an error; no key at OmnaBase either time.
Fails: an attacker's link connects the site, or a cancel leaves a key behind.
## OmnaBase — `apps/api/src/pluginRound1.test.ts` (Vitest, env sourced), `packages/db/src/structure74.test.ts`
**`DELETE /me`** — a key revokes itself and only itself, with its site's connect-made addresses, audited as the key.
Passes: 200/204, then 401 on `/me` and on a second call; a neighbour works and keeps its address; an audit row by the key without secrets.
Fails: Disconnect leaves the key alive, one site switches off another, or nobody can tell who disconnected.
**`POST /me/ping`** — one signed ping to the key's own site's address, nowhere else; a site without an address gets 409.
Passes: 2xx; one ping with a delivery id and a signature that checks with the site's secret; none at another site or a hand-made address.
Fails: the plugin cannot see whether OmnaBase reaches it, or a key makes OmnaBase call other addresses.
**`/me` slugs and the default view** — projects as `{ id, slug, name }`; `GET /tickets` without `view` is `all`, as documented.
Passes: Shop's id, slug and name; no `view` answers 200 with a resolved ticket, the same as `view=all`.
Fails: the plugin cannot build a form's address, or a client following the docs gets "No such view".
**Own tickets only** — a connect-made address hears its own site's tickets, never another site's or a mail ticket (review S4).
Passes: site one hears only its ticket, site two only its own; a hand-made address still hears all three.
Fails: a forum site receives the names and addresses of everyone who mailed the team.
**The records** — route table, guide, decisions and changelog carry round one.
Passes: both routes in the table without scope; the guide names them, the slugs and own tickets; "M13 round one" in DECISIONS; a changelog line that a site can disconnect itself.
Fails: the documentation and the server disagree again, as the default view did in round zero.

## Shapes pinned (chosen; the builder may rename and tell the red team)
`Connect\Pkce` (`verifier`, `challenge`, `state`); `Security\Signature::verify($secret, $ts, $raw, $header, ?$now)`; `Security\Vault` (`save`, `read`, `state` empty/ok/unreadable, `clear`, `keySource`; salts through `wp_salt()`, rotated in tests by the `salt` filter, so wp-env must not define `OMNABASE_ENCRYPTION_KEY`); admin-post actions and nonces `omnabase_connect`, `omnabase_disconnect`, `omnabase_check`; the callback at `load-settings_page_omnabase`; the action `omnabase_webhook($type, $payload, $delivery)`; `Rest\DeliveryLog::recent()`; `data-check="me|ping"` with `data-result="ok"`; pending-connect data under names holding "omnabase".

## Contradictions and open points
1. **Expiry**: the brief says 15 minutes, the coordinator 10; the test passes either (fine at 9, refused at 16). Record the choice in DECISIONS.
2. **`/me/ping`**: the brief "queues" (the screen waits 30 s), the coordinator "answers the result"; the tests accept both.
3. **Replay key**: the coordinator says event id, the docs delivery id; one per address either way, and replays resend both unchanged.
4. **Health loopback** (the brief's call to the site's own `/events`) against "no `wp_remote_*` to any host but OmnaBase's": allowed under `src/Api/` via `rest_url()`.
5. **`/me` projects as objects** breaks the docs and existing bare-id pins (`publicApiRound1Door` 188/192, `connectRound5` 368, `api-keys.e2e` 141/164, `connect-site.e2e`); an unlimited key's list is unspecified (only the shape is pinned).
6. The docs' connect example (`admin.php?page=omnabase`, `webhooks:manage`, `ticket.created`) differs from the brief; the tests follow the brief.
7. Beyond new code: `StoredKeyTest.php` goes; `round-trip.spec.ts` and the harness's `set-key` move to the vault.
