/**
 * M13 round six, the red team's failing check: uninstall on a connected, busy site removes everything of the plugin and tells OmnaBase once.
 * Not a browser flow: it drives the development WordPress (8888) through WP-CLI and asks OmnaBase's e2e server whether the site's key still
 * works; it lives here because it needs both running.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 10: "Uninstall removes every option, usermeta, post meta, transient,
 * scheduled job and the outbox table, after a best-effort `DELETE /me`"; "Round six" *Red team*: "uninstall leaves nothing of ours, the outbox
 * table included". The coordinator: "an integration test: activate, connect through the harness, create outbox rows and forum meta and
 * transients, run `uninstall.php` the way WordPress does (`WP_UNINSTALL_PLUGIN`), then nothing of the plugin's remains in options, tables,
 * meta, transients, cron events; the `DELETE /me` attempted once against the e2e server".
 *
 * Why here and not in tests/Integration: the integration layer runs every test inside a database transaction and turns `DROP TABLE` into a
 * no-op on temporary tables, so the outbox table could never be seen gone, and its OmnaBase is faked; here the real database and the real
 * e2e server answer. WordPress's own way is followed: the plugin is deactivated, then `uninstall_plugin()` (what Plugins › Delete calls) defines
 * `WP_UNINSTALL_PLUGIN` and runs uninstall.php, without deleting files (this site's plugin folder is this checkout; the file never calls
 * `delete_plugins()` or `wp plugin uninstall`). WordPress's `http_api_debug` moment (after every outgoing call) counts the `DELETE /me` calls
 * in the same process.
 *
 * The words: *uninstall*, what WordPress runs when an administrator deletes a plugin; *transient*, a stored value with an expiry; *cron
 * event*, a job WordPress's scheduler (WP-Cron) will run; *outbox*, the plugin's table of calls still to make; *`DELETE /me`*, OmnaBase's
 * call that stops the site's own key.
 *
 * Shared-site rules this file keeps: it reactivates the plugin afterwards (activation makes the outbox table again), forgets the key and
 * leaves no user, post or row of its own.
 *
 * Why this fails today: uninstall.php never calls `DELETE /me` (its header says "Still to come"), so the key keeps working.
 */
import { expect, test } from "./fixtures";
import { SITE_HOST, omnabaseHook, publicApi, wp } from "./harness";

test.describe.configure({ mode: "serial", timeout: 180_000 });

const run = Date.now().toString(36);
let key = "";
let user = "", post = "";
const php = (code: string): string => wp("eval", code).trim();

test.beforeAll(async () => {
  wp("plugin", "activate", "omnabase");
  wp("omnabase-test", "forget-key");
  ({ key } = await omnabaseHook<{ key: string }>("/api/test/apikey", { workspace: "kriesi", site: SITE_HOST, scopes: ["tickets:read", "tickets:write", "customers:read", "customers:write"] }));
  wp("omnabase-test", "set-key", key, "--webhooks=whsec_uninstallProbe000000000001", "--facts=uninstall-facts-secret-0001");
});
test.afterAll(async () => {
  try { wp("plugin", "activate", "omnabase"); } catch { /* reported by the test */ }
  try { wp("omnabase-test", "forget-key"); } catch { /* the vault may be gone already */ }
  if (user) php(`require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( ${Number(user)} );`);
  if (post) php(`wp_delete_post( ${Number(post)}, true );`);
  if (key) await publicApi(key, "DELETE", "/me");
});

/**
 * What it checks: Deleting a connected plugin that has work in flight removes all of it from the site and stops its key at OmnaBase once.
 * Passes when: with the site connected (the key works: GET /me 200), a waiting and a needing-attention outbox row, a user's site id and
 *   "known" mark, a topic's ticket number and a reply's message id, a notice and a form transient, the settings options and a waiting
 *   subscriber-mail job in place, WordPress's uninstall (the plugin deactivated first) leaves no option, user meta, post meta, table or
 *   scheduled job whose name holds "omnabase", makes exactly one DELETE /me, and the key then answers 401.
 * Fails when: a deleted plugin leaves customer ids, ticket numbers or a table of queued messages on the site, or its key keeps working at
 *   OmnaBase for ever (a leaked database could still read every ticket).
 */
