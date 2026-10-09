/**
 * M13 round one, the red team's failing flow: Connect end to end in a browser. The administrator clicks Connect on Settings › OmnaBase
 * (wp-env's WordPress as https://wp.omnabase.test), lands on OmnaBase's real connect page (the web app on 4391, served by the e2e stack),
 * signs in as the founder by a mailed code, approves, and is back on the settings screen "Connected to Team Kriesi" with the permissions;
 * "Check connection" proves both directions; connecting again rotates the key; Disconnect revokes it at OmnaBase; Cancel and a forged way
 * back store nothing.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): round one's *Red team* "Playwright: connect end to end (code by mail
 * capture, Approve, back); Cancel; Disconnect makes the old key fail; reconnect rotates; Check connection shows the `ping` arriving"; choice 2
 * ("Connected to Team Kriesi · project Omnalingo · key obw_…7Q2X", "Connect / Disconnect / Check connection"); the plugin's `CLAUDE.md`
 * ("From round one, Connect needs OmnaBase's web app too (the connect page on Vite, port 4391): `bin/omnabase-e2e.sh` grows to start it").
 * The coordinator: "`connect.spec.ts`: the admin clicks Connect → the OmnaBase connect page … → sign in as the founder, approve → back on
 * the settings screen 'Connected to Team Kriesi' with the scopes; Check connection both green; Disconnect; the forged state refused".
 *
 * Shapes the red team pins (chosen where marked; the builder may rename and tell the red team):
 * - The screen's buttons "Connect", "Check connection", "Disconnect" (sentence case, the brief's words); a confirm dialog, if any, is
 *   accepted.
 * - The connect page is `OMNABASE_APP_URL` (`http://localhost:4391`, from `.wp-env.json`) + `/connect#…`; its labels are OmnaBase's
 *   (`ConnectScreen.tsx`: "Email", "Send me a code", "Code", "Continue", "Workspace", "Project", "Approve" / "Approve and rotate the key",
 *   "Cancel"). The project is Enfold (no other system answers its customer questions in Team Kriesi's seed, so no question is asked).
 * - (chosen) The two results of "Check connection" as elements with `data-check="me"` / `data-check="ping"` and `data-result="ok"`; the
 *   flow runs OmnaBase's webhook pass (`/api/test/tick`) while it waits, so it holds whether `/me/ping` sends at once or queues.
 * - OmnaBase's side is read through the founder's own session (`GET /api/v1/w/kriesi/sites`: the sites with a live key, their last four).
 *
 * Why this fails today: the screen has no Connect button ("Connecting your site arrives in the next version.").
 *
 * Existing flows this round must change (the builder's): `round-trip.spec.ts` stores its key through the harness's `set-key`, which wrote
 * round zero's plain option; it moves to the vault (or to this real connect).
 */
