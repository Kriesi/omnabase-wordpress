# Fixing round "the vault's key from a file" — builder's report

2026-10-11, the builder (Opus 5.5). The founder (2026-10-11): "go with the file-based key, remove the block"; a customer without coding
knowledge must never be asked to add something to wp-config.php (WordPress's own settings file). Red team's tests:
`docs/reviews/vault-key-red-team-report.md`, its 12 parked files copied in unchanged. Decisions: `DECISIONS.md`, "2026-10-11 — The vault's
key file; the Stored connection card gone". Nothing committed.

Words used below: *vault*, the plugin's one place for the site's OmnaBase key and its two secrets, stored scrambled; *key file*, the file
holding the separate key that unscrambles them; *salts*, the secret phrases in wp-config.php that WordPress mixes into its cookies (a
security plugin's "log everyone out" changes them); *option*, a stored setting in WordPress's database; *notice*, WordPress's coloured
message box at the top of admin pages; *network* (multisite), several sites sharing one WordPress and one file system; *uninstall*, what
runs when an administrator deletes the plugin; *`.pot`*, the translation template.

## What was built

- **The stored connection is locked with a key the plugin makes itself.** On the first save (Connect) the plugin writes a random key into
  `omnabase/key.php` under wp-content (or under the main site's uploads folder when wp-content cannot be written). The file ends the request
  before the key (a browser opening it gets an empty page), and an `index.php` and a `.htaccess` refusing every request sit beside it. It is
  made in one step and private from the start (only its owner may read it), never rewritten, one per network. A "log everyone out" no
  longer disconnects the site.
- **The order** on each save: a site owner's own `OMNABASE_ENCRYPTION_KEY` when defined, else the key file, else the salts (silently, also
  when the salts live in the database). The vault records which one it used and a short fingerprint of the key (never the key), and reads
  back with exactly that one: a host that later allows files, or a constant added later, never locks a site out.
- **"Reconnect needed" says why**: on the connection card and in the notice on every admin page, "the file this site locks its connection
  with is gone" or "the site's secret keys changed" (or no reason when neither is known). Reading never makes a new key behind the owner's
  back.
- **The pending connect** (the step between clicking Connect and coming back from OmnaBase) seals its secret the same way a save does.
- **The "Stored connection" card and its Advanced block are gone.** The Connection tab ends with the Status card (the connection card while
  not connected). No tab mentions wp-config.php, the constant, encryption or how the connection is locked; the screen's data no longer
  carries a key source.
- **Uninstall removes the key file and its folder**, once for the whole network, after every site has stopped its key at OmnaBase.
  Disconnect keeps the file; connecting again reuses it.
- Customer texts: the readme's "What happens when I delete the plugin?" names the key file; the changelog's reconnect line says "if the
  stored connection can no longer be read … and why" (its readme copy too); the "Details and Advanced" line now says the Advanced block is
  gone; a new line "The stored connection is locked with a key the plugin keeps in a protected file; nothing to set up." `.pot`
  regenerated (369 entries). `CLAUDE.md`: a paragraph on the vault's key.

## Decided here (details in DECISIONS)

- **Names kept as the red team pinned them**: `source` (`constant`|`file`|`salts`), `kid` (16 hex characters), `Vault::unreadableReason()`
  with `file_gone` / `keys_changed`, the folder `omnabase`, the file `key.php`, `new Vault()` without arguments. `Vault::keySource()` is
  removed (nothing called it). New classes: `Security\VaultKey` (the choice), `Security\KeyFile` (the file), `Admin\ReconnectWords` (the
  plain words).
- **One step**: a temporary file, made private before the key goes in, hard-linked into place (a link never overwrites); `rename` only
  while the file is still absent, for file systems without hard links. A key file present but unreadable for this process is never
  replaced; that save falls back to the salts.
- **A key file anyone on the server may read** still works and is noted once per request in PHP's error log as a code line (no key, no
  translation: the vault may run before translations load).
- **The key file's template and the `.htaccess` are nowdocs**: server file contents, not words for people; the prose guard would otherwise
  read them as untranslated sentences.
- **A record saved before this round** (no `source`) reads as unreadable: no migration at this stage; the site connects again.

## Tests adjusted (reason in the file and in DECISIONS)

- `tests/Unit/Support/FakeNetwork.php` (the unit layer's fake network): a small file system (wp-content as the stand-in folder, emptied of
  the key folder at each install) and fakes for the six WordPress file helpers the key file calls; `traces()` now also reports a key folder
  left behind, so the network uninstall tests check the key file goes too. No assertion changed.

## The runs

| Layer | Result |
| --- | --- |
| `composer lint` | clean (157 files) |
| `composer test` (unit and structure) | 233 tests, all pass (the 22 parked unit and 7 parked structure tests among them) |
| `composer test:integration`, plain | 224 tests, all pass (the 7 `VaultFileTest` cases and the new `ConnectionTabTest` cases among them; a web request for the key file or its folder gets nothing of the key) |
| `composer test:integration`, shops | 55 tests, all pass |
| `composer test:integration`, forum | 48 tests, all pass |
| `npm run typecheck` | clean |
| `npx playwright test` (one whole run) | 22 passed, 6 failed, 30 did not run (see below) |
| `bin/build-dist.sh` | `build/dist/omnabase-0.1.0-dev.zip` (178 files) |
| Plugin Check on the zip | "No errors found" (no warnings either) |

**The browser flows.** The 22 that passed include both parked flows in `admin-look.spec.ts` ("a closed block opens like the kit's More",
"no tab shows the Stored connection card…"), the parked "the detail waits behind Details", the release checks (zip contents, Plugin Check,
the `.pot`), `fresh.spec.ts` (install and delete on a clean WordPress) and `uninstall.spec.ts`. Those flows connect through WP-CLI, so the
key file made by WP-CLI is read by the web server.

**The 6 failures are the environment, not this round.** Each is the first step of a group that connects through OmnaBase's connect page:
the founder's local stack (3011/4391, reused as ordered, not restarted) already has his LocalWP site `localhost/omnabase` connected to Team
Kriesi's project Enfold, so the connect page asks "Answer customer questions for Enfold from wp.omnabase.test?" and keeps Approve disabled
until a choice is made; the flows (written for a fresh stack) never answer it and time out. The 30 that did not run are the rest of those
serial groups (connect, facts, form, forum, messages, shortcode builder). A second whole run would meet the same question, so none was
started. Seen in the trace's last frame; nothing in the requests failed.

## For the coordinator

- **The connect-based flows need a stack without the LocalWP site's connection** (a restarted stack has a fresh schema), or the flows must
  learn to answer the connect page's "which site answers customer questions" question. Both touch the founder's local loop: his call.
- **Existing connections must connect again** once: a vault saved before this round (no recorded source) now reads "reconnect needed".
  The wp-env sites were saved afresh by the tests; the founder's LocalWP site (not touched) will show it until he clicks Connect again.
- The unreleased changelog keeps the red team's required line naming the Advanced block although customers never saw that block (it came
  and went inside 0.1.0); worth dropping at release if the guard is relaxed.
