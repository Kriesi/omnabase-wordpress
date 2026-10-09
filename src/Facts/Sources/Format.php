<?php
/**
 * Dates and amounts as the facts contract shows them.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Dates as `Y-m-d` (the docs' example): a unix time in the site's time zone, a stored date string by its own date part. Amounts as plain
 * text with the currency inside (`59.00 EUR`): the contract has no currency field and no HTML is wanted on the card.
 */
final class Format {

	/**
	 * A day, or null.
	 *
	 * @param mixed $when A unix time, a `Y-m-d…` string, or nothing (0, '', null).
	 */
	public static function day( $when ): ?string {
		if ( is_int( $when ) || ( is_string( $when ) && ctype_digit( $when ) ) ) {
			return (int) $when > 0 ? wp_date( 'Y-m-d', (int) $when ) : null;
		}
		return is_string( $when ) && 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $when, $m ) && '0000-00-00' !== $m[1] ? $m[1] : null;
	}

	/**
	 * An amount with its currency.
	 *
	 * @param mixed  $amount   The amount.
	 * @param string $currency The currency code.
	 * @param int    $decimals The currency's decimals.
	 */
	public static function money( $amount, string $currency, int $decimals = 2 ): string {
		return trim( number_format( (float) $amount, max( 0, $decimals ), '.', '' ) . ' ' . strtoupper( $currency ) );
	}

	/**
	 * A link into the site's wp-admin, on https whenever the site is (WordPress would otherwise follow the current request's scheme, and
	 * OmnaBase keeps only https links).
	 *
	 * @param string $path The path under wp-admin.
	 */
	public static function adminLink( string $path ): string {
		return admin_url( $path, 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? 'https' : 'admin' );
	}

	/**
	 * Newest first by id (records whose plugin offers no ordering of its own).
	 *
	 * @param array<int,mixed> $records The records.
	 * @param callable         $id      A record's id.
	 * @return array<int,mixed>
	 */
	public static function newestFirst( array $records, callable $id ): array {
		usort( $records, static fn ( $a, $b ) => (int) $id( $b ) <=> (int) $id( $a ) );
		return array_slice( $records, 0, 20 );
	}
}
