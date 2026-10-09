# M13 round six — red-team report (failing tests for "ready to hand out")

2026-10-09, the red team (Opus 5.5). The tests the builder makes pass in round six: `readme.txt` finished, the privacy-policy text and the personal-data exporter, translation-ready strings and `languages/omnabase.pot`, Plugin Check clean on the zip, uninstall with its `DELETE /me`, the zip on a clean WordPress, WooCommerce's My Account "Support" link, the "theme styles only" switch, the Shortcodes section, the leftovers of rounds two to five, the versions, the unreleased changelog and the wrap-up records. They are **parked**, in neither repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m13-round6-parked/` with `plugin/…` and `omnabase/…` mirroring each repository (12 files, none replaces an existing one). Runners beside it: `red-m13-round6-{run,int,e2e,omnabase}.sh`.

**Counts**: 36 cases in 11 test files plus the helper `RoundSixTestCase.php`. Plugin: structure 12, integration 15 (plain run 10, shops run 5), Playwright-run checks 7 (`release` 3, `fresh` 2, `uninstall` 1, `cron-health` 1). OmnaBase: structure 2. **Each file fails today for its reason** (run 2026-10-09): structure 9 of 12 (three guards pass by design, below); plain integration 10 of 10; shops 5 of 5; `release` 3 of 3; `uninstall` 1 of 1 (everything is removed today; it fails at "one DELETE /me": 0, the key still answers 200); `cron-health` 1 of 1; `fresh` stops at the missing `.wp-env.fresh.json`, its second test not run; OmnaBase 2 of 2. **Probes** (all removed): a throwaway round six hooked in from outside `src/` made 9 of the 10 plain tests and 5 of 5 shops tests pass (the kept-words test was not probed); a `.pot` from `wp i18n make-pot` made the structure `.pot` test pass; a probe `.wp-env.fresh.json` made `fresh.spec.ts` pass 2 of 2 (that environment was destroyed afterwards).

**Plugin Check today** (dev zip, unpacked, `wp plugin check <path> --format=json`): **2 errors, 0 warnings** — `WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters` ("Setting `suppress_filters` to `true` is prohibited") at `src/Admin/ForumView.php:58` and `src/Forum/Posts.php:158`. The readme placeholders are not flagged by it.

Words: *Plugin Check*, WordPress.org's checker every listed plugin passes (an error blocks, a warning advises); *`.pot`*, the template translators start from; *text domain*, the name translations are filed under (`omnabase`); *uninstall*, what WordPress runs when the plugin is deleted; *exporter*, the piece WordPress's "Export Personal Data" tool calls; *nonce*, a short-lived form token against forged requests; *WP-Cron*, WordPress's built-in scheduler.

## Contradictions and choices (the brief wins unless marked)

