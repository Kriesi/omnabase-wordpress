/**
 * M13 round four, the red team's failing flow: the shortcode form from a visitor's browser to OmnaBase's inbox. The site connects for real (as
 * forum.spec.ts does), the founder leaves Enfold's hosted web form switched off but gives the project two products, a licence field and a
 * privacy address, and the flow makes a page holding `[omnabase_form]`. A visitor opens it: the form fetches its time token, shows the
 * project's products and privacy link, and a sent message becomes a form ticket in the inbox with the visitor as the customer. A filled trap
 * field files nothing; a token sent a second time for other words is refused; two visitors on the same page get two tickets; with OmnaBase
 * unreachable the visitor is told the message is on its way, and the outbox delivers it once OmnaBase is back.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) round four: "*a visitor sends the shortcode form, with or without OmnaBase's
 * hosted form switched on, and gets a reference; bots and double clicks do not make tickets*"; choice 8 (the fields, the token fetched on
 * load, the honeypot, "only a network failure falls back to the outbox with 'Thanks, your message is on its way'", "Thanks. Your reference is
 * #1234; we answer by email."). The coordinator: "`form.spec.ts`: a page with the shortcode (the harness makes it) → a visitor sends → the
 * ticket appears in OmnaBase's inbox with the form's channel and the visitor as the customer; the honeypot filled → nothing filed; the token
 * reused → refused; OmnaBase down → the outbox carries it and the inbox gets it later; the product select shows the project's products".
 *
 * Choices this flow makes (the builder may change them and tell the red team):
 * - The form's fields by name (`name`, `email`, `subject`, `message`, `product`, `licence`, the trap `omnabase_hp`), the routes
 *   `/wp-json/omnabase/v1/form-token` and `/wp-json/omnabase/v1/form` (`RoundFourTestCase`).
 * - Each visitor waits 3.5 seconds after the page loaded before sending (the brief's "older than 3 seconds").
 * - OmnaBase is made unreachable for WordPress by the harness's switch (`omnabase_test_offline`, the replaced must-use plugin); the outbox is
 *   worked with `wp cron event run omnabase_outbox` (forum.spec.ts's way).
 * - Every visitor reaches wp-env from the same Docker address, so the plugin's temporary values (`omnabase` transients: tokens, counters,
 *   the cached settings) are cleared before each test; otherwise the brief's 5 an hour per address would refuse this file's sixth message.
 * - The project's settings are changed through OmnaBase's own workspace API with the founder's session; the inbox is read through the public
 *   API with the site's key and the founder's ticket page.
 *
 * Shared-seed rules this file keeps: it restores Enfold's form settings, trashes its page, switches the harness back online, disconnects the
 * site and resets the relay.
 *
 * Why this fails today: no shortcode is registered, so the page shows "[omnabase_form]" as text and no token is fetched.
 */
import type { Browser, BrowserContext, Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, SITE, SITE_HOST, WP_LOCAL, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
const PRIVACY = "https://kriesi.example/privacy";
const run = Date.now().toString(36);
type Ticket = { number: number; subject: string; channel: string; projectId: string; customer: { email: string; name: string } };
type WebForm = { enabled: boolean; products: string[]; askLicense: boolean; privacyUrl: string | null };

test.describe.configure({ mode: "serial" });

let key = "";
let pageUrl = "";
let pageId = "";
let enfold = { id: "", slug: "", name: "" };
let formBefore: WebForm | null = null;
let founderContext: BrowserContext;
let founderPage: Page;
const visitors: BrowserContext[] = [];

const php = (code: string): string => wp("eval", code).trim();

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

/** Connect from the settings screen (forum.spec.ts's way): the founder approves Team Kriesi › Enfold, so the key has one project */
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

/** Enfold's form settings through OmnaBase's workspace API, with the founder's session */
async function setEnfoldForm(webForm: Partial<WebForm>): Promise<void> {
  const res = await founderContext.request.patch(`${OMNABASE_E2E}/api/v1/w/kriesi/projects/${enfold.id}`, { data: { webForm } });
  expect(res.ok(), `Enfold's form settings saved (${res.status()})`).toBe(true);
}

/** the plugin's temporary values (tokens, counters, the cached settings) go, so this file's visitors start afresh */
function resetFormState(): void {
  php(`global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%omnabase%' AND option_name NOT LIKE '%omnabase_notice_%'" ); wp_cache_flush();`);
}

/** a visitor (nobody signed in) on the form's page, once the form fetched its time token */
async function visitorOnTheForm(browser: Browser): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await routeSite(context);
  visitors.push(context);
  const page = await context.newPage();
  const token = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith("/wp-json/omnabase/v1/form-token"), { timeout: 15_000 });
  token.catch(() => undefined); // awaited below, after the clearer check
  await page.goto(pageUrl);
  await expect(page.getByText("[omnabase_form]"), "the shortcode is replaced by the form").toHaveCount(0);
  expect((await token).status(), "the form fetched its time token on load").toBe(200);
  return page;
}

