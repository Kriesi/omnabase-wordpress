/**
 * A browser for clicking through the local WordPress by hand: Playwright's Chromium with the browser flows' route (every request for
 * https://wp.omnabase.test goes to wp-env at http://localhost:8888 as a TLS front would hand it on; tests/e2e/harness.ts), so no port
 * 443, no certificate and no change to the Mac's network (LocalWP's router keeps 80 and 443). The profile lives under
 * tests/e2e/.auth/browser (git-ignored), so logins survive a restart. While the window is open the terminal prints every mail OmnaBase's
 * local e2e server captures (sign-in codes, invitations, the message centre's confirmation links, replies to customers): the e2e server
 * sends nothing anywhere. Started by bin/local.sh; closes with its last window.
 *
 *   node bin/local-browser.ts [--connect] [url]   the window, opened at the plugin's settings page unless an address is given; a
 *                                                 wp-login page is filled with wp-env's admin / password (a throwaway local install);
 *                                                 --connect also clicks Connect and approves on OmnaBase as the seeded founder
 *   node bin/local-browser.ts --check [file]  no window: opens the settings page through the route, prints its state, writes a screenshot
 *
 * Needs the e2e stack (bin/local.sh stack) and wp-env. Node 22 runs the TypeScript directly.
 */
import { chromium, type BrowserContext, type Page } from "@playwright/test";
import { mkdirSync } from "node:fs";
import { resolve } from "node:path";
import { OMNABASE_APP, OMNABASE_E2E, REPO, SITE, SITE_HOST, routeSite } from "../tests/e2e/harness.ts";

const SETTINGS = `${SITE}/wp-admin/options-general.php?page=omnabase`;
/** the addresses whose captured mails are shown: OmnaBase's seeded founder, wp-env's administrator and the seed's people; add more with MAIL_TO=a,b */
const WATCHED = ["kriesi@example.test", "wordpress@example.com", "ada@example.com", "ben@example.com", "mike@example.test", "nina@example.com", "support@wp.omnabase.test", ...(process.env.MAIL_TO ?? "").split(",").map((s) => s.trim()).filter(Boolean)];
const PROFILE = resolve(REPO, "tests/e2e/.auth/browser");
const FOUNDER = "kriesi@example.test"; // Team Kriesi's seeded owner

type Captured = { subject: string; text: string };
type Raw = { raw: string; destinations: string[]; messageId: string };

