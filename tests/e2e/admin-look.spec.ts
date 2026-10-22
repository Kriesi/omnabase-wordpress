/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the brief (OmnaBase
 * `docs/briefs/plugin-frontend-design.md`, rule 2) adds a sixth tab, "Appearance" (`&tab=appearance`), after "Forms and messages". The list of
 * tabs every flow here walks gains it in that place, so the phone check, the "no Stored connection card" check and the founder's pictures cover
 * the Appearance tab too (`appearance.png`, `appearance-phone.png`); the words "five tabs" in the descriptions become "six". No check changed.
 *
 * FIXING ROUND "THE VAULT'S KEY FROM A FILE" (2026-10-11), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the founder removed
 * the "Stored connection" card and its Advanced block ("go with the file-based key, remove the block"), so "Advanced opens like the kit's
 * More" has nothing left to open. It becomes "a closed block opens like the kit's More" on the Customer data tab's "Choose user fields",
 * which every site shows whatever its connection state (the same look checks, the same chevron and indent), and a new flow checks in the
 * browser that no tab shows an Advanced block or the card any more and that the Connection tab ends with the Status card (the Connection
 * card while not connected). Every other flow is unchanged.
 *
 * M14 round four, the red team's failing flow: Settings › OmnaBase in a real browser wears OmnaBase's settings look: white cards with the
 * kit's border and corners, the kit's line tabs with the open tab in the accent colour, the kit's primary button, rows side by side on a
 * wide window and stacked on a phone, a tab strip that scrolls on its own on a phone, the closed blocks like the kit's More, the builder
 * as a kit dialog, the stylesheet on this screen alone; and a picture of each tab for the founder.
 *
 * The founder's order (2026-10-10): "still horrible design. Use a styling similar to the OmnaBase settings where you render the settings
 * nicely in white background cards, and use the canonical tab styling we have in OmnaBase, in the WP backend." The coordinator's contract
 * for round four: computed styles in a browser (the card's background equals the kit's surface colour from the light tokens, the active
 * tab's text the accent, the primary button's background the primary button colour); on a 390px-wide window no tab's content overflows
 * sideways and the tab strip scrolls itself; a screenshot of each tab under test-results/admin-look/ for the founder (not a baseline).
 * Why a browser flow: only a browser applies the stylesheet, so only a browser can say what colour a card really is, and whether
 * WordPress's own admin styles win over the plugin's.
 *
 * The words: *computed style*, the value the browser finally uses after every stylesheet had its say; *token*, one of the kit's named design
 * values (`--ob-bg-surface: #ffffff` …) in OmnaBase's `packages/ui/src/styles/tokens.css`, read here at test time so the test follows the
 * founder's palette when it changes; *card*, a white box with a thin border and rounded corners holding one section; *More*, the kit's closed
 * "show more" line; *dialog*, the small window over the page that "Build a shortcode" opens; *overlay*, the dimmed page behind it.
 *
 * Choices this file makes (the builder may change them and tell the red team): the class names of `tests/Integration/AdminLookShapes.php`
 * (the kit's own: `ob-form__section`, `ob-tabs__item--active`, `ob-btn--primary`, `ob-btn--outline`, `ob-form-row`, `ob-more`, `ob-page__title`);
 * a card's side padding is the kit's 20px or 24px step; the title is the kit's xl size; the tokens come from the OmnaBase repository beside
 * this one (or OMNABASE_REPO); the pictures are full-page at 1280×900 (`<tab>.png`) and 390×844 (`<tab>-phone.png`).
 *
 * Shared-seed rules this file keeps: it changes nothing on the development WordPress or at OmnaBase. It only opens pages, opens and closes
 * the dialog and a disclosure, and reads; it works in whatever state the site is in (connected or not), so it can run while the founder
 * clicks through by hand.
 *
 * Why this fails today: the screen uses WordPress's grey tab strip, loose sections on the gray page and WordPress's buttons; there are no
 * cards, no kit tabs, no kit buttons, no rows, and the outbox and delivery tables overflow a phone's width.
 */
import { mkdirSync, readFileSync, statSync } from "node:fs";
import { resolve } from "node:path";
import type { Page } from "@playwright/test";
import { expect, test } from "./fixtures";
import { REPO } from "./harness";

const SETTINGS = "/wp-admin/options-general.php?page=omnabase";
const TABS = ["connection", "data", "forums", "pages", "appearance", "activity"] as const;
const SHOTS = resolve(REPO, "test-results/admin-look");
const DESKTOP = { width: 1280, height: 900 };
const PHONE = { width: 390, height: 844 };

/** The kit's light tokens (the first `:root { … }` block of OmnaBase's tokens.css), name → raw value. */
function lightTokens(): Record<string, string> {
  const repo = process.env.OMNABASE_REPO ?? resolve(REPO, "../omnabase");
  const css = readFileSync(resolve(repo, "packages/ui/src/styles/tokens.css"), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
  const block = /:root\s*\{([^}]*)\}/.exec(css)?.[1] ?? "";
  const out: Record<string, string> = {};
  for (const m of block.matchAll(/(--ob-[a-z0-9-]+)\s*:\s*([^;]+);/g)) out[m[1]] = m[2].trim();
  return out;
}
const TOKENS = lightTokens();

/** A token's final value, following `var(--ob-…)` to the end. */
function token(name: string): string {
  let value = TOKENS[name];
  for (let i = 0; value && i < 10; i++) {
    const ref = /^var\(\s*(--ob-[a-z0-9-]+)\s*\)$/.exec(value);
    if (!ref) break;
    value = TOKENS[ref[1]];
  }
  if (!value) throw new Error(`the kit has no light token ${name}`);
  return value;
}

/** A hex colour as the browser reports it (`rgb(r, g, b)`). */
function rgb(hex: string): string {
  const h = hex.replace("#", "");
  const full = h.length === 3 ? h.split("").map((c) => c + c).join("") : h.slice(0, 6);
  const [r, g, b] = [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16));
  return `rgb(${r}, ${g}, ${b})`;
}

