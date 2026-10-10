<?php
/**
 * Plugin Name: OmnaBase test harness (wp-env only)
 * Description: The local WordPress's test plumbing. Mapped into wp-env's mu-plugins by .wp-env.json (and .wp-env.fresh.json, round six's clean WordPress); never part of the plugin's zip.
 *
 * Five things, all inert unless wp-config.php defines OMNABASE_TEST_MODE (wp-env's config does) on a `local` environment:
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
 * 5. A WordPress in a subfolder (fixing round "sites in a subfolder", the red team, for a browser flow or the founder's loop): while the
 *    option `omnabase_test_path` holds a path (`wp option update omnabase_test_path /sub`), a test-host request that is "in the folder" sees
 *    the site at https://wp.omnabase.test/sub: its Site Address and WordPress Address carry the path, so every link, the REST address and the
 *    Connect link's site name the folder. "In the folder" means: the request's address starts with the path (OmnaBase's relay forwards
 *    `/sub/wp-json/…` as it is), or it carries `X-Forwarded-Prefix: /sub` (the browser's route strips the path before handing a request to
 *    localhost:8888, because WordPress's files are not under /sub there, and names the stripped path in that header); the harness then puts
 *    the path back in front of the request's address, so WordPress reads the request the way a real subfolder install does. A request
 *    without either stays at the root, so a forgotten option never moves the founder's own loop. A subfolder session logs in afresh: the
 *    root's login cookies are scoped to `/` and `/wp-admin`, not to `/sub/wp-admin`. Deleting the option puts the site back at the root.
 *
 * @package OmnaBase
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'OMNABASE_TEST_MODE' ) || true !== OMNABASE_TEST_MODE || 'local' !== wp_get_environment_type() ) {
	return;
}

/**
 * The subfolder this request sees the site in (item 5): the option's path when the request is in the folder, else '' (the root). When the
 * browser's route stripped the path (`X-Forwarded-Prefix`), it is put back in front of the request's address.
 */
function omnabase_test_folder(): string {
	$path = '/' . trim( (string) get_option( 'omnabase_test_path', '' ), '/' );
	if ( '/' === $path || ! preg_match( '#^(/[A-Za-z0-9._~-]+)+$#', $path ) ) {
		return '';
	}
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with the option's path, then only prefixed.
	$prefix = isset( $_SERVER['HTTP_X_FORWARDED_PREFIX'] ) ? '/' . trim( (string) $_SERVER['HTTP_X_FORWARDED_PREFIX'], '/' ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with the option's path, never stored or printed.
	$under  = $uri === $path || str_starts_with( $uri, $path . '/' ) || str_starts_with( $uri, $path . '?' );
	if ( ! $under && $prefix !== $path ) {
		return '';
	}
	if ( ! $under ) {
		$_SERVER['REQUEST_URI'] = $path . ( str_starts_with( $uri, '/' ) ? $uri : '/' . $uri );
	}
	return $path;
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
	$site                   = $origin . omnabase_test_folder();
	// wp-env defines WP_HOME and WP_SITEURL as http://localhost:8888; core's own filters apply them at priority 10, ours wins after.
	add_filter( 'option_home', static fn () => $site, 99 );
	add_filter( 'option_siteurl', static fn () => $site, 99 );
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
