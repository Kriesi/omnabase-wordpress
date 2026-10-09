<?php
/**
 * The warning on every admin page when the stored connection can no longer be read.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * When the vault reads "unreadable" (the salts changed: a security plugin's "log everyone out"), replies and customer questions stop
 * arriving (the receivers answer 503). Administrators are told on every admin page, not only on the plugin's screen; nobody else is.
 */
final class Notices {

	/**
	 * Builds the notices.
	 *
	 * @param Vault $vault The vault.
	 */
	public function __construct( private Vault $vault ) {}

	/** Registers at `admin_notices`. */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/** Prints "OmnaBase: reconnect needed" for administrators while the vault cannot be read. */
	public function render(): void {
		if ( ! current_user_can( SettingsScreen::CAPABILITY ) || Vault::UNREADABLE !== $this->vault->state() ) {
			return;
		}
		printf(
			'<div class="notice notice-error ob-wp-reconnect"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'OmnaBase: reconnect needed', 'omnabase' ),
			esc_html__( 'The stored connection can no longer be read (the site\'s secret keys changed), so OmnaBase\'s replies are not arriving.', 'omnabase' ),
			esc_url( SettingsScreen::url() ),
			esc_html__( 'Connect again', 'omnabase' )
		);
	}
}