async function openTab(page: Page, tab: string): Promise<void> {
  await page.goto(`${SETTINGS}&tab=${tab}`);
  await expect(page.locator(".ob-wp-settings")).toBeVisible();
}

/**
 * What it checks: On every tab the cards, the open tab, the buttons and the title take the kit's values, not WordPress's.
 * Passes when: on a wide window, each tab has at least one card, and every card's background is the kit's surface colour, with a 1px solid
 *   border in the kit's border colour, the kit's corner radius and the kit's 20px or 24px side padding; the open tab's text is the kit's
 *   accent colour; every visible primary button's background is the kit's primary button colour with its text colour; every visible
 *   outline button is the surface colour with the kit's strong border; the title is the kit's xl size, semi-bold, in the system font (not
 *   the kit's placeholder typeface); and at least one primary button was seen across the tabs.
 * Fails when: the cards are transparent on the gray page, WordPress's blue or grey wins over the kit's, the open tab is not marked in the
 *   accent, or the page swaps WordPress's font for the undecided one.
 */
test("every tab wears the kit's card, tab, button and title values", async ({ page }) => {
  await page.setViewportSize(DESKTOP);
  let primaries = 0;
  for (const tab of TABS) {
    await openTab(page, tab);
    const seen = await page.evaluate(() => {
      const css = (el: Element, pseudo?: string) => getComputedStyle(el, pseudo);
      const visible = (el: Element) => (el as HTMLElement).offsetParent !== null;
      const root = document.querySelector(".ob-wp-settings")!;
      const title = root.querySelector("h1.ob-page__title");
      const active = root.querySelector("nav .ob-tabs__item--active");
      return {
        cards: Array.from(root.querySelectorAll(".ob-form__section")).map((c) => {
          const s = css(c);
          return { bg: s.backgroundColor, bw: s.borderTopWidth, bs: s.borderTopStyle, bc: s.borderTopColor, radius: s.borderTopLeftRadius, pad: s.paddingLeft };
        }),
        active: active ? css(active).color : null,
        primary: Array.from(root.querySelectorAll(".ob-btn--primary")).filter(visible).map((b) => ({ bg: css(b).backgroundColor, color: css(b).color, words: b.textContent?.trim() })),
        outline: Array.from(root.querySelectorAll(".ob-btn--outline")).filter(visible).map((b) => ({ bg: css(b).backgroundColor, bc: css(b).borderTopColor, words: b.textContent?.trim() })),
        title: title ? { size: css(title).fontSize, weight: css(title).fontWeight, family: css(title).fontFamily } : null,
      };
    });

    expect(seen.cards.length, `${tab}: cards`).toBeGreaterThan(0);
    for (const card of seen.cards) {
      expect(card.bg, `${tab}: a card's background is the kit's surface`).toBe(rgb(token("--ob-bg-surface")));
      expect([card.bw, card.bs], `${tab}: a 1px solid border`).toEqual(["1px", "solid"]);
      expect(card.bc, `${tab}: in the kit's border colour`).toBe(rgb(token("--ob-border")));
      expect(card.radius, `${tab}: the kit's corners`).toBe(token("--ob-radius-md"));
      expect([token("--ob-space-5"), token("--ob-space-6")], `${tab}: the kit's side padding`).toContain(card.pad);
    }
    expect(seen.active, `${tab}: the open tab's text is the accent`).toBe(rgb(token("--ob-accent")));
    for (const b of seen.primary) {
      expect(b.bg, `${tab}: "${b.words}" wears the primary button colour`).toBe(rgb(token("--ob-btn-primary-bg")));
      expect(b.color, `${tab}: "${b.words}" text`).toBe(rgb(token("--ob-btn-primary-text")));
    }
    primaries += seen.primary.length;
    for (const b of seen.outline) {
      expect(b.bg, `${tab}: "${b.words}" is white`).toBe(rgb(token("--ob-bg-surface")));
      expect(b.bc, `${tab}: "${b.words}" has the kit's strong border`).toBe(rgb(token("--ob-border-strong")));
    }
    expect(seen.title, `${tab}: the kit's page title`).not.toBeNull();
    expect(seen.title!.size, `${tab}: the title's size`).toBe(token("--ob-text-xl"));
    expect(seen.title!.weight, `${tab}: semi-bold`).toBe("600");
    expect(seen.title!.family, `${tab}: the system font`).not.toMatch(/Plus Jakarta/i);
  }
  expect(primaries, "at least one primary button across the tabs").toBeGreaterThan(0);
});

