/**
 * The plugin's forms round (2026-10-25), the red team's failing flows: the Appearance tab's look on a form built in OmnaBase and drawn by
 * OmnaBase's embed, in a real browser, in light and in dark. They replace the Classic form's look flows in frontend-look.spec.ts (the builder
 * removes those; the message centre's flows there stay). Pictures go to test-results/frontend-look/ for the founder's eye, never compared.
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`) outcome 3: "The Appearance tab's look applies: light, dark or following the device, the
 * colour and the font, passed to the embed as CSS variables"; "Tests": "`frontend-look.spec.ts` for the Appearance variables". The plugin's own
 * rules (its CLAUDE.md): text reads at 4.5 to 1 and focus rings at 3 to 1 in both themes; at 375 px nothing scrolls sideways. Report findings 8
 * and 9: the look reaches the embed through the plugin's stylesheet under the wrapper (so "following the device" works), and the embed draws its
 * focus ring in its accent colour, which may need a focus variable of its own on OmnaBase's side.
 *
 * The words: *embed*, OmnaBase's script that draws the form inside the plugin's container; *computed style*, the value the browser finally uses;
 * *token*, one of OmnaBase's named design values (`packages/ui/src/styles/tokens.css`, light block and dark block); *contrast*, WCAG's
 * brightness ratio (4.5 to 1 for text, 3 to 1 for a focus ring); *focus ring*, the outline that shows a keyboard user where they are.
 *
 * Choices this file makes (the builder may change them and tell the red team): the Appearance tab's labels as `AppearanceTestCase`; the
 * embed's controls found by their labels inside the container; the embed's Send button carries the kit's primary button colour
 * (`--ob-btn-primary-bg`, the owner's colour when one is chosen) with its text colour; a field's error found through `aria-describedby`.
 *
 * Shared-seed rules this file keeps: it puts the Appearance choices back to the defaults, archives its form, trashes its page, disconnects the
 * site and resets the relay.
 *
 * Why this fails today: the shortcode prints the Classic form, so no embed draws anything and no container is found.
 */
import { mkdirSync, readFileSync, statSync } from "node:fs";
import { resolve } from "node:path";
import type { Browser, BrowserContext, Locator, Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, REPO, SITE, SITE_HOST, WP_LOCAL, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
const SHOTS = resolve(REPO, "test-results/frontend-look");
const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1280, height: 900 };
const run = Date.now().toString(36);
const SLUG = `wp-look-${run}`;
const TEAL = "#0f7c8a";
const PALE = "#f5e663";
const INK = "#1a1a1a";

type Theme = "light" | "dark";
type Rgb = [number, number, number];
type Choice = { theme?: "Light" | "Dark" | "Follow the visitor's system"; accent?: "blue" | string; font?: "inherit" | "load"; themeOnly?: boolean };

test.describe.configure({ mode: "serial" });

/* ---------- OmnaBase's live tokens ---------- */

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
function tokenRgb(name: string, theme: Theme): Rgb {
  const vars: Record<string, string> = theme === "dark" ? DARK_VARS : LIGHT_VARS;
  let v = vars[name] ?? "";
  for (let i = 0; i < 20 && /^var\(/.test(v); i++) v = vars[/var\(\s*(--ob-[a-z0-9-]+)/.exec(v)![1]!] ?? "";
  const hex = /^#([0-9a-f]{6})$/i.exec(v)?.[1];
  if (!hex) throw new Error(`${name} does not resolve to a plain colour in ${theme}: ${v}`);
  return [0, 2, 4].map((i) => parseInt(hex.slice(i, i + 2), 16)) as Rgb;
}
const hexRgb = (hex: string): Rgb => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)) as Rgb;

/* ---------- colours in the page ---------- */

