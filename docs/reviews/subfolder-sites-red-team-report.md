# Fixing round "sites in a subfolder" — red-team report (failing tests)

2026-10-11, the red team (Opus 5.5). These are the tests the builder makes pass in this fixing round. The round comes from the founder's question of 2026-10-10: "isn't that a bug? A user installing his WordPress in a subfolder could not use it."

The fix has two halves:

- **A WordPress in a subfolder connects.** Its address is its home address with the folder (`https://example.com/blog`).
- **On a multisite, each site connects on its own.** A *multisite* (a WordPress network) is several sites sharing one WordPress installation. A *sub-site* is one of them, for example `https://localhost/omnabase/` in a subdirectory network.

OmnaBase's side is being fixed in parallel. Its red team parked tests in the same folder (`packages/…`). The plugin's site address is written exactly the way OmnaBase's parked `siteBase` table writes it, so the approval and the exchange compare the same text. The *exchange* is the site's one call that trades the one-time code for its key.

## Where the tests are

The tests are **parked** outside the repository, mirroring its paths:

`/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-subfolder-parked/tests/`

The builder copies them in unchanged. Nothing in this repository was changed except this report.

| Parked file | Layer | Test methods (cases) | Fail today |
| --- | --- | --- | --- |
| `tests/Unit/SiteCheckTest.php` | unit | 6 (25 cases) | 5 methods (22 cases) |
| `tests/Unit/MultisiteTest.php` | unit | 13 | 8 |
| `tests/Unit/Support/FakeNetwork.php`, `FakeWpdb.php` | unit helpers (a fake WordPress network), no tests | — | — |
| `tests/Structure/SubfolderStructureTest.php` | structure | 8 | 7 |
| `tests/Integration/SubfolderTest.php` (extends `RoundOneTestCase`, plain run) | integration | 8 | 6 |
| **New, total** | | **35 methods (54 cases)** | **26 methods** |
| `tests/Integration/ConnectFlowTest.php` | adjusted | 1 method changed | it fails today on the new wording |
| `tests/e2e/uninstall.spec.ts` | adjusted | one arranging line | — |
| `tests/wp-env/mu-plugins/omnabase-test-harness.php` | the test harness, extended | no tests | — |

**The 9 that pass today** guard against a regression; they are not padding:

- the http, other-port and plain-permalinks refusals;
- the outbox table's name follows the current site;
- a sub-site gets its own table;
- network activation leaves every site a table;
- the first-shown marks are per site;
- the single-site uninstall;
- the table name is never remembered;
- an http subfolder site is refused for https;
- the receivers answer under the folder.

## Words used below

- *Home address*: WordPress's "Site Address" setting (`home`), where visitors find the site.
- *WordPress address*: the "WordPress Address" setting (`siteurl`), where WordPress's own files and admin live. Usually it is the same as the home address.
- *REST address*: the `/wp-json/` address where WordPress answers programs. OmnaBase's calls arrive there.
- *Permalinks*: WordPress's page-address setting. "Plain" means `?p=123` addresses, which have no `/wp-json/` address.
- *Index permalinks*: page addresses with `/index.php/` in them.
- *Option*: a stored setting. Each site of a network has its own.
- *User meta*: notes stored on a user. On a network **one set is shared by every site**.
- *User option*: WordPress's per-site user meta, stored under the site's table prefix (`wp_2_…`).
- *Table prefix*: the start of a site's database table names (`wp_` for the main site, `wp_2_` for site 2).
- *Scheduled job / timer*: WP-Cron, WordPress's timer.
- *Activation / deactivation*: turning the plugin on or off. *Network activation* turns it on for every site at once. WordPress then runs the plugin's activation step once, on the main site, with a "network-wide" flag.
- *Uninstall*: what runs when the plugin is deleted. On a network it runs once, for all sites.
- *The way back*: the settings page OmnaBase sends the administrator back to after they approve.
- *`.pot`*: the translation template.

## Findings beyond the contract (for the coordinator)

