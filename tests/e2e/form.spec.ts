/**
 * The plugin's forms round (2026-10-25), the red team's failing flow, replacing round four's Classic flow of the same name: the shortcode shows a
 * form built in OmnaBase, drawn on the WordPress page by OmnaBase's embed, from a visitor's browser to OmnaBase's inbox; a signed-in customer's
 * details arrive verified through the prefill token the plugin's server asks for.
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`): outcomes 1 and 2, "Rendering", "Verified prefill" and "Tests" ("the form renders, a
 * logged-in user's submission arrives verified, a guest's arrives plain … the browser flow `form.spec.ts` adjusted to the embed"). The brief
 * places "a logged-in user's submission arrives verified" in the integration layer; the plugin's integration layer fakes OmnaBase at the HTTP
 * layer, so the real round trip is proven here, against the app's lane-3 stack (report finding 10).
 *
 * The words: *embed*, OmnaBase's script `forms.js`, loaded from OmnaBase's app address, that draws the form inside an empty container the
 * shortcode prints; *prefill token*, a short-lived note signed by OmnaBase, asked for by the plugin's server with the site's key, that makes the
 * signed-in customer's address, name and order number show as verified in the ticket; *verified*, the ticket's submission block marks the value
 * as vouched for by the shop, not typed by a stranger; *relay*, the e2e server's hook that lets OmnaBase reach this WordPress.
 *
 * Choices this flow makes (the builder may change them and tell the red team):
 * - The founder builds the forms through OmnaBase's workspace API with his session (`POST /api/v1/w/kriesi/forms`, then `PATCH` with slug,
 *   fields, default project and published), as OmnaBase's own round tests do; the ticket's submission block is read from the workspace's
 *   ticket (`GET /api/v1/w/kriesi/tickets/<n>`: `submission.fields[].verified`, round three).
 * - The embed's words are OmnaBase's ("Send", "Thanks, we have your message.", "Your reference: #<n>"); fields are found by their labels.
 * - Ada (seeded customer, password "test-password") has a WooCommerce order; the WooCommerce customer data switch is turned on for this file and
 *   put back afterwards, so her newest order number is vouched for.
 * - The plugin's temporary values (`omnabase` transients: the list of forms, the prefill notes) are cleared before each test.
 * - OmnaBase is made unreachable for WordPress (not for the browser) by the harness's switch `omnabase_test_offline`.
 *
 * Shared-seed rules this file keeps: it archives the forms it made, puts Enfold's default form and the customer data switches back, trashes its
 * pages, switches the harness back online, disconnects the site and resets the relay.
 *
 * Why this fails today: the shortcode prints the Classic form (no container, no embed), asks for no prefill token, and ignores `id`.
 */
import type { Browser, BrowserContext, Page, Locator } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, SITE, SITE_HOST, WP_LOCAL, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
const run = Date.now().toString(36);
const SUPPORT = `wp-support-${run}`;
const SALES = `wp-sales-${run}`;
type Ticket = { number: number; subject: string; channel: string; projectId: string; customer: { email: string; name: string } };
type SubmissionField = { key: string; value: string; verified: boolean };
type WorkspaceTicket = { number: number; projectId: string; submission?: { fields: SubmissionField[] } | null };

test.describe.configure({ mode: "serial" });

let key = "";
let enfold = { id: "", slug: "", name: "" };
let founderContext: BrowserContext;
let defaultBefore: string | null = null;
let factsBefore = "";
const formIds: string[] = [];
const pageIds: string[] = [];
const contexts: BrowserContext[] = [];
const pages: Record<"support" | "two" | "bare" | "unknown", string> = { support: "", two: "", bare: "", unknown: "" };

const php = (code: string): string => wp("eval", code).trim();

