/**
 * The browser flows' network and helpers (brief, round zero, "The harness's network").
 *
 * - The browser opens https://wp.omnabase.test. Playwright's route hands each request to wp-env at http://localhost:8888 with
 *   `X-Forwarded-Host` / `X-Forwarded-Proto: https`, so no certificate, no port 443 and no change to the Mac's network (LocalWP's
 *   router for Omnalingo is untouched). The harness's must-use plugin in wp-env then answers as https at that host.
 * - WordPress (inside Docker) reaches OmnaBase's e2e server at http://host.docker.internal:3011 (`OMNABASE_API_URL` in .wp-env.json).
 * - OmnaBase reaches WordPress through the e2e server's relay (`POST /api/test/relay`), which sends the site's calls to localhost:8888.
 * - Playwright itself talks to OmnaBase's e2e server at http://localhost:3011 for the test hooks.
 */
import { execFileSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import type { BrowserContext } from "@playwright/test";

export const SITE_HOST = "wp.omnabase.test";
export const SITE = `https://${SITE_HOST}`;
export const WP_LOCAL = process.env.WP_LOCAL_URL ?? "http://localhost:8888";
export const OMNABASE_E2E = process.env.OMNABASE_E2E_URL ?? `http://localhost:${process.env.OMNABASE_E2E_PORT ?? "3011"}`;
/** OmnaBase's web app (the connect page), as `OMNABASE_APP_URL` in .wp-env.json names it */
export const OMNABASE_APP = process.env.OMNABASE_APP_URL ?? "http://localhost:4391";
export const REPO = resolve(dirname(fileURLToPath(import.meta.url)), "../..");
export const ADMIN_STATE = resolve(REPO, "tests/e2e/.auth/admin.json");

// headers that describe one hop, never handed on: the local fetch sets its own; compression is left out so the body is plain
const REQUEST_HOP = new Set(["host", "connection", "content-length", "accept-encoding", "keep-alive", "transfer-encoding", "upgrade", "te"]);
const RESPONSE_HOP = new Set(["content-encoding", "content-length", "transfer-encoding", "connection", "keep-alive", "set-cookie"]);

/** sends every request for https://wp.omnabase.test to wp-env, as a TLS front would */
export async function routeSite(context: BrowserContext): Promise<void> {
  await context.route((url) => url.hostname === SITE_HOST, async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    const headers: Record<string, string> = {};
    for (const [k, v] of Object.entries(await req.allHeaders())) if (!REQUEST_HOP.has(k) && !k.startsWith(":")) headers[k] = v;
    headers["x-forwarded-host"] = SITE_HOST;
    headers["x-forwarded-proto"] = "https";
    const body = req.postDataBuffer();
    const res = await fetch(WP_LOCAL + url.pathname + url.search, { method: req.method(), headers, body: body ? new Uint8Array(body) : undefined, redirect: "manual" });
    const out: Record<string, string> = {};
    res.headers.forEach((v, k) => { if (!RESPONSE_HOP.has(k)) out[k] = v; });
    const cookies = res.headers.getSetCookie();
    if (cookies.length) out["set-cookie"] = cookies.join("\n"); // Playwright splits set-cookie on new lines
    const next = inSiteRedirect(req.isNavigationRequest(), res.status, res.headers.get("location"), req.url());
    if (next) {
      // Chromium does not hand the redirect of a fulfilled answer back to this route, so the next address would meet real DNS (there is no
      // wp.omnabase.test): the page sends the browser on itself, a fresh navigation this route takes again (replacing the history entry)
      delete out.location;
      await route.fulfill({ status: 200, headers: { ...out, "content-type": "text/html; charset=utf-8" }, body: `<!doctype html><title>Redirecting</title><script>location.replace(${JSON.stringify(next)})</script>` });
      return;
    }
    await route.fulfill({ status: res.status, headers: out, body: Buffer.from(await res.arrayBuffer()) });
  });
}

/**
 * the absolute address a navigation's redirect leads to when it stays on the site (wp.omnabase.test), else null. M13 round three (the red
 * team): a target that differs from the requested page only by its #fragment (bbPress's reply form posts to the topic and answers with
 * "<topic>#post-<id>") loses the fragment, because `location.replace` to such an address only jumps within the page and the browser would
 * stay on the "Redirecting" page for ever
 */
function inSiteRedirect(navigation: boolean, status: number, location: string | null, from: string): string | null {
  if (!navigation || status < 300 || status >= 400 || !location) return null;
  const to = new URL(location, from);
  const here = new URL(from);
  if (to.hostname !== SITE_HOST) return null;
  if (to.hash && to.origin === here.origin && to.pathname === here.pathname && to.search === here.search) to.hash = "";
  return to.toString();
}

/** runs WP-CLI inside wp-env's cli container (the harness's `wp omnabase-test …` commands, the seed) */
export function wp(...args: string[]): string {
  return execFileSync("npx", ["wp-env", "run", "cli", "--", "wp", ...args], { cwd: REPO, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] });
}

/** a POST to one of OmnaBase's e2e test hooks (`/api/test/…`) */
export async function omnabaseHook<T = Record<string, unknown>>(path: string, data: unknown): Promise<T> {
  const res = await fetch(`${OMNABASE_E2E}${path}`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(data) });
  if (!res.ok) throw new Error(`${path} answered ${res.status}: ${await res.text()}`);
  return (await res.json()) as T;
}

/** a call to OmnaBase's public API with a site key, as the plugin makes it */
export async function publicApi<T = Record<string, unknown>>(key: string, method: string, path: string, data?: unknown): Promise<{ status: number; body: T }> {
  const res = await fetch(`${OMNABASE_E2E}/api/public/v1${path}`, { method, headers: { authorization: `Bearer ${key}`, ...(data === undefined ? {} : { "content-type": "application/json" }) }, body: data === undefined ? undefined : JSON.stringify(data) });
  const text = await res.text();
  return { status: res.status, body: (text ? JSON.parse(text) : null) as T };
}
