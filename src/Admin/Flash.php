<?php
/**
 * A short message for an administrator's next view of the settings screen.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * After Connect, the way back, Disconnect or a check, the browser is sent to the clean settings address (no code, state or message in it);
 * what happened travels in a transient for that administrator alone, shown once, with at most one link (the builder's new page).
 */
final class Flash {

	private const LIFETIME_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Keeps a message for the user's next view.
	 *
	 * @param int    $user    The administrator.
	 * @param string $type    `success`, `info`, `warning` or `error` (WordPress's notice classes).
	 * @param string $message The message, plain text.
	 * @param array{url:string,label:string}|null $link A link after the message, or none.
	 */
	public function set( int $user, string $type, string $message, ?array $link = null ): void {
		set_transient( Options::NOTICE_TRANSIENT . $user, array( 'type' => $type, 'message' => $message, 'link' => $link ), self::LIFETIME_SECONDS );
	}

	/**
	 * The user's message, once (null when there is none).
	 *
	 * @param int $user The administrator.
	 * @return array{type:string,message:string,link:?array{url:string,label:string}}|null
	 */
	public function take( int $user ): ?array {
		$notice = get_transient( Options::NOTICE_TRANSIENT . $user );
		if ( ! is_array( $notice ) ) {
			return null;
		}
		delete_transient( Options::NOTICE_TRANSIENT . $user );
		$type = in_array( $notice['type'] ?? '', array( 'success', 'info', 'warning', 'error' ), true ) ? $notice['type'] : 'info';
		$link = is_array( $notice['link'] ?? null ) && '' !== (string) ( $notice['link']['url'] ?? '' ) ? array( 'url' => (string) $notice['link']['url'], 'label' => (string) ( $notice['link']['label'] ?? '' ) ) : null;
		return array( 'type' => $type, 'message' => (string) ( $notice['message'] ?? '' ), 'link' => $link );
	}
}
