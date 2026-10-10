# M14 round two — builder's report (the shortcode builder and the marks)

2026-10-10, the builder (Opus 5.5). Brief: OmnaBase `docs/briefs/m14-settings-cleanup.md` (draft 2, choice 2, "Order of work › Round two").
Red team's tests: `docs/reviews/m14-round2-red-team-report.md`, copied in unchanged (5 files, 34 tests). Decisions and the coordinator's
rulings: `DECISIONS.md`, "2026-10-10 — M14 round two: the shortcode builder and the marks". Nothing committed.

## What was built

- **"Build a shortcode"** on the Forms and messages tab opens a dialog (a small window over the page that must be closed first; the browser's
  own `<dialog>`). The owner picks one of two cards (contact form, message centre), a project only when the site's key has several, the
  subject's first words (form) or what visitors who are not signed in see (message centre). The shortcode (the tag, such as
  `[omnabase_form]`, that WordPress replaces with the plugin's output) updates live in a read-only field, with Copy and "Paste it into any
  page or post." For the message centre the dialog names the page picked as the message centre page. Escape or Close shuts it, and the
  keyboard is back on the button. Without JavaScript the button stays hidden and the six lines of round six show as before.
- **"Create the page for me"** publishes "Contact support" (form) or "Support" (message centre) at once, written by the person who clicked,
  and a new message centre page becomes the picked one. The page's content is built on the server from checked choices only; it needs both
  the settings permission and the permission to publish pages, and its own form token. The next view says the page is published, with an
  "Edit the page" link.
- **An existing page is offered first**: when a published page the person may edit already carries the tag, the dialog names it (linked to
  its editor) and offers "Use this page" (the form: opens it; the message centre: picks it); Create then reads "Create a new page anyway",
  in grey.
- **Refusals in words**: an unknown choice, or several projects with none chosen, makes no page and the notice says why.
- **The first-display marks**: the first time the contact form or the message centre shows on the public site, the time is stored once
  (beside the two front-end switches); later views write nothing. A note only administrators see, a render inside wp-admin, in the block
  editor's loading of a page, or in a draft's preview do not count. The setup line's "Add the form to a page" ticks off with it.
- **"Forget this connection"**: in "Disconnected in OmnaBase", a grey button beside Connect clears the dead connection from the site, without
  calling OmnaBase, and says so in words.
- New small classes (all under 110 lines, methods under 30): `ShortcodeTag`, `BuilderChoices`, `PageMaker`, `BuilderActions`, `TaggedPages`,
  `BuilderView`, `AdminAssets` (Admin) and `FirstShown` (Forms); `assets/admin.js` (plain JavaScript, 182 lines) and a small
  `assets/admin.css`, both loaded only on Settings › OmnaBase. No new stored setting.
- Readme (Shortcodes part, a new FAQ, the "disconnected" answer), three changelog lines under `= 0.1.0 — unreleased =`, `.pot` regenerated
  (363 entries).

## Test counts (all green)

| Layer | Result |
| --- | --- |
| `composer lint` (phpcs) | clean, 152 files |
| `composer test` (unit + structure) | 153 tests, 1180 assertions (structure +5, the red team's) |
| Integration, plain run | 177 tests, 2150 assertions (+24, the red team's; all passed on the first run) |
| Integration, shops run | 55 tests, 305 assertions |
| Integration, forum run | 46 tests, 543 assertions |
| `npm run typecheck` | clean |
| `npx playwright test` (whole suite, a fresh OmnaBase e2e stack from `main` at 24856257) | 50 passed, none re-run; `connect.spec.ts` passes against the new seed |
| `bin/build-dist.sh` + Plugin Check on the zip | no errors |

The stale stack on 3011/4391 was stopped by its listening processes only (`lsof -sTCP:LISTEN`): a plain `lsof -ti tcp:4391` also lists a
Chrome helper connected to the port, which was left alone. Playwright started the fresh stack and stopped it after the run. 3021/4401 and
the database on 5435 were not touched. One extra check outside the repository (a scratch spec, not kept): "Use this page" for the message
centre picks the page and the Create button turns into the grey "Create a new page anyway"; it passed.

## Renamed or adjusted

- **Renamed: nothing.** Every shape in the red team's "Shapes pinned" table is kept.
- **Tests adjusted: none.**
- **Deviation from the round's order, recorded**: the script is queued at `admin_enqueue_scripts` (WordPress's moment for admin scripts,
  checked against the screen's own page name), not inside the screen's load hook; the red team's tests and structure guard expect it there,
  and the effect is the same.

## Left for the fixing round

- **No automated test covers "Use this page"** or the "Create a new page anyway" swap (only the scratch check above). A Playwright step would
  pin them.
- The dialog was checked by the flows and the scratch spec, not yet by eye: the cards' look, the dialog on a phone width, and the offer's
  wording are for the founder's look in the local loop (`bin/local.sh up`).
- The message centre with `guest="form"` also sets the contact form's mark (a visitor does see the real form there). Say if only a plain
  `[omnabase_form]` should count.
- Still not ordered: question 4's daily site report.

## For the coordinator

- Ruling (c) was applied to **both kinds**: a contact form on a site with several projects and none chosen is refused too, since visitors
  would see nothing. Say if the form should instead be allowed through.
- The marks travel through an internal action (`omnabase_shown`) rather than a constructor argument (the message centre's shortcode already
  takes six). It is a hook name other code could listen to; it is not documented as public.
