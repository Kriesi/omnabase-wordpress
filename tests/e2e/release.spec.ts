/**
 * M13 round six, the red team's failing checks on the zip a customer gets: what is inside it, WordPress.org's own Plugin Check on it, and the
 * translation template against its sentences. Not a browser flow: the build and the checks run through the shell and wp-env's cli
 * container, and they live here because they need wp-env running (Plugin Check is installed there) and take a minute.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) "Round six": "Plugin Check clean"; "i18n-ready strings (a `.pot` through
 * `wp i18n make-pot`)"; its *Red team*: "Plugin Check zero errors; … the zip holds no tests, `node_modules` or dev constants". The
 * coordinator: "Plugin Check on the built zip with zero errors (a test that runs the build and the check through wp-env and parses the
 * JSON; warnings listed in the report)"; "the `.pot` present and up to date with the strings".
 *
 * The words: *Plugin Check*, the WordPress.org team's checker every plugin must pass before it is listed; an *error* there blocks a listing,
 * a *warning* is advice; *`.pot`*, the template translators start from; *make-pot*, WP-CLI's command that writes it from the code.
 *
 * Choices this file makes (the builder may change them and tell the red team): the development zip (`bin/build-dist.sh`, the same files as
 * the release zip) is unpacked into `build/check/omnabase` (git-ignored, inside the plugin folder wp-env maps, so the cli container sees it at
 * `/var/www/html/wp-content/plugins/omnabase/build/check/omnabase`) and checked by path with `wp plugin check <path> --format=json`, whose output is a
 * "FILE: <path>" line followed by a JSON list per file. Warnings are attached to the test's report and printed, never failed on.
 *
 * Why this fails today: the zip has no `languages/omnabase.pot`; Plugin Check reports two errors (WordPressVIPMinimum's
 * `suppress_filters` in the forum screen's and the forum posts' queries); there is no `.pot` to compare.
 */
import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, readFileSync, rmSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test } from "./fixtures";
import { REPO } from "./harness";

test.describe.configure({ timeout: 300_000 }); // not serial: each check reports on its own

const CHECK = resolve(REPO, "build/check");
const IN_CLI = "/var/www/html/wp-content/plugins/omnabase/build/check";
let zip = "";
let files: string[] = [];

