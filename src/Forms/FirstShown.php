<?php
/**
 * The first-display marks: when each of the two tags first showed on the public site.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The form and the message centre announce each real render (`EVENT`, with `form` or `messages`); the first one on the public site is
 * noted once in `omnabase_display['first_shown']`, beside the front-end switches, which stay as they are. A note only administrators see
 * never announces itself; a render inside wp-admin, in a request for WordPress's own API (the block editor loads a page's content that way)
 * or in a preview of a draft is not the public site and notes nothing. Once noted, later views read the mark and write nothing. The setup
 * line reads the marks (`Display::firstShown()`).
 */
final class FirstShown {

	public const EVENT = 'omnabase_shown';
	public const TAGS  = array( 'form', 'messages' );

	private const FIRST_SHOWN = 'first_shown';

	/**
	 * Builds the recorder.
	 *
	 * @param Display $display Reads the marks.
	 */
	public function __construct( private Display $display ) {}

	/** Listens for the two tags' renders. */
	public function register(): void {
		add_action( self::EVENT, array( $this, 'note' ) );
	}

	/**
	 * Notes the first public render of a tag, once.
	 *
	 * @param string $tag `form` or `messages`.
	 */
	public function note( $tag ): void {
		if ( ! in_array( $tag, self::TAGS, true ) || isset( $this->display->firstShown()[ $tag ] ) || ! self::onThePublicSite() ) {
			return;
		}
		$stored = get_option( Options::DISPLAY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$marks  = is_array( $stored[ self::FIRST_SHOWN ] ?? null ) ? $stored[ self::FIRST_SHOWN ] : array();

		$stored[ self::FIRST_SHOWN ] = array( $tag => time() ) + $marks;
		update_option( Options::DISPLAY, $stored, false );
	}

	/** Whether this request shows the public site (not wp-admin, not WordPress's own API, not a preview). */
	private static function onThePublicSite(): bool {
		return ! is_admin() && ! wp_is_serving_rest_request() && ! is_preview();
	}
}
