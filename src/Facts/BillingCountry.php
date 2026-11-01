<?php
/**
 * The customer's billing country, as OmnaBase stores it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * The customer sidebar round (OmnaBase's brief `docs/briefs/customer-sidebar-round.md`, 2026-11-01): the country of a user's billing
 * address, two upper-case letters (ISO 3166-1 alpha-2, "AT"). WooCommerce's billing country first (the user field `billing_country`),
 * else the EDD customer's primary address. Anything that is not two letters is no country: the caller leaves the key out, never sends it
 * empty (OmnaBase empties a field it is sent as null). The plugin sends no language: a WordPress user's language is usually the site's.
 */
final class BillingCountry {

	/**
	 * The user's billing country, or null.
	 *
	 * @param int $user_id The user.
	 */
	public static function ofUser( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}
		return self::code( get_user_meta( $user_id, 'billing_country', true ) ) ?? self::fromEdd( $user_id );
	}

	/**
	 * Two upper-case letters, or null for anything else (a name, three letters, an empty value, a list).
	 *
	 * @param mixed $raw The raw value.
	 */
	public static function code( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$code = strtoupper( trim( $raw ) );
		return 1 === preg_match( '/^[A-Z]{2}$/', $code ) ? $code : null;
	}

	/**
	 * The EDD customer's primary address's country, when EDD is there.
	 *
	 * @param int $user_id The user.
	 */
	private static function fromEdd( int $user_id ): ?string {
		if ( ! function_exists( 'edd_get_customer_address' ) || ! function_exists( 'edd_get_customer_by' ) || ! edd_get_customer_by( 'user_id', $user_id ) ) {
			return null;
		}
		$address = edd_get_customer_address( $user_id );
		return self::code( is_array( $address ) ? ( $address['country'] ?? null ) : null );
	}
}