/** an element's text colour, its own background and the background really behind it, read through a canvas */
async function colours(el: Locator): Promise<{ fg: Rgb; own: [number, number, number, number]; bg: Rgb; color: string }> {
  return el.evaluate((node) => {
    const ctx = document.createElement("canvas").getContext("2d", { willReadFrequently: true })!;
    const read = (v: string): [number, number, number, number] => {
      ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = "rgba(0, 0, 0, 0)"; ctx.fillStyle = v; ctx.fillRect(0, 0, 1, 1);
      const d = ctx.getImageData(0, 0, 1, 1).data; return [d[0]!, d[1]!, d[2]!, d[3]! / 255];
    };
    const s = getComputedStyle(node as Element);
    let behind: [number, number, number, number] = [255, 255, 255, 1];
    for (let n: Element | null = node as Element; n; n = n.parentElement) {
      const c = read(getComputedStyle(n).backgroundColor);
      if (c[3] > 0.5) { behind = c; break; }
    }
    const fg = read(s.color);
    return { fg: [fg[0], fg[1], fg[2]], own: read(s.backgroundColor), bg: [behind[0], behind[1], behind[2]], color: s.color };
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
const near = (a: Rgb, b: Rgb) => a.every((v, i) => Math.abs(v - b[i]!) <= 2);

async function expectReadable(el: Locator, what: string, min = 4.5): Promise<void> {
  const c = await colours(el);
  const ratio = contrast(c.fg, c.bg);
  expect(ratio, `${what}: ${c.color} on rgb(${c.bg.join(", ")}) reads at ${ratio.toFixed(2)} to 1`).toBeGreaterThanOrEqual(min);
}

/** a focused control shows a ring (outline or box-shadow) that stands out 3 to 1 from what is behind the control */
async function expectFocusRing(el: Locator, what: string): Promise<void> {
  await el.focus();
  await el.page().keyboard.press("Shift+Tab");
  await el.page().keyboard.press("Tab");
  await expect(el, `${what}: has the keyboard focus`).toBeFocused();
  const ring = await el.evaluate((node) => {
    const s = getComputedStyle(node as Element);
    const width = parseFloat(s.outlineWidth) || 0;
    const shadow = s.boxShadow !== "none" ? (/rgba?\([^)]*\)|oklab\([^)]*\)|color\([^)]*\)/.exec(s.boxShadow)?.[0] ?? "") : "";
    const colour = s.outlineStyle !== "none" && width > 0 ? s.outlineColor : shadow;
    if (!colour) return null;
    const ctx = document.createElement("canvas").getContext("2d", { willReadFrequently: true })!;
    ctx.fillStyle = colour; ctx.fillRect(0, 0, 1, 1);
    const d = ctx.getImageData(0, 0, 1, 1).data; return { colour, rgb: [d[0]!, d[1]!, d[2]!] as [number, number, number] };
  });
  expect(ring, `${what}: a visible focus ring`).not.toBeNull();
  const behind = (await colours(el.locator("xpath=.."))).bg;
  expect(contrast(ring!.rgb, behind), `${what}: the ring (${ring!.colour}) stands out 3 to 1`).toBeGreaterThanOrEqual(3);
}

/* ---------- the site ---------- */

const php = (code: string): string => wp("eval", code).trim();
let key = "";
let enfoldId = "";
let formId = "", pageId = "", pageUrl = "";
let admin: Page; let adminContext: BrowserContext;
let founderContext: BrowserContext;
const contexts: BrowserContext[] = [];

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

/** a visitor on the form's page, once the embed drew the form, at a size, in a device colour scheme */
async function visitor(browser: Browser, size = DESKTOP, scheme: "light" | "dark" = "light"): Promise<{ page: Page; box: Locator }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] }, viewport: size, colorScheme: scheme });
  await routeSite(context);
  contexts.push(context);
  const page = await context.newPage();
  await page.goto(pageUrl);
  const box = page.locator(`[data-omnabase-form="kriesi/${SLUG}"]`);
  await expect(box, "the embed drew the form").toHaveAttribute("data-omnabase-ready", "ready");
  return { page, box };
}

async function shot(page: Page, name: string, el?: Locator): Promise<void> {
  mkdirSync(SHOTS, { recursive: true });
  const path = resolve(SHOTS, `${name}.png`);
  if (el) await el.screenshot({ path });
  else await page.screenshot({ path, fullPage: true });
  expect(statSync(path).size, path).toBeGreaterThan(1000);
}

