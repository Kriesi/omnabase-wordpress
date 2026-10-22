/**
 * The front-end design round (2026-10-22), the red team's failing flows: the contact form and the message centre in a real browser, in light
 * and in dark, after the owner's choices on the new Appearance tab. Computed colours are checked against OmnaBase's live tokens (read from the
 * OmnaBase repository at test time, as admin-look.spec.ts does), readability as contrast ratios, the phone layout at 375px with the layout
 * rules of OmnaBase's own screens.visual.ts (nothing scrolls sideways, every control on screen), and pictures of every piece are written to
 * test-results/frontend-look/ for the founder's eye (a record, never a comparison: the brief's "no screenshot baselines in the plugin repo").
 *
 * Brief (OmnaBase `docs/briefs/plugin-frontend-design.md`) rules 2 to 6 (quoted in the integration tests' headers); rule 5: "both shortcodes
 * read well at 375 px (one column, 16 px side gutters, no sideways scroll, controls on screen), checked by a browser flow with the layout
 * assertions of OmnaBase's `screens.visual.ts` (width, overflow), not by pictures"; rule 6: "save pictures of the form and the message centre
 * (list, thread, confirm) in light and dark at phone and desktop widths into `test-results/frontend-look/` … and check computed colours
 * against the live tokens".
 *
 * The words: *computed style*, the value the browser finally uses after every stylesheet had its say; *token*, one of OmnaBase's named design
 * values in `packages/ui/src/styles/tokens.css` (light: its first block; dark: its `[data-theme="dark"]` block over the light one); *contrast*,
 * WCAG's brightness ratio of two colours (4.5 to 1 for text, 3 to 1 for a focus ring); *focus ring*, the outline that shows a keyboard user
 * where they are; *honeypot*, the field hidden from people that only bots fill in (parked off screen on purpose, so the "on screen" rule skips
 * what screen readers are told to skip); *success card*, the box that takes the form's place once a message went.
 *
 * Choices this file makes (the builder may change them and tell the red team): the Appearance tab at `&tab=appearance` with the labels of
 * `AppearanceTestCase` (Light, Dark, "Follow the visitor's system", OmnaBase's blue, the owner's own colour with a colour field, "Inherit the
 * theme's font", "Load OmnaBase's font", "Theme styles only") and one "Save"; the wrapper `.ob-wp` (today's); a message's author kind as
 * today's modifier `ob-wp-messages__message--you` / `--team`; the prompt's card `.ob-portal-confirm` (the brief's); the success card found by
 * its words "We answer by email." and its "Send another"; a field's error found through the field's `aria-describedby`. The visitor's
 * message tint: any background other than the team message's (the brief leaves the tint to the builder; the test pins only that the two
 * differ and that neither is unreadable).
 *
 * Shared-seed rules this file keeps: it makes its own customer, pages and conversation, puts the Appearance choices back to the defaults,
 * trashes its pages, deletes its user, switches the harness back online, disconnects the site and resets the relay.
 *
 * Why this fails today: there is no Appearance tab, so no choice can be saved; the form has no dark colours, no success card, no
 * `aria-describedby`; its error reads at 4.2 to 1; the message centre has no `.ob-portal-confirm` card.
 */
import { mkdirSync, readFileSync, statSync } from "node:fs";
import { resolve } from "node:path";
import type { Browser, BrowserContext, Locator, Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, REPO, SITE, SITE_HOST, WP_LOCAL, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
const W = `${OMNABASE_E2E}/api/v1/w/kriesi`;
const SHOTS = resolve(REPO, "test-results/frontend-look");
const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1280, height: 900 };
const run = Date.now().toString(36);
const LIA = { login: `lia${run}`, email: `lia.${run}@example.com`, name: "Lia Look" };
const SUBJECT = `Looks ${run}`;
const ANSWER = `Team answer ${run}: try the new version.`;
const TEAL = "#0f7c8a";
const PALE = "#f5e663";
const INK = "#1a1a1a";

type Theme = "light" | "dark";
type Rgb = [number, number, number];
type Choice = { theme?: "Light" | "Dark" | "Follow the visitor's system"; accent?: "blue" | string; font?: "inherit" | "load"; themeOnly?: boolean };

test.describe.configure({ mode: "serial" });

/* ---------- OmnaBase's live tokens ---------- */

