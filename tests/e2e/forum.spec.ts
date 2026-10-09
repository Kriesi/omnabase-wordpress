/**
 * M13 round three, the red team's failing flow: the forum and the inbox, both ways. The site connects for real (as facts.spec.ts does), the
 * administrator marks the Support (public) and Staff (hidden) forums and picks the support user, Ada posts a topic through bbPress's own form,
 * the outbox files it, and the founder sees it in OmnaBase's inbox marked "Public on wp.omnabase.test" with no customer facts beside it. The
 * founder answers in OmnaBase; after the inbox's few seconds and the webhooks' pass the answer appears on the forum under the support user with
 * the closing line. Ada answers on the forum and the ticket comes back to the team. A topic in the unmarked Members forum creates nothing; one
 * in the hidden Staff forum becomes a ticket that is not public. With the site unreachable the founder's next answer waits ("Waiting for
 * wp.omnabase.test") and reaches the forum once the site is back.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) round three: "*a customer's topic in a marked forum appears in the inbox as
 * 'Public on wp.omnabase.test' even on a busy day; the team's answer appears in the forum under the support user; nothing is lost, posted
 * twice or echoed*"; its *Red team*: "Playwright: topic → ticket → reply in OmnaBase → tick → reply on the forum; with the site down, OmnaBase
 * shows 'Waiting for wp.omnabase.test'". The coordinator: "`forum.spec.ts`: the site connected; a visitor posts a topic in the marked forum →
 * the ticket appears in OmnaBase's inbox with 'Public on wp.omnabase.test' and no customer facts card; the founder replies in OmnaBase → the
 * reply appears on the forum under the support user with the closing line; the visitor replies on the forum → the ticket reopens with the
 * message; an unmarked forum's topic creates nothing; a hidden forum's topic creates a non-public ticket". OmnaBase's ticket page:
 * `.ob-site-mark` "Public on <host>", `.ob-detail` "Waiting for <host>", the composer `.ob-composer__editor` and "Send"
 * (`apps/web/e2e/public-api-delivery.e2e.ts`); the customer facts card `.ob-site-data` (facts.spec.ts).
 *
 * Choices this flow makes (the builder may change them and tell the red team):
 * - The forum section is `.ob-wp-settings__forum` (ForumScreenTest's names): checkboxes `omnabase_forum[forums][]` by forum id, the select
 *   `omnabase_forum[support_user]`, one submit button. The seeded support user's display name is "Support".
 * - bbPress's own front-end forms (`#bbp_topic_title`, `#bbp_topic_content`, `#bbp_topic_submit`; `#bbp_reply_content`, `#bbp_reply_submit`) at
 *   `/forums/forum/<slug>/`; bbPress's posting throttle is set to 0 for the run (restored after).
 * - The outbox is worked with `wp cron event run omnabase_outbox` (WP-Cron cannot wake itself through the harness's address); OmnaBase's
 *   sender and webhooks passes through `/api/test/tick` with the test clock's `now`.
 * - The WooCommerce source is switched on so that a facts card would have something to show: its absence on a public topic is the point.
 *
 * Shared-seed rules this file keeps: it disconnects the site at the end, forgets the forum and facts options, puts the throttle back, trashes
 * the topics it made and resets the relay.
 *
 * Why this fails today: the settings screen has no forum section, and nothing listens to bbPress.
 */
