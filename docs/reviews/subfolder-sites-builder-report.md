# Fixing round "sites in a subfolder" — builder's report

2026-10-11, the builder (Opus 5.5). The founder's question (2026-10-10): "isn't that a bug? A user installing his WordPress in a subfolder
could not use it." Red team's tests: `docs/reviews/subfolder-sites-red-team-report.md`, copied in unchanged (the plugin's parked files only;
OmnaBase's TypeScript tests in the same parked folder were left alone). Decisions: `DECISIONS.md`, "2026-10-11 — Sites in a subfolder; a
multisite's sub-sites". Nothing committed.

Words used below: *home address*, WordPress's "Site Address" setting, where visitors find the site; *REST address*, the `/wp-json/` address
where WordPress answers other programs (OmnaBase's calls arrive there); *multisite* or *network*, several WordPress sites sharing one
installation; *user meta*, notes stored on a user (on a network, shared by every site); *user option*, WordPress's per-site user note,
stored under the site's table prefix; *the way back*, the settings page OmnaBase sends the administrator back to after Approve.

## What was built

- **A WordPress in a subfolder connects.** The site's address is now its home address with the folder (`https://example.com/blog`), written
  the way OmnaBase writes it: scheme and host in lower case, standard port dropped, doubled slashes collapsed, no trailing slash, the folder's
  letters kept. The Connect link, the exchange (the one call that trades OmnaBase's code for the key) and the kept connection carry it; a
  site without a title is still named by its host. The host-only address is gone from the code.
- **Connect still refuses what OmnaBase can never reach**, each with its reason, none mentioning a folder: no https or another port than 443;
  "Plain" permalinks; a REST address that is not `<address>/wp-json/` (moved by another plugin, index permalinks, another host); and a site
  whose admin, and so the way back, lies outside the address (home at `/blog`, WordPress at `/wordpress`). A home address stored with a
  trailing slash (REST address with a doubled slash) is accepted.
- **On a multisite each site has its own connection.** The five notes that were shared across the network are per site now: the "known to
  OmnaBase" mark, an administrator's pending Connect, the message centre's fingerprint, its confirmation-link count and a refused question's
  kept words. They are WordPress user options through one small class (`UserNotes`); a new connection on one site forgets only that site's
  marks; WordPress's privacy export and erase tools read and erase the current site's notes. The customer's site id stays shared (it names
  the person).
- **Each site its own table and timers.** The outbox's table name is read from the current site every time (it already was). Turning the
  plugin on for a whole network makes every site's table; a site added later gets it on its first load. Turning it off for the network
  clears every site's timers (before, sub-sites' timers kept firing); turning it off on one site clears only that site's.
- **Deleting the plugin from a network cleans every site** (all of them: WordPress lists only 100 unless asked for all), each site stopping
  its own key at OmnaBase; a single site works as before and never asks for a network's sites.
- Readme: the FAQ "Does it work in a subfolder or on a multisite?" (yes; one connection per site), the requirements line without "the root
  of its domain", the uninstall answer mentions networks. One changelog line under `= 0.1.0 — unreleased =`. `.pot` regenerated (371
  entries). `CLAUDE.md`: a rule for subfolders and networks, and the note that reading the site's table prefix is no SQL.
- The parked test harness (`omnabase_test_path`, a subfolder WordPress for a browser flow) is copied in; no flow uses it yet.

## Decided here (details in DECISIONS)

- **User options, not site options,** for the per-site notes: WordPress's own per-site user notes, following `switch_to_blog()`; a site
  option holding every known customer would grow without bound in one row.
- **The way-back check reads the settings page's own address** (the `return` the link sends) instead of WordPress's admin address, which
  answers http outside an https request (it refused every site in the integration run).
- **The kept connection's address** is OmnaBase's answer, or the address the plugin sent when the answer names none.
- **Every network loop** (activation, deactivation, uninstall) asks for every site's id in one query (`number => 0`).

