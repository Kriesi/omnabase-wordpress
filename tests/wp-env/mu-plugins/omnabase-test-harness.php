<?php
/**
 * Plugin Name: OmnaBase test harness (wp-env only)
 * Description: The local WordPress's test plumbing. Mapped into wp-env's mu-plugins by .wp-env.json; never part of the plugin's zip.
 *
 * Four things, all inert unless wp-config.php defines OMNABASE_TEST_MODE (wp-env's config does) on a `local` environment:
 *
 * 1. The harness's network (brief, round zero): the browser and OmnaBase's e2e server reach this WordPress as
 *    https://wp.omnabase.test, but the request arrives at http://localhost:8888 carrying `X-Forwarded-Host: wp.omnabase.test`
 *    (Playwright's route, OmnaBase's relay). For such a request WordPress is told it is on https at that host, so it builds
 *    https://wp.omnabase.test links (the plugins' scripts and styles included) and never redirects back to localhost. A plain request to
 *    localhost:8888 stays as it was.
 * 2. `wp omnabase-test set-key <key> [--webhooks=<secret>] [--facts=<secret>]` / `forget-key`: writes the site's OmnaBase key (and
 *    its secrets) into the vault as if the site had connected, so a flow can test the receivers and "Connected to …" with a key minted
 *    by OmnaBase's test hook; round one's own flow (connect.spec.ts) connects through the real page instead.
 * 3. The paid add-ons' fakes (M13 round two): when WooCommerce or EDD is active but WooCommerce Subscriptions, EDD Software Licensing or
 *    EDD Recurring is not, the fake from the plugin's `tests/fakes/` stands in (each is inert when the real add-on is loaded), so the
 *    browser flows and the seed can show subscriptions and licences. Never in the plugin's zip: the fakes and this file live under tests/.
 * 4. OmnaBase "down" (M13 round four, the red team, for form.spec.ts): while the option `omnabase_test_offline` is set
 *    (`wp option update omnabase_test_offline 1`), every call this WordPress makes to OmnaBase's API (the host of `OMNABASE_API_URL`) fails
 *    the way an unreachable server does, before it leaves the site; deleting the option brings OmnaBase back. The e2e server itself keeps
 *    running, so the flow can read its inbox meanwhile.
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
	// M13 round four (the builder's, DECISIONS "M13 round four"): WordPress fixes the content folder's address (WP_CONTENT_URL) from
	// localhost:8888 before this file loads, so plugins' scripts and styles (the shortcode form's own script) would point at
	// https://localhost:8888, which the browser's route does not take; they are moved to the test host like every other link.
	$content = wp_parse_url( (string) WP_CONTENT_URL );
	$local   = ( $content['host'] ?? '' ) . ( isset( $content['port'] ) ? ':' . $content['port'] : '' );
	$swap    = static fn ( $url ) => (string) preg_replace( '#^https?://' . preg_quote( $local, '#' ) . '(?=/|$)#i', $origin, (string) $url );
	add_filter( 'content_url', $swap, 99 );
	add_filter( 'plugins_url', $swap, 99 );
} )();

add_action(
	'plugins_loaded',
	static function () {
		$fakes = WP_PLUGIN_DIR . '/omnabase/tests/fakes/';
		$wanted = array(
			'woocommerce-subscriptions.php' => class_exists( 'WooCommerce' ),
			'edd-software-licensing.php'    => function_exists( 'EDD' ),
			'edd-recurring.php'             => function_exists( 'EDD' ),
		);
		foreach ( $wanted as $file => $shop_active ) {
			if ( $shop_active && is_readable( $fakes . $file ) ) {
				require_once $fakes . $file;
			}
		}
	},
	1 // before the OmnaBase plugin boots (10), after every real plugin is loaded
);

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! get_option( 'omnabase_test_offline' ) || ! defined( 'OMNABASE_API_URL' ) ) {
			return $pre;
		}
		$api = wp_parse_url( (string) OMNABASE_API_URL );
		$to  = wp_parse_url( (string) $url );
		if ( ( $api['host'] ?? '' ) === ( $to['host'] ?? '' ) && ( $api['port'] ?? null ) === ( $to['port'] ?? null ) ) {
			return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect (the test harness has OmnaBase offline)' );
		}
		return $pre;
	},
	1,
	3
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'omnabase-test set-key',
		static function ( array $args, array $assoc ) {
			if ( ! class_exists( \OmnaBase\WordPress\Security\Vault::class ) ) {
				WP_CLI::error( 'The OmnaBase plugin is not active.' );
			}
			( new \OmnaBase\WordPress\Security\Vault() )->save(
				array(
					'key'      => (string) ( $args[0] ?? '' ),
					'webhooks' => isset( $assoc['webhooks'] ) ? (string) $assoc['webhooks'] : null,
					'facts'    => isset( $assoc['facts'] ) ? (string) $assoc['facts'] : null,
				)
			);
			WP_CLI::success( 'Key stored in the vault.' );
		},
		array(
			'shortdesc' => 'Store an OmnaBase key (and optionally its webhook and facts secrets) in the vault as if the site had connected (wp-env only).',
			'synopsis'  => array(
				array( 'type' => 'positional', 'name' => 'key' ),
				array( 'type' => 'assoc', 'name' => 'webhooks', 'optional' => true ),
				array( 'type' => 'assoc', 'name' => 'facts', 'optional' => true ),
			),
		)
	);
	WP_CLI::add_command(
		'omnabase-test forget-key',
		static function () {
			if ( class_exists( \OmnaBase\WordPress\Security\Vault::class ) ) {
				( new \OmnaBase\WordPress\Security\Vault() )->clear();
			}
			WP_CLI::success( 'Key forgotten.' );
		},
		array( 'shortdesc' => 'Empty the OmnaBase vault (wp-env only).' )
	);
}