import type { Browser, BrowserContext, Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { ADMIN_STATE, OMNABASE_APP, OMNABASE_E2E, SITE, SITE_HOST, WP_LOCAL, omnabaseHook, publicApi, routeSite, wp } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const FOUNDER = "kriesi@example.test";
const run = Date.now().toString(36);
type RelayAnswer = { calls: { url: string; method: string; status: number | null; excerpt: string }[] };
type Ticket = { number: number; status: string; public: boolean; replyVia: string; external: { id: string } | null; messages: { kind: string; bodyText: string }[] };

test.describe.configure({ mode: "serial" });

let key = "";
const forums: Record<"Support" | "Members" | "Staff", string> = { Support: "", Members: "", Staff: "" };
let topicUrl = "";
let topicId = "";
let ticket = 0;
let throttle = "";
const made: string[] = [];
let founderPage: Page;
let founderContext: BrowserContext;
let adaContext: BrowserContext;
let ada: Page;

/** PHP run inside wp-env's development WordPress; what it echoes */
const php = (code: string): string => wp("eval", code).trim();
/** the same as the administrator (bbPress hides private and hidden forums, and their topics, from a query made as nobody) */
const AS_ADMIN = "wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 1 ) ); ";

/** the text of the last mail to an address (the e2e server's capture transport), or "" */
async function lastMail(email: string): Promise<string> {
  const res = await fetch(`${OMNABASE_E2E}/api/test/mail?to=${encodeURIComponent(email)}`);
  return res.ok ? (((await res.json()) as { text?: string } | null)?.text ?? "") : "";
}
/** the six-digit code of a sign-in mail newer than `before` */
async function codeFor(email: string, before: string): Promise<string> {
  for (let i = 0; i < 100; i++) {
    const text = await lastMail(email);
    const hit = text !== before ? /(\d{3}) (\d{3})/.exec(text) : null;
    if (hit) return hit[1]! + hit[2]!;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error(`no new code in the mail to ${email}`);
}

/** Connect from the settings screen (facts.spec.ts's way): OmnaBase's page, the founder signs in by code, approves Team Kriesi › Enfold */
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
  await page.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER, before));
  await page.getByRole("button", { name: "Continue" }).click();
  await page.getByLabel("Workspace").selectOption({ label: "Team Kriesi" });
  await page.getByLabel("Project").selectOption({ label: "Enfold" });
  await page.getByRole("button", { name: /^Approve/ }).click();
  await page.waitForURL((u) => u.hostname === SITE_HOST);
  await expect(page.locator(".ob-wp-settings__connection")).toHaveAttribute("data-state", "connected");
  await context.close();
}

/** the founder, signed in to OmnaBase's web app */
async function founderSignsIn(browser: Browser): Promise<void> {
  founderContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  founderPage = await founderContext.newPage();
  await founderPage.goto(`${OMNABASE_APP}/signin?workspace=kriesi`);
  await founderPage.getByRole("textbox", { name: "Email" }).fill(FOUNDER);
  const before = await lastMail(FOUNDER);
  await founderPage.getByRole("button", { name: "Continue", exact: true }).click();
  await founderPage.getByRole("textbox", { name: "Code" }).fill(await codeFor(FOUNDER, before));
  await founderPage.getByRole("button", { name: "Sign in" }).click();
  await expect(founderPage).toHaveURL(/\/kriesi(\/|$)/);
}

/** Ada, signed in to the forum site with her seeded login */
async function adaSignsIn(browser: Browser): Promise<void> {
  adaContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  await routeSite(adaContext);
  ada = await adaContext.newPage();
  await ada.goto(`${SITE}/wp-login.php`);
  await ada.locator("#user_login").fill("ada");
  await ada.locator("#user_pass").fill("test-password");
  await ada.locator("#wp-submit").click();
  await ada.waitForURL((u) => !u.pathname.startsWith("/wp-login.php"));
}

/** Ada posts a topic through bbPress's own form in a forum; its id */
async function postTopic(forumSlug: string, title: string, content: string): Promise<string> {
  await ada.goto(`${SITE}/forums/forum/${forumSlug}/`);
  await ada.locator("#bbp_topic_title").fill(title);
  await ada.locator("#bbp_topic_content").fill(content);
  await ada.locator("#bbp_topic_submit").click();
  await ada.waitForURL((u) => u.pathname.startsWith("/forums/topic/"));
  const id = php(`${AS_ADMIN}echo get_posts( array( 'post_type' => 'topic', 'title' => ${JSON.stringify(title)}, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1 ) )[0] ?? '';`);
  expect(id, "bbPress saved the topic").toMatch(/^\d+$/);
  made.push(id);
  return id;
}

/** every waiting outbox row becomes due, then the outbox job runs (as WP-Cron would) */
function workOutbox(): void {
  php(`global $wpdb; $wpdb->query( "UPDATE {$wpdb->prefix}omnabase_outbox SET next_at = " . ( time() - 1 ) . " WHERE state = 'waiting'" );`);
  wp("cron", "event", "run", "omnabase_outbox");
}

const ticketOf = (topic: string): number => Number(php(`echo (int) get_post_meta( ${topic}, '_omnabase_ticket', true );`));
const readTicket = async (n: number) => publicApi<Ticket>(key, "GET", `/tickets/${n}`);
async function tickAt(job: "sender" | "webhooks", at: Date): Promise<void> {
  await omnabaseHook("/api/test/tick", { job, now: at.toISOString() });
}

