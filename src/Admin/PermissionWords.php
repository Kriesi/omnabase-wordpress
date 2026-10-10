<?php
/**
 * The key's permissions in words.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * OmnaBase names a key's permissions by codes (`tickets:read`, `portal` …); the screen says what they let the site do. A read and a write
 * permission on the same thing become one line ("Read and write tickets"). A code this plugin does not know yet is shown as it is.
 */
final class PermissionWords {

	/**
	 * The permissions as lines of words.
	 *
	 * @param string[] $codes The key's permission codes.
	 * @return string[]
	 */
	public static function of( array $codes ): array {
		$lines = array();
		foreach ( array( 'tickets', 'customers' ) as $thing ) {
			$line = self::readWrite( $thing, in_array( $thing . ':read', $codes, true ), in_array( $thing . ':write', $codes, true ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		$known = array( 'tickets:read', 'tickets:write', 'customers:read', 'customers:write', 'forms:write', 'portal' );
		if ( in_array( 'forms:write', $codes, true ) ) {
			$lines[] = __( 'Send contact forms', 'omnabase' );
		}
		if ( in_array( 'portal', $codes, true ) ) {
			$lines[] = __( 'Run the message centre', 'omnabase' );
		}
		return array_merge( $lines, array_values( array_diff( $codes, $known ) ) );
	}

	/**
	 * "Read and write tickets", "Read tickets", "Write tickets", or '' for neither.
	 *
	 * @param string $thing `tickets` or `customers`.
	 * @param bool   $read  The read permission.
	 * @param bool   $write The write permission.
	 */
	private static function readWrite( string $thing, bool $read, bool $write ): string {
		$words = array(
			'tickets'   => array( __( 'Read and write tickets', 'omnabase' ), __( 'Read tickets', 'omnabase' ), __( 'Write tickets', 'omnabase' ) ),
			'customers' => array( __( 'Read and write customers', 'omnabase' ), __( 'Read customers', 'omnabase' ), __( 'Write customers', 'omnabase' ) ),
		);
		if ( $read && $write ) {
			return $words[ $thing ][0];
		}
		return $read ? $words[ $thing ][1] : ( $write ? $words[ $thing ][2] : '' );
	}
}
