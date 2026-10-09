<?php
/**
 * How often one customer may ask for the confirmation link.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Three a day per user, OmnaBase's own number: the fourth never leaves the site, so neither a script riding a session nor an impatient
 * customer fills an inbox through it. The times are kept in the user's meta (the last day's only).
 */
final class LinkLimit {

	private const PER_DAY = 3;

	/**
	 * Whether the user may ask now; counts the request when they may.
	 *
	 * @param int $user_id The user.
	 */
	public function allow( int $user_id ): bool {
		$since = time() - DAY_IN_SECONDS;
		$kept  = get_user_meta( $user_id, Options::PORTAL_LINKS_META, true );
		$times = array_values( array_filter( is_array( $kept ) ? array_map( 'intval', $kept ) : array(), static fn ( int $t ) => $t > $since ) );
		if ( count( $times ) >= self::PER_DAY ) {
			return false;
		}
		$times[] = time();
		update_user_meta( $user_id, Options::PORTAL_LINKS_META, $times );
		return true;
	}
}