/** the founder's reply from OmnaBase's composer; its message id */
async function founderReplies(n: number, body: string): Promise<string> {
  await founderPage.goto(`${OMNABASE_APP}/kriesi/inbox/all-open/${n}?compose=reply`);
  await founderPage.locator(".ob-composer__editor").pressSequentially(body);
  const posted = founderPage.waitForResponse((r) => r.request().method() === "POST" && new URL(r.url()).pathname.endsWith(`/tickets/${n}/messages`));
  await founderPage.getByRole("button", { name: "Send", exact: true }).click();
  const answer = await posted;
  expect(answer.ok(), `the reply is accepted (${answer.status()})`).toBe(true);
  return ((await answer.json()) as { sent: { messageId: string } }).sent.messageId;
}

/** the replies shown on the topic's page as Ada sees it: author and text of each */
async function forumReplies(): Promise<{ author: string; text: string }[]> {
  await ada.goto(topicUrl);
  return ada.locator(".bbp-replies .bbp-body > div, .bbp-replies li.bbp-body > div").evaluateAll((els) =>
    els.filter((e) => e.querySelector(".bbp-reply-content")).map((e) => ({ author: (e.querySelector(".bbp-reply-author")?.textContent ?? "").replace(/\s+/g, " ").trim(), text: (e.querySelector(".bbp-reply-content")?.textContent ?? "").replace(/\s+/g, " ").trim() })),
  );
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000); // a real connect, two sign-ins and a few WP-CLI calls (each a container start)
  php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear(); delete_option( 'omnabase_forum' ); delete_option( 'omnabase_facts' );");
  throttle = php("echo get_option( '_bbp_throttle_time', '' );");
  php("update_option( '_bbp_throttle_time', 0 );");
  for (const title of ["Support", "Members", "Staff"] as const) {
    forums[title] = php(`${AS_ADMIN}echo get_posts( array( 'post_type' => 'forum', 'title' => '${title}', 'post_status' => array( 'publish', 'private', 'hidden' ), 'fields' => 'ids', 'numberposts' => 1 ) )[0] ?? '';`);
    expect(forums[title], `the seed's ${title} forum`).toMatch(/^\d+$/);
  }
  await omnabaseHook("/api/test/relay", { reset: true });
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await connect(browser);
  key = php("echo ( new \\OmnaBase\\WordPress\\Security\\Vault() )->read()['key'] ?? '';");
  expect(key, "the connect stored a key").toMatch(/^obw_/);
  php("update_option( 'omnabase_facts', array( 'sources' => array( 'woocommerce' => true ), 'meta' => array() ) );");
  await founderSignsIn(browser);
  await adaSignsIn(browser);
});

test.afterAll(async () => {
  try {
    php(`delete_option( 'omnabase_forum' ); delete_option( 'omnabase_facts' ); update_option( '_bbp_throttle_time', ${JSON.stringify(throttle || "10")} ); foreach ( ${JSON.stringify(made)} as $t ) { wp_trash_post( (int) $t ); }`);
  } catch { /* the plugin may be half built */ }
  if (key) await publicApi(key, "DELETE", "/me");
  try { php("( new \\OmnaBase\\WordPress\\Security\\Vault() )->clear();"); } catch { /* idem */ }
  await omnabaseHook("/api/test/relay", { reset: true });
  await founderContext?.close();
  await adaContext?.close();
});

/**
 * What it checks: The administrator chooses on the settings screen which forums feed the inbox and under whose name OmnaBase answers.
 * Passes when: ticking Support and Staff, leaving Members unticked, choosing "Support" as the support user and saving brings the screen back
 *   with exactly those two forums ticked and Support chosen.
 * Fails when: the forum settings cannot be made or do not stick.
 */
