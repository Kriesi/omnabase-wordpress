/**
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the page picker moves to the "Forms and messages" tab, which has
 * one save form for the picker and the Shortcodes block, ending in one primary "Save" (brief `docs/briefs/m14-settings-cleanup.md`, choice 1:
 * "one save action `omnabase_save_pages`"; review S1, S3). The picker test opens `&tab=pages`, clicks the tab's "Save" button by its words, and
 * also expects to come back to the pages tab. The picker's names, the check after saving and every flow on the front are unchanged.
 *
 * M13 round five, the red team's failing flow: the message centre from a signed-in customer's browser to OmnaBase and back. The site connects
 * for real (as form.spec.ts does), the administrator picks the message centre's page on the settings screen, and a customer of this run (Mia,
 * made for the run so no earlier run's confirmation carries over) has an earlier mail conversation with Team Kriesi and a forum topic filed
 * through the plugin. Mia opens the message centre: the page is never cached, the prompt shows, her new ticket appears at once (and in
 * OmnaBase's inbox), the mail conversation and the forum topic only after she opens the link OmnaBase mailed her. Her thread shows the team's
 * answer and never its note; her reply reopens the resolved ticket. Ben, another customer, sees nothing of hers, not even by number. When Mia
 * changes her email in WordPress the prompt returns and the next link goes to the new address. A visitor who is not signed in sees a login
 * link back. A page built without the shortcode in its content is still kept out of caches once picked.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) round five: "*a logged-in customer sees their tickets, opens one, answers,
 * files a new one, and after clicking the mailed link also sees their older email conversations and forum topics; a changed email asks
 * again*"; its *Red team*: "visitor A cannot open B's ticket by number; a `?customer=` or header changes nothing; no note or system line in any
 * thread; … the prompt until confirmed, then (mail capture, the link) the old mail conversation and the forum topic listed; … an e-mail change
 * sends the new address and the prompt returns; the picked page carries `no-store` … also when built with a page builder; logged out sees the
 * login link"; choice 9 (`Cache-Control: private, no-store`). The coordinator: "`messages.spec.ts`: a logged-in seeded user with a forum ticket
 * and a form ticket → the list shows the form ticket at once and the forum topic only after confirmation; the prompt → the mailed link → the
 * forum ticket and an earlier mail conversation appear; New ticket → in OmnaBase's inbox; Reply → the ticket reopens; a logged-out visitor sees
 * the login link; the team's note never shows".
 *
 * Choices this flow makes (the builder may change them and tell the red team):
 * - The settings screen's section `.ob-wp-settings__portal` with the select `omnabase_portal[page]` and one submit button; the option
 *   `omnabase_portal` (`RoundFiveTestCase`). The forum settings are written as round three's option (`omnabase_forum`), not through the screen.
 * - "The form ticket … at once" is read as the ticket Mia files through the message centre's New ticket form: a shortcode-form ticket is a
 *   `form` ticket, which OmnaBase shows only after confirmation (M12 round four's rule; see the report).
 * - The prompt's button "Show my email conversations" ("Send me the link" read too); the sentence after it "We sent a link to"; the New ticket
 *   form is the form holding an input `subject`; the reply form the one holding a text box and no subject.
 * - The confirmation link is taken from OmnaBase's mail capture (`/api/test/mail?to=`) and opened on the e2e API's own origin; its page's one
 *   button confirms (M12 round four's page).
 * - The team's answer is a founder's reply through OmnaBase's workspace API with the `mail_out` flag on for the test (and off after), sent by
 *   the sender's pass (`/api/test/tick`); the note through the same API.
 * - The email change is made with WP-CLI (`wp user update`, which runs WordPress's `profile_update`), then the outbox is worked.
 *
 * Shared-seed rules this file keeps: it deletes its two users, trashes its pages and its topic, forgets the forum and portal options, puts the
 * forum throttle back, switches `mail_out` off, disconnects the site and resets the relay.
 *
 * Why this fails today: the settings screen has no message-centre section, and `[omnabase_messages]` shows as typed.
 */
