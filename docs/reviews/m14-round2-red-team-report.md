# M14 round two — red-team report (failing tests: the shortcode builder and the marks)

2026-10-10, the red team (Opus 5.5). The tests the builder makes pass in round two of M14 (brief: OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2, choice 2 and "Order of work › Round two"; review: OmnaBase `docs/reviews/m14-settings-cleanup-review.md`, S4–S6, N7, N9; the coordinator's ruling after round one on "Forget this connection"; the founder's answer to question 1: publish at once). They are **parked**, in no repository: `/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-m14-r2-parked/`, mirroring this repository's paths. The builder copies them in unchanged. No existing file was changed.

**Counts.** 34 new test methods in 5 new files: integration 24 (all in the plain run), structure 5, Playwright 5.

| Parked file | Tests |
| --- | --- |
| `tests/Integration/ShortcodeBuilderTest.php` (extends `RoundSixTestCase`) | 15 |
| `tests/Integration/FirstShownTest.php` (extends `RoundSixTestCase`) | 5 |
| `tests/Integration/RevokedForgetTest.php` (extends `TabsTestCase`) | 4 |
| `tests/Structure/M14RoundTwoStructureTest.php` | 5 |
| `tests/e2e/shortcode-builder.spec.ts` | 5 |

**Words used below.** *Shortcode*: a tag such as `[omnabase_form]` that WordPress replaces with the plugin's output. *Dialog*: a small window over the page that must be closed first (HTML's `<dialog>`). *Nonce*: a short-lived form token against forged requests. *Capability*: a permission a role gives (`manage_options`: may change settings; `publish_pages`: may publish pages; `edit_others_pages`: may edit other people's pages). *Role*: a named bundle of capabilities. *admin-post*: WordPress's address that hands a posted admin form to the plugin's handler. *Option*: a stored setting. *Enqueue*: WordPress's list of scripts a page loads. *`<noscript>`*: a part of the page shown only with JavaScript off. *Flash*: the one-time notice on the next view of the screen. *First-display mark*: the time the plugin notes when a tag first shows on the public site. *Vault*: the plugin's encrypted store of the key and secrets. *Project*: the OmnaBase project a site's tickets go to (a key from Connect has exactly one).

## What ran, and what could not

- **Structure (ran, against today's code).** `vendor/bin/phpunit --bootstrap tests/bootstrap.php <parked file>`, nothing copied: **5 of 5 fail for their reasons**. There is no `assets/admin.js`. No PHP under `src/` queues it. The `.pot` lacks 12 of the 13 words: only "Support" is there already, from the My Account link. The readme's Shortcodes part and FAQ do not name "Build a shortcode". The changelog has no builder line and no "Forget this connection" line.
- **Playwright (ran once, from a scratch configuration).** The spec typechecks with strict `tsc` and the repository's own compiler options. It was checked in a scratch folder, with symlinks to the repository's `fixtures.ts`, `harness.ts`, `global-setup.ts` and `node_modules` and a copy of `playwright.config.ts`, so nothing was copied into the repository. It then ran against the running stack (wp-env 8888, OmnaBase 3011/4391, all reused, nothing killed):
  - `beforeAll` passed: a real Connect, and the key has Enfold alone.
  - Test 1 failed for its reason, waiting for `.ob-wp-settings__shortcodes` › button "Build a shortcode".
  - The other 4 did not run, because the file runs serially.
  - `afterAll` cleaned up: the key was stopped at OmnaBase and the vault emptied; the portal pick and the display setting were put back; the relay was reset.

  Two side effects:
  - A first draft of the spec left an empty `omnabase_display` option on the development WordPress. I deleted it afterwards; an empty option behaves the same as a missing one. The parked spec now restores that option exactly.
  - Global setup rewrote the git-ignored `tests/e2e/.auth/admin.json`, as every run does. `git status` stays clean.
- **Integration (could not run).** The parked folder is not mounted in wp-env's tests container, and copying files into the repository was not allowed. All three files pass `php -l`. A libxml parse (the same parser the tests use) confirmed that `<noscript>` and `<dialog>` children are read as elements, so the XPath reads work.

  **Expected today, by reading** (please confirm in the plain run):
  - All 15 `ShortcodeBuilderTest` tests fail. There is no button, no dialog, no Create form, no script and no handler. The two refusal tests also end with a positive control (the administrator's own post makes a page), so they cannot pass today without the builder's code.
  - All 5 `FirstShownTest` tests fail, because nothing writes `first_shown`. The two "no mark" tests end with a visitor's real render that must set the mark.
  - 3 of the 4 `RevokedForgetTest` tests fail, because there is no "Forget this connection" button. The fourth (a working connection keeps Disconnect and shows no Forget) passes today on purpose; it is a guard.

  Disconnect's handler already makes no call with a revoked key (round one's `Client` returns the revoked error first) and clears the vault. So once the button exists, `test_forget_clears_the_site_without_calling_omnabase` should pass without new handler logic.

## The tests, in PM speak

### `ShortcodeBuilderTest` (integration, plain run)

1. **The Shortcodes block has a "Build a shortcode" button that never submits the save**
   - **What it checks:** The Shortcodes block has a "Build a shortcode" button that opens the builder and never saves the tab by accident.
   - **Passes when:** The block has one button with those words, of the kind that does not send a form, and it points at the builder's dialog by its id.
   - **Fails when:** There is no way into the builder, or clicking the button submits the tab's settings: the page reloads, a half-made choice is saved, and no dialog opens.
2. **The dialog holds the choices, the tag, Copy and Create**
   - **What it checks:** The dialog holds every choice the brief names, plus the live tag, Copy, the paste line, the message centre's page and "Create the page for me".
   - **Passes when:** The dialog has:
     - the two cards, worded as in the brief;
     - a "Subject starts with" field;
     - "Visitors who are not signed in" with "see a sign-in link" and "see the contact form instead";
     - a read-only field that already holds a tag;
     - Copy, and "Paste it into any page or post.";
     - "It works only on the page picked as the message centre page" with the picked page's title;
     - a primary "Create the page for me".

     With one project, there is no project choice.
   - **Fails when:** An owner has to guess what a choice does, cannot see or copy the tag, puts the message centre on the wrong page, or is asked to pick a project when there is only one.
3. **The dialog sits outside the save form; Create has its own form and token**
   - **What it checks:** The builder's form stands apart from the tab's save form, has its own token, and carries every choice.
   - **Passes when:**
     - the dialog is outside the save form, and no form sits inside another;
     - the Create form names its action and holds a valid token for it;
     - the form contains both cards, the subject and both guest choices.
   - **Fails when:** The browser drops the inner form, so Create saves the settings instead, or the server never sees the owner's choices.
4. **Without JavaScript the six lines stay inside `<noscript>`**
   - **What it checks:** Without JavaScript the owner still reads the two tags and their options, as today.
   - **Passes when:** The block's no-script part holds today's six lines in today's order, and none of them stands outside it.
   - **Fails when:** With JavaScript off, a dead button and no tags; with JavaScript on, the old list repeated beside the builder.
5. **The script loads only on the plugin's screen**
   - **What it checks:** The builder's script loads on Settings › OmnaBase and on no other admin page.
   - **Passes when:** `assets/admin.js` is queued on the plugin's screen and not on the Dashboard, the Posts list or Settings › General.
   - **Fails when:** The builder does nothing, or every admin page carries the script for nothing.
6. **Create refuses without the form's token**
   - **What it checks:** Create makes nothing without the form's token.
   - **Passes when:** A post with no token, or with another action's token, makes no page. The same post with its own token, and only the kind chosen, makes one.
   - **Fails when:** Another site can make an administrator's browser publish pages here.
7. **Create refuses a person who may not publish pages**
   - **What it checks:** Only a person who may both change settings and publish pages can have a page made.
   - **Passes when:** A settings-only role and an Editor, each with their own valid token, make no page. The administrator's same post makes one.
   - **Fails when:** A settings-only helper account publishes pages it could never publish by hand, or an Editor reaches a settings action.
8. **Create refuses an unknown kind or project**
   - **What it checks:** The server accepts only the two kinds and only the site's own projects.
   - **Passes when:** These make no page:
     - the kinds `gallery`, empty and `form"]`;
     - with two projects kept, an unknown project slug, for the form and for the message centre.
   - **Fails when:** A crafted post publishes an unknown tag, or a form that sends to a project the site may not use.
9. **The subject is cleaned on the server**
   - **What it checks:** The subject can never close the tag early and smuggle another shortcode onto a page.
   - **Passes when:**
     - `x"] [other` makes a page whose only tag is `[omnabase_form subject="x other"]`, with one `[` and one `]` in the whole content;
     - a subject of only `"[]"` makes `[omnabase_form]`.
   - **Fails when:** A pasted subject publishes a page that runs a second shortcode, or shows a broken tag.
10. **Create makes the contact page, published, with its tag and title**
    - **What it checks:** Create with the contact form publishes a "Contact support" page with the form's tag at once.
    - **Passes when:**
      - one page is made: published, titled "Contact support", written by the administrator;
      - its only tag is `[omnabase_form subject="Billing"]`, with no project because the key has one, although the post named it;
      - the message centre's pick is untouched, and the browser is back on the Forms and messages tab.
    - **Fails when:** A draft nobody sees, a "Contact-2" page, a tag that breaks when the project is renamed, or the message centre page silently replaced.
11. **Create makes the message centre page and picks it**
    - **What it checks:** Create with the message centre publishes a "Support" page, picks it, and the next view says so with an edit link.
    - **Passes when:**
      - one page is made: published, titled "Support", with only `[omnabase_messages guest="form"]`;
      - it is now the picked page;
      - the tab's notice holds "published", "Support" and a link to edit that page.
    - **Fails when:** The new page is not picked, so it shows only the "picked page" note and gets cached; the owner cannot find it; or guests get the sign-in link although the owner chose the form.
12. **The page is built from the choices, never from tag text sent**
    - **What it checks:** The page's content comes only from the checked choices, never from tag text or content the browser sends.
    - **Passes when:**
      - a form post that also sends `[omnabase_messages][evil]`, a content field and a guest choice makes only `[omnabase_form]`;
      - a message centre post with a subject makes `[omnabase_messages]`;
      - an unknown guest value makes either no page or a page without a guest option.
    - **Fails when:** Anyone who can post the form can publish any shortcode or text, or the other kind's options leak into the tag.
13. **With several projects the dialog offers them, and the tag names one**
    - **What it checks:** A key with several projects is asked which one, and the tag names it.
    - **Passes when:**
      - with Enfold and Omnalingo kept, the Create form offers `enfold` and `omnalingo`;
      - the form for `omnalingo` makes a tag naming `project="omnalingo"`;
      - the message centre for `enfold` with the sign-in link makes `[omnabase_messages project="enfold"]`.
    - **Fails when:** A multi-project site gets a form that shows only an administrator's "name a project" note, or messages go to the wrong project.
14. **An existing published page with the tag is offered**
    - **What it checks:** Before making a page, the dialog offers a published page that already carries the tag. Drafts are not offered.
    - **Passes when:**
      - the form offer links to the published `[omnabase_form subject="Hi"]` page and shows its title;
      - the message centre offer links to the picked page;
      - nothing links to the draft.
    - **Fails when:** A second contact page (and a "contact-2" address), or the owner pointed at a draft.
15. **A page the person may not edit is not offered**
    - **What it checks:** The offer lists only pages the person may edit.
    - **Passes when:** For a role that may publish and edit its own pages but not other people's, the dialog offers the user's own contact page and not the administrator's.
    - **Fails when:** The dialog points at a page the person cannot open, or reveals edit addresses of pages they may not touch.

### `FirstShownTest` (integration, plain run)

1. **The first form render stores the time once**
   - **What it checks:** The first time the contact form shows on the public site, the time is noted once, and the switches are kept.
   - **Passes when:**
     - with "theme styles only" on, a visitor's view stores a whole-number `form` time between before and after the view, and the switch stays on;
     - with the mark moved back to long ago, a visitor's view and a signed-in customer's view leave it there and write nothing to the stored setting.
   - **Fails when:** The setup line never learns the form is live, the time creeps forward, every view writes to the database, or noting the time turns the styles switch off.
2. **The first message centre render stores its own time once**
   - **What it checks:** The first time the message centre shows on its page, that time is noted once.
   - **Passes when:**
     - a visitor's view of the picked page (the sign-in link) stores `messages` and no `form`;
     - with the mark moved back, a signed-in customer's view leaves it there and writes nothing.
   - **Fails when:** The message centre never counts as live, or its time changes with every visit.
3. **A note only administrators see sets no mark**
   - **What it checks:** A tag that shows only the administrators' note does not count as live.
   - **Passes when:**
     - the message centre on a page that is not the picked one stores no mark;
     - in between, a visitor's real form does store its mark;
     - the form on a site that is not connected stores none.
   - **Fails when:** The setup line ticks "Add the form to a page" although visitors see nothing there.
4. **Rendering inside the admin sets no mark**
   - **What it checks:** Showing a tag inside wp-admin (a preview in an editor or page builder) is not the public site.
   - **Passes when:** A render on an admin screen stores no mark. The same tag for a visitor on the public site then stores it.
   - **Fails when:** An owner previewing a draft is told the form is live.
5. **The setup line's last step is done after the first render**
   - **What it checks:** After the form first shows, the setup line's last step reads done.
   - **Passes when:** On a connected site with customer data not yet saved, a visitor's view turns "Add the form to a page" from not done to done.
   - **Fails when:** The owner keeps being told to add the form to a page that already has it.

### `RevokedForgetTest` (integration, plain run)

1. **The revoked state offers Forget beside Connect**
   - **What it checks:** In "Disconnected in OmnaBase", the tab offers "Forget this connection" beside Connect.
   - **Passes when:**
     - Connect stays;
     - a grey (not primary) "Forget this connection" posts Disconnect's action with a valid token;
     - no button reads "Disconnect".
   - **Fails when:** An owner who will not reconnect cannot clear the dead connection, or meets "Disconnect" for a connection OmnaBase already ended.
2. **Forget clears the site without calling OmnaBase**
   - **What it checks:** Forget clears the site's side without calling OmnaBase with the dead key.
   - **Passes when:**
     - posting it makes no call to OmnaBase, empties the vault and removes the kept connection record;
     - it returns to the Connection tab, and the next view is "not connected" with Connect;
     - the notice gives no advice to revoke the key in OmnaBase.
   - **Fails when:** The site keeps the dead key, knocks once more with it, or sends the owner to revoke a key OmnaBase already stopped.
3. **Forget needs the form's token**
   - **What it checks:** Forget needs the form's token.
   - **Passes when:** Without the token, the key stays and the state stays revoked.
   - **Fails when:** Another site can make an administrator's browser wipe the connection.
4. **The connected state keeps Disconnect and shows no Forget** (passes today; a guard)
   - **What it checks:** A working connection keeps Disconnect and shows no Forget.
   - **Passes when:** A connected tab has "Disconnect" and no "Forget this connection".
   - **Fails when:** An owner is offered to "forget" a live connection, which would leave the key alive in OmnaBase.

### `M14RoundTwoStructureTest` (structure)

1. **The builder's script exists and is plain JavaScript**
   - **What it checks:** The builder's script exists, opens the browser's own dialog, and copies with the clipboard, falling back to selecting the field.
   - **Passes when:** `assets/admin.js` exists and has `showModal`, `navigator.clipboard` and `.select(`. It has no jQuery, no `innerHTML` and no test-mode switch.
   - **Fails when:** No script, a library the screen does not promise, a typed subject put into the page as HTML, or test code shipped.
2. **The script is queued from the admin side only**
   - **What it checks:** The script is queued from the admin side, at WordPress's moment for admin scripts, and never by the public pieces.
   - **Passes when:** A PHP file under `src/` names `assets/admin.js` and `admin_enqueue_scripts`. The form's and the message centre's code never name it.
   - **Fails when:** The builder's script reaches visitors' pages, or is printed by hand outside WordPress's script list.
3. **The `.pot` holds the builder's words**
   - **What it checks:** Every word of the builder and of "Forget this connection" can be translated.
   - **Passes when:** The `.pot` holds the 13 words listed under "Shapes" (compared without regard to capitals) and a sentence starting "It works only on the page picked as the message centre page".
   - **Fails when:** A translated site shows the builder half in English, or the `.pot` was not regenerated.
4. **The readme mentions the builder**
   - **What it checks:** The readme tells owners about the builder.
   - **Passes when:** The readme's "= Shortcodes =" part and its FAQ both name "Build a shortcode".
   - **Fails when:** Owners keep copying tags by hand and never learn the plugin can make the page.
5. **The changelog names the builder and Forget**
   - **What it checks:** The unreleased changelog names the builder and "Forget this connection".
   - **Passes when:** The top section is still "0.1.0 — unreleased", with a line naming "Build a shortcode" or "shortcode builder" and a line naming "Forget this connection".
   - **Fails when:** Customers are not told about the round's two visible changes, or a dated section was edited.

### `shortcode-builder.spec.ts` (Playwright, serial)

1. **Build a shortcode opens the dialog and Escape closes it**
   - **What it checks:** The button opens the dialog, Escape closes it, and the keyboard is back on the button.
   - **Passes when:** A click shows the dialog. Escape hides it and focuses the button.
   - **Fails when:** The builder cannot be opened or left with the keyboard, or a keyboard user is lost at the top of the page.
2. **The message centre with the form for guests: the tag follows, Copy, Create the page**
   - **What it checks:** The owner builds the message centre with the form for guests, copies the tag, and has the page made, published and picked.
   - **Passes when:**
     - choosing "Message centre" shows `[omnabase_messages]` and the picked-page line;
     - choosing "see the contact form instead" shows `[omnabase_messages guest="form"]`, and no project choice is visible;
     - Copy puts exactly that tag on the clipboard;
     - Create returns to the tab with a notice holding "published" and an edit link;
     - the page is published, titled "Support", carries the tag, is picked, and the picker shows it.
   - **Fails when:** The tag does not follow the clicks, Copy copies nothing, the page is a draft or carries another tag, or the owner must still pick it by hand.
3. **A visitor's first view of the new page sets the message centre's first-display mark**
   - **What it checks:** A visitor's first view of the new page notes that the message centre is live.
   - **Passes when:** A visitor sees the contact form in place of the tag, and `first_shown.messages` then holds a time.
   - **Fails when:** The setup line and OmnaBase's site report never learn the message centre is live.
4. **The subject is cleaned as it is typed**
   - **What it checks:** The subject is cleaned as it is typed.
   - **Passes when:** With "Contact form" chosen, typing `x"] [other` leaves the field at `x other` and the tag at `[omnabase_form subject="x other"]`.
   - **Fails when:** A quote or bracket breaks the pasted tag or runs another shortcode.
5. **A key with several projects gets a project choice**
   - **What it checks:** Only a key with several projects asks which one, and the tag then names it.
   - **Passes when:** With a second project added to the kept connection record, a "Project" select shows, and choosing it puts `project="second-e2e"` into the tag. The record is put back afterwards.
   - **Fails when:** A multi-project site gets a tag without a project, or the choice is missing.

## Shapes pinned (the builder may rename anything marked "renamable", and tells the red team)

| What | Pinned | Source |
| --- | --- | --- |
| The button | `<button type="button">` "Build a shortcode" inside `.ob-wp-settings__shortcodes` (outside the `<noscript>`), `aria-controls` = the dialog's id | brief's words fixed; `type="button"` required (the block is inside the save form, and `TabsTest` counts submit buttons); `aria-controls` renamable |
| The dialog | one `<dialog class="ob-wp-builder">` with an id, not inside the save form | brief (`<dialog>`); class renamable |
| Create's form | its own `<form>` with hidden `action` = `omnabase_create_page`, nonce of the same name in `_wpnonce`; holds every choice; one submit, primary, "Create the page for me" | action name renamable; words fixed |
| Field names | `omnabase_builder[kind]` radios `form` / `messages`; `omnabase_builder[subject]` text; `omnabase_builder[guest]` radios `""` (sign-in link) / `form`; `omnabase_builder[project]` select of slugs, absent with one project | renamable together |
| Labels (words) | "Contact form: visitors' messages become tickets", "Message centre: signed-in customers read and answer their tickets", "Subject starts with", "Visitors who are not signed in", "see a sign-in link", "see the contact form instead" (capitals free), "Copy", "Paste it into any page or post.", "It works only on the page picked as the message centre page" (+ the picked page's title), "Project" (the select's accessible name, Playwright) | brief, fixed |
| The tag field | the dialog's one `readonly` input or textarea, holding a tag from the start | readonly fixed; element free |
| Copy | `<button type="button">` "Copy"; clipboard API, fallback `.select(` | brief |
| Page titles | "Support" (message centre), "Contact support" (form); published at once; author = the poster | brief + founder's Q1 |
| Page content | exactly one OmnaBase tag; `project` left out with one project (even when posted); `subject` cleaned of `"`, `[`, `]`; attribute order not pinned (parsed), except `[omnabase_messages guest="form"]`, `[omnabase_messages project="enfold"]`, `[omnabase_form subject="…"]` as single-attribute strings | brief + review S4/S5 |
| The pick | a message centre page made by Create becomes `omnabase_portal['page']`; a form page leaves it alone | brief |
| The flash | the screen's notice (`.ob-wp-settings__notice`) holds "published", the title and a link to `post.php?post=<id>&action=edit` | "published" and link chosen, renamable |
| The offer | `.ob-wp-builder__existing[data-kind="form|messages"]` in the dialog, linking to the page (edit address or permalink), with its title; only published pages the person may edit | class and attribute renamable |
| The script | a queued script whose address ends in `assets/admin.js`, only at `admin_enqueue_scripts` with hook `settings_page_omnabase` | file fixed; handle free |
| First-display marks | `omnabase_display['first_shown']['form'|'messages']` = a whole number (time), written once; no write on later views; switches kept; not set by an administrator's note, nor by a render inside wp-admin | round one's shape; the two "not set" rules chosen, renamable with a reason |
| Forget | in the `revoked` state: a submit "Forget this connection", not primary, in an `omnabase_disconnect` form with its nonce; no "Disconnect" button in that state; none in the `connected` state | coordinator's ruling; words fixed |
| `.pot` words | the 13 above (+ the picked-page line by prefix) | structure guard |
| Readme / changelog | "Build a shortcode" in "= Shortcodes =" and the FAQ; changelog lines naming the builder and "Forget this connection" | coordinator |

## Left open on purpose (the builder decides and records it in DECISIONS)

- **Create while a page with the tag already exists.** The tests pin the offer in the dialog. They do not pin whether the server still makes a new page when Create is posted anyway. Every test that creates a page first moves any such page to the bin, so both answers pass.
- **A guest value other than `form`.** Either refuse the post or drop the value. Both pass; the tag must never carry it.
- **Messages with several projects and no project chosen.** Not pinned. The form's unknown project is refused; an unknown project is refused for both kinds.
- **Where the dialog sits in the markup.** It can be after the save form or elsewhere on the tab, as long as it is outside the save form and the button names it.
- **No new option.** The marks live in `omnabase_display` (already in `Options::ALL` and `uninstall.php`). If the builder adds an option, the existing guard checks that `uninstall.php` names it.

## Notes for the builder

- The round-one guards already read the dialog. The dialog is not inside a "Details" block, so:
  - `PlainWordsTest` (banned words, at most 30 words per line) applies to every line in it;
  - `TabsTest` (one primary "Save", no form inside another) applies too.
- `ShortcodesSectionTest` keeps passing only if the six lines stay inside the block. It reads the block's whole text, `<noscript>` included.
- The e2e spec connects through the real Connect page (form.spec.ts's way). For the run, it sets published pages that already carry an OmnaBase tag to draft and publishes them again at the end. Never run it beside OmnaBase's own suite.
- Integration fixture to know: `RoundFiveTestCase` already creates a published, picked page carrying `[omnabase_messages]`. The offer tests rely on it, and the creation tests move it to the bin first.
