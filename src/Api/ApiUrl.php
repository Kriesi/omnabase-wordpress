<?php
/**
 * Where the plugin calls OmnaBase, and where it sends an administrator to connect.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * One pinned host: https://app.omnabase.com, for the API and for the connect page. Two wp-config.php constants point a development site
 * elsewhere: `OMNABASE_API_URL` (the API, for instance OmnaBase's test server) and `OMNABASE_APP_URL` (the connect page, for instance
 * OmnaBase's web app on Vite); the settings screen says so whenever either is set.
 */
final class ApiUrl {

	public const PRODUCTION = 'https://app.omnabase.com';

	/** OmnaBase's privacy policy and terms, linked from the suggested privacy text (the readme names the same addresses). */
	public const PRIVACY = self::PRODUCTION . '/legal/privacy';
	public const TERMS   = self::PRODUCTION . '/legal/terms';

	/** The API's base address, without a trailing slash. */
	public static function base(): string {
		return self::override() ?? self::PRODUCTION;
	}

	/** The development address from `OMNABASE_API_URL`, or null when the plugin talks to production. */
	public static function override(): ?string {
		return self::constantUrl( 'OMNABASE_API_URL' );
	}

	/** The app's base address (the connect page), without a trailing slash. */
	public static function app(): string {
		return self::appOverride() ?? self::PRODUCTION;
	}

	/** The development address from `OMNABASE_APP_URL`, or null when the connect page is production's. */
	public static function appOverride(): ?string {
		return self::constantUrl( 'OMNABASE_APP_URL' );
	}

	/**
	 * The full address of a public API path.
	 *
	 * @param string $path A path under /api/public/v1, such as `/me`.
	 */
	public static function publicApi( string $path ): string {
		return self::base() . '/api/public/v1' . $path;
	}

	/**
	 * A constant's address without its trailing slash, or null when undefined or empty.
	 *
	 * @param string $name The constant.
	 */
	private static function constantUrl( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}
		$url = rtrim( (string) constant( $name ), '/' );
		return '' === $url ? null : $url;
	}
}
