<?php
/**
 * A user's notes that belong to this site's connection.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * On a WordPress network (multisite) every site shares the users and their user meta, but each site has its own OmnaBase connection. The
 * notes that belong to one connection (`NAMES`: the "known to OmnaBase" mark, an administrator's pending connect, the message centre's
 * fingerprint, its confirmation-link times, a refused question's kept words) are WordPress user options: user meta stored under the current
 * site's table prefix (`wp_omnabase_known` on a single site and a network's main site, `wp_2_omnabase_known` on site 2), read and written for
 * whichever site is current, also after `switch_to_blog()`. The customer's site id is not one of them: it names the person, so it stays
 * shared user meta.
 */
final class UserNotes {

	/** The notes kept per site, by their plain names (the stored name carries the site's prefix in front). */
	public const NAMES = array(
		Options::KNOWN_META,
		Options::PENDING_CONNECT_META,
		Options::PORTAL_FINGERPRINT_META,
		Options::PORTAL_LINKS_META,
		Options::PORTAL_DRAFT_META,
	);

	/**
	 * A user's note on this site, or '' when there is none.
	 *
	 * @param int    $user_id The user.
	 * @param string $name    The note's plain name.
	 * @return mixed
	 */
	public static function get( int $user_id, string $name ) {
		$value = $user_id > 0 ? get_user_option( $name, $user_id ) : false;
		return false === $value ? '' : $value;
	}

	/**
	 * Keeps a user's note on this site.
	 *
	 * @param int    $user_id The user.
	 * @param string $name    The note's plain name.
	 * @param mixed  $value   The value.
	 */
	public static function set( int $user_id, string $name, $value ): void {
		update_user_option( $user_id, $name, $value );
	}

	/**
	 * Forgets a user's note on this site; whether there was one.
	 *
	 * @param int    $user_id The user.
	 * @param string $name    The note's plain name.
	 */
	public static function delete( int $user_id, string $name ): bool {
		return (bool) delete_user_option( $user_id, $name );
	}

	/**
	 * Forgets a note on this site for every user (a new connection; the uninstall uses the same stored name).
	 *
	 * @param string $name The note's plain name.
	 */
	public static function deleteForEveryone( string $name ): void {
		global $wpdb;
		delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . $name, '', true );
	}

	/**
	 * Whether a name is one of the per-site notes.
	 *
	 * @param string $name A user meta name.
	 */
	public static function isPerSite( string $name ): bool {
		return in_array( $name, self::NAMES, true );
	}
}
