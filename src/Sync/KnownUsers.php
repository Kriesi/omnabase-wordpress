<?php
/**
 * Which users OmnaBase knows by their site id.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\UserNotes;

defined( 'ABSPATH' ) || exit;

/**
 * A user counts as known once OmnaBase accepted the plugin's link for them, or asked the site about them by their site id. Only known users
 * get licence refreshes (OmnaBase answers 404 for anyone else), and a known user needs no link. A new connection starts afresh: the workspace
 * on the other side may be another one, which knows none of them. The mark is this site's (a user option): on a network each site has its
 * own connection, so its own knowledge.
 */
final class KnownUsers {

	/**
	 * Marks a user as known.
	 *
	 * @param int $user_id The user.
	 */
	public function mark( int $user_id ): void {
		if ( $user_id > 0 && ! $this->isKnown( $user_id ) ) {
			UserNotes::set( $user_id, Options::KNOWN_META, time() );
		}
	}

	/**
	 * Whether OmnaBase knows the user.
	 *
	 * @param int $user_id The user.
	 */
	public function isKnown( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) UserNotes::get( $user_id, Options::KNOWN_META );
	}

	/** Forgets every mark on this site (a new connection; another site of a network keeps its own). */
	public function forgetAll(): void {
		UserNotes::deleteForEveryone( Options::KNOWN_META );
	}

	/**
	 * A user's name for the outbox list, or their number when they are gone.
	 *
	 * @param int $user_id The user.
	 */
	public static function nameOf( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? (string) $user->display_name : '#' . $user_id;
	}

	/** Why a user's call can no longer be made, in words. */
	public static function goneReason(): string {
		return __( 'This call can no longer be made: the user it is about was deleted.', 'omnabase' );
	}
}
