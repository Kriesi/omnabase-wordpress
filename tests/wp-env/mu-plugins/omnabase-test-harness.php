<?php
/**
 * Plugin Name: OmnaBase test harness (wp-env only)
 * Description: The local WordPress's test plumbing. Mapped into wp-env's mu-plugins by .wp-env.json; never part of the plugin's zip.
 *
 * Two things, both inert unless wp-config.php defines OMNABASE_TEST_MODE (wp-env's config does) on a `local` environment:
 *
 * 1. The harness's network (brief, round zero): the browser and OmnaBase's e2e server reach this WordPress as
 *    https://wp.omnabase.test, but the request arrives at http://localhost:8888 carrying `X-Forwarded-Host: wp.omnabase.test`
 *    (Playwright's route, OmnaBase's relay). For such a request WordPress is told it is on https at that host, so it builds
 *    https://wp.omnabase.test links and never redirects back to localhost. A plain request to localhost:8888 stays as it was.
 * 2. `wp omnabase-test set-key <key>` / `forget-key`: writes the site's OmnaBase key as if the site had connected, so a browser
 *    flow can test "Connected to …" before Connect exists (round one replaces the stored key with the vault and this command with
 *    a connect through the real page).
 *
 * @package OmnaBase
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'OMNABASE_TEST_MODE' ) || true !== OMNABASE_TEST_MODE || 'local' !== wp_get_environment_type() ) {
	return;
}

( static function () {
	$host      = defined( 'OMNABASE_TEST_HOST' ) ? strtolower( (string) OMNABASE_TEST_HOST ) : '';
	$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ? strtolower( trim( (string) $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared to a constant, never stored or printed.
	if ( '' === $host || $forwarded !== $host ) {
		return;
	}
	$_SERVER['HTTPS']       = 'on';
	$_SERVER['HTTP_HOST']   = $host;
	$_SERVER['SERVER_NAME'] = $host;
	$_SERVER['SERVER_PORT'] = '443';
	$origin                 = 'https://' . $host;
	// wp-env defines WP_HOME and WP_SITEURL as http://localhost:8888; core's own filters apply them at priority 10, ours wins after.
	add_filter( 'option_home', static fn () => $origin, 99 );
	add_filter( 'option_siteurl', static fn () => $origin, 99 );
} )();

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'omnabase-test set-key',
		static function ( array $args ) {
			if ( ! class_exists( \OmnaBase\WordPress\Api\StoredKey::class ) ) {
				WP_CLI::error( 'The OmnaBase plugin is not active.' );
			}
			( new \OmnaBase\WordPress\Api\StoredKey() )->save( (string) ( $args[0] ?? '' ) );
			WP_CLI::success( 'Key stored.' );
		},
		array( 'shortdesc' => 'Store an OmnaBase key as if the site had connected (wp-env only).' )
	);
	WP_CLI::add_command(
		'omnabase-test forget-key',
		static function () {
			if ( class_exists( \OmnaBase\WordPress\Api\StoredKey::class ) ) {
				( new \OmnaBase\WordPress\Api\StoredKey() )->forget();
			}
			WP_CLI::success( 'Key forgotten.' );
		},
		array( 'shortdesc' => 'Forget the stored OmnaBase key (wp-env only).' )
	);
}
