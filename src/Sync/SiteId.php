<?php
/**
 * The site's id for a user, as OmnaBase knows them.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Not the bare WordPress user id: a deleted user's number can return (a database restore) and would inherit the old account's tickets. The
 * plugin sends `<user id>-<8 random lower-case letters or digits>`, made once per user and kept in usermeta, so it goes with the user and a
 * reused number gets a new id. Fixed once released (brief, choice 6).
 */
final class SiteId {

	private const RANDOM = 8;

	/**
	 * The user's site id, made on first use; an empty string for a user that does not exist.
	 *
	 * @param int $user_id The WordPress user.
	 */
	public function forUser( int $user_id ): string {
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return '';
		}
		$kept = get_user_meta( $user_id, Options::SITE_ID_META, true );
		if ( is_string( $kept ) && self::belongsTo( $kept, $user_id ) ) {
			return $kept;
		}
		$id = $user_id . '-' . strtolower( wp_generate_password( self::RANDOM, false, false ) );
		update_user_meta( $user_id, Options::SITE_ID_META, $id );
		return $id;
	}

	/**
	 * The user a site id names, or null (unknown, malformed, or the id of a user who is gone).
	 *
	 * @param string $site_id A site id OmnaBase sent.
	 */
	public function userFor( string $site_id ): ?int {
		if ( 1 !== preg_match( '/^([1-9][0-9]*)-[a-z0-9]{' . self::RANDOM . '}$/', $site_id, $m ) ) {
			return null;
		}
		$user_id = (int) $m[1];
		$kept    = get_user_meta( $user_id, Options::SITE_ID_META, true );
		return is_string( $kept ) && '' !== $kept && hash_equals( $kept, $site_id ) ? $user_id : null;
	}

	/**
	 * Whether a kept id has the user's number and the expected form.
	 *
	 * @param string $id      The kept id.
	 * @param int    $user_id The user.
	 */
	private static function belongsTo( string $id, int $user_id ): bool {
		return 1 === preg_match( '/^' . $user_id . '-[a-z0-9]{' . self::RANDOM . '}$/', $id );
	}
}