import type { Browser, BrowserContext, Page, Response } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, SITE, SITE_HOST, WP_LOCAL, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
const W = `${OMNABASE_E2E}/api/v1/w/kriesi`;
const PASS = { spam: "PASS", virus: "PASS", spf: "PASS", dkim: "PASS", dmarc: "PASS" };
const run = Date.now().toString(36);
const MIA = { login: `mia${run}`, email: `mia.${run}@example.com`, next: `mia.new.${run}@example.com`, name: "Mia Centre" };
const BEN = { login: `ben${run}`, email: `ben.${run}@example.com`, name: "Ben Other" };
const SUBJECTS = { mail: `Earlier mail ${run}`, topic: `Forum topic ${run}`, mine: `From the message centre ${run}` };
const NOTE = `TEAM-NOTE-${run}`, ANSWER = `Team answer ${run}: please update to 7.2.`;
type Ticket = { number: number; subject: string; status: string; channel: string; customer: { email: string } | null; messages?: { kind: string; bodyText: string }[] };

test.describe.configure({ mode: "serial" });

let key = "";
let enfold = { id: "", slug: "", mailLocal: "" };
let pageId = "", builtId = "", portalUrl = "", builtUrl = "", topicId = "", throttle = "";
let miaId = "", benId = "";
let mailTicket = 0, topicTicket = 0, mineTicket = 0;
let founderContext: BrowserContext; let founderPage: Page;
let miaContext: BrowserContext; let mia: Page;
const contexts: BrowserContext[] = [];

const php = (code: string): string => wp("eval", code).trim();
const AS_ADMIN = "wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) ); ";