1. **Five user notes are network-wide today, but belong to one site's connection.** The pinned fix is real; the four multisite tests and one structure guard below enforce it. The five notes are:
   - the "known to OmnaBase" mark;
   - an administrator's pending connect;
   - the message centre's fingerprint;
   - its confirmation-link count;
   - a refused question's kept words.

   User meta is shared across a network, so today:
   - sub-site B reads sub-site A's "known" mark and never introduces the customer to B's own workspace;
   - a new connection on A wipes B's marks;
   - a network administrator who clicks Connect on A, then on B, loses A's pending connect ("not started by your Connect");
   - words written in shop A's message centre show up in shop B's.

   The tests leave the storage open: a WordPress user option or a key that carries the site. The privacy tools (Holdings, Exporter, Eraser) must read and erase the per-site notes.

   The customer's site id (`omnabase_site_id`) may stay shared: it names the person. One known side effect of keeping it shared: an erasure on one sub-site removes it for all of them.
2. **WordPress's `get_sites()` returns at most 100 sites unless told otherwise.** A network uninstall that loops `get_sites()` with default arguments leaves sites 101 and up untouched. This is pinned by a 150-site test.
3. **Deactivation ignores the network-wide flag.** After a network deactivation, every sub-site's outbox timers keep firing for a plugin that is off. This is pinned.
4. **A WordPress address outside the home folder** (home `https://example.com/blog`, WordPress at `https://example.com/wordpress`) gives a way back under `/wordpress/wp-admin`. OmnaBase's parked `connectProblem` test refuses a way back outside the site's folder ("another site").
   - The plugin is pinned to refuse such a site before the link, with a reason. This is *(chosen, contingent)*: if the coordinator rules that OmnaBase accepts that way back, the one unit case goes, with a DECISIONS entry.
   - The usual "WordPress in its own directory" setup (home at the root, WordPress in `/wp`) is a root site and connects.
5. **A home address stored with a trailing slash** gives a REST address with a doubled slash (`https://example.com/blog//wp-json/`). WordPress appends `/wp-json/` to the home address as stored, and its own sanitising keeps the slash. OmnaBase's `…/blog/wp-json/…` still reaches the site, so the REST check is pinned to tolerate the doubled slash *(chosen)*.
6. **Not pinned, for later:** when a customer changes their email address on a network, only the sub-site where it changed tells its OmnaBase at once. The other sub-sites resend at their next message-centre visit, because the fingerprint differs. That is acceptable for now.

## Proof that the tests can pass

The red team wrote a rough sketch of the fix in a scratch copy of the plugin, outside the repository:

`…/scratchpad/red-subfolder-proof/`

All 46 parked unit and structure tests pass against it. Every pinned shape is reachable. The sketch is not for copying. In outline:

- `SiteCheck::base()` normalises the home address. `problem()` compares the normalised `rest_url()` with `base() . '/wp-json'` and checks that `admin_url()` is under `base()`.
- The five notes use `get_user_option`, `update_user_option` and `delete_user_option`. `KnownUsers::forgetAll()` deletes `$wpdb->get_blog_prefix() . 'omnabase_known'` for every user.
- `Bootstrap::deactivate( $network_wide )` loops `get_sites( [ 'fields' => 'ids', 'number' => 0 ] )`.
- `uninstall.php` wraps one site's removal in a closure, loops the sites when `is_multisite()`, and deletes the shared site id once.
- `Holdings` reads user options. `Eraser` deletes user options (and the site id as user meta).

## The tests, each with its three lines

### `tests/Unit/SiteCheckTest.php` (whether a site can connect; the site's address written one way)