## Tests adjusted (reason in the file and in DECISIONS)

- `tests/Structure/RoundTwoStructureTest.php`, two guards, adjusted, not weakened: the "SQL only in the outbox's table" guard now lets a file
  read the site's table prefix (`$wpdb->get_blog_prefix()`, no query) and refuses every other `$wpdb` use as before; the uninstall guard asks
  for the shared site id under its plain name and the per-site notes under the site's prefix, instead of two plain names (the red team's
  new guard forbids the plain names).
- `tests/e2e/harness.ts` (no test changed): its two calls to OmnaBase's e2e server are tried once more when the connection was already
  closed (`ECONNRESET` after a long WP-CLI step on a loaded machine).

## Test counts

| Layer | Result |
| --- | --- |
| `composer lint` (phpcs) | clean |
| `composer test` (unit + structure) | 212 tests, 2767 assertions (the red team's 35 methods / 54 cases among them) |
| Integration, plain run | 212 tests, 2924 assertions (SubfolderTest's 8 and the adjusted ConnectFlowTest pass) |
| Integration, shops run | 55 tests, 307 assertions |
| Integration, forum run | 48 tests, 638 assertions |
| `npm run typecheck` | clean |
| `npx playwright test` | no failure from this round's change; load flakes passed alone (below) |
| `bin/build-dist.sh` + Plugin Check on the zip | "No errors found" |

**The browser flows** (57 flows; the founder's e2e stack on 3011/4391 was answering and was reused, never restarted). No failure came from
this round's change; every failing flow passed when its file ran alone. The Mac's load average was 23–43 during the round (other projects'
test runs), every flow ran 3–5× slower than in round four.

- **Run one** (19.2 min): 47 passed, 3 failed, 7 did not run. Facts "an outbox row drains after OmnaBase is back" hit the 60-second timeout
  (each outbox run waited half a minute on OmnaBase's refresh); the message centre's setup and the uninstall flow lost their call to
  OmnaBase's e2e server with `ECONNRESET`, which skipped the message-centre file's other 7.
- **Run two** (29.9 min): 48 passed, 3 failed, 6 did not run. The same drain flow and the uninstall flow, both `ECONNRESET` right after a long
  WP-CLI step (in the uninstall flow the checks before it had passed: nothing left behind, exactly one `DELETE /me`), and the forum file's
  setup timed out at a sign-in, which skipped its other 6.
- **Why `ECONNRESET`:** the flows' WP-CLI helpers block Node while WP-CLI runs; on a loaded machine that outlasts the server's 5-second
  keep-alive window, the server closes the idle connection unseen, and the next call reuses it. The harness now tries such a call a second
  time when the connection was already closed (no check changed; DECISIONS).
- **Alone, after the harness change:** message centre 8/8; facts 4/4 (with uninstall, before the harness change, which then hit the reset
  once more); uninstall 1/1; forum 7/7. A third whole-suite run was stopped during its global setup on the coordinator's word; no
  further whole-suite run was started.

3021/4401 and the database on 5435 were not touched; wp-env was neither destroyed nor cleaned; no git command changed the working tree.

## For the coordinator

- **The admin-outside-the-address refusal is contingent** (the red team's finding 4): it stands as long as OmnaBase refuses a way back
  outside the site's folder. If OmnaBase accepts it, the one unit case and the refusal go (a DECISIONS entry).
- **OmnaBase's stricter address rules** (a query, a fragment, a user name, `..`) are not checked on the plugin's side; a real home address
  never carries them, and OmnaBase refuses them at the link.
- **Not changed (finding 6, accepted):** on a network, a customer's new email address reaches only the site where it changed at once; the
  other sites resend it at the customer's next message-centre visit.
- **No browser flow for a subfolder yet:** the harness supports one, but the browser's route would have to strip the folder; a follow-up if
  wanted, once OmnaBase's side of the round is in the e2e stack.