/** every block of tokens.css by its selector (comments left out), name → raw value, the first value kept */
function tokenBlocks(): Map<string, Record<string, string>> {
  const repo = process.env.OMNABASE_REPO ?? resolve(REPO, "../omnabase");
  const css = readFileSync(resolve(repo, "packages/ui/src/styles/tokens.css"), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
  const blocks = new Map<string, Record<string, string>>();
  for (const m of css.matchAll(/([^{}]+)\{([^{}]*)\}/g)) {
    const selector = m[1]!.trim().replace(/^@media[^{]*$/, "").trim();
    const into = blocks.get(selector) ?? {};
    for (const d of m[2]!.matchAll(/(--ob-[a-z0-9-]+)\s*:\s*([^;]+);/g)) into[d[1]!] ??= d[2]!.trim();
    blocks.set(selector, into);
  }
  return blocks;
}
const BLOCKS = tokenBlocks();
const LIGHT_VARS = BLOCKS.get(":root") ?? {};
const DARK_VARS = { ...LIGHT_VARS, ...(BLOCKS.get(':root[data-theme="dark"]') ?? {}) };

/** a token resolved to its colour in a theme, as the browser writes it (`rgb(r, g, b)`) */
function token(name: string, theme: Theme): string {
  const vars: Record<string, string> = theme === "dark" ? DARK_VARS : LIGHT_VARS;
  let v = vars[name] ?? "";
  for (let i = 0; i < 20 && /^var\(/.test(v); i++) v = vars[/var\(\s*(--ob-[a-z0-9-]+)/.exec(v)![1]!] ?? "";
  const hex = /^#([0-9a-f]{6})$/i.exec(v)?.[1];
  if (!hex) throw new Error(`${name} does not resolve to a plain colour in ${theme}: ${v}`);
  return `rgb(${[0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(", ")})`;
}
const hexRgb = (hex: string) => `rgb(${[1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(", ")})`;

/* ---------- colours in the page ---------- */

/**
 * an element's text colour, its own background, and the background really behind it (the first ancestor that paints one), each as
 * [r, g, b] read through a canvas, so oklab() and color-mix() results compare like plain colours
 */
async function colours(el: Locator): Promise<{ fg: Rgb; own: [number, number, number, number]; bg: Rgb; color: string; background: string }> {
  return el.evaluate((node) => {
    const ctx = document.createElement("canvas").getContext("2d", { willReadFrequently: true })!;
    const read = (v: string): [number, number, number, number] => {
      ctx.clearRect(0, 0, 1, 1);
      ctx.fillStyle = "rgba(0, 0, 0, 0)";
      ctx.fillStyle = v;
      ctx.fillRect(0, 0, 1, 1);
      const d = ctx.getImageData(0, 0, 1, 1).data;
      return [d[0]!, d[1]!, d[2]!, d[3]! / 255];
    };
    const s = getComputedStyle(node as Element);
    let behind: [number, number, number, number] = [255, 255, 255, 1];
    for (let n: Element | null = node as Element; n; n = n.parentElement) {
      const c = read(getComputedStyle(n).backgroundColor);
      if (c[3] > 0.5) { behind = c; break; }
    }
    const fg = read(s.color);
    return { fg: [fg[0], fg[1], fg[2]], own: read(s.backgroundColor), bg: [behind[0], behind[1], behind[2]], color: s.color, background: s.backgroundColor };
  });
}
function luminance([r, g, b]: Rgb): number {
  const f = (c: number) => { const s = c / 255; return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
}
function contrast(a: Rgb, b: Rgb): number {
  const [x, y] = [luminance(a), luminance(b)].sort((p, q) => q - p) as [number, number];
  return (x + 0.05) / (y + 0.05);
}
const rgbOf = (css: string): Rgb => (/(\d+),\s*(\d+),\s*(\d+)/.exec(css)!.slice(1, 4).map(Number) as Rgb);

/** an element's text reads at 4.5 to 1 (or `min`) on what is behind it */
async function expectReadable(el: Locator, what: string, min = 4.5): Promise<void> {
  const c = await colours(el);
  const ratio = contrast(c.fg, c.bg);
  expect(ratio, `${what}: ${c.color} on rgb(${c.bg.join(", ")}) reads at ${ratio.toFixed(2)} to 1`).toBeGreaterThanOrEqual(min);
}

/** a focused control shows a ring (outline or box-shadow) that stands out 3 to 1 from what is behind the control */
async function expectFocusRing(el: Locator, what: string): Promise<void> {
  await el.focus();
  await el.page().keyboard.press("Shift+Tab");
  await el.page().keyboard.press("Tab"); // reached by the keyboard, so the browser shows its keyboard focus style
  await expect(el, `${what}: has the keyboard focus`).toBeFocused();
  const ring = await el.evaluate((node) => {
    const s = getComputedStyle(node as Element);
    const shadow = s.boxShadow !== "none" ? s.boxShadow : "";
    const width = parseFloat(s.outlineWidth) || 0;
    return { outline: s.outlineStyle !== "none" && width > 0 ? s.outlineColor : "", shadow: /rgba?\([^)]*\)|oklab\([^)]*\)|color\([^)]*\)/.exec(shadow)?.[0] ?? "" };
  });
  const colour = ring.outline || ring.shadow;
  expect(colour, `${what}: a visible focus ring`).not.toBe("");
  const probe = el.page().locator("body");
  const ringRgb = await probe.evaluate((_b, v) => {
    const ctx = document.createElement("canvas").getContext("2d", { willReadFrequently: true })!;
    ctx.fillStyle = v; ctx.fillRect(0, 0, 1, 1);
    const d = ctx.getImageData(0, 0, 1, 1).data; return [d[0]!, d[1]!, d[2]!] as [number, number, number];
  }, colour);
  const behind = (await colours(el)).bg;
  expect(contrast(ringRgb, behind), `${what}: the ring (${colour}) stands out 3 to 1`).toBeGreaterThanOrEqual(3);
}

/** kit rule 4: anything filled with the accent writes its text in the accent's own text colour */
async function expectTextOnAccent(scope: Locator, accent: string, onAccent: string, what: string): Promise<void> {
  const wrong = await scope.evaluate((root, [a, on]) => {
    const ctx = document.createElement("canvas").getContext("2d", { willReadFrequently: true })!;
    const read = (v: string) => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = "rgba(0,0,0,0)"; ctx.fillStyle = v; ctx.fillRect(0, 0, 1, 1); return Array.from(ctx.getImageData(0, 0, 1, 1).data).join(","); };
    const accentRgb = read(a!), onRgb = read(on!);
    return Array.from(root.querySelectorAll<HTMLElement>("*")).filter((el) => el.offsetParent !== null && read(getComputedStyle(el).backgroundColor) === accentRgb && (el.textContent ?? "").trim() !== "" && read(getComputedStyle(el).color) !== onRgb).map((el) => el.outerHTML.slice(0, 80));
  }, [accent, onAccent]);
  expect(wrong, `${what}: text on the accent wears the accent's text colour`).toEqual([]);
}

/* ---------- the site ---------- */

const php = (code: string): string => wp("eval", code).trim();
let key = "";
let enfold = { id: "" };
let formBefore: { enabled: boolean; products: string[]; askLicense: boolean; privacyUrl: string | null } | null = null;
let formId = "", centreId = "", formUrl = "", centreUrl = "", liaId = "";
let ticket = 0;
let admin: Page; let adminContext: BrowserContext;
let founderContext: BrowserContext;
let lia: Page; let liaContext: BrowserContext;
const contexts: BrowserContext[] = [];

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
function resetFormState(): void {
  php(`global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%transient%omnabase%' AND option_name NOT LIKE '%omnabase_notice_%'" ); wp_cache_flush();`);
}
function workOutbox(): void {
  php(`global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}omnabase_outbox SET next_at = " . ( time() - 1 ) . " WHERE state = 'waiting'" );`);
  wp("cron", "event", "run", "omnabase_outbox");
}
const toSite = (url: string) => url.replace(/^https?:\/\/[^/]+/, SITE);

/** the administrator saves the Appearance tab with these choices, by the labels a person reads */
async function setAppearance(c: Choice): Promise<void> {
  await admin.goto(`${SETTINGS}&tab=appearance`);
  const form = admin.locator("form").filter({ has: admin.getByRole("radio", { name: "Dark", exact: true }) });
  await expect(form, "the Appearance tab's form").toHaveCount(1);
  if (c.theme) await form.getByRole("radio", { name: c.theme, exact: true }).check();
  if (c.accent === "blue") await form.getByRole("radio", { name: /OmnaBase['’]?s? blue/i }).check();
  else if (c.accent) {
    await form.getByRole("radio", { name: /\b(own|your|pick)\b.*colou?r|colou?r.*\b(own|your|pick)\b/i }).check();
    await form.locator('input[type="color"]').fill(c.accent);
  }
  if (c.font === "inherit") await form.getByRole("radio", { name: /Inherit the theme['’]s font/i }).check();
  if (c.font === "load") await form.getByRole("radio", { name: /Load OmnaBase['’]s font/i }).check();
  if (c.themeOnly !== undefined) await form.getByLabel(/theme(['’]s)? styles only/i).setChecked(c.themeOnly);
  await form.getByRole("button", { name: "Save", exact: true }).click();
  await admin.waitForURL((u) => u.searchParams.get("tab") === "appearance");
}

/** a visitor (nobody signed in) on the form's page once its time token came, at a size, in a device colour scheme */
async function visitor(browser: Browser, size = DESKTOP, scheme: "light" | "dark" = "light"): Promise<Page> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: size, colorScheme: scheme });
  await routeSite(context);
  contexts.push(context);
  const page = await context.newPage();
  const tokenCame = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith("/wp-json/omnabase/v1/form-token"), { timeout: 15_000 });
  tokenCame.catch(() => undefined);
  await page.goto(formUrl);
  expect((await tokenCame).status(), "the form fetched its time token").toBe(200);
  return page;
}
const theForm = (page: Page) => page.locator("form").filter({ has: page.locator('textarea[name="message"]') });
const wrapper = (page: Page) => page.locator(".ob-wp").first();
const card = (page: Page) => wrapper(page).locator("[role=status], [aria-live]").filter({ hasText: "We answer by email." });

async function fill(page: Page, who: { name: string; email: string; subject: string; message: string }, wait = 3_500): Promise<void> {
  const form = theForm(page);
  await form.locator('input[name="name"]').fill(who.name);
  await form.locator('input[name="email"]').fill(who.email);
  await form.locator('input[name="subject"]').fill(who.subject);
  await form.locator('textarea[name="message"]').fill(who.message);
  if (wait) await page.waitForTimeout(wait);
  await form.locator('[type="submit"]').first().click();
}
/** the error line a field names in its aria-describedby */
async function errorOf(page: Page, name: string): Promise<Locator> {
  const field = theForm(page).locator(`[name="${name}"]`);
  const ids = ((await field.getAttribute("aria-describedby")) ?? "").split(/\s+/).filter(Boolean);
  expect(ids.length, `${name}: names its error line`).toBeGreaterThan(0);
  return page.locator(ids.map((id) => `#${id}[data-for="${name}"]`).join(", ")).first();
}

async function shot(page: Page, name: string, el?: Locator): Promise<void> {
  mkdirSync(SHOTS, { recursive: true });
  const path = resolve(SHOTS, `${name}.png`);
  if (el) await el.screenshot({ path });
  else await page.screenshot({ path, fullPage: true });
  expect(statSync(path).size, path).toBeGreaterThan(1000);
}

/** OmnaBase's layout rules (screens.visual.ts): nothing scrolls sideways, every control fully on screen (the honeypot's hidden part excused) */
async function expectSoundLayout(page: Page, what: string): Promise<void> {
  const m = await page.evaluate(() => {
    const doc = document.documentElement;
    const offscreen = Array.from(document.querySelectorAll<HTMLElement>(".ob-wp button, .ob-wp a, .ob-wp input, .ob-wp textarea, .ob-wp select")).filter((e) => {
      const r = e.getBoundingClientRect();
      const s = getComputedStyle(e);
      if (e.closest("[aria-hidden='true'], [hidden]") || s.visibility === "hidden" || e.getAttribute("type") === "hidden") return false;
      return r.width > 0 && r.height > 0 && (r.right > window.innerWidth + 1 || r.left < -1);
    }).map((e) => (e.getAttribute("name") ?? e.textContent ?? "").trim().slice(0, 40));
    const wrap = document.querySelector(".ob-wp")!.getBoundingClientRect();
    return { docScroll: doc.scrollWidth, bodyScroll: document.body.scrollWidth, width: window.innerWidth, offscreen, wrapLeft: wrap.left, wrapRight: wrap.right };
  });
  expect(m.docScroll, `${what}: the page does not scroll sideways`).toBeLessThanOrEqual(m.width);
  expect(m.bodyScroll, `${what}: nor does its body`).toBeLessThanOrEqual(m.width);
  expect(m.offscreen, `${what}: every control is fully on screen`).toEqual([]);
  expect(m.wrapLeft, `${what}: the wrapper starts on screen`).toBeGreaterThanOrEqual(0);
  expect(m.wrapRight, `${what}: the wrapper ends on screen`).toBeLessThanOrEqual(m.width + 0.5);
}

async function signsIn(browser: Browser, login: string, size = DESKTOP): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: size });
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
async function openCentre(page: Page, query = ""): Promise<void> {
  const res = await page.goto(`${centreUrl}${query}`);
  expect(res, "the message centre answers").not.toBeNull();
  const cc = (res!.headers()["cache-control"] ?? "").toLowerCase();
  expect(cc, "the message centre is never cached, whatever the look").toContain("no-store");
  expect(cc).toContain("private");
}
const prompt = (page: Page) => page.getByRole("button", { name: /show my email conversations|send me the link/i });
const replyForm = (page: Page) => page.locator("form").filter({ has: page.locator("textarea") }).filter({ hasNot: page.locator('input[name="subject"]') });
const newTicketForm = (page: Page) => page.locator("form").filter({ has: page.locator('input[name="subject"]') }).filter({ has: page.locator("textarea") });

test.beforeAll(async ({ browser }) => {
  test.setTimeout(300_000);
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear(); delete_option( 'omnabase_test_offline' ); delete_option( 'omnabase_portal' );");
  resetFormState();
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  expect(key, "the connect stored a key").toMatch(/^obw_/);
  const me = await publicApi<{ projects: { id: string }[] }>(key, "GET", "/me");
  enfold = me.body.projects[0]!;
  await founderSignsIn(browser);
  const ws = (await (await founderContext.request.get(W)).json()) as { projects: { id: string; webForm?: typeof formBefore }[] };
  formBefore = ws.projects.find((p) => p.id === enfold.id)?.webForm ?? null;
  await founderContext.request.patch(`${W}/projects/${enfold.id}`, { data: { webForm: { products: ["Enfold", "Enfold Pro"], askLicense: true, privacyUrl: "https://kriesi.example/privacy" } } });

  formId = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Contact look ${run}', 'post_content' => '[omnabase_form]' ) );`);
  centreId = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Messages look ${run}', 'post_content' => '[omnabase_messages]' ) );`);
  php(`update_option( 'omnabase_portal', array( 'page' => ${centreId} ) );`);
  formUrl = toSite(php(`echo get_permalink( ${formId} );`));
  centreUrl = toSite(php(`echo get_permalink( ${centreId} );`));
  liaId = php(`echo wp_insert_user( array( 'user_login' => '${LIA.login}', 'user_email' => '${LIA.email}', 'display_name' => '${LIA.name}', 'user_pass' => 'test-password', 'role' => 'subscriber' ) );`);
  expect(liaId, "Lia made").toMatch(/^\d+$/);

  adminContext = await browser.newContext({ storageState: ADMIN_STATE, viewport: DESKTOP });
  await routeSite(adminContext);
  admin = await adminContext.newPage();

  ({ context: liaContext, page: lia } = await signsIn(browser, LIA.login));
  await openCentre(lia);
  const form = newTicketForm(lia);
  await form.locator('input[name="subject"]').fill(SUBJECT);
  await form.locator("textarea").fill(`The slider does not move (${run}).`);
  await form.locator('[type="submit"], button:not([type])').first().click();
  await expect(lia.getByText(SUBJECT).first()).toBeVisible();
  const list = await publicApi<{ data: { number: number; subject: string }[] }>(key, "GET", "/tickets?view=all-open&limit=100");
  ticket = list.body.data.find((t) => t.subject === SUBJECT)?.number ?? 0;
  expect(ticket, "Lia's question is a ticket").toBeGreaterThan(0);
  await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: true } });
  try {
    expect((await founderContext.request.post(`${W}/tickets/${ticket}/messages`, { data: { kind: "reply", body: ANSWER } })).ok(), "the team's answer").toBe(true);
    await omnabaseHook("/api/test/tick", { job: "sender", now: new Date(Date.now() + 15_000).toISOString() });
  } finally {
    await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: false } });
  }
});

test.beforeEach(() => resetFormState());

test.afterAll(async () => {
  try { if (admin) await setAppearance({ theme: "Light", accent: "blue", font: "inherit", themeOnly: false }); } catch { /* the tab may not exist yet */ }
  try {
    php(`require_once ABSPATH . 'wp-admin/includes/user.php'; if ( ${liaId || 0} ) { wp_delete_user( ${liaId || 0} ); } foreach ( array( ${formId || 0}, ${centreId || 0} ) as $p ) { if ( $p ) { wp_trash_post( $p ); } } delete_option( 'omnabase_portal' ); delete_option( 'omnabase_test_offline' );`);
  } catch { /* the plugin may be half built */ }
  try { resetFormState(); } catch { /* idem */ }
  try { await omnabaseHook("/api/test/limits", { workspace: "kriesi", flag: { mail_out: false } }); } catch { /* idem */ }
  if (formBefore && founderContext) await founderContext.request.patch(`${W}/projects/${enfold.id}`, { data: { webForm: formBefore } });
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  for (const c of contexts) await c.close();
  await liaContext?.close();
  await adminContext?.close();
  await founderContext?.close();
});

/**
 * What it checks: The contact form in light and in dark wears OmnaBase's colours, reads well, and shows a keyboard user where they are.
 * Passes when: for Light and then Dark saved on the Appearance tab, a visitor's form wrapper carries that theme mark and paints the theme's
 *   surface colour; a field's label is in the theme's text colour; a text field has the surface and the strong border; Send has the primary
 *   button's colour and text; the labels, the privacy line and Send's words read at 4.5 to 1; a focused field shows a ring that stands out
 *   3 to 1; anything filled with the accent writes in the accent's text colour; a picture of the form is saved at desktop and phone width.
 * Fails when: Dark is chosen and the form stays white (the dark colours copied but never applied), the form takes the theme's page colour so
 *   dark text lands on a dark theme (or light text on a light one), the privacy line fades to grey nobody can read, or the focus ring
 *   vanishes on the night background.
 */
test("the form in light and dark wears OmnaBase's colours and reads well", async ({ browser }) => {
  for (const theme of ["light", "dark"] as const) {
    await setAppearance({ theme: theme === "light" ? "Light" : "Dark", accent: "blue" });
    for (const [size, suffix] of [[DESKTOP, "desktop"], [PHONE, "phone"]] as const) {
      const page = await visitor(browser, size);
      const wrap = wrapper(page);
      await expect(wrap, `${theme}: the theme mark`).toHaveAttribute("data-theme", theme);
      const surface = await colours(wrap);
      expect(`rgb(${surface.bg.join(", ")})`, `${theme}: the wrapper stands on the theme's surface`).toBe(token("--ob-bg-surface", theme));
      const label = theForm(page).getByText("Email", { exact: true }).first();
      expect((await colours(label)).color, `${theme}: a label in the text colour`).toBe(token("--ob-text", theme));
      await expectReadable(label, `${theme}: the label`);
      const email = theForm(page).locator('input[name="email"]');
      const field = await email.evaluate((e) => { const s = getComputedStyle(e); return { bg: s.backgroundColor, border: s.borderTopColor }; });
      const outer = await email.evaluate((e) => { const box = e.closest(".ob-input") ?? e; const s = getComputedStyle(box); return { bg: s.backgroundColor, border: s.borderTopColor }; });
      expect([field.bg, outer.bg], `${theme}: the field on the surface`).toContain(token("--ob-bg-surface", theme));
      expect([field.border, outer.border], `${theme}: the field's strong border`).toContain(token("--ob-border-strong", theme));
      const send = theForm(page).locator('[type="submit"]').first();
      await expect(send, "Send is ready once the token came").toBeEnabled();
      const btn = await colours(send);
      expect(btn.background, `${theme}: Send wears the primary colour`).toBe(token("--ob-btn-primary-bg", theme));
      expect(btn.color, `${theme}: and its text`).toBe(token("--ob-btn-primary-text", theme));
      await expectReadable(send, `${theme}: Send's words`);
      await expectReadable(page.getByText("We use your details only to answer your message.").first(), `${theme}: the privacy line`);
      await expectReadable(page.getByRole("link", { name: "Privacy policy" }), `${theme}: the privacy link`);
      await expectFocusRing(email, `${theme}: the email field`);
      await expectTextOnAccent(wrap, token("--ob-accent", theme), token("--ob-on-accent", theme), theme);
      await shot(page, `form-${theme}-${suffix}`);
    }
  }
});

/**
 * What it checks: "Follow the visitor's system" turns the form dark on a dark device and light on a light one.
 * Passes when: with that choice saved, the form's wrapper carries no theme mark; a visitor whose device is set to dark sees the dark surface
 *   and the dark text colour, one whose device is light sees the light ones.
 * Fails when: the system choice is a light theme in disguise (the media block never written), or it ignores the device altogether.
 */
test("the system choice follows the visitor's device", async ({ browser }) => {
  await setAppearance({ theme: "Follow the visitor's system" });
  for (const scheme of ["dark", "light"] as const) {
    const page = await visitor(browser, DESKTOP, scheme);
    const wrap = wrapper(page);
    expect(await wrap.getAttribute("data-theme"), "no theme mark: the device decides").toBeNull();
    expect(`rgb(${(await colours(wrap)).bg.join(", ")})`, `${scheme} device: the surface`).toBe(token("--ob-bg-surface", scheme));
    expect((await colours(theForm(page).getByText("Email", { exact: true }).first())).color, `${scheme} device: the text`).toBe(token("--ob-text", scheme));
  }
});

/**
 * What it checks: A wrong field gets its error under it, tied to it for screen readers and readable in both themes, and the form stays.
 * Passes when: in Light and in Dark, sending an address that is not one shows the email field's error line (the element its
 *   aria-describedby names) visible, below the field, with words, marks the field aria-invalid="true", reads at 4.5 to 1, shows no success
 *   card, and keeps the subject the visitor typed.
 * Fails when: the error shows at the top of the form or nowhere near the field, a screen reader never hears it, the red cannot be read on
 *   white (today 4.2 to 1) or on night, or a refusal wipes what the visitor wrote.
 */
test("a wrong field shows its error under it, in both themes", async ({ browser }) => {
  for (const theme of ["Light", "Dark"] as const) {
    await setAppearance({ theme });
    const page = await visitor(browser);
    await fill(page, { name: "Wanda Wrong", email: "not-an-address", subject: `Wrong ${run}`, message: "Please help." });
    const error = await errorOf(page, "email");
    await expect(error, `${theme}: the error line shows`).toBeVisible();
    await expect(error, `${theme}: with words`).not.toHaveText("");
    const field = theForm(page).locator('input[name="email"]');
    await expect(field, `${theme}: the field says it is wrong`).toHaveAttribute("aria-invalid", "true");
    const [f, e] = [await field.boundingBox(), await error.boundingBox()];
    expect(e!.y, `${theme}: the error sits under the field`).toBeGreaterThanOrEqual(f!.y + f!.height - 1);
    await expectReadable(error, `${theme}: the error`);
    await expect(card(page), `${theme}: no success card`).toBeHidden();
    await expect(theForm(page).locator('input[name="subject"]'), `${theme}: the subject is kept`).toHaveValue(`Wrong ${run}`);
    await shot(page, `form-error-${theme.toLowerCase()}-desktop`);
  }
});

/**
 * What it checks: The success card takes the form's place with the reference number, and "Send another" brings back a form that sends again.
 * Passes when: after a good message the form is hidden and the card shows "Thanks. Your reference is #<n>", "We answer by email.", a picture
 *   hidden from screen readers and "Send another"; the card is read out (focus moves into it, or it is a live region); "Send another" shows
 *   the form again with an empty message, fetches a fresh time token, and a second message gets a different number; both numbers are real
 *   tickets in OmnaBase; pictures of the card in light and dark are saved.
 * Fails when: the card shows but the form's used token is kept, so the second message is refused ("This form was sent already"); the number is
 *   missing or wrong; or a screen-reader user hears nothing after pressing Send.
 */
test("the success card shows the reference and Send another sends again", async ({ browser }) => {
  for (const theme of ["Light", "Dark"] as const) {
    await setAppearance({ theme });
    resetFormState();
    const page = await visitor(browser);
    await fill(page, { name: "Sam Success", email: `sam.${theme}.${run}@example.org`, subject: `Card ${theme} ${run}`, message: "First message." });
    const done = card(page);
    await expect(done, `${theme}: the card shows`).toBeVisible();
    await expect(theForm(page), `${theme}: the form gives way`).toBeHidden();
    await expect(done).toContainText(/Thanks\. Your reference is #\d+/);
    const first = Number(/#(\d+)/.exec((await done.textContent()) ?? "")?.[1] ?? 0);
    expect(first, `${theme}: a number`).toBeGreaterThan(0);
    await expect(done.locator("svg[aria-hidden='true']").first(), `${theme}: the check icon`).toBeVisible();
    const announced = await done.evaluate((el) => el.contains(document.activeElement) || el.getAttribute("role") === "status" || !!el.getAttribute("aria-live"));
    expect(announced, `${theme}: the card is read out`).toBe(true);
    await expectReadable(done.getByText("We answer by email."), `${theme}: the card's line`);
    const wrap = wrapper(page);
    await expectTextOnAccent(wrap, token("--ob-accent", theme === "Light" ? "light" : "dark"), token("--ob-on-accent", theme === "Light" ? "light" : "dark"), `${theme} card`);
    await shot(page, `form-done-${theme.toLowerCase()}-desktop`, wrap);

    const fresh = page.waitForResponse((r) => new URL(r.url()).pathname.endsWith("/wp-json/omnabase/v1/form-token"), { timeout: 15_000 });
    await done.getByRole("button", { name: "Send another" }).or(done.getByRole("link", { name: "Send another" })).first().click();
    expect((await fresh).status(), `${theme}: a fresh time token`).toBe(200);
    await expect(theForm(page), `${theme}: the form is back`).toBeVisible();
    await expect(theForm(page).locator('textarea[name="message"]'), `${theme}: with an empty message`).toHaveValue("");
    await fill(page, { name: "Sam Success", email: `sam.${theme}.${run}@example.org`, subject: `Card again ${theme} ${run}`, message: "Second message." });
    await expect(card(page)).toBeVisible();
    const second = Number(/#(\d+)/.exec((await card(page).textContent()) ?? "")?.[1] ?? 0);
    expect(second, `${theme}: a second, different number`).toBeGreaterThan(0);
    expect(second).not.toBe(first);
    for (const n of [first, second]) expect((await publicApi(key, "GET", `/tickets/${n}`)).status, `ticket #${n} exists`).toBe(200);
  }
});

/**
 * What it checks: The card shows only what really happened: no number when the message waits for OmnaBase, no card at all when it was refused.
 * Passes when: sent within the first three seconds (too quick), the form stays with the visitor's words and its sentence, and no card shows;
 *   sent while OmnaBase cannot be reached, the card says the message is on its way and shows no "#" number; the outbox then delivers it.
 * Fails when: the card's "Thanks. Your reference is #" shows for a refused message (the visitor waits for an answer that never comes), or an
 *   on-its-way message shows "#undefined" or "#0".
 */
test("the card shows only what really happened", async ({ browser }) => {
  await setAppearance({ theme: "Light" });
  const quick = await visitor(browser);
  await fill(quick, { name: "Quinn Quick", email: `quinn.${run}@example.org`, subject: `Quick ${run}`, message: "Too fast." }, 0);
  await expect(quick.getByText(/That was quick/)).toBeVisible();
  await expect(card(quick), "no card for a refused message").toBeHidden();
  await expect(theForm(quick).locator('textarea[name="message"]')).toHaveValue("Too fast.");

  const page = await visitor(browser);
  php("update_option( 'omnabase_test_offline', 1 );");
  try {
    await fill(page, { name: "Otto Outage", email: `otto.${run}@example.org`, subject: `Look outage ${run}`, message: "Sent during the outage." });
    const done = card(page);
    await expect(done).toBeVisible();
    await expect(done).toContainText(/on its way/);
    await expect(done).not.toContainText(/#/);
  } finally {
    php("delete_option( 'omnabase_test_offline' );");
  }
  workOutbox();
});

/**
 * What it checks: The owner's own colour reaches the Send button with readable words, and the focus ring stays visible on a dark page.
 * Passes when: with teal saved, Send's colour is teal and its words white; with a pale yellow, Send's words are #1a1a1a (the app's rule);
 *   with OmnaBase's "Black" (#1a1a1a) and Dark, a focused field still shows a ring that stands out 3 to 1 from the night surface.
 * Fails when: the owner's colour never reaches the page, white words sit on pale yellow, or a dark accent on a dark page leaves keyboard users
 *   without a visible ring (the app's own rule today: the ring takes the owner's colour as it is).
 */
test("the owner's colour reaches Send with readable words", async ({ browser }) => {
  for (const [accent, text] of [[TEAL, "#ffffff"], [PALE, INK]] as const) {
    await setAppearance({ theme: "Light", accent });
    const page = await visitor(browser);
    const send = theForm(page).locator('[type="submit"]').first();
    await expect(send).toBeEnabled();
    const c = await colours(send);
    expect(c.background, `${accent}: Send wears the owner's colour`).toBe(hexRgb(accent));
    expect(c.color, `${accent}: with the app's text colour`).toBe(hexRgb(text));
  }
  await setAppearance({ theme: "Dark", accent: INK });
  const page = await visitor(browser);
  await expectFocusRing(theForm(page).locator('input[name="email"]'), "a black accent on a dark page");
});

/**
 * What it checks: OmnaBase's font loads only when the owner chose it, and "Theme styles only" loads nothing of OmnaBase's but keeps the marks.
 * Passes when: with the theme's font, the page asks for no font file of the plugin's and a label's font is not Plus Jakarta Sans; with
 *   OmnaBase's font, a woff2 file is fetched from the plugin's folder and the label's font starts with "Plus Jakarta Sans"; with "Theme styles
 *   only", the page links no stylesheet from the plugin's folder and asks for no font file of the plugin's, and the wrapper still carries its
 *   theme mark.
 * Fails when: every visitor downloads the font whatever the owner chose, the font is fetched from another server, or turning OmnaBase's look
 *   off also strips the marks a theme may style by.
 */
test("the font loads only when chosen, and theme styles only loads nothing", async ({ browser }) => {
  // Builder's adjustment (2026-10-22, DECISIONS): only the plugin's own font files are watched. The test WordPress's theme (Twenty
  // Twenty-Five) serves its own font from the site, which the first check counted as "a font file" although the page used the theme's
  // font as asked; the plugin cannot and must not stop a theme's font. Every check below is otherwise unchanged.
  const fonts = (page: Page) => { const seen: string[] = []; page.on("request", (r) => { if (/\/plugins\/omnabase\/.*\.woff2(\?|$)/.test(r.url())) seen.push(r.url()); }); return seen; };
  const fontOf = (page: Page) => theForm(page).getByText("Email", { exact: true }).first().evaluate((e) => getComputedStyle(e).fontFamily);

  await setAppearance({ theme: "Light", font: "inherit", themeOnly: false });
  let context = await browser.newContext({ storageState: { cookies: [], origins: [] } }); await routeSite(context); contexts.push(context);
  let page = await context.newPage(); let seen = fonts(page);
  await page.goto(formUrl); await page.waitForLoadState("networkidle");
  expect(seen, "the theme's font: no font file").toEqual([]);
  expect(await fontOf(page)).not.toMatch(/Plus Jakarta/i);

  await setAppearance({ font: "load" });
  context = await browser.newContext({ storageState: { cookies: [], origins: [] } }); await routeSite(context); contexts.push(context);
  page = await context.newPage(); seen = fonts(page);
  await page.goto(formUrl); await page.waitForLoadState("networkidle");
  expect(seen.length, "OmnaBase's font: its file is fetched").toBeGreaterThan(0);
  for (const url of seen) expect(new URL(url).hostname, "from the site itself").toBe(SITE_HOST);
  expect(await fontOf(page)).toMatch(/^"?Plus Jakarta Sans/);

  await setAppearance({ theme: "Dark", font: "load", themeOnly: true });
  context = await browser.newContext({ storageState: { cookies: [], origins: [] } }); await routeSite(context); contexts.push(context);
  page = await context.newPage(); seen = fonts(page);
  await page.goto(formUrl); await page.waitForLoadState("networkidle");
  await expect(page.locator("link[rel='stylesheet'][href*='/plugins/omnabase/assets/']"), "no stylesheet of the plugin's").toHaveCount(0);
  expect(seen, "no font file").toEqual([]);
  await expect(wrapper(page), "the theme mark stays").toHaveAttribute("data-theme", "dark");
  await setAppearance({ theme: "Light", font: "inherit", themeOnly: false });
});

/**
 * What it checks: The message centre in light and dark: the list, the prompt, the thread and a reply, each in OmnaBase's colours and readable.
 * Passes when: for Light and Dark, Lia's message centre (never cached) carries the theme mark on the theme's surface; her conversation's
 *   subject, its status pill's word and its date read at 4.5 to 1, and the pill reads Open, Answered, Resolved or Closed; the subject link
 *   shows a focus ring; the prompt to confirm her address is the kit's confirm card (surface, border) with the primary button; in the
 *   thread her own message and the team's answer have different backgrounds, the team's on the surface, both readable, each with its
 *   author's name; her reply is sent and its notice reads at 4.5 to 1; pictures of the list, the confirm card and the thread are saved at
 *   desktop and phone width.
 * Fails when: the dark message centre keeps white rows, the pill's grey cannot be read (OmnaBase's "resolved" grey reads at 2.1 to 1 on
 *   white), the visitor's and the team's messages look the same (or swapped), or the prompt stays the plugin's own box.
 */
test("the message centre in light and dark: list, prompt, thread and reply", async ({ browser }) => {
  for (const [label, theme] of [["Light", "light"], ["Dark", "dark"]] as const) {
    // Builder's adjustment (2026-10-22, DECISIONS): OmnaBase's blue is chosen here, as the form's flow does; the flow before this one leaves
    // the owner's colour on Black, and the prompt's button rightly wore it, so the check against the blue token failed for that reason only.
    await setAppearance({ theme: label, accent: "blue" });
    for (const [size, suffix] of [[DESKTOP, "desktop"], [PHONE, "phone"]] as const) {
      await lia.setViewportSize(size);
      await openCentre(lia);
      const wrap = wrapper(lia);
      await expect(wrap).toHaveAttribute("data-theme", theme);
      expect(`rgb(${(await colours(wrap)).bg.join(", ")})`, `${theme}: the surface`).toBe(token("--ob-bg-surface", theme));
      const subject = lia.locator(`a[href*="omnabase_ticket=${ticket}"]`).first();
      await expectReadable(subject, `${theme}: the subject`);
      const row = lia.locator("li").filter({ has: subject }).first();
      const pill = row.getByText(/^(Open|Answered|Resolved|Closed)$/).first();
      await expect(pill, `${theme}: the status in words`).toBeVisible();
      await expectReadable(pill, `${theme}: the pill`);
      const date = row.getByText(/^\s*\d{1,2} \S+ \d{4}\s*$/).first();
      await expectReadable(date, `${theme}: the date`);
      await expectFocusRing(subject, `${theme}: the subject link`);
      const confirm = lia.locator(".ob-portal-confirm");
      await expect(confirm, `${theme}: the kit's confirm card`).toBeVisible();
      const cc = await confirm.evaluate((e) => { const s = getComputedStyle(e); return { bg: s.backgroundColor, border: s.borderTopColor }; });
      expect(cc.bg, `${theme}: the card on the surface`).toBe(token("--ob-bg-surface", theme));
      expect(cc.border, `${theme}: the card's border`).toBe(token("--ob-border", theme));
      expect((await colours(prompt(lia))).background, `${theme}: its button is primary`).toBe(token("--ob-btn-primary-bg", theme));
      await shot(lia, `messages-list-${theme}-${suffix}`);
      await shot(lia, `messages-confirm-${theme}-${suffix}`, confirm);

      await openCentre(lia, `?omnabase_ticket=${ticket}`);
      const mine = lia.locator(".ob-wp-messages__message--you").first();
      const team = lia.locator(".ob-wp-messages__message--team").filter({ hasText: ANSWER }).first();
      await expect(mine).toBeVisible();
      await expect(team, `${theme}: the team's answer`).toBeVisible();
      const [m, t] = [await colours(mine), await colours(team)];
      expect(m.own[3] > 0.5 ? `rgb(${m.own.slice(0, 3).join(", ")})` : `rgb(${m.bg.join(", ")})`, `${theme}: Lia's message and the team's look different`).not.toBe(t.own[3] > 0.5 ? `rgb(${t.own.slice(0, 3).join(", ")})` : `rgb(${t.bg.join(", ")})`);
      expect(`rgb(${t.bg.join(", ")})`, `${theme}: the team's answer on the surface`).toBe(token("--ob-bg-surface", theme));
      await expectReadable(mine.getByText(/slider does not move/), `${theme}: Lia's words`);
      await expectReadable(team.getByText(ANSWER), `${theme}: the team's words`);
      await expect(mine, `${theme}: Lia's message names her ("You", the API's word)`).toContainText("You");
      await shot(lia, `messages-thread-${theme}-${suffix}`);
    }
    await lia.setViewportSize(DESKTOP);
    const words = `Thanks, ${theme} ${run}.`;
    await replyForm(lia).locator("textarea").fill(words);
    await replyForm(lia).locator('[type="submit"], button:not([type])').first().click();
    await expect(lia.getByText(words).first()).toBeVisible();
    const notice = lia.getByText("Your answer was sent.").first();
    await expect(notice).toBeVisible();
    await expectReadable(notice, `${theme}: the notice`);
  }
});

/**
 * What it checks: On a 375px phone both shortcodes fit: nothing scrolls sideways and every control is fully on screen, in light and dark.
 * Passes when: at 375px wide, in Light and Dark, the form's page, the message centre's list and its thread pass OmnaBase's layout rules (the
 *   page and its body no wider than the screen, every button, link, field and select of the plugin's output within the screen, the
 *   honeypot's hidden part excused), and the wrapper lies within the screen; the success card on a phone fits too.
 * Fails when: a long subject, the pill or the reply box pushes the page sideways under the customer's thumb, or Send ends off screen.
 */
test("on a phone both shortcodes fit, in light and dark", async ({ browser }) => {
  for (const theme of ["Light", "Dark"] as const) {
    await setAppearance({ theme });
    const page = await visitor(browser, PHONE);
    await expectSoundLayout(page, `${theme}: the form`);
    await fill(page, { name: "Pia Phone", email: `pia.${theme}.${run}@example.org`, subject: `Phone ${theme} ${run}`, message: "From my phone." });
    await expect(card(page)).toBeVisible();
    await expectSoundLayout(page, `${theme}: the success card`);
    await shot(page, `form-done-${theme.toLowerCase()}-phone`);
    await lia.setViewportSize(PHONE);
    await openCentre(lia);
    await expectSoundLayout(lia, `${theme}: the message centre's list`);
    await openCentre(lia, `?omnabase_ticket=${ticket}`);
    await expectSoundLayout(lia, `${theme}: the thread`);
    await lia.setViewportSize(DESKTOP);
  }
});