const theForm = (page: Page) => page.locator("form").filter({ has: page.locator('textarea[name="message"]') });

/** fills the form as a person does and sends it after the brief's 3 seconds */
async function send(page: Page, who: { name: string; email: string; subject: string; message: string }): Promise<void> {
  const form = theForm(page);
  await form.locator('input[name="name"]').fill(who.name);
  await form.locator('input[name="email"]').fill(who.email);
  await form.locator('input[name="subject"]').fill(who.subject);
  await form.locator('textarea[name="message"]').fill(who.message);
  await page.waitForTimeout(3_500);
  await form.locator('[type="submit"]').first().click();
}

async function ticketsWithSubject(subject: string): Promise<Ticket[]> {
  const list = await publicApi<{ data: Ticket[] }>(key, "GET", "/tickets?view=all-open&limit=100");
  return list.body.data.filter((t) => t.subject === subject);
}

function workOutbox(): void {
  php(`global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}omnabase_outbox SET next_at = " . ( time() - 1 ) . " WHERE state = 'waiting'" );`);
  wp("cron", "event", "run", "omnabase_outbox");
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear(); delete_option( 'omnabase_test_offline' );");
  resetFormState();
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  expect(key, "the connect stored a key").toMatch(/^obw_/);
  const me = await publicApi<{ projects: { id: string; slug: string; name: string }[] }>(key, "GET", "/me");
  expect(me.body.projects, "the key has Enfold alone").toHaveLength(1);
  enfold = me.body.projects[0]!;
  await founderSignsIn(browser);
  const ws = (await (await founderContext.request.get(`${OMNABASE_E2E}/api/v1/w/kriesi`)).json()) as { projects: { id: string; webForm?: WebForm }[] };
  formBefore = ws.projects.find((p) => p.id === enfold.id)?.webForm ?? null;
  await setEnfoldForm({ enabled: false, products: ["Enfold", "Enfold Pro"], askLicense: true, privacyUrl: PRIVACY });
  pageId = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact ${run}', 'post_content' => '[omnabase_form]' ) );`);
  expect(pageId, "the page with the shortcode").toMatch(/^\d+$/);
  pageUrl = php(`echo get_permalink( ${pageId} );`).replace(/^https?:\/\/[^/]+/, SITE);
});

test.beforeEach(() => resetFormState());

test.afterAll(async () => {
  try { php(`delete_option( 'omnabase_test_offline' ); wp_trash_post( (int) ${JSON.stringify(pageId || "0")} );`); } catch { /* the plugin may be half built */ }
  try { resetFormState(); } catch { /* idem */ }
  if (formBefore && founderContext) await founderContext.request.patch(`${OMNABASE_E2E}/api/v1/w/kriesi/projects/${enfold.id}`, { data: { webForm: { enabled: formBefore.enabled, products: formBefore.products, askLicense: formBefore.askLicense, privacyUrl: formBefore.privacyUrl } } });
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  for (const c of visitors) await c.close();
  await founderContext?.close();
});