async function lastMail(email: string): Promise<string> {
  const res = await fetch(`${OMNABASE_E2E}/api/test/mail?to=${encodeURIComponent(email)}`);
  return res.ok ? (((await res.json()) as { text?: string } | null)?.text ?? "") : "";
}
async function codeFor(email: string, before: string): Promise<string> {
  for (let i = 0; i < 100; i++) {
    const text = await lastMail(email);
    const hit = text !== before ? /(\d{3}) (\d{3})/.exec(text) : null;
    if (hit) return hit[1]! + hit[2]!;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`no new code in the mail to ${email}`);
}
/** the confirmation link of a mail to `email` newer than `before`, or "" after a few seconds */
async function confirmLink(email: string, before: string): Promise<string> {
  for (let i = 0; i < 50; i++) {
    const text = await lastMail(email);
    const hit = text !== before ? /https?:\/\/[^\s"'<>]*\/api\/v1\/portal\/confirm\?t=[^\s"'<>]+/.exec(text) : null;
    if (hit) return hit[0];
    await new Promise((r) => setTimeout(r, 100));
  }
  return "";
}

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
async function founderSignsIn(browser: Browser): Promise<void> {
  founderContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  founderPage = await founderContext.newPage();
  await founderPage.goto(`${OMNABASE_APP}/signin?workspace=kriesi`);
  await founderPage.getByRole("textbox", { name: "Email" }).fill(FOUNDER);
  const before = await lastMail(FOUNDER);
  await founderPage.getByRole("button", { name: "Continue", exact: true }).click();
  await founderPage.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER, before));
  await founderPage.getByRole("button", { name: "Sign in" }).click();
  await expect(founderPage).toHaveURL(/\/kriesi(\/|$)/);
}
/** a WordPress user of this run signed in to the site */
async function signsIn(browser: Browser, login: string): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await routeSite(context);
  contexts.push(context);
  const page = await context.newPage();
  await page.goto(`${SITE}/wp-login.php`);
  await page.locator("#user_login").fill(login);
  await page.locator("#user_pass").fill("test-password");
  await page.locator("#wp-submit").click();
  await page.waitForURL((u) => !u.pathname.startsWith("/wp-login.php"));
  return { context, page };
}
function workOutbox(): void {
  php(`global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}omnabase_outbox SET next_at = " . ( time() - 1 ) . " WHERE state = 'waiting'" );`);
  wp("cron", "event", "run", "omnabase_outbox");
}
const toSite = (url: string) => url.replace(/^https?:\/\/[^/]+/, SITE);
const newUser = (u: { login: string; email: string; name: string }) => php(`echo wp_insert_user( array( 'user_login' => '${u.login}', 'user_email' => '${u.email}', 'display_name' => '${u.name}', 'first_name' => '${u.name.split(" ")[0]}', 'user_pass' => 'test-password', 'role' => 'subscriber' ) );`);
/** the message centre as a page sees it, with the answer's headers */
async function openCentre(page: Page, query = ""): Promise<Response> {
  const res = await page.goto(`${portalUrl}${query}`);
  expect(res, "the message centre answers").not.toBeNull();
  await expect(page.getByText("[omnabase_messages]"), "the shortcode is replaced").toHaveCount(0);
  return res!;
}
function expectPrivate(res: Response, who: string): void {
  const cc = (res.headers()["cache-control"] ?? "").toLowerCase();
  expect(cc, `${who}: Cache-Control no-store`).toContain("no-store");
  expect(cc, `${who}: Cache-Control private`).toContain("private");
}
const prompt = (page: Page) => page.getByRole("button", { name: /show my email conversations|send me the link/i });
const newTicketForm = (page: Page) => page.locator("form").filter({ has: page.locator('input[name="subject"]') }).filter({ has: page.locator("textarea") });
const replyForm = (page: Page) => page.locator("form").filter({ has: page.locator("textarea") }).filter({ hasNot: page.locator('input[name="subject"]') });
async function ticketsWithSubject(subject: string): Promise<Ticket[]> {
  const list = await publicApi<{ data: Ticket[] }>(key, "GET", "/tickets?view=all-open&limit=100");
  return list.body.data.filter((t) => t.subject === subject);
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(300_000);
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear(); delete_option( 'omnabase_forum' ); delete_option( 'omnabase_portal' );");
  throttle = php("echo get_option( '_bbp_throttle_time', '' );");
  php("update_option( '_bbp_throttle_time', 0 );");
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  expect(key, "the connect stored a key").toMatch(/^obw_/);
  await founderSignsIn(browser);
  const ws = (await (await founderContext.request.get(W)).json()) as { projects: { id: string; slug: string; name: string; mailLocal?: string }[] };
  const p = ws.projects.find((x) => x.name === "Enfold")!;
  enfold = { id: p.id, slug: p.slug, mailLocal: p.mailLocal ?? "" };
  expect(enfold.mailLocal, "Enfold's mail address").not.toBe("");

  miaId = newUser(MIA); benId = newUser(BEN);
  expect(miaId, "Mia made").toMatch(/^\d+$/);
  expect(benId, "Ben made").toMatch(/^\d+$/);
  const support = php(`${AS_ADMIN}echo get_posts( array( 'post_type' => 'forum', 'title' => 'Support', 'post_status' => array( 'publish', 'private', 'hidden' ), 'fields' => 'ids', 'numberposts' => 1 ) )[0] ?? '';`);
  const supportUser = php("echo get_user_by( 'login', 'support' )->ID ?? '';");
  php(`update_option( 'omnabase_forum', array( 'forums' => array( ${support} ), 'support_user' => ${supportUser} ) );`);
  pageId = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Messages ${run}', 'post_content' => '[omnabase_messages]' ) );`);
  builtId = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Built ${run}', 'post_content' => '<div class="elementor" data-elementor-type="wp-page"></div>' ) );`);
  portalUrl = toSite(php(`echo get_permalink( ${pageId} );`));
  builtUrl = toSite(php(`echo get_permalink( ${builtId} );`));

  const to = `${enfold.mailLocal}@kriesi.omnabase.com`;
  const raw = [`From: Mia Centre <${MIA.email}>`, `To: ${to}`, `Subject: ${SUBJECTS.mail}`, `Date: ${new Date().toUTCString()}`, `Message-ID: <mc-${run}@example.com>`, "MIME-Version: 1.0", "Content-Type: text/plain; charset=UTF-8", "", "My question from last month, by mail.", ""].join("\r\n");
  expect((await founderContext.request.post(`${OMNABASE_E2E}/api/test/inbound`, { data: { raw, recipients: [to], verdicts: PASS } })).ok(), "the earlier mail arrives").toBe(true);
  mailTicket = (await ticketsWithSubject(SUBJECTS.mail))[0]?.number ?? 0;
  expect(mailTicket, "the earlier mail is a ticket").toBeGreaterThan(0);

  ({ context: miaContext, page: mia } = await signsIn(browser, MIA.login));
  await mia.goto(`${SITE}/forums/forum/support/`);
  await mia.locator("#bbp_topic_title").fill(SUBJECTS.topic);
  await mia.locator("#bbp_topic_content").fill(`The switcher is gone (${run}).`);
  await mia.locator("#bbp_topic_submit").click();
  await mia.waitForURL((u) => u.pathname.startsWith("/forums/topic/"));
  topicId = php(`${AS_ADMIN}echo get_posts( array( 'post_type' => 'topic', 'title' => ${JSON.stringify(SUBJECTS.topic)}, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1 ) )[0] ?? '';`);
  workOutbox();
  topicTicket = Number(php(`echo (int) get_post_meta( ${topicId || 0}, '_omnabase_ticket', true );`));
  expect(topicTicket, "Mia's topic is a ticket").toBeGreaterThan(0);
});