async function lastMail(email: string): Promise<string> {
  const res = await fetch(`${OMNABASE_E2E}/api/test/mail?to=${encodeURIComponent(email)}`);
  return res.ok ? (((await res.json()) as { text?: string } | null)?.text ?? "") : "";
}
async function codeFor(email: string, before: string): Promise<string> {
  for (let i = 0; i < 100; i++) {
    const text = await lastMail(email);
    const hit = text !== before ? /(\d{3}) ?(\d{3})/.exec(text) : null;
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
  const page = await founderContext.newPage();
  await page.goto(`${OMNABASE_APP}/signin?workspace=kriesi`);
  await page.getByRole("textbox", { name: "Email" }).fill(FOUNDER);
  const before = await lastMail(FOUNDER);
  await page.getByRole("button", { name: "Continue", exact: true }).click();
  await page.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER, before));
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(/\/kriesi(\/|$)/);
}

/** a published form built through OmnaBase's workspace API with the founder's session, Enfold as its default project */
async function buildForm(slug: string, name: string, items: Record<string, unknown>[]): Promise<void> {
  const made = await founderContext.request.post(`${OMNABASE_E2E}/api/v1/w/kriesi/forms`, { data: { name } });
  expect(made.ok(), `the form ${name} made (${made.status()})`).toBe(true);
  const id = ((await made.json()) as { id: string }).id;
  formIds.push(id);
  const saved = await founderContext.request.patch(`${OMNABASE_E2E}/api/v1/w/kriesi/forms/${id}`, { data: { slug, fields: { v: 1, items }, defaultProjectId: enfold.id, published: true } });
  expect(saved.ok(), `the form ${name} saved (${saved.status()}: ${await saved.text()})`).toBe(true);
}

async function setEnfoldDefaultForm(formId: string | null): Promise<void> {
  const res = await founderContext.request.patch(`${OMNABASE_E2E}/api/v1/w/kriesi/projects/${enfold.id}`, { data: { defaultFormId: formId } });
  expect(res.ok(), `Enfold's default form saved (${res.status()})`).toBe(true);
}

function makePage(title: string, content: string): string {
  const id = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => ${JSON.stringify(`${title} ${run}`)}, 'post_content' => ${JSON.stringify(content)} ) );`);
  expect(id, `the page ${title}`).toMatch(/^\d+$/);
  pageIds.push(id);
  return php(`echo get_permalink( ${id} );`).replace(/^https?:\/\/[^/]+/, SITE);
}

/** the plugin's temporary values (the list of forms, the prefill notes) go, so each test starts afresh */
function resetFormState(): void {
  php(`global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%omnabase%' AND option_name NOT LIKE '%omnabase_notice_%'" ); wp_cache_flush();`);
}

async function visitor(browser: Browser): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await routeSite(context);
  contexts.push(context);
  return context.newPage();
}

/** a seeded customer signed in on the site (password "test-password") */
async function signedIn(browser: Browser, login: string): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await routeSite(context);
  contexts.push(context);
  const page = await context.newPage();
  await page.goto(`${SITE}/wp-login.php`);
  await page.locator("#user_login").fill(login);
  await page.locator("#user_pass").fill("test-password");
  await page.locator("#wp-submit").click();
  await page.waitForURL((u) => !u.pathname.startsWith("/wp-login.php"));
  return page;
}

const formBox = (page: Page, slug = SUPPORT): Locator => page.locator(`[data-omnabase-form="kriesi/${slug}"]`);

/** opens a page and waits until the embed has drawn the form in its container */
async function openForm(page: Page, url: string, slug = SUPPORT): Promise<Locator> {
  await page.goto(url);
  await expect(page.getByText("[omnabase_form"), "the shortcode is replaced").toHaveCount(0);
  const box = formBox(page, slug);
  await expect(box, "the embed drew the form").toHaveAttribute("data-omnabase-ready", "ready");
  return box;
}

