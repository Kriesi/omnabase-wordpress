/**
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the customer-data section moves to the Customer data tab, which
 * has one save form ending in one primary "Save" (brief `docs/briefs/m14-settings-cleanup.md`, choice 1; review S1, S3). `switchSources()`
 * opens `&tab=data`, clicks the tab's "Save" button by its words, and also expects to come back to the data tab. The switches' names, the
 * checks after saving and every flow on OmnaBase's side are unchanged.
 *
 * M13 round two, the red team's failing flow: the team sees the shop on the ticket. The site connects for real (Connect, OmnaBase's page,
 * the founder approves Team Kriesi › Enfold: OmnaBase then holds the site's customer endpoint and the plugin its secret), the administrator
 * switches customer-data sources on, a ticket arrives from the seeded WooCommerce customer Ada, and the founder opens it in OmnaBase's web
 * app: OmnaBase asks the site through the relay, and the ticket's customer column shows her WooCommerce order and her EDD licence. With the
 * WooCommerce switch off the order vanishes. Then the outbox: the link OmnaBase needs (the site's id for Ada) goes out, and a licence refresh
 * that fails while OmnaBase cannot reach the site waits and goes once it can.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) round two: "*when the team opens a customer who bought on the site, the card
 * shows account, orders, subscriptions and licences from the switched-on sources only, every answer is one OmnaBase accepts, and licence
 * changes reach OmnaBase on their own, even through an outage*"; its *Red team*: "… give an answer OmnaBase's reader accepts (the e2e test call
 * says so); … a switched-off source is absent; … with OmnaBase down, the refresh waits and goes later". The coordinator: "`facts.spec.ts`: with
 * the site connected …, OmnaBase's e2e server is told through the relay to call the site's `/customer` for the seeded Woo customer → the
 * ticket's customer card in the OmnaBase web app shows the order; the EDD licence shows; with the Woo switch off the orders vanish; an outbox
 * row drains after the e2e server comes back — use the e2e server's hooks". OmnaBase's card: `connect-site.e2e.ts` ("the ticket's
 * information column renders it with the kit's `SiteCustomerData` (`.ob-site-data`: 'Orders', 'Licences', 'Version …')", at
 * `/<workspace>/inbox/all-open/<number>?info=1`).
 *
 * Choices this flow makes (the builder may change them and tell the red team):
 * - (chosen) The site connects through the real Connect, not the harness's vault write: only Connect makes OmnaBase's customer endpoint for
 *   the site (with `siteId`, which the refresh needs) and hands the plugin its secret. No OmnaBase change is needed for that.
 * - (chosen) "OmnaBase down" for the refresh is the relay switched off (`/api/test/relay { reset: true }`): OmnaBase then cannot reach the
 *   site's customer endpoint during the refresh and answers 409 `facts_failed`, a failure the outbox must retry; switching the relay on again
 *   is "OmnaBase back". The e2e server itself cannot be stopped mid-run without losing its schema.
 * - The customer-data section is `.ob-wp-settings__facts` with a checkbox per source (`omnabase_facts[sources][]`, values as
 *   `RoundTwoTestCase` names them) and one submit button; the outbox is worked by the WP-Cron job `omnabase_outbox` (run here with
 *   `wp cron event run`); its table is `wp_omnabase_outbox` with `kind` and `state`.
 * - EDD Software Licensing is the fake (`tests/fakes/edd-software-licensing.php`); the dev WordPress must load the fakes when the paid add-ons
 *   are absent (the builder's harness change). This flow writes Ada's licence into the fake itself ("Omnalingo Pro", version 2.4.1).
 *
 * Shared-seed rules this file keeps: it disconnects the site at the end (the key, its webhook address and customer endpoint go), switches
 * every source off, forgets the fake licence and resets the relay.
 *
 * Why this fails today: the screen has no customer-data section, and the site answers `{}` to OmnaBase's question.
 */
import type { Browser, BrowserContext, Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, SITE, SITE_HOST, WP_LOCAL, codeFor, lastMail, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
type RelayAnswer = { calls: { url: string; method: string; status: number | null; excerpt: string }[] };

test.describe.configure({ mode: "serial" });

let key = "";
let ticket = 0;
let adaOrder = "";
let ada = "";
let founderPage: Page;
let founderContext: BrowserContext;

/** PHP run inside wp-env's development WordPress; what it echoes */
const php = (code: string): string => wp("eval", code).trim();


/** Connect from the settings screen: OmnaBase's page, the founder signs in by code, approves Team Kriesi › Enfold, back connected */
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
  await expect(page.getByRole("textbox", { name: "Code" })).toBeVisible();
  await page.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER, before));
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Workspace").selectOption({ label: "Team Kriesi" });
  await page.getByLabel("Project").selectOption({ label: "Enfold" });
  await page.getByRole("button", { name: /^Approve/ }).click();
  await page.waitForURL((u) => u.hostname === SITE_HOST);
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "connected");
  await context.close();
}