test("the administrator marks the forums and picks the support user", async ({ page }) => {
  await page.goto(SETTINGS);
  const section = page.locator(".ob-wp-settings__forum");
  await expect(section).toBeVisible();
  for (const [title, id] of Object.entries(forums)) await section.locator(`input[name="omnabase_forum[forums][]"][value="${id}"]`).setChecked(title !== "Members");
  await section.locator('select[name="omnabase_forum[support_user]"]').selectOption({ label: "Support" });
  await section.locator('[type="submit"]').first().click();
  await page.waitForURL((u) => u.pathname === "/wp-admin/options-general.php");
  await expect(section.locator(`input[name="omnabase_forum[forums][]"][value="${forums.Support}"]`)).toBeChecked();
  await expect(section.locator(`input[name="omnabase_forum[forums][]"][value="${forums.Staff}"]`)).toBeChecked();
  await expect(section.locator(`input[name="omnabase_forum[forums][]"][value="${forums.Members}"]`)).not.toBeChecked();
  await expect(section.locator('select[name="omnabase_forum[support_user]"] option:checked')).toHaveText(/Support/);
});

/**
 * What it checks: The round's first half: a customer's topic in the marked public forum arrives in the founder's inbox as a public forum
 *   ticket, and the customer's shop data is kept off it.
 * Passes when: after Ada posts in Support and the outbox runs, the topic holds a ticket number; OmnaBase's API reads that ticket as public,
 *   with replies going to the site and the reference "topic-<id>"; the founder's ticket page shows "Public on wp.omnabase.test" and, with
 *   the information column open, no customer facts card.
 * Fails when: forum topics never reach the inbox, or a customer's orders sit beside a ticket whose answer every forum visitor reads.
 */
test("a visitor's topic becomes a public ticket without the customer facts card", async () => {
  topicId = await postTopic("support", `Forum flow ${run}`, `Since the update the switcher is gone (${run}).`);
  topicUrl = ada.url();

  workOutbox();

  ticket = ticketOf(topicId);
  expect(ticket, "the topic keeps its ticket number").toBeGreaterThan(0);
  const read = await readTicket(ticket);
  expect(read.status, JSON.stringify(read.body)).toBe(200);
  expect(read.body).toMatchObject({ public: true, replyVia: "site", external: { id: `topic-${topicId}` } });
  await founderPage.goto(`${OMNABASE_APP}/kriesi/inbox/all-open/${ticket}?info=1`);
  await expect(founderPage.locator(".ob-site-mark")).toContainText(`Public on ${SITE_HOST}`);
  await founderPage.waitForTimeout(2_000); // the facts card would have been asked for by now
  await expect(founderPage.locator(".ob-site-data")).toHaveCount(0);
});

/**
 * What it checks: The team's answer in OmnaBase appears on the forum under the support user with the agent's closing line, once.
 * Passes when: the founder sends a reply from the composer; after OmnaBase's sender and webhooks passes the relay shows the site's events
 *   receiver answered 200, and Ada's topic page shows exactly one reply holding the text, written by "Support", ending with "— <name>, Team
 *   Kriesi".
 * Fails when: forum customers never see the team's answers, see them twice, or cannot tell who answered.
 */
test("the founder's reply appears on the forum under the support user with the closing line", async () => {
  const body = `Please update to version 2.1 (${run}).`;
  await founderReplies(ticket, body);
  await tickAt("sender", new Date(Date.now() + 10_000));
  await tickAt("webhooks", new Date(Date.now() + 11_000));

  const relay = await omnabaseHook<RelayAnswer>("/api/test/relay", {});
  expect(relay.calls.filter((c) => c.url === `${SITE}/wp-json/omnabase/v1/events` && c.status === 200).length, "the site took the reply").toBeGreaterThan(0);
  const replies = (await forumReplies()).filter((r) => r.text.includes(body));
  expect(replies, "one reply on the forum").toHaveLength(1);
  expect(replies[0]!.author).toContain("Support");
  expect(replies[0]!.text).toMatch(/— [^,]+, Team Kriesi/);
});

/**
 * What it checks: The customer's answer on the forum brings the ticket back to the team with her words.
 * Passes when: Ada replies on her topic and the outbox runs; OmnaBase's API reads the ticket open again, its newest message the customer's
 *   holding her text.
 * Fails when: the customer's follow-up stays on the forum while the ticket waits on her for ever.
 */
test("the visitor's reply on the forum reopens the ticket with her message", async () => {
  const text = `It works now, but the flags are gone (${run}).`;
  await ada.goto(topicUrl);
  await ada.locator("#bbp_reply_content").fill(text);
  await ada.locator("#bbp_reply_submit").click();
  await expect(ada.locator(".bbp-reply-content").filter({ hasText: text })).toHaveCount(1);

  workOutbox();

  const read = await readTicket(ticket);
  expect(read.body.status).toBe("open");
  const last = read.body.messages[read.body.messages.length - 1]!;
  expect(last.kind).toBe("customer");
  expect(last.bodyText).toContain(text);
});