/**
 * What it checks: A visitor sees the shortcode form with the project's products, the licence field and the team's privacy link, though the
 *   project's hosted form is switched off.
 * Passes when: the page shows the form (no raw "[omnabase_form]"), the form fetched its time token, the product list offers "Enfold" and
 *   "Enfold Pro", a licence field shows, and the privacy link leads to the project's privacy address.
 * Fails when: a freshly connected site's form is empty or broken until the owner opens OmnaBase's public form, or the products and privacy
 *   link do not follow the project.
 */
test("a visitor sees the form with the project's products and privacy link", async ({ browser }) => {
  const page = await visitorOnTheForm(browser);
  await expect(page.getByText("[omnabase_form]")).toHaveCount(0);
  const form = theForm(page);
  await expect(form).toBeVisible();
  await expect(form.locator('select[name="product"] option')).toContainText(["Enfold", "Enfold Pro"]);
  await expect(form.locator('input[name="licence"]')).toBeVisible();
  await expect(page.locator(`a[href="${PRIVACY}"]`)).toBeVisible();
});

/**
 * What it checks: The round's point: a visitor's message becomes a form ticket in the founder's inbox, with the visitor as the customer.
 * Passes when: after sending, the page shows "Your reference is #<n>"; OmnaBase's ticket <n> is Enfold's, on the form channel, its customer
 *   the visitor's address and its subject the visitor's; the founder's ticket page shows the subject.
 * Fails when: messages from the site's form never reach the inbox, land in another project, or arrive under someone else's name.
 */
