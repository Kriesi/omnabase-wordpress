<?php
/**
 * Runs when an administrator deletes the plugin under Plugins: removes everything the plugin stored.
 *
 * The list grows with the rounds; the structure guard checks that every option in `Options::ALL` is named here. Round one: the options
 * (the vault, the kept connection, the last check, the last ping, the deliveries log, the handled delivery ids), each administrator's
 * pending connect (user meta) and the screen's one-time messages (transients). Round two: the customer-data switches, the facts log, the
 * outbox's schema version, every user's site id and "known to OmnaBase" mark (user meta), the outbox's two scheduled jobs and its table
 * `{prefix}omnabase_outbox`. Still to come: the best-effort `DELETE /me` (round six), post meta (rounds three to five).
 *
 * @package OmnaBase
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$omnabase_options = array(
	'omnabase_vault',
	'omnabase_site',
	'omnabase_health',
	'omnabase_last_ping',
	'omnabase_deliveries',
	'omnabase_handled',
	'omnabase_facts',
	'omnabase_facts_log',
	'omnabase_db_version',
);

foreach ( $omnabase_options as $omnabase_option ) {
	delete_option( $omnabase_option );
}

// Every administrator's pending connect, every user's site id and "known to OmnaBase" mark.
delete_metadata( 'user', 0, 'omnabase_pending_connect', '', true );
delete_metadata( 'user', 0, 'omnabase_site_id', '', true );
delete_metadata( 'user', 0, 'omnabase_known', '', true );

// The outbox's timer: the job every minute and the one-off run after a new row.
wp_clear_scheduled_hook( 'omnabase_outbox' );
wp_clear_scheduled_hook( 'omnabase_outbox_now' );

// The screen's one-time messages (`omnabase_notice_<user id>` and their timeouts), whoever they were for.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall: transients by prefix, no API for that, nothing to cache.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_' ) . '%' . $wpdb->esc_like( 'omnabase_notice_' ) . '%'
	)
);

// The outbox's table, with every call still queued (the site is leaving OmnaBase).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}omnabase_outbox" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall: the plugin's own table, only the site's prefix in it.
