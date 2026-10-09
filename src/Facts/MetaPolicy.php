<?php
/**
 * Which usermeta may ever leave the site: the one banned list, shared by the shaper and the settings screen's picker.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * A usermeta key whose name looks like a secret is never sent and never offered, even when an administrator ticked it: the brief's name
 * parts (`pass`, `token`, `secret`, `key`, `hash`, `session`, `auth`, `otp`, `nonce`, `code`, `licen`, `card`, `iban`, `backup`,
 * `purchase`), the coordinator's `salt` and `capabilities`, and three exact names, in any capitalisation. The cost, accepted: `code` also
 * bans `billing_postcode`, `key` every `*_key` field, `auth` an `author_*` field. The picker also hides keys starting with `_` (private by
 * WordPress's convention) and WordPress's own screen preferences, which say nothing about a customer.
 */
final class MetaPolicy {

	private const BANNED_NAMES = array( 'user_pass', 'session_tokens', '_two_factor_backup_codes' );

	private const BANNED_PARTS = array( 'pass', 'token', 'secret', 'key', 'hash', 'session', 'auth', 'otp', 'nonce', 'code', 'licen', 'card', 'iban', 'backup', 'purchase', 'salt', 'capabilities' );

	/** WordPress's own per-user screen preferences: never interesting on a support card, so not offered (not banned). */
	private const NOISE = array( 'rich_editing', 'syntax_highlighting', 'comment_shortcuts', 'admin_color', 'use_ssl', 'show_admin_bar_front', 'show_welcome_panel', 'dismissed_wp_pointers', 'locale', 'user_level', 'user-settings', 'user-settings-time', 'dashboard_quick_press_last_post_id', 'community-events-location' );

	/** The longest key OmnaBase reads. */
	public const MAX_KEY = 64;

	/**
	 * Whether a key may never be sent.
	 *
	 * @param string $key A usermeta key.
	 */
	public static function isBanned( string $key ): bool {
		$name = strtolower( $key );
		if ( in_array( $name, self::BANNED_NAMES, true ) ) {
			return true;
		}
		foreach ( self::BANNED_PARTS as $part ) {
			if ( str_contains( $name, $part ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the picker offers a key: not banned, not private, not WordPress's own preferences, a sensible name.
	 *
	 * @param string $key A usermeta key.
	 */
	public static function isOffered( string $key ): bool {
		if ( '' === $key || str_starts_with( $key, '_' ) || self::isBanned( $key ) || ! self::isWellFormed( $key ) ) {
			return false;
		}
		foreach ( self::NOISE as $noise ) {
			if ( $key === $noise || str_ends_with( $key, '_' . $noise ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a key is a plain name OmnaBase can read: letters, digits, `_ - . :`, at most 64 characters.
	 *
	 * @param string $key A usermeta key.
	 */
	public static function isWellFormed( string $key ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_\-.:]{1,' . self::MAX_KEY . '}$/', $key );
	}
}
