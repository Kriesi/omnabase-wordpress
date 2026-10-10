# M14 round one — builder's report (tabs and words)

2026-10-10, the builder (Opus 5.5). Brief: OmnaBase `docs/briefs/m14-settings-cleanup.md` (draft 2, choices 1, 3, 4, 5, "Order of work ›
Round one"). Red team's tests: `docs/reviews/m14-round1-red-team-report.md`, copied in unchanged except the four adjustments below. The
decisions and rulings: `DECISIONS.md`, "2026-10-10 — M14 round one: tabs and words". Nothing committed.

## What was built

- **Five tabs** on Settings › OmnaBase: Connection, Customer data, Forums, Forms and messages, Activity. They use WordPress's own tab row
  (`nav-tab`), and the open tab is in the address (`&tab=…`; no tab or an unknown one opens Connection). Only the open tab is built and
  printed. Every save and every action comes back to the tab it was made on.
- **One save form per settings tab**, each with one blue "Save". The message centre's page and the two display switches (theme styles only,
  the WooCommerce My Account link) now save together in one form. The old two handlers are gone.
- **Connection tab**: a setup line at the top ("Set up: Connect ✓ · Customer data · Forums · Add the form to a page"; Dismiss hides it).
  Then the connection box. Then one status line ("Working both ways · checked 2 minutes ago" / "OmnaBase cannot reach this site"), with the
  timer warning visible in plain words when it applies. One closed "Details" holds the permissions in words, the key's last four, the three
  directions and the last customer-data call. A closed "Advanced" at the end holds the encryption advice.
- **The fourth state, "Disconnected in OmnaBase"** (OmnaBase answered 401: someone stopped the site's key there): the screen offers Connect
  only, and the site makes no further call with the key, the outbox included. Waiting rows keep waiting, their tries untouched, and go after
  Connect. A form message sent meanwhile waits the same way. (Outbox: the plugin's lasting list of calls to OmnaBase still to make.)
- **Activity tab**: "On its way to OmnaBase" (each row's raw last answer behind a "Details"), and "What OmnaBase sent here" with events in
  words. The tab reads "Activity · 2" while two rows need attention, and the Settings menu shows the same count as WordPress's bubble after
  an unchanged "OmnaBase".
- **Plain words**: every visible line is checked against the one banned list (`webhook`, `endpoint`, `401`, `cron` …). The forum labels now
  say what each setting does ("Who answers on the forum", "Line under each answer", "Who may send topics": Members only / Guests too).
- **Plugins list**: a "Settings" link beside Deactivate, and the description ends "Set it up under Settings › OmnaBase."
- **Readme** (Description, Installation, FAQ, External services), **changelog** (six lines under `= 0.1.0 — unreleased =`, plus one
  "WP-Cron" line reworded) and **`.pot`** (regenerated, 331 entries) follow the new words.
- New small classes: `Admin\Tabs`, `StatusLine`, `PermissionWords`, `SetupLine`, `PagesActions`, `PluginLinks`; tab templates under
  `templates/admin/tabs/`. The largest admin class is `Health` at 133 lines. One new stored setting, `omnabase_setup` (the dismissed setup
  line), is in `Options::ALL` and `uninstall.php`. The revoked mark is kept inside the vault's own option, beside the key.

## Test counts (all green)

| Layer | Result |
| --- | --- |
| `composer lint` (phpcs) | clean, 142 files |
| `composer test` (unit + structure) | 148 tests, 1126 assertions |
| Integration, plain run | 153 tests, 1920 assertions (includes the builder's new `RevokedOutboxTest`) |
| Integration, shops run | 55 tests, 305 assertions |
| Integration, forum run | 46 tests, 543 assertions |
| `npm run typecheck` | clean |
| `npx playwright test` (whole suite, OmnaBase's running e2e stack reused) | 45 passed, none re-run |
| `bin/build-dist.sh` + Plugin Check on the zip | no errors |

The first full Playwright run had two failures; both were fixed in the code, and every flow then passed:

- **`connect.spec.ts`**: its "open every Details" helper clicks the closed disclosures by position, and the screen had two. The tab now has
  one "Details", which is also what the brief describes.
- **`settings-tabs.spec.ts`**: a setup step's link had the same name as the "Customer data" tab. Each step's link is now named
  "Setup step: …" for screen readers; the visible words are unchanged.

## Renamed or adjusted

- **Renamed: nothing.** Every shape in the red team's "Shapes pinned" table is kept as pinned.
- **Tests adjusted** (each with its reason in the test's comment and in DECISIONS):
  - `ShortcodesSectionTest` (theme switch) and `MyAccountLinkTest` (My Account switch): both read the switch's form from the Shortcodes
    block's HTML alone, but the tab's one save form wraps that block (as `PagesTabTest` pins), so the excerpt holds no form. They now read
    the switch from the whole tab and assert separately that it sits inside the Shortcodes block.
  - `ForumsTabTest` (labels): the test assumed guests were off, but an unset guest setting means "Guests too" (M13's default). The test now
    stores guests off first; its assertions are unchanged.
  - `round-trip.spec.ts`: the menu link is now found by a name that starts with "OmnaBase", because the bubble lengthens the name (the red
    team's point 11).
- **Not yet updated**: `ForumScreenTest`'s file header still calls the guest setting a checkbox. Its tests post the value directly and pass;
  the header was left as the red team wrote it.

## Left for round two

- The shortcode builder dialog (Copy, "Create the page for me"), its `admin.js` and the `<noscript>` fallback.
- Writing the first-display marks when a tag shows on the public site. Round one only reads `omnabase_display['first_shown']`.
- Anything from question 4 (the daily site report): not ordered.

## For the coordinator

- **Two queries on the settings screen.** The brief says one count per admin page. Every admin page makes one count for the bubble; the
  settings screen makes one more for its tab row, so the number is always fresh. Say if a strict single count is wanted.
- **No Disconnect button in the revoked state.** Only Connect is offered, because OmnaBase already stopped the key. Disconnect still clears
  the site if posted. Say if the button should show there too.
