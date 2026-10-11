<?php
/**
 * The plain words for "reconnect needed": why the stored connection can no longer be read.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * One sentence per reason the vault gives (`Vault::unreadableReason()`): the key file gone, the site's secret keys changed, or neither
 * known. The connection card and the admin notice say the same thing in their own sentence shape; each stays within 30 words.
 */
final class ReconnectWords {

	/**
	 * The connection card's line.
	 *
	 * @param string|null $reason The vault's reason.
	 */
	public static function card( ?string $reason ): string {
		switch ( $reason ) {
			case Vault::FILE_GONE:
				return __( 'The stored connection can no longer be read because the file this site locks its connection with is gone. Connect again to receive replies.', 'omnabase' );
			case Vault::KEYS_CHANGED:
				return __( 'The stored connection can no longer be read because the site\'s secret keys changed. Connect again to receive replies.', 'omnabase' );
		}
		return __( 'The stored connection can no longer be read. Connect again to receive replies.', 'omnabase' );
	}

	/**
	 * The admin notice's line (after "OmnaBase: reconnect needed").
	 *
	 * @param string|null $reason The vault's reason.
	 */
	public static function notice( ?string $reason ): string {
		switch ( $reason ) {
			case Vault::FILE_GONE:
				return __( 'The stored connection can no longer be read (the file this site locks its connection with is gone), so OmnaBase\'s replies are not arriving.', 'omnabase' );
			case Vault::KEYS_CHANGED:
				return __( 'The stored connection can no longer be read (the site\'s secret keys changed), so OmnaBase\'s replies are not arriving.', 'omnabase' );
		}
		return __( 'The stored connection can no longer be read, so OmnaBase\'s replies are not arriving.', 'omnabase' );
	}
}
