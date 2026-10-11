# Red team: the vault's key from a file, the "Stored connection" card goes

2026-10-11, the red team (Opus 5.5). A small fixing round of the WordPress plugin. The founder: "go with the file-based key, remove the block." The tests below are **failing tests written first**; the builder makes them pass. They are **parked**, in no repository:

`/private/tmp/claude-501/-Users-kriesi-Github/5570ce63-2ccb-4a10-ab50-0f12be9aefaf/scratchpad/red-vault-key-parked/tests/`

The folder mirrors this repository's paths. The builder copies the files in unchanged. Each changed existing file carries a note at its top: "adjusted, not weakened", saying what changed and why. Nothing in this repository was changed except this report.

**Words used below.**

- *Vault*: the plugin's one place for the site's OmnaBase key and its two secrets. They are stored scrambled with libsodium's secretbox and can only be read with a separate key.
- *Key file*: the new file holding that separate key.
- *Salts*: the secret phrases in `wp-config.php` (WordPress's own settings file) that WordPress mixes into its cookies. A security plugin's "log everyone out" changes them.
- *Option*: a stored setting in WordPress's database.
- *Template*: a PHP file that prints part of the settings screen.
- *`.pot`*: the translation template, the list of every sentence translators translate.
- *Uninstall*: what runs when an administrator deletes the plugin.
- *Network* (multisite): several sites sharing one WordPress and one file system.
- *Notice*: WordPress's coloured message box at the top of admin pages.
- *Mode 0600*: file permissions under which only the file's owner may read or write it.
- *World-readable*: a file anyone on the server may read.
- *Key id*: a short fingerprint that tells two keys apart without revealing either.
- *wp-env*: the local Docker WordPress the tests run in. Its tests environment is on port 8889.

## The parked files

| Parked file | Layer | Tests | Fail today |
| --- | --- | --- | --- |
| `tests/Unit/VaultKeyTest.php` (new) | unit | 14 | 14 |
| `tests/Unit/VaultTest.php` (adjusted) | unit | 8 | 4 (the 4 changed ones; the 4 unchanged pass) |
| `tests/Structure/VaultKeyStructureTest.php` (new) | structure | 7 | 6 (the readme guard passes today and must keep passing) |
| `tests/Integration/VaultFileTest.php` (new) | integration, plain run | 7 | 7 |
| `tests/Integration/ConnectionTabTest.php` (adjusted) | integration, plain run | 4 unchanged + 2 new (one with 5 tab cases) = 10 cases | 2 (the last-block test and the Connection tab's case; the other four tabs are guards that pass today) |
| `tests/Integration/RoundOneTestCase.php` (adjusted) | integration base class (the key-file helpers) | none of its own | — |
| `tests/Integration/DisconnectAndHealthTest.php` (adjusted) | integration | unchanged count | 0 today; without the adjustment it would fail once the key file exists |
| `tests/Integration/SecretsNeverRenderedTest.php` (adjusted) | integration | unchanged count | 0 today (same reason) |
| `tests/Integration/AdminLookShapes.php`, `AdminLookTest.php` (adjusted) | integration | unchanged count | 0 today; without the adjustment they fail once the card is gone |
| `tests/e2e/admin-look.spec.ts` (adjusted) | browser flow | 1 replaced + 1 new | not run (see below) |
| `tests/e2e/settings-tabs.spec.ts` (adjusted) | browser flow | 1 replaced | not run |

Counts:

- **New or changed tests: 37.**
- **Failing today, among those that could run: 33.**
  - Unit: 18 of 22.
  - Structure: 6 of 7.
  - Integration: 9 cases, in `VaultFileTest` and `ConnectionTabTest`.
- **Browser flows:** the 3 new or changed flows typecheck but were not run.

**Every other test still passes beside the parked files:**

- Unit and structure: 233 tests run together.
- Integration: 224 cases in the plain run.
- The only exceptions are two runner artifacts. They are explained under "What could not run".

## Each test, in three lines

### `tests/Unit/VaultKeyTest.php`

- **test_the_constant_wins_and_no_file_is_made**
  - *What it checks:* A key the site owner put into wp-config.php (OMNABASE_ENCRYPTION_KEY) wins over everything else, and then no key file is made.
  - *Passes when:* with the constant defined and wp-content writable, saving records the source "constant", no key file appears anywhere, the credentials read back, and changing the salts does not matter.
  - *Fails when:* a power user's own key is ignored in favour of a file, or the plugin litters wp-content with a key file it never uses.
- **test_a_writable_folder_gets_one_guarded_key_file**
  - *What it checks:* On an ordinary site the plugin makes one key file in a protected folder, and that file gives nothing away when someone opens its address.
  - *Passes when:* after the first save with wp-content writable, exactly one key file exists in a folder named "omnabase" (under wp-content or its uploads folder), beside an index.php and a .htaccess that refuses every request; only its owner may read it (mode 0600 or stricter); it starts with "<?php", running it prints nothing, it holds the key as text; the source is recorded as "file"; the credentials read back.
  - *Fails when:* the key lands in a file a browser can download, a file any other account on a shared server may read, or nowhere at all (the vault still hangs on the salts and a "log everyone out" disconnects the site).
- **test_the_key_file_is_made_once_and_reused_never_rewritten**
  - *What it checks:* The key file is made once and then only read, also across Disconnect and a new connection.
  - *Passes when:* after a first save, a second save with other credentials, clearing the vault (Disconnect) and a third save, the key file is the same file with the same content throughout, the recorded key id never changes, the last credentials read back; and a save still works when the key file is read-only.
  - *Fails when:* every save makes a new key (two browser tabs saving at once would lock each other out), Disconnect deletes the file, or a site whose host marks the file read-only can no longer connect.
- **test_an_unwritable_folder_falls_back_to_the_salts**
  - *What it checks:* A host where the plugin may not write under wp-content still gets a working, locked vault: the salts, as before.
  - *Passes when:* with wp-content and its uploads folder read-only, saving records the source "salts", no key file exists anywhere, no PHP warning is raised, and the credentials read back.
  - *Fails when:* Connect breaks on locked-down hosts, the screen fills with "Permission denied" warnings, or the credentials are stored unscrambled.
- **test_salts_in_the_database_still_lock_the_vault_silently**
  - *What it checks:* A site whose salts live in the database (wp-config.php still holds WordPress's placeholder) and which cannot write a file is still locked with the salts, without any fuss.
  - *Passes when:* with the placeholder salts and wp-content read-only, saving records "salts", the credentials read back, and no warning of any kind is raised or logged.
  - *Fails when:* such a site cannot connect, or the plugin nags about a weakness the owner can do nothing about (the founder removed that advice from the screen).
- **test_a_vault_locked_with_the_salts_stays_with_the_salts_when_a_folder_becomes_writable**
  - *What it checks:* A vault locked with the salts keeps using the salts after the host starts allowing files.
  - *Passes when:* saved while wp-content was read-only (source "salts"), then wp-content becomes writable: the credentials still read back, the recorded source is still "salts", and changing the salts now makes the vault unreadable (proof it still reads with the salts).
  - *Fails when:* a host change (a migration, a permissions fix) makes the vault try a new key file, so the stored connection suddenly cannot be read and the site stops receiving replies.
- **test_a_vault_locked_with_the_file_stays_with_the_file_when_the_constant_appears**
  - *What it checks:* A vault locked with the key file keeps using the file after someone adds OMNABASE_ENCRYPTION_KEY to wp-config.php.
  - *Passes when:* saved with the key file (source "file"), then the constant is defined: the credentials still read back with the source still "file"; deleting the key file then makes the vault unreadable (proof it still reads with the file).
  - *Fails when:* adding the constant (for example from a hardening guide) silently locks the site out of its own stored connection.
- **test_the_next_save_chooses_again**
  - *What it checks:* A connection saved later chooses its key afresh: once the host allows files, the next connection uses the file.
  - *Passes when:* saved while wp-content was read-only (source "salts"), then wp-content becomes writable and the vault saves again (a new Connect): the source is now "file", a key file exists, and the credentials read back.
  - *Fails when:* a site stays tied to the salts forever although it could now have the sturdier key file.
- **test_a_lost_key_file_means_reconnect_needed_with_its_reason**
  - *What it checks:* When the key file is lost (a restore without wp-content, a cleanup plugin), the site shows "reconnect needed" and knows why.
  - *Passes when:* after the key file is deleted, the state is "unreadable" (not "empty", not "ok"), reading gives nothing, the reason is "file_gone"; reading did not quietly make a new key file; before the loss the reason was empty.
  - *Fails when:* the vault hands out garbage, pretends the site was never connected (no warning appears), blames the salts for a missing file (the owner hunts the wrong cause), or replaces the lost key with a new one behind the owner's back.
- **test_changed_salts_mean_reconnect_needed_with_their_reason**
  - *What it checks:* When a vault locked with the salts meets changed salts, the site shows "reconnect needed" and says the secret keys changed.
  - *Passes when:* saved with wp-content read-only (source "salts"), after the salts change the state is "unreadable" with the reason "keys_changed"; with the salts back it reads again and has no reason; an empty vault has no reason either.
  - *Fails when:* the owner is told a file is missing on a site that never had one, or the warning stays after the salts are back.
- **test_the_key_id_is_short_and_not_the_key**
  - *What it checks:* The vault notes which key locked it by a short fingerprint, never by the key itself.
  - *Passes when:* for each of the three sources (here: the file and the salts) the stored record holds a key id of 1 to 16 characters; for the file it is none of the key's written forms (the text in the file, the raw bytes, base64, hex) and contains none of them.
  - *Fails when:* the "key id" is the key, so a database dump or backup carries both the scrambled credentials and the key that opens them.
- **test_the_key_never_reaches_an_option_or_a_log_line**
  - *What it checks:* The key in the file never leaves the file: not into the database, not into a log line.
  - *Passes when:* after saving, reading, a read that logs a warning (the file made world-readable), Disconnect and a new save, none of the key's written forms (the text in the file, raw bytes, base64, hex) appears in any option of any site, in the network's options, or in any warning or log line.
  - *Fails when:* a database backup, a debug log or a support ticket with a pasted log carries the key that opens the stored credentials.
- **test_a_world_readable_key_file_is_read_and_logged_never_shown**
  - *What it checks:* A key file anyone on the server may read still works, and the plugin notes the risk in the error log, never on screen.
  - *Passes when:* reading a key file with mode 0600 logs nothing; after the file is made readable by everyone (0644), the credentials still read back, at least one warning reaches the error log (or PHP's warning channel), and nothing is printed.
  - *Fails when:* a host's permissive umask (the default mode for new files) breaks the connection, the risk goes unnoticed, or the warning lands on the admin screen or a visitor's page.
- **test_one_key_file_per_network_one_vault_per_site**
  - *What it checks:* On a network, the sites share one key file, and each site keeps its own vault.
  - *Passes when:* on a two-site network, the main site saves one set of credentials and the second site another: exactly one key file exists (not one per site's uploads folder), both sites record the same key id, each site reads back its own credentials, and the second site's save left the main site's stored record unchanged.
  - *Fails when:* every sub-site makes its own key file (a file per site under uploads/sites/…), or one site's save overwrites another site's connection.

### `tests/Unit/VaultTest.php`

- **test_without_constants_and_without_a_writable_folder_the_salts_lock_the_vault**
  - *What it checks:* Without a dedicated encryption key, without real salt constants and without a folder it may write a key file into, the vault is still locked, with the salts WordPress keeps in the database.
  - *Passes when:* with neither OMNABASE_ENCRYPTION_KEY nor AUTH_KEY/AUTH_SALT defined and wp-content read-only, saving records the source "salts" and the credentials read back.
  - *Fails when:* such a site cannot connect at all, or its credentials are stored unscrambled.
- **test_the_encryption_key_constant_wins_over_the_salts**
  - *What it checks:* A dedicated encryption key in wp-config.php wins over the salts, so a salt rotation no longer locks the vault.
  - *Passes when:* with OMNABASE_ENCRYPTION_KEY defined (32 random bytes, base64), the source the vault records is "constant", and after the salts change the credentials still read back.
  - *Fails when:* the vault ignores the dedicated key, so a "log everyone out" disconnects the site anyway.
- **test_real_salt_constants_are_the_salts**
  - *What it checks:* Real salt constants in wp-config.php lock the vault when no key file can be written.
  - *Passes when:* with AUTH_KEY and AUTH_SALT defined as real values and wp-content read-only, saving records the source "salts" and the credentials read back.
  - *Fails when:* a properly configured site on a locked-down host cannot connect.
- **test_the_placeholder_salt_still_locks_the_vault_with_the_salts**
  - *What it checks:* WordPress's placeholder salt (a sample wp-config.php never filled in) still locks the vault, silently: the plugin no longer tells the owner where the lock's key lives.
  - *Passes when:* with AUTH_KEY and AUTH_SALT set to "put your unique phrase here" and wp-content read-only, saving records the source "salts" (never a separate "database" source) and the credentials read back.
  - *Fails when:* such a site cannot connect, or the vault records a source no reading path knows.

### `tests/Structure/VaultKeyStructureTest.php`

- **test_the_stored_connection_card_is_gone**
  - *What it checks:* The "Stored connection" card is gone from the settings screen's code, and the screen no longer asks where the key comes from.
  - *Passes when:* the card's template file no longer exists; no file under templates/ or src/ names it, its classes or its key-source mark; no file under templates/ or src/Admin (the settings screen's code) mentions the key source at all.
  - *Fails when:* a leftover include brings the card back, or the screen keeps computing and passing the key's source, a door for the advice to return.
- **test_the_translation_template_drops_the_cards_sentences**
  - *What it checks:* The translation template drops the card's sentences, so translators are not asked to translate a screen that no longer exists.
  - *Passes when:* languages/omnabase.pot names no line of the card's template and holds none of its sentences ("Stored connection", "How this site locks …", "Advanced") and no sentence naming wp-config.php or OMNABASE_ENCRYPTION_KEY.
  - *Fails when:* the `.pot` was not regenerated, so translators work on dead sentences and the release check (which compares the `.pot` with the code) fails at release time.
- **test_no_visible_string_names_the_constant_or_wp_config**
  - *What it checks:* No sentence a person can read anywhere in the plugin names wp-config.php or OMNABASE_ENCRYPTION_KEY, or says how the connection is locked.
  - *Passes when:* no sentence handed to WordPress's translation functions under src/ or templates/ names wp-config.php, the constant, or the words "encryption" or "locked"; no template prints either name anywhere in its code; the constant's name appears in src/ only as the bare name the vault looks up, and only under the security code.
  - *Fails when:* the advice comes back in a notice, a Details line or a help text, telling an owner to edit a settings file the founder decided they should never have to touch.
- **test_uninstall_removes_the_key_file_once_for_the_network**
  - *What it checks:* Deleting the plugin removes the key file and its folder, once for the whole network, and the uninstall's own list says so.
  - *Passes when:* uninstall.php's header comment names "the key file"; its code outside the per-site part removes a file or folder (or asks the plugin's own code to remove the key file); the per-site part (run once per site of a network) removes no file or folder.
  - *Fails when:* a deleted plugin leaves its key behind in wp-content (the readme promises everything goes), or a network's uninstall tries to delete the shared file once per site.
- **test_the_changelog_says_the_key_file_and_the_advanced_blocks_end**
  - *What it checks:* The release notes tell customers the connection is now locked with a key in a protected file and that the Advanced block is gone, and no other line still promises an Advanced block.
  - *Passes when:* changelog.txt's top section is still "0.1.0 — unreleased", has a line naming a key kept in a file, has a line saying the Advanced block is gone (or removed), every line naming "Advanced" says it is gone, and no line names wp-config.php, the constant, "salts" or "encryption key".
  - *Fails when:* customers are not told, or the notes still say the detail waits behind "Details" and "Advanced" while the screen has no Advanced block.
- **test_the_readme_stays_true**
  - *What it checks:* The readme stays true: deleting the plugin still removes everything it stored, the external-services text still says nothing is sent before Connect, and nothing mentions the removed card.
  - *Passes when:* readme.txt's FAQ still asks "What happens when I delete the plugin?" and answers that it removes everything it stored on the site; the External services section still says nothing is sent before you connect; the readme names neither "Stored connection" nor an "Advanced" block.
  - *Fails when:* the plugin's public page promises a clean removal the code does not deliver, or points owners to a block that no longer exists.
- **test_the_key_file_is_written_in_one_step_with_a_private_mode**
  - *What it checks:* The code that makes the key file makes it in one step and private from the start.
  - *Passes when:* the runtime code under src/ creates a file only if it does not exist yet (fopen's "x" mode) or writes it elsewhere first and moves it into place (rename or link), and sets the private mode 0600 (or 0400).
  - *Fails when:* two administrators clicking Connect at the same moment each write a different key (one of the two connections can never be read again), or the file is born readable by everyone and only tightened afterwards.

### `tests/Integration/VaultFileTest.php`

- **test_a_real_connect_makes_the_guarded_key_file**
  - *What it checks:* A real Connect makes the key file, private and guarded, in the plugin's folder under wp-content.
  - *Passes when:* after Connect, exactly one key file exists in a folder named "omnabase" (under wp-content or its uploads folder), beside an index.php and a .htaccess refusing every request; only its owner may read it; it starts with "<?php" and holds the key as text; the vault records the source "file" and a key id of at most 16 characters.
  - *Fails when:* Connect still locks the connection with the salts (a "log everyone out" disconnects the site), or the key file is readable by every account on a shared server, or a browser could list the folder.
- **test_a_web_request_for_the_key_file_answers_without_the_key**
  - *What it checks:* Opening the key file's address (or its folder's) in a browser gives nothing away.
  - *Passes when:* the tests WordPress's own web server answers a request for the key file either 200 with an empty page or 403 (refused), and the answer holds none of the key's written forms; a request for the folder answers 200 empty or 403 and lists no file.
  - *Fails when:* anyone who guesses the address downloads the key that opens the stored credentials, or browses the folder to find it.
- **test_uninstall_removes_the_key_file_and_its_folder**
  - *What it checks:* Deleting the plugin removes the key file and its folder.
  - *Passes when:* after Connect, running the plugin's uninstall the way WordPress does (OmnaBase answering the key's stop) leaves no key file and no key folder.
  - *Fails when:* a deleted plugin leaves its key behind in wp-content, against the readme's promise that it removes everything it stored.
- **test_disconnect_keeps_the_key_file_and_a_reconnect_reuses_it**
  - *What it checks:* Disconnect keeps the key file, and connecting again reuses it.
  - *Passes when:* after Connect and Disconnect, the vault is empty but the key file is still there with the same content; after a second Connect the vault records the same key id and the file is unchanged.
  - *Fails when:* Disconnect deletes the key (harmless today, but a second site of a network sharing the file would lose its connection), or each Connect writes a new key.
- **test_the_key_files_content_never_reaches_a_page_or_the_database**
  - *What it checks:* The key in the file never reaches a page or the database.
  - *Passes when:* after Connect and a "Check connection", none of the key's written forms (the text in the file, the raw bytes, base64, hex) appears on the settings screen, on any of its five tabs, in the admin notices, in any option or in any user's stored notes.
  - *Fails when:* the key is printed into a page (a page cache or a browser extension could copy it) or saved in the database next to the scrambled credentials it opens.
- **test_a_lost_key_file_reads_reconnect_needed_in_plain_words**
  - *What it checks:* When the key file is lost, the Connection tab and every admin page say "reconnect needed" and say in plain words that the file is gone.
  - *Passes when:* after Connect, with the key folder gone, the connection card is in the "reconnect" state, its words name the file and say it is gone, do not blame changed secret keys, and each line is at most 30 words; the tab still ends with the Status card; the administrators' notice says "OmnaBase: reconnect needed", names the file and does not blame the secret keys; neither names wp-config.php, the constant, encryption or an Advanced block.
  - *Fails when:* the owner is sent hunting for changed secret keys when a backup restore or a cleanup plugin deleted the file, or the line turns into developer advice.
- **test_changed_salts_read_reconnect_needed_in_plain_words**
  - *What it checks:* When the salts locked the vault (no file could be written) and they change, the screen says the site's secret keys changed, not that a file is gone.
  - *Passes when:* with no key folder possible, Connect records the source "salts" and makes no key file; after the salts change, the connection card is in the "reconnect" state and says the secret keys changed (each line at most 30 words); the notice says "OmnaBase: reconnect needed" and the same; neither speaks of a file being gone, nor names wp-config.php, the constant, encryption or an Advanced block.
  - *Fails when:* a host that never allowed the file is told its key file is gone, or the salts case loses its plain explanation.

### `tests/Integration/ConnectionTabTest.php`

- **test_the_status_card_is_the_tabs_last_block_and_no_advanced_block_is_left**
  - *What it checks:* The Connection tab ends with the Status card, and the "Stored connection" card with its Advanced block is gone.
  - *Passes when:* on a connected site after a check, the tab's last card is the Status card; while not connected, its last card is the Connection card; in both states the tab has no closed block whose summary reads "Advanced", nothing with the old card's class or the key-source mark, and no "Stored connection" heading.
  - *Fails when:* the card the founder removed is still there (or comes back empty), or something new hangs below the Status card.
- **test_no_tab_says_how_the_connection_is_locked**
  - *What it checks:* No tab says anything about how the stored connection is locked: not on the open page, not inside Details.
  - *Passes when:* on a site in full use and on a site not connected, the tab's every word (closed blocks included; scripts and styles left out; tooltips and labels included) holds neither "Advanced" nor "Stored connection", "lock", "locks", "locked", any word starting "encrypt", "wp-config", OMNABASE_ENCRYPTION_KEY, "secret keys" or "salts".
  - *Fails when:* the advice the founder removed survives somewhere on the screen (a Details line, a help text, a tooltip), and an owner is again told to edit a settings file.

### `tests/e2e/admin-look.spec.ts`

- **a closed block opens like the kit's More**
  - *What it checks:* A closed block (here "Choose user fields") opens like the kit's More: a summary line with a drawn chevron, the body set in.
  - *Passes when:* on the Customer data tab, the "Choose user fields" block is a kit More whose summary shows no browser triangle but a drawn chevron (a pseudo-element with content); after a click its body is visible and its text starts further right than the summary line.
  - *Fails when:* the closed blocks keep the browser's bare grey triangle, or open into text glued to the left edge under the summary.
- **no tab shows the Stored connection card and the Connection tab ends with Status**
  - *What it checks:* No tab shows the removed "Stored connection" card or an Advanced block, and the Connection tab ends with the Status card.
  - *Passes when:* on each tab no closed block's summary reads "Advanced", nothing carries the old card's class or the key-source mark, and the words "Stored connection", "wp-config" and "OMNABASE_ENCRYPTION_KEY" are nowhere in the page's text (closed blocks included); on the Connection tab the last card is the Status card when the site is connected or needs a reconnect, and the Connection card otherwise.
  - *Fails when:* the card the founder removed still shows in a real browser (a cached build, a leftover include), or something hangs below the Status card.
### `tests/e2e/settings-tabs.spec.ts`

- **the detail waits behind Details**
  - *What it checks:* The key's last four characters are hidden until the owner opens "Details", and the removed Advanced block is gone.
  - *Passes when:* on the Connection tab of the connected site, the key's last four are not visible; after clicking "Details" they are visible; the page has no closed block whose summary reads "Advanced" and no line about where the encryption key comes from.
  - *Fails when:* the first screen an owner sees is full of key characters, the detail cannot be reached at all, or the advice about wp-config.php the founder removed is still on the page.
## The adjusted tests and why

- **`tests/Unit/VaultTest.php`** (round one's vault test)
  - **Why it had to change.** On an ordinary site the vault now writes a key file, so the salts tests have to make sure no file can be written first.
  - **What changed.** Each test runs in its own PHP process with its own read-only temporary wp-content. `WP_CONTENT_DIR` is a constant, so it can only be set once per process. The WordPress functions a key file needs are faked in `setUp()`.
  - **The old `keySource()` tests.** The three tests of `keySource()` ("constant", "salts", "database") became tests of the source the vault records when it saves:
    - the constant still wins;
    - real salts record "salts";
    - the placeholder salts the old screen called "database" also record "salts", silently. This is design point (d): no screen speaks of it any more.
  - **Unchanged.** The round-trip, empty, other-salts and damaged-value tests ask exactly what they asked before.
- **`tests/Integration/RoundOneTestCase.php`**
  - **Why it had to change.** The key file is the point of this round: changing the salts alone no longer makes a stored connection unreadable.
  - **`rotateSalts()`** now loses the key whatever locked the vault. It changes the salts as before and also moves the key folder aside.
  - **`restoreVaultKey()`** brings both back and removes any key folder made in the meantime. `tear_down()` always calls it, so no test leaves the shared tests WordPress without its key folder.
  - **New helpers:** `keyFolders()`, `keyFiles()`, `hideKeyFolders()`, `blockKeyFolders()`, `vaultRecord()` and `keyMaterial()`.
    - `blockKeyFolders()` puts a plain file where the key folder would go, so no file can be made and the salts lock the vault.
    - `keyMaterial()` lists the key's written forms, for the leak checks.
  - **Not loosened.** Every test that calls `rotateSalts()` still sees an unreadable vault, now on either source.
- **`DisconnectAndHealthTest.php`, `SecretsNeverRenderedTest.php`**
  - **What changed.** They brought the key back with `remove_all_filters( 'salt' )`, which cannot bring back a moved key folder. They now call `restoreVaultKey()`.
  - **Unchanged.** Same assertions, same order.
  - Without this change, the first would wrongly keep showing the warning after "the key is back". The second would read the front page and the two receivers with an unreadable vault, which tests less.
- **`ConnectionTabTest.php`**
  - **Replaced:** `test_the_encryption_advice_sits_in_a_closed_advanced_block_at_the_end`. The block it checked no longer exists.
  - **Two stricter tests in its place:**
    - The tab ends with the Status card (with the Connection card while not connected), with no Advanced block, no key-source mark and no "Stored connection".
    - No tab, connected or not, says anything about how the connection is locked. This includes the inside of Details, which the old test left alone.
  - **Unchanged:** the four other tests.
- **`AdminLookShapes.php`, `AdminLookTest.php`**
  - The Connection tab's list of cards loses `ob-wp-settings__advanced`.
  - The tab's closed blocks go from at least 2 to at least 1: the Status card's Details is left. Every closed block is still checked to be the kit's More (the "show more" component from OmnaBase's own component kit).
  - The PM lines name the sections as they are now.
- **`tests/e2e/admin-look.spec.ts`**
  - **Replaced:** "Advanced opens like the kit's More". It becomes "a closed block opens like the kit's More", run on the Customer data tab's "Choose user fields". Every site shows that block, whatever its connection state. The look checks are the same.
  - **New:** a flow checking that no tab shows the card or an Advanced block, and that the Connection tab ends with the Status card (or the Connection card while not connected).
- **`tests/e2e/settings-tabs.spec.ts`**
  - "The detail waits behind Details and Advanced" becomes "the detail waits behind Details".
  - The key's last four are still hidden until Details is opened.
  - The flow now also asserts that no Advanced block and no key-source line exist at all. The old flow only asked them to be hidden.

Not changed, but they rely on the adjusted base class: `ReceiversTest` (503 while the vault is unreadable) and `PlainWordsTest` (its "unreadable" state now reads as the lost file). Both pass with the adjusted base class.

## What ran, and what could not

**Unit and structure** ran through PHPUnit pointed at the parked files. The runner is beside the folder: `red-vault-key-run/phpunit.xml`, `bootstrap.php`, and `phpunit-combined.xml` for the run together with the repository's suites.

- Today: 18 of the 22 unit tests fail and 6 of the 7 structure tests fail.
- Run together with the repository's own suites (the repository's `VaultTest.php` swapped for the parked one), 233 tests run and only the parked failures appear.

**A sketch shows the unit tests can pass.** A scratch proof made all 22 parked unit tests pass:

- the sketch is a copy of `src/` with a sketch `KeyFile` and a sketch vault, in `red-vault-key-proof/`;
- the run is `red-vault-key-run/phpunit-proof.xml`.

It is a sketch, not a proposal for the builder's code, and it is not in the repository.

**Integration** ran in the tests environment (8889) only, by the previous red team's method, with `red-vault-key-run/int.sh`:

1. The parked files are copied into the tests-cli container's `/tmp/red-vault-key/`. They are laid out like the plugin, with links to the plugin's top-level files, because the parked base class boots the plugin from two folders up.
2. PHPUnit runs against the repository's integration bootstrap, with the parked base classes winning over the repository's.
3. The copy is removed.

Results:

- **Parked files with `ReceiversTest` and `PlainWordsTest`:** 74 cases. The 9 expected failures appear (7 in `VaultFileTest`, 2 in `ConnectionTabTest`); everything else passes.
- **The whole plain run with the parked files swapped in:** 224 cases. The 9 expected failures appear, plus 2 runner artifacts:
  - `PluginsListTest` and `FormShortcodeTest` fail only because, through `/tmp`, the plugin's file path is `/tmp/red-vault-key/omnabase.php`. Its Plugins-list name and its asset addresses come out wrong.
  - Run the normal way, both pass (14 of 14).
- **Leftovers:** the run created no key file, because no key file exists yet. It left no folder and no `/tmp` copy behind.
- **The web server's answer was checked by hand** in the tests environment, with a throwaway guarded folder that was then removed:
  - a PHP file that ends the request answers 200 with an empty page;
  - with the `.htaccess` deny, the file and the folder answer 403;
  - Apache's 403 page names neither the file nor the key.
- **Not needed:** the shops and forum runs. No parked test is in those groups.

**Browser flows** were not run, because they need the development WordPress (8888) and OmnaBase's e2e stack (3011/4391). Both are off-limits for this round, as ordered.

- Their TypeScript typechecks in a scratch copy of `tests/e2e` with the parked files swapped in (`red-vault-key-run/tc/`, `tsc -p`: clean).
- Nothing touched the development WordPress, the founder's LocalWP site, or OmnaBase's stack.
- No git command was run.

## The shapes pinned, and what the builder may rename

Each name below is the red team's choice. The builder may rename any of them and tell the red team.

1. **`new Vault()` with no arguments keeps working.** `uninstall.php`, the test harness's `set-key` and every test build it so.
2. **The vault's stored option (`Options::VAULT`) is an array holding:**
   - `source`, one of `constant`, `file`, `salts`;
   - a key id under `kid` (or `key_id`), 1–16 characters, never containing any written form of the key.
3. **The reason a vault cannot be read: `Vault::unreadableReason(): ?string`.**
   - `null` while the vault reads or is empty;
   - `file_gone` when it was locked with the key file and that file is missing;
   - `keys_changed` when it was locked with the salts and they changed.
   - Anything else (such as a damaged value) is open.
4. **Reading never writes a key file.** After the file is lost, a read leaves the reason at `file_gone`; it does not make a new key behind the owner's back.
5. **The key folder.**
   - It is named `omnabase` and lives either under `WP_CONTENT_DIR` or under the uploads folder (the main site's on a network). This candidate list sits in one place: `RoundOneTestCase::keyFolders()`. The unit test accepts both.
   - The key file is a `.php` file in that folder, other than `index.php`.
   - Beside it are `index.php` and a `.htaccess` holding "Require all denied" or "Deny from all".
   - On a network there is one file, never one per sub-site's uploads folder.
6. **The key file's content.**
   - It starts with `<?php`.
   - Running it prints nothing: it ends the request before the key.
   - It holds the key as base64 or hex text, which the leak checks need in order to find it.
   - Its mode lets nobody but the owner in (`& 0077 === 0`).
7. **The world-readable warning** goes to PHP's error log (`error_log()`), `trigger_error()` or `wp_trigger_error()`. Any of the three counts. Reading a key file with mode 0600 warns about nothing. A site with salts in the database and no writable folder warns about nothing either.
8. **The reasons' words** are the coordinator's:
   - the lost file's line says "file" and "gone";
   - the salts' line says "secret keys" and "changed";
   - in both the connection card (`data-state="reconnect"`, each line at most 30 words) and the "OmnaBase: reconnect needed" notice;
   - neither names `wp-config`, the constant, "encrypt…", "salts" or "Advanced".
9. **No word about the lock on any tab, connected or not.** None of these words appears anywhere, Details included: "Advanced", "Stored connection", "lock/locks/locked", "encrypt…", "wp-config", `OMNABASE_ENCRYPTION_KEY`, "secret keys", "salts". The reconnect state is the only exception, for its reason line.
10. **Structure.**
    - `templates/admin/partials/advanced.php` is deleted.
    - None of the card's classes or its `data-key-source` mark remain in `src/` or `templates/`.
    - No `keySource` remains in `src/Admin` or `templates/`. The vault itself may keep or drop `keySource()`; nothing calls it.
    - The constant's name appears in `src/` only as the bare name the security code looks up.
    - `uninstall.php`:
      - names "the key file" in its header;
      - removes it outside the per-site closure `$omnabase_uninstall_site` (through `unlink`/`rmdir`/`wp_delete_file`, or a plugin method named remove…/delete…);
      - removes no file inside that closure.
    - The code makes the file in one step and private: `fopen` "x", or `rename`/`link` into place, and `0600`/`0400`.
    - The changelog's unreleased section:
      - has a line about a key kept in a file;
      - has a line saying Advanced is gone;
      - has no other line still promising "Advanced". Today's line "…the technical detail waits behind "Details" and "Advanced"" must be reworded.
    - The readme's delete FAQ and the External services text keep their promises.
11. **Test fakes.** The unit test fakes the WordPress functions a key file plausibly calls, such as `wp_upload_dir`, `wp_mkdir_p`, `switch_to_blog` and the site options. If the builder's code calls another WordPress function, the builder may add a fake for it in `setUp()` without changing an assertion.
12. **The web check's address.** The integration web check calls `http://tests-wordpress` from inside wp-env (`OMNABASE_TEST_WEB` overrides it). It accepts 200 with an empty page, or 403.

## Notes for the builder

- **Do not keep the key in memory across calls.** That means no static cache, and no cache on a vault object that lives for the whole request.
  - Several tests lose the key (or change the salts) after a Connect in the same PHP process.
  - The notice and the connection view hold vault objects built when the plugin starts.
  - Reading a small file on each call is cheap.
- **`WordPress.WP.AlternativeFunctions` will flag the direct file calls** (`fopen`, `chmod`, `rename`, `link`, `unlink`), because WPCS prefers `WP_Filesystem`. `WP_Filesystem` cannot create a file "only if absent" in one step, so a `phpcs:ignore` with the reason is the honest answer. Record it in `DECISIONS.md`.
- **The pending connect seals its verifier through the vault.** That happens before the credentials are saved, so it must use the same choice of key as a save. Otherwise Connect's way back cannot open what Connect sealed.
- **Two existing customer lines are now only true for sites that fall back to the salts:**
  - the unreleased changelog line "if the site's secret keys change, every admin page says 'OmnaBase: reconnect needed'";
  - its copy in `readme.txt`'s Changelog.
  - Worth rewording to "if the stored connection can no longer be read". Not pinned.
- **Stale comments to update in the same change:** the vault's own header comment ("`keySource()` says "database" and the screen says so"), the Connection tab template's header comment, and `SettingsScreen`'s comment ("the encryption advice").