/** OmnaBase's layout rules: nothing scrolls sideways, every control of the form fully on screen (the embed's hidden trap excused) */
async function expectSoundLayout(page: Page, what: string): Promise<void> {
  const m = await page.evaluate(() => {
    const doc = document.documentElement;
    const offscreen = Array.from(document.querySelectorAll<HTMLElement>("[data-omnabase-form] button, [data-omnabase-form] input, [data-omnabase-form] textarea, [data-omnabase-form] select")).filter((e) => {
      const r = e.getBoundingClientRect();
      if (e.closest("[aria-hidden='true'], [hidden]") || getComputedStyle(e).visibility === "hidden" || e.getAttribute("type") === "hidden") return false;
      return r.width > 0 && r.height > 0 && (r.right > window.innerWidth + 1 || r.left < -1);
    }).map((e) => (e.getAttribute("name") ?? e.textContent ?? "").trim().slice(0, 40));
    return { docScroll: doc.scrollWidth, bodyScroll: document.body.scrollWidth, width: window.innerWidth, offscreen };
  });
  expect(m.docScroll, `${what}: the page does not scroll sideways`).toBeLessThanOrEqual(m.width);
  expect(m.bodyScroll, `${what}: nor does its body`).toBeLessThanOrEqual(m.width);
  expect(m.offscreen, `${what}: every control is fully on screen`).toEqual([]);
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear(); delete_option( 'omnabase_test_offline' );");
  resetFormState();
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  enfoldId = (await publicApi<{ projects: { id: string }[] }>(key, "GET", "/me")).body.projects[0]!.id;
  await founderSignsIn(browser);
  const made = await founderContext.request.post(`${OMNABASE_E2E}/api/v1/w/kriesi/forms`, { data: { name: `WP look ${run}` } });
  expect(made.ok(), `the form made (${made.status()})`).toBe(true);
  formId = ((await made.json()) as { id: string }).id;
  const items = [
    { key: "email", type: "email", label: "Email", required: true, mapping: "email", help: "We answer to this address." },
    { key: "full_name", type: "text", label: "Your name", required: false, mapping: "name" },
    { key: "topic", type: "select", label: "Topic", required: false, options: [{ value: "billing", label: "Billing" }, { value: "bug", label: "A bug" }] },
    { key: "message", type: "textarea", label: "Message", required: true, mapping: "message" },
    { key: "consent", type: "checkbox", label: "I agree to the privacy policy", required: true },
  ];
  const saved = await founderContext.request.patch(`${OMNABASE_E2E}/api/v1/w/kriesi/forms/${formId}`, { data: { slug: SLUG, fields: { v: 1, items }, defaultProjectId: enfoldId, published: true } });
  expect(saved.ok(), `the form saved (${saved.status()}: ${await saved.text()})`).toBe(true);
  pageId = php(`echo wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Form look ${run}', 'post_content' => '[omnabase_form id="${SLUG}"]' ) );`);
  pageUrl = php(`echo get_permalink( ${pageId} );`).replace(/^https?:\/\/[^/]+/, SITE);
  adminContext = await browser.newContext({ storageState: ADMIN_STATE });
  await routeSite(adminContext);
  admin = await adminContext.newPage();
});

test.beforeEach(() => resetFormState());

test.afterAll(async () => {
  try { if (admin) await setAppearance({ theme: "Light", accent: "blue", font: "inherit", themeOnly: false }); } catch { /* the plugin may be half built */ }
  try { php(`wp_trash_post( (int) ${JSON.stringify(pageId || "0")} );`); } catch { /* idem */ }
  try { resetFormState(); } catch { /* idem */ }
  if (founderContext && formId) { try { await founderContext.request.post(`${OMNABASE_E2E}/api/v1/w/kriesi/forms/${formId}/archive`); } catch { /* idem */ } }
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  for (const c of contexts) await c.close();
  await adminContext?.close();
  await founderContext?.close();
});

/**
 * What it checks: The form built in OmnaBase wears OmnaBase's colours on the site, light and dark as the owner chose, and reads well for
 *   everyone, keyboard users included.
 * Passes when: in Light and in Dark the Send button has the kit's primary button colour of that theme and readable words on it (4.5 to 1); a
 *   label, a help line and the text typed into a field read at 4.5 to 1; in Dark the fields are dark (no white boxes on a night page); a field
 *   reached by the keyboard shows a ring standing out 3 to 1; pictures of both go to test-results/frontend-look/.
 * Fails when: the owner picks Dark and gets white fields, the Send button's words cannot be read, or a keyboard user cannot see where they are.
 */