import { request as playwrightRequest, type APIRequestContext, type Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { OMNABASE_E2E, SITE, SITE_HOST, WP_LOCAL, omnabaseHook, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const APP = process.env.OMNABASE_APP_URL ?? "http://localhost:4391";
const FOUNDER = "kriesi@example.test";
const SCOPES = ["tickets:read", "tickets:write", "customers:read", "customers:write", "forms:write", "portal"];
const FULL_KEY = /obw_[A-Za-z0-9_-]{20,}/;
type RelayAnswer = { calls: { url: string; method: string; status: number | null; excerpt: string }[] };
type SiteRow = { host: string | null; keyLast4: string };

test.describe.configure({ mode: "serial" });

let founder: APIRequestContext;
let firstLast4 = "";

/** the last code mailed to an address (the e2e server's capture transport) */
async function codeFor(email: string): Promise<string> {
  for (let i = 0; i < 50; i++) {
    const res = await fetch(`${OMNABASE_E2E}/api/test/mail?to=${encodeURIComponent(email)}`);
    const m = res.ok ? ((await res.json()) as { text?: string } | null) : null;
    const hit = m?.text ? /(\d{3}) (\d{3})/.exec(m.text) : null;
    if (hit) return hit[1]! + hit[2]!;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`no code in the mail to ${email}`);
}
/** the founder's live sites in Team Kriesi with this host, as OmnaBase lists them */
async function liveSites(): Promise<SiteRow[]> {
  const res = await founder.get("/api/v1/w/kriesi/sites");
  expect(res.ok(), await res.text()).toBe(true);
  return ((await res.json()) as { rows: SiteRow[] }).rows.filter((r) => r.host === SITE_HOST);
}
/** from the settings screen: Connect, then on OmnaBase's page sign in as the founder by code */
async function connectAndSignIn(page: Page): Promise<void> {
  await page.goto(SETTINGS);
  await page.getByRole("button", { name: "Connect", exact: true }).click();
  await page.waitForURL((u) => u.toString().startsWith(`${APP}/connect`));
  expect(new URL(page.url()).hash, "everything after #").toContain(`site=${encodeURIComponent(SITE)}`);
  await expect(page.getByRole("heading", { name: SITE_HOST })).toBeVisible();
  await page.getByRole("textbox", { name: "Email" }).fill(FOUNDER);
  await page.getByRole("button", { name: "Send me a code" }).click();
  await expect(page.getByRole("textbox", { name: "Code" })).toBeVisible();
  await page.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER));
  await page.getByRole("button", { name: "Continue" }).click();
}
/** pick Team Kriesi and Enfold, approve, and arrive back on the clean settings address */
async function approve(page: Page): Promise<void> {
  await page.getByLabel("Workspace").selectOption({ label: "Team Kriesi" });
  await page.getByLabel("Project").selectOption({ label: "Enfold" });
  await page.getByRole("button", { name: /^Approve/ }).click();
  await page.waitForURL((u) => u.hostname === SITE_HOST);
  await expect(page, "back on the settings page, without the code or state in the address").toHaveURL(`${SITE}${SETTINGS}`);
}
async function expectConnected(page: Page): Promise<void> {
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "connected");
  await expect(page.getByText("Connected to Team Kriesi")).toBeVisible();
  await expect(page.getByText("Enfold").first()).toBeVisible();
  for (const scope of SCOPES) await expect(page.getByText(scope, { exact: false }).first(), scope).toBeVisible();
  expect(await page.content(), "the key is never in the page").not.toMatch(FULL_KEY);
}