test("uninstall removes everything and stops the key once", async () => {
  expect((await publicApi(key, "GET", "/me")).status, "the key works before").toBe(200);
  user = php(`echo wp_insert_user( array( 'user_login' => 'uninst${run}', 'user_email' => 'uninst.${run}@example.com', 'user_pass' => wp_generate_password() ) );`);
  post = php(`echo wp_insert_post( array( 'post_title' => 'Uninstall probe ${run}', 'post_status' => 'publish' ) );`);
  php(`global $wpdb; $t = $wpdb->prefix . 'omnabase_outbox'; $now = time();
    foreach ( array( array( 'user_link', 'waiting' ), array( 'licence_refresh', 'attention' ) ) as $i => $r ) {
      $wpdb->insert( $t, array( 'kind' => $r[0], 'subject_id' => ${Number(user)}, 'dedupe_key' => $r[0] . ':${run}:' . $i, 'idempotency_key' => 'obwp-${run}-' . $i, 'state' => $r[1], 'attempts' => 1, 'next_at' => $now + 3600, 'claimed_until' => 0, 'last_error' => '', 'created_at' => $now ) );
    }
    update_user_meta( ${Number(user)}, 'omnabase_site_id', '${user}-abcdefgh' );
    update_user_meta( ${Number(user)}, 'omnabase_known', '1' );
    update_post_meta( ${Number(post)}, '_omnabase_ticket', 4242 );
    update_post_meta( ${Number(post)}, '_omnabase_message_id', 'msg-${run}' );
    set_transient( 'omnabase_notice_1', array( 'type' => 'info', 'message' => 'probe' ), 600 );
    set_transient( 'omnabase_form_t_${run}', 1, 600 );
    update_option( 'omnabase_forum', array( 'forums' => array( ${Number(post)} ) ) );
    update_option( 'omnabase_portal', array( 'page' => ${Number(post)} ) );
    wp_schedule_single_event( time() + 600, 'omnabase_forum_mails', array( ${Number(post)} ) );
    echo 'ok';`);
  const before = JSON.parse(php(leftoversCode()));
  expect(before.tables, "the outbox table exists before").toEqual([`${php("global $wpdb; echo $wpdb->prefix;")}omnabase_outbox`]);

  wp("plugin", "deactivate", "omnabase");
  const out = JSON.parse(php(`require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $deletes = 0;
    add_action( 'http_api_debug', static function ( $response, $context, $class, $args, $url ) use ( &$deletes ) {
      if ( 'DELETE' === strtoupper( (string) ( $args['method'] ?? '' ) ) && str_ends_with( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), '/api/public/v1/me' ) ) { $deletes++; }
    }, 10, 5 );
    uninstall_plugin( 'omnabase/omnabase.php' );
    echo wp_json_encode( array( 'deletes' => $deletes ) );`).split("\n").pop()!);

  expect(JSON.parse(php(leftoversCode()))).toEqual({ options: [], usermeta: [], postmeta: [], tables: [], cron: [] });
  expect(out.deletes, "one DELETE /me").toBe(1);
  expect((await publicApi(key, "GET", "/me")).status, "the key is stopped").toBe(401);
});

/** PHP that prints every name of the plugin's still in the database (the harness's offline switch and the seed's `_omnabase_seed` marker aside: bin/seed.php's, not the plugin's) */
function leftoversCode(): string {
  return `global $wpdb; $cron = array();
    foreach ( (array) _get_cron_array() as $at => $hooks ) { foreach ( array_keys( (array) $hooks ) as $hook ) { if ( str_contains( (string) $hook, 'omnabase' ) ) { $cron[] = $hook; } } }
    echo wp_json_encode( array(
      'options'  => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%omnabase%' AND option_name <> 'omnabase_test_offline'" ),
      'usermeta' => $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE '%omnabase%'" ),
      'postmeta' => $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} WHERE meta_key LIKE '%omnabase%' AND meta_key <> '_omnabase_seed'" ),
      'tables'   => $wpdb->get_col( "SHOW TABLES LIKE '%omnabase%'" ),
      'cron'     => array_values( array_unique( $cron ) ),
    ) );`;
}
