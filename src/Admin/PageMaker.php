<?php
/**
 * "Create the page for me": the page itself.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Portal\PickedPage;

defined( 'ABSPATH' ) || exit;

/**
 * Publishes a page at once (the founder's answer to the brief's question 1) written by the current user: "Contact support" carrying the
 * contact form's tag, or "Support" carrying the message centre's tag, which then becomes the picked message centre page. The content is
 * built here from checked choices alone, never from text the browser sent.
 */
final class PageMaker {

	/**
	 * Builds the maker.
	 *
	 * @param PickedPage $picked The message centre's page.
	 */
	public function __construct( private PickedPage $picked ) {}

	/**
	 * Makes the page; its id, or WordPress's reason it could not.
	 *
	 * @param array{kind:string,form:string,project:string,subject:string,guest:string} $choices The checked choices.
	 * @return int|\WP_Error
	 */
	public function make( array $choices ) {
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => self::titleFor( $choices['kind'] ),
				'post_content' => ShortcodeTag::content( ShortcodeTag::build( $choices ) ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( ! is_wp_error( $id ) && ShortcodeTag::MESSAGES === $choices['kind'] ) {
			$this->picked->save( (int) $id );
		}
		return $id;
	}

	/**
	 * The new page's title.
	 *
	 * @param string $kind `form` or `messages`.
	 */
	public static function titleFor( string $kind ): string {
		return ShortcodeTag::FORM === $kind ? __( 'Contact support', 'omnabase' ) : __( 'Support', 'omnabase' );
	}
}