/** one line per new captured mail: who, the subject, a six-digit code or the links in it */
function describe(to: string, subject: string, text: string): string {
  const code = /(\d{3}) (\d{3})/.exec(text);
  const links = [...new Set((text.match(/https?:\/\/[^\s<>"')]+/g) ?? []))].slice(0, 4);
  const detail = code ? `code ${code[1]} ${code[2]}` : links.length ? links.join("\n      ") : text.replace(/\s+/g, " ").slice(0, 160);
  return `✉  ${to}: "${subject}"\n      ${detail}`;
}

/** polls the e2e server's capture: the last plain mail per watched address and every raw (customer) mail; prints each once */
function watchMail(): () => void {
  const seenPlain = new Map<string, string>();
  const seenRaw = new Set<string>();
  const silent = new Set<string>(); // first pass: what was captured before the window opened stays quiet
  let first = true;
  const tick = async () => {
    try {
      for (const to of WATCHED) {
        const res = await fetch(`${OMNABASE_E2E}/api/test/mail?to=${encodeURIComponent(to)}`);
        const m = res.ok ? ((await res.json()) as Captured | null) : null;
        if (!m) continue;
        const fingerprint = `${m.subject}\n${m.text}`;
        if (seenPlain.get(to) === fingerprint) continue;
        seenPlain.set(to, fingerprint);
        if (!first) console.log(describe(to, m.subject, m.text));
      }
      const res = await fetch(`${OMNABASE_E2E}/api/test/outbox`);
      const raws = res.ok ? ((await res.json()) as Raw[]) : [];
      for (const r of raws) {
        if (seenRaw.has(r.messageId)) continue;
        seenRaw.add(r.messageId);
        if (first) { silent.add(r.messageId); continue; }
        const subject = /^Subject: (.*)$/im.exec(r.raw)?.[1] ?? "(no subject)";
        console.log(describe(r.destinations.join(", "), subject, r.raw.replace(/=\r?\n/g, "")));
      }
    } catch { /* the e2e server is away: tried again next time */ }
    first = false;
  };
  // the e2e server has no worker of its own: the flows tick it by hand, so this window ticks the two jobs a click-through needs every
  // 5 seconds: webhooks (OmnaBase's calls to the site: a reply reaching the forum or the message centre) and the mail sender
  const work = async () => {
    for (const job of ["webhooks", "sender"]) {
      try { await fetch(`${OMNABASE_E2E}/api/test/tick`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ job }) }); } catch { /* next time */ }
    }
  };
  void tick();
  const timer = setInterval(() => void tick(), 1500);
  const worker = setInterval(() => void work(), 5000);
  return () => { clearInterval(timer); clearInterval(worker); };
}

/** the last sign-in code mailed to the address, once it differs from what was there before (the capture keeps older codes) */
async function freshCode(to: string, before: string | null): Promise<string> {
  for (let i = 0; i < 100; i++) {
    const res = await fetch(`${OMNABASE_E2E}/api/test/mail?to=${encodeURIComponent(to)}`);
    const m = res.ok ? ((await res.json()) as Captured | null) : null;
    const hit = m && m.text !== before ? /(\d{3}) (\d{3})/.exec(m.text) : null;
    if (hit) return hit[1]! + hit[2]!;
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error(`no new code in the mail to ${to}`);
}
async function lastMailText(to: string): Promise<string | null> {
  const res = await fetch(`${OMNABASE_E2E}/api/test/mail?to=${encodeURIComponent(to)}`);
  return res.ok ? (((await res.json()) as Captured | null)?.text ?? null) : null;
}

/** a wp-login page is filled with wp-env's documented default administrator (a throwaway local install) */
async function signInWp(page: Page): Promise<void> {
  if (!page.url().includes("wp-login.php")) return;
  await page.locator("#user_login").fill(process.env.WP_ADMIN_USER ?? "admin");
  await page.locator("#user_pass").fill(process.env.WP_ADMIN_PASSWORD ?? "password");
  await page.locator("#wp-submit").click();
  await page.waitForURL((u) => !u.pathname.startsWith("/wp-login.php"));
  await page.goto(SETTINGS);
  console.log("Signed in to wp-admin as admin.");
}

/** Connect from the settings page: the connect page, the founder's code, Team Kriesi / Enfold, Approve, back on the site */
async function connect(page: Page): Promise<void> {
  const box = page.locator(".ob-wp-settings__connection");
  if ((await box.count()) && (await box.getAttribute("data-state")) === "connected") { console.log("Already connected."); return; }
  const before = await lastMailText(FOUNDER);
  await page.getByRole("button", { name: "Connect", exact: true }).click();
  await page.waitForURL((u) => u.toString().startsWith(`${OMNABASE_APP}/connect`));
  await page.getByRole("heading", { name: SITE_HOST }).waitFor({ timeout: 90_000 }); // Vite compiles the page on its first load: slow
  const email = page.getByRole("textbox", { name: "Email" });
  if (await email.isVisible({ timeout: 5_000 }).catch(() => false)) {
    await email.fill(FOUNDER);
    await page.getByRole("button", { name: "Send me a code" }).click();
    await page.getByRole("textbox", { name: "Code" }).fill(await freshCode(FOUNDER, before));
    await page.getByRole("button", { name: "Continue" }).click();
  }
  await page.getByLabel("Workspace").selectOption({ label: "Team Kriesi" });
  await page.getByLabel("Project").selectOption({ label: "Enfold" });
  await page.getByRole("button", { name: /^Approve/ }).click();
  await page.waitForURL((u) => u.hostname === SITE_HOST);
  await box.waitFor();
  console.log(`Connect done: connection ${await box.getAttribute("data-state")}.`);
}

/** the plugin's settings page through the route: the connection's state as the page marks it */
async function settingsState(page: Page): Promise<string> {
  await page.goto(SETTINGS);
  if (page.url().includes("wp-login.php")) return "signed out (wp-login.php)";
  const box = page.locator(".ob-wp-settings__connection");
  return (await box.count()) ? `settings page, connection ${await box.getAttribute("data-state")}` : `unexpected page: ${await page.title()}`;
}

async function check(file: string): Promise<void> {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  await routeSite(context);
  const page = await context.newPage();
  console.log(await settingsState(page));
  mkdirSync(resolve(file, ".."), { recursive: true });
  await page.screenshot({ path: file, fullPage: true });
  console.log(`screenshot: ${file}`);
  await browser.close();
}

async function open(url: string, doConnect: boolean): Promise<void> {
  mkdirSync(PROFILE, { recursive: true });
  const headless = process.env.LOCAL_HEADLESS === "1"; // a smoke test without a window
  const context: BrowserContext = await chromium.launchPersistentContext(PROFILE, headless ? { headless: true, viewport: { width: 1280, height: 900 } } : { headless: false, viewport: null, args: ["--window-size=1440,980"] });
  await routeSite(context);
  const page = context.pages()[0] ?? (await context.newPage());
  const stopWatching = watchMail();
  console.log(`Browser open at ${url}\nwp-admin: admin / password (wp-env's default) · OmnaBase's connect page signs in by a code printed here · close the window to end`);
  context.on("close", () => { stopWatching(); process.exit(0); });
  await page.goto(url);
  await signInWp(page);
  if (doConnect) await connect(page).catch((e: Error) => console.error(`Connect by script failed (the window stays open; finish it by hand): ${e.message.split("\n")[0]}`));
}

const args = process.argv.slice(2);
if (args[0] === "--check") await check(args[1] ?? resolve(REPO, "build/local-check.png"));
else await open(args.find((a) => !a.startsWith("--")) ?? SETTINGS, args.includes("--connect"));
