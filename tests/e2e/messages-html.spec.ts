/**
 * The plugin's message-centre HTML round (2026-10-28), the red team's failing flows: a reply written in OmnaBase with a link, a list and a table
 * shows as those elements on the WordPress page, a reply typed in the message centre shows its address as a link, and a wide table does not
 * push a phone's page sideways. The site connects for real (as messages.spec.ts does), OmnaBase's lane-3 e2e stack is the backend, and the
 * team's reply leaves through OmnaBase's sender pass, so the portal's real display shape reaches the plugin.
 *
 * Brief (OmnaBase `docs/briefs/plugin-message-centre-html-round.md`): "Browser flows (lane 3): `messages.spec.ts` gains a reply sent from the
 * app side with a link and a list that shows as those elements on the WordPress page; `frontend-look.spec.ts`'s thread pictures include such a
 * reply (pictures are records for the founder's eye)"; outcome 1 ("Their own messages too (a reply typed into the message centre shows its
 * addresses as links …)"); rule 2's stylesheet ("lists (markers back …), links (… underlined …), code and pre (… monospace, pre scrolls
 * sideways), tables (collapsed borders, … the body scrolls sideways when a table is wider)").
 *
 * The words: *computed style*, the value the browser finally uses after every stylesheet had its say (the site's theme included); *portal*, the
 * public API's calls the message centre makes; *sender pass*, OmnaBase's job that sends queued replies (`/api/test/tick`).
 *
 * Choices this file makes (the builder may change them and tell the red team):
 * - A file of its own beside messages.spec.ts (that file is a long serial story; one more step there would hang on all its earlier ones).
 * - The founder's reply is sent through OmnaBase's workspace API with the composer's `html` field (the app's composer sends both), with the
 *   `mail_out` flag on for the send and off after, as messages.spec.ts does.
 * - Pictures go to `test-results/frontend-look/messages-html-<theme>-<size>.png`: records for the founder's eye, never compared. The theme is
 *   switched through the Appearance option (`omnabase_appearance`) and put back afterwards.
 *
 * Shared-seed rules this file keeps: it deletes its user, trashes its page, puts the Appearance option back, switches `mail_out` off,
 * disconnects the site and resets the relay.
 *
 * Why this fails today: the thread prints the message's plain text, so the page holds no list item, no table and no link inside the message
 * box, and the box keeps the text's lines with `pre-line`.
 */
import { mkdirSync, statSync } from "node:fs";
import { resolve } from "node:path";
import type { Browser, BrowserContext, Locator, Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, REPO, SITE, SITE_HOST, WP_LOCAL, codeFor, lastMail, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
const W = `${OMNABASE_E2E}/api/v1/w/kriesi`;
const SHOTS = resolve(REPO, "test-results/frontend-look");
const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1280, height: 900 };
const run = Date.now().toString(36);
const NOA = { login: `noa${run}`, email: `noa.${run}@example.com`, name: "Noa Html" };
const SUBJECT = `Shaped ${run}`;
const DOCS = `https://example.com/docs-${run}`;
const WIDE = Array.from({ length: 7 }, (_, i) => `Column-number-${i + 1}-with-a-long-heading`);
const REPLY_HTML = `<p>Thanks for writing, <strong>here is what helps</strong>:</p><ul><li>Clear the cache ${run}</li><li>Reload the page</li></ul>`
  + `<p>Read <a href="${DOCS}">the docs</a> for more.</p><blockquote><p>A quoted tip.</p></blockquote><pre><code>define( 'WP_DEBUG', true );</code></pre>`
  + `<table><thead><tr>${WIDE.map((h) => `<th>${h}</th>`).join("")}</tr></thead><tbody><tr>${WIDE.map((_, i) => `<td>${i * 7}</td>`).join("")}</tr></tbody></table>`;
const REPLY_TEXT = `Thanks for writing, here is what helps:\n\n- Clear the cache ${run}\n- Reload the page\n\nRead the docs (${DOCS}) for more.`;
const TYPED = `https://example.com/order-${run}`;

test.describe.configure({ mode: "serial" });

