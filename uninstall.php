<?php
/**
 * Runs when an administrator deletes the plugin under Plugins: removes everything the plugin stored.
 *
 * The list grows with the rounds; the structure guard checks that every option in `Options::ALL` is named here. Still to come:
 * the best-effort `DELETE /me` (round one), usermeta and post meta (rounds one to five), transients and scheduled jobs, and the
 * outbox table `{prefix}omnabase_outbox` (round two).
 *
 * @package OmnaBase
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$omnabase_options = array(
	'omnabase_connection',
);

foreach ( $omnabase_options as $omnabase_option ) {
	delete_option( $omnabase_option );
}
