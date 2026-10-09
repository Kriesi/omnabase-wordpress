<?php
/**
 * Runs when an administrator deletes the plugin under Plugins: removes everything the plugin stored.
 *
 * The list grows with the rounds; the structure guard checks that every option in `Options::ALL` is named here. Round one: the options
 * (the vault, the kept connection, the last check, the last ping, the deliveries log, the handled delivery ids), each administrator's
 * pending connect (user meta) and the screen's one-time messages (transients). Round two: the customer-data switches, the facts log, the
 * outbox's schema version, every user's site id and "known to OmnaBase" mark (user meta), the outbox's two scheduled jobs and its table
 * `{prefix}omnabase_outbox`. Round three: the forum settings, the ticket number on every topic and OmnaBase's message id on every reply the
 * plugin posted (post meta), the subscriber-mail jobs. Round four: what the shortcode form keeps as transients (the time tokens, the counted
 * submissions and token requests, the cached form settings: `omnabase_form_…`); its waiting messages go with the outbox's table. Round five: the message centre's picked page (option), each user's fingerprint and
 * confirmation-link times (user meta). Still to come: the best-effort `DELETE /me` (round six).
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
	'omnabase_forum',
	'omnabase_portal',
);

foreach ( $omnabase_options as $omnabase_option ) {
	delete_option( $omnabase_option );
}

// Every administrator's pending connect, every user's site id and "known to OmnaBase" mark.
delete_metadata( 'user', 0, 'omnabase_pending_connect', '', true );
delete_metadata( 'user', 0, 'omnabase_site_id', '', true );
delete_metadata( 'user', 0, 'omnabase_known', '', true );

// The message centre's per-user fingerprint and its record of confirmation links asked for.
delete_metadata( 'user', 0, 'omnabase_portal_fingerprint', '', true );
delete_metadata( 'user', 0, 'omnabase_portal_links', '', true );

// Every topic's ticket number and every posted reply's OmnaBase message id.
delete_post_meta_by_key( '_omnabase_ticket' );
delete_post_meta_by_key( '_omnabase_message_id' );

// The outbox's timer (the job every minute and the one-off run after a new row) and the forum's waiting subscriber mails.
wp_clear_scheduled_hook( 'omnabase_outbox' );
wp_clear_scheduled_hook( 'omnabase_outbox_now' );
wp_unschedule_hook( 'omnabase_forum_mails' );

// The screen's one-time messages (`omnabase_notice_<user id>` and their timeouts), whoever they were for.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall: transients by prefix, no API for that, nothing to cache.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_' ) . '%' . $wpdb->esc_like( 'omnabase_notice_' ) . '%'
	)
);

// What the shortcode form keeps for a while (`omnabase_form_…` transients and their timeouts): tokens, counted addresses, cached settings.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall: transients by prefix, no API for that, nothing to cache.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( '_transient_' ) . '%' . $wpdb->esc_like( 'omnabase_form_' ) . '%'
	)
);

// The outbox's table, with every call still queued (the site is leaving OmnaBase).
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}omnabase_outbox" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- uninstall: the plugin's own table, only the site's prefix in it.
