<?php
/**
 * The settings screen's front-end switches: save "theme styles only" and the My Account link.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forms\Display;

defined( 'ABSPATH' ) || exit;

/**
 * A thin handler at `admin-post.php`: its nonce (a short-lived form token) and `manage_options`, then both switches are stored as ticked and
 * the browser goes back to the screen. Without WooCommerce the My Account switch is not on the form, so its stored value is kept.
 */
final class DisplayActions {

	public const SAVE       = 'omnabase_save_display';
	public const THEME_ONLY = 'omnabase_theme_only';
	public const MY_ACCOUNT = 'omnabase_my_account';

	/**
	 * Builds the handler.
	 *
	 * @param Display $display The switches.
	 * @param Flash   $flash   The message for the next view.
	 */
	public function __construct( private Display $display, private Flash $flash ) {}

	/** Registers the admin-post action. */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ) );
	}

	/** Stores the two switches. */
	public function save(): void {
		Guard::check( self::SAVE );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by Guard::check() above; only the presence of each box is read.
		$theme_only = isset( $_POST[ self::THEME_ONLY ] );
		$my_account = ShortcodesView::shopActive() ? isset( $_POST[ self::MY_ACCOUNT ] ) : $this->display->myAccount();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->display->save( $theme_only, $my_account );
		$this->flash->set( get_current_user_id(), 'success', __( 'Display settings saved.', 'omnabase' ) );
		Guard::backToScreen();
	}
}
