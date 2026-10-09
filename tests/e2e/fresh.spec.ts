/**
 * M13 round six, the red team's failing flow: the zip on a WordPress that has never seen the plugin, the way a customer installs it. An
 * administrator uploads the zip under Plugins › Add New › Upload Plugin, activates it, finds Settings › OmnaBase with Connect, and the site
 * keeps working; then deletes it from the Plugins screen, and nothing of the plugin is left in the database.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) "Round six": "the zip installed into a clean wp-env and smoke-run";
 * "uninstall verified". The coordinator: "`fresh.spec.ts`: wp-env's second site (8889) or a reset of the first: install the built zip
 * through wp-admin's upload, activate, see Settings › OmnaBase with Connect; nothing else broken — read what wp-env allows".
 *
 * What wp-env allows, read (README of @wordpress/env 11): both of today's sites map this repository as `wp-content/plugins/omnabase` (the
 * tests site too: mappings apply to both), so uploading the zip there would ask to replace the plugin's folder (this checkout), and deleting
 * it would delete the source code. wp-env 11 runs parallel environments from one folder with `--config <file>` (its own containers and
 * database). So the fresh site is a third one: `.wp-env.fresh.json`, port 8890, PHP 8.1, no plugins and no mappings (the structure guard pins
 * it), started by this file when it does not answer, its database reset with `wp-env clean all --force` and any earlier upload deleted
 * before the run. The file refuses to run if that config maps anything into wp-content/plugins/omnabase or maps this folder. Connect is only
 * offered on https with pretty permalinks (round one), as a customer's real site has them: the fresh site maps the harness's must-use plugin
 * alone (`tests/wp-env/mu-plugins`, never the plugin) with `OMNABASE_TEST_MODE` and `OMNABASE_TEST_HOST: "fresh.omnabase.test"`, the
 * browser opens https://fresh.omnabase.test (routed to localhost:8890 as harness.ts routes wp.omnabase.test), and the run sets pretty
 * permalinks after the reset.
 *
 * The words: *upload*, WordPress's own installer for a plugin zip; *activate*, turning an installed plugin on; *uninstall*, what WordPress
 * runs when an administrator deletes a plugin (the plugin's uninstall.php).
 *
 * Choices this file makes (the builder may change them and tell the red team): the zip is today's development zip (`bin/build-dist.sh`,
 * the same files as the release zip); nothing here talks to OmnaBase; a PHP warning, notice, deprecation or fatal error printed in any page counts as broken (wp-env shows them).
 *
 * Why this fails today: there is no `.wp-env.fresh.json`.
 */
