# M14 round four — red-team report (failing tests: the settings screen's look)

2026-10-10, the red team (Opus 5.5). These are the tests the builder makes pass in round four of M14. The round comes from the founder's order of 2026-10-10, given after he saw the five tabs in WordPress's default admin look: "still horrible design. Use a styling similar to the OmnaBase settings where you render the settings nicely in white background cards, and use the canonical tab styling we have in OmnaBase, in the WP backend". The coordinator's contract for the round is restated in each file's header.

The tests are **parked** outside every repository, mirroring this repository's paths:

`/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m14-r4-parked/`

The builder copies them in unchanged. Six existing test files had to change for the new markup. They are parked beside the new ones, each marked "adjusted, not weakened" (see below). Nothing in this repository was changed except this report.

**Counts.** 34 new test methods in 4 new test files, plus one helper file:

| Kind | Methods |
| --- | --- |
| Integration | 14 (29 cases once the data providers expand) |
| Structure | 13 |
| Playwright | 7 |

Six existing files are adjusted.

| Parked file | Tests |
| --- | --- |
| `tests/Structure/M14RoundFourStructureTest.php` | 13 |
| `tests/Integration/AdminLookTest.php` (extends `TabsTestCase`, plain run) | 12 methods, 27 cases |
| `tests/Integration/AdminLookForumTest.php` (extends `RoundThreeTestCase`, group `forum`) | 2 |
| `tests/Integration/AdminLookShapes.php` (a trait: the shape readers and the class names, no tests) | — |
| `tests/e2e/admin-look.spec.ts` | 7 |
| Adjusted: `tests/Integration/RoundOneTestCase.php`, `TabsTestCase.php`, `PlainWordsTest.php`, `PlainWordsShopsTest.php`, `ForumsTabTest.php`, `RoundTwoScreenTest.php` | — |

**Words used below.**
- *Kit*: OmnaBase's own component library (`packages/ui` in the OmnaBase repository). The screen reuses the kit's class names, so the same look comes from the same names.
- *Token*: one of the kit's named design values in its `tokens.css`, such as `--ob-bg-surface: #ffffff`.
- *Card*: a white box with a 1px border and rounded corners that holds one section.
- *Row*: one setting, with its name and help on the left and the control on the right.
- *Primary button*: the one blue main action. *Outline button*: a white button with a grey border.
- *More*: the kit's closed "show more" line (HTML's `<details>`).
- *Notice*: WordPress's own coloured message box.
- *Flash*: the one-time message after an action.
- *Scoped*: every rule and variable sits under one class on the screen's wrapper.
- *Computed style*: the value the browser finally uses after every stylesheet has had its say.
- *Enqueue*: WordPress's list of stylesheets a page loads.
- *Hook*: a named moment where a plugin's code runs.

## A finding for the coordinator first: the card's class name

The contract spells the card `section.ob-form-section`, `h2.ob-form-section__title`, `p.ob-form-section__intro`. **The kit has no such class.**
- The kit's `FormSection` (in `packages/ui/src/primitives/Form.tsx`) renders `section.ob-form__section`, then `div.ob-form__head`, then `h2.ob-form__title`, then `div.ob-form__intro`.
- `forms.css` styles exactly those names.

The contract also says "the kit's class names reused where the markup is the same". So the tests pin the kit's spelling (`ob-form__section`, `ob-form__title`, `ob-form__intro`), and the intro may be any element: the kit's is a `div`.

Every class name lives in one place, `AdminLookShapes`' static properties. If the coordinator wants the literal spelling instead, it is a one-line change per name. Two places would need the same edit:
- the structure test's "reuses the kit's class names" list;
- the spec's selectors.

The structure test `test_the_source_reuses_the_kits_class_names` would refuse `ob-form-section`, because the kit does not style that name. That is deliberate: it catches look-alike names.

