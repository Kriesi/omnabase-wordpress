# M14 round one — red-team report (failing tests: tabs and plain words)

2026-10-10, the red team (Opus 5.5). The tests the builder makes pass in round one of M14 (brief: OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2, choices 1, 3, 4, 5 and "Order of work › Round one"; review: OmnaBase `docs/reviews/m14-settings-cleanup-review.md`, S1–S3, S16, N1). They are **parked**, in no repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m14-r1-parked/`, mirroring this repository's paths (`tests/Integration/…`, `tests/Structure/…`, `tests/e2e/…`). The builder copies them in; each changed existing file carries a note at its top ("adjusted, not weakened": what changed and why).

**Counts.** 54 new test methods in 12 new test files (62 cases: two integration methods run once per tab), plus one new helper base (`TabsTestCase.php`): integration 45 methods (plain run 39, shops run 2, forum run 4; the two per-tab methods make 5 cases each, 53 cases in all), structure 5, Playwright 4. 19 existing files changed: integration 13 (two of them the helper bases `RoundOneTestCase.php` and `RoundSixTestCase.php`), structure 1, Playwright 5.

**Words used below.** *Tab*: one of the screen's five panels, the open one named in the address (`&tab=…`). *Disclosure*: a line such as "Details" that opens a hidden block when clicked (HTML's `<details>`), closed by default. *Nonce*: a short-lived form token against forged requests. *admin-post*: WordPress's address that hands a posted admin form to the plugin's handler. *Option*: a stored setting. *Filter*: a named moment where a plugin may change a list before WordPress uses it (the Plugins list's action links). *Outbox*: the plugin's lasting list of calls to OmnaBase still to make. *Delivery*: one call OmnaBase made to the site (a reply was sent, a ticket changed). *Bubble*: WordPress's small round count after a menu entry. *401*: OmnaBase's answer "this key is not (or no longer) valid". *wp-env*: the local Docker WordPress the tests run in.

## What ran, and what could not

- **Structure (ran, against today's code).** `vendor/bin/phpunit --bootstrap tests/bootstrap.php <parked file>`, nothing copied:
  - `M14RoundOneStructureTest`: **4 of 5 fail for their reasons** — the header description ends "… on every ticket."; the readme's FAQ says "WP-Cron", "cron job", "wp-cron.php"; the FAQ says "Shortcodes section" and Installation names no tab; the changelog has no line about tabs, the fourth state or the Settings link. The fifth (the guard's own examples) passes by design.
  - `RoundSixStructureTest` (adjusted): **1 of 12 fails** — the `.pot` (translation template) has no "On its way to OmnaBase"; the other 11 pass as before.
  - Baseline: the repository's own `composer test` is green today (143 tests).
- **Integration (could not run).** wp-env's tests container sees only this repository (mapped as `wp-content/plugins/omnabase`); the parked folder is not mounted there, and copying the files in was not allowed. What was done instead:
  - `php -l` on every parked PHP file: no syntax error.
  - Every parked integration class loaded together with the repository's own base cases under a stub of WordPress's test case: inheritance and method signatures are compatible, and every test method (new and changed) carries the three PM lines.
  - The new reading helpers (`visibleText`, `bannedIn`, `formsIn`, `formFields`, `submitsOf`, `formDepth`, `navTabs`, `countClass`) run on hand-made samples (all as intended) and on **today's real screen**, fetched from the development WordPress (8888) at the bare address, `&tab=data` and `&tab=activity`: today there is no tab row, all eight sections are on every address, seven forms (among them `omnabase_save_portal` and `omnabase_save_display`) sit on every address, and the banned word "Webhook" ("Webhook deliveries") is visible on every address.
  - **Expected today, by reading** (please confirm with the three integration runs): every new integration test fails, except five guards that pass today by design: `PlainWordsTest` — the guard's own examples, and the 30-word count per tab (today's lines are short enough); `TabsTest` — no form inside another (today's depth is 1); `DisconnectedStateTest` — an outage, a 500 or the site's own 401 is not a disconnect (today never shows the fourth state at all); `ActivityTabTest` — at most one outbox query while the menu is built (today: none). `PlainWordsShopsTest`'s first test may pass today (no delivery is seeded in that run); its second fails.
  - Of the changed integration tests, these fail today on purpose: `DisconnectAndHealthTest` (the 401 test: no fourth state), `ConnectFlowTest` (permissions are codes, not words), `MessageCentrePageTest` (its two save tests post `omnabase_save_pages`, which has no handler yet), `ForumScreenTest` (the save does not return to the Forums tab). The others still pass today, because today's single page holds every section whatever the address; they start depending on the tab once the builder renders only the open tab.
- **Playwright (could not run).** Playwright reads only `tests/e2e`; running the parked specs from a scratch configuration would have used the shared development WordPress, which is connected to Team Kriesi right now, and these flows forget its key (`wp omnabase-test forget-key`). Nothing of the stack was touched. Instead: **the six parked specs typecheck** (strict `tsc`, through symlinks in the scratchpad to the repository's own `fixtures.ts`, `harness.ts` and `node_modules`; no copy). Expected today: all five adjusted specs fail at their changed steps (old timer words; scope codes visible and no "Details"; no "Save" button by that exact name on the data, forums and pages tabs), and `settings-tabs.spec.ts` fails 4 of 4.

## Shapes pinned (the builder may rename anything marked "renamable", and tells the red team)

| What | Pinned | Source |
| --- | --- | --- |
| Tab slugs in the address | `connection` (default; also for no tab or an unknown one), `data`, `forums`, `pages`, `activity` | brief, fixed |
| Tab labels, in order | "Connection", "Customer data", "Forums", "Forms and messages", "Activity" | brief, fixed |
| Tab links | `<a class="nav-tab">` with `page=omnabase&tab=<slug>` in the address; the open one also `nav-tab-active` | WordPress's tab style (brief); classes fixed, markup around them free |
| Activity's label | "Activity" alone with no row needing attention; "Activity" plus the count (the only number in the label: "Activity · 2") otherwise | brief; the separator is free |
| Sections per tab (kept classes) | connection: `__connection`, `__health` (exactly one); data: `__facts`; forums: `__forum`; pages: `__portal`, `__shortcodes`; activity: `__outbox`, `__deliveries` | brief + older tests, fixed |
| Save actions | `omnabase_save_facts`, `omnabase_save_forum` (as today), `omnabase_save_pages` (new, replaces `omnabase_save_portal` and `omnabase_save_display`, which must no longer be registered) | brief, fixed |
| Save button | exactly one submit in each save form, primary (`button-primary`), words exactly "Save"; no "Save" button on Connection or Activity | brief, fixed |
| Field names kept | `omnabase_portal[page]`, `omnabase_theme_only`, `omnabase_my_account`, `omnabase_facts[sources][]`, `omnabase_facts[meta][]`, `omnabase_forum[...]` | today's names, renamable only together with the older tests |
| Guest choice | two radio buttons `omnabase_forum[guests]` labelled "Members only" (stores off) and "Guests too" (stores on), under the words "Who may send topics" | brief's words; radios chosen, renamable |
| Forum labels | "Who answers on the forum" (support user), "Line under each answer" (closing line) | brief, fixed |
| User fields | a closed `<details>` whose `<summary>` reads "Choose user fields", inside the customer-data save form; the rule as list items naming passwords, tokens, keys, private fields | brief; summary words fixed |
| Disclosures | `<details>` whose `<summary>` starts with "Details" or "Advanced" (closed by default) | brief's words, fixed; `<details>` chosen, renamable only with the guard |
| Status block | `.ob-wp-settings__health` (one) with visible "Working both ways" + "checked … ago", or "OmnaBase cannot reach this site"; its Details holds `data-check="me|ping|loopback"` with `data-result`, and the last customer-data call line | brief + review S1 |
| Permissions and key | in words ("read and write tickets", "read and write customers", "forms", "message centre"), no code (`tickets:read` …) anywhere on the tab; the key's last four (`obw_…7Q2X`) only inside a Details | brief, fixed |
| Advanced | the element with `data-key-source` inside a closed "Advanced" `<details>`, the last disclosure on the Connection tab | brief; `data-key-source` kept from today |
| Fourth state | `data-state="revoked"` on `.ob-wp-settings__connection`; words "Disconnected in OmnaBase" and "Someone disconnected this site in OmnaBase. Click Connect to connect it again."; the Connect form (`omnabase_connect`), no Check connection form; no further call to OmnaBase until Connect; outages, 500s and the site's own 401 (reported inside a 200 ping answer) never trigger it | words fixed (brief); `revoked` chosen, renamable |
| Setup line | `.ob-wp-settings__setup` on the Connection tab only, before the connection box; steps `[data-step="connect|data|forums|form"]` with `data-done="1|0"`, a link to their tab, "✓" when done; words "Connect", "Customer data", "Forums", "Add the form to a page"; a Dismiss/Hide form inside it | words fixed (brief); class and data marks chosen, renamable |
| Form step | done when `omnabase_display['first_shown']` is a non-empty value (the test stores `['form' => <time>]`); the page scan is not tested | coordinator |
| Activity words | outbox `h2` exactly "On its way to OmnaBase", no visible "outbox"; deliveries `h2` "What OmnaBase sent here"; events "A reply was sent", "A ticket changed", "A ticket was resolved", "Test", no raw codes visible | brief, fixed |
| Menu bubble | the Settings entry's page title stays "OmnaBase"; its menu title is exactly "OmnaBase" with nothing needing attention, else "OmnaBase" followed by one element with class `awaiting-mod` or `update-plugins` holding the count; at most one outbox query while the menu is built | brief + review S16; class pair chosen |
| Settings link | WordPress's `plugin_action_links` filters, registered when the plugin's admin parts boot, add one link with the words "Settings" to `options-general.php?page=omnabase`; Deactivate stays | brief; words fixed |
| Row description | ends "Set it up under Settings › OmnaBase." (also free of banned words) | brief, fixed |
| `.pot` samples | "This site is not connected to OmnaBase yet.", "On its way to OmnaBase", "Retry", "Discard", "Message centre", "Shortcodes", "Forms and messages", "What OmnaBase sent here", "Disconnected in OmnaBase", "Save", and the header description as omnabase.php says it | adjusted guard |
| Helper names | `openTab( $tab )`, `tabIn()`, `visibleText()`, `bannedIn()`, `formsIn()`, `formFields()`, `postFields()`, `plantOutboxRow()`, `navTabs()`, `openTabs()`, `countClass()`, `submitsOf()`, `formDepth()` in `RoundOneTestCase`; `busySite()`, `receiveDeliveries()` in `TabsTestCase`; `screen( $tab )` in `RoundSixTestCase` | renamable (tell the red team) |

## Contradictions, choices and open points

1. **Two more pinned tests break than the brief lists.** `ConnectFlowTest` (it looks for the six permission codes in the page) and `connect.spec.ts` (codes and the key's last four visible; `approve()` expected the bare address after the way back) contradict choice 1 ("Permissions as words … behind Details"). Both are adjusted (below). The brief said `connect.spec.ts` keeps passing; it does not without the change.
2. **The banned list, as matched.** Whole words (letters, digits, `_` and `-` count as part of a word, so "WP-Cron" does not also count as "cron", "#4090" is not "409", "restore" is not "REST"), `REST` case-sensitive, the rest not. Two choices on top, both stated in the tests: plurals of the nouns count ("webhooks", "endpoints", "scopes"), so the whole-word rule cannot be dodged by an "s"; web addresses (`http(s)://…`) are dropped before matching, so the development notices ("talks to http://host.docker.internal:3011") are not flagged. Only "Details" and "Advanced" blocks are exempt: "Choose user fields" counts as visible. `<noscript>` text counts as visible (round two's fallback lines must be plain too).
3. **Readme and changelog.** They cannot fold text away, so the file guard allows a banned word only as a short bracketed name (three words at most) after plain words, as in "WordPress's timer (WP-Cron)", or in backticks. A long bracketed aside counts as a sentence. Today's Description hides "(a turned-off WP-Cron, an outdated shop plugin)" that way and is caught.
4. **The Activity count is in round one** (the coordinator), while the brief's task 3 lists it in round two. Pinned here: the label, the bubble, and at most one outbox query while the menu is built.
5. **"The daily and on-demand calls stop until Connect."** Round one has no daily call (the site report is round two's question 4). Pinned: once in the fourth state, viewing any tab and posting Check connection make no call to OmnaBase. **Not pinned, open:** whether the outbox (queued calls) pauses too after a 401; today each queued call would meet the same 401 and end as "needs attention". A ruling would help.
6. **The Forums step without bbPress** (bbPress: the forum plugin). The Forums tab stays (brief), but its step can never be "saved" through the screen there. `SetupLineTest` lets the builder leave the step out or count it done without bbPress (record it in DECISIONS); `ForumsTabTest` pins all four steps where bbPress is active.
7. **Permission codes** are pinned out of the whole tab, Details included ("as words"). If the builder wants the codes for support staff, it is a DECISIONS entry and a word with the red team.
8. **The guest switch becomes two radio buttons** ("Members only / Guests too"); the stored value stays a yes/no, so `ForumScreenTest`'s posts (guests absent = off) keep working.
9. **`RoundSixTestCase::postAdmin()` now nests bracketed names** as PHP reads a browser's post. Without that, flipping the theme switch through the merged form would post the page pick under the literal key `omnabase_portal[page]`, clear the message centre page and break `MyAccountLinkTest`.
10. **The way back from Connect.** The return address registered at OmnaBase stays `options-general.php?page=omnabase` (`ConnectFlowTest` pins it, unchanged); only the redirect after arrival may add `&tab=connection` (`TabsTest`, `connect.spec.ts` accept both).
11. **Risk for the builder:** `round-trip.spec.ts` finds the menu link by the exact name "OmnaBase". If an earlier flow leaves an outbox row needing attention, the bubble changes the link's accessible name and that step fails. Not changed here (the risk depends on the order of the flows); worth a look when the bubble lands.
12. **The Settings link's filter** must be registered when the admin parts boot (the test boots them on the settings screen), not only while `plugins.php` loads.
13. **`MessageCentrePageTest` posts only the page field** to the merged action, as a browser would without the theme box ticked: the merged handler then stores the theme switch off. Intended.

## Parked files

New:
- `tests/Integration/TabsTestCase.php` (helper base: the busy site, deliveries, the per-tab section and save lists)
- `tests/Integration/TabsTest.php`, `ConnectionTabTest.php`, `DisconnectedStateTest.php`, `SetupLineTest.php`, `ActivityTabTest.php`, `PagesTabTest.php`, `PluginsListTest.php`, `PlainWordsTest.php` (plain run)
- `tests/Integration/PlainWordsShopsTest.php` (shops run), `tests/Integration/ForumsTabTest.php` (forum run)
- `tests/Structure/M14RoundOneStructureTest.php`
- `tests/e2e/settings-tabs.spec.ts`

Changed (each with its note at the top):
- `tests/Integration/RoundOneTestCase.php` — `openTab()` and the reading and posting helpers added; nothing removed.
- `tests/Integration/RoundSixTestCase.php` — `screen( $tab )`; `flip()` reads the Forms and messages tab; `postAdmin()` nests bracketed names.
- `tests/Integration/ConnectFlowTest.php` — the permissions in words, the codes gone (was: the six codes in the page).
- `tests/Integration/DisconnectAndHealthTest.php` — the 401 test expects the fourth state (was: only "the me row is not ok").
- `tests/Integration/SecretsNeverRenderedTest.php` — also renders and searches every tab.
- `tests/Integration/ForumScreenTest.php` — opens the Forums tab; the save also returns to it.
- `tests/Integration/MessageCentrePageTest.php` — opens the Forms and messages tab; posts `omnabase_save_pages`.
- `tests/Integration/ShortcodesSectionTest.php` — opens the Forms and messages tab.
- `tests/Integration/MyAccountLinkTest.php` — the switch inside the Shortcodes block of the Forms and messages tab.
- `tests/Integration/RoundSixLeftoversTest.php` — the outbox read from the Activity tab.
- `tests/Integration/RoundTwoScreenTest.php` — customer data from the Customer data tab, the outbox from Activity.
- `tests/Integration/ShopVersionsTest.php` — the Customer data tab.
- `tests/Integration/ForumTopicsTest.php` — one line: the "topics waiting" check reads the Activity tab.
- `tests/Structure/RoundSixStructureTest.php` — the `.pot` samples follow the new words; the header description read from omnabase.php.
- `tests/e2e/cron-health.spec.ts` — the plain timer words, visible, no banned word (was: "WP-Cron … turned off" and "cron job").
- `tests/e2e/connect.spec.ts` — Details opened before the key and the permissions (in words); the way back may carry `&tab=connection`.
- `tests/e2e/facts.spec.ts`, `forum.spec.ts`, `messages.spec.ts` — open their tab, click the tab's one "Save", expect to come back to it.

Not changed, and why they keep passing: `SettingsMenuTest` (the title stays exactly "OmnaBase" with nothing needing attention), `round-trip.spec.ts` (see point 11), `form.spec.ts` and `fresh.spec.ts` (they read only the connection box, the Connect button and the classes that stay), the other integration tests (they do not read the settings screen).

## New tests — what each checks (the three PM lines)

### `tests/Integration/TabsTest.php`

- **test_the_screen_has_five_tabs_in_order_with_their_names**
  - What it checks: The screen shows five tabs, in this order, with names that say what each is for.
  - Passes when: the screen's tab row holds exactly Connection, Customer data, Forums, Forms and messages, Activity, each a link to the screen's address with its own tab name in it (connection, data, forums, pages, activity).
  - Fails when: a site owner faces one long page again, a tab is missing or misnamed, or a tab's link leads nowhere.
- **test_connection_opens_by_default_and_the_open_tab_is_marked**
  - What it checks: The screen opens on Connection when the address names no tab or an unknown one, and marks the open tab.
  - Passes when: the bare address and the address with an unknown tab both mark Connection (and only it) as open and show the connection box; each tab's own address marks that tab (and only it) as open.
  - Fails when: an owner lands on an empty panel, two tabs look open at once, or a made-up address shows something unintended.
- **test_each_section_sits_in_its_tab_and_only_the_open_tab_is_rendered**
  - What it checks: Each tab holds its own sections and only the open tab is on the page.
  - Passes when: on a site in full use, Connection holds the connection box and the status block, Customer data the customer-data section, Forums the forum section, Forms and messages the page picker and the Shortcodes block, Activity the outbox and the list of what OmnaBase sent; and no tab holds any other tab's sections.
  - Fails when: a section lands in the wrong tab (an owner looks for the forum settings and cannot find them), or every section is still printed on every tab (one long page behind a tab row).
- **test_a_settings_tab_has_one_save_form_with_one_primary_save_button**
  - What it checks: A tab with settings has one save form ending in one blue "Save" button; Connection and Activity have none.
  - Passes when: Customer data and Forms and messages each hold exactly one form that saves settings (its action the tab's save action), with exactly one submit button, a primary one reading "Save"; Connection and Activity hold no form that saves settings and no "Save" button.
  - Fails when: a tab has two save buttons (an owner saves one half and loses the other), a button reads "Save display settings" beside "Save page", or a tab without settings shows a Save button that does nothing.
- **test_no_form_sits_inside_another**
  - What it checks: No form sits inside another on any tab (HTML does not allow it; a browser silently breaks the inner one).
  - Passes when: on every tab of a site in full use, the raw page never opens a form while another is still open.
  - Fails when: Retry, Discard, Send now, Connect or Disconnect sit inside a tab's save form, so the browser drops their form and clicking them saves the settings instead, or does nothing.
- **test_every_save_and_action_returns_to_its_tab**
  - What it checks: Every save and every action brings the owner back to the tab it was made on.
  - Passes when: saving Customer data returns to the data tab, saving Forms and messages to pages, saving the forum settings to forums, Retry, Discard and Send now to activity, Check connection and Disconnect to connection; each on Settings › OmnaBase.
  - Fails when: after saving the forum settings the owner lands on Connection and has to find the tab again to see whether it worked.
- **test_the_way_back_from_connect_opens_the_connection_tab**
  - What it checks: Coming back from OmnaBase's connect page (approved or cancelled) opens the Connection tab.
  - Passes when: a cancelled connect's way back redirects to Settings › OmnaBase with no tab or the connection tab in the address.
  - Fails when: the owner returns from OmnaBase to another tab and cannot see whether the site is connected.
- **test_user_fields_sit_behind_a_closed_choose_user_fields_line**
  - What it checks: The user fields sit behind a closed "Choose user fields" line inside the customer-data form, with the rule as a list.
  - Passes when: the Customer data tab holds a closed disclosure reading "Choose user fields" that contains the user-field ticks (one for billing_phone here) and sits inside the tab's save form; next to it a list names passwords, tokens, keys and private fields as never offered.
  - Fails when: the user fields are a long open list of internal names every owner must scroll past, the ticks sit outside the form so they are never saved, or the rule is a sentence with commas instead of a list.
- **test_the_forums_tab_without_bbpress_holds_one_line**
  - What it checks: Without bbPress the Forums tab stays, with one line saying what to install.
  - Passes when: in a WordPress without bbPress the Forums tab is in the tab row and holds "Install and activate bbPress to send forum topics to OmnaBase." and no form.
  - Fails when: the tab vanishes (an owner never learns forums are possible) or shows forum settings that cannot work.

### `tests/Integration/ConnectionTabTest.php`

- **test_a_healthy_connection_reads_working_both_ways**
  - What it checks: When both directions work, the status block reads "Working both ways" and says when it was checked.
  - Passes when: after a check whose ping arrived (and the site's receiver refused an unsigned call, as it should), the status block's visible words, outside any Details, say "Working both ways" and "checked … ago".
  - Fails when: an owner must read a three-row table of arrows and codes to learn that everything is fine.
- **test_a_broken_return_path_reads_omnabase_cannot_reach_this_site**
  - What it checks: When OmnaBase cannot reach the site, the status block says so in one plain line, with the detail one click away.
  - Passes when: after a check whose ping met a refusal at the site, the status block's visible words say "OmnaBase cannot reach this site", do not say "Working both ways", and the block holds a closed Details.
  - Fails when: a broken connection reads as healthy, or the owner gets the raw refusal ("answered 401") instead of a sentence.
- **test_the_directions_and_the_last_customer_data_call_sit_inside_details**
  - What it checks: The three directions, the last customer-data call and the receiver's answer sit inside the status block's Details, with their marks kept.
  - Passes when: inside the status block's Details are the rows marked me, ping and loopback (each with its result mark) and a line about OmnaBase's last customer-data call; none of the three rows stands outside a Details.
  - Fails when: the detail rows stay on the open page (the technical table the founder asked to hide), or their marks are lost and the browser flows that read them break.
- **test_permissions_in_words_and_the_key_wait_behind_details**
  - What it checks: The key's permissions are said in words and, with the key's last four characters, wait behind "Details".
  - Passes when: the Connection tab's visible words hold neither a permission code (tickets:read …) nor the key's last four; a Details on the tab holds the key's last four and the permissions as words: read and write tickets, read and write customers, forms, the message centre; no permission code appears anywhere on the tab.
  - Fails when: an owner reads "customers:write portal" and wonders what the site may do, or the key's characters sit on the open page.
- **test_the_encryption_advice_sits_in_a_closed_advanced_block_at_the_end**
  - What it checks: The encryption advice sits in a closed "Advanced" block at the end of the Connection tab.
  - Passes when: the line marked with the key's source is inside a closed Details whose summary reads "Advanced", that block is the last disclosure on the tab, and neither "OMNABASE_ENCRYPTION_KEY" nor "wp-config.php" is on the open page.
  - Fails when: every owner meets a line of PHP to paste into a settings file on the first screen they open.

### `tests/Integration/DisconnectedStateTest.php`

- **test_a_refused_key_on_the_screens_own_call_shows_the_fourth_state**
  - What it checks: When OmnaBase refuses the key while the screen asks who the key is, the screen says "Disconnected in OmnaBase", offers Connect, and stops asking.
  - Passes when: with OmnaBase answering 401 to the screen's question, the Connection tab (at the latest on the next view) has the state `revoked`, the words "Disconnected in OmnaBase" and "Someone disconnected this site in OmnaBase. Click Connect to connect it again.", a Connect button and no Check connection button; across both views OmnaBase was asked once.
  - Fails when: an owner whose site was disconnected in OmnaBase sees "Connected to Team Kriesi" with a cryptic error, or every view of the screen fires another refused call at OmnaBase.
- **test_a_refused_check_leads_to_the_fourth_state_and_the_calls_stop**
  - What it checks: A "Check connection" that meets OmnaBase's 401 leads to the same state, and afterwards the site stops calling.
  - Passes when: after a check whose calls OmnaBase answers 401, the Connection tab is in the `revoked` state with its words; then another view, another check posted anyway and a view of every tab make no call to OmnaBase at all.
  - Fails when: the site keeps knocking with a dead key (OmnaBase's logs fill with refusals and its rate limit counts them), or the owner is told only that a check "failed".
- **test_connecting_again_ends_the_fourth_state**
  - What it checks: Connecting again ends the fourth state.
  - Passes when: after the `revoked` state, a full Connect (OmnaBase hands a new key) and OmnaBase answering the screen's question again bring back "Connected to Team Kriesi" with the state `connected` and without "Disconnected in OmnaBase".
  - Fails when: the site stays "Disconnected in OmnaBase" for ever after the owner did exactly what the screen asked.
- **test_an_outage_or_the_sites_own_refusal_is_not_a_disconnect**
  - What it checks: Only OmnaBase's 401 counts: an outage, a server error, or a refusal the site itself gave OmnaBase is not a disconnect.
  - Passes when: with OmnaBase unreachable, with OmnaBase answering 500, and after a check where OmnaBase reports that the site's own receiver answered 401, the Connection tab stays `connected` and never says "Disconnected in OmnaBase".
  - Fails when: a short outage or a security plugin on the site tells the owner that someone disconnected the site, and they reconnect for nothing (rotating the key).

### `tests/Integration/SetupLineTest.php`

- **test_a_new_site_sees_the_steps_in_order_none_done**
  - What it checks: A new site sees the setup line first on the Connection tab, its steps in order, each linking to its tab, none ticked.
  - Passes when: on a site not connected and with nothing saved, the Connection tab's setup line comes before the connection box and holds Connect, Customer data and "Add the form to a page" in that order (Forums between them where shown), each linking to its own tab (connection, data, pages; forums), none marked done and none showing "✓".
  - Fails when: a first-time owner does not see what to do in which order, a step's link opens the wrong tab, or a step reads done on a site where nothing was set up.
- **test_steps_tick_as_their_settings_are_saved**
  - What it checks: Each step ticks itself once its setting is saved: Connect when connected, Customer data when its form was saved, the form step when the first-display mark is stored.
  - Passes when: after a full Connect only Connect is done (with "✓"); after saving the Customer data tab's form Customer data is done too; with the first-display mark stored the form step is done as well.
  - Fails when: a step stays open after the owner did it (the owner keeps doing it again), or one step's action ticks another.
- **test_the_form_step_counts_the_stored_first_display_mark**
  - What it checks: The form step counts the stored first-display mark, and nothing else in this round.
  - Passes when: with everything else done but no mark (and no page holding a tag), the form step is open; with the mark stored, it is done.
  - Fails when: the step ticks without the form ever being shown, or stays open although the form is live.
- **test_the_line_disappears_once_every_step_is_done**
  - What it checks: The setup line disappears once every step is done.
  - Passes when: connected, the customer-data and forum settings saved and the first-display mark stored, the Connection tab has no setup line.
  - Fails when: a finished setup keeps nagging the owner on every visit.
- **test_dismissing_hides_the_line**
  - What it checks: An owner can dismiss the setup line before finishing it.
  - Passes when: the line holds a form whose button reads "Dismiss" or "Hide"; sending it (with its own fields) returns to the Connection tab, and the line is gone although steps are still open.
  - Fails when: an owner who never wants forums or a form keeps seeing the line for ever.
- **test_the_line_is_only_on_the_connection_tab**
  - What it checks: The setup line sits on the Connection tab only.
  - Passes when: with steps open, the Connection tab holds the line and the Customer data, Forms and messages and Activity tabs do not.
  - Fails when: the line crowds every tab, pushing the settings an owner came for down the page.

### `tests/Integration/ActivityTabTest.php`

- **test_the_outbox_reads_on_its_way_to_omnabase**
  - What it checks: The outbox reads "On its way to OmnaBase", with Retry, Discard and Send now on its rows.
  - Passes when: the Activity tab's outbox section is headed "On its way to OmnaBase" and offers Retry and Discard for the rows needing attention and Send now for the waiting one; no line on the open page reads "Outbox".
  - Fails when: an owner meets the word "outbox" and has to guess it means messages still on their way.
- **test_deliveries_read_what_omnabase_sent_here_with_events_in_words**
  - What it checks: The list of OmnaBase's calls reads "What OmnaBase sent here", each call's event in words.
  - Passes when: after a reply, a ticket change, a resolve and a test ping arrived, the Activity tab has the heading "What OmnaBase sent here" and its open page reads "A reply was sent", "A ticket changed", "A ticket was resolved" and "Test", and shows none of the codes message.sent, ticket.updated, ticket.resolved.
  - Fails when: the owner reads "message.sent" and "ticket.updated" and cannot tell what happened on their site.
- **test_the_tab_name_counts_only_rows_needing_attention**
  - What it checks: The Activity tab's name carries the number of outbox rows needing attention, and only those.
  - Passes when: with two rows needing attention and one waiting, the tab reads "Activity" with the number 2 (and no other number); with only a waiting row, it reads just "Activity".
  - Fails when: a stuck call hides behind an unmarked tab (customer data stops reaching OmnaBase and nobody looks), or calls that are merely waiting raise a false alarm.
- **test_the_menu_shows_the_count_as_a_bubble_after_an_unchanged_title**
  - What it checks: The Settings menu shows the same count as a bubble after an unchanged "OmnaBase".
  - Passes when: with two rows needing attention, the menu entry's words before its first tag are exactly "OmnaBase", a bubble element (WordPress's `awaiting-mod` or `update-plugins`) follows holding 2, and the page title stays "OmnaBase"; with none needing attention the entry is exactly "OmnaBase".
  - Fails when: an owner never notices that calls are stuck unless they open the screen, or the count is glued into the title so the page's name reads "OmnaBase2" and the menu test breaks.
- **test_the_count_is_one_small_question_per_page**
  - What it checks: The count costs one small question to the outbox table per admin page, not one per row or per menu item.
  - Passes when: building the admin menu with three rows in the outbox asks the database about the outbox table at most once.
  - Fails when: every admin page of a busy shop runs several queries over the outbox just to draw a number.

### `tests/Integration/PagesTabTest.php`

- **test_one_form_holds_the_picker_and_the_shortcodes_block**
  - What it checks: One form on the Forms and messages tab holds the page picker and the Shortcodes block with its theme switch.
  - Passes when: the tab's one save form (action `omnabase_save_pages`) contains the page picker inside `.ob-wp-settings__portal` and the "theme's styles only" checkbox inside `.ob-wp-settings__shortcodes`, whose heading is "Shortcodes"; the tab's name reads "Forms and messages".
  - Fails when: the picker and the switch need two saves (an owner changes both, saves one and loses the other), or the Shortcodes block loses its heading or its switch.
- **test_one_save_stores_the_page_and_the_switch**
  - What it checks: One save stores the picked page and the theme switch together and returns to the tab.
  - Passes when: sending the form with a new page picked and the switch ticked stores both and redirects to the pages tab; sending it again with no page and the switch unticked clears both.
  - Fails when: one half of the tab is silently not saved, or the owner lands on another tab after saving.
- **test_the_merged_save_needs_the_token_and_an_administrator**
  - What it checks: The merged save needs the form's token and an administrator.
  - Passes when: the form sent without its nonce, and the form sent by an editor, change neither the picked page nor the switch; the same form with its nonce, by the administrator, does.
  - Fails when: a forged link moves the message centre to an unprotected page or changes the site's look.
- **test_the_two_old_save_handlers_are_merged_into_one**
  - What it checks: The two separate save handlers of the old screen are gone, merged into the one.
  - Passes when: WordPress knows a handler for `omnabase_save_pages` and none for `omnabase_save_portal` or `omnabase_save_display`.
  - Fails when: stray old forms or bookmarks keep saving half of the tab through a handler nobody tests any more.

### `tests/Integration/PluginsListTest.php`

- **test_the_plugins_row_has_a_settings_link**
  - What it checks: The plugin's row on the Plugins list has a "Settings" link to Settings › OmnaBase, beside WordPress's own links.
  - Passes when: WordPress's action-link filters, run for the plugin's row with Deactivate in it, add a link reading "Settings" whose address is options-general.php?page=omnabase, and keep Deactivate.
  - Fails when: an owner who just activated the plugin has to hunt through the Settings menu to find where it is set up.
- **test_the_row_description_says_where_to_set_it_up**
  - What it checks: The plugin's description on the Plugins list ends by saying where to set it up.
  - Passes when: WordPress reads the plugin's description from its header and it ends with "Set it up under Settings › OmnaBase.".
  - Fails when: the row says what the plugin does but not where to go next.

### `tests/Integration/PlainWordsTest.php`

- **test_no_banned_word_on_the_open_page_of_a_busy_site**
  - What it checks: No tab of a site in full use shows a developer word on its open page.
  - Passes when: on a connected site with a failed check, OmnaBase's calls received, rows needing attention and waiting in the outbox and a user field to offer, the tab's visible words hold none of the banned words (whole words; REST with its case; plurals too).
  - Fails when: an owner who knows nothing of programming meets "webhook", "endpoint", "401" or "cron" and stops trusting they set the plugin up right.
- **test_every_connection_state_speaks_plainly**
  - What it checks: The Connection tab speaks plainly in every state it can be in, not only when things work.
  - Passes when: not connected, not connected because the site cannot be connected (plain permalinks), disconnected in OmnaBase (a 401), unreadable after the site's secret keys changed, and connected without a check yet: each state's visible words hold no banned word.
  - Fails when: the moment something goes wrong (the moment an owner reads most carefully) the screen falls back to developer words such as "/wp-json/ addresses".
- **test_the_guard_reads_and_matches_as_the_brief_says**
  - What it checks: The guard itself: it catches what it must and lets through what it may.
  - Passes when: the reading drops a Details block and an Advanced block (with "webhook" inside) but keeps a "Choose user fields" block, drops web addresses, and the matching finds "Webhooks", "wp-json", "REST API", "WP-Cron" and "409" while leaving "restore", "rest of", "https", "#4090" and "endpointless" alone.
  - Fails when: the guard misses banned words (every other test here would pass for nothing) or flags ordinary words (the builder cannot write normal English).
- **test_every_description_on_every_tab_has_at_most_30_words**
  - What it checks: Every description, paragraph and help line on every tab has at most 30 words.
  - Passes when: on a site in full use, each paragraph, each element marked as a description and each list item of every tab has 30 words or fewer (shortcode tags not counted).
  - Fails when: a tab grows a paragraph an owner will not read.

### `tests/Integration/PlainWordsShopsTest.php`

- **test_the_customer_data_tab_with_every_source_speaks_plainly**
  - What it checks: With every shop source and an outdated add-on, the Customer data tab still speaks plainly and briefly.
  - Passes when: all seven sources switched on and WooCommerce Subscriptions at 2.0.0, the tab's visible words hold no banned word, still name WooCommerce Subscriptions as an unsupported version, and every paragraph, description and list item has at most 30 words.
  - Fails when: the richest shop setup, the one most owners with shops have, brings back developer words or long paragraphs.
- **test_the_my_account_switch_sits_in_the_one_pages_form**
  - What it checks: With WooCommerce active, the My Account switch sits in the Shortcodes block of the Forms and messages tab's one save form, and the tab speaks plainly.
  - Passes when: the checkbox `omnabase_my_account` is inside `.ob-wp-settings__shortcodes`, inside the form whose action is `omnabase_save_pages`; the tab's visible words hold no banned word.
  - Fails when: the My Account link keeps a form and a button of its own, so the tab has two saves again.

### `tests/Integration/ForumsTabTest.php`

- **test_one_save_form_and_the_way_back_to_forums**
  - What it checks: The Forums tab has one save form ending in one blue "Save", and saving returns to the Forums tab.
  - Passes when: the tab holds exactly one form saving settings (`omnabase_save_forum`) with one primary submit reading "Save"; sending it as it stands redirects to the forums tab.
  - Fails when: the button reads "Save forum settings" beside other Save buttons, or the owner lands on Connection after saving.
- **test_labels_say_what_each_setting_does**
  - What it checks: Each forum setting is named by what it does for the owner.
  - Passes when: the support-user picker is labelled "Who answers on the forum", the closing-line field "Line under each answer", and the guest setting "Who may send topics" with two choices "Members only" and "Guests too", the stored one picked; saving "Guests too" stores guests on and "Members only" stores them off.
  - Fails when: an owner reads "Support user" or "Closing line" and has to guess what happens on the forum.
- **test_the_forums_tab_speaks_plainly**
  - What it checks: The Forums tab with bbPress speaks plainly and keeps its descriptions short.
  - Passes when: the tab's visible words hold no banned word, and every paragraph, description and list item has at most 30 words.
  - Fails when: the forum settings bring back developer words or long paragraphs.
- **test_the_setup_line_has_the_forums_step**
  - What it checks: Where bbPress is active, the setup line shows all four steps, Forums ticking once the forum settings are saved.
  - Passes when: the Connection tab's setup line holds connect, data, forums, form in that order, Forums linking to the forums tab; with the forum settings deleted Forums is not done; after saving the Forums tab's form it is done.
  - Fails when: an owner with a forum never sees the forum step, or it stays open after they saved the forum settings.

### `tests/Structure/M14RoundOneStructureTest.php`

- **test_the_header_description_says_where_to_set_it_up**
  - What it checks: The plugin's description on the Plugins list ends by saying where to set it up, in plain words.
  - Passes when: omnabase.php's header description ends with "Set it up under Settings › OmnaBase." and holds no banned word.
  - Fails when: an owner who just activated the plugin reads what it does but not where to go next.
- **test_the_readme_speaks_the_screens_plain_words**
  - What it checks: The readme's Description, Installation, FAQ and External services speak the screen's plain words.
  - Passes when: outside short bracketed names and backticks, none of the four sections holds a banned word (so "WP-Cron", "cron job" and "wp-cron.php" are gone from the FAQ and the Description, or stand only as a bracketed name after plain words).
  - Fails when: the screen says "WordPress's timer" while the plugin's own page tells owners about "a real cron job calling wp-cron.php".
- **test_the_readme_names_the_tabs**
  - What it checks: The readme sends owners to the tabs by the names the screen shows.
  - Passes when: the FAQ's answer about the theme's styles names the "Forms and messages" tab and no longer says "Shortcodes section"; the Installation steps name the tabs "Customer data", "Forums" and "Forms and messages".
  - Fails when: an owner follows the readme to a "Shortcodes section" that no longer exists as such, or to "the same screen" without knowing which tab.
- **test_the_changelog_names_this_rounds_changes_in_plain_words**
  - What it checks: The unreleased changelog tells customers about this round's changes, in plain words.
  - Passes when: changelog.txt's top section is still "0.1.0 — unreleased" and has a line about the settings tabs, a line about "Disconnected in OmnaBase", and a line about the Settings link on the Plugins list; outside short bracketed names and backticks, none of its lines holds a banned word.
  - Fails when: customers are not told the screen changed, or the release notes still say "WP-Cron" while the screen says "timer".
- **test_the_guard_matches_as_the_brief_says**
  - What it checks: The guard of this file catches what it must and leaves ordinary words alone.
  - Passes when: "a real cron job calling wp-cron.php" yields two hits, "Webhooks" one, "the REST API" one, a long bracketed aside "(a turned-off WP-Cron, an outdated shop plugin)" one; "WordPress's timer (WP-Cron)" and "restore the rest over https" yield none.
  - Fails when: the readme and changelog checks above pass for nothing, or flag plain English.

### `tests/e2e/settings-tabs.spec.ts`

- **the tab row moves between panels**
  - What it checks: The tab row moves between panels and only the open panel is on the page.
  - Passes when: the bare address shows the connection box and no customer-data section; clicking "Customer data" opens an address with tab=data showing the customer-data section and no connection box; clicking "Forms and messages" opens tab=pages with the page picker.
  - Fails when: the tabs are decoration over one long page, or a click leads to an empty panel.
- **the detail waits behind Details and Advanced**
  - What it checks: The key's last four characters and the encryption advice are hidden until the owner opens "Details" or "Advanced".
  - Passes when: on the Connection tab of the connected site, the key's last four and the line about where the encryption key comes from are not visible; after clicking "Details" the last four are visible; after clicking "Advanced" the encryption line is visible.
  - Fails when: the first screen an owner sees is full of key characters and PHP lines, or the detail cannot be reached at all.
- **the Plugins list has a Settings link**
  - What it checks: The Plugins list leads to the settings screen and says where to set the plugin up.
  - Passes when: the plugin's row on the Plugins list has a "Settings" link and a description ending "Set it up under Settings › OmnaBase."; clicking the link opens Settings › OmnaBase.
  - Fails when: an owner who just activated the plugin has no direct way to its settings.
- **a key revoked in OmnaBase shows Disconnected in OmnaBase**
  - What it checks: When the site's key is revoked in OmnaBase, the screen says "Disconnected in OmnaBase" and offers Connect.
  - Passes when: after OmnaBase stops the key (its own DELETE /me), the Connection tab's box is in the `revoked` state, reads "Disconnected in OmnaBase" and "Someone disconnected this site in OmnaBase. Click Connect to connect it again.", shows a Connect button and no Check connection button.
  - Fails when: an owner whose site was disconnected in OmnaBase keeps seeing "Connected to Team Kriesi" while nothing arrives any more.

## Existing tests changed — the changed tests' PM lines

Each changed file states at its top what changed and why. The test methods whose descriptions changed:

### `tests/Integration/DisconnectAndHealthTest.php`

- **test_a_refused_key_shows_as_a_failed_check**
  - What it checks: When OmnaBase refuses the key, "Check connection" says so, as the fourth state "Disconnected in OmnaBase".
  - Passes when: with `GET /me` answering 401, the Connection tab's connection box is in the `revoked` state and reads "Disconnected in OmnaBase"; a "me" row, if one is still printed, is not "ok".
  - Fails when: a revoked key still shows as a working connection, or only as a failed row the owner has to interpret.

### `tests/Integration/ConnectFlowTest.php`

- **test_the_screen_shows_the_connection_its_permissions_and_the_keys_last_four**
  - What it checks: After connecting, the screen says where the site is connected, what it may do and which key it holds, without the key itself; the workspace and the permissions are kept, so they show even while OmnaBase cannot be reached.
  - Passes when: the screen reads "Connected to Team Kriesi", names the project Enfold, says the six permissions in words (read and write tickets, read and write customers, forms, the message centre; no code such as tickets:read) and shows the key as "obw_…7Q2X" (its last four), never in full; with OmnaBase unreachable it still names Team Kriesi and the permissions in words.
  - Fails when: the admin cannot tell which workspace or permissions the site has, the full key is printed into the page, or the connection seems to vanish whenever OmnaBase is briefly down.

### `tests/Integration/SecretsNeverRenderedTest.php`

- **test_no_page_script_or_rest_answer_holds_a_stored_value**
  - What it checks: After a real connect, none of the three stored values appears in anything the site prints or answers.
  - Passes when: the settings screen (connected, offline, after "Check connection", and each of its five tabs), the admin pages' notices, head and footer scripts, the front page's head and footer, the two receivers' answers and the REST index hold neither the key, nor the webhook secret, nor the facts secret; the screen does show the key's last four.
  - Fails when: the key or a secret is printed into a page or a script variable (any visitor, browser extension or page cache could copy it and act as the site in OmnaBase).

### `tests/Integration/ForumScreenTest.php`

- **test_saving_stores_exactly_the_choices**
  - What it checks: Saving the forum section stores exactly what the administrator chose.
  - Passes when: ticking only Support and Off topic, choosing Nina as the support user, a closing line, guests off and closing on resolve on, then saving, leaves the forum option with exactly those forums, Nina, the line, guests false and closing true, and goes back to the screen's Forums tab.
  - Fails when: ticks are lost, a forum stays marked after unticking it, or the switches cannot be turned off.

### `tests/Integration/MyAccountLinkTest.php`

- **test_off_by_default_no_support_link**
  - What it checks: Without the switch, WooCommerce's My Account menu is left as WooCommerce made it.
  - Passes when: the Forms and messages tab offers the "My Account" switch inside its Shortcodes block, unticked, and the My Account menu has no "Support" item.
  - Fails when: the plugin changes a shop's account menu the owner never asked to change, or the owner cannot find the switch.

### `tests/Structure/RoundSixStructureTest.php`

- **test_the_pot_is_present_and_up_to_date**
  - What it checks: languages/omnabase.pot exists, belongs to this plugin and lists every sentence the code translates.
  - Passes when: the file exists with a header naming OmnaBase, the version 0.1.0 and the text domain; it holds the sample sentences (the settings screen, its tabs and buttons, the outbox, the shortcodes, the fourth connection state) and the plugin's description exactly as omnabase.php's header says it; and every first string of every translation call in src/, templates/ and the main file is one of its msgids.
  - Fails when: translators start from a missing or old template, so new sentences stay English in every translation.

### `tests/e2e/cron-health.spec.ts`

- **health warns while WordPress's timer is turned off**
  - What it checks: An owner whose host switched WordPress's timer off learns it on Settings › OmnaBase, in plain words, with what to do.
  - Passes when: with the timer switched off (DISABLE_WP_CRON set), the status block on the Connection tab shows, without opening anything, a line saying WordPress's timer is off and asking the host to run it every minute, and that line holds no developer word (no "WP-Cron", "cron", "DISABLE_WP_CRON"); with the switch removed, that line is gone.
  - Fails when: forum topics and licence changes wait for ever on such a host and nobody is told why, the owner is told about "WP-Cron" and "wp-cron.php", the warning hides behind "Details", or it stays after the host fixed it.

### `tests/e2e/connect.spec.ts`

- **connect end to end: Connect, approve on OmnaBase, back connected** (and, through `expectConnected()`, "connecting again rotates the key")
  - What it checks: The round's story: an administrator connects the site without copying a key.
  - Passes when: Connect leads to OmnaBase's connect page for wp.omnabase.test; after the founder signs in by code and approves Team Kriesi's project Enfold, the browser is back on the clean settings address (its Connection tab), which reads "Connected to Team Kriesi", names Enfold, and behind "Details" says the six permissions in words and shows the key only by the last four OmnaBase lists for the site.
  - Fails when: a site owner has to copy a key by hand, the way back loses the connection, or the key is printed into the page.

### `tests/e2e/facts.spec.ts`, `forum.spec.ts`, `messages.spec.ts`

Their PM lines are unchanged (the same story); only the steps open the tab, click "Save" and expect the tab back.