test("the embedded form in light and dark wears OmnaBase's colours and reads well", async ({ browser }) => {
  for (const theme of ["light", "dark"] as Theme[]) {
    await setAppearance({ theme: theme === "light" ? "Light" : "Dark", accent: "blue", font: "inherit", themeOnly: false });
    const { page, box } = await visitor(browser);
    const send = box.getByRole("button", { name: "Send" });
    const button = await colours(send);
    expect(near([button.own[0], button.own[1], button.own[2]], tokenRgb("--ob-btn-primary-bg", theme)), `${theme}: Send wears the primary button colour (got rgb(${button.own.slice(0, 3).join(", ")}))`).toBe(true);
    expect(contrast(button.fg, [button.own[0], button.own[1], button.own[2]]), `${theme}: Send's words read on it`).toBeGreaterThanOrEqual(4.5);
    await expectReadable(box.getByText("Your name", { exact: true }), `${theme}: a label`);
    await expectReadable(box.getByText("We answer to this address."), `${theme}: a help line`);
    const email = box.getByLabel("Email");
    await email.fill("reader@example.org");
    const field = await colours(email);
    expect(contrast(field.fg, [field.own[0], field.own[1], field.own[2]].map((v, i) => (field.own[3] > 0.5 ? v : field.bg[i]!)) as Rgb), `${theme}: typed text reads in its field`).toBeGreaterThanOrEqual(4.5);
    if (theme === "dark") expect(luminance(field.own[3] > 0.5 ? [field.own[0], field.own[1], field.own[2]] : field.bg), "dark: the field is dark").toBeLessThan(0.2);
    await expectFocusRing(box.getByLabel("Your name"), `${theme}: a field's focus ring`);
    await shot(page, `form-embed-${theme}-desktop`, box);
  }
});

/**
 * What it checks: "Follow the visitor's system" turns the embedded form dark on a dark device and light on a light one.
 * Passes when: with that choice saved, a visitor whose device is dark sees dark fields (low brightness) and one whose device is light sees light
 *   fields (high brightness).
 * Fails when: the system choice is ignored because the colours were written once onto the container, so night-mode visitors get a white form.
 */
test("the system choice follows the visitor's device", async ({ browser }) => {
  await setAppearance({ theme: "Follow the visitor's system", accent: "blue", font: "inherit", themeOnly: false });
  const fieldBrightness = async (scheme: "light" | "dark") => {
    const { box } = await visitor(browser, DESKTOP, scheme);
    const c = await colours(box.getByLabel("Email"));
    return luminance(c.own[3] > 0.5 ? [c.own[0], c.own[1], c.own[2]] : c.bg);
  };
  expect(await fieldBrightness("dark"), "a dark device: dark fields").toBeLessThan(0.2);
  expect(await fieldBrightness("light"), "a light device: light fields").toBeGreaterThan(0.6);
});

/**
 * What it checks: A field left wrong shows its error under it, tied to it for screen readers, readable in both themes.
 * Passes when: sending the empty form marks Email invalid, and the error line Email names (aria-describedby) is visible with words reading at
 *   4.5 to 1, in Light and in Dark.
 * Fails when: a customer cannot read what to fix, or a screen reader user never hears it.
 */
test("a wrong field shows its error under it, in both themes", async ({ browser }) => {
  for (const theme of ["light", "dark"] as Theme[]) {
    await setAppearance({ theme: theme === "light" ? "Light" : "Dark" });
    const { box } = await visitor(browser);
    await box.getByRole("button", { name: "Send" }).click();
    const email = box.getByLabel("Email");
    await expect(email).toHaveAttribute("aria-invalid", "true");
    const ids = ((await email.getAttribute("aria-describedby")) ?? "").split(/\s+/).filter(Boolean);
    const errorLine = box.locator(ids.map((id) => `#${id}`).join(", ")).filter({ hasText: /\S/ }).last();
    await expect(errorLine).toBeVisible();
    await expectReadable(errorLine, `${theme}: the error`);
  }
});

/**
 * What it checks: The owner's own colour reaches the Send button, with the words in white or ink as OmnaBase's app would choose.
 * Passes when: with teal (#0f7c8a) Send is teal with words reading at 4.5 to 1; with pale yellow (#f5e663) Send is that yellow with ink
 *   (#1a1a1a) words.
 * Fails when: the owner's colour stops at the plugin's own pieces, or pale colours get white words nobody can read.
 */
