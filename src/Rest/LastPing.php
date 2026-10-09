<?php
/**
 * When OmnaBase's last `ping` arrived.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The events receiver records the moment a signed `ping` arrives; "Check connection" compares it with the moment the check started, so
 * the screen shows OmnaBase → site as working only when a ping really arrived.
 */
final class LastPing {

	/** Records that a ping arrived now. */
	public function record(): void {
		update_option( Options::LAST_PING, time(), false );
	}

	/** When the last ping arrived (Unix seconds), 0 for never. */
	public function at(): int {
		return (int) get_option( Options::LAST_PING, 0 );
	}

	/** Forgets it (Disconnect). */
	public function clear(): void {
		delete_option( Options::LAST_PING );
	}
}
