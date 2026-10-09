/**
 * Before the flows: wp-env, OmnaBase's e2e server and its connect page must answer, then the administrator signs in to wp-admin once and the session is
 * kept for every flow (tests/e2e/.auth/admin.json, git-ignored). wp-env's administrator is `admin` / `password`, its documented default.
 */
import { chromium, type FullConfig } from "@playwright/test";
import { mkdirSync } from "node:fs";
import { dirname } from "node:path";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, SITE, WP_LOCAL, routeSite } from "./harness";

async function answers(url: string): Promise<boolean> {
  try { await fetch(url, { redirect: "manual", signal: AbortSignal.timeout(5_000) }); return true; } catch { return false; }
}

async function waitFor(url: string, ms: number): Promise<boolean> {
  for (const until = Date.now() + ms; Date.now() < until; await new Promise((r) => setTimeout(r, 1_000))) if (await answers(url)) return true;
  return false;
}

export default async function globalSetup(_config: FullConfig): Promise<void> {
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