test.afterAll(async () => {
  try {
    php(`require_once ABSPATH . 'wp-admin/includes/user.php'; foreach ( array( ${miaId || 0}, ${benId || 0} ) as $u ) { if ( $u ) { wp_delete_user( $u ); } } foreach ( array( ${pageId || 0}, ${builtId || 0}, ${topicId || 0} ) as $p ) { if ( $p ) { wp_trash_post( $p ); } } delete_option( 'omnabase_forum' ); delete_option( 'omnabase_portal' ); update_option( '_bbp_throttle_time', ${JSON.stringify(throttle || "10")} );`);
  } catch { /* the plugin may be half built */ }
  try { await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: false } }); } catch { /* idem */ }
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  for (const c of contexts) await c.close();
  await founderContext?.close();
});

/**
 * What it checks: The administrator picks the message centre's page on the settings screen.
 * Passes when: the screen's message-centre section offers the run's page in its list; choosing it and saving brings the screen back with that
 *   page selected.
 * Fails when: the owner cannot say where the message centre lives, so no page is protected from caching.
 */
test("the administrator picks the message centre's page", async ({ page }) => {
  await page.goto(`${SETTINGS}&tab=pages`);
  const section = page.locator(".ob-wp-settings__portal");
  await expect(section).toBeVisible();
  await section.locator('select[name="omnabase_portal[page]"]').selectOption(pageId);
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await page.waitForURL((u) => u.pathname === "/wp-admin/options-general.php" && u.searchParams.get("tab") === "pages");
  await expect(page.locator('.ob-wp-settings__portal select[name="omnabase_portal[page]"]')).toHaveValue(pageId);
});

/**
 * What it checks: A visitor who is not signed in sees a login link that brings them back, and the page is still never cached.
 * Passes when: the page shows a link to wp-login.php whose return address is the message centre, none of Mia's subjects, and answers with
 *   Cache-Control holding no-store and private.
 * Fails when: logged-out visitors see an empty box or the raw tag, land elsewhere after signing in, or a cache keeps the page.
 */
test("a logged-out visitor sees the login link back", async ({ browser }) => {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await routeSite(context);
  contexts.push(context);
  const page = await context.newPage();
  const res = await openCentre(page);
  expectPrivate(res, "a visitor");
  const login = page.locator('a[href*="wp-login.php"]').first();
  await expect(login).toBeVisible();
  const back = new URL((await login.getAttribute("href")) ?? "", SITE).searchParams.get("redirect_to") ?? "";
  expect(new URL(back, SITE).pathname).toBe(new URL(portalUrl).pathname);
  for (const s of Object.values(SUBJECTS)) await expect(page.getByText(s)).toHaveCount(0);
});

/**
 * What it checks: The round's story: Mia's new ticket appears at once and reaches OmnaBase's inbox; her earlier mail conversation and her forum
 *   topic appear only after she opens the link OmnaBase mailed her.
 * Passes when: Mia's message centre answers private and no-store, shows the prompt and neither the mail conversation nor the topic; her New
 *   ticket shows on the page and is an api ticket of hers in OmnaBase (the founder's ticket page shows it); pressing the prompt's button says
 *   "We sent a link to"; the link in her mail confirms; her next view has no prompt and lists the mail conversation and the forum topic.
 * Fails when: the message centre shows history on the site's word alone, never shows it, loses message-centre questions, or the link does not
 *   lead anywhere in a real browser.
 */