async function sendAndRead(box: Locator): Promise<number> {
  await box.getByRole("button", { name: "Send" }).click();
  await expect(box.getByText("Thanks, we have your message.")).toBeVisible();
  const ref = box.getByText(/Your reference: #\d+/);
  await expect(ref).toBeVisible();
  return Number(/#(\d+)/.exec((await ref.textContent()) ?? "")![1]);
}

async function submissionOf(n: number): Promise<WorkspaceTicket> {
  const res = await founderContext.request.get(`${OMNABASE_E2E}/api/v1/w/kriesi/tickets/${n}`);
  expect(res.ok(), `the founder reads ticket ${n} (${res.status()})`).toBe(true);
  return (await res.json()) as WorkspaceTicket;
}
const verified = (t: WorkspaceTicket): string[] => (t.submission?.fields ?? []).filter((f) => f.verified).map((f) => f.key).sort();

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
  const ws = (await (await founderContext.request.get(`${OMNABASE_E2E}/api/v1/w/kriesi`)).json()) as { projects: { id: string; defaultFormId?: string | null }[] };
  defaultBefore = ws.projects.find((p) => p.id === enfold.id)?.defaultFormId ?? null;
  await buildForm(SUPPORT, `WP support ${run}`, [
    { key: "email", type: "email", label: "Email", required: true, mapping: "email" },
    { key: "full_name", type: "text", label: "Your name", required: false, mapping: "name" },
    { key: "order_number", type: "text", label: "Order number", required: false, mapping: "order" },
    { key: "message", type: "textarea", label: "Message", required: true, mapping: "message" },
  ]);
  await buildForm(SALES, `WP sales ${run}`, [
    { key: "email", type: "email", label: "Email", required: true, mapping: "email" },
    { key: "message", type: "textarea", label: "Your question", required: true, mapping: "message" },
  ]);
  factsBefore = php("echo wp_json_encode( get_option( 'omnabase_facts', array() ) );");
  php("$f = (array) get_option( 'omnabase_facts', array() ); $f['sources'] = array_merge( (array) ( $f['sources'] ?? array() ), array( 'profile' => true, 'woocommerce' => true ) ); update_option( 'omnabase_facts', $f );");
  pages.support = makePage("Contact", `[omnabase_form id="${SUPPORT}"]`);
  pages.two = makePage("Two forms", `[omnabase_form id="${SUPPORT}"]\n\n[omnabase_form id="${SALES}"]`);
  pages.bare = makePage("Bare contact", "[omnabase_form]");
  pages.unknown = makePage("Unknown form", `[omnabase_form id="nope-${run}"]`);
});

test.beforeEach(() => resetFormState());

test.afterAll(async () => {
  try { php(`delete_option( 'omnabase_test_offline' ); foreach ( ${JSON.stringify(pageIds.map(Number))} as $id ) { wp_trash_post( (int) $id ); }`); } catch { /* the plugin may be half built */ }
  try { if (factsBefore) php(`update_option( 'omnabase_facts', json_decode( ${JSON.stringify(factsBefore)}, true ) );`); } catch { /* idem */ }
  try { resetFormState(); } catch { /* idem */ }
  if (founderContext) {
    try { await founderContext.request.patch(`${OMNABASE_E2E}/api/v1/w/kriesi/projects/${enfold.id}`, { data: { defaultFormId: defaultBefore } }); } catch { /* idem */ }
    for (const id of formIds) { try { await founderContext.request.post(`${OMNABASE_E2E}/api/v1/w/kriesi/forms/${id}/archive`); } catch { /* idem */ } }
  }
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  for (const c of contexts) await c.close();
  await founderContext?.close();
});

/**
 * What it checks: A visitor sees the form the founder built in OmnaBase, drawn on the WordPress page by OmnaBase's embed loaded from the app.
 * Passes when: the page holds no raw shortcode, the embed's script comes from the app address (answering 200), and the container shows the
 *   form's fields by their labels (Email, Your name, Order number, Message) with a Send button; nothing of the old fixed form shows.
 * Fails when: the site keeps the retired fixed form, the script is loaded from an address the browser cannot reach, or the form stays empty.
 */
