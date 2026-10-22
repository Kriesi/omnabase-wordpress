<?php
/**
 * The Forms and messages tab's one save: the message centre's page and the My Account switch.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\Portal\PickedPage;

defined( 'ABSPATH' ) || exit;

/**
 * A thin handler at `admin-post.php` (WordPress's address that hands a posted admin form to a plugin): its nonce (a short-lived form token)
 * and `manage_options`, then the picked page is stored (a published page, or none), the My Account switch as ticked, and the browser goes back
 * to the tab. Without WooCommerce the My Account switch is not on the form, so its stored value is kept. "Theme styles only" moved to the
 * Appearance tab (2026-10-22): this save never touches it, so saving here cannot switch it off.
 */
final class PagesActions {

	public const SAVE       = 'omnabase_save_pages';
	public const MY_ACCOUNT = 'omnabase_my_account';

	/**
	 * Builds the handler.
	 *
	 * @param PickedPage $page    The message centre's page.
	 * @param Display    $display The front-end switches.
	 * @param Flash      $flash   The message for the next view.
	 */
	public function __construct( private PickedPage $page, private Display $display, private Flash $flash ) {}

	/** Registers the admin-post action. */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ) );
	}

	/** Stores the page and the My Account switch. */
	public function save(): void {
		Guard::check( self::SAVE );
		$this->page->save( self::postedPage() );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked by Guard::check() above; only the presence of each box is read.
		$my_account = ShortcodesView::shopActive() ? isset( $_POST[ self::MY_ACCOUNT ] ) : $this->display->myAccount();
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$this->display->save( $my_account );
		$this->flash->set( get_current_user_id(), 'success', __( 'Forms and messages settings saved.', 'omnabase' ) );
		Guard::backToScreen( Tabs::PAGES );
	}

	/** The posted page when it is a published page, else 0 (the nonce was checked by the caller). */
	private static function postedPage(): int {
		$posted  = isset( $_POST['omnabase_portal'] ) && is_array( $_POST['omnabase_portal'] ) ? wp_unslash( $_POST['omnabase_portal'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- checked by Guard::check() in save(); the field is sanitised below.
		$page_id = absint( is_scalar( $posted['page'] ?? null ) ? $posted['page'] : 0 );
		return $page_id > 0 && 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id ) ? $page_id : 0;
	}
}