import type { BrowserContext, Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { existsSync, readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test } from "./fixtures";
import { REPO } from "./harness";

test.describe.configure({ mode: "serial", timeout: 600_000 });

const CONFIG = ".wp-env.fresh.json";
const FRESH_HOST = "fresh.omnabase.test";
const FRESH = `https://${FRESH_HOST}`;
const FRESH_LOCAL = process.env.WP_FRESH_URL ?? "http://localhost:8890";
const BROKEN = /Fatal error|Parse error|Warning:|Notice:|Deprecated:|There has been a critical error/;
let zip = "";

const run = (cmd: string, args: string[]): string => execFileSync(cmd, args, { cwd: REPO, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"], maxBuffer: 64 * 1024 * 1024 });
const freshWp = (...args: string[]): string => run("npx", ["wp-env", "run", `--config=${CONFIG}`, "cli", "--", "wp", ...args]);

/** the fresh site's config, refused when it could reach this checkout */
function safeConfig(): void {
  const path = resolve(REPO, CONFIG);
  if (!existsSync(path)) throw new Error(`${CONFIG} is missing: round six's clean WordPress (port 8890, no plugins, maps nothing of this repository)`);
  const config = JSON.parse(readFileSync(path, "utf8")) as { plugins?: unknown[]; mappings?: Record<string, string> };
  if ((config.plugins ?? []).length) throw new Error(`refusing: ${CONFIG} installs plugins`);
  for (const [to, from] of Object.entries(config.mappings ?? {})) {
    if (to.includes("plugins/omnabase") || [".", "./", "..", REPO].includes(String(from).replace(/\/$/, ""))) throw new Error(`refusing: ${CONFIG} maps ${from} to ${to}`);
  }
}
const HOP = new Set(["host", "connection", "content-length", "accept-encoding", "keep-alive", "transfer-encoding", "upgrade", "te", "content-encoding", "set-cookie"]);
/** https://fresh.omnabase.test → wp-env's fresh site, as harness.ts's routeSite does for wp.omnabase.test (a redirect within the site is
 *  followed by a fresh navigation, which this route takes again) */
async function routeFresh(context: BrowserContext): Promise<void> {
  await context.route((url) => url.hostname === FRESH_HOST, async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    const headers: Record<string, string> = {};
    for (const [k, v] of Object.entries(await req.allHeaders())) if (!HOP.has(k) && !k.startsWith(":")) headers[k] = v;
    headers["x-forwarded-host"] = FRESH_HOST;
    headers["x-forwarded-proto"] = "https";
    const body = req.postDataBuffer();
    const res = await fetch(FRESH_LOCAL + url.pathname + url.search, { method: req.method(), headers, body: body ? new Uint8Array(body) : undefined, redirect: "manual" });
    const out: Record<string, string> = {};
    res.headers.forEach((v, k) => { if (!HOP.has(k)) out[k] = v; });
    const cookies = res.headers.getSetCookie();
    if (cookies.length) out["set-cookie"] = cookies.join("\n");
    const location = res.headers.get("location");
    const next = req.isNavigationRequest() && res.status >= 300 && res.status < 400 && location ? new URL(location, req.url()) : null;
    if (next && next.hostname === FRESH_HOST) {
      next.hash = "";
      delete out.location;
      await route.fulfill({ status: 200, headers: { ...out, "content-type": "text/html; charset=utf-8" }, body: `<!doctype html><title>Redirecting</title><script>location.replace(${JSON.stringify(next.toString())})</script>` });
      return;
    }
    await route.fulfill({ status: res.status, headers: out, body: Buffer.from(await res.arrayBuffer()) });
  });
}
/** a browser on the fresh site: through the https host, or (`plain`) at http://localhost:8890 itself, where WordPress's uploader works
 *  (a routed request loses a multipart file's bytes) */
async function freshBrowser(browser: import("@playwright/test").Browser, plain = false): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  if (!plain) await routeFresh(context);
  return { context, page: await context.newPage() };
}
async function answers(url: string): Promise<boolean> {
  try { await fetch(url, { redirect: "manual", signal: AbortSignal.timeout(5_000) }); return true; } catch { return false; }
}
async function signIn(page: Page, base: string = FRESH): Promise<void> {
  await page.goto(`${base}/wp-login.php`);
  await page.waitForLoadState("load");
  await page.waitForTimeout(500); // wp-login.php moves the focus to the user field shortly after loading
  await page.locator("#user_login").fill("admin");
  await page.locator("#user_pass").fill("password");
  await expect(page.locator("#user_login")).toHaveValue("admin");
  await expect(page.locator("#user_pass")).toHaveValue("password");
  await page.locator("#wp-submit").click();
  await page.waitForURL((u) => !u.pathname.startsWith("/wp-login.php"), { timeout: 60_000 });
}
const omnabaseLeftovers = (): string => freshWp("eval", `global $wpdb; echo wp_json_encode( array( 'options' => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%omnabase%'" ), 'usermeta' => $wpdb->get_col( "SELECT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '%omnabase%'" ), 'tables' => $wpdb->get_col( "SHOW TABLES LIKE '%omnabase%'" ) ) );`).trim();

test.beforeAll(async () => {
  safeConfig();
  if (!(await answers(FRESH_LOCAL))) run("npx", ["wp-env", "start", `--config=${CONFIG}`]);
  try { freshWp("plugin", "delete", "omnabase"); } catch { /* not installed: already fresh */ }
  run("npx", ["wp-env", "clean", "all", `--config=${CONFIG}`, "--force"]); // --force: wp-env asks y/N otherwise
  freshWp("rewrite", "structure", "/%postname%/", "--hard");
  const out = run("bin/build-dist.sh", []);
  zip = /PKG\s+(\S+\.zip)/.exec(out)?.[1] ?? "";
  if (!zip || !existsSync(zip)) throw new Error(`bin/build-dist.sh made no zip:\n${out}`);
});

/**
 * What it checks: A site owner installs the zip through WordPress's own uploader, activates it, and finds the settings screen ready to
 *   connect, with the rest of the site still working.
 * Passes when: the upload says "Plugin installed successfully.", activation says "Plugin activated.", Settings › OmnaBase shows the heading,
 *   "not connected" and a Connect button and no development notice, the front page answers 200, and no page shows a PHP error, warning,
 *   notice or WordPress's "critical error".
 * Fails when: the zip misses a file, needs something only the workshop has, or breaks wp-admin or the front page on a customer's first try.
 */
test("the zip installs, activates and shows Connect on a clean WordPress", async ({ browser }) => {
  const plain = await freshBrowser(browser, true);
  await signIn(plain.page, FRESH_LOCAL);
  await plain.page.goto(`${FRESH_LOCAL}/wp-admin/plugin-install.php?tab=upload`);
  await plain.page.locator("#pluginzip").setInputFiles(zip);
  await plain.page.locator("#install-plugin-submit").click();
  await expect(plain.page.getByText("Plugin installed successfully.")).toBeVisible({ timeout: 60_000 });
  expect(await plain.page.content()).not.toMatch(BROKEN);
  await plain.page.getByRole("link", { name: /Activate Plugin/ }).click();
  await expect(plain.page.getByText("Plugin activated.")).toBeVisible();
  expect(await plain.page.content()).not.toMatch(BROKEN);
  await plain.context.close();

  const { context, page } = await freshBrowser(browser);
  const scriptErrors: string[] = [];
  page.on("pageerror", (e) => scriptErrors.push(e.message));
  await signIn(page);
  await page.goto(`${FRESH}/wp-admin/plugins.php`);
  expect(await page.content()).not.toMatch(BROKEN);

  await page.goto(`${FRESH}/wp-admin/options-general.php?page=omnabase`);
  await expect(page.getByRole("heading", { level: 1, name: "OmnaBase" })).toBeVisible();
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "not_connected");
  await expect(page.getByRole("button", { name: "Connect", exact: true })).toBeVisible();
  await expect(page.locator(".ob-wp-settings__dev")).toHaveCount(0);
  expect(await page.content()).not.toMatch(BROKEN);

  const front = await page.goto(`${FRESH}/`);
  expect(front?.status()).toBe(200);
  expect(await page.content()).not.toMatch(BROKEN);
  await page.goto(`${FRESH}/wp-admin/`);
  expect(await page.content()).not.toMatch(BROKEN);
  expect(scriptErrors).toEqual([]);
  await context.close();
});

