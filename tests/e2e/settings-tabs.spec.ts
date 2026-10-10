/**
 * M14 round one, the red team's failing flow: the tabbed settings screen in a real browser: the tab row moves between panels, the
 * technical detail waits behind closed "Details" and "Advanced" blocks, the Plugins list leads to the screen, and a key revoked in OmnaBase
 * shows the fourth state "Disconnected in OmnaBase".
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1 (five tabs, the open tab in the address; the status line with the
 * detail behind "Details", the encryption advice in a collapsed "Advanced"; the fourth state "Disconnected in OmnaBase" when OmnaBase answers
 * 401), choice 5 (a "Settings" link on the Plugins list; the row description ends "Set it up under Settings › OmnaBase."). Why a browser flow:
 * only a browser knows whether a closed `<details>` really hides its content, and only OmnaBase's real server can revoke a key and answer
 * 401 the way it does in production.
 *
 * The words: *tab*, one of the screen's five panels; *disclosure*, a line such as "Details" that opens a hidden block when clicked; *Plugins
 * list*, wp-admin's Plugins screen; *revoke*, stopping a key in OmnaBase, after which OmnaBase refuses it (401).
 *
 * Choices this file makes (the builder may change them and tell the red team): the tab links are found by their names ("Customer data",
 * "Forms and messages"); the plugin's row on the Plugins list is `tr[data-plugin="omnabase/omnabase.php"]` (WordPress's own mark); the
 * site is connected through the harness's `set-key` with a key from OmnaBase's test hook (as `cron-health.spec.ts` does), and the last test
 * revokes that key through OmnaBase's own `DELETE /me`.
 *
 * Shared-seed rules this file keeps: it forgets the key at the end (and the key is stopped at OmnaBase by the last test or the cleanup).
 *
 * Why this fails today: no tab row, every detail on the open page, no Settings link, no fourth state.
 */
import { expect, test } from "./fixtures";
import { SITE_HOST, omnabaseHook, publicApi, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";

test.describe.configure({ mode: "serial" });

let key = "";

test.beforeAll(async () => {
  wp("omnabase-test", "forget-key");
  ({ key } = await omnabaseHook<{ key: string }>("/api/test/apikey", { workspace: "kriesi", site: SITE_HOST, scopes: ["tickets:read", "tickets:write", "customers:read", "customers:write", "forms:write", "portal"] }));
  wp("omnabase-test", "set-key", key);
});
test.afterAll(async () => {
  wp("omnabase-test", "forget-key");
  if (key) await publicApi(key, "DELETE", "/me");
});

/**
 * What it checks: The tab row moves between panels and only the open panel is on the page.
 * Passes when: the bare address shows the connection box and no customer-data section; clicking "Customer data" opens an address with
 *   tab=data showing the customer-data section and no connection box; clicking "Forms and messages" opens tab=pages with the page picker.
 * Fails when: the tabs are decoration over one long page, or a click leads to an empty panel.
 */
test("the tab row moves between panels", async ({ page }) => {
  await page.goto(SETTINGS);
  await expect(page.locator(".ob-wp-settings__connection")).toBeVisible();
  await expect(page.locator(".ob-wp-settings__facts")).toHaveCount(0);

  await page.getByRole("link", { name: "Customer data", exact: true }).click();
  await page.waitForURL((u) => u.searchParams.get("tab") === "data");
  await expect(page.locator(".ob-wp-settings__facts")).toBeVisible();
  await expect(page.locator(".ob-wp-settings__connection")).toHaveCount(0);

  await page.getByRole("link", { name: "Forms and messages", exact: true }).click();
  await page.waitForURL((u) => u.searchParams.get("tab") === "pages");
  await expect(page.locator(".ob-wp-settings__portal")).toBeVisible();
});

/**
 * What it checks: The key's last four characters and the encryption advice are hidden until the owner opens "Details" or "Advanced".
 * Passes when: on the Connection tab of the connected site, the key's last four and the line about where the encryption key comes from are
 *   not visible; after clicking "Details" the last four are visible; after clicking "Advanced" the encryption line is visible.
 * Fails when: the first screen an owner sees is full of key characters and PHP lines, or the detail cannot be reached at all.
 */
test("the detail waits behind Details and Advanced", async ({ page }) => {
  await page.goto(SETTINGS);
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "connected");
  const last4 = page.getByText(`obw_…${key.slice(-4)}`).first();
  const source = page.locator("[data-key-source]").first();
  await expect(last4).toBeHidden();
  await expect(source).toBeHidden();

  for (const summary of await page.locator("details:not([open]) > summary", { hasText: /^\s*Details/ }).all()) await summary.click();
  await expect(last4).toBeVisible();
  await page.locator("details > summary", { hasText: /^\s*Advanced/ }).first().click();
  await expect(source).toBeVisible();
});

/**
 * What it checks: The Plugins list leads to the settings screen and says where to set the plugin up.
 * Passes when: the plugin's row on the Plugins list has a "Settings" link and a description ending "Set it up under Settings › OmnaBase.";
 *   clicking the link opens Settings › OmnaBase.
 * Fails when: an owner who just activated the plugin has no direct way to its settings.
 */
test("the Plugins list has a Settings link", async ({ page }) => {
  await page.goto("/wp-admin/plugins.php");
  const row = page.locator('tr[data-plugin="omnabase/omnabase.php"]');
  await expect(row).toContainText("Set it up under Settings › OmnaBase.");
  await row.getByRole("link", { name: "Settings", exact: true }).click();
  await page.waitForURL((u) => u.pathname === "/wp-admin/options-general.php" && u.searchParams.get("page") === "omnabase");
  await expect(page.getByRole("heading", { level: 1, name: "OmnaBase" })).toBeVisible();
});

/**
 * What it checks: When the site's key is revoked in OmnaBase, the screen says "Disconnected in OmnaBase" and offers Connect.
 * Passes when: after OmnaBase stops the key (its own DELETE /me), the Connection tab's box is in the `revoked` state, reads "Disconnected in
 *   OmnaBase" and "Someone disconnected this site in OmnaBase. Click Connect to connect it again.", shows a Connect button and no Check
 *   connection button.
 * Fails when: an owner whose site was disconnected in OmnaBase keeps seeing "Connected to Team Kriesi" while nothing arrives any more.
 */
test("a key revoked in OmnaBase shows Disconnected in OmnaBase", async ({ page }) => {
  expect((await publicApi(key, "DELETE", "/me")).status, "OmnaBase stops the key").toBeLessThan(300);
  await page.goto(SETTINGS);
  await page.reload(); // the first view may be the one that meets the 401
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "revoked");
  await expect(page.getByText("Disconnected in OmnaBase", { exact: false }).first()).toBeVisible();
  await expect(page.getByText("Someone disconnected this site in OmnaBase. Click Connect to connect it again.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Connect", exact: true })).toBeVisible();
  await expect(page.getByRole("button", { name: "Check connection" })).toHaveCount(0);
});