1. **Versions in composer.json/package.json** (the coordinator): neither file declares a version today. Pinned: `package.json` carries `"version"` equal to the header; composer.json only if it declares one (Composer advises leaving it out); `bin/check-version.sh` reads package.json.
2. **"The version and the changelog's first dated section"** vs the founder's global rule (the date goes on at release only): pinned `= 0.1.0 — unreleased =` and the release build's refusal of an undated section. The founder's real-site test comes before any date.
3. **Uninstall as "an integration test"**: the integration layer wraps each test in a transaction and turns `DROP TABLE` into a no-op on temporary tables, and fakes OmnaBase. So it runs against the dev WordPress (8888) and the real e2e server, WordPress's way: deactivate, then `uninstall_plugin()` (never `delete_plugins()`: that site's plugin folder is this checkout).
4. **The fresh site**: both of wp-env's sites (8888, 8889) map this repository, so uploading or deleting the plugin there would overwrite or delete the source. Pinned a third wp-env, `.wp-env.fresh.json` (port 8890, no plugins, maps only the harness's must-use plugin, test host `fresh.omnabase.test`). Connect shows only on https with pretty permalinks (round one), so the screen is viewed through that https host. The upload goes over plain `http://localhost:8890` (a routed request loses a multipart file's bytes). `wp-env clean` needs `--force` or it waits for y/N. **Finding**: with the probe config the zip already installs, shows Connect and uninstalls cleanly today.
5. **The idempotency column** (DECISIONS round two's leftover): built in round two (every outbox row carries its own key). No test.
6. **A disabled WP-Cron** is a wp-config constant that one PHP process cannot set and unset again, so it is a flow (`wp config set/delete`).
7. **"Unsupported version"**: simulated by lowering the WooCommerce Subscriptions fake's `$version` to 2.0.0. The real add-on keeps its version in the same place. The minimum versions are the builder's choice.
8. **Exporter, no eraser**: the brief names exporters. WordPress also offers erasers for "Erase Personal Data". **Ruling wanted** on whether the site id should be erasable.
9. **i18n**: the bare-output scan passes today. **Real find**: the facts summary on the screen shows untranslated words (`'licences (the oldest)'` built in `Facts\Shaper`, and the raw section keys such as "orders").
10. **Readme "Privacy" section**: the brief's "readme.txt complete (… privacy …)" is pinned as its own `== Privacy ==` section. Also pinned: an FAQ with three or more questions, and no "PLACEHOLDER" anywhere (the terms and privacy addresses still carry one).
11. **The kept words** (round five's leftover) come back only to the same user, never in the address bar. Where they are kept is the builder's choice; a new option or meta must be on `Options` (the existing guard then checks uninstall).
12. **Flake seen, not ours**: global setup's admin sign-in timed out twice (`waitForURL` after Log In), and once a route callback outlived the browser (`TargetClosedError`). Reruns passed.
13. **Guards that pass today by design**: no bare string printed, every translation call names `omnabase`, the release build refuses an undated section.

## Plugin — `tests/Structure/RoundSixStructureTest.php` (`composer test`)
- **Readme complete** — Passes: no PLACEHOLDER; Description, Installation, FAQ (≥3), External services, Privacy, Changelog with 0.1.0; short description ≤150 characters. Fails: the plugin is handed out with "[PLACEHOLDER: …]" on its page.
- **External services** — Passes: names app.omnabase.com and Kriesi Media GmbH, forum topics and replies, form messages, the message centre, orders/licences, what comes back, "nothing before you connect", and https terms and privacy links. Fails: WordPress.org refuses the disclosure, or owners learn too late that orders leave the site.
- **Privacy pieces registered** — Passes: `src/Privacy/` calls `wp_add_privacy_policy_content` at `admin_init`, and an exporter filter exists. Fails: the owner is never offered the text.
- **No bare strings** (guard) — Passes: no words printed outside translation; the scanner first catches its 5 planted examples. Fails: an English sentence no translation can replace.
- **Prose in src/** — Passes: every label built in `src/` goes through translation (SQL, header values and named technical strings aside). Fails today: "licences (the oldest)" reaches every screen in English.
- **Text domain** (guard) — Passes: every translation call ends with `'omnabase'`. Fails: sentences are filed under someone else's translations.
- **`.pot` present and current** — Passes: `languages/omnabase.pot` with OmnaBase 0.1.0 and `X-Domain: omnabase`, the sample sentences, and every translated sentence of the code. Fails: translators start from nothing or an old list.
- **Versions in package/composer** — Passes: package.json's version equals the header's; composer.json agrees if it has one; check-version reads package.json. Fails: tooling and zip disagree unnoticed.
- **Changelog unreleased** — Passes: top heading `0.1.0 — unreleased`, with lines for My Account, "theme styles only" and the privacy text. Fails: notes dated before anyone has the zip, or owners not told.
- **Release refuses undated** (guard) — Passes: `build-dist.sh --release` on a copy exits non-zero, names the missing date and makes no zip. Fails: a customer zip ships with "unreleased" notes.
- **Fresh site maps nothing** — Passes: `.wp-env.fresh.json` on port 8890, PHP 8.1, no plugins, no mapping of the plugin or this folder, harness and test host set. Fails: the fresh-install test could delete the source code.
- **Records** — Passes: dated "M13 round six" and "M13: done" in DECISIONS.md. Fails: the round's choices and the end go unwritten.

## Plugin — `tests/Integration/ShortcodesSectionTest.php` (plain run; helper `RoundSixTestCase.php`)
- **Section names both** — Passes: `.ob-wp-settings__shortcodes` with an h2 "Shortcodes" names both shortcodes and guest, project, subject; each line ≤30 words. Fails: owners guess the shortcodes.
- **Switch offered, off** — Passes: a "theme styles only" checkbox in that section, unticked, and both pieces still load `assets/form.css`. Fails: the switch is missing, or new sites look unstyled.
- **On keeps the stylesheet away** — Passes: ticked, neither `[omnabase_form]` nor the message centre queues `form.css`; unticked, it is back. Fails: the plugin's look overrides a theme the owner chose, or cannot be undone.
- **Needs the token** — Passes: without the nonce the switch stays off. Fails: another site changes the shop's look.

## Plugin — `tests/Integration/MyAccountLinkTest.php` (shops run)
- **Off by default** — Passes: the "My Account" switch is offered unticked; the menu has no "Support". Fails: the shop's account menu changes unasked.
- **On adds Support** — Passes: "Support" leads to the picked page and sits before "Log out". Fails: customers cannot find the message centre, or reach a missing page.
- **No page picked** — Passes: no "Support" item. Fails: a link to nowhere.
- **Needs the token** — Passes: without the nonce, no link. Fails: a forged request edits every customer's menu.

## Plugin — `tests/Integration/PrivacyTest.php` (plain run)
- **Privacy text** — Passes: one text from "OmnaBase" during `admin_init` names Kriesi Media GmbH, app.omnabase.com, processor, forum topics and replies, form messages, the message centre, customer data, what comes back, and links OmnaBase's privacy policy. Fails: the owner's policy is silent about the service.
- **Exporter** — Passes: an "OmnaBase" exporter returns the customer's site id and is done; for an unknown address, nothing and done. Fails: a data request leaves out the id that ties a customer to their support history.

## Plugin — `tests/Integration/RoundSixLeftoversTest.php` (plain run)
- **Send now** — Passes: a waiting form row shows "Send now"; sending it and the outbox's next run make one call, and the row reads sent. Fails: an owner who fixed an outage still waits, or a message goes twice.
- **Send now needs the token** — Passes: without the nonce the row keeps waiting and nothing goes. Fails: a forged request pushes the outbox.
- **Health: last facts call** — Passes: a health line about customer-data calls exists and shows today's date (or "… ago") after one arrives. Fails: empty customer cards with no clue whether OmnaBase reaches the site.
- **Refused New ticket keeps the words** — Passes: after OmnaBase's 429 the form holds the subject and message (markup shown as text); the address carries none of it; Ben sees none of it; after a success the form is empty. Fails: customers retype, see each other's words, or run script.

## Plugin — `tests/Integration/ShopVersionsTest.php` (shops run)
- **Unsupported version** — Passes: today's versions show no "unsupported version"; WooCommerce Subscriptions at 2.0.0 shows one naming it. Fails: an old add-on breaks the customer card silently.

## Plugin — `tests/e2e/release.spec.ts` (Playwright-run; builds the dev zip)
- **Zip contents** — Passes: only `omnabase/`, with main file, uninstall, readme, autoloader, `form.css` and `languages/omnabase.pot`; no tests, bin, node_modules, wp-env, PHPUnit or Playwright files; no test-mode mention. Fails: customers get the workshop or no translation template. Fails today: no `.pot`.
- **Plugin Check** — Passes: no ERROR on the unpacked zip; warnings attached to the report. Fails: no WordPress.org listing. Fails today: the 2 `suppress_filters` errors above.
- **`.pot` matches the code** — Passes: `wp i18n make-pot` on the zip gives exactly the committed sentences, and the zip's copy is the committed one. Fails: new sentences never reach translators.

## Plugin — `tests/e2e/fresh.spec.ts`
- **Install, activate, Connect** — Passes: upload, "Plugin installed successfully.", "Plugin activated."; Settings › OmnaBase reads "not connected" with Connect and no development notice; front page 200; no PHP problem or critical error anywhere. Fails: a customer's first try breaks.
- **Delete leaves nothing** — Passes: Deactivate, Delete, "OmnaBase was successfully deleted."; no option, user meta or table with "omnabase". Fails: settings and the outbox table stay behind.

## Plugin — `tests/e2e/uninstall.spec.ts`
- **Uninstall removes all, stops the key once** — Passes: on a connected site with outbox rows, user and post meta, transients, options and a scheduled mail, nothing named "omnabase" is left in options, user meta, post meta, tables or cron; exactly one `DELETE /me`; the key then answers 401. Fails: ids, ticket numbers or queued messages stay behind, or the key works for ever.

## Plugin — `tests/e2e/cron-health.spec.ts`
- **WP-Cron off warns** — Passes: with `DISABLE_WP_CRON`, health names WP-Cron as turned off and asks for a real cron job; without it, no warning. Fails: topics wait for ever on such hosts and nobody is told why.

## OmnaBase — `packages/db/src/structure78.test.ts` (`pnpm --filter @omnabase/db test`)
- **Progress 6/6** — Passes: the M13 row reads `| 6 | 6 |`, the section heading 6/6, and the section names the founder's test on a real site. Fails: the page still says 5/6, or the founder's own step is missing.
- **DECISIONS "M13: done"** — Passes: a dated heading, as M12 has. Fails: the milestone's end goes unwritten.

**Existing tests to change**: none expected. **Left on the machines**: no copies in either repository; wp-env's dev site is reactivated with its outbox table and without `DISABLE_WP_CRON`; the probe's fresh environment is destroyed; nothing listens on 3011 or 4391.
