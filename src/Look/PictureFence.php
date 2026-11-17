<?php
/**
 * The two passes that keep only the pictures a rule admits, around WordPress's cleaner.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Look;

defined( 'ABSPATH' ) || exit;

/**
 * `wp_kses` (WordPress's HTML cleaner) cannot check a picture's address, so these two passes do, for a rule that says which addresses may
 * stay: before the cleaner, every picture whose address the rule refuses leaves; after it, each picture's address is checked once more and
 * a width that is no whole number from 1 to 9999 is taken off. Used by the message centre's portal pictures (`PortalPictures`, the
 * pictures-in-the-text round, 2026-10-29) and the forum reply's saved pictures (the forum reply pictures round, 2026-11-17).
 */
final class PictureFence {

	/**
	 * The HTML with every picture the rule refuses removed.
	 *
	 * @param string                $html The message's HTML.
	 * @param callable(string):bool $ok   Whether a picture's address (entities decoded) may stay.
	 */
	public static function only( string $html, callable $ok ): string {
		return (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $m ) use ( $ok ): string {
				$tag = new \WP_HTML_Tag_Processor( $m[0] );
				return $tag->next_tag( 'img' ) && $ok( self::decoded( (string) $tag->get_attribute( 'src' ) ) ) ? $m[0] : '';
			},
			$html
		);
	}

	/**
	 * The cleaned HTML with each picture's address checked again and a width that is no whole number taken off.
	 *
	 * @param string                $html The cleaned HTML.
	 * @param callable(string):bool $ok   Whether a picture's address may stay.
	 */
	public static function settle( string $html, callable $ok ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'img' ) ) {
			if ( ! $ok( self::decoded( (string) $tags->get_attribute( 'src' ) ) ) ) {
				$tags->remove_attribute( 'src' );
			}
			$width = $tags->get_attribute( 'width' );
			if ( null !== $width && ! preg_match( '#^[1-9][0-9]{0,3}$#', (string) $width ) ) {
				$tags->remove_attribute( 'width' );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * An address as the rule reads it: trimmed, its entities decoded.
	 *
	 * @param string $src The picture's address.
	 */
	private static function decoded( string $src ): string {
		return html_entity_decode( trim( $src ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