/**
 * What it checks: A setting's name and help sit beside its control on a wide window and above it on a phone.
 * Passes when: on the Forms and messages tab, the row holding the message centre's page picker shows the picker to the right of the label
 *   (on the same line) at 1280px wide, and below the label at 390px wide.
 * Fails when: the row keeps WordPress's table look, or on a phone the picker is squeezed beside the label into an unusable sliver.
 */
test("rows sit side by side on a wide window and stacked on a phone", async ({ page }) => {
  const boxes = async () => {
    const row = page.locator(".ob-form-row", { has: page.locator("select[name='omnabase_portal[page]']") });
    await expect(row).toHaveCount(1);
    const label = await row.locator(".ob-form-row__label").boundingBox();
    const control = await row.locator(".ob-form-row__control").boundingBox();
    expect(label && control, "the row's label and control are on screen").toBeTruthy();
    return { label: label!, control: control! };
  };

  await page.setViewportSize(DESKTOP);
  await openTab(page, "pages");
  const wide = await boxes();
  expect(wide.control.x, "the control starts right of the label").toBeGreaterThanOrEqual(wide.label.x + wide.label.width - 1);
  expect(wide.control.y, "on the same line").toBeLessThan(wide.label.y + wide.label.height);

  await page.setViewportSize(PHONE);
  await openTab(page, "pages");
  const narrow = await boxes();
  expect(narrow.control.y, "the control sits under the label").toBeGreaterThanOrEqual(narrow.label.y + narrow.label.height - 1);
});

/**
 * What it checks: On a phone nothing on any tab is wider than the screen, and the tab strip scrolls sideways on its own.
 * Passes when: at 390px wide, on each tab the screen's wrapper is no wider inside than it shows (nothing pokes out to the side); the tab strip
 *   is wider inside than it shows (the six tabs do not fit) yet ends within the wrapper, lets its own content scroll (overflow auto or
 *   scroll), and really scrolls when asked to.
 * Fails when: a table or a long line pushes the page sideways on a phone (the owner must pan to read it), or the six tabs wrap into a
 *   jumble or run off the screen with no way to reach Activity.
 */