test("a visitor sees the form built in OmnaBase, drawn by the embed", async ({ browser }) => {
  const page = await visitor(browser);
  const script = page.waitForResponse((r) => r.url().split("?")[0] === `${OMNABASE_APP}/forms.js`, { timeout: 15_000 });
  script.catch(() => undefined);
  const box = await openForm(page, pages.support);
  expect((await script).status(), "the embed's script from the app address").toBe(200);
  for (const label of ["Email", "Your name", "Order number", "Message"]) await expect(box.getByLabel(label)).toBeVisible();
  await expect(box.getByRole("button", { name: "Send" })).toBeVisible();
  await expect(page.locator('input[name="omnabase_hp"]'), "no Classic trap field").toHaveCount(0);
});

/**
 * What it checks: A visitor's message becomes a form ticket in Enfold with the visitor as the customer, and nothing they typed is shown as
 *   checked by the shop.
 * Passes when: after sending, the embed thanks with a reference; ticket <n> is Enfold's, on the form channel, the visitor's address as its
 *   customer; its submission block marks no field verified.
 * Fails when: messages from the site's form never reach the inbox, land in another project, or a stranger's typing looks verified.
 */
test("a visitor's message becomes a ticket, nothing verified", async ({ browser }) => {
  const page = await visitor(browser);
  const box = await openForm(page, pages.support);
  const email = `vera.${run}@example.org`;
  await box.getByLabel("Email").fill(email);
  await box.getByLabel("Your name").fill("Vera Visitor");
  await box.getByLabel("Message").fill(`The slider stopped after the update (${run}).`);
  const n = await sendAndRead(box);

  const read = await publicApi<Ticket>(key, "GET", `/tickets/${n}`);
  expect(read.status, JSON.stringify(read.body)).toBe(200);
  expect(read.body).toMatchObject({ channel: "form", projectId: enfold.id, customer: { email } });
  expect(verified(await submissionOf(n)), "a visitor's values are not verified").toEqual([]);
});

/**
 * What it checks: The round's point: a signed-in shop customer finds their address, name and newest order number filled in, and the team sees
 *   exactly those values as verified by the shop.
 * Passes when: Ada, signed in, sees the form with her address, her name and her newest WooCommerce order number already filled in; after she
 *   writes her message and sends, the ticket's submission block marks email, full_name and order_number verified and the message not.
 * Fails when: signed-in customers must type what the shop knows, or reach the team as unverified strangers whose order number nobody trusts.
 */
test("a signed-in customer's details are filled in and arrive verified", async ({ browser }) => {
  const order = php("$u = get_user_by( 'login', 'ada' ); $o = wc_get_orders( array( 'customer_id' => $u->ID, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC' ) ); echo $o ? $o[0]->get_order_number() : '';");
  expect(order, "the seed gave Ada an order").not.toBe("");
  const page = await signedIn(browser, "ada");
  const box = await openForm(page, pages.support);
  await expect(box, "the page carries OmnaBase's note").toHaveAttribute("data-prefill-token", /.+/);
  await expect(box.getByLabel("Email")).toHaveValue("ada@example.com");
  await expect(box.getByLabel("Your name")).toHaveValue("Ada Lovelace");
  await expect(box.getByLabel("Order number")).toHaveValue(order);
  await box.getByLabel("Message").fill(`Is my licence valid for staging? (${run})`);
  const n = await sendAndRead(box);

  const t = await submissionOf(n);
  expect(t.projectId).toBe(enfold.id);
  expect(verified(t), "the shop's values verified").toEqual(["email", "full_name", "order_number"]);
  expect(t.submission?.fields.find((f) => f.key === "message")?.verified, "the typed message is not").toBe(false);
});

/**
 * What it checks: A value the signed-in customer changes is their own word, not the shop's.
 * Passes when: Ben, signed in, replaces his filled-in name with "Someone Else" and sends; the ticket marks his address verified and the name not.
 * Fails when: a customer could type any name over the shop's and have it shown as checked by the shop.
 */
test("a value the customer changes arrives unverified", async ({ browser }) => {
  const page = await signedIn(browser, "ben");
  const box = await openForm(page, pages.support);
  await expect(box.getByLabel("Email")).toHaveValue("ben@example.com");
  await box.getByLabel("Your name").fill("Someone Else");
  await box.getByLabel("Message").fill(`A question in another name (${run}).`);
  const t = await submissionOf(await sendAndRead(box));
  expect(verified(t)).toContain("email");
  expect(verified(t)).not.toContain("full_name");
});

