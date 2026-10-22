<?php
/**
 * The Appearance tab's one save: the theme, the accent, the font and "Theme styles only".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Look\Appearance;

defined( 'ABSPATH' ) || exit;

/**
 * A thin handler at `admin-post.php` (WordPress's address that hands a posted admin form to a plugin): its nonce (a short-lived form token)
 * and `manage_options`, then the four choices are stored in the look's own option (`Look\Appearance` keeps only valid values: a colour that
 * is not `#rrggbb` leaves OmnaBase's blue) and the browser goes back to the tab. It is the only save that writes "Theme styles only".
 */
final class AppearanceActions {

	public const SAVE  = 'omnabase_save_appearance';
	public const FIELD = 'omnabase_appearance';

	/**
	 * Builds the handler.
	 *
	 * @param Appearance $appearance The stored look.
	 * @param Flash      $flash      The message for the next view.
	 */
	public function __construct( private Appearance $appearance, private Flash $flash ) {}

	/** Registers the admin-post action. */
	public function register(): void {
		add_action( 'admin_post_' . self::SAVE, array( $this, 'save' ) );
	}

	/** Stores the choices. */
	public function save(): void {
		Guard::check( self::SAVE );
		$this->appearance->save( self::posted() );
		$this->flash->set( get_current_user_id(), 'success', __( 'Appearance saved.', 'omnabase' ) );
		Guard::backToScreen( Tabs::APPEARANCE );
	}

	/**
	 * The posted choices (the nonce was checked by the caller), as `Look\Appearance` reads them.
	 *
	 * @return array{theme:string,accent:string,font:string,themeOnly:bool}
	 */
	private static function posted(): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- checked by Guard::check() in save(); every value is sanitised below and cleaned by Appearance.
		$posted = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( $_POST[ self::FIELD ] ) : array();
		$text   = static fn ( string $key ): string => is_scalar( $posted[ $key ] ?? null ) ? sanitize_text_field( (string) $posted[ $key ] ) : '';
		return Appearance::clean(
			array(
				'theme'     => $text( 'theme' ),
				'accent'    => 'own' === $text( 'accent' ) ? $text( 'colour' ) : '',
				'font'      => $text( 'font' ),
				'themeOnly' => '' !== $text( 'theme_only' ),
			)
		);
	}
}