1. **The site's address is the home address written one way** (12 cases).
   - What it checks: the plugin writes the site's address one way, the same way OmnaBase does. A WordPress in a subfolder keeps its folder. The host's capitals and a trailing or doubled slash never make another site.
   - Passes when: each home address in the table becomes the expected address:
     - https;
     - the host in lower case;
     - the folder kept with its own letters, without a trailing slash;
     - doubled slashes collapsed;
     - the standard port dropped;
     - the root written with no path.

     The table covers: `https://Example.COM/blog/` → `https://example.com/blog`, `https://example.com/Blog` kept, `https://example.com:443/blog/` → `https://example.com/blog`, `https://localhost/omnabase/` → `https://localhost/omnabase`, the root → `https://example.com`.
   - Fails when: the folder is dropped, and the blog's replies go to the main site's WordPress. Or the plugin writes `https://Example.com/blog/` while OmnaBase approved `https://example.com/blog`, and the exchange is refused.
2. **The host-only origin is gone.**
   - What it checks: the old "origin" (host only) is gone.
   - Passes when: the site check offers no `origin()`.
   - Fails when: an old caller keeps sending the host without the folder.
3. **A site in a folder can connect** (9 cases).
   - What it checks: nothing about a folder stops Connect any more.
   - Passes when: there is no problem for any of these:
     - the root;
     - a folder;
     - two levels;
     - a network's sub-site;
     - the standard port written out;
     - capitals in the host;
     - a trailing slash (REST with a doubled one);
     - WordPress's files in `/wp` under a root home;
     - WordPress in a folder under the home.
   - Fails when: the founder's bug stays.
4. **http, another port and plain permalinks are still refused with their reason** (passes today).
   - What it checks: the refusals that still matter stay, each with its own reason.
   - Passes when: http (also in a folder) is refused naming https; port 8443 is refused naming https or the port; plain permalinks are refused naming permalinks; no reason mentions a subfolder.
   - Fails when: a site OmnaBase can never call is let through, or the admin is told to move WordPress out of its folder.
5. **A REST address elsewhere is refused.**
   - What it checks: a site whose WordPress answers programs somewhere other than the home address plus `/wp-json/` is refused.
   - Passes when: each of these is refused with a reason that says nothing about a subfolder:
     - a REST prefix moved by a plugin (`/blog/api/`);
     - index permalinks (`/blog/index.php/wp-json/`);
     - REST at the host's root while the site lives in `/blog`;
     - REST on another host.
   - Fails when: the admin connects a site whose OmnaBase addresses answer "not found".
6. **An admin outside the home folder is refused before the link** *(chosen, contingent; finding 4)*.
   - What it checks: a site whose WordPress admin lives outside its home address's folder is refused before the link is built.
   - Passes when: with home `/blog` and WordPress at `/wordpress`, there is a problem that says nothing about a subfolder.
   - Fails when: the admin approves on OmnaBase, OmnaBase refuses the way back, and nobody on the WordPress side said why.

### `tests/Unit/MultisiteTest.php` (the plugin on a network, against the fake network)

1. **The outbox table is named by the current site** (passes today).
   - What it checks: each site's waiting calls live in that site's own table.
   - Passes when: `wp_omnabase_outbox` on the main site, `wp_2_omnabase_outbox` after switching to site 2, `wp_omnabase_outbox` again after switching back.
   - Fails when: site 2's topics wait in the main site's table and are sent with the main site's key.
2. **A sub-site gets its own outbox table** (passes today).
   - What it checks: activating on a sub-site makes that site's own table.
   - Passes when: on site 3 the install step makes `wp_3_omnabase_outbox` and notes the version in site 3's settings only.
   - Fails when: a second sub-site reuses or alters another site's table, or never gets its own.
3. **Network activation leaves every site with its own table** (passes today).
   - What it checks: after network activation every site has its own table, made at activation or on the site's first page load.
   - Passes when: activation runs once with the network-wide flag; after each site's first load, sites 1–3 each have a table and a version note; the plugin's start does make a missing table on load.
   - Fails when: only the main site gets a table, and every other site's outbox fails on its first topic.
4. **The known mark is per site.**
   - What it checks: a customer introduced to one sub-site's workspace is not known to another's.
   - Passes when: marked known on site 1, site 2 says not known and site 1 still says known; marking on site 2 leaves both known.
   - Fails when: site 2 never introduces the customer to its own workspace, so its team sees no customer data and licence changes are refused.