test.beforeAll(async () => {
  try { wp("eval", "( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* round zero has no vault: the flow fails at the Connect button */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  founder = await playwrightRequest.newContext({ baseURL: OMNABASE_E2E });
  await founder.post("/api/auth/email-otp/send-verification-otp", { data: { email: FOUNDER, type: "sign-in" } });
  const signed = await founder.post("/api/auth/sign-in/email-otp", { data: { email: FOUNDER, otp: await codeFor(FOUNDER) } });
  expect(signed.ok(), await signed.text()).toBe(true);
});
test.afterAll(async () => {
  await omnabaseHook("/api/test/relay", { reset: true });
  await founder?.dispose();
});

/**
 * What it checks: The round's story: an administrator connects the site without copying a key.
 * Passes when: Connect leads to OmnaBase's connect page for wp.omnabase.test; after the founder signs in by code and approves Team Kriesi's
 *   project Enfold, the browser is back on the clean settings address, which reads "Connected to Team Kriesi", names Enfold and the six
 *   permissions, and shows the key only by the last four OmnaBase lists for the site.
 * Fails when: a site owner has to copy a key by hand, the way back loses the connection, or the key is printed into the page.
 */
test("connect end to end: Connect, approve on OmnaBase, back connected", async ({ page }) => {
  await connectAndSignIn(page);
  await approve(page);
  await expectConnected(page);
  const rows = await liveSites();
  expect(rows, "one live key for the site at OmnaBase").toHaveLength(1);
  firstLast4 = rows[0]!.keyLast4;
  await expect(page.getByText(`obw_…${firstLast4}`)).toBeVisible();
});

/**
 * What it checks: "Check connection" shows both directions working: the site reaches OmnaBase, and OmnaBase reaches the site.
 * Passes when: after the click, the "me" result and the "ping" result both turn "ok" within 30 seconds, and OmnaBase's log of calls to the
 *   site shows a ping delivered to /wp-json/omnabase/v1/events and answered 200.
 * Fails when: a security plugin or a broken receiver blocks OmnaBase's calls and the screen still looks healthy, so replies and customer
 *   questions silently never arrive.
 */
test("Check connection: both directions green", async ({ page }) => {
  await page.goto(SETTINGS);
  await page.getByRole("button", { name: "Check connection" }).click();
  await expect.poll(async () => {
    await omnabaseHook("/api/test/tick", { job: "webhooks" });
    const me = await page.locator('[data-check="me"]').getAttribute("data-result", { timeout: 2_000 }).catch(() => null);
    const ping = await page.locator('[data-check="ping"]').getAttribute("data-result", { timeout: 2_000 }).catch(() => null);
    if (ping !== "ok") await page.reload();
    return `${me}/${ping}`;
  }, { timeout: 30_000, intervals: [1_000] }).toBe("ok/ok");
  const relay = await omnabaseHook<RelayAnswer>("/api/test/relay", {});
  expect(relay.calls.filter((c) => c.url === `${SITE}/wp-json/omnabase/v1/events` && c.status === 200).length, "a ping reached the site and was accepted").toBeGreaterThan(0);
});

/**
 * What it checks: Connecting the same site again replaces its key: OmnaBase keeps exactly one live key, a new one, and the screen shows it.
 * Passes when: after Connect and "Approve and rotate the key", OmnaBase lists one live key for the site whose last four differ from the
 *   first connect's, and the screen shows those new last four.
 * Fails when: a reconnect leaves two working keys, or the site keeps using the old key, which OmnaBase stopped at the exchange.
 */
test("connecting again rotates the key", async ({ page }) => {
  expect(firstLast4, "the first connect ran").not.toBe("");
  await connectAndSignIn(page);
  await approve(page);
  await expectConnected(page);
  const rows = await liveSites();
  expect(rows).toHaveLength(1);
  expect(rows[0]!.keyLast4, "a new key").not.toBe(firstLast4);
  await expect(page.getByText(`obw_…${rows[0]!.keyLast4}`)).toBeVisible();
});

/**
 * What it checks: Disconnect revokes the site's key at OmnaBase and leaves the screen ready to connect again.
 * Passes when: after Disconnect the screen is "not connected" with a Connect button, and OmnaBase lists no live key for the site.
 * Fails when: the key keeps working at OmnaBase after the site owner disconnected (whoever copied it keeps access to the tickets).
 */
test("Disconnect revokes the key at OmnaBase", async ({ page }) => {
  page.on("dialog", (d) => void d.accept());
  await page.goto(SETTINGS);
  await page.getByRole("button", { name: "Disconnect" }).click();
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "not_connected");
  await expect(page.getByRole("button", { name: "Connect", exact: true })).toBeVisible();
  expect(await liveSites(), "no live key for the site").toEqual([]);
});

/**
 * What it checks: Cancel on OmnaBase's page brings the admin back to a screen that says so, with nothing connected.
 * Passes when: after signing in and pressing Cancel, the browser is back on the settings page, which is "not connected" and mentions the
 *   cancel; OmnaBase lists no live key for the site.
 * Fails when: a cancelled connect leaves a key behind, or the admin lands on an error page.
 */
test("Cancel on OmnaBase stores nothing", async ({ page }) => {
  await connectAndSignIn(page);
  await page.getByRole("button", { name: "Cancel" }).click();
  await page.waitForURL((u) => u.hostname === SITE_HOST);
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "not_connected");
  await expect(page.getByText(/cancel/i).first()).toBeVisible();
  expect(await liveSites()).toEqual([]);
});

/**
 * What it checks: A way back to the settings page that the admin's own Connect did not start is refused.
 * Passes when: opening the settings page with a made-up code and state shows an error notice and stays "not connected"; OmnaBase lists no
 *   live key for the site.
 * Fails when: an attacker's link, holding a code from the attacker's own approval, connects the victim's site to the attacker's workspace.
 */
test("a forged way back is refused", async ({ page }) => {
  await page.goto(`${SETTINGS}&code=${"f".repeat(43)}&state=forged-${Date.now()}`);
  await expect(page.locator(".notice-error, .ob-wp-settings__error").first()).toBeVisible();
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "not_connected");
  expect(await liveSites()).toEqual([]);
});
