/**
 * M14 round two, the red team's failing flow: the shortcode builder in a real browser: "Build a shortcode" opens the browser's own dialog,
 * the tag follows the owner's clicks, Copy puts it on the clipboard, "Create the page for me" publishes the page and picks it, Escape closes
 * the dialog and gives focus back, and the subject is cleaned as it is typed. A visitor's first view of the new page sets the message centre's
 * first-display mark.
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 2 and "Order of work › Round two": "a Playwright flow that opens the
 * dialog, picks the message centre with the contact form for guests, sees `[omnabase_messages guest="form"]` change as it clicks, copies it,
 * creates the page and finds it picked and published … with the tag; a subject `x"] [other` yields a clean tag"; review S5 (`project` left
 * out for a key with one project, so the expected tag is `[omnabase_messages guest="form"]`), N7 (Escape closes, focus returns to the
 * button); the founder's answer to question 1: published at once. Why a browser flow: only a browser runs the dialog, the live tag, the
 * clipboard and the focus.
 *
 * The words: *dialog*, a small window over the page that must be closed first; *shortcode*, a tag such as `[omnabase_form]` that WordPress
 * replaces with the plugin's output; *clipboard*, where Copy puts text for pasting; *focus*, the element the keyboard acts on; *first-display
 * mark*, the time the plugin notes when a tag first shows on the public site.
 *
 * Choices this file makes (the builder may change them and tell the red team): the button is found by its name "Build a shortcode"; the dialog
 * by its role; the cards and guest choices by their words (clicked as text, so a card that hides its radio still works); the tag is the
 * dialog's one read-only field; the Project choice is a select with the accessible name "Project"; the flash's link to the new page is a link
 * whose address holds `action=edit`; the mark is read from `omnabase_display['first_shown']['messages']`. The site connects through the real
 * Connect page (form.spec.ts's way), so the key has Enfold alone, as every real site's key has one project; the multi-project case adds a
 * second project to the kept connection record (the way only a hand-made key could look) and puts it back.
 *
 * Shared-seed rules this file keeps: published pages that already carry an OmnaBase tag are set to draft for the run and published again at
 * the end; the pages it makes go to the bin; the message centre's pick and the front-end switches' setting (with its first-display marks) are
 * put back as they were; the key is stopped at OmnaBase
 * and the vault emptied.
 *
 * Why this fails today: there is no "Build a shortcode" button, no dialog and no "Create the page for me".
 */
import type { Browser, Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, SITE, SITE_HOST, WP_LOCAL, codeFor, lastMail, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const PAGES_TAB = `${SETTINGS}&tab=pages`;
const FOUNDER = "kriesi@example.test";
const FORM_CARD = "Contact form: visitors' messages become tickets";
const MESSAGES_CARD = "Message centre: signed-in customers read and answer their tickets";

test.describe.configure({ mode: "serial" });

let key = "";
let portalBefore = "";
let displayBefore = "";
let parked: string[] = [];
const made: string[] = [];

const php = (code: string): string => wp("eval", code).trim();
const toSite = (url: string) => url.replace(/^https?:\/\/[^/]+/, SITE);


/** Connect from the settings screen (form.spec.ts's way): the founder approves Team Kriesi › Enfold, so the key has one project */
async function connect(browser: Browser): Promise<void> {
  const context = await browser.newContext({ storageState: ADMIN_STATE });
  await routeSite(context);
  const page = await context.newPage();
  await page.goto(`${SITE}${SETTINGS}`);
  await page.getByRole("button", { name: "Connect", exact: true }).click();
  await page.waitForURL((u) => u.toString().startsWith(`${OMNABASE_APP}/connect`));
  await page.getByRole("textbox", { name: "Email" }).fill(FOUNDER);
  const before = await lastMail(FOUNDER);
  await page.getByRole("button", { name: "Send me a code" }).click();
  await page.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER, before));
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Workspace").selectOption({ label: "Team Kriesi" });
  await page.getByLabel("Project").selectOption({ label: "Enfold" });
  await page.getByRole("button", { name: /^Approve/ }).click();
  await page.waitForURL((u) => u.hostname === SITE_HOST);
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "connected");
  await context.close();
}