/** the founder, signed in to OmnaBase's web app through its own sign-in screen */
async function founderSignsIn(browser: Browser): Promise<void> {
  founderContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  founderPage = await founderContext.newPage();
  await founderPage.goto(`${OMNABASE_APP}/signin?workspace=kriesi`);
  await founderPage.getByRole("textbox", { name: "Email" }).fill(FOUNDER);
  const before = await lastMail(FOUNDER);
  await founderPage.getByRole("button", { name: "Continue", exact: true }).click();
  await expect(founderPage.getByRole("heading", { name: "Check your mail" })).toBeVisible();
  await founderPage.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER, before));
  await founderPage.getByRole("button", { name: "Sign in" }).click();
  await expect(founderPage).toHaveURL(/\/kriesi(\/|$)/);
}

/** the customer-data switches on the settings screen: exactly these on */
async function switchSources(page: Page, on: string[]): Promise<void> {
  await page.goto(`${SETTINGS}&tab=data`);
  const section = page.locator(".ob-wp-settings__facts");
  for (const box of await section.locator('input[type="checkbox"][name="omnabase_facts[sources][]"]').all()) {
    await box.setChecked(on.includes((await box.getAttribute("value")) ?? ""));
  }
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await page.waitForURL((u) => u.pathname === "/wp-admin/options-general.php" && u.searchParams.get("tab") === "data");
  for (const source of on) await expect(section.locator(`input[name="omnabase_facts[sources][]"][value="${source}"]`), source).toBeChecked();
}

/** the ticket's customer column in OmnaBase's web app, opened fresh (OmnaBase asks the site every time a card with the block opens) */
async function siteDataOnTheCard(): Promise<string> {
  await founderPage.goto(`${OMNABASE_APP}/kriesi/inbox/all-open/${ticket}?info=1`);
  const block = founderPage.locator(".ob-site-data").first();
  await expect(block).toBeVisible();
  await block.locator("summary, .ob-site-data__head").first().click().catch(() => {}); // folded under "From <site>": open it when it is a details
  return (await block.innerText()).replace(/\s+/g, " ");
}

/** the outbox's rows of a kind: their states */
function outboxStates(kind: string): string[] {
  const out = php(`global $wpdb; echo wp_json_encode( $wpdb->get_col( $wpdb->prepare( "SELECT state FROM {$wpdb->prefix}omnabase_outbox WHERE kind = %s ORDER BY id", '${kind}' ) ) );`);
  return JSON.parse(out || "[]") as string[];
}

/** every waiting row becomes due (time passes), whatever the next-try column's type */
function makeDue(): void {
  php(`global $wpdb; $t = $wpdb->prefix . 'omnabase_outbox'; $type = (string) $wpdb->get_var( $wpdb->prepare( "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'next_at'", $t ) ); $past = in_array( $type, array( 'int', 'bigint' ), true ) ? (string) ( time() - 1 ) : gmdate( 'Y-m-d H:i:s', time() - 1 ); $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET next_at = %s WHERE state = 'waiting'", $past ) );`);
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(180_000); // a real connect, a sign-in and a few WP-CLI calls (each a container start)
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear(); delete_option( 'omnabase_facts' );");
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  expect(key, "the connect stored a key").toMatch(/^obw_/);
  ada = php("echo get_user_by( 'email', 'ada@example.com' )->ID;");
  adaOrder = php("echo wc_get_orders( array( 'limit' => 1, 'meta_key' => '_omnabase_seed', 'meta_value' => 'ada-order' ) )[0]->get_order_number();");
  const me = await publicApi<{ projects: { id: string; name: string }[] }>(key, "GET", "/me");
  const project = me.body.projects.find((p) => p.name === "Enfold")!.id;
  const filed = await publicApi<{ number: number }>(key, "POST", "/tickets", { project, customer: { email: "ada@example.com", name: "Ada Lovelace" }, subject: "Which version is my licence for?", message: "I bought Enfold and Omnalingo Pro.", external: { id: `facts-${Date.now()}` } });
  expect(filed.status, JSON.stringify(filed.body)).toBe(201);
  ticket = filed.body.number;
  await founderSignsIn(browser);
});

test.afterAll(async () => {
  try { php("delete_option( 'omnabase_facts' ); delete_option( 'omnabase_fake_edd_sl' );"); } catch { /* the plugin may be half built */ }
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  await founderContext?.close();
});

/**
 * What it checks: The round's story: the team opens a ticket from a shop customer and sees her WooCommerce order and her EDD licence, asked
 *   from the site at that moment and accepted by OmnaBase.
 * Passes when: with WooCommerce and EDD Software Licensing switched on, the ticket's customer column shows the site's section with Ada's
 *   seeded order number, "Omnalingo Pro", "2.4.1" and the masked key ending 9K4M (never the full key); OmnaBase's relay log shows its signed
 *   call to the site's /wp-json/omnabase/v1/customer answered 200.
 * Fails when: the team answers a licence question blind, OmnaBase refuses the site's answer, or the full licence key reaches OmnaBase.
 */
