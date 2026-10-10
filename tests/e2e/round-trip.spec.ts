/**
 * Round zero's flow, kept as the workshop's smoke test: the administrator opens Settings › OmnaBase on wp-env's WordPress (as
 * https://wp.omnabase.test), WordPress calls OmnaBase's e2e server from inside Docker, and OmnaBase's server calls WordPress back
 * through the relay. The key comes from OmnaBase's test hook and is written into the vault by the harness's `set-key` (round one's real
 * connect is connect.spec.ts); since round one the receiver exists, so the relayed webhook is checked and accepted.
 *
 * M14 round one, the builder: ADJUSTED, NOT WEAKENED. The menu link is found inside the admin menu by a name starting with "OmnaBase" (the
 * count bubble may follow it); the test's PM lines are unchanged.
 */
import { expect, test } from "./fixtures";
import { SITE, SITE_HOST, WP_LOCAL, omnabaseHook, publicApi, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
type RelayAnswer = { ok: boolean; routes: { host: string; to: string }[]; calls: { url: string; method: string; status: number | null; excerpt: string; error?: string }[] };

test.describe.configure({ mode: "serial" });

let key = "";
test.beforeAll(async () => {
  wp("omnabase-test", "forget-key");
  await omnabaseHook("/api/test/relay", { reset: true });
  // a key for the site "wp.omnabase.test" in Team Kriesi, as a connect would hand it over (one live key per site: an older one is revoked)
  ({ key } = await omnabaseHook<{ key: string }>("/api/test/apikey", { workspace: "kriesi", site: SITE_HOST, scopes: ["tickets:read", "tickets:write", "webhooks:manage"] }));
});
test.afterAll(async () => {
  wp("omnabase-test", "forget-key");
  if (key) await publicApi(key, "DELETE", "/me"); // the minted key stops, so the next connect starts from a site without a live key
  await omnabaseHook("/api/test/relay", { reset: true });
});

/**
 * What it checks: an administrator finds the plugin's settings screen under Settings › OmnaBase, and before connecting it says the site
 *   is not connected.
 * Passes when: the page at options-general.php?page=omnabase shows the heading "OmnaBase", "not connected", the development notice
 *   naming OmnaBase's test server, and the plugin's version; the address stays https://wp.omnabase.test.
 * Fails when: the menu entry or the screen is missing, the screen claims a connection that does not exist, or WordPress redirects the
 *   browser away from the site's https address (the harness would be broken for every later round).
 */
test("the settings screen renders under Settings › OmnaBase", async ({ page }) => {
  await page.goto(SETTINGS);

  await expect(page).toHaveURL(`${SITE}${SETTINGS}`);
  await expect(page.getByRole("heading", { level: 1, name: "OmnaBase" })).toBeVisible();
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "not_connected");
  await expect(page.getByText("This site is not connected to OmnaBase yet.")).toBeVisible();
  await expect(page.getByText("Development: this site talks to http://host.docker.internal:3011 instead of app.omnabase.com.")).toBeVisible();
  await expect(page.getByText("OmnaBase for WordPress 0.1.0")).toBeVisible();
  // Builder's adjustment (M14 round one, DECISIONS "2026-10-10 — M14 round one"): while an earlier flow leaves a call needing attention,
  // the menu entry carries WordPress's count bubble after "OmnaBase" ("OmnaBase 2 calls need attention" to a screen reader), so the exact
  // name no longer matched. The link is found by a name that starts with "OmnaBase"; its address is checked as before.
  await expect(page.locator("#adminmenu").getByRole("link", { name: /^OmnaBase(\s|$)/ }).first()).toHaveAttribute("href", /options-general\.php\?page=omnabase/);
});

/**
 * What it checks: WordPress, running inside Docker, reaches OmnaBase's test server, and the screen shows which workspace the stored key
 *   belongs to.
 * Passes when: after the site's key is stored, the screen reads "Connected to Team Kriesi" and names the site as OmnaBase knows it.
 * Fails when: the call from the container never arrives (host.docker.internal unreachable, a wrong address or header), or the screen
 *   does not read OmnaBase's answer; an admin would then never see their site connected.
 */
test("WordPress reaches OmnaBase and shows the workspace the key belongs to", async ({ page }) => {
  wp("omnabase-test", "set-key", key);

  await page.goto(SETTINGS);

  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "connected");
  await expect(page.getByText("Connected to Team Kriesi")).toBeVisible();
  await expect(page.getByText(`This site is known to OmnaBase as ${SITE_HOST}.`)).toBeVisible();
});

/**
 * What it checks: OmnaBase's server reaches this WordPress through the relay, and the site's receiver accepts OmnaBase's signed call: a
 *   webhook for a new ticket, addressed to https://wp.omnabase.test, passes OmnaBase's own address guard, arrives at wp-env and is checked
 *   with the webhook's secret.
 * Passes when: with the relay set, a webhook address on the site (its secret stored in the site's vault) and a new ticket filed by the
 *   site's key, OmnaBase's webhook pass delivers one POST to /wp-json/omnabase/v1/events and the site answers 200 (round zero, before the
 *   receiver existed, proved the arrival by WordPress's own "no route" 404).
 * Fails when: the relay, the harness's https handling or the receiver's signature check is broken, so no later round could test webhooks,
 *   forum replies or customer data travelling from OmnaBase to the site.
 */
test("OmnaBase reaches WordPress through the relay", async () => {
  const relay = await omnabaseHook<RelayAnswer>("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  expect(relay.routes).toEqual([{ host: SITE_HOST, to: WP_LOCAL }]);

  const hook = await publicApi<{ webhook: { id: string }; secret: string }>(key, "POST", "/webhooks", { url: `${SITE}/wp-json/omnabase/v1/events`, events: ["ticket.created"] });
  expect(hook.status).toBe(201);
  wp("omnabase-test", "set-key", key, `--webhooks=${hook.body.secret}`);
  try {
    const list = await publicApi<{ data: { projectId: string }[] }>(key, "GET", "/tickets?view=all-open&limit=1");
    expect(list.status).toBe(200);
    const filed = await publicApi(key, "POST", "/tickets", { project: list.body.data[0]!.projectId, customer: { email: "relay-probe@example.com", name: "Relay probe" }, subject: "Round zero: the relay probe", message: "OmnaBase calls the site back.", external: { id: `relay-probe-${Date.now()}` } });
    expect(filed.status).toBe(201);

    await expect.poll(async () => {
      await omnabaseHook("/api/test/tick", { job: "webhooks" });
      return (await omnabaseHook<RelayAnswer>("/api/test/relay", {})).calls.filter((c) => c.url === `${SITE}/wp-json/omnabase/v1/events`);
    }, { timeout: 20_000 }).toEqual([expect.objectContaining({ method: "POST", status: 200, excerpt: expect.stringContaining('"ok":true') })]);
  } finally {
    await publicApi(key, "DELETE", `/webhooks/${hook.body.webhook.id}`);
  }
});
