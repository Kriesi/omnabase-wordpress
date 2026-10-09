# M13 round four — red-team report (failing tests for the shortcode form)

2026-10-09, the red team (Opus 5.5). The tests the builder makes pass in round four: the `[omnabase_form]` shortcode (a tag WordPress replaces with the plugin's form), its single-use time token, the honeypot, the limits, the submission to OmnaBase with the outbox behind it, and OmnaBase's two changes (the keyed form ignores the hosted form's switch; `GET /forms/{slug}`). They are **parked**, in neither repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m13-round4-parked/` with `plugin/…` and `omnabase/…` mirroring each repository's paths (9 files). One replaces an existing file: `tests/wp-env/mu-plugins/omnabase-test-harness.php` (the local test harness, a must-use plugin WordPress always loads; it gains a switch that makes OmnaBase unreachable for WordPress). Copy them in as they are; the plain integration run picks the new tests up (no group).

**Counts**: 50 cases in 8 test files plus the helper `RoundFourTestCase.php`. Plugin: structure 7, wp-env integration 29, Playwright 6. OmnaBase: API 6, structure 2. **Each fails today for its reason** (run 2026-10-09): structure 7 of 7 (no `src/Forms/`, template, script, stylesheet, routes, uninstall names, changelog line; the 29 older guards pass); integration 29 of 29 (12 at the shortcode left as typed, 17 at the missing time-token route); Playwright: the setup passed (a real connect, the founder's session, Enfold's form settings changed, a page with the shortcode made), then test 1 stops at "[omnabase_form]" shown as text, 5 not run; OmnaBase API 6 of 6 (three `404 No such form` with the hosted switch off, `No such route`, 404 instead of 403, not in the route table), structure 2 of 2. **Probe** (removed): a throwaway 150-line form built to the pinned shapes made 26 of the 29 integration tests pass; the three left failed exactly where the probe had no outbox and no uninstall line, so the helpers (token ageing, the fake's replay, the field-error search) work. The harness switch was proved in wp-env (OmnaBase's host refused, other hosts untouched).

Words: *time token*, a one-time ticket the form's script fetches when the page loads; *honeypot*, a field people never see and bots fill; *idempotency key*, a one-time id that makes a resend count once; *outbox*, the plugin's lasting list of calls still to make; *REST route*, an address under `/wp-json/…`; *nonce*, a short-lived form token against forged requests; *hosted form*, OmnaBase's own public web form page.

## Contradictions (the brief wins unless marked; the coordinator's wording in quotes)

1. **Limits**: "per-address limits 25 an hour under OmnaBase's 30" vs the brief's 5 an hour per address **and** 25 an hour per site. Pinned: the brief.
2. **Token life**: "single-use, 10 minutes" vs the brief's "older than 3 seconds, younger than 2 hours". Pinned: the brief (1 second and 3 hours refused).
3. **Outbox**: "only when OmnaBase refuses with 5xx/429" vs the brief's "only a network failure". Pinned: no answer **and a 5xx** go to the outbox (a reading: OmnaBase's proxy answers 502/503 while the API restarts); a 429 is "Please try again in an hour", never queued (queuing it would sidestep the per-address limit). Ruling wanted on the 5xx.
4. **Plan limit words**: "We can't take messages right now" vs the brief's "This form is not available right now". Pinned: the brief, for 403 and 404.
5. **`GET /forms/{slug}`**: "`forms:write` or `tickets:read` … `{products, licence: {asked, required}, privacyUrl, name}`" vs the brief's `forms:write` and `{ products, askLicense, privacyUrl }`. Pinned: the brief's names and scope; OmnaBase's form settings have no "licence required" flag, and the door checks one scope per route. `tickets:read` is not tested either way.
6. **No JavaScript**: "a fallback token fetched server-side at render" contradicts "a cached page never carries a usable token". Pinned: no token is ever in the HTML; without the script there is no token and the submission is refused.
7. **Cache time** of the settings: "a short time" vs the brief's hour. Pinned only "two views, one call".
8. **The outbox holds no customer data** (DECISIONS round two; bodies are rebuilt from a post or user), but a form message has nothing to rebuild from. Pinned: it may wait on the site, and is gone from the database once sent.
9. **Single use vs a double click**: pinned the same token with the same words answers the first reference again; with other words it is refused.
10. **Chosen beyond the brief** (from the coordinator): the token bound to its project; the privacy link falls back to the site's privacy page; the page with the shortcode is made by the flow through WP-CLI (the harness only gains the offline switch).
11. **OmnaBase test to change**: `formsRound4.test.ts`, "the project's form settings are honoured", expects 404 for a form-off project; round four takes it (201).

## Plugin — `tests/Structure/RoundFourStructureTest.php` (`composer test`)
**Parts exist** — Passes: `src/Forms/{Shortcode,Token,Guard}.php`, `src/Rest/Form.php`, `tests/e2e/form.spec.ts`. Fails: the form tangled into one class.
**Shortcode and routes named** — Passes: `omnabase_form`, `omnabase/v1/form`, `omnabase/v1/form-token` registered. Fails: the documented shortcode does nothing, or the script calls a missing address.
**Template escapes everything** — Passes: a front-end template holding "form", the direct-access stop, every `echo` starts with an escaping function. Fails: words from a page or OmnaBase run as script.
**Script holds no secret** — Passes: `assets/**/*form*.js` mentions the token, holds no `obw_`, `whsec_`, Authorization, Bearer or omnabase.com. Fails: the key ships to every visitor.
**Stylesheet from the kit** — Passes: `--ob-` variables under `.ob-wp`, never `:root`, no dark block, no Plus Jakarta Sans; a `bin/` code line reads `tokens.css`. Fails: the theme restyled or turned dark.
**Uninstall** — Passes: names holding `omnabase_form`, or a sweep of `omnabase_` temporary values. Fails: tokens and counted addresses stay after deletion.
**Records** — Passes: a changelog line about the form shortcode; a dated "M13 round four" in DECISIONS. Fails: site owners not told.

## Plugin — `tests/Integration/FormShortcodeTest.php` (`composer test:integration`, plain run; helpers `RoundFourTestCase.php`)
**Fields, products, licence, privacy** — Passes: name, email, subject, message; products exactly Enfold and Enfold Pro; a licence field; the project's privacy link; settings asked; the form's script queued. Fails: wrong products, no privacy link, no token fetch.
**The key's single project** — Passes: without the attribute the form is Enfold's and Enfold's settings are asked. Fails: the owner must type a slug for no reason.
**Unclear project** (3 cases: two projects, a project the key lacks, not connected) — Passes: visitor and editor see nothing; the administrator a notice naming the project. Fails: visitors see setup errors, or the owner never learns why.
**Fallbacks** — Passes: no products or licence asked: neither shown; no privacy address: the site's privacy page; neither: no link. Fails: an empty list, a needless licence field, a dead link.
**Settings cached** — Passes: two views, one call. Fails: every view costs an API call.
**OmnaBase unreachable** — Passes: the four fields still show. Fails: the contact page goes blank in an outage.
**No token in the page** — Passes: two views identical; no `token` value; a fresh token in neither the form nor the scripts. Fails: a cached page shares one token.
**Honeypot** — Passes: `omnabase_hp`, not `type=hidden`, `tabindex=-1`, `autocomplete=off`, inside `aria-hidden="true"`. Fails: no trap, or a real visitor fills it.
**Escaping** — Passes: a script in `subject=""` and an image-tag product shown only as text. Fails: script runs in visitors' browsers.
**Signed-in customer** — Passes: name and email filled; the REST nonce on the page; a visitor's email empty. Fails: retyping, or WordPress refuses the post as forged.
**Subject attribute** — Passes: "Licence question" in the subject field. Fails: no pre-worded subject.
**No key in the page** — Passes: no stored value in either view, the scripts, a token answer or a form answer. Fails: the site's key is readable in the source.

## Plugin — `tests/Integration/FormTokenTest.php`
**Own token, never cached** — Passes: two different tokens, each with Cache-Control no-store or no-cache. Fails: visitors or a CDN share a token.
**Only for a usable form** — Passes: a project the key lacks, and a site not connected: 4xx, no token. Fails: tokens for forms that do not exist.
**Token limits** — Passes: of 100 from one address at least one 429; another address still served. Fails: a bot fills the database, or one address blocks everybody.

## Plugin — `tests/Integration/FormSubmitTest.php`
**Reaches OmnaBase, reference shown** — Passes: one call with the site's key, an Idempotency-Key (≤200), a body of only name, email, subject, message, product, licence; "Thanks. … Your reference is #2001 … we answer by email". Fails: lost messages, OmnaBase's 400 for an extra field, no reference.
**Empty optional fields left out** — Passes: no product or licence in the body. Fails: every visitor without a licence gets "could not be sent".
**Wrong fields named** (6 cases) — Passes: empty name, bad address, empty or 201-character subject, empty or 20 001-character message: 400 with the field's own error; nothing sent. Fails: one vague error, or OmnaBase asked to take what it refuses.
**Trap filled** — Passes: success starting "Thanks"; no call, no row. Fails: bots file tickets or learn they were caught.
**Fresh, unused token of the project** (6 cases) — Passes: none, made up, reused for other words, 1 second, 3 hours, another project's: refused, no reference; one call only. Fails: bots post without the page or replay one token.
**Double click** — Passes: both answers #2001; one Idempotency-Key. Fails: the question filed twice or reported failed.
**Two visitors, one cached page** — Passes: two keys, #2001 and #2002. Fails: the second merged or refused.
**5 an hour per address** — Passes: the sixth 429 "Please try again in an hour", never sent; another address goes on. Fails: one bot fills the inbox, or one person blocks all.
**Forwarded address only through the filter** — Passes: new `X-Forwarded-For` headers do not lift the limit; with `omnabase_visitor_address` they count. Fails: bots escape by writing a header, or a Cloudflare site is stuck at 5 an hour.
**25 an hour per site** — Passes: 25 sent, the 26th refused before OmnaBase is asked. Fails: OmnaBase refuses the site's key and real visitors with it.
**Refusals in plain words** (5 cases) — Passes: 404, `plan_limit`, suspended: "This form is not available right now"; 429: "Please try again in an hour"; 400: "Your message could not be sent" with an address; never "plan", "suspend", "workspace" or OmnaBase's text; none queued. Fails: visitors read about the owner's subscription, or refusals retry for ever.
**Network failure through the outbox** — Passes: "on its way" after one try; one waiting form row; later one call with the same key and words; row sent; the words gone from the database. Fails: lost, doubled, or kept for ever.
**A 5xx through the outbox too** (contradiction 3) — Passes: 503 → "on its way", one row. Fails: every message during a deploy fails.
**Everything kept is removed on uninstall** — Passes: every new `omnabase` option or transient is on the options list or matched by uninstall.php. Fails: tokens and addresses outlive the plugin.

## Plugin — `tests/e2e/form.spec.ts` (`npx playwright test`; replaced harness)
**Form with products and privacy link** (hosted form off) — Passes: no raw shortcode, token fetched, Enfold and Enfold Pro, licence field, privacy link. Fails: a fresh site's form is broken until the hosted page opens.
**Sent → ticket in the inbox** — Passes: "Your reference is #n"; ticket n is Enfold's, channel form, the visitor's address and subject; the founder's page shows it. Fails: the inbox never gets site messages, or under the wrong customer.
**Trap filled** — Passes: "Thanks"; no ticket with that subject. Fails: spam in the inbox.
**Token reused** — Passes: the captured request resent with other words: ≥400, no second ticket. Fails: one copied token lets a bot post at will.
**Two visitors** — Passes: two different references, one ticket each. Fails: merged or refused.
**OmnaBase down** — Passes: "on its way", one waiting form row, no ticket; back online and the outbox worked: exactly one form ticket. Fails: lost or doubled during an outage.

## OmnaBase — `apps/api/src/pluginRound4.test.ts` (Vitest, the default test database)
**Hosted switch off still files** — Passes: 201, Enfold's form ticket with the site; the hosted page and submit still 404. Fails: fresh sites' forms fail, or the plugin opens the public page.
**Products, licence, archive still apply** — Passes: listed product and licence 201 (masked to the team); off-list product 400; unasked licence 400; archived and unknown 404. Fails: dropping the switch drops the team's rules.
**Daily alert counts it** — Passes: threshold 1, two submissions, one `tickets_day` alert. Fails: a flood through sites goes unnoticed.
**`GET /forms/{slug}` answers the settings** — Passes: `{ products, askLicense, privacyUrl }` for form-off, bare and form-on projects. Fails: the site cannot show products or the privacy link.
**Scope and project** — Passes: no `forms:write` 403; unknown, archived, another project's 404. Fails: any key reads any product's settings.
**In the route table and openapi.json** — Passes: `GET /forms/:project`, `forms:write`, the project parameter; one GET under `/forms/{…}`. Fails: server and documentation disagree.

## OmnaBase — `packages/db/src/structure76.test.ts`
**The guide** — Passes: `GET /forms/{…}` with the three names; no "form switched off … 404" left. Fails: developers believe the hosted form must be on.
**Records** — Passes: a dated "M13 round four" in DECISIONS; a changelog line that site forms work whether or not the hosted form is on. Fails: the change is not written down.
