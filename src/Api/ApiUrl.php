<?php
/**
 * Where the plugin calls OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * One pinned host: https://app.omnabase.com. The constant `OMNABASE_API_URL` (wp-config.php) points a development site at
 * another one, for instance OmnaBase's test server; the settings screen says so whenever it is set.
 */
final class ApiUrl {

	public const PRODUCTION = 'https://app.omnabase.com';

	/** The base address, without a trailing slash. */
	public static function base(): string {
		$override = self::override();
		return null !== $override ? $override : self::PRODUCTION;
	}

	/** The development address from `OMNABASE_API_URL`, or null when the plugin talks to production. */
	public static function override(): ?string {
		if ( ! defined( 'OMNABASE_API_URL' ) ) {
			return null;
		}
		$url = rtrim( (string) constant( 'OMNABASE_API_URL' ), '/' );
		return '' === $url ? null : $url;
	}

	/**
	 * The full address of a public API path.
	 *
	 * @param string $path A path under /api/public/v1, such as `/me`.
	 */
	public static function publicApi( string $path ): string {
		return self::base() . '/api/public/v1' . $path;
	}
}
