/**
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the timer warning is rewritten in plain words and "WP-Cron" joins
 * the one banned list (brief `docs/briefs/m14-settings-cleanup.md`, choice 1: "the timer warning stays visible when it applies, in plain words
 * ('Your host turned WordPress's timer off: ask them to run it every minute, or messages to OmnaBase wait for visitors')"; choice 3; review
 * S1: "`tests/e2e/cron-health.spec.ts` … require the visible word 'WP-Cron' and the block `.ob-wp-settings__health`. … Keep
 * `.ob-wp-settings__health` on the status line's wrapper. Rewrite cron-health's check to the new plain words"; S2: the line without file
 * names). So WARNING now matches the plain sentence (the timer, off, every minute) instead of "WP-Cron … turned off" + "cron job"; the test
 * also asserts the warning is visible on the open page (not folded into Details) and that it holds no banned word. The block's class, the
 * switch through WP-CLI and the "gone again" half are unchanged.
 *
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
/** the plain warning: WordPress's timer is off, and what to ask the host (run it every minute) */
const WARNING = /\btimer\b[^.]*\boff\b[^.]*every minute|every minute[^.]*\btimer\b[^.]*\boff\b/i;
/** the one banned list (M14 brief, choice 3): whole words, REST with its case, plurals of the nouns */
const BANNED: { word: string; plural: boolean }[] = [
  "wp-json", "REST", "WP-Cron", "cron", "DISABLE_WP_CRON", "HMAC", "signature", "usermeta", "meta key", "salts", "encryption key", "nonce",
  "endpoint", "webhook", "scope", "JSON", "HTTP", "401", "409", "5xx",
].map((word) => ({ word, plural: ["signature", "meta key", "encryption key", "nonce", "endpoint", "webhook", "scope"].includes(word) }));
const bannedIn = (text: string): string[] =>
  BANNED.filter(({ word, plural }) => {
    const body = word.replace(/[.*+?^${}()|[\]\\]/g, "\\$&").replace(/ /g, "\\s+") + (plural ? "s?" : "");
    return new RegExp(`(?<![\\p{L}\\p{N}_-])${body}(?![\\p{L}\\p{N}_-])`, word === "REST" ? "u" : "iu").test(text);
  }).map(({ word }) => word);

test.describe.configure({ mode: "serial" });

let key = "";
const unsetCron = (): void => { try { wp("config", "delete", "DISABLE_WP_CRON", "--type=constant"); } catch { /* not set */ } };
/** the outbox's every-minute run, due at `when` (a strtotime phrase: "now", "-20 minutes") */
const outboxDue = (when: string): void => {
  try { wp("cron", "event", "unschedule", "omnabase_outbox"); } catch { /* not scheduled */ }
  wp("cron", "event", "schedule", "omnabase_outbox", when, "omnabase_every_minute");
};
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
 * What it checks: An owner whose host switched WordPress's timer off, and whose messages to OmnaBase therefore wait, learns it on Settings ›
 *   OmnaBase, in plain words, with what to do; an owner whose host switched it off but runs it from the server is not bothered (2026-10-28).
 * Passes when: with the timer switched off (DISABLE_WP_CRON set) but the sending run due now, no warning shows; with the sending run twenty
 *   minutes overdue, the status block on the Connection tab shows, without opening anything, a line saying WordPress's timer is off and
 *   asking the host to run it every minute, and that line holds no developer word (no "WP-Cron", "cron", "DISABLE_WP_CRON"); with the
 *   switch removed and the run back on time, that line is gone.
 * Fails when: forum topics and licence changes wait for ever on such a host and nobody is told why, the owner is told about "WP-Cron" and
 *   "wp-cron.php", the warning hides behind "Details", or it stays after the host fixed it.
 */
test("health warns while WordPress's timer is turned off", async ({ page }) => {
  wp("config", "set", "DISABLE_WP_CRON", "true", "--raw", "--type=constant");
  try {
    outboxDue("now");
    await afterConfigChange(page);
    await page.goto(SETTINGS);
    await expect(page.locator(".ob-wp-settings__health"), "off, but the server runs it: no warning").not.toContainText(WARNING);
    outboxDue("-20 minutes");
    await page.goto(SETTINGS);
    const health = page.locator(".ob-wp-settings__health");
    await expect(health).toContainText(WARNING);
    const line = health.getByText(WARNING).first();
    await expect(line, "the warning is on the open page, not folded away").toBeVisible();
    expect(bannedIn((await line.innerText()).replace(/\bhttps?:\/\/\S+/g, " ")), "the warning speaks plainly").toEqual([]);
  } finally {
    unsetCron();
    outboxDue("now");
  }
  await afterConfigChange(page);
  await page.goto(SETTINGS);
  await expect(page.locator(".ob-wp-settings__health")).toBeVisible();
  await expect(page.locator(".ob-wp-settings__health")).not.toContainText(WARNING);
});
