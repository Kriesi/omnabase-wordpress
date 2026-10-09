<?php
/**
 * The settings screen's forum action: save the forum section.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forum\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A thin handler at `admin-post.php`: its nonce (a short-lived form token) and `manage_options`, then the forum settings store exactly what
 * was ticked and chosen, and the browser goes back to the screen.
 */
final class ForumActions {

	public const SAVE = 'omnabase_save_forum';

	/**
	 * Builds the handler.
	 *
	 * @param Settings $settings The forum settings.
	 * @param Flash    $flash    The message for the next view.
	 */
	public function __construct( private Settings $settings, private Flash $flash ) {}

	/** Registers the admin-post action. */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ) );
	}

	/** Stores the forum section's choices. */
	public function save(): void {
		Guard::check( self::SAVE );
		$posted = isset( $_POST['omnabase_forum'] ) && is_array( $_POST['omnabase_forum'] ) ? wp_unslash( $_POST['omnabase_forum'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- the nonce is checked by Guard::check() above; each field is sanitised below.
		$forums = is_array( $posted['forums'] ?? null ) ? array_map( 'absint', array_filter( $posted['forums'], 'is_scalar' ) ) : array();
		$this->settings->save(
			$forums,
			absint( is_scalar( $posted['support_user'] ?? null ) ? $posted['support_user'] : 0 ),
			sanitize_text_field( is_scalar( $posted['closing_line'] ?? null ) ? (string) $posted['closing_line'] : '' ),
			! empty( $posted['guests'] ),
			! empty( $posted['close_resolved'] )
		);
		$this->flash->set( get_current_user_id(), 'success', __( 'Forum settings saved.', 'omnabase' ) );
		Guard::backToScreen();
	}
}
