<?php
/**
 * The settings screen's actions' common door and way out.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Every action posted from the screen needs its form's nonce (a short-lived form token against forged requests) and `manage_options`;
 * afterwards the browser goes back to the clean settings address.
 */
final class Guard {

	/**
	 * The form's nonce and the administrator's permission, or WordPress's own refusal.
	 *
	 * @param string $action The action, also the nonce's name.
	 */
	public static function check( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( SettingsScreen::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OmnaBase.', 'omnabase' ), 403 );
		}
	}

	/** To the clean settings address. */
	public static function backToScreen(): void {
		wp_safe_redirect( SettingsScreen::url() );
		exit;
	}
}
