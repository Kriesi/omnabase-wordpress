/**
 * M13 round six, the red team's failing flow: health warns when WordPress's scheduler is switched off, and stops warning when it is back.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 3, health: "warnings for disabled WP-Cron, plain permalinks, a stored
 * encryption key, unreadable credentials"; "Risks": "**WP-Cron** runs late on quiet sites; `DISABLE_WP_CRON` needs a real cron (health
 * warns)". The plugin's DECISIONS "M13 round two", "Open for round three": "health lines for the facts calls and a disabled WP-Cron" (carried to
 * round six). The coordinator: "the health lines for facts calls and disabled WP-Cron".
 *
 * Why a browser flow: the switch is a constant in wp-config.php (`DISABLE_WP_CRON`), which a test inside one PHP process can set but never
 * unset; here WP-CLI writes it into wp-env's wp-config.php for one page view and removes it again (also when the test fails).
 *
 * The words: *WP-Cron*, WordPress's built-in scheduler, which runs due jobs (the plugin's outbox among them) when someone visits the site;
 * *`DISABLE_WP_CRON`*, the wp-config.php switch that turns it off, after which only a real cron job on the server runs them; *health*, the
 * settings screen's section that says whether the connection works.
 *
 * Choices this file makes (the builder may change them and tell the red team): the warning sits in the health section
 * (`.ob-wp-settings__health`, shown while connected) and names WP-Cron, says it is turned off (or disabled), and says a real (server) cron
 * job is needed; the site is connected through the harness's `set-key` with a key from OmnaBase's test hook, so health shows.
 *
 * Shared-seed rules this file keeps: it removes the constant, forgets the key and stops it at OmnaBase.
 *
 * Why this fails today: health has no line about WP-Cron.
 */
import { expect, test } from "./fixtures";
import { SITE_HOST, omnabaseHook, publicApi, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const WARNING = /WP-Cron[^.]*(turned off|disabled|switched off)|(turned off|disabled|switched off)[^.]*WP-Cron/i;

test.describe.configure({ mode: "serial" });

let key = "";
const unsetCron = (): void => { try { wp("config", "delete", "DISABLE_WP_CRON", "--type=constant"); } catch { /* not set */ } };
/**
 * The builder's adjustment (round six, DECISIONS "M13 round six", test adjustments): wp-env's WordPress container keeps compiled PHP files
 * for 2 seconds (`opcache.revalidate_freq = 2`), so a page opened right after WP-CLI changed wp-config.php can still run the old one. The
 * full suite met it (the warning still showed after the constant was removed); waiting out that window before each page view changes
 * nothing the test asserts.
 */
const afterConfigChange = (page: import("@playwright/test").Page): Promise<void> => page.waitForTimeout(2_500);

test.beforeAll(async () => {
  unsetCron();
  wp("omnabase-test", "forget-key");
  ({ key } = await omnabaseHook<{ key: string }>("/api/test/apikey", { workspace: "kriesi", site: SITE_HOST, scopes: ["tickets:read", "tickets:write"] }));
  wp("omnabase-test", "set-key", key);
});
test.afterAll(async () => {
  unsetCron();
  wp("omnabase-test", "forget-key");
  if (key) await publicApi(key, "DELETE", "/me");
});

/**
 * What it checks: An owner whose host switched WordPress's scheduler off learns it on Settings › OmnaBase, with what to do.
 * Passes when: with DISABLE_WP_CRON set, the health section names WP-Cron as turned off and says a real (server) cron job is needed; with
 *   the constant removed, that warning is gone.
 * Fails when: forum topics and licence changes wait in the outbox for ever on such a host and nobody is told why, or the warning stays
 *   after the host fixed it.
 */
test("health warns while WP-Cron is turned off", async ({ page }) => {
  wp("config", "set", "DISABLE_WP_CRON", "true", "--raw", "--type=constant");
  try {
    await afterConfigChange(page);
    await page.goto(SETTINGS);
    const health = page.locator(".ob-wp-settings__health");
    await expect(health).toContainText(WARNING);
    await expect(health).toContainText(/(real|server|system) cron|cron job/i);
  } finally {
    unsetCron();
  }
  await afterConfigChange(page);
  await page.goto(SETTINGS);
  await expect(page.locator(".ob-wp-settings__health")).toBeVisible();
  await expect(page.locator(".ob-wp-settings__health")).not.toContainText(WARNING);
});
