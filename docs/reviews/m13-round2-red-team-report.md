# M13 round two — red-team report (failing tests for customer data)

2026-10-09, the red team (Opus 5.5). The tests the builder makes pass in round two: OmnaBase's question about a customer (the facts call) answered from the switched-on sources, the shaper, the outbox, the licence copy, the settings screen's two new sections. They are **parked**, in neither repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m13-round2-parked/plugin/…` mirrors this repository's paths (19 files; three of them replace existing files: `tests/Integration/bootstrap.php`, `phpunit-integration.xml`, `bin/test-integration.sh`). Copy them in as they are. **No OmnaBase change is needed.**

**Counts**: 118 test cases in 9 files (unit 59, structure 7, wp-env integration 48 plus a helper class, Playwright 4), plus a contract oracle, its verdict file and three fakes. **Each fails today for its reason** (run 2026-10-09): unit 64 of 66 with structure (classes `Facts\Shaper`, `Outbox\Policy` missing; round files, uninstall entries, the changelog line missing); integration 46 of 48 (the receiver answers `{}`, no `Sync\SiteId`, no outbox table, no screen sections); Playwright stops at the missing customer-data section after a real connect, a ticket and the founder's sign-in all worked (3 serial tests skipped). **Passing today on purpose** (guards): the oracle agrees with OmnaBase, the fakes stay in `tests/`, every source off by default, an unknown address answers `{}`. The unit tests went green against a throwaway shaper and policy (deleted); the flow's two premises were probed (the card shows a block the site answers; the refresh is 409 `facts_failed` with the relay off, 200 with it on). Round one's 31 integration tests still pass.

Words: *facts call*, OmnaBase's signed question to `/wp-json/omnabase/v1/customer`; *block*, the `omnabase` part of the answer; *usermeta*, extra fields per user; *outbox*, the lasting list of calls still to make; *WP-Cron*, WordPress's timer; *HPOS*, WooCommerce's newer order tables; *fake*, a stand-in for a paid add-on, test code only.

## Plugin — `tests/Unit/FactsShaperTest.php` (`composer test`; judge `tests/Unit/Support/SiteFactsOracle.php`)
**The judge is OmnaBase's own** — the PHP oracle accepts exactly what OmnaBase's reader accepted in 58 recorded answers (`tests/fixtures/site-facts-zod.json`, made by `site-facts-zod.mts` from OmnaBase's code).
Passes: every recorded verdict matches. Fails: the stand-in drifted, so the shaper's tests could pass while the team's card shows an error.
**Refused answers become accepted ones** (22 cases) — 2 000-character, accented, emoji, list, object, non-UTF-8 and infinite meta values; an 80-character meta key; empty meta and profile; a null date; numbers where text belongs; a blank number or product; long status, product, site, link; list keys with gaps.
Passes: each raw answer is refused (or cannot be encoded), each shaped one accepted, long values cut not dropped, the good orders and meta kept. Fails: one odd field blanks the shop's whole section on the card.
**Twenty newest, keys masked, `{}` for nothing** — 25 orders/subscriptions/licences give the first 20, 30 sites give 20; a full key becomes `••••-7Q2X`; nothing found is exactly `{}`.
Passes: as stated, the full key nowhere. Fails: old orders shown, licence keys copied into OmnaBase, or `[]` refused as a list.
**Banned usermeta** (24 names) — `user_pass`, `session_tokens`, `_two_factor_backup_codes`, `wp_capabilities`, API keys, tokens, salts, card, IBAN, purchase codes, licences … in any capitalisation.
Passes: each absent with its value, billing country and nickname beside it sent. Fails: a password hash, session or backup code reaches the card and the AI.
**Under 128 KB as sent** — 30 meta values of 1 000 accented letters (60 KB as UTF-8, 180 KB as WordPress escapes them); a 156 KB answer with long links and site lists.
Passes: under 128 KB and accepted; the profile left out first, then subscriptions; all 20 licences and orders stay; the left-out sections named. Fails: OmnaBase stops reading at 128 KB, or the licences are the ones dropped.
## Plugin — `tests/Unit/OutboxPolicyTest.php` (`composer test`)
**Sent, waiting or needing attention** — what each answer means for a row.
Passes: 2xx sent; 429 waits exactly its Retry-After (seconds or a date, never dead-letters); network errors and 5xx wait 1, 5, 15, 60 minutes then hourly; 409 `facts_failed` retries; 400, 404, 409 `forgotten`/`address_taken`/`no_endpoint` need attention with a reason in words; failing over 3 days needs attention. Fails: rows hammer OmnaBase, vanish, or retry hopelessly for ever.
## Plugin — `tests/Structure/RoundTwoStructureTest.php` (`composer test`)
**Parts, SQL, storage, uninstall, records** — the brief's classes, seven classes implementing `FactsSource`; `$wpdb` only in `src/Outbox/Table.php`; orders via `wc_get_orders`, never `shop_order` posts; HPOS compatibility declared; the banned list written once.
Passes: all present. Fails: sources tangled into one class, SQL spread around, orders missed on HPOS shops, two banned lists drifting apart.
**Uninstall and the fakes** — `omnabase_facts` and `omnabase_db_version` listed; uninstall drops `omnabase_outbox`, clears the `omnabase_outbox` job, deletes the site ids; fakes never named by runtime code nor zipped; changelog and DECISIONS lines.
Passes: as stated. Fails: customers' ids and queued calls stay after deletion, or a fake add-on ships to customers.
## Plugin — `tests/Integration/FactsIdentityProfileTest.php` (`composer test:integration`, second run; helpers `RoundTwoTestCase.php`)
**Off by default, `{}` for strangers** — nothing switched on answers `{}`; an unknown address answers 200 `{}`.
Passes: as stated. Fails: connecting starts sharing everything, or the card errors for unknown people.
**The profile** — registered since and roles; usermeta only when ticked; ticked values shaped; banned ones never; a user without meta accepted.
Passes: `2021-03-04`, `["customer"]`, billing country, a cut bio; no list value, password hash, token, backup code or API key. Fails: unchosen or secret meta leaves the site, or one odd value blanks the section.
**The site id** — `<user id>-<8 characters>`, made once; wins over the address; a reused user number never matches an old id.
Passes: stable, unique, leads back; Ben's id with Ada's address answers Ben; the deleted user's id names nobody. Fails: a colleague's orders show, or a stranger inherits a deleted customer.
**Switched off is absent; the link** — only the profile on: no orders/subscriptions/licences keys; an address known here but not by id queues one `user_link`, sent as `PUT …/customers/by-external/<site id>` with email and name.
Passes: one row for Ada (none for Ben's id or a stranger), sent with the key, marked sent. Fails: OmnaBase never learns the site's ids, or every card view adds a row.
## Plugin — `tests/Integration/FactsWooTest.php`
**Orders** — number as text, `2026-09-30`, total `59.00 EUR` without HTML, status, an https wp-admin link; 25 orders give the newest 20; a guest found by billing address; both order storages answer alike.
Passes: as stated, legacy and HPOS answers equal. Fails: the card shows price HTML, old orders, nothing for guests or for HPOS shops.
**Speed, switch, Subscriptions, License Manager** — 500 orders in under a second and 128 KB; orders absent with the switch off; the Subscriptions fake gives product, status, renewal; License Manager gives product, `••••-7Q2X`, status, expiry, no sites.
Passes: as stated, the full key nowhere. Fails: slow cards (OmnaBase allows 5 seconds), a switch that does not stop data, or licence keys copied.
## Plugin — `tests/Integration/FactsEddTest.php`
**EDD 3 and its add-ons** — orders (`39.00 EUR`, `complete`); a buyer with two addresses found by either; Software Licensing (fake) with product, `••••-9K4M`, status, version 2.4.1, expiry, sites; Recurring (fake) with product, status, renewal; each source its own switch.
Passes: as stated, Ben's licence never in Ada's answer. Fails: EDD buyers invisible, the full key copied, or one switch opening another source.
## Plugin — `tests/Integration/OutboxAndLicenceCopyTest.php`
**The table and the timer** — `wp_omnabase_outbox` with id, kind, attempts, next_at, state, last_error; `omnabase_db_version` set; the job `omnabase_outbox` every 60 seconds.
Passes: as stated. Fails: an updated site without the table loses every licence change, or rows wait for a click.
**Each licence event queues one refresh** (6 cases) — a Woo order status change, a Subscriptions status change, an EDD order status change, `edd_sl_post_set_status`, `edd_sl_activate_license`, License Manager's `lmfwc_event_post_order_license_keys`.
Passes: one waiting `licence_refresh` row for Ada each time. Fails: OmnaBase's licence copy goes stale after a renewal, refund or activation.
**Dedupe and who is known** — two changes, one row; a change after sending, a new row; nothing for a user OmnaBase does not know; asked about by site id counts as known.
Passes: as stated. Fails: bursts of refreshes (each makes OmnaBase call the site), swallowed later changes, or a pile of 404s.
**Sending** — `POST …/by-external/<site id>/refresh` with the key and a timeout of at least 15 seconds; down: waiting, one attempt, a reason, next try a minute later, then sent; 429: an hour later, not tried before; 400: attention, row kept.
Passes: as stated. Fails: refreshes time out, an outage loses them, the limit is hammered, or refused rows vanish.
**At once but not inline; one sender; 20 a run; no key, no send** — nothing sent inside the shop's own step, then sent at shutdown or a run within 5 seconds; an overlapping run sends nothing twice; 25 due rows: 20 sent, 5 wait; an emptied vault: no call, rows wait.
Passes: as stated. Fails: checkouts hang up to 15 seconds, topics go twice, one visit carries a backlog, or a disconnected site loses its queue.
## Plugin — `tests/Integration/RoundTwoScreenTest.php`
**Customer data section** — a checkbox per detected source (all seven here), none checked; Save (`omnabase_save_facts`, its nonce, `manage_options`) stores exactly the ticks; the picker offers billing_country, hides `_private_flag`, `session_tokens`, `api_token`.
Passes: as stated; no token or a subscriber stores nothing. Fails: sharing turned on by a forged link, or secret meta offered.
**Outbox section** — a row needing attention shows its reason with Retry (`omnabase_outbox_retry`) and Discard (`omnabase_outbox_discard`); Retry makes it wait, due now; Discard needs the token; descriptions at most 30 words.
Passes: as stated. Fails: stuck calls invisible or impossible to resend or clear.
## Plugin — `tests/e2e/facts.spec.ts` (`npx playwright test`; wp-env, OmnaBase's e2e stack on 3011/4391)
**The card shows the shop** — a real Connect, Woo and EDD Software Licensing on, Ada's ticket opened by the founder: the card shows her seeded order, "Omnalingo Pro", 2.4.1, `9K4M`, never the key; the relay log shows the signed `/customer` call answered 200.
Passes: as stated. Fails: the team answers licence questions blind, or OmnaBase refuses the site's answer.
**Woo off; the link; the outage** — with WooCommerce unticked the order vanishes, the licence stays; the outbox sends Ada's site id and OmnaBase finds her by it; with the relay off a refresh waits (409 `facts_failed`), with it back it is sent.
Passes: as stated. Fails: a withdrawn source still flows, no refresh can reach a customer, or an outage loses a licence change.

## Shapes pinned (chosen; the builder may rename and tell the red team)
`Facts\Shaper::shape(array): ['answer' => object, 'dropped' => string[]]` (drop order profile, subscriptions, orders, licences last); `Outbox\Policy::decide($outcome, $row, $now)`; `Sync\SiteId::forUser()/userFor()`; option `omnabase_facts` (`sources` by id: `profile woocommerce woocommerce_subscriptions edd edd_recurring edd_software_licensing lmfw`, `meta` list); table `{prefix}omnabase_outbox` (states `waiting sent attention`, kinds `user_link licence_refresh`) made on load when `omnabase_db_version` is missing or older; WP-Cron hook `omnabase_outbox`; admin actions `omnabase_save_facts`, `omnabase_outbox_retry`, `omnabase_outbox_discard`; sections `.ob-wp-settings__facts`, `.ob-wp-settings__outbox`; dates `Y-m-d`; fakes' API as in `tests/fakes/` (the real add-ons' public names, data in `omnabase_fake_*` options).

## Contradictions and open points
1. **The contract has no display name, `found`, currency or items** (`siteFacts.ts`): the profile is registered/roles/meta; the total carries the currency as text (`59.00 EUR`, the docs' example). Tests follow the contract; nobody found is `{}` (the brief), not an empty profile.
2. **No user**: the coordinator says empty answer, the brief says guest records by address; the tests follow the brief (Woo billing address, EDD customer addresses).
3. **Outbox payload**: the coordinator lists a payload column, the brief builds the body when sent; neither is pinned.
4. **Dead-letter N**: chosen 3 days (OmnaBase retries ~45 hours); 429 never; `409 facts_failed` retries, `no_endpoint` needs attention.
5. **License Manager 3.0.20 has no licence-edit or activation action**: only `lmfwc_event_post_order_license_keys`; an admin's edit there refreshes nothing until the order changes. Record in DECISIONS.
6. **Known to OmnaBase** (chosen): link accepted, or asked about by site id; others get no refresh.
7. **Banned list** = the brief's parts plus the coordinator's `salt`, `capabilities`; `code` also bans `billing_postcode`, `key` any `*_key` field (the brief's cost, worth one DECISIONS line).
8. **zod 4.6 counts code points**, not UTF-16 units (verified); 128 KB counts the bytes WordPress sends (`\uXXXX`, `\/`).
9. **The flow connects for real** instead of the harness's vault write: only Connect gives OmnaBase the site's endpoint with its site (the refresh needs it). "OmnaBase down" is the relay off (the refresh's own failure, 409 `facts_failed`).
10. **The shops load in a second integration run** (`OMNABASE_TEST_SHOPS=1`, `@group shops`): with them loaded, WooCommerce's admin phones home and EDD's notices break round one's tests; EDD needs `EDD_DOING_TESTS` for its tables. The builder: load `tests/fakes` in wp-env's development WordPress when the paid add-ons are absent (the flow writes its own fake licence).
