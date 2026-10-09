<?php
/**
 * Which users OmnaBase knows by their site id.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A user counts as known once OmnaBase accepted the plugin's link for them, or asked the site about them by their site id. Only known users
 * get licence refreshes (OmnaBase answers 404 for anyone else), and a known user needs no link. A new connection starts afresh: the workspace
 * on the other side may be another one, which knows none of them.
 */
final class KnownUsers {

	/**
	 * Marks a user as known.
	 *
	 * @param int $user_id The user.
	 */
	public function mark( int $user_id ): void {
		if ( $user_id > 0 && ! $this->isKnown( $user_id ) ) {
			update_user_meta( $user_id, Options::KNOWN_META, time() );
		}
	}

	/**
	 * Whether OmnaBase knows the user.
	 *
	 * @param int $user_id The user.
	 */
	public function isKnown( int $user_id ): bool {
		return $user_id > 0 && '' !== (string) get_user_meta( $user_id, Options::KNOWN_META, true );
	}

	/** Forgets every mark (a new connection). */
	public function forgetAll(): void {
		delete_metadata( 'user', 0, Options::KNOWN_META, '', true );
	}
}