test("Mia's new ticket at once, her mail conversation and forum topic after the link", async () => {
  const res = await openCentre(mia);
  expectPrivate(res, "Mia");
  await expect(prompt(mia)).toBeVisible();
  await expect(mia.getByText(SUBJECTS.mail)).toHaveCount(0);
  await expect(mia.getByText(SUBJECTS.topic)).toHaveCount(0);

  const form = newTicketForm(mia);
  await form.locator('input[name="subject"]').fill(SUBJECTS.mine);
  await form.locator("textarea").fill(`My licence key is refused (${run}).`);
  await form.locator('[type="submit"], button:not([type])').first().click();
  await expect(mia.getByText(SUBJECTS.mine).first()).toBeVisible();
  const filed = await ticketsWithSubject(SUBJECTS.mine);
  expect(filed, "one ticket in OmnaBase").toHaveLength(1);
  mineTicket = filed[0]!.number;
  expect(filed[0]).toMatchObject({ channel: "api", customer: { email: MIA.email } });
  await founderPage.goto(`${OMNABASE_APP}/kriesi/inbox/all-open/${mineTicket}`);
  await expect(founderPage.locator(".ob-detail")).toContainText(SUBJECTS.mine);

  await openCentre(mia);
  const before = await lastMail(MIA.email);
  await prompt(mia).click();
  await expect(mia.getByText(/We sent a link to/i)).toBeVisible();
  const link = await confirmLink(MIA.email, before);
  expect(link, "the confirmation mail holds the link").not.toBe("");
  const confirmPage = await founderContext.newPage();
  const u = new URL(link);
  await confirmPage.goto(`${OMNABASE_E2E}${u.pathname}${u.search}`);
  const button = confirmPage.getByRole("button");
  if (await button.count()) await button.first().click();
  await expect(confirmPage.locator("body")).toContainText(/confirmed|thank/i);
  await confirmPage.close();

  await openCentre(mia);
  await expect(prompt(mia)).toHaveCount(0);
  await expect(mia.getByText(SUBJECTS.mail).first()).toBeVisible();
  await expect(mia.getByText(SUBJECTS.topic).first()).toBeVisible();
  await expect(mia.getByText(SUBJECTS.mine).first()).toBeVisible();
});

/**
 * What it checks: A thread in the message centre shows the team's answer and never the team's internal note.
 * Passes when: the founder adds a note and sends a reply on the mail conversation; after OmnaBase's sender pass Mia's thread holds the answer
 *   and not the note's words.
 * Fails when: customers read the team's private notes on the site, or never see the answer they were sent.
 */
test("the thread shows the team's answer and never its note", async () => {
  expect((await founderContext.request.post(`${W}/tickets/${mailTicket}/messages`, { data: { kind: "note", body: NOTE } })).ok(), "the note").toBe(true);
  await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: true } });
  try {
    expect((await founderContext.request.post(`${W}/tickets/${mailTicket}/messages`, { data: { kind: "reply", body: ANSWER } })).ok(), "the reply").toBe(true);
    await omnabaseHook("/api/test/tick", { job: "sender", now: new Date(Date.now() + 15_000).toISOString() });
  } finally {
    await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: false } });
  }
  await openCentre(mia, `?omnabase_ticket=${mailTicket}`);
  await expect(mia.getByText(ANSWER).first()).toBeVisible();
  await expect(mia.getByText(NOTE)).toHaveCount(0);
  expect(await mia.content(), "the note is nowhere in the page").not.toContain(NOTE);
});

/**
 * What it checks: Mia's reply in the message centre brings a resolved ticket back to the team.
 * Passes when: the founder resolves the mail conversation; Mia answers in its thread; the thread shows her words and OmnaBase reads the ticket
 *   as open with her message last.
 * Fails when: answers from the site vanish, or land on a resolved ticket nobody looks at.
 */