5. **A new connection on one site keeps the other sites' marks.**
   - What it checks: connecting one sub-site anew forgets only that site's marks.
   - Passes when: site 2 starts afresh; site 1 still knows the customer.
   - Fails when: reconnecting one shop makes every shop re-introduce all its customers.
6. **A pending connect is per site.**
   - What it checks: a network administrator who clicks Connect on two sub-sites keeps a pending connect on each.
   - Passes when: Connect is started on site 1, then on site 2; both ways back complete.
   - Fails when: the second click overwrites the first and the admin reads "not started by your Connect".
7. **The message centre's notes are per site.**
   - What it checks: a refused question's words and the confirmation-link count belong to the site the customer used.
   - Passes when: words kept on site 1 are absent on site 2; after three links on site 1 (the fourth refused there), site 2 still allows one.
   - Fails when: shop A's question appears pre-filled in shop B, or links asked for in A block confirming in B for a day.
8. **The privacy tools read and erase this site's notes.**
   - What it checks: WordPress's export and erase tools on a sub-site find and remove that sub-site's notes, and leave another sub-site's alone.
   - Passes when: read on site 2, the plugin's holdings name the "known" mark and the kept words; erasing on site 2 removes both there; site 1 keeps its own.
   - Fails when: an export misses the sub-site's notes, or an erasure wipes another shop's records or leaves its own.
9. **The first-shown marks are per site** (passes today).
   - What it checks: the setup line's "first shown" marks are per site.
   - Passes when: the form's first showing on site 2 is noted on site 2 only.
   - Fails when: site 1 ticks "Add the form to a page" because another shop showed its form.