test("the ticket's customer column shows the shop's order and licence", async ({ page }) => {
  await switchSources(page, ["woocommerce", "edd_software_licensing"]);
  expect(php("echo class_exists( 'EDD_Software_Licensing' ) ? 'yes' : 'no';"), "EDD Software Licensing (its fake) is loaded in wp-env").toBe("yes");
  php(`$d = get_posts( array( 'post_type' => 'download', 'title' => 'Omnalingo Pro', 'fields' => 'ids', 'numberposts' => 1 ) )[0]; update_post_meta( $d, '_edd_sl_version', '2.4.1' ); update_option( 'omnabase_fake_edd_sl', array( array( 'id' => 71, 'user_id' => ${ada}, 'customer_id' => 0, 'download_id' => $d, 'key' => 'e2e0e2e0e2e0e2e0e2e0e2e0e2e09K4M', 'status' => 'active', 'expiration' => strtotime( '+1 year' ), 'sites' => array( 'https://kunde.example' ) ) ) );`);

  const card = await siteDataOnTheCard();

  expect(card).toContain(adaOrder);
  expect(card).toContain("Omnalingo Pro");
  expect(card).toContain("2.4.1");
  expect(card).toContain("9K4M");
  expect(card).not.toContain("e2e0e2e0");
  const relay = await omnabaseHook<RelayAnswer>("/api/test/relay", {});
  expect(relay.calls.filter((c) => c.url === `${SITE}/wp-json/omnabase/v1/customer` && c.status === 200).length).toBeGreaterThan(0);
});

/**
 * What it checks: Switching WooCommerce off on the settings screen takes the orders off the team's card at once.
 * Passes when: after the administrator unticks WooCommerce (EDD Software Licensing stays on) and saves, the reopened card no longer shows Ada's
 *   order number, and still shows the licence.
 * Fails when: a site owner withdraws a source and its data keeps reaching OmnaBase.
 */
test("with the WooCommerce switch off the orders vanish", async ({ page }) => {
  await switchSources(page, ["edd_software_licensing"]);

  const card = await siteDataOnTheCard();

  expect(card).not.toContain(adaOrder);
  expect(card).toContain("Omnalingo Pro");
});

/**
 * What it checks: The link OmnaBase needs goes out through the outbox: after OmnaBase asked about Ada by her address, the site tells OmnaBase
 *   its own id for her.
 * Passes when: the card's question queued a "user_link" row; after a run of the outbox job it is sent, none waits, and OmnaBase finds a
 *   customer by Ada's site id.
 * Fails when: OmnaBase never learns the site's ids, so no licence refresh can ever reach a customer.
 */
test("the site's id for Ada reaches OmnaBase through the outbox", async () => {
  expect(outboxStates("user_link").length, "a link queued by the card's question (perhaps sent at once already)").toBeGreaterThan(0);
  makeDue();

  wp("cron", "event", "run", "omnabase_outbox");

  expect(outboxStates("user_link")).toContain("sent");
  expect(outboxStates("user_link")).not.toContain("waiting");
  const adaSiteId = php(`echo ( new \\OmnaBase\\WordPress\\Sync\\SiteId() )->forUser( ${ada} );`);
  const found = await publicApi<{ customer: { email: string } | null }>(key, "POST", "/customers/lookup", { externalId: adaSiteId });
  expect(found.status, JSON.stringify(found.body)).toBe(200);
});

/**
 * What it checks: A licence refresh that fails while OmnaBase cannot reach the site waits in the outbox and goes once OmnaBase can again.
 * Passes when: with the relay off, Ada's licence change queues one refresh; a run leaves it waiting (OmnaBase's refresh failed); with the relay
 *   on again and the next try due, a run sends it, the row is sent, and OmnaBase's log shows it asked the site's customer endpoint.
 * Fails when: an outage loses the licence change, so OmnaBase's licence copy stays stale until the next change, maybe months later.
 */
test("an outbox row drains after OmnaBase is back", async () => {
  await omnabaseHook("/api/test/relay", { reset: true });
  php("do_action( 'edd_sl_post_set_status', 71, 'expired' );");
  expect(outboxStates("licence_refresh")).toEqual(["waiting"]);
  makeDue();
  wp("cron", "event", "run", "omnabase_outbox");
  expect(outboxStates("licence_refresh"), "the failed refresh still waits").toEqual(["waiting"]);

  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  makeDue();
  wp("cron", "event", "run", "omnabase_outbox");

  expect(outboxStates("licence_refresh")).toEqual(["sent"]);
  const relay = await omnabaseHook<RelayAnswer>("/api/test/relay", {});
  expect(relay.calls.filter((c) => c.url === `${SITE}/wp-json/omnabase/v1/customer` && c.status === 200).length, "OmnaBase asked the site during the refresh").toBeGreaterThan(0);
});