/**
 * What it checks: A forum the administrator did not tick stays out of the inbox.
 * Passes when: Ada's topic in the unmarked Members forum leaves no outbox row and no ticket number after the outbox runs, and no ticket of the
 *   site carries its reference.
 * Fails when: every forum of the site floods the inbox.
 */
test("a topic in an unmarked forum creates nothing", async () => {
  const id = await postTopic("members", `Members only ${run}`, `A members' question (${run}).`);

  workOutbox();

  expect(php(`global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}omnabase_outbox WHERE subject_id = %d", ${id} ) );`)).toBe("0");
  expect(ticketOf(id)).toBe(0);
  const list = await publicApi<{ data: { external: { id: string } | null }[] }>(key, "GET", "/tickets?view=all-open&limit=100");
  expect(list.body.data.filter((t) => t.external?.id === `topic-${id}`)).toHaveLength(0);
});

/**
 * What it checks: A topic in the hidden (moderators-only) forum becomes a ticket that is not public, so the team keeps the customer's data.
 * Passes when: a moderator's topic in the marked Staff forum, made the way wp-admin makes it, gets a ticket number after the outbox runs, and
 *   OmnaBase reads that ticket with public false; its page shows no "Public on" mark.
 * Fails when: a staff-only topic is treated as public, so the AI and the team lose the customer's facts for no reason, or it is not filed.
 */
test("a hidden forum's topic creates a non-public ticket", async () => {
  const id = php(`$nina = get_user_by( 'login', 'nina' )->ID; $id = bbp_insert_topic( array( 'post_parent' => ${forums.Staff}, 'post_author' => $nina, 'post_title' => 'Staff question ${run}', 'post_content' => 'Only moderators read this (${run}).' ), array( 'forum_id' => ${forums.Staff} ) ); echo $id;`);
  made.push(id);

  workOutbox();

  const n = ticketOf(id);
  expect(n, "the hidden forum's topic was filed").toBeGreaterThan(0);
  const read = await readTicket(n);
  expect(read.body.public).toBe(false);
  await founderPage.goto(`${OMNABASE_APP}/kriesi/inbox/all-open/${n}`);
  await expect(founderPage.locator(".ob-site-mark")).toHaveCount(0);
});

/**
 * What it checks: When OmnaBase cannot reach the site, the team sees the answer waiting, and it reaches the forum once the site is back.
 * Passes when: with the relay off the founder's second reply leaves the ticket page saying "Waiting for wp.omnabase.test"; with the relay on
 *   again and the webhooks' next try due, the reply appears once on Ada's topic.
 * Fails when: an answer the forum never received looks delivered, or a short outage of the site loses it.
 */
test("with the site down the reply waits, then reaches the forum", async () => {
  await omnabaseHook("/api/test/relay", { reset: true });
  // Builder's adjustment (DECISIONS "M13 round three", test adjustment 2): with the relay merely reset, the e2e server's webhook net falls
  // back to its own stand-in receiver, which answers 200 for any *.test host, so the "site down" answer was "Delivered to wp.omnabase.test".
  // The site is now really down: its host is relayed to a loopback port nothing listens on, so OmnaBase's call is refused as a dead site's is.
  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: "http://127.0.0.1:9" });
  const body = `Second answer while the site is down (${run}).`;
  await founderReplies(ticket, body);
  await tickAt("sender", new Date(Date.now() + 10_000));
  const first = new Date(Date.now() + 11_000);
  await tickAt("webhooks", first);
  await founderPage.goto(`${OMNABASE_APP}/kriesi/inbox/all-open/${ticket}`);
  await expect(founderPage.locator(".ob-detail")).toContainText(`Waiting for ${SITE_HOST}`);

  await omnabaseHook("/api/test/relay", { host: SITE_HOST, to: WP_LOCAL });
  await tickAt("webhooks", new Date(first.getTime() + 65_000));

  expect((await forumReplies()).filter((r) => r.text.includes(body)), "the waiting reply, once").toHaveLength(1);
});