const run = (cmd: string, args: string[]): string => execFileSync(cmd, args, { cwd: REPO, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"], maxBuffer: 64 * 1024 * 1024 });
const cli = (...args: string[]): string => run("npx", ["wp-env", "run", "cli", "--env-cwd=wp-content/plugins/omnabase", "--", ...args]);

type Finding = { line: number; column: number; type: "ERROR" | "WARNING"; code: string; message: string };
/** Plugin Check's JSON output: "FILE: <path>" then a JSON list of findings, per file */
function findings(out: string): (Finding & { file: string })[] {
  const all: (Finding & { file: string })[] = [];
  const re = /^FILE: (.+)\n(\[.*\])$/gm;
  for (let m = re.exec(out); m; m = re.exec(out)) for (const f of JSON.parse(m[2]!) as Finding[]) all.push({ ...f, file: m[1]! });
  return all;
}
/** the msgids of a .pot (multi-line ids joined) */
function msgids(pot: string): Set<string> {
  const ids = new Set<string>();
  for (const m of pot.matchAll(/^msgid ((?:"(?:[^"\\]|\\.)*"\s*)+)/gm)) {
    const id = [...m[1]!.matchAll(/"((?:[^"\\]|\\.)*)"/g)].map((p) => p[1]).join("").replace(/\\(.)/g, (_, c: string) => (c === "n" ? "\n" : c === "t" ? "\t" : c));
    if (id) ids.add(id);
  }
  return ids;
}

test.beforeAll(() => {
  const out = run("bin/build-dist.sh", []);
  zip = /PKG\s+(\S+\.zip)/.exec(out)?.[1] ?? "";
  if (!zip || !existsSync(zip)) throw new Error(`bin/build-dist.sh made no zip:\n${out}`);
  files = run("unzip", ["-Z1", zip]).split("\n").filter((f) => f && !f.endsWith("/"));
  rmSync(CHECK, { recursive: true, force: true });
  mkdirSync(CHECK, { recursive: true });
  run("unzip", ["-q", zip, "-d", CHECK]);
});
test.afterAll(() => rmSync(CHECK, { recursive: true, force: true }));

/**
 * What it checks: The zip holds what runs on a customer's site and nothing of the workshop.
 * Passes when: every file sits under omnabase/; the main file, uninstall.php, readme.txt, the autoloader, the form stylesheet and
 *   languages/omnabase.pot are inside; no tests, node_modules, bin, wp-env, PHPUnit or Playwright file is; no file mentions the test mode or
 *   the test commands.
 * Fails when: customers install the test harness, a door meant for wp-env only, or a zip without its translation template.
 */
test("the zip holds the plugin and nothing of the workshop", () => {
  expect(files.every((f) => f.startsWith("omnabase/"))).toBe(true);
  for (const needed of ["omnabase.php", "uninstall.php", "readme.txt", "vendor/autoload.php", "assets/form.css", "languages/omnabase.pot"]) expect(files, needed).toContain(`omnabase/${needed}`);
  const dev = files.filter((f) => /(^|\/)(tests?|node_modules|bin|\.github|paid)\/|(^|\/)(\.wp-env[^/]*\.json|phpunit[^/]*\.xml|playwright\.config\.ts|phpcs\.xml\.dist|package(-lock)?\.json|CLAUDE\.md|AGENTS\.md|DECISIONS\.md)$/.test(f));
  expect(dev).toEqual([]);
  const leaks = files.filter((f) => /\.(php|js|css|txt|json)$/.test(f)).filter((f) => /OMNABASE_TEST_MODE|omnabase-test\b/.test(readFileSync(resolve(CHECK, f), "utf8")));
  expect(leaks).toEqual([]);
});

/**
 * What it checks: WordPress.org's Plugin Check finds no error in the zip's files.
 * Passes when: `wp plugin check` on the unpacked zip lists no finding of type ERROR; its warnings are listed in the test's report.
 * Fails when: the plugin cannot be listed on WordPress.org, or a check the reviewers run (escaping, queries, headers) finds a problem first
 *   seen after release.
 */
test("Plugin Check reports no error on the zip", () => {
  const all = findings(cli("wp", "plugin", "check", `${IN_CLI}/omnabase`, "--format=json"));
  const warnings = all.filter((f) => f.type === "WARNING");
  for (const w of warnings) test.info().annotations.push({ type: "plugin-check warning", description: `${w.file}:${w.line} ${w.code} ${w.message}` });
  if (warnings.length) console.log(`Plugin Check warnings (${warnings.length}):\n${warnings.map((w) => `  ${w.file}:${w.line} ${w.code}`).join("\n")}`);
  expect(all.filter((f) => f.type === "ERROR").map((f) => `${f.file}:${f.line} ${f.code}: ${f.message}`)).toEqual([]);
});

/**
 * What it checks: The committed translation template is the one WP-CLI makes from the zip's code today.
 * Passes when: `wp i18n make-pot` on the unpacked zip gives the same set of sentences as languages/omnabase.pot in the repository and in the
 *   zip (none missing, none left over).
 * Fails when: a new sentence never reaches translators, or a removed one is still translated for nothing.
 */
test("languages/omnabase.pot matches the sentences in the code", () => {
  cli("wp", "i18n", "make-pot", `${IN_CLI}/omnabase`, `${IN_CLI}/fresh.pot`, "--slug=omnabase", "--domain=omnabase", "--exclude=vendor");
  const fresh = msgids(readFileSync(resolve(CHECK, "fresh.pot"), "utf8"));
  const committedPath = resolve(REPO, "languages/omnabase.pot");
  expect(existsSync(committedPath), "languages/omnabase.pot").toBe(true);
  const committed = msgids(readFileSync(committedPath, "utf8"));
  expect([...fresh].filter((id) => !committed.has(id)), "missing from the committed .pot").toEqual([]);
  expect([...committed].filter((id) => !fresh.has(id)), "in the .pot but no longer in the code").toEqual([]);
  expect(readFileSync(resolve(CHECK, "omnabase/languages/omnabase.pot"), "utf8")).toBe(readFileSync(committedPath, "utf8"));
});
