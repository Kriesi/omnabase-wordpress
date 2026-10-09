# M13 round five — red-team report (failing tests for the message centre)

2026-10-09, the red team (Opus 5.5). The tests the builder makes pass in round five: the `[omnabase_messages]` shortcode (a tag WordPress replaces with the plugin's output) on the page the administrator picks, the list, the thread, New ticket, Reply, the confirm prompt, the two refusals, a changed WordPress email, the no-cache marking, and OmnaBase's two changes (forum topics after confirmation; a new address clears the confirmation). They are **parked**, in neither repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m13-round5-parked/` with `plugin/…` and `omnabase/…` mirroring each repository's paths (10 files, none replaces an existing one). Copy them in as they are; the plain integration run picks the new tests up (no group). Runners used: `red-m13-round5-{run,int,e2e}.sh` beside the folder.

**Counts**: 57 cases in 9 test files plus the helper `RoundFiveTestCase.php`. Plugin: structure 7, wp-env integration 35, Playwright 8. OmnaBase: API 5, structure 2. **Each file fails today for its reason** (run 2026-10-09): structure 7 of 7 (no `src/Portal/`, `Sync/ProfileWatcher.php`, template, shortcode, `send_headers` hook, option, changelog line, flow); integration 35 of 35 (the tag left as typed, no forms, no hooks, no settings section); Playwright: the setup passed (a real connect, the founder's session, Mia's earlier mail as a ticket, her bbPress topic filed by the outbox as a ticket), then test 1 stops at the missing settings section, 7 not run; OmnaBase API 4 of 5 (topics never listed and 404; a new address still answers `confirmed: true`), the fifth a guard that passes today by design; OmnaBase structure 2 of 2. **Probes** (removed): a throwaway 120-line message centre built to the pinned shapes made 32 of the 35 integration tests pass, the three left at the settings section it lacked; in OmnaBase, two changed lines (`seenBy` with this site's `api` tickets, `confirmed_at` cleared on a new address) made 5 of 5 pass, `seenBy` alone left the Q5 test failing at `confirmed: false`.

Words: *site id*, the plugin's id for a WordPress user as OmnaBase knows them (`<user id>-<8 random characters>`); *confirm prompt*, the box asking a customer to prove their address through a link OmnaBase mails; *nonce*, a short-lived form token against forged requests; *hook*, a named moment where a plugin's code runs (`send_headers`: before the page's headers leave; `template_redirect`: before the page is built); *outbox*, the plugin's lasting list of calls still to make; *page builder*, a plugin such as Elementor that keeps a page's layout outside its stored content.

## Contradictions and choices (the brief wins unless marked; the coordinator's wording in quotes)

1. **The portal call**: "the portal calls by key with `{site, externalId, email}`": the API has no such body. Pinned `docs/public-api.md`: `PUT /customers/by-external/<site id> { email, name }`, then every portal call with `OmnaBase-Customer: <site id>`; the fake refuses unknown fields with 400, as OmnaBase does.
2. **Attachments** "as links through the portal": the brief puts attachments out of scope and the portal's answers carry none. Not pinned.
3. **The product select** on New ticket: the portal's create route takes `{ project?, subject, message }` only (400 otherwise). Not pinned; it needs an OmnaBase change if wanted.
4. **"The honeypot and the token as the form has"** beside the brief's nonce: pinned all three: the nonce, round four's `omnabase_hp`, and a token in the rendered form so the same form sent twice is one Idempotency-Key (chosen).
5. **Logged out**: "the form (if a project is set)" vs the brief's "a login link back, or the form with `guest="form"`". Pinned the brief.
6. **Refusal words**: "already in use for another account — contact support" vs the brief's two sentences. Pinned the brief's key phrases ("belongs to another account", "by email"; "deleted at your request").
7. **Cache-Control**: "no-store, private" vs the brief's "private, no-store": both directives, order free. A command-line PHP records no headers and `nocache_headers()` stops once output began, so the integration layer checks `DONOTCACHEPAGE` and LiteSpeed's `litespeed_control_set_nocache` during WordPress's own request (no page built); the header itself is checked in the flow (customer, visitor, Ben, the page-builder page).
8. **The hook**: the brief's `send_headers` (review S6 said `template_redirect`). Pinned `send_headers`, matching the page, not its content.
9. **"A page without the pick → an admin-only notice"**, chosen: the shortcode on any other page, or with no page picked, shows a customer nothing private (that page gets no no-cache marking) and the administrator a notice naming the page setting.
10. **A changed email**: "sent at once" vs the brief's "queue": both pass (the test works the outbox before looking). Only users OmnaBase knows are sent (choice 5's road not taken).
11. **"The form ticket at once"** (the flow): a shortcode-form ticket is a `form` ticket, which OmnaBase shows only after confirmation (M12 round four). Pinned the ticket filed through the message centre's New ticket as the one shown at once.
12. **Forum topics**: "site tickets filed by a confirmed address of the identity" vs the brief's "this site's `api` tickets filed under their customer". Pinned the brief (this site only, same customer, never spam). They differ only for a topic filed from an unconfirmed second address of that customer. **Unruled**: once confirmed, a second WordPress account with the same address also sees the first account's message-centre tickets on this site (same customer, `api`, this site); M12's "by identity" rule covers only the unconfirmed state. Ruling wanted.
13. **Chosen**: while OmnaBase says unconfirmed every view asks again (OmnaBase never calls the site when the link is opened); confirmed and unchanged, no `PUT` per view (the brief's fingerprint); the prompt's own limit is OmnaBase's number, three links a day per visitor, the fourth never sent; the address after "We sent a link to" partly hidden (the brief's "a…@example.com"); a message whose kind is not `you`, `team` or `other` never shown.
14. **The structure guard**: a file that names the `OmnaBase-Customer` header may not read `$_GET`, `$_REQUEST`, `$_COOKIE` or a request header (the brief's "no `$_GET`/`$_REQUEST` in the identity path"), so the header lives in a layer that is handed the site id.
15. **Not covered**: WooCommerce's My Account "Support" link and its account-edit hook (they need the shops run); "theme styles only" (Q4); OmnaBase's e2e server needs no new hook (the flow uses `/api/test/inbound`, `/mail`, `/limits`, `/tick`).
16. **The tests' harness boots the plugin twice** (WordPress's own load, then the test's admin boot), so counts on a plain view are "at least one", never "exactly one"; form posts stop at the first handler's redirect. **Existing tests to change**: none expected in either repository.

## Plugin — `tests/Structure/RoundFiveStructureTest.php` (`composer test`)
- **Parts exist** — Passes: `src/Portal/{Shortcode,Controller,Views,NoCache}.php`, `src/Sync/ProfileWatcher.php`, `tests/e2e/messages.spec.ts`, each PHP file with the direct-access stop. Fails: one tangled class where a list change breaks the cache protection.
- **Shortcode and hook** — Passes: `add_shortcode( 'omnabase_messages' )` and an action at `send_headers`. Fails: the shortcode does nothing, or no-cache headers come after the headers left (S6).
- **Identity path** — Passes: a file names `OmnaBase-Customer`; no such file reads `$_GET`, `$_REQUEST`, `$_COOKIE`, `$_SERVER['HTTP_…']`; `src/Portal/` reads no `$_REQUEST` or `$_COOKIE`. Fails: a later change lets the address bar choose whose tickets show.
- **Template escapes** — Passes: a front-end template holding "messages" or "portal", the stop, every echo escaped (`_e` refused). Fails: a subject or message runs script in a signed-in browser.
- **Scripts hold no secret** — Passes: any `assets/*messages*`/`*portal*` script holds no `obw_`, `whsec_`, Authorization, Bearer, omnabase.com or the header. Fails: the browser gets the key or calls OmnaBase itself.
- **Stored names** — Passes: an option holding "portal" or "messages" on `Options::ALL` and in uninstall.php; every `_META` constant in uninstall.php. Fails: the pick or a fingerprint outlives the plugin.
- **Records** — Passes: a changelog line about the message centre; a dated "M13 round five" in DECISIONS. Fails: owners are not told.

## Plugin — `tests/Integration/MessageCentreViewTest.php` (`composer test:integration`, plain run; helper `RoundFiveTestCase.php`)
- **Logged out** — Passes: no raw tag, a wp-login.php link whose `redirect_to` is the page, no ticket, no call. Fails: visitors see the tag, someone's tickets, or land elsewhere after signing in.
- **`guest="form"`** — Passes: round four's form (message box, email field), no portal call. Fails: shops without accounts cannot take questions there.
- **Named by site id** — Passes: first call `PUT …/by-external/<site id>` with exactly email and name; every portal call `OmnaBase-Customer: <site id>`. Fails: identity by address alone (S6) or by a reusable number.
- **The list** — Passes: subjects newest change first; each row a status word, its last change's year, a link with `omnabase_ticket=<n>`. Fails: customers cannot tell what is answered or open a thread.
- **Paging** — Passes: two rows a page; the next link carries OmnaBase's cursor and the next view asks `after=<cursor>`. Fails: many tickets, one page; or the first page repeated.
- **The thread** — Passes: the visitor's, the team's (its name) and "Another participant"'s words; kinds note and system never; no script or handler. Fails: internal lines shown, or a message runs code as the customer.
- **Another's number** — Passes: Ben's number for Ada reads "not found", none of his words; letters or a path never reach OmnaBase's path. Fails: reading another conversation by editing the address.
- **Request changes nothing** — Passes: `?customer=`, `?omnabase_customer=`, `?externalId=`, `?user_id=`, `?email=`, two cookies and an `OmnaBase-Customer` request header naming Ben: Ada's id and address only. Fails: a cookie or parameter opens someone's history.
- **Two customers** — Passes: Ada's list holds hers only; Ben's straight after holds his only, naming Ben. Fails: a list kept on the site for one is shown to the next.
- **Reused number** — Passes: Ada deleted, a new account under her number: a new suffix, none of her tickets, her old id never asked. Fails: a restore hands a stranger the deleted customer's history.
- **No stored value** — Passes: neither key nor secret in the logged-out page, list, thread or scripts. Fails: the key that reads every ticket is in a customer's page.

## Plugin — `tests/Integration/MessageCentreConfirmTest.php`
- **Prompt until confirmed** — Passes: unconfirmed Ada: "Show my email conversations" and a six-word reason beside it; confirmed Ben: none. Fails: customers never find their history, or confirmed ones are nagged.
- **One request, the sentence** — Passes: one `POST /portal/confirm` naming Ada; back on the page "We sent a link to", "@example.com", "24 hours", not her full address. Fails: nothing happens, several mails, or the whole address on screen.
- **Prompt leaves** — Passes: after OmnaBase counts the link opened, the next view asks again and shows no prompt. Fails: the prompt stays for ever.
- **Fingerprint** — Passes: confirmed and unchanged, the second view sends no `PUT` and still reads the list. Fails: an extra call per view on a busy shop.
- **Limits, plain refusal** — Passes: four posts of the form, three reach OmnaBase; OmnaBase's 429 reads without "rate", "429", "Too many requests" and claims no link. Fails: a script fills an inbox, or a technical error shows.
- **Nonce** — Passes: without the nonce, or logged out, nothing is sent. Fails: another site makes a customer's browser send confirmation mails.

## Plugin — `tests/Integration/MessageCentreWriteTest.php`
- **New ticket** — Passes: one `POST /portal/tickets` naming Ada, an Idempotency-Key, only subject, message and at most the site's one project; back on the page, the subject shown. Fails: questions lost, filed as someone else, or a blank page.
- **One form, one ticket** — Passes: the same form twice: one key, one ticket; a fresh form: a second ticket. Fails: a double click files twice, or a second question is swallowed.
- **Trap field** — Passes: `omnabase_hp` on the form; filled, no call. Fails: bots riding a session file tickets.
- **Nonce, signed in** — Passes: no nonce, or logged out with it: no call. Fails: forged tickets in a customer's name.
- **Reply reopens** — Passes: one `POST /portal/tickets/<n>/messages { body }` naming Ada; back on the thread, her words, status open. Fails: answers vanish or stay on a resolved ticket.
- **Another's number in the form** — Passes: Ada's form changed to Ben's number: Ben's ticket untouched, only Ada named, "not found". Fails: writing into another customer's conversation.
- **Refusals in plain words** (3 cases: 429, 403 `plan_limit`, 400) — Passes: a sentence without "plan", "allowance", "workspace", codes or schema words; no success claimed. Fails: customers read the owner's subscription or believe a refused question went.

## Plugin — `tests/Integration/MessageCentreIdentityTest.php`
- **`address_taken`** — Passes: "belongs to another account … by email"; no portal call, no prompt, no code, "plan" or OmnaBase's words. Fails: an error code or a list that is not theirs.
- **`forgotten`** — Passes: "deleted at your request"; no portal call or OmnaBase wording. Fails: an error, or the visit re-adds a forgotten person.
- **Changed email** — Passes: after the outbox runs, a `PUT` with the new address; the next view shows the prompt, still by site id. Fails: a taken-over account keeps the old history.
- **Only known users** — Passes: Ada's change is sent, never-linked Carl's is not. Fails: every profile edit makes an OmnaBase customer (or none is sent).
- **No new address, nothing sent** — Passes: after Ada's first view, a biography change sends no `PUT`. Fails: a call and a row per profile save.

## Plugin — `tests/Integration/MessageCentrePageTest.php`
- **Never cache, at the request** — Passes: another page (even with the tag): no LiteSpeed action; the picked page for a customer and a visitor: the action each time, `DONOTCACHEPAGE` true, nothing rendered. Fails: a cache serves one customer's page to the next, or the site loses its cache.
- **Page builder** — Passes: a picked page whose content lacks the tag still gets the action. Fails: Elementor-style sites cache the message centre.
- **Elsewhere** (2 cases: another page, no pick) — Passes: a customer sees no list, prompt or tag and nothing is asked; the administrator a notice about the page. Fails: a list on an unprotected page, or a silent empty box.
- **Settings pick** — Passes: `.ob-wp-settings__portal`, "[omnabase_messages]", `omnabase_portal[page]` listing pages, the pick selected; saving stores it. Fails: the owner cannot choose the protected page.
- **Only an administrator with the nonce** — Passes: the section exists; no nonce or an editor: the pick unchanged. Fails: a forged link moves the centre to an unprotected page.
- **30 words** — Passes: every description in the section ≤ 30 words. Fails: walls of text.

## Plugin — `tests/e2e/messages.spec.ts` (`npx playwright test`)
- **The pick** — Passes: the section offers the run's page; saved, it stays selected. Fails: no page is protected.
- **Logged out** — Passes: private, no-store; a login link back to the page; no subjects. Fails: an empty box or a cached page.
- **The story** — Passes: Mia's page private and no-store, the prompt, no history; her New ticket shown and an `api` ticket of hers in the inbox; "We sent a link to"; the captured link confirms; then no prompt, the mail conversation and the forum topic listed. Fails: history on the site's word, never, or questions lost.
- **Answer, never the note** — Passes: the founder's note and reply (mail out on for the test); after the sender's pass the thread holds the answer, not the note. Fails: notes leak or answers never show.
- **Reply reopens** — Passes: the resolved ticket, Mia answers: her words shown, OmnaBase reads it open with her message last. Fails: answers vanish.
- **Ben sees nothing** — Passes: private, no-store, none of Mia's subjects; her number "not found"; `?customer=` with her site id changes nothing. Fails: another customer's history by the address bar or a cache.
- **Changed email** — Passes: after `wp user update` and the outbox, the prompt, her own ticket only; the next link mailed to the new address, none to the old. Fails: a taken-over account keeps the history.
- **Page builder** — Passes: the picked builder page answers no-store and private. Fails: builder sites cache it.

## OmnaBase — `apps/api/src/pluginRound5.test.ts` (Vitest, the default test database)
- **Q6: topics after the link** — Passes: topics filed before and after the user unlisted and 404 (no reply) while unconfirmed; after the mailed link listed, 200, the reply 201 as the customer's. Fails: forum topics never show, or show on the site's word alone.
- **A topic's thread** — Passes: confirmed: the site author's answer "Nina Moderator on <host>" and the first post, never the note. Fails: opening topics opens notes.
- **This site's only** — Passes: the other site's topic for the same person, a stranger's topic, a spam topic: unlisted and 404. Fails: an unrelated site's forum or spam in the shop's centre.
- **Q5: a new address clears** — Passes: `PUT` with a new address answers `confirmed: false`; the identity's confirmation empty, its address the new one; the new address on the customer unconfirmed; mail and topic hidden, own ticket kept; the link to the new address only; after it everything back, proven, `confirmed: true`. Fails: a taken-over account keeps the history, or the centre never asks again.
- **Q5 guard** (passes today) — Passes: capitals and spaces, or a new name: still `confirmed: true`. Fails: customers asked to confirm on every visit.

## OmnaBase — `packages/db/src/structure77.test.ts`
- **The guide** — Passes: the message-centre section names this site's forum topics after confirming; a new address clears or asks again. Fails: plugin developers think topics never show.
- **Records** — Passes: a dated "M13 round five" in DECISIONS; changelog lines, each in one sentence, for topics in the message centre and a changed address asking again. Fails: the change is not written down.