/**
 * What it checks: A page with two forms shows both, each working on its own, with the embed's script loaded once.
 * Passes when: the page with the Support and the Sales shortcodes draws both forms, exactly one request loads forms.js, and a question sent in
 *   the Sales form thanks only there.
 * Fails when: the second form stays empty, a form is drawn twice, or sending one form clears the other.
 */
test("two forms on one page, one script", async ({ browser }) => {
  const page = await visitor(browser);
  const loads: string[] = [];
  page.on("request", (r) => { if (r.url().split("?")[0].endsWith("/forms.js")) loads.push(r.url()); });
  await openForm(page, pages.two);
  const sales = formBox(page, SALES);
  await expect(sales).toHaveAttribute("data-omnabase-ready", "ready");
  expect(loads, "the script once").toHaveLength(1);
  await sales.getByLabel("Email").fill(`sam.${run}@example.org`);
  await sales.getByLabel("Your question").fill("Do you offer a team licence?");
  await sendAndRead(sales);
  await expect(formBox(page).getByRole("button", { name: "Send" }), "the Support form is untouched").toBeVisible();
});

/**
 * What it checks: When WordPress cannot reach OmnaBase, a signed-in customer still gets the form (the browser reaches OmnaBase on its own), only
 *   without the shop's vouching.
 * Passes when: with OmnaBase unreachable for WordPress, Ada's page carries no prefill token, the embed still draws the form, and her message
 *   arrives as a ticket with nothing verified.
 * Fails when: a hiccup between the shop and OmnaBase takes the contact form away from signed-in customers, or loses their message.
 */
test("with OmnaBase unreachable for WordPress, a signed-in customer still sends, unverified", async ({ browser }) => {
  php("update_option( 'omnabase_test_offline', 1 );");
  try {
    const page = await signedIn(browser, "ada");
    const box = await openForm(page, pages.support);
    await expect(box).not.toHaveAttribute("data-prefill-token", /.+/);
    await box.getByLabel("Email").fill("ada@example.com");
    await box.getByLabel("Message").fill(`Sent while the shop could not vouch (${run}).`);
    const n = await sendAndRead(box);
    expect(verified(await submissionOf(n))).toEqual([]);
  } finally {
    php("delete_option( 'omnabase_test_offline' );");
  }
});

/**
 * What it checks: A contact page whose shortcode names no form shows the project's default form, so pages made before this round keep working.
 * Passes when: with the Support form set as Enfold's default in OmnaBase, the page holding a bare [omnabase_form] draws the Support form.
 * Fails when: every existing contact page goes blank after the update.
 */
test("a shortcode without an id shows the project's default form", async ({ browser }) => {
  await setEnfoldDefaultForm(formIds[0]!);
  const page = await visitor(browser);
  await openForm(page, pages.bare);
  await expect(formBox(page)).toHaveAttribute("data-project", enfold.slug);
});

/**
 * What it checks: A shortcode naming a form OmnaBase does not have shows visitors nothing and tells the administrator.
 * Passes when: a visitor on the page sees no form container and no note; the administrator on the same page reads the plugin's note ending
 *   "Only administrators see this note."
 * Fails when: visitors meet "This form is not available." on a live page, or the owner never learns the shortcode is wrong.
 */
test("an unknown form shows visitors nothing and the administrator a note", async ({ browser }) => {
  const page = await visitor(browser);
  await page.goto(pages.unknown);
  await expect(page.locator("[data-omnabase-form]")).toHaveCount(0);
  await expect(page.getByText("Only administrators see this note.")).toHaveCount(0);

  const adminContext = await browser.newContext({ storageState: ADMIN_STATE });
  await routeSite(adminContext);
  contexts.push(adminContext);
  const admin = await adminContext.newPage();
  await admin.goto(pages.unknown);
  await expect(admin.getByText(/Only administrators see this note\./)).toBeVisible();
});