test("on a phone no tab overflows and the tab strip scrolls itself", async ({ page }) => {
  await page.setViewportSize(PHONE);
  for (const tab of TABS) {
    await openTab(page, tab);
    const m = await page.evaluate(() => {
      const root = document.querySelector(".ob-wp-settings") as HTMLElement;
      const nav = root.querySelector("nav") as HTMLElement;
      nav.scrollLeft = 10_000;
      return {
        rootScroll: root.scrollWidth,
        rootClient: root.clientWidth,
        rootRight: root.getBoundingClientRect().right,
        navScroll: nav.scrollWidth,
        navClient: nav.clientWidth,
        navRight: nav.getBoundingClientRect().right,
        navOverflow: getComputedStyle(nav).overflowX,
        navScrolled: nav.scrollLeft,
      };
    });
    expect(m.rootScroll, `${tab}: nothing wider than the screen`).toBeLessThanOrEqual(m.rootClient);
    expect(m.navScroll, `${tab}: the six tabs are wider than a phone`).toBeGreaterThan(m.navClient);
    expect(m.navRight, `${tab}: the strip ends inside the screen`).toBeLessThanOrEqual(m.rootRight + 0.5);
    expect(["auto", "scroll"], `${tab}: the strip scrolls its own content`).toContain(m.navOverflow);
    expect(m.navScrolled, `${tab}: and it does scroll`).toBeGreaterThan(0);
  }
});

/**
 * What it checks: A closed block (here "Choose user fields") opens like the kit's More: a summary line with a drawn chevron, the body set in.
 * Passes when: on the Customer data tab, the "Choose user fields" block is a kit More whose summary shows no browser triangle but a drawn
 *   chevron (a pseudo-element with content); after a click its body is visible and its text starts further right than the summary line.
 * Fails when: the closed blocks keep the browser's bare grey triangle, or open into text glued to the left edge under the summary.
 */
test("a closed block opens like the kit's More", async ({ page }) => {
  await page.setViewportSize(DESKTOP);
  await openTab(page, "data");
  const more = page.locator("details.ob-more", { has: page.locator("summary.ob-more__toggle", { hasText: /^\s*Choose user fields/ }) });
  await expect(more).toHaveCount(1);
  const summary = more.locator("summary.ob-more__toggle");
  const look = await summary.evaluate((s) => {
    const own = getComputedStyle(s);
    const pseudo = ["::before", "::after"].map((p) => getComputedStyle(s, p).content);
    return { display: own.display, listStyle: own.listStyleType, pseudo };
  });
  expect(look.display === "list-item" && look.listStyle !== "none", "no browser triangle").toBe(false);
  expect(look.pseudo.some((c) => c !== "none" && c !== "normal"), "a drawn chevron").toBe(true);

  await summary.click();
  const body = more.locator(".ob-more__body");
  await expect(body).toBeVisible();
  const indent = await page.evaluate(() => {
    const d = Array.from(document.querySelectorAll("details.ob-more")).find((x) => /^\s*Choose user fields/.test(x.querySelector("summary")?.textContent ?? ""))!;
    const s = d.querySelector("summary")!.getBoundingClientRect();
    const b = d.querySelector(".ob-more__body") as HTMLElement;
    const st = getComputedStyle(b);
    return { summaryLeft: s.left, bodyTextLeft: b.getBoundingClientRect().left + parseFloat(st.paddingLeft) + parseFloat(st.borderLeftWidth) };
  });
  expect(indent.bodyTextLeft, "the body is set in").toBeGreaterThan(indent.summaryLeft);
  await summary.click();
});

/**
 * What it checks: No tab shows the removed "Stored connection" card or an Advanced block, and the Connection tab ends with the Status card.
 * Passes when: on each tab no closed block's summary reads "Advanced", nothing carries the old card's class or the key-source mark, and the
 *   words "Stored connection", "wp-config" and "OMNABASE_ENCRYPTION_KEY" are nowhere in the page's text (closed blocks included); on the
 *   Connection tab the last card is the Status card when the site is connected or needs a reconnect, and the Connection card otherwise.
 * Fails when: the card the founder removed still shows in a real browser (a cached build, a leftover include), or something hangs below the
 *   Status card.
 */
test("no tab shows the Stored connection card and the Connection tab ends with Status", async ({ page }) => {
  await page.setViewportSize(DESKTOP);
  for (const tab of TABS) {
    await openTab(page, tab);
    const seen = await page.evaluate(() => {
      const root = document.querySelector(".ob-wp-settings")!;
      const cards = Array.from(root.querySelectorAll(".ob-form__section"));
      return {
        advanced: Array.from(root.querySelectorAll("details > summary")).filter((s) => /^\s*Advanced\b/.test(s.textContent ?? "")).length,
        marks: root.querySelectorAll(".ob-wp-settings__advanced, .ob-wp-settings__security, [data-key-source]").length,
        text: (root as HTMLElement).textContent ?? "",
        last: cards.length ? cards[cards.length - 1].className : "",
        state: root.querySelector(".ob-wp-settings__connection")?.getAttribute("data-state") ?? "",
      };
    });
    expect(seen.advanced, `${tab}: no Advanced block`).toBe(0);
    expect(seen.marks, `${tab}: nothing of the old card`).toBe(0);
    expect(seen.text, `${tab}: no Stored connection card`).not.toMatch(/Stored connection|wp-config|OMNABASE_ENCRYPTION_KEY/);
    if (tab === "connection") {
      const status = seen.state === "connected" || seen.state === "reconnect";
      expect(seen.last, `the Connection tab ends with the ${status ? "Status" : "Connection"} card (state ${seen.state})`).toContain(status ? "ob-wp-settings__health" : "ob-wp-settings__connection");
    }
  }
});