/** the Forms and messages tab with the builder open */
async function openBuilder(page: Page) {
  await page.goto(PAGES_TAB);
  const button = page.locator(".ob-wp-settings__shortcodes").getByRole("button", { name: "Build a shortcode", exact: true });
  await button.click();
  const dialog = page.getByRole("dialog");
  await expect(dialog).toBeVisible();
  return { button, dialog, tag: dialog.locator("input[readonly], textarea[readonly]") };
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();");
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  expect(key, "the connect stored a key").toMatch(/^obw_/);
  const me = await publicApi<{ projects: { id: string; slug: string; name: string }[] }>(key, "GET", "/me");
  expect(me.body.projects, "the key has Enfold alone").toHaveLength(1);
  portalBefore = php("echo wp_json_encode( get_option( 'omnabase_portal', null ) );");
  displayBefore = php("echo wp_json_encode( get_option( 'omnabase_display', null ) );");
  php("$d = get_option( 'omnabase_display', null ); if ( is_array( $d ) && isset( $d['first_shown'] ) ) { unset( $d['first_shown'] ); update_option( 'omnabase_display', $d, false ); }");
  // published pages that already carry a tag would be offered instead of a new page: set aside for the run
  const ids = php("global $wpdb; echo implode( ',', $wpdb->get_col( \"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( post_content LIKE '%[omnabase_messages%' OR post_content LIKE '%[omnabase_form%' )\" ) );");
  parked = ids ? ids.split(",") : [];
  for (const id of parked) php(`wp_update_post( array( 'ID' => ${Number(id)}, 'post_status' => 'draft' ) );`);
});

test.afterAll(async () => {
  try {
    for (const id of made) php(`wp_trash_post( ${Number(id)} );`);
    for (const id of parked) php(`wp_update_post( array( 'ID' => ${Number(id)}, 'post_status' => 'publish' ) );`);
    php(`$p = json_decode( ${JSON.stringify(portalBefore || "null")}, true ); if ( null === $p ) { delete_option( 'omnabase_portal' ); } else { update_option( 'omnabase_portal', $p ); }`);
    php(`$d = json_decode( ${JSON.stringify(displayBefore || "null")}, true ); if ( null === $d ) { delete_option( 'omnabase_display' ); } else { update_option( 'omnabase_display', $d, false ); }`);
  } catch { /* the plugin may be half built */ }
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
});

/**
 * What it checks: "Build a shortcode" opens the dialog, Escape closes it, and the keyboard is back on the button.
 * Passes when: clicking the button on the Forms and messages tab shows the dialog; pressing Escape hides it and the button has the focus.
 * Fails when: the builder cannot be opened, cannot be left with the keyboard, or leaves a keyboard user lost at the top of the page.
 */
test("Build a shortcode opens the dialog and Escape closes it", async ({ page }) => {
  const { button, dialog } = await openBuilder(page);
  await page.keyboard.press("Escape");
  await expect(dialog).toBeHidden();
  await expect(button).toBeFocused();
});

/**
 * What it checks: The owner builds the message centre with the contact form for guests, copies the tag, and has the page made, published
 *   and picked.
 * Passes when: after choosing "Message centre" the tag reads `[omnabase_messages]` and the dialog says it works only on the picked message
 *   centre page; after "see the contact form instead" it reads `[omnabase_messages guest="form"]`; no project choice shows (one project);
 *   Copy puts exactly that tag on the clipboard; "Create the page for me" returns to the tab with a notice linking to the new page's editor;
 *   the page is published, titled "Support", carries the tag, and the page picker now shows it.
 * Fails when: the tag does not follow the clicks, Copy copies nothing or something else, the page is a draft or carries another tag, or the
 *   owner must still pick the new page by hand before the message centre works.
 */
test("the message centre with the form for guests: the tag follows, Copy, Create the page", async ({ page, context }) => {
  await context.grantPermissions(["clipboard-read", "clipboard-write"], { origin: SITE });
  const { dialog, tag } = await openBuilder(page);
  await dialog.getByText(MESSAGES_CARD).click();
  await expect(tag).toHaveValue("[omnabase_messages]");
  await expect(dialog.getByText("It works only on the page picked as the message centre page", { exact: false })).toBeVisible();
  await dialog.getByText("see the contact form instead", { exact: false }).click();
  await expect(tag).toHaveValue('[omnabase_messages guest="form"]');
  await expect(dialog.getByRole("combobox", { name: "Project" })).toBeHidden();

  await dialog.getByRole("button", { name: "Copy", exact: true }).click();
  await expect.poll(() => page.evaluate(() => navigator.clipboard.readText())).toBe('[omnabase_messages guest="form"]');

  await dialog.getByRole("button", { name: "Create the page for me" }).click();
  await page.waitForURL((u) => u.pathname === "/wp-admin/options-general.php" && u.searchParams.get("tab") === "pages");
  const notice = page.locator(".ob-wp-settings__notice");
  await expect(notice).toContainText(/published/i);
  const edit = notice.locator('a[href*="action=edit"]');
  await expect(edit).toHaveCount(1);
  const id = /[?&]post=(\d+)/.exec((await edit.getAttribute("href")) ?? "")?.[1] ?? "";
  expect(id, "the notice links to the new page").toMatch(/^\d+$/);
  made.push(id);

  expect(php(`echo get_post_status( ${id} );`)).toBe("publish");
  expect(php(`echo get_the_title( ${id} );`)).toBe("Support");
  expect(php(`echo get_post_field( 'post_content', ${id} );`)).toContain('[omnabase_messages guest="form"]');
  expect(php("echo (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 );"), "picked as the message centre page").toBe(id);
  await expect(page.locator('.ob-wp-settings__portal select[name="omnabase_portal[page]"]')).toHaveValue(id);
});

