<?php
/**
 * A user field named on the Customer data tab, or one key of a list stored in a user field, written `field.key`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * The licence-and-fields round (2026-11-04): `av_purchase_data.supported_until` is the key `supported_until` of the list kriesi.at keeps in
 * the user field `av_purchase_data`. The part before the FIRST dot is the field, the rest is one key; a name with an empty part or a second
 * dot is no path (a field whose own name holds a dot cannot be named here, accepted and recorded in DECISIONS.md). The field part follows
 * the licence field's rule (`MetaPolicy::isLicenceField`), the key part the plain naming rule.
 */
final class FieldPath {

	/**
	 * The field and the key (null for a plain field), or null when the name is no path the source can read.
	 *
	 * @param string $path The name as stored.
	 * @return array{0:string,1:?string}|null
	 */
	public static function split( string $path ): ?array {
		$parts = explode( '.', $path, 2 );
		if ( ! MetaPolicy::isLicenceField( $parts[0] ) ) {
			return null;
		}
		if ( ! isset( $parts[1] ) ) {
			return array( $parts[0], null );
		}
		return str_contains( $parts[1], '.' ) || ! MetaPolicy::isWellFormed( $parts[1] ) ? null : array( $parts[0], $parts[1] );
	}

	/**
	 * Whether a name may be stored as the licence field or the expiry.
	 *
	 * @param string $path The name.
	 */
	public static function isValid( string $path ): bool {
		return null !== self::split( $path );
	}

	/**
	 * The user field a name reads (what WordPress's change moments name), or '' for no path.
	 *
	 * @param string $path The name as stored.
	 */
	public static function field( string $path ): string {
		return self::split( $path )[0] ?? '';
	}

	/**
	 * The stored value a name points at: the field's value, or the key's value inside the list (an array, or an object's property, as
	 * another plugin may store it); null when there is nothing to read.
	 *
	 * @param int    $user_id The user.
	 * @param string $path    The name as stored.
	 * @return mixed
	 */
	public static function read( int $user_id, string $path ) {
		$split = self::split( $path );
		if ( null === $split ) {
			return null;
		}
		$stored = get_user_meta( $user_id, $split[0], true );
		if ( null === $split[1] ) {
			return $stored;
		}
		if ( is_array( $stored ) ) {
			return $stored[ $split[1] ] ?? null;
		}
		return is_object( $stored ) && isset( $stored->{$split[1]} ) ? $stored->{$split[1]} : null;
	}
}
