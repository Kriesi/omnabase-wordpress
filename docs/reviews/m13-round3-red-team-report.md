# M13 round three — red-team report (failing tests for the forum)

2026-10-09, the red team (Opus 5.5). The tests the builder makes pass in round three: topics in marked bbPress forums become tickets through the outbox, forum replies reach the ticket, OmnaBase's answers come back as forum replies by the support user, the forum section of the settings screen, and OmnaBase's new `siteAuthor` field. They are **parked**, in neither repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m13-round3-parked/` with `plugin/…` and `omnabase/…` mirroring each repository's paths (13 files). Four replace existing test setup: `tests/Integration/bootstrap.php` (loads bbPress when `OMNABASE_TEST_FORUM=1`), `phpunit-integration.xml` (the plain run leaves the group `forum` out), `bin/test-integration.sh` (a third run, the group `forum`), `tests/e2e/harness.ts` (a fix, below). Copy them in as they are.

**Counts**: 62 cases in 9 files. Plugin: structure 7, wp-env integration 42 (plus the helper `RoundThreeTestCase.php`), Playwright 7. OmnaBase: API 4, structure 2. **Each fails today for its reason** (run 2026-10-09): structure 7 of 7 (no `src/Forum/`, option, post meta in uninstall, idempotency column, changelog line); integration 39 of 42 (nothing listens to bbPress: no ticket call, no row, no section, no option; every webhook answered 200 and nothing posted); Playwright stops at the missing forum section after a real connect and two sign-ins worked (6 serial tests not run); OmnaBase API 3 of 4 (`400 unrecognized key "siteAuthor"`), structure 2 of 2. **Pass today on purpose** (guards): an unmarked forum and a trashed topic send nothing; replies in an unmarked forum send nothing; only an administrator with the form token saves; OmnaBase's refusals of misplaced `siteAuthor`. Probes (removed): with a ticket number faked onto topics, the receiver and reply tests fail at their own assertions (0 replies posted, 200 instead of 409, 0 message calls), not in setup; the OmnaBase test with a member instead of `siteAuthor` fails only at the author's name, and its portal path passes; bbPress's own forms, the reply markup and the closing-line regex were proved in wp-env's browser. Round one's 31 plain integration tests still pass with the new setup.

Words: *topic*/*reply*, bbPress's forum posts; *marked forum*, ticked by the administrator; *hidden forum*, moderators only; *private forum*, every logged-in user; *support user*, the WordPress user OmnaBase's answers appear under; *outbox*, the lasting list of calls still to make; *idempotency key*, a one-time id that makes a resend count once; *echo*, the site's own posting coming back to OmnaBase as new.

## Plugin — `tests/Structure/RoundThreeStructureTest.php` (`composer test`)
**The parts exist** — `src/Forum/{Triggers,TopicSender,ReplySender,ReplyPoster,SubscriberMails,Closer}.php`, `tests/e2e/forum.spec.ts`. Passes: all present. Fails: the forum tangled into one class.
**Hooks named, answers through bbPress** — the six bbPress moments plus `transition_post_status`; `bbp_insert_reply`, `wp_kses_post`. Passes: each registered and called. Fails: wp-admin, approved or rescued topics missed; uncleaned HTML.
**Echo marker written once** — `_omnabase_message_id` in one file of `src/`; `_omnabase_ticket` named. Passes: as stated. Fails: poster and sender check different markers, answers echo.
**Nothing answers 410** — no `410` in `src/Rest` or `src/Forum`. Passes: none. Fails: one trashed topic switches the site's address off for good.
**Idempotency key per row** — an `idempotency_key` column, table version above `1`. Passes: as stated. Fails: a lost answer makes two tickets; updated sites keep the old table.
**Uninstall** — `omnabase_forum` in the list; post meta `_omnabase_ticket`, `_omnabase_message_id` deleted for every post; ≥3 jobs cleared. Passes: as stated. Fails: forum choices and markers stay after deletion.
**Records** — a changelog line about forum topics and tickets; a dated "M13 round three" in DECISIONS. Passes: both. Fails: site owners not told.

## Plugin — `tests/Integration/ForumTopicsTest.php` (`composer test:integration`, third run; helpers `RoundThreeTestCase.php`)
**One public ticket from a topic** — Ada's topic in Support. Passes: one `POST /tickets` with the project, `{ada@example.org, Ada Lovelace}`, title, text, `external {id: "topic-<id>", url: permalink}`, `replyVia: "site"`, `public: true`; topic meta `_omnabase_ticket` = 1001. Fails: topics never reach the inbox, or answers are mailed instead of posted.
**Every way to publish, exactly one ticket** (4 cases: front form, wp-admin, held then approved, spam then unspammed). Passes: nothing while held or spam, then one call. Fails: missed topics, spam in the inbox, two tickets for one topic.
**Every forum but a hidden one is public** (3 cases, review B1). Passes: public `true`, private `true`, hidden `false`. Fails: a customer's orders answered where every customer reads.
**Unmarked forum, trashed topic** — guard. Passes: no row, no call. Fails: the whole site floods the inbox, or removed topics still arrive.
**Plain text within limits** — HTML with a script, 60 000 characters, a 250-character title. Passes: words kept, no tags or script, ≤50 000 and ≤200. Fails: OmnaBase refuses it for ever, or the team reads raw HTML.
**Guests** — given name and address used; no address: no call, the row needs attention naming the email; guests switched off: no call. Fails: guests vanish silently or OmnaBase is sent what it must refuse.
**Same Idempotency-Key on every try** — 503 then 201. Passes: both tries share one key (≤200 visible characters), another topic has another. Fails: a hiccup makes two tickets.
**Four 503s end in one ticket** (review B2). Passes: five tries, number kept, row sent. Fails: an outage drops a topic.
**The daily limit holds topics until tomorrow** (B2) — 60 topics, 50 taken, 10 answered 429 with Retry-After 7200. Passes: 50 numbers, 10 waiting (none needing attention) at least two hours ahead; the screen says "10 topics waiting" and "daily limit"; next day all 60 filed, 60 distinct references. Fails: the busiest day loses questions, or nobody sees why.
**A repeat is success, a conflict needs attention** — a 200 with ticket 4242 stores 4242; a 409 leaves no number and needs attention with OmnaBase's words. Fails: topics refiled for ever, or refused ones forgotten.
**One topic, one row** — front form fires two moments. Passes: one waiting row. Fails: two rows race and file twice.

## Plugin — `tests/Integration/ForumRepliesTest.php`
**The author's reply is the customer's** — Passes: one call to ticket 1001: `from: customer`, plain text, `external {id: "reply-<id>"}`, an Idempotency-Key, no member, customer, delivered, siteAuthor or kind. Fails: follow-ups stay on the forum or are filed as someone else's.
**A moderator's reply** — Nina. Passes: `from: team`, `delivered: true`, `siteAuthor.name` "Kriesi Support" (the support user's name), `reply-<id>`, no customer, not a note. Fails: the ticket waits on the team, the customer is mailed again, or the answer hides in a note.
**Others, the support user, marked replies never sent** — Ben on Ada's topic; the support user by hand; Nina's reply carrying `_omnabase_message_id` written after saving. Passes: no message call, nothing needing attention. Fails: strangers' posts on Ada's ticket; answers twice on the ticket.
**Every way to publish a reply, once** (4 cases). Passes: nothing while held or spam, then one call. Fails: replies lost or doubled.
**A reply waits for its topic's ticket** — topic refused 429, Ada replies. Passes: no call, reply row waiting; once filed, the reply goes to 1001 after the topic. Fails: follow-ups dropped for want of a ticket.
**Edits and deletions not synced** — Passes: editing and trashing make no call. Fails: a typo fix posts the reply twice.
**Unmarked forum replies** — guard. Passes: no row, no call. Fails: a kept-out forum leaks through replies.

## Plugin — `tests/Integration/ForumReceiverTest.php`
**Posted by the support user with a closing line** — Passes: 200; one published reply by the support user, `<strong>` kept, `<script>` gone, "— Ada, Team Kriesi", `_omnabase_message_id` = msg-1, reply count 1, last reply it. Fails: no answer on the forum, wrong author, unsafe HTML.
**Posting queues nothing** (echo) — Passes: `bbp_insert_reply` fired, no reply row, no message call. Fails: every answer twice on the ticket.
**Redelivery and same message once** — Passes: same delivery twice and a second delivery id all 200, one reply. Fails: retries post the answer two or three times.
**Trashed or deleted topic: 409** — Passes: 409, nothing posted, the log row not "handled"; restored, the same delivery 200 and posted; deleted 409. Fails: OmnaBase thinks it was posted, or one topic switches the forum off.
**2xx only after the reply exists** — saving blocked. Passes: an error (not 410), nothing posted; then 200 and posted. Fails: a reply marked delivered that never appeared.
**A closed topic still gets the reply** — Passes: 200, published (not stored "closed", which bbPress hides). Fails: lost or invisible answers.
**Subscriber mails after the answer** (S3) — Ben follows the topic. Passes: no mail inside the receiver, a plugin job left, then Ben mailed once. Fails: slow mail makes OmnaBase retry and post twice; subscribers never told.
**Closing line setting** — Passes: "Cheers, Ada from Team Kriesi", not the default. Fails: the owner cannot word the signature.
**Only the team's replies on the site's topics** — Passes: a customer's message and a ticket without reference: 200, nothing; no support user: an error other than 410, nothing. Fails: customers' words under the support user; replies under no one.
**Resolve closes only when switched on** (2 cases: `ticket.updated` resolved, `ticket.resolved`). Passes: off stays open, on closes. Fails: topics close behind the owner's back.

## Plugin — `tests/Integration/ForumScreenTest.php`
**Every forum with a tick** — `.ob-wp-settings__forum`, `omnabase_forum[forums][]` by id. Passes: four boxes, the marked three ticked; "not public" for hidden; edits not sent; guests' addresses unchecked. Fails: the owner cannot choose or does not learn what is public.
**Saving stores exactly the choices** (`omnabase_save_forum`). Passes: two forums, Nina, the line as plain text, guests off, close on; back to the screen. Fails: ticks lost, switches stuck.
**Support user needs moderator rights** — Passes: picker offers moderators only; Ben's id posted anyway is not stored. Fails: answers under a customer's account.
**Administrator with the form token only** — guard. Passes: no token, an editor: nothing changes. Fails: forged requests reroute the forum.
**Descriptions ≤30 words** — Passes: each. Fails: walls of text.

## Plugin — `tests/e2e/forum.spec.ts` (`npx playwright test`; replaced `harness.ts`)
**Marks forums, picks Support** — Passes: Support and Staff ticked, Members not, Support chosen after saving. Fails: the settings do not stick.
**A visitor's topic becomes a public ticket without the facts card** — Ada posts in Support. Passes: a ticket number; the API reads public, `replyVia: site`, `topic-<id>`; the page shows "Public on wp.omnabase.test" and no `.ob-site-data`. Fails: topics missing, or orders beside a public answer.
**The founder's reply on the forum** — composer, sender and webhooks passes. Passes: the relay shows `/events` 200; one reply by Support with "— <name>, Team Kriesi". Fails: answers missing, doubled or unsigned.
**Ada's reply reopens the ticket** — Passes: status open, newest message the customer's with her text. Fails: the ticket waits on her for ever.
**Unmarked forum creates nothing** — Members. Passes: no row, no number, no ticket with its reference. Fails: every forum feeds the inbox.
**Hidden forum, non-public ticket** — Nina's topic in Staff. Passes: a number, `public: false`, no "Public on". Fails: staff topics treated as public, or not filed.
**Site down: waiting, then delivered** — relay off. Passes: "Waiting for wp.omnabase.test", then once the relay is back one reply on the forum. Fails: undelivered answers look delivered or are lost.

## OmnaBase — `apps/api/src/pluginRound3.test.ts` (Vitest, default test database)
**`siteAuthor`: a delivered team answer** — Passes: 201, `kind: team`, author "Kriesi Support on <host>", sent, `reply-7`; the team's view a reply (not a note), sent, naming it; ticket pending; no mail, no `message.sent` back; same reference 200. Fails: moderators' answers become notes, are mailed, or echo onto the forum.
**Shown in the portal** — Passes: the visitor reads it as the team's. Fails: the customer's history misses the answer.
**An optional role** — Passes: `{name, role}` 201. Fails: sites naming a role are refused.
**Refused where it does not belong** — guard today. Passes: 400 without `delivered: true`, on a customer's post, on a note, empty or 121-character name; another site's ticket refused, nothing added. Fails: a site mails in the team's name or writes onto others' tickets.
## OmnaBase — `packages/db/src/structure75.test.ts`
**Shape and guide name `siteAuthor`** — Passes: in `publicApiResources.ts` and `docs/public-api.md`. Fails: developers cannot find it.
**Records** — Passes: DECISIONS "M13 round three"; an unreleased changelog line naming a moderator. Fails: the change goes unrecorded.

## Contradictions and findings (for the coordinator)
1. The prompt's `reply_via` and `{ id: topic_id }`: the API says `replyVia` and the brief `topic-<id>`; pinned the contract's.
2. "409 duplicate treated as success": no such answer exists. A repeat is `200` with the ticket; `409 conflict` means "filed where this key cannot reach". Pinned 200 = success, 409 = attention.
3. `siteAuthor` shows as "<name> on <host>" (brief), not "<name> (via <site>)" (prompt); pinned the brief. `role` is the coordinator's addition; accepted, display not pinned.
4. Moderators: the brief sends members as `member` and non-members as `siteAuthor`, the prompt every moderator as `siteAuthor`, and the plugin cannot tell members. Pinned Nina (non-member) only. To rule: always `siteAuthor`, or let OmnaBase take `member` + `siteAuthor` and fall back.
5. **The close-on-resolve switch can never fire today**: OmnaBase's fan-out sends a resolve as `ticket.resolved`, and the plugin subscribes only `message.sent,ticket.updated`. Both shapes pinned. Fix: Connect asks for `ticket.resolved` too (safe since round one: a connect-made address hears only its own site's tickets), or the fan-out also sends `ticket.updated`.
6. `bbp_insert_reply` gives a reply its topic's status: on a closed topic the reply is stored "closed" and hidden. The poster must pass `publish` (pinned).
7. The body is built when sent, under one idempotency key per row. If a topic is edited between a lost answer and the retry, OmnaBase answers `409` (same key, other request) and the row needs attention though the ticket exists. Not pinned; suggest fixing the body at first try.
8. **Harness fix (parked `harness.ts`)**: a redirect that changes only the `#fragment` left the browser on the "Redirecting" page, since `location.replace` only jumps within the page. bbPress's reply form does exactly that, so the fragment is now dropped.
9. The brief's e2e "daily ceiling, then the test clock's next day" is covered in integration with a faked 429: the plugin's outbox runs on WordPress's clock, not OmnaBase's test clock.
10. Chosen without the brief: a guest without an address needs attention; no support user means an error other than 410; a `message.sent` without a site reference is ignored. The flows' connect and sign-in helpers repeat `facts.spec.ts`; worth one shared module.
