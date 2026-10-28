<?php
/**
 * The settings screen's forum action: save the forum section.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forum\PostFields;
use OmnaBase\WordPress\Forum\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A thin handler at `admin-post.php`: its nonce (a short-lived form token) and `manage_options`, then the forum settings store exactly what
 * was ticked and chosen, and the browser goes back to the Forums tab. The guest choice arrives as `1` ("Guests too") or `0` ("Members only").
 * The site-fields round: the Post fields rows are cleaned by `PostFields::cleanRows`, the dropped ones named in the notice, and the
 * auto-complete's cached meta keys forgotten.
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
		$fields = PostFields::cleanRows( $posted['fields'] ?? array() );
		$this->settings->saveFields( $fields['fields'] );
		PostFields::forgetMetaKeys();
		$this->flash->set( get_current_user_id(), array() === $fields['dropped'] ? 'success' : 'warning', $this->savedWords( $fields['dropped'] ) );
		Guard::backToScreen( Tabs::FORUMS );
	}

	/**
	 * The notice after a save: saved, and the post fields left out by name.
	 *
	 * @param string[] $dropped The rows not kept.
	 */
	private function savedWords( array $dropped ): string {
		if ( array() === $dropped ) {
			return __( 'Forum settings saved.', 'omnabase' );
		}
		/* translators: %s: the post fields not saved, separated by commas. */
		return sprintf( __( 'Forum settings saved. These post fields were left out (a key is lower-case letters, digits, _ and -, with a name of up to 60 characters, at most 10 fields): %s', 'omnabase' ), implode( ', ', $dropped ) );
	}
}