test("a visitor sends the form and the ticket appears in the inbox", async ({ browser }) => {
  const page = await visitorOnTheForm(browser);
  const subject = `Form flow ${run}`;
  await theForm(page).locator('select[name="product"]').selectOption("Enfold Pro");
  await send(page, { name: "Vera Visitor", email: `vera.${run}@example.org`, subject, message: `The slider stopped after the update (${run}).` });
  const thanks = page.getByText(/Your reference is #\d+/);
  await expect(thanks).toBeVisible();
  const n = Number(/#(\d+)/.exec((await thanks.textContent()) ?? "")![1]);

  const read = await publicApi<Ticket>(key, "GET", `/tickets/${n}`);
  expect(read.status, JSON.stringify(read.body)).toBe(200);
  expect(read.body).toMatchObject({ subject, channel: "form", projectId: enfold.id, customer: { email: `vera.${run}@example.org` } });
  await founderPage.goto(`${OMNABASE_APP}/kriesi/inbox/all-open/${n}`);
  await expect(founderPage.locator(".ob-detail")).toContainText(subject);
});

/**
 * What it checks: A bot that fills the trap field is shown "sent" and nothing is filed.
 * Passes when: with the trap field filled by script, sending shows a thanks message and OmnaBase holds no ticket with that subject.
 * Fails when: spam bots file tickets through the site's form, or learn from an error that they were caught.
 */
test("a filled trap field files nothing", async ({ browser }) => {
  const page = await visitorOnTheForm(browser);
  const subject = `Trap ${run}`;
  await theForm(page).locator('input[name="omnabase_hp"]').evaluate((el) => { (el as HTMLInputElement).value = "http://cheap-pills.example"; });
  await send(page, { name: "Bot", email: `bot.${run}@example.org`, subject, message: "Buy now." });
  await expect(page.getByText(/Thanks/)).toBeVisible();
  expect(await ticketsWithSubject(subject)).toHaveLength(0);
});

/**
 * What it checks: A captured token cannot be used again for other words.
 * Passes when: after a visitor's message is filed, the same request sent again with another subject and message (the same token) is refused,
 *   and OmnaBase holds no ticket with the second subject.
 * Fails when: one token copied from a real visitor lets a bot post as often as it likes.
 */
test("a token used a second time for other words is refused", async ({ browser }) => {
  const page = await visitorOnTheForm(browser);
  const posted = page.waitForRequest((r) => r.method() === "POST" && new URL(r.url()).pathname.endsWith("/wp-json/omnabase/v1/form"));
  await send(page, { name: "Rita Replay", email: `rita.${run}@example.org`, subject: `Replay first ${run}`, message: "The first message." });
  const request = await posted;
  await expect(page.getByText(/Your reference is #\d+/)).toBeVisible();

  const second = `Replay second ${run}`;
  const type = request.headers()["content-type"] ?? "application/json";
  const raw = request.postData() ?? "";
  let body: string;
  if (type.includes("json")) body = JSON.stringify({ ...(JSON.parse(raw) as Record<string, unknown>), subject: second, message: "Another message, the same token." });
  else { const p = new URLSearchParams(raw); p.set("subject", second); p.set("message", "Another message, the same token."); body = p.toString(); }
  const again = await fetch(`${WP_LOCAL}/wp-json/omnabase/v1/form`, { method: "POST", headers: { "content-type": type, "x-forwarded-host": SITE_HOST, "x-forwarded-proto": "https" }, body });
  expect(again.status, await again.clone().text()).toBeGreaterThanOrEqual(400);
  expect(await ticketsWithSubject(second)).toHaveLength(0);
});

/**
 * What it checks: Two visitors on the same page each get their own ticket (each page view fetched its own token).
 * Passes when: two visitors open the page, each sends a different question, both read a reference, the two references differ and OmnaBase
 *   holds one ticket for each subject.
 * Fails when: the second visitor behind a shared page is refused or their question is merged into the first's ticket.
 */
test("two visitors on one page get two tickets", async ({ browser }) => {
  const anna = await visitorOnTheForm(browser);
  const bert = await visitorOnTheForm(browser);
  await send(anna, { name: "Anna", email: `anna.${run}@example.org`, subject: `Anna asks ${run}`, message: "Anna's question." });
  await send(bert, { name: "Bert", email: `bert.${run}@example.org`, subject: `Bert asks ${run}`, message: "Bert's question." });
  const a = /#(\d+)/.exec((await anna.getByText(/Your reference is #\d+/).textContent()) ?? "")?.[1];
  const b = /#(\d+)/.exec((await bert.getByText(/Your reference is #\d+/).textContent()) ?? "")?.[1];
  expect(a).toBeTruthy();
  expect(b).toBeTruthy();
  expect(a).not.toBe(b);
  expect(await ticketsWithSubject(`Anna asks ${run}`)).toHaveLength(1);
  expect(await ticketsWithSubject(`Bert asks ${run}`)).toHaveLength(1);
});

/**
 * What it checks: A message sent while OmnaBase cannot be reached is kept and reaches the inbox once OmnaBase is back.
 * Passes when: with OmnaBase unreachable for WordPress, the visitor reads "Thanks, your message is on its way", one waiting outbox row about a
 *   form exists and OmnaBase holds no ticket yet; with OmnaBase reachable again and the outbox worked, OmnaBase holds exactly one form ticket
 *   with that subject.
 * Fails when: questions sent during an outage are lost, or filed twice when it ends.
 */
test("with OmnaBase down the outbox carries the message, and the inbox gets it later", async ({ browser }) => {
  const page = await visitorOnTheForm(browser);
  const subject = `While down ${run}`;
  php("update_option( 'omnabase_test_offline', 1 );");
  try {
    await send(page, { name: "Otto Outage", email: `otto.${run}@example.org`, subject, message: "Sent during the outage." });
    await expect(page.getByText(/on its way/)).toBeVisible();
    expect(php(`global $wpdb; echo (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}omnabase_outbox WHERE kind LIKE '%form%' AND state = 'waiting'" );`)).toBe("1");
    expect(await ticketsWithSubject(subject)).toHaveLength(0);
  } finally {
    php("delete_option( 'omnabase_test_offline' );");
  }

  workOutbox();

  const filed = await ticketsWithSubject(subject);
  expect(filed).toHaveLength(1);
  expect(filed[0]!.channel).toBe("form");
});
