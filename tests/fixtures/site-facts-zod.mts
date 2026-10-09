/**
 * M13 round two (the red team): the corpus that ties the unit tests' contract oracle (`tests/Unit/Support/SiteFactsOracle.php`) to OmnaBase's
 * real reader. Each case is an answer the plugin could send; OmnaBase's own `readEndpointAnswer` (`packages/server/src/integrations/
 * endpoint.ts`, which reads the `omnabase` block with `packages/shared/src/siteFacts.ts`) says whether it is accepted, and the answer's size
 * is checked against the 128 KB the call reads (`guardedPost`'s `maxBytes`). The verdicts are written to `site-facts-zod.json` beside this
 * file; the unit test then proves the PHP oracle agrees with every one, so the shaper's tests judge by OmnaBase's rules, not by a guess.
 *
 * Run it again whenever OmnaBase's facts contract changes (it needs the OmnaBase repository and its `pnpm install`):
 *   OMNABASE_REPO=../omnabase ../omnabase/packages/server/node_modules/.bin/tsx tests/fixtures/site-facts-zod.mts
 */
import { writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(process.env.OMNABASE_REPO ?? resolve(here, "../../../omnabase"));
const { readEndpointAnswer, ENDPOINT_LIMITS } = (await import(pathToFileURL(resolve(repo, "packages/server/src/integrations/endpoint.ts")).href)) as typeof import("../../../omnabase/packages/server/src/integrations/endpoint");

const x = (n: number, c = "x") => c.repeat(n);
const order = (o: Record<string, unknown> = {}) => ({ number: "4711", date: "2026-09-30", total: "59.00 EUR", status: "completed", ...o });
const sub = (o: Record<string, unknown> = {}) => ({ product: "Enfold updates", status: "active", renewsAt: "2027-05-01", ...o });
const lic = (o: Record<string, unknown> = {}) => ({ product: "Enfold", keyMasked: "••••-7Q2X", status: "active", version: "7.1.5", expiresAt: "2027-05-01", ...o });
const block = (b: unknown) => ({ omnabase: b });
const metaOf = (n: number, last?: unknown) => Object.fromEntries(Array.from({ length: n }, (_, i) => [`key_${i}`, i === n - 1 && last !== undefined ? last : `v${i}`]));

const cases: [string, unknown | string][] = [
  ["nothing at all", {}],
  ["an empty block", block({})],
  ["the block as a list", block([])],
  ["version 1", block({ v: 1 })],
  ["version 2", block({ v: 2 })],
  ["profile as an empty list", block({ profile: [] })],
  ["profile null", block({ profile: null })],
  ["profile empty object", block({ profile: {} })],
  ["meta as an empty list", block({ profile: { meta: [] } })],
  ["meta empty object", block({ profile: { meta: {} } })],
  ["meta value a list", block({ profile: { meta: { prefs: ["a", "b"] } } })],
  ["meta value an object", block({ profile: { meta: { prefs: { a: 1 } } } })],
  ["meta value 1000 characters", block({ profile: { meta: { bio: x(1000) } } })],
  ["meta value 1001 characters", block({ profile: { meta: { bio: x(1001) } } })],
  ["meta value 1000 characters inside spaces", block({ profile: { meta: { bio: `  ${x(1000)}  ` } } })],
  ["meta value 1000 characters inside no-break spaces", block({ profile: { meta: { bio: ` ${x(1000)} ` } } })],
  ["meta value 500 emoji (1000 UTF-16 units)", block({ profile: { meta: { bio: x(500, "😀") } } })],
  ["meta value 501 emoji (1002 UTF-16 units)", block({ profile: { meta: { bio: x(501, "😀") } } })],
  ["meta value 1000 accented letters", block({ profile: { meta: { bio: x(1000, "é") } } })],
  ["meta key 64 characters", block({ profile: { meta: { [x(64, "k")]: "v" } } })],
  ["meta key 65 characters", block({ profile: { meta: { [x(65, "k")]: "v" } } })],
  ["meta 31 good keys", block({ profile: { meta: metaOf(31) } })],
  ["meta 31 keys, the last a list", block({ profile: { meta: metaOf(31, ["a"]) } })],
  ["meta number, true and null", block({ profile: { meta: { n: 3.5, t: true, z: null } } })],
  ["registeredAt null", block({ profile: { registeredAt: null } })],
  ["registeredAt 41 characters", block({ profile: { registeredAt: x(41) } })],
  ["roles 25 good", block({ profile: { roles: Array.from({ length: 25 }, (_, i) => `role${i}`) } })],
  ["roles 21, the 21st too long", block({ profile: { roles: [...Array.from({ length: 20 }, (_, i) => `role${i}`), x(61)] } })],
  ["roles a string", block({ profile: { roles: "customer" } })],
  ["role 61 characters", block({ profile: { roles: [x(61)] } })],
  ["orders as an object with number keys", block({ orders: { "0": order(), "2": order() } })],
  ["orders empty list", block({ orders: [] })],
  ["order number a number", block({ orders: [order({ number: 4711 })] })],
  ["order number empty", block({ orders: [order({ number: "" })] })],
  ["order number blank", block({ orders: [order({ number: "   " })] })],
  ["order number 61 characters", block({ orders: [order({ number: x(61) })] })],
  ["order date null", block({ orders: [order({ date: null })] })],
  ["order total with HTML 60 characters", block({ orders: [order({ total: `<span class="amount">${x(30)}</span>` })] })],
  ["order status 61 characters", block({ orders: [order({ status: x(61) })] })],
  ["21 orders, the 21st broken", block({ orders: [...Array.from({ length: 20 }, () => order()), order({ number: 1 })] })],
  ["order href 2001 characters", block({ orders: [order({ href: `https://shop.example.org/${x(1976)}` })] })],
  ["order href 2000 characters", block({ orders: [order({ href: `https://shop.example.org/${x(1975)}` })] })],
  ["order href on http", block({ orders: [order({ href: "http://shop.example.org/wp-admin/post.php?post=1" })] })],
  ["order href not an address", block({ orders: [order({ href: "not an address" })] })],
  ["order href null", block({ orders: [order({ href: null })] })],
  ["subscription product 121 characters", block({ subscriptions: [sub({ product: x(121) })] })],
  ["subscription product blank", block({ subscriptions: [sub({ product: " " })] })],
  ["subscription product an ideographic space", block({ subscriptions: [sub({ product: "　" })] })],
  ["subscription without product", block({ subscriptions: [{ status: "active" }] })],
  ["licence sites 21, the 21st too long", block({ licences: [lic({ sites: [...Array.from({ length: 20 }, (_, i) => `site${i}.example`), x(201)] })] })],
  ["licence site 201 characters", block({ licences: [lic({ sites: [x(201)] })] })],
  ["licence sites a string", block({ licences: [lic({ sites: "kunde.example" })] })],
  ["licence key unmasked", block({ licences: [lic({ keyMasked: "ABCD-EFGH-IJKL-7Q2X" })] })],
  ["licence version 61 characters", block({ licences: [lic({ version: x(61) })] })],
  ["unknown fields", block({ orders: [order({ currency: "EUR", items: [{ name: "Enfold" }] })], found: false })],
  ["a list as the answer", []],
  ["51 flat fields", Object.fromEntries(Array.from({ length: 51 }, (_, i) => [`f${i}`, "v"]))],
  ["a block over 128 KB", block({ profile: { meta: Object.fromEntries(Array.from({ length: 140 }, (_, i) => [`k${i}`, x(1000)])) } })],
];

const out = cases.map(([name, value]) => {
  const json = JSON.stringify(value);
  const tooLarge = Buffer.byteLength(json, "utf8") > ENDPOINT_LIMITS.maxBytes;
  const read = readEndpointAnswer(json);
  return { name, json, accepted: !tooLarge && read.ok, why: tooLarge ? "too_large" : read.ok ? null : read.reason };
});
writeFileSync(resolve(here, "site-facts-zod.json"), `${JSON.stringify(out, null, 1)}\n`);
console.log(`${out.length} cases, ${out.filter((c) => c.accepted).length} accepted`);
for (const c of out) console.log(`${c.accepted ? "ok     " : "refused"} ${c.name}${c.why ? ` (${c.why})` : ""}`);