10. **Uninstall on a network cleans every site and stops each key.**
    - What it checks: deleting the plugin from a network removes what it kept on every site and stops every connected site's key.
    - Passes when: on three sites (1 and 2 connected), each planted with everything the plugin keeps (settings, table, jobs, post notes, user notes, written through the plugin's own classes), nothing of the plugin is left anywhere. OmnaBase gets one "stop this key" call from site 1 with site 1's key and one from site 2 with site 2's key, and none for site 3. The main site is current again afterwards.
    - Fails when: sub-sites keep their customer notes, queued messages and still-valid keys, or one site's key stops another's.
11. **Uninstall reaches every site of a large network.**
    - What it checks: the uninstall reaches every site beyond WordPress's default page of 100.
    - Passes when: on 150 sites, no table and no setting is left.
    - Fails when: sites 101–150 keep their data forever.
12. **Uninstall on a single site works as before** (passes today).
    - What it checks: the single-site uninstall is unchanged, and it never asks for a network's sites.
    - Passes when: nothing is left; there is one "stop this key" call with the site's key; the network's sites are never asked for.
    - Fails when: the network loop breaks the common case, or queries a network table a single site does not have.
13. **Deactivation clears the timers of the sites it applies to.**
    - What it checks: network-wide deactivation stops the timers on every site; a sub-site's own deactivation stops only its own.
    - Passes when: no timer is left anywhere after the network-wide deactivation; deactivating on site 2 alone clears site 2 and leaves sites 1 and 3.
    - Fails when: timers keep firing for a plugin that is off, or one shop's deactivation stops another's outbox.

### `tests/Structure/SubfolderStructureTest.php` (file-level checks)

1. **The readme answers the subfolder and multisite question.**
   - What it checks: the FAQ says whether the plugin works in a subfolder and on a multisite.
   - Passes when: the heading "= Does it work in a subfolder or on a multisite? =" is there; the answer starts with "Yes" and says each site has its own (one) connection.
   - Fails when: a customer cannot find out before installing, or believes one connection covers the network.
2. **The readme drops the root-of-the-domain requirement.**
   - What it checks: the readme no longer demands the domain's root.
   - Passes when: there is no "root of its/the domain"; the "Connect needs" line keeps permalinks and https.
   - Fails when: subfolder customers skip the plugin.
3. **The changelog has a line for subfolders and networks.**
   - What it checks: the release notes say subfolders and networks work.
   - Passes when: the top section is still "= 0.1.0 — unreleased ="; a line names a subfolder and a line names multisite or a network.
   - Fails when: refused customers never learn they can connect now.
4. **The subfolder refusal is gone from the `.pot` and the code.**
   - What it checks: the translation template follows the code.
   - Passes when: no `.pot` sentence speaks of a subfolder or the domain's root, and no code under src/ or templates/ still says it.
   - Fails when: translators translate a dead refusal, or it still shows somewhere.
5. **No host-only origin is left.**
   - What it checks: nothing names the site by its host alone.
   - Passes when: no code line under src/ or templates/ defines or calls `origin()`.
   - Fails when: a forgotten call files the blog as another site.
6. **Uninstall goes through every site of a network.**
   - What it checks: the network uninstall loops the sites.
   - Passes when: uninstall.php's code calls `is_multisite(`, `get_sites(`, `switch_to_blog(` and `restore_current_blog(`.
   - Fails when: a network keeps every sub-site's data.
7. **The table name is never remembered** (passes today).
   - What it checks: the outbox table's name is worked out from the current site each time.
   - Passes when: the table class has no static property, and `name()` builds the name from `$wpdb->prefix` or `get_blog_prefix()`.
   - Fails when: a request that switches sites keeps writing to the first site's table.
8. **Per-site user notes are never plain user meta.**
   - What it checks: the five notes are never stored as plain, network-wide user meta.
   - Passes when: no code under src/ hands `Options::KNOWN_META`, `PENDING_CONNECT_META`, `PORTAL_FINGERPRINT_META`, `PORTAL_LINKS_META` or `PORTAL_DRAFT_META` bare to WordPress's user meta functions (`get/update/add/delete_user_meta`, or `delete_metadata( 'user', 0, … )`), and uninstall.php does not delete them by their plain stored names alone.
   - Fails when: one shop's notes are read by another, or the uninstall leaves the per-site notes behind.

   This guard also catches the two places the multisite unit tests do not reach: `Portal\Visitor` and `Sync\ProfileWatcher`, the fingerprint.

### `tests/Integration/SubfolderTest.php` (Connect from a subfolder WordPress, in wp-env's tests WordPress)

The subfolder is made the way a real install stores it: `home` and `siteurl` are set to `https://shop.example.org/sub`. The integration bootstrap already lifts wp-env's fixed addresses for this layer, which is how round one's refusal test made its subfolder.

1. **A subfolder site is offered Connect.**
   - What it checks: a subfolder WordPress is offered Connect with no refusal.
   - Passes when: the Connection tab has the Connect button and no subfolder sentence.
   - Fails when: the founder's bug stays.
2. **The link names the site with its folder.**
   - What it checks: Connect names the site with its folder.
   - Passes when: the link's `site` is `https://shop.example.org/sub`, `name` is the site title, and `return` is `…/sub/wp-admin/options-general.php?page=omnabase`.
   - Fails when: OmnaBase sends the blog's replies to the host's root, or the admin back to the wrong admin.
3. **The exchange and the kept connection carry the folder.**
   - What it checks: the exchange names the same site, and the kept connection holds the folder.
   - Passes when: the exchange's `site` is `…/sub`; the vault holds the key; the kept connection's site address is `…/sub`; the tab says "This site is known to OmnaBase as Kriesi Shop."
   - Fails when: OmnaBase refuses the exchange as made for another site, or the screen loses the line naming the site.
4. **Capitals in the host are written in lower case and the folder kept.**
   - What it checks: the address is written one way with capitals stored.
   - Passes when: stored `https://Shop.Example.org/Sub` gives the link `https://shop.example.org/Sub`, and the exchange sends the same.
   - Fails when: capitals refuse the site, or the link and the exchange differ.
5. **Without a title, the name is the host.**
   - What it checks: a subfolder site without a title is named by its host.
   - Passes when: the link's name is `shop.example.org`.
   - Fails when: the name is empty, or carries the folder and scheme.
6. **The receivers answer under the folder** (passes today).
   - What it checks: the plugin's addresses exist where OmnaBase will call them.
   - Passes when: `rest_url()` gives `…/sub/wp-json/omnabase/v1/events` and `…/customer`, and both routes are registered.
   - Fails when: every reply and customer question meets "not found".
7. **A REST address elsewhere is refused in plain words.**
   - What it checks: a renamed `/wp-json/` is refused before the link, in plain words.
   - Passes when: with the REST prefix renamed to `api`, there is no link and no Connect button; the card's error line gives a reason without "subfolder"; the tab has no banned technical word outside Details and Advanced.
   - Fails when: the admin connects an unreachable site, or reads about folders and "REST".
8. **An http subfolder site is refused for https** (passes today).
   - What it checks: http is refused for https, not for the folder.
   - Passes when: there is no link, and the screen names https and no subfolder.
   - Fails when: an http site is sent to OmnaBase, or told to move out of its folder.

## Adjusted tests (adjusted, not weakened)

- **`tests/Integration/ConnectFlowTest.php`**
  - `test_connect_is_refused_with_the_reason_on_http_plain_permalinks_and_a_subfolder` becomes `…_and_a_rest_address_elsewhere`. The subfolder case is now a must-connect, covered positively by `SubfolderTest`.
  - The third case is now a REST prefix renamed by a plugin (`rest_url_prefix` → `api`), expecting a reason matching `/address|reach/i`. That is what the old "`rest_url()` is not `<origin>/wp-json/`" half of the subfolder refusal guarded.
  - Every case now also asserts the reason says nothing about a subfolder.
  - The http and permalinks cases are unchanged.
  - The link test's comment now says the `site` field carries the folder. Its expected value `https://shop.example.org` is unchanged, because this site is at the root.
  - A note heads the file.
  - Today the new case fails only on the wording: the screen says "root of its domain".
- **`tests/e2e/uninstall.spec.ts`**
  - The arrangement planted the old shared name by hand (`update_user_meta( …, 'omnabase_known', '1' )`). Once the mark is per site, that name is no longer what the plugin stores, and the uninstall would rightly leave it behind.
  - It now marks the user through the plugin's own `KnownUsers` class.
  - The check (no user meta holding "omnabase" left) is unchanged. A note heads the file.
- **Checked and left alone:**
  - `tests/e2e/connect.spec.ts` asserts `site=${encodeURIComponent(SITE)}` for the root site `https://wp.omnabase.test`. A root site's address is unchanged, so it holds.
  - `tests/Integration/PrivacyTest.php` line 97 plants a bare `omnabase_known`. If the builder uses WordPress user options, `get_user_option()` falls back to the bare name, so the exporter test still holds. If the builder picks a key with the site id in it, that line must plant through `KnownUsers` instead, the same adjustment as above.
  - No unit test pinned `origin()`.

## The harness (parked copy)

`tests/wp-env/mu-plugins/omnabase-test-harness.php` gains item 5, a WordPress in a subfolder for the browser loop. It is controlled by the option `omnabase_test_path` (`wp option update omnabase_test_path /sub`).

- **Which requests it moves.** It applies to a test-host request that is "in the folder": the request's path starts with `/sub`, as OmnaBase's relay forwards `/sub/wp-json/…`, or the request carries `X-Forwarded-Prefix: /sub`.
- **What it changes.** For such a request it puts the folder in the Site Address and the WordPress Address. When the browser's route stripped the path, it puts the path back in front of `REQUEST_URI` (the request's address). WordPress's files are not under `/sub` on localhost:8888, so the browser's route must strip it.
- **When it stays inert.** Any other request stays at the root, so a forgotten option never moves the founder's loop.
- **Login.** A subfolder session logs in afresh, because the root's cookies are scoped to `/` and `/wp-admin`.
- **Still missing.** No flow uses it yet. The browser's route (`tests/e2e/harness.ts`, `bin/local-browser.ts`) would need to strip `/sub` and send the header. That is a follow-up for a flow, not written here, because the browser flows must not run in this round.
- **Not used by the integration layer.** The integration layer does not go through the harness. It sets the stored addresses directly, as explained above.

