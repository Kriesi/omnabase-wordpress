# M14 round four — builder's report (the settings screen's look)

2026-10-10, the builder (Opus 5.5). The founder's order: "still horrible design. Use a styling similar to the OmnaBase settings where you
render the settings nicely in white background cards, and use the canonical tab styling we have in OmnaBase, in the WP backend." Red team's
tests: `docs/reviews/m14-round4-red-team-report.md`, copied in unchanged (4 new files and a helper trait, 6 adjusted files). Decisions and the
coordinator's rulings: `DECISIONS.md`, "2026-10-10 — M14 round four: the screen's look". Nothing committed.

## What was built

- **OmnaBase's settings look on Settings › OmnaBase.** A white page (the kit's page colour) filling WordPress's content area beside its dark
  menu; the page head ("OmnaBase" and one line under it); OmnaBase's line tabs (the canonical tab strip: the open tab in the accent blue with
  a soft tint and an underline, the Activity count as the kit's small number); every section a white card with a 1px gray border, rounded
  corners, a heading and a short intro; every setting a row with its name and help on the left and the control on the right (stacked on a
  phone); the kit's buttons (blue for the main actions, white with a gray border for the rest, small ones on the outbox's rows).
- **Kit pieces reused by name** (the *kit* is OmnaBase's own component library): cards, rows, line tabs, buttons, the switch for on/off
  settings, More for every closed block (a summary line with a drawn chevron, the body set in on a light gray ground), rules as a bulleted
  help list, tables in a bordered table card, and the builder's dialog as the kit's dialog (gray foot with Create and Close, the page dimmed
  behind it).
- **Per tab**: Connection: a "Set up" card with numbered steps (ticked once done) and Dismiss beside the heading, the Connection card
  (green dot beside "Connected to …"), the Status card (a coloured dot: green working, red broken, amber waiting, gray not checked) with
  Details, and a "Stored connection" card holding Advanced. Customer data: a switch per source, then User fields with the field ticks in two
  columns. Forums: the rules as a list, the forum ticks in two columns, four rows. Forms and messages: the page picker row, Shortcodes with
  "Build a shortcode" and two switch rows. Activity: count chips and the two tables (on a phone their card scrolls sideways).
- **WordPress's notices stay WordPress's** (the development addresses, the "Saved." message), under the tab strip and as wide as the cards.
- **The stylesheet is built**: `styles/admin.css` (the source: the kit's rules for the reused classes, copied and scoped under
  `.ob-wp-settings`, no raw colour, WordPress's system font, no dark mode) plus the kit's light tokens make `assets/admin.css`, through
  `bin/build-css.php` (the old form-only script generalised to both stylesheets; `bin/build-dist.sh` runs it before staging). Round two's
  dialog styles are merged in.
- WordPress's tab, settings-table and button classes and `submit_button()` are gone from the admin templates. New partials: `notices.php`
  (the frame's notices) and `forum-rows.php` (the forum card's rows). `Tabs::row()` returns the count apart from the label.
- One changelog line under `= 0.1.0 — unreleased =`; `CLAUDE.md` describes the built admin stylesheet and the new script; `.pot` regenerated
  (370 entries).

## Test counts (all green)

| Layer | Result |
| --- | --- |
| `composer lint` (phpcs) | clean |
| `composer test` (unit + structure) | 166 tests, 1713 assertions (structure +13, the red team's) |
| Integration, plain run | 204 tests, 2886 assertions (the red team's 27 cases passed on the first run) |
| Integration, shops run | 55 tests, 307 assertions |
| Integration, forum run | 48 tests, 638 assertions (+2, the red team's) |
| `npm run typecheck` | clean |
| `npx playwright test` (whole suite; the e2e stack on 3011/4391 from the founder's loop was answering and was reused) | 57 passed, none re-run |
| `bin/build-dist.sh` + Plugin Check on the zip | "No errors found" |

3021/4401 and the database on 5435 were not touched; wp-env was neither destroyed nor cleaned.

## The screenshots, judged against the kit's picture

Desktop (1280px), written by the flow:

- `test-results/admin-look/connection.png`
- `test-results/admin-look/data.png`
- `test-results/admin-look/forums.png`
- `test-results/admin-look/pages.png`
- `test-results/admin-look/activity.png`

The phone pictures sit beside them (`<tab>-phone.png`). The cards, rows, buttons and tab strip match OmnaBase's `settings-general` picture:
the same white cards and gray border, heading and intro sizes, label and help on the left, the control on the right, the blue primary
button and the outline buttons. Fixed after looking: the forum ticks' "(public)" notes were pushed to the far right (now beside the name);
the user-field names were boxed like code chips (now plain); the More chevron was too thin; the status dot sat in the middle of a two-line
status (now on the first line); "Build a shortcode" had no space under it; the outbox and deliveries columns were crushed on a phone (they
now keep a 640px minimum inside their sideways-scrolling card). A table with outbox rows was checked on a scratch page outside the
repository (the development site has no rows, and the flows change nothing there).

## Renamed or adjusted

- **Tests adjusted: none of the red team's.** One fixture path: `RoundSixStructureTest`'s copy list names `bin/build-css.php` instead of the
  renamed `bin/build-form-css.php` (a list of files to copy, no assertion changed; recorded in DECISIONS).
- **Renamed:** `bin/build-form-css.php` → `bin/build-css.php`. The tab label "Activity · 2" is now "Activity" with the count in its own
  element (the old translated string is gone).
- **Shapes kept**: every `ob-wp-settings__*` class from earlier rounds, `data-check`, `data-step`, the `h2` "Shortcodes", the button words,
  the field names. `ob-wp-settings__facts` now sits on the Customer data save form (it wraps both cards).

## For the coordinator

- **The page is white, not gray.** The kit's page token (`--ob-bg-page`) is white, so following the ruling the wrapper is white and fills
  WordPress's content area; the cards sit on it exactly as in OmnaBase's picture. On a short tab with WordPress's tall menu, WordPress's
  gray shows below the white area, where its footer is. If the founder prefers the cards on WordPress's gray page, it is one rule to drop.
- **Status colours**: the kit has no success or danger token (its interface is monochrome); the dots use the kit's own `--ob-green-500` and
  `--ob-red-500` (primitives). Say if OmnaBase should add semantic names.
- **Lists of ticks keep WordPress's tick box** (the forums, the user fields): the kit's own tick box draws its mark from an image, and the
  stylesheet loads none (the red team pins "no url(").
- **The front-end switches got short labels** ("Theme styles only", "My Account menu"), with their old sentences as help.

## Left for later

- The founder's look in the local loop (`bin/local.sh up`), especially the dialog on a phone and a site with outbox rows.
- Dark mode and the final typeface wait for the founder's decisions on the kit.