/**
 * What it checks: "Build a shortcode" opens a white kit dialog with rounded corners and a shadow over a dimmed page.
 * Passes when: on the Forms and messages tab, after clicking "Build a shortcode", the dialog is visible with the kit's surface colour, the
 *   kit's corner radius and a shadow, and the page behind it is dimmed (the overlay is not transparent); Close closes it.
 * Fails when: the builder opens as a bare browser box with a hard grey border, or the page behind it stays bright so the dialog gets lost.
 */
test("the builder's dialog is a kit dialog over a dimmed page", async ({ page }) => {
  await page.setViewportSize(DESKTOP);
  await openTab(page, "pages");
  await page.locator(".ob-wp-settings__shortcodes").getByRole("button", { name: "Build a shortcode", exact: true }).click();
  const dialog = page.locator("dialog#ob-wp-builder");
  await expect(dialog).toBeVisible();
  const look = await dialog.evaluate((d) => {
    const s = getComputedStyle(d);
    return { bg: s.backgroundColor, radius: s.borderTopLeftRadius, shadow: s.boxShadow, backdrop: getComputedStyle(d, "::backdrop").backgroundColor };
  });
  expect(look.bg, "white").toBe(rgb(token("--ob-bg-surface")));
  expect(look.radius, "the kit's corners").toBe(token("--ob-radius-md"));
  expect(look.shadow, "a shadow").not.toBe("none");
  const alpha = /rgba?\(([^)]+)\)/.exec(look.backdrop)?.[1].split(",").map((v) => v.trim());
  expect(alpha && (alpha.length === 3 || parseFloat(alpha[3]) > 0), `the page behind is dimmed (${look.backdrop})`).toBe(true);
  await dialog.getByRole("button", { name: "Close", exact: true }).click();
  await expect(dialog).toBeHidden();
});

/**
 * What it checks: The screen's stylesheet loads on Settings › OmnaBase and on no other admin page.
 * Passes when: the settings screen links the plugin's assets/admin.css once; the Dashboard links no stylesheet from the plugin's assets.
 * Fails when: the kit's look leaks into the rest of the admin, or the screen shows without it.
 */
test("the stylesheet loads on the plugin's screen alone", async ({ page }) => {
  await page.goto(SETTINGS);
  await expect(page.locator("link[rel='stylesheet'][href*='/plugins/omnabase/assets/admin.css']")).toHaveCount(1);
  await page.goto("/wp-admin/index.php");
  await expect(page.locator("link[rel='stylesheet'][href*='/plugins/omnabase/assets/']")).toHaveCount(0);
});

/**
 * What it checks: The founder gets a picture of every tab, wide and on a phone, to judge the look (a record, not a comparison).
 * Passes when: for each of the six tabs, once its cards are on the page, a full-page picture is saved as
 *   test-results/admin-look/<tab>.png (1280px wide) and <tab>-phone.png (390px wide), each a real image file.
 * Fails when: the pictures are missing or show the old look, so the founder has to click through every tab by hand to see the round's
 *   result.
 */
test("a picture of each tab for the founder", async ({ page }) => {
  mkdirSync(SHOTS, { recursive: true });
  for (const [size, suffix] of [[DESKTOP, ""], [PHONE, "-phone"]] as const) {
    await page.setViewportSize(size);
    for (const tab of TABS) {
      await openTab(page, tab);
      await expect(page.locator(".ob-wp-settings .ob-form__section").first(), `${tab}: the new look is on the page`).toBeVisible();
      const path = resolve(SHOTS, `${tab}${suffix}.png`);
      await page.screenshot({ path, fullPage: true });
      expect(statSync(path).size, path).toBeGreaterThan(1000);
    }
  }
});