## What ran, and what could not

- **Unit and structure, today's code:** run with `vendor/bin/phpunit -c phpunit.xml --bootstrap <scratch bootstrap> <parked file>`. The scratch bootstrap is `…/scratchpad/red-subfolder-run/bootstrap.php`: the repository's bootstrap plus an autoloader for the parked helpers. Results:
  - 46 tests;
  - the failures are the expected ones (`base()` missing, the subfolder refusal, the network-wide notes, the one-site uninstall and deactivation, the missing readme, changelog and `.pot` changes);
  - the repository's own 166 unit and structure tests still pass beside them, run in one process with no interference.
- **Unit and structure, the proof sketch:** all 46 parked tests pass (bootstrap `…/red-subfolder-run/bootstrap-proof.php`).
- **Integration:** run in the tests environment (8889) without touching the repository. The two parked files were copied into the tests-cli container's `/tmp/red-subfolder/` and run with the repository's `phpunit-integration.xml`.
  - `SubfolderTest`: 8 tests, 6 fail as expected; the receivers and http cases pass.
  - The adjusted `ConnectFlowTest`: 11 tests, 1 fails as expected (the wording).
  - `bin/test-integration.sh` itself cannot take paths outside the plugin's mapped folder.
  - The shops and forum runs were not needed; no parked test is in those groups.
