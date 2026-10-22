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
 * confirmation-link times (user meta). Round six: first a best-effort `DELETE /me` that stops the site's key at OmnaBase (only when the vault
 * holds a readable key; whatever OmnaBase answers, the removal goes on), then the front-end switches (option) and the message centre's kept
 * words of a refused question (user meta). M14: the settings screen's dismissed setup line (option). Sites in a subfolder and networks
 * (2026-10-11): on a WordPress network (multisite) everything above but the site id is each site's own, so the removal runs on every site
 * of the network (all of them: WordPress lists only 100 unless asked for all), each site stopping its own key; the five user notes that
 * belong to a site's connection are user options, stored under the site's table prefix (`wp_2_omnabase_known` …); the customer's site id is
 * shared by the network's sites and goes once at the end. The vault's key file (2026-10-11): the key file and its folder (`omnabase/` under
 * wp-content or the main site's uploads folder, with its `index.php` and `.htaccess`) are one per network and go once, after every site has
 * stopped its key at OmnaBase (that stop still needs the key).
 *
 * @package OmnaBase
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The plugin is not loaded during uninstall, so its autoloader is required here (for the key's stop at OmnaBase).
$omnabase_can_call = is_readable( __DIR__ . '/vendor/autoload.php' );
if ( $omnabase_can_call ) {
	require_once __DIR__ . '/vendor/autoload.php';
}

/**
 * Removes everything the plugin stored on the current site.
 *
 * @param bool $can_call Whether the plugin's classes can be loaded (for the key's stop at OmnaBase).
 */
$omnabase_uninstall_site = static function ( bool $can_call ): void {
	global $wpdb;

	// Best effort, before the vault goes: OmnaBase stops this site's key (and with it the site's own webhook addresses). A site that never
	// connected makes no call.
	if ( $can_call ) {
		$vault = new \OmnaBase\WordPress\Security\Vault();
		if ( \OmnaBase\WordPress\Security\Vault::OK === $vault->state() ) {
			( new \OmnaBase\WordPress\Api\Client( $vault ) )->revoke();
		}
	}

	$options = array(
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
		'omnabase_display',
		'omnabase_appearance',
		'omnabase_setup',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// This site's user notes, for every user, under the site's own prefix (WordPress user options): each administrator's pending connect,
	// the "known to OmnaBase" mark, the message centre's fingerprint, its confirmation-link times and the kept words of a refused question.
	$notes = array( 'omnabase_pending_connect', 'omnabase_known', 'omnabase_portal_fingerprint', 'omnabase_portal_links', 'omnabase_portal_draft' );
	foreach ( $notes as $note ) {
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . $note, '', true );
	}

	// Every topic's ticket number and every posted reply's OmnaBase message id.
	delete_post_meta_by_key( '_omnabase_ticket' );
	delete_post_meta_by_key( '_omnabase_message_id' );

	// The outbox's timer (the job every minute and the one-off run after a new row) and the forum's waiting subscriber mails.
	wp_clear_scheduled_hook( 'omnabase_outbox' );
	wp_clear_scheduled_hook( 'omnabase_outbox_now' );
	wp_unschedule_hook( 'omnabase_forum_mails' );

	// The screen's one-time messages (`omnabase_notice_<user id>` and their timeouts), whoever they were for.
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
};

// A network: every site, each with its own settings, table, jobs, notes and key (`number` 0: all sites, not WordPress's default 100).
if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $omnabase_site ) {
		switch_to_blog( (int) $omnabase_site );
		$omnabase_uninstall_site( $omnabase_can_call );
		restore_current_blog();
	}
} else {
	$omnabase_uninstall_site( $omnabase_can_call );
}

// Every user's site id, shared by a network's sites: once, for every user.
delete_metadata( 'user', 0, 'omnabase_site_id', '', true );

// The vault's key file and its folder, shared by a network's sites: once, last (every site's stop at OmnaBase above still needed it).
if ( $omnabase_can_call ) {
	( new \OmnaBase\WordPress\Security\KeyFile() )->remove();
}