test("the owner's colour reaches Send with readable words", async ({ browser }) => {
  for (const [accent, words] of [[TEAL, null], [PALE, INK]] as [string, string | null][]) {
    await setAppearance({ theme: "Light", accent });
    const { box } = await visitor(browser);
    const c = await colours(box.getByRole("button", { name: "Send" }));
    const own: Rgb = [c.own[0], c.own[1], c.own[2]];
    expect(near(own, hexRgb(accent)), `Send wears ${accent} (got rgb(${own.join(", ")}))`).toBe(true);
    expect(contrast(c.fg, own), `${accent}: Send's words read`).toBeGreaterThanOrEqual(4.5);
    if (words) expect(near(c.fg, hexRgb(words)), `${accent}: ink words`).toBe(true);
  }
});

/**
 * What it checks: "Theme styles only" keeps the plugin's stylesheet away from the embedded form, which still draws in the embed's own plain look.
 * Passes when: with that switch saved, the page links no stylesheet from the plugin's folder and the embed still draws the form with its Send
 *   button; with it off again, the plugin's form stylesheet is back.
 * Fails when: the switch breaks the form, or the plugin's colours still override a theme the owner wanted alone.
 */
test("theme styles only leaves the embed its own look", async ({ browser }) => {
  await setAppearance({ theme: "Light", accent: "blue", font: "inherit", themeOnly: true });
  const off = await visitor(browser);
  await expect(off.page.locator('link[rel="stylesheet"][href*="/omnabase/assets/"]')).toHaveCount(0);
  await expect(off.box.getByRole("button", { name: "Send" })).toBeVisible();
  await setAppearance({ themeOnly: false });
  const on = await visitor(browser);
  await expect(on.page.locator('link[rel="stylesheet"][href*="/omnabase/assets/form.css"]')).toHaveCount(1);
});

/**
 * What it checks: OmnaBase's font, when the owner loads it, reaches the embedded form; otherwise the form speaks in the theme's font.
 * Passes when: with "Load OmnaBase's font" the font's stylesheet is linked and a label of the form uses the same typeface as the plugin's
 *   wrapper; with "Inherit the theme's font" no font stylesheet is linked and the label uses the page body's typeface.
 * Fails when: the owner loads OmnaBase's font and the form keeps the theme's, or the theme's font is replaced without being asked.
 */
test("the font follows the owner's choice", async ({ browser }) => {
  await setAppearance({ theme: "Light", font: "load", themeOnly: false });
  const loaded = await visitor(browser);
  await expect(loaded.page.locator('link[rel="stylesheet"][href*="/omnabase/assets/fonts.css"]')).toHaveCount(1);
  const fonts = await loaded.box.evaluate((box) => ({ label: getComputedStyle(box.querySelector("label")!).fontFamily, wrapper: getComputedStyle(box.closest(".ob-wp")!).fontFamily }));
  expect(fonts.label, "the label in the wrapper's typeface").toBe(fonts.wrapper);

  await setAppearance({ font: "inherit" });
  const inherited = await visitor(browser);
  await expect(inherited.page.locator('link[rel="stylesheet"][href*="/omnabase/assets/fonts.css"]')).toHaveCount(0);
  const theme = await inherited.box.evaluate((box) => ({ label: getComputedStyle(box.querySelector("label")!).fontFamily, body: getComputedStyle(document.body).fontFamily }));
  expect(theme.label, "the label in the theme's typeface").toBe(theme.body);
});

/**
 * What it checks: On a 375px phone the embedded form fits, in light and dark.
 * Passes when: in Light and in Dark nothing on the page scrolls sideways and every control of the form is fully on screen; pictures go to
 *   test-results/frontend-look/.
 * Fails when: phone visitors must scroll sideways to reach Send, or a field runs off the screen.
 */
test("on a phone the embedded form fits, in light and dark", async ({ browser }) => {
  for (const theme of ["light", "dark"] as Theme[]) {
    await setAppearance({ theme: theme === "light" ? "Light" : "Dark", accent: "blue", font: "inherit", themeOnly: false });
    const { page } = await visitor(browser, PHONE);
    await expectSoundLayout(page, `${theme} phone`);
    await shot(page, `form-embed-${theme}-phone`);
  }
});