const php = (code: string): string => wp("eval", code).trim();
let key = "";
let centreId = "", centreUrl = "", noaId = "", appearance = "";
let ticket = 0;
let founderContext: BrowserContext;
let noa: Page; let noaContext: BrowserContext;

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
async function signsIn(browser: Browser, login: string): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: DESKTOP });
  await routeSite(context);
  const page = await context.newPage();
  await page.goto(`${SITE}/wp-login.php`);
  await page.locator("#user_login").fill(login);
  await page.locator("#user_pass").fill("test-password");
  await page.locator("#wp-submit").click();
  await page.waitForURL((u) => !u.pathname.startsWith("/wp-login.php"));
  return { context, page };
}
const toSite = (url: string) => url.replace(/^https?:\/\/[^/]+/, SITE);
const openThread = (page: Page) => page.goto(`${centreUrl}?omnabase_ticket=${ticket}`);
const replyForm = (page: Page) => page.locator("form").filter({ has: page.locator("textarea") }).filter({ hasNot: page.locator('input[name="subject"]') });
const newTicketForm = (page: Page) => page.locator("form").filter({ has: page.locator('input[name="subject"]') }).filter({ has: page.locator("textarea") });
const teamBody = (page: Page): Locator => page.locator(".ob-wp-messages__message--team").filter({ hasText: "here is what helps" }).locator(".ob-wp-messages__body").first();
const style = (el: Locator, prop: string) => el.evaluate((n, p) => getComputedStyle(n as Element).getPropertyValue(p), prop);
async function shot(page: Page, name: string): Promise<void> {
  mkdirSync(SHOTS, { recursive: true });
  const path = resolve(SHOTS, `${name}.png`);
  await page.screenshot({ path, fullPage: true });
  expect(statSync(path).size, path).toBeGreaterThan(1000);
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(300_000);
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear(); delete_option( 'omnabase_portal' );");
  appearance = php("echo wp_json_encode( get_option( 'omnabase_appearance', null ) );");
  php("update_option( 'omnabase_appearance', array( 'theme' => 'light' ), false );");
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  expect(key, "the connect stored a key").toMatch(/^obw_/);
  await founderSignsIn(browser);
  centreId = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Messages html ${run}', 'post_content' => '[omnabase_messages]' ) );`);
  php(`update_option( 'omnabase_portal', array( 'page' => ${centreId} ) );`);
  centreUrl = toSite(php(`echo get_permalink( ${centreId} );`));
  noaId = php(`echo wp_insert_user( array( 'user_login' => '${NOA.login}', 'user_email' => '${NOA.email}', 'display_name' => '${NOA.name}', 'user_pass' => 'test-password', 'role' => 'subscriber' ) );`);
  expect(noaId, "Noa made").toMatch(/^\d+$/);

  ({ context: noaContext, page: noa } = await signsIn(browser, NOA.login));
  await noa.goto(centreUrl);
  const form = newTicketForm(noa);
  await form.locator('input[name="subject"]').fill(SUBJECT);
  await form.locator("textarea").fill(`The menu is gone (${run}).`);
  await form.locator('[type="submit"], button:not([type])').first().click();
  await expect(noa.getByText(SUBJECT).first()).toBeVisible();
  const list = await publicApi<{ data: { number: number; subject: string }[] }>(key, "GET", "/tickets?view=all-open&limit=100");
  ticket = list.body.data.find((t) => t.subject === SUBJECT)?.number ?? 0;
  expect(ticket, "Noa's question is a ticket").toBeGreaterThan(0);
  await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: true } });
  try {
    const sent = await founderContext.request.post(`${W}/tickets/${ticket}/messages`, { data: { kind: "reply", body: REPLY_TEXT, html: REPLY_HTML } });
    expect(sent.ok(), `the team's shaped reply: ${await sent.text()}`).toBe(true);
    await omnabaseHook("/api/test/tick", { job: "sender", now: new Date(Date.now() + 15_000).toISOString() });
  } finally {
    await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: false } });
  }
});

test.afterAll(async () => {
  try {
    php(`require_once ABSPATH . 'wp-admin/includes/user.php'; if ( ${noaId || 0} ) { wp_delete_user( ${noaId || 0} ); } if ( ${centreId || 0} ) { wp_trash_post( ${centreId || 0} ); } delete_option( 'omnabase_portal' );`);
    php(appearance && appearance !== "null" ? `update_option( 'omnabase_appearance', json_decode( ${JSON.stringify(appearance)}, true ), false );` : "delete_option( 'omnabase_appearance' );");
  } catch { /* the plugin may be half built */ }
  try { await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: false } }); } catch { /* idem */ }
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  await noaContext?.close();
  await founderContext?.close();
});

/**
 * What it checks: The team's reply written in OmnaBase with a link, a list, a quote, code and a table shows on the WordPress page as those
 *   elements, in the plugin's own look whatever the site's theme does.
 * Passes when: in Noa's thread the team's message box holds the bold words, a list of two items whose markers show, a link to the docs that
 *   opens a new tab without handing over the page and is underlined, a quote, a code block in a monospace font, and a table whose borders
 *   collapse; the box no longer keeps lines with `pre-line`; the link's address does not show in brackets.
 * Fails when: the customer reads "the docs (https://…)" and "- Clear the cache" in one grey block, or the theme strips the list's markers.
 */
