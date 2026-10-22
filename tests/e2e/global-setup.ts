/**
 * Before the flows: wp-env, OmnaBase's e2e server and its connect page must answer, then the administrator signs in to wp-admin once and the session is
 * kept for every flow (tests/e2e/.auth/admin.json, git-ignored). wp-env's administrator is `admin` / `password`, its documented default.
 * The lane switch (2026-10-22): when the flows run against another OmnaBase stack than .wp-env.json's (OMNABASE_E2E_PORT / OMNABASE_APP_URL,
 * e.g. lane 3 on 3031 and 4411), WordPress is pointed at the same ports first (bin/lane.sh set) and put back after the run (the teardown this
 * returns runs bin/lane.sh reset), so no run reaches a stack it was not given.
 */
import { chromium, type FullConfig } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { mkdirSync } from "node:fs";
import { dirname } from "node:path";
import { ADMIN_STATE, LANE, OMNABASE_APP, OMNABASE_E2E, REPO, SITE, WP_LOCAL, routeSite } from "./harness";

async function answers(url: string): Promise<boolean> {
  try { await fetch(url, { redirect: "manual", signal: AbortSignal.timeout(5_000) }); return true; } catch { return false; }
}

async function waitFor(url: string, ms: number): Promise<boolean> {
  for (const until = Date.now() + ms; Date.now() < until; await new Promise((r) => setTimeout(r, 1_000))) if (await answers(url)) return true;
  return false;
}

/** the founder's stack, as .wp-env.json writes it: no switch needed there */
const DEFAULT_LANE = { api: "3011", web: "4391" };
const lane = (...args: string[]) => process.stdout.write(execFileSync("bin/lane.sh", args, { cwd: REPO, encoding: "utf8" }));

export default async function globalSetup(config: FullConfig): Promise<(() => void) | void> {
  const switched = LANE.api !== DEFAULT_LANE.api || LANE.web !== DEFAULT_LANE.web;
  if (switched && (await answers(WP_LOCAL))) lane("set", LANE.api, LANE.web);
  try {
    await prepare(config);
  } catch (e) {
    if (switched) lane("reset");
    throw e;
  }
  if (switched) return () => lane("reset");
}

async function prepare(_config: FullConfig): Promise<void> {
  if (!(await answers(WP_LOCAL))) throw new Error(`wp-env does not answer at ${WP_LOCAL}: run \`npx wp-env start\` (and \`npm run seed\`) first`);
  if (!(await answers(`${OMNABASE_E2E}/health`))) throw new Error(`OmnaBase's e2e server does not answer at ${OMNABASE_E2E}: see CLAUDE.md, "The OmnaBase e2e server"`);
  // round one: Connect sends the browser to OmnaBase's connect page (Vite, started beside the API by bin/omnabase-e2e.sh); give it a minute
  if (!(await waitFor(OMNABASE_APP, 60_000))) throw new Error(`OmnaBase's connect page does not answer at ${OMNABASE_APP}: bin/omnabase-e2e.sh starts it with the API`);

  const browser = await chromium.launch();
  const context = await browser.newContext();
  await routeSite(context);
  const page = await context.newPage();
  await page.goto(`${SITE}/wp-login.php`);
  await page.locator("#user_login").fill(process.env.WP_ADMIN_USER ?? "admin");
  await page.locator("#user_pass").fill(process.env.WP_ADMIN_PASSWORD ?? "password");
  await page.locator("#wp-submit").click();
  await page.waitForURL((u) => !u.pathname.startsWith("/wp-login.php")); // WordPress sends an admin to wherever a plugin's login filter says
  await page.goto(`${SITE}/wp-admin/`);
  await page.locator("#wpbody").waitFor();
  mkdirSync(dirname(ADMIN_STATE), { recursive: true });
  await context.storageState({ path: ADMIN_STATE });
  await browser.close();
}
