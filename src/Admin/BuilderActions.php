<?php
/**
 * The shortcode builder's action: "Create the page for me".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A thin handler at `admin-post.php` (WordPress's address that hands a posted admin form to a plugin): its own nonce (a short-lived form
 * token), `manage_options` and `publish_pages` (a person who may change settings but not publish pages cannot have one published), the
 * choices checked, the page made, and the browser back on the Forms and messages tab with a notice that links to the new page's editor.
 */
final class BuilderActions {

	public const CREATE = 'omnabase_create_page';
	public const FIELD  = 'omnabase_builder';

	/**
	 * Builds the handler.
	 *
	 * @param BuilderChoices $choices The posted choices, checked.
	 * @param PageMaker      $maker   The page.
	 * @param Flash          $flash   The message for the next view.
	 */
	public function __construct( private BuilderChoices $choices, private PageMaker $maker, private Flash $flash ) {}

	/** Registers the admin-post action. */
	public function register(): void {
		add_action( 'admin_post_' . self::CREATE, array( $this, 'create' ) );
	}

	/** Makes the page from the checked choices, or says why not. */
	public function create(): void {
		Guard::check( self::CREATE );
		if ( ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to publish pages.', 'omnabase' ), 403 );
		}
		$read = $this->choices->read( self::posted() );
		if ( null === $read['choices'] ) {
			$this->flash->set( get_current_user_id(), 'error', $read['problem'] );
			Guard::backToScreen( Tabs::PAGES );
		}
		$this->report( $this->maker->make( $read['choices'] ), $read['choices']['kind'] );
		Guard::backToScreen( Tabs::PAGES );
	}

	/**
	 * The notice for the next view: the page published (with the link to edit it), or why it could not be made.
	 *
	 * @param int|\WP_Error $made The page's id, or the reason.
	 * @param string        $kind `form` or `messages`.
	 */
	private function report( $made, string $kind ): void {
		$user = get_current_user_id();
		if ( is_wp_error( $made ) ) {
			/* translators: %s: WordPress's reason. */
			$this->flash->set( $user, 'error', sprintf( __( 'The page could not be made: %s', 'omnabase' ), $made->get_error_message() ) );
			return;
		}
		$title = get_the_title( $made );
		$words = ShortcodeTag::MESSAGES === $kind
			/* translators: %s: the new page's title. */
			? sprintf( __( '"%s" is published and is now the message centre page.', 'omnabase' ), $title )
			/* translators: %s: the new page's title. */
			: sprintf( __( '"%s" is published with the contact form.', 'omnabase' ), $title );
		$this->flash->set( $user, 'success', $words, array( 'url' => (string) get_edit_post_link( $made, 'raw' ), 'label' => __( 'Edit the page', 'omnabase' ) ) );
	}

	/** The builder's posted fields, unslashed (the nonce was checked by the caller; each field is checked by `BuilderChoices`). */
	private static function posted(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- checked by Guard::check() in create(); every field is compared with the known values or cleaned in BuilderChoices.
		return isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? (array) wp_unslash( $_POST[ self::FIELD ] ) : array();
	}
}