test("Mia's reply reopens the ticket", async () => {
  expect((await founderContext.request.patch(`${W}/tickets/${mailTicket}`, { data: { status: "resolved" } })).ok(), "resolved").toBe(true);
  await openCentre(mia, `?omnabase_ticket=${mailTicket}`);
  const words = `Still broken on 7.2 (${run}).`;
  await replyForm(mia).locator("textarea").fill(words);
  await replyForm(mia).locator('[type="submit"], button:not([type])').first().click();
  await expect(mia.getByText(words).first()).toBeVisible();
  const read = await publicApi<Ticket>(key, "GET", `/tickets/${mailTicket}`);
  expect(read.body.status, "open again").toBe("open");
  expect(read.body.messages?.at(-1)?.bodyText ?? "").toContain(words);
});

/**
 * What it checks: Another customer sees nothing of Mia's, not by number and not by naming her in the address, and his page is private too.
 * Passes when: Ben's message centre answers private and no-store and shows none of Mia's subjects; with Mia's ticket number it says "not
 *   found"; with `?customer=` and `?omnabase_customer=` set to Mia's site id it still shows none of hers.
 * Fails when: one customer reads another's support history by changing the address bar, or a cache hands Mia's list to Ben.
 */
test("Ben sees none of Mia's tickets, not even by number", async ({ browser }) => {
  const { page: ben } = await signsIn(browser, BEN.login);
  const res = await openCentre(ben);
  expectPrivate(res, "Ben");
  for (const s of Object.values(SUBJECTS)) await expect(ben.getByText(s)).toHaveCount(0);
  await openCentre(ben, `?omnabase_ticket=${mineTicket}`);
  await expect(ben.getByText(/not found/i).first()).toBeVisible();
  await expect(ben.getByText(SUBJECTS.mine)).toHaveCount(0);
  const miaSite = php(`echo get_user_meta( ${miaId}, 'omnabase_site_id', true );`);
  expect(miaSite, "Mia's site id").toMatch(new RegExp(`^${miaId}-[a-z0-9]{8}$`));
  await openCentre(ben, `?customer=${encodeURIComponent(miaSite)}&omnabase_customer=${encodeURIComponent(miaSite)}`);
  for (const s of Object.values(SUBJECTS)) await expect(ben.getByText(s)).toHaveCount(0);
});

/**
 * What it checks: When Mia changes her email in WordPress, the message centre asks her to confirm again and the next link goes to the new
 *   address (Q5).
 * Passes when: after the change and the outbox's run, Mia's view shows the prompt, keeps her message-centre ticket and hides the mail
 *   conversation and the forum topic; pressing the button mails a link to the new address and none to the old.
 * Fails when: a taken-over account keeps the previous owner's history after changing the address, or the link goes to the old mailbox.
 */
test("a changed email asks again, and the link goes to the new address", async () => {
  wp("user", "update", MIA.login, `--user_email=${MIA.next}`, "--skip-email");
  workOutbox();
  await openCentre(mia);
  await expect(prompt(mia)).toBeVisible();
  await expect(mia.getByText(SUBJECTS.mine).first()).toBeVisible();
  await expect(mia.getByText(SUBJECTS.mail)).toHaveCount(0);
  await expect(mia.getByText(SUBJECTS.topic)).toHaveCount(0);
  const old = await lastMail(MIA.email), fresh = await lastMail(MIA.next);
  await prompt(mia).click();
  await expect(mia.getByText(/We sent a link to/i)).toBeVisible();
  expect(await confirmLink(MIA.next, fresh), "the link goes to the new address").not.toBe("");
  expect(await lastMail(MIA.email), "nothing new to the old address").toBe(old);
});

/**
 * What it checks: A picked page whose stored content does not hold the shortcode (a page builder's) is still never cached.
 * Passes when: with the page-builder page picked, its answer for a visitor carries Cache-Control no-store and private.
 * Fails when: on page-builder sites the message centre's page is cached and one customer's list is served to the next.
 */
test("a page built without the shortcode in its content is still never cached", async ({ browser }) => {
  php(`update_option( 'omnabase_portal', array( 'page' => ${builtId} ) );`);
  try {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    await routeSite(context);
    contexts.push(context);
    const res = await (await context.newPage()).goto(builtUrl);
    expect(res).not.toBeNull();
    expectPrivate(res!, "the page-builder page");
  } finally {
    php(`update_option( 'omnabase_portal', array( 'page' => ${pageId} ) );`);
  }
});