- **Not run:**
  - the browser flows, including the adjusted `uninstall.spec.ts`;
  - anything on the development WordPress (8888) or on OmnaBase's e2e stack (3011/4391), as ordered;
  - the parked harness, only `php -l` on it;
  - `composer lint`, because phpcs excludes `tests/`.
- **A side effect of the fake network:** it writes an empty stand-in for WordPress's `wp-admin/includes/upgrade.php` into the unit layer's fake WordPress folder in the system temp directory. Table creation requires that file; `dbDelta` itself is faked.

## Shapes pinned, and what the builder may rename (tell the red team)

- **The site check.** `Connect\SiteCheck::base(): string` replaces `origin()`, and `origin()` must be gone. `problem(): ?string` stays.
- **The address format.** The site address is: https, the host lower-cased, the folder's letters kept, no trailing slash, doubled slashes collapsed, port 443 dropped, the root with no path. It is the same text in the link's `site`, the exchange's `site` and the kept connection. The link's `name` falls back to the host alone.
- **Refusal words** are the builder's, with these limits:
  - the https and permalinks refusals keep naming their cause;
  - the REST-elsewhere refusal must match `/address|reach/i` in the integration tests;
  - no refusal speaks of a subfolder or the root of the domain;
  - visible words stay free of the banned technical list.
- **Multisite class names and methods,** as today: `Outbox\Table`, `Sync\KnownUsers`, `Connect\ConnectState`, `Portal\Draft`, `Portal\LinkLimit`, `Sync\SiteId`, `Forms\FirstShown`, `Privacy\Holdings::userMeta()`, keyed by `Options`' plain names, and `Privacy\Eraser::erase()`.
- **Deactivation.** `Bootstrap::deactivate( bool $network_wide )` *(chosen)*.
- **Storage of the per-site notes.** It is open (a user option or a site-carrying key). The structure guard only forbids handing the `Options` constants bare to the user meta functions.
- **Readme and changelog.**
  - The FAQ heading is exact: "Does it work in a subfolder or on a multisite?". Its answer starts with "Yes" and says one connection (or its own connection) per site.
  - The changelog lines go under `= 0.1.0 — unreleased =`, naming "subfolder" and "multisite" or "network".
- **Contingent.** The admin-outside-the-home-folder refusal (finding 4) waits on the coordinator's ruling on OmnaBase's way-back rule.
- **The builder may also rename:** the test helpers (`FakeNetwork`, `FakeWpdb`, `putSiteAt`), the option `omnabase_test_path`, and the header `X-Forwarded-Prefix`.