test("the team's reply shows its link, list, quote, code and table on the WordPress page", async () => {
  await openThread(noa);
  const body = teamBody(noa);
  await expect(body, "the team's shaped reply is in the thread").toBeVisible();
  await expect(body.locator("strong")).toHaveText("here is what helps");
  await expect(body.locator("ul > li")).toHaveCount(2);
  expect(await style(body.locator("ul").first(), "list-style-type"), "the list's markers show").not.toBe("none");
  const link = body.locator(`a[href="${DOCS}"]`);
  await expect(link).toHaveText("the docs");
  await expect(link).toHaveAttribute("target", "_blank");
  await expect(link).toHaveAttribute("rel", /noopener/);
  expect(await style(link, "text-decoration-line"), "the link is underlined").toContain("underline");
  await expect(body.locator("blockquote")).toContainText("A quoted tip.");
  expect(await style(body.locator("pre").first(), "font-family"), "code in a monospace font").toMatch(/mono|courier|consolas|menlo/i);
  await expect(body.locator("table th")).toHaveCount(WIDE.length);
  expect(await style(body.locator("table").first(), "border-collapse")).toBe("collapse");
  expect(await style(body, "white-space"), "the HTML carries its own breaks").not.toBe("pre-line");
  await expect(body, "no address in brackets").not.toContainText(`(${DOCS})`);
});

/**
 * What it checks: An answer Noa types into the message centre comes back with her address as a link that opens a new tab.
 * Passes when: Noa sends "My order is at <address>, thanks." from the thread's reply box; after the page comes back her message box holds a
 *   link to that address with target `_blank`.
 * Fails when: the customer's own address shows as plain words, or as a link that replaces the message centre.
 */
test("Noa's typed answer shows its address as a link", async () => {
  await openThread(noa);
  await replyForm(noa).locator("textarea").fill(`My order is at ${TYPED}, thanks.`);
  await replyForm(noa).locator('[type="submit"], button:not([type])').first().click();
  const mine = noa.locator(".ob-wp-messages__message--you").filter({ hasText: TYPED }).locator(".ob-wp-messages__body");
  await expect(mine).toBeVisible();
  const link = mine.locator(`a[href="${TYPED}"]`);
  await expect(link, "her address is a link").toHaveCount(1);
  await expect(link).toHaveAttribute("target", "_blank");
});

/**
 * What it checks: On a 375px phone a wide table inside a message scrolls within the thread instead of pushing the page sideways, in light and
 *   dark; pictures of the shaped thread are kept for the founder's eye.
 * Passes when: at 375px the page and its body are no wider than the screen and the plugin's wrapper lies within it, while the table itself is
 *   wider than its message box; in Light and Dark at desktop and phone width a full-page picture of the thread is written to
 *   test-results/frontend-look/.
 * Fails when: one wide table makes the whole page slide sideways under the customer's thumb.
 */
test("a wide table scrolls inside the thread on a phone; pictures of the shaped thread", async () => {
  for (const theme of ["light", "dark"] as const) {
    php(`update_option( 'omnabase_appearance', array( 'theme' => '${theme}' ), false );`);
    for (const [size, suffix] of [[DESKTOP, "desktop"], [PHONE, "phone"]] as const) {
      await noa.setViewportSize(size);
      await openThread(noa);
      const body = teamBody(noa);
      await expect(body.locator("table")).toBeVisible();
      if (suffix === "phone") {
        const m = await noa.evaluate(() => {
          const wrap = document.querySelector(".ob-wp")!.getBoundingClientRect();
          const table = document.querySelector(".ob-wp-messages__body table")!.getBoundingClientRect();
          const box = document.querySelector(".ob-wp-messages__message--team .ob-wp-messages__body")!.getBoundingClientRect();
          return { doc: document.documentElement.scrollWidth, body: document.body.scrollWidth, width: window.innerWidth, wrapLeft: wrap.left, wrapRight: wrap.right, table: table.width, box: box.width };
        });
        expect(m.table, `${theme}: the table is wider than its box (the case under test)`).toBeGreaterThan(m.box);
        expect(m.doc, `${theme}: the page does not scroll sideways`).toBeLessThanOrEqual(m.width);
        expect(m.body, `${theme}: nor does its body`).toBeLessThanOrEqual(m.width);
        expect(m.wrapLeft).toBeGreaterThanOrEqual(0);
        expect(m.wrapRight).toBeLessThanOrEqual(m.width + 0.5);
      }
      await shot(noa, `messages-html-${theme}-${suffix}`);
    }
  }
  await noa.setViewportSize(DESKTOP);
});