/**
 * What it checks: A visitor's first view of the new message centre page notes that the message centre is live.
 * Passes when: after a visitor (nobody signed in) opens the page made above and sees the message centre's guest view in place of the tag
 *   (the project's form, or the sign-in link while the project has no default form), the stored first-display mark for the message centre
 *   holds a time.
 * Fails when: the setup line and OmnaBase's site report never learn that the message centre is live on the site.
 */
test("a visitor's first view of the new page sets the message centre's first-display mark", async ({ browser }) => {
  expect(made[0], "the page from the flow above").toBeTruthy();
  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await routeSite(visitor);
  try {
    const page = await visitor.newPage();
    await page.goto(toSite(php(`echo get_permalink( ${Number(made[0])} );`)));
    await expect(page.getByText("[omnabase_messages")).toHaveCount(0);
    // adjusted 2026-10-25 (the plugin's forms round), not weakened: the guest form is now the project's form built in OmnaBase, and the seed's
    // Enfold has no default form, so the visitor gets the sign-in link instead (DECISIONS); the plugin's output still takes the tag's place
    await expect(page.locator(".ob-wp"), "the message centre's guest view in place of the tag").toHaveCount(1);
  } finally {
    await visitor.close();
  }
  expect(Number(php("$d = get_option( 'omnabase_display', array() ); echo (int) ( $d['first_shown']['messages'] ?? 0 );")), "the message centre's mark").toBeGreaterThan(0);
});

/**
 * What it checks: The subject is cleaned as the owner types it, so the tag can never break.
 * Passes when: after choosing "Contact form" and typing `x"] [other` into "Subject starts with", the field reads `x other` and the tag reads
 *   `[omnabase_form subject="x other"]`.
 * Fails when: a quote or bracket in the subject closes the tag early, so the pasted shortcode breaks the page or runs another shortcode.
 */
test("the subject is cleaned as it is typed", async ({ page }) => {
  const { dialog, tag } = await openBuilder(page);
  await dialog.getByText(FORM_CARD).click();
  await expect(tag).toHaveValue("[omnabase_form]");
  const subject = dialog.getByLabel("Subject starts with");
  await subject.pressSequentially('x"] [other');
  await expect(subject).toHaveValue("x other");
  await expect(tag).toHaveValue('[omnabase_form subject="x other"]');
});

/**
 * What it checks: Only a key with several projects asks which project, and the tag then names it.
 * Passes when: with a second project added to the kept connection record, the dialog for the contact form shows a "Project" choice, and
 *   choosing the second project puts `project="<its slug>"` into the tag.
 * Fails when: a site with several projects gets a tag without a project (the form shows only an administrator's note), or the choice is
 *   missing.
 */
test("a key with several projects gets a project choice", async ({ page }) => {
  const before = php("echo wp_json_encode( get_option( 'omnabase_site' ) );");
  php("$s = get_option( 'omnabase_site' ); $s['projects'][] = array( 'id' => 'project-e2e-second', 'slug' => 'second-e2e', 'name' => 'Second project' ); update_option( 'omnabase_site', $s, false );");
  try {
    const { dialog, tag } = await openBuilder(page);
    await dialog.getByText(FORM_CARD).click();
    const project = dialog.getByRole("combobox", { name: "Project" });
    await expect(project).toBeVisible();
    await project.selectOption("second-e2e");
    await expect(tag).toHaveValue(/project="second-e2e"/);
    await expect(tag).toHaveValue(/^\[omnabase_form\b[^\]]*\]$/);
  } finally {
    php(`update_option( 'omnabase_site', json_decode( ${JSON.stringify(before)}, true ), false );`);
  }
});