The other kit names pinned were checked against the kit's files on 2026-10-10:
- `ob-tabs ob-tabs--line`, `ob-tabs__item`, `ob-tabs__item--active` and `ob-tabs__count` (from `Misc.tsx` and `info.css`);
- `ob-form-row`, `__label`, `__help` and `__control` (from `Form.tsx` and `forms.css`);
- `ob-more`, `ob-more__toggle` and `ob-more__body` (from `Help.tsx` and `forms.css`);
- `ob-page__title` and `ob-page__subtitle` (from `dashboard.css`);
- `ob-btn`, `--primary`, `--outline` and `--sm` (from `primitives.css`);
- `ob-dialog` (from `primitives.css`).

## What ran, and what could not

- **Structure (ran against today's code):** `vendor/bin/phpunit --bootstrap tests/bootstrap.php <parked file>`, with nothing copied in. **13 of 13 fail for their reasons**:
  - there is no `styles/admin.css`;
  - `assets/admin.css` is round two's hand-written dialog stylesheet, with no header and no variables;
  - the templates use `nav-tab-wrapper`, `nav-tab`, `form-table` and `submit_button()`;
  - the changelog has no line about the look.
- **Structure, the feasibility check:** a throwaway copy in the scratchpad (`red-m14-r4-probe/`) checked that a plausible implementation can pass all 13 tests. The copy had:
  - the templates with the kit's tab and button classes and without `form-table`;
  - a 14-rule `styles/admin.css`;
  - `bin/build-form-css.php` extended by about ten lines to also write `assets/admin.css`;
  - one changelog line.

  12 passed. The 13th failed only because that probe stylesheet left out `.ob-more`.
- **Integration (could not run):** the parked folder is not mounted in wp-env's tests container, and copying into the repository was not allowed.
  - Every parked PHP file passes `php -l`.
  - The trait's readers were exercised in plain PHPUnit with the same libxml parser the integration layer uses. They ran against a hand-written page in the kit's markup, where every check passed, and against a captured page in an older markup, where they found the loose sections and the stray fields as they should.
  - **Expected today, from reading the code** (please confirm in the runs):
    - In the plain run, 25 of the 27 cases fail.
    - Two pass on purpose, as guards for the round: `test_the_notices_keep_wordpress_markup` (the flash and the development notices are WordPress notices today and must stay so) and `test_the_stylesheet_is_queued_only_on_the_screen` (round two's loader already does it).
    - In the forum run, both `AdminLookForumTest` tests fail.
    - The adjusted files fail where they read the tab strip or a primary button (`TabsTest`, `ActivityTabTest`, `PagesTabTest`, `ForumsTabTest`, `ShortcodeBuilderTest`), because today's markup has no kit classes. That is the intended effect. The plain-words adjustments pass today.
- **Playwright (not run, as ordered):** the founder is clicking through the development WordPress and the e2e stack.
  - The spec typechecks with the repository's own strict `tsc` options, checked in a scratch folder with symlinks to `fixtures.ts`, `harness.ts`, `global-setup.ts` and `node_modules`.
  - `playwright test --list` loads it (7 tests), so the token file is read without error.
  - The token readout was checked by hand: surface `#ffffff`, border `#e6e6e6`, strong border `#d4d4d4`, accent and primary button `#2271b1`, primary text `#ffffff`, radius `6px`, spacing 20 and 24px, title 22px.
  - **The spec changes nothing on the development WordPress or at OmnaBase.** No `set-key`, no saves: it opens pages, opens and closes the dialog and the Advanced block, and reads. It works in whatever state the site is in.
  - Expected today: 6 of 7 fail. `the stylesheet loads on the plugin's screen alone` passes, as a guard.
- Nothing git-related was run apart from `git status` (clean before and after, apart from this report).

## The tests, in PM speak

### `M14RoundFourStructureTest` (structure)

1. **The admin stylesheet has a source and a built file**
   - **What it checks:** The screen's look has a source stylesheet and a shipped, built one.
   - **Passes when:** `styles/admin.css` and `assets/admin.css` both exist and are not empty.
   - **Fails when:** The look is hand-edited in the shipped file (the next build throws it away), or the shipped file is missing from the zip.
2. **The built file starts with a header naming the build and the tokens**
   - **What it checks:** The shipped stylesheet says, in its first line, which script built it and from which kit tokens.
   - **Passes when:** The first line opens a comment naming a script `bin/<name>.php` and `packages/ui/src/styles/tokens.css`; that first comment also names `styles/admin.css`; the named script exists.
   - **Fails when:** Someone edits the shipped file by hand because nothing says it is generated, or nobody can rebuild it.
3. **The built file holds the kit's variables under the root class**
   - **What it checks:** The kit's variables sit under the screen's own class, and every variable the rules use is defined there.
   - **Passes when:**
     - one `.ob-wp-settings` block sets `--ob-` variables, and no other block sets one (never `:root`);
     - every `var(--ob-…)` used in the file is set in that block;
     - the block sets the card's, border's, corner's, accent's and primary button's variables.
   - **Fails when:** The kit's colours leak onto every admin page through `:root`; a rule names a variable nobody set, so a card turns transparent or a button loses its colour; or the look does not use the kit's card and button values at all.
4. **The variables are the kit's light tokens**
   - **What it checks:** The variables in the shipped file are the kit's light tokens, unchanged.
   - **Passes when:** The OmnaBase repository is beside this one (or `OMNABASE_REPO` names it), the block is not empty, every variable has exactly the value of the first block of OmnaBase's `tokens.css`, and no `--ob-font` variable is copied. The test is skipped without that repository.
   - **Fails when:** The screen's colours drift from the product's (a hand-tuned blue, or a stale build after the tokens changed), or the placeholder typeface slips in.
5. **The built file is the source after the variables**
   - **What it checks:** The shipped file is the build's output, and round two's dialog styles are part of it.
   - **Passes when:** `assets/admin.css` contains the whole text of `styles/admin.css`, after the variables block, and the source styles `.ob-wp-builder` and its `::backdrop`.
   - **Fails when:** The shipped file and the source drift apart, so a fix lands in one and the next build undoes it, or the dialog's styles are lost when the old file is replaced.
6. **The source names no raw colour**
   - **What it checks:** Every colour in the source comes from a kit variable.
   - **Passes when:** `styles/admin.css`, comments left out, holds no hex colour, no `rgb()`/`rgba()`/`hsl()`/`hsla()` and no named colour such as white, black, grey or red. Names inside variables, such as `--ob-blue-500`, do not count.
   - **Fails when:** A hand-picked colour drifts from the palette and stays when the founder changes the tokens.
7. **Every rule of the source is scoped under the root class**
   - **What it checks:** Every rule applies only inside the screen.
   - **Passes when:** Every selector, inside media queries too, starts with `.ob-wp-settings`, and none names `html`, `body`, `:root`, `#wpcontent`, `#wpbody` or `.wp-core-ui`.
   - **Fails when:** The plugin's look changes other admin pages, or WordPress's menus and toolbar shift when the screen loads.
8. **No url, no typeface and no dark block**
   - **What it checks:** The screen loads nothing from elsewhere and ships no typeface or dark mode.
   - **Passes when:** Neither file holds `url(`, `@import`, `@font-face`, "Plus Jakarta", `--ob-font` or `prefers-color-scheme`.
   - **Fails when:** The screen fetches a font or an image from another server, swaps WordPress's system font for the undecided placeholder, or turns dark inside WordPress's light admin.
9. **The build script builds the admin stylesheet**
   - **What it checks:** The script named in the header really builds the screen's stylesheet from the tokens.
   - **Passes when:** Its code, comments left out, names `tokens.css`, `styles/admin.css`, `assets/admin.css` and `.ob-wp-settings`, and `assets/form.css` still names a build script that exists.
   - **Fails when:** The header points at a script that never touches the admin stylesheet, or generalising it breaks the form's stylesheet.
10. **Build-dist runs the admin build**
    - **What it checks:** Building the customer zip rebuilds the screen's stylesheet first.
    - **Passes when:** `bin/build-dist.sh` has a code line (not a comment) running the script named in the header, before the files are staged.
    - **Fails when:** A release ships an old look because nobody rebuilt the stylesheet after the tokens changed.
11. **WordPress's tab, table and button classes leave the templates**
    - **What it checks:** WordPress's tab, settings-table and button classes are gone from the admin templates.
    - **Passes when:** No file under `templates/` names `nav-tab`. No file under `templates/admin/` does any of these:
      - names `form-table`;
      - names `button-primary`, `button-secondary`, `button-small`, `button-large` or `button-link`;
      - gives an element the bare class `button`;
      - calls `submit_button()` (which always prints WordPress's `button` class).
    - **Fails when:** A tab or a button keeps WordPress's look beside the kit's, so the screen looks patched together.
12. **The source reuses the kit's class names**
    - **What it checks:** The source reuses the kit's own class names, and every new name carries the plugin's prefix.
    - **Passes when:** The OmnaBase repository is present, every `ob-` class the source styles is either `ob-wp-…` or a class the kit's stylesheets also style, and the source styles the kit's line tabs, tab item, active tab, card, row, page title, primary and outline buttons and More. The test is skipped without that repository.
    - **Fails when:** The screen invents look-alike names (`ob-form-section` beside the kit's `ob-form__section`), so "the canonical tab styling" is a copy that drifts.
13. **The changelog names the new look**
    - **What it checks:** The unreleased changelog tells customers the settings screen has a new look.
    - **Passes when:** The top section is still "0.1.0 — unreleased" and has a line naming the settings screen with look, design, cards, style or styling.
    - **Fails when:** Customers find a different-looking screen with no word about it, or a dated section was edited.

### `AdminLookTest` (integration, plain run)

1. **Every tab has the page head** (five cases, one per tab)
   - **What it checks:** Every tab opens with the same page head: the title "OmnaBase" in the kit's title style and one short line under it.
   - **Passes when:** The wrapper carries the root class once. It holds exactly one main heading, reading "OmnaBase", with `ob-page__title`. One `ob-page__subtitle` with words (at most 30) follows the title and comes before the tab strip.
   - **Fails when:** The screen keeps WordPress's plain title, has no line saying what it is for, or the line grows into a paragraph.
2. **The tab strip is the kit's line tabs** (five cases)
   - **What it checks:** The tab strip is OmnaBase's line tabs, with the open tab marked, and nothing of WordPress's tab look is left.
   - **Passes when:**
     - there is one named `nav` with `ob-tabs ob-tabs--line`;
     - it holds exactly the five links, each `ob-tabs__item`, leading to `page=omnabase&tab=<slug>` in order, and nothing else to click;
     - only the open tab carries `ob-tabs__item--active` and `aria-current="page"`;
     - no `nav-tab`, `nav-tab-wrapper` or `nav-tab-active` appears anywhere.
   - **Fails when:** WordPress's grey folder tabs remain, two tabs look open, the open tab is not announced to screen readers, or a leftover class brings WordPress's styles back.
3. **The Activity count is the kit's tab count**
   - **What it checks:** The Activity tab shows how many calls need attention as the kit's small count, and nothing when none do.
   - **Passes when:** With two calls needing attention and one waiting, the Activity link holds one `span.ob-tabs__count` reading 2. With only a waiting call there is no count element. No other tab holds one.
   - **Fails when:** The count is glued into the words ("Activity · 2"), shows 0, or counts calls that are merely waiting.
4. **Every section is a card** (five cases, site in full use)
   - **What it checks:** Every section of every tab is a white card of its own, with a heading, and nothing a person reads floats outside a card.
   - **Passes when:**
     - each tab's sections sit in separate cards:
       - Connection: the connection box, the status block with Details, and Advanced;
       - Customer data: the sources and the user fields;
       - Forums: the forum section;
       - Forms and messages: the page picker and the Shortcodes block;
       - Activity: the outbox and the list of what OmnaBase sent.
     - every card is a `section.ob-form__section`, never inside another card, with exactly one `h2.ob-form__title`, and an intro (if any) of at most 30 words;
     - every `h2` outside the dialog is a card's title;
     - no paragraph, list, table, fieldset, disclosure or row with words sits outside a card. The page head, the tab strip, the dialog, WordPress's notices and the version line are the exceptions, and button-only lines (such as the Save line) do not count.
   - **Fails when:** The screen is still loose text on the gray page, two sections share a box, a heading floats between cards, or a help line or table hangs outside the white boxes.
5. **Every setting is a kit row** (Customer data and Forms and messages)
   - **What it checks:** Every setting is a row in the kit's layout, and WordPress's settings tables are gone.
   - **Passes when:**
     - there is no `form-table` and no `th[scope=row]`;
     - every select, text box and tick box outside the dialog sits inside an `ob-form-row__control`;
     - every row sits in a card and has one label with words, at most one help of at most 30 words, and one control, with label and help before the control and outside it;
     - a `<label for>` names a field inside the row's control;
     - Forms and messages has a row labelled "Message centre page" whose control holds the page picker and whose help names `[omnabase_messages]`.
   - **Fails when:** The page settings keep WordPress's two-column table, a tick box floats outside the layout, a row has no name, or a label points at the wrong field.
6. **Every button wears the kit's look on a connected site**
   - **What it checks:** Every button on a connected site's tabs wears the kit's look: blue for the main actions, white with a border for the rest, small on the outbox's rows, and no WordPress button class.
   - **Passes when:**
     - every button, submit and button-like link carries `ob-btn` and exactly one of `ob-btn--primary` and `ob-btn--outline`;
     - Save, Check connection, Build a shortcode and Create the page for me are primary;
     - Connect beside a connection, Disconnect, Dismiss, Copy, Close, Retry, Discard and Send now are outline;
     - the outbox's row buttons carry `ob-btn--sm`;
     - no `button`, `button-primary`, `button-secondary`, `button-small`, `button-large` or `button-link` remains;
     - all the named buttons were seen.
   - **Fails when:** WordPress's buttons mix with the kit's, two blues compete, a secondary action looks like the main one, or WordPress's button styles leak in.
7. **Connect is primary while not connected**
   - **What it checks:** Connect is the blue main button while the site is not connected, and "Forget this connection" stays secondary.
   - **Passes when:** On a fresh site Connect is primary. In the "Disconnected in OmnaBase" state Connect is primary and Forget is outline.
   - **Fails when:** A first-time owner meets a grey Connect, or the clean-up of a dead connection looks like the main action.
8. **The setup line is a card with a list of steps**
   - **What it checks:** The setup line is a card whose steps are a list, not a blue WordPress notice.
   - **Passes when:** With steps open, the line is (or sits in) a card, has no `notice` class, and sits in or holds no notice. Each `[data-step]` element is a list item or inside one, all in one list, and Dismiss stays.
   - **Fails when:** The line still looks like a WordPress alert, or its steps run together as one sentence with dots.
9. **Every disclosure is the kit's More** (Connection, Customer data, Activity)
   - **What it checks:** Every closed block (Details, Advanced, Choose user fields, an outbox row's details) is the kit's More.
   - **Passes when:** Every `<details>` carries `ob-more`. Its first child is `summary.ob-more__toggle`, and everything else sits in one `ob-more__body` with no loose words beside it.
   - **Fails when:** Details and Advanced stay as bare browser triangles, or their content spills out unindented.
10. **The builder dialog is the kit's dialog**
    - **What it checks:** The shortcode builder's dialog is the kit's dialog.
    - **Passes when:** `dialog#ob-wp-builder` keeps `ob-wp-builder`, also carries `ob-dialog`, and keeps its heading "Build a shortcode".
    - **Fails when:** The builder opens as a bare browser box with WordPress's grey border.
11. **The notices keep WordPress markup** (passes today: a guard)
    - **What it checks:** The development notices and the message after an action keep WordPress's notice look, outside the cards.
    - **Passes when:** After saving Customer data, the next view's message carries `notice` and a `notice-…` class and sits in no card. Any development notice carries `notice notice-warning` and sits in no card.
    - **Fails when:** WordPress's familiar "Saved." box turns into something unrecognisable, or a message hides inside a card.
12. **The stylesheet is queued only on the screen** (passes today: a guard)
    - **What it checks:** The screen's stylesheet loads on Settings › OmnaBase and on no other admin page.
    - **Passes when:** At the admin stylesheet moment, the screen queues `assets/admin.css` alone under the plugin's admin handle, with the plugin's version. The Dashboard, the Plugins list, Settings › General, the Posts list and another plugin's settings page queue no stylesheet of the plugin's.
    - **Fails when:** The kit's look leaks into the rest of the admin, or the screen loads without it.

### `AdminLookForumTest` (integration, forum run)

1. **The forum settings are kit rows in a card**
   - **What it checks:** With bbPress active, the Forums tab is a white card whose settings are the kit's rows, without WordPress's table.
   - **Passes when:**
     - there is no `form-table` and no row heading;
     - the forum section is in a well-formed card;
     - every tick, box and choice (the forum ticks too) sits in a row's control;
     - rows labelled "Who answers on the forum", "Line under each answer", "Who may send topics" (both guest choices in one row) and "Resolved tickets" each hold their field;
     - helps are at most 30 words, and nothing to read sits outside a card.
   - **Fails when:** The forum settings keep the grey two-column table, the forum ticks hang outside the layout, or a label no longer sits beside its field.
2. **The forum tab's buttons wear the kit's look**
   - **What it checks:** The Forums tab's buttons wear the kit's look and none of WordPress's button classes.
   - **Passes when:** Save is primary, every other button outline, and no WordPress tab, table or button class remains.
   - **Fails when:** The forum tab keeps WordPress's blue Save while the other tabs wear OmnaBase's.

### `admin-look.spec.ts` (Playwright; changes nothing on the site)

1. **Every tab wears the kit's card, tab, button and title values**
   - **What it checks:** On every tab the cards, the open tab, the buttons and the title take the kit's values, not WordPress's.
   - **Passes when:** At 1280px, each tab has at least one card, and:
     - every card has the surface colour, a 1px solid border in the kit's border colour, the kit's radius and 20 or 24px side padding;
     - the open tab's text is the accent;
     - every visible primary button has the primary button colour and its text colour;
     - every visible outline button is surface-coloured with the strong border;
     - the title is the xl size, weight 600, not the placeholder typeface;
     - at least one primary button was seen.

     All values come from OmnaBase's `tokens.css`, read at test time.
   - **Fails when:** Cards are transparent on the gray page, WordPress's colours win, the open tab is not in the accent, or the font is swapped.
2. **Rows sit side by side on a wide window and stacked on a phone**
   - **What it checks:** A setting's name and help sit beside its control on a wide window and above it on a phone.
   - **Passes when:** The message centre page row shows the picker to the right of the label, on the same line, at 1280px, and below the label at 390px.
   - **Fails when:** The row keeps the table look, or the picker is squeezed into a sliver on a phone.
3. **On a phone no tab overflows and the tab strip scrolls itself**
   - **What it checks:** On a phone nothing on any tab is wider than the screen, and the tab strip scrolls sideways on its own.
   - **Passes when:** At 390px, on each tab:
     - the wrapper's `scrollWidth` is no more than its `clientWidth`;
     - the strip is wider inside than it shows, yet ends within the wrapper;
     - the strip has `overflow-x` set to auto or scroll and really scrolls.
   - **Fails when:** A table pushes the page sideways (the owner must pan), or the tabs wrap into a jumble or run off with no way to reach Activity.
4. **Advanced opens like the kit's More**
   - **What it checks:** Advanced opens like the kit's More: a summary line with a drawn chevron and the body set in.
   - **Passes when:** The summary shows no browser triangle but a pseudo-element with content (the chevron). After a click the body is visible and its text starts right of the summary's left edge.
   - **Fails when:** The bare browser triangle stays, or the body is glued to the left edge.
5. **The builder's dialog is a kit dialog over a dimmed page**
   - **What it checks:** "Build a shortcode" opens a white kit dialog with rounded corners and a shadow over a dimmed page.
   - **Passes when:** The dialog is visible with the surface colour, the kit's radius and a shadow, the `::backdrop` is not transparent, and Close closes it.
   - **Fails when:** A bare grey-bordered box opens, or the page behind stays bright.
6. **The stylesheet loads on the plugin's screen alone** (passes today: a guard)
   - **What it checks:** The stylesheet loads on Settings › OmnaBase and on no other admin page.
   - **Passes when:** The screen links the plugin's `assets/admin.css` once, and the Dashboard links nothing from the plugin's assets.
   - **Fails when:** The look leaks into the rest of the admin, or the screen shows without it.
7. **A picture of each tab for the founder**
   - **What it checks:** The founder gets a picture of every tab, wide and on a phone (a record, not a baseline).
   - **Passes when:** For each tab, once its cards are on the page, a full-page picture is saved as `test-results/admin-look/<tab>.png` (1280px) and `<tab>-phone.png` (390px), each a real image.
   - **Fails when:** The pictures are missing or show the old look, so the founder must click through every tab by hand.

## Existing tests adjusted, not weakened

Each file is parked with an "adjusted, not weakened" note at the change.

- **`RoundOneTestCase.php`**: the three readers that named WordPress's classes now name the kit's. Each reader asks the same question of the new markup, and no assertion changed. A leftover WordPress class no longer counts, and round four bans those classes outright.
  - `navTabs()` reads `a.ob-tabs__item` (was `nav-tab`).
  - `openTabs()` reads `ob-tabs__item--active` (was `nav-tab-active`).
  - `submitsOf()` calls a button primary when it carries `ob-btn--primary` (was `button-primary`).

  Without this change, `TabsTest`, `ActivityTabTest`, `PagesTabTest`, `ForumsTabTest`, `RevokedForgetTest`, `SetupLineTest` and `ShortcodeBuilderTest` would read an empty tab row or no primary button once the markup changes.
- **`TabsTestCase.php`**: the comment only, so it no longer describes `nav-tab` as current. No code changed.
- **`PlainWordsTest.php`, `PlainWordsShopsTest.php`**: the 30-word rule also counts `ob-form__intro`, `ob-form-row__help` and `ob-page__subtitle`. These may be `div`s, which the old "paragraph, list item or description" reading would miss. Nothing was taken out of the count.
- **`ForumsTabTest.php`**: two changes, with the old paths kept and every assertion unchanged.
  - `labelOf()` also reads the row's `ob-form-row__label`, where the table row's `th` used to be.
  - The 30-word reading adds the intro and help classes.
- **`RoundTwoScreenTest.php`**: two changes; the limit is unchanged. Without them, `assertNotEmpty( $texts )` would fail once `.description` goes away.
  - The description reading also covers the new sources and user-fields cards.
  - It also reads the kit's intro and help classes.

## Shapes pinned, and what the builder may rename

- **Renamable in one place** (`AdminLookShapes`): the root class `ob-wp-settings`, the card, title and intro, the row and its three parts, the list of primary words, and the section-to-card map.
  - If a kit name changes, the spec's selectors and the structure test's kit-name list change with it.
  - The structure test's root class is its `ROOT` constant.
- **Kept from earlier rounds and relied on:**
  - the section classes `ob-wp-settings__connection`, `__health`, `__advanced`, `__sources`, `__user-fields`, `__forum`, `__portal`, `__shortcodes`, `__outbox`, `__deliveries`, `__setup`, `__notice` and `__dev`;
  - `[data-step]` in the setup line, and the dialog's `#ob-wp-builder` and `.ob-wp-builder`;
  - the tab addresses `page=omnabase&tab=<slug>`;
  - the button words, and the field names.
- **Chosen by the red team** (the builder may argue them in `DECISIONS.md`):
  - every `<details>` is a kit More, not only Details and Advanced;
  - "Use this page" in the builder is outline (the contract's primary list does not name it), and Dismiss is outline;
  - WordPress's button classes and `submit_button()` leave the admin templates, since their admin styles fight the kit's;
  - the More's chevron is a drawn pseudo-element, and the native triangle is hidden;
  - a changelog line for the look;
  - the forum settings are pinned in a second integration file, because only the forum run renders them.
- **For the builder:** the plugin's `CLAUDE.md` still says "WordPress's own admin styles … no build step for the admin", and its Layout line describes `build-form-css.php` as the form's builder alone. The founder's order changes both; the round should update those lines.
- **Noted, not pinned:**
  - The contract makes two primaries on Forms and messages: Save and Build a shortcode.
  - The kit's own page background (`--ob-bg-page`) is white. In WordPress the cards sit on WordPress's gray page, which matches the founder's description, and the page background is left to WordPress.
  - The kit's tabs are buttons with `role="tab"`. Here they stay links with `aria-current`, because each tab is its own address.