/**
 * What it checks: Deleting the plugin from the Plugins screen runs its uninstall and leaves nothing of it in the database.
 * Passes when: after Deactivate and Delete (the browser's confirm accepted) WordPress says the plugin was deleted, no page showed a PHP
 *   problem, and the database holds no option, user meta or table whose name contains "omnabase".
 * Fails when: a never-connected site's uninstall errors (it has no key to stop), or the outbox table and settings stay behind after the
 *   owner removed the plugin.
 */
test("deleting it from the Plugins screen leaves nothing behind", async ({ browser }) => {
  const { context, page } = await freshBrowser(browser, true);
  await signIn(page, FRESH_LOCAL);
  await page.goto(`${FRESH_LOCAL}/wp-admin/plugins.php`);
  await page.locator("#deactivate-omnabase").click();
  await expect(page.getByText("Plugin deactivated.")).toBeVisible();
  page.once("dialog", (d) => void d.accept());
  await page.locator("#delete-omnabase").click();
  await expect(page.getByText(/OmnaBase was successfully deleted\./)).toBeVisible({ timeout: 30_000 });
  expect(await page.content()).not.toMatch(BROKEN);
  expect(JSON.parse(omnabaseLeftovers())).toEqual({ options: [], usermeta: [], tables: [] });
  await context.close();
});
