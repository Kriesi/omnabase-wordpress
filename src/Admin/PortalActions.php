<?php
/**
 * The settings screen's message-centre action: save the page pick.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Portal\PickedPage;

defined( 'ABSPATH' ) || exit;

/**
 * A thin handler at `admin-post.php`: its nonce (a short-lived form token) and `manage_options`, then the picked page is stored (a published
 * page, or none) and the browser goes back to the screen.
 */
final class PortalActions {

	public const SAVE = 'omnabase_save_portal';

	/**
	 * Builds the handler.
	 *
	 * @param PickedPage $page  The picked page.
	 * @param Flash      $flash The message for the next view.
	 */
	public function __construct( private PickedPage $page, private Flash $flash ) {}

	/** Registers the admin-post action. */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ) );
	}

	/** Stores the message centre's page. */
	public function save(): void {
		Guard::check( self::SAVE );
		$posted  = isset( $_POST['omnabase_portal'] ) && is_array( $_POST['omnabase_portal'] ) ? wp_unslash( $_POST['omnabase_portal'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- the nonce is checked by Guard::check() above; the field is sanitised below.
		$page_id = absint( is_scalar( $posted['page'] ?? null ) ? $posted['page'] : 0 );
		$this->page->save( $page_id > 0 && 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id ) ? $page_id : 0 );
		$this->flash->set( get_current_user_id(), 'success', __( 'Message centre page saved.', 'omnabase' ) );
		Guard::backToScreen();
	}
}
