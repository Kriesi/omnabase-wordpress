<?php
/**
 * The message centre's pictures: only OmnaBase's own signed portal pictures stay.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Look;

use OmnaBase\WordPress\Api\ApiUrl;

defined( 'ABSPATH' ) || exit;

/**
 * The pictures-in-the-text round (OmnaBase, 2026-10-29; OmnaBase's brief rule 4 and its red team's findings 7 and 8): a team reply's pasted
 * screenshot reaches the message centre as `<img src="<API>/api/public/v1/portal/files/<token>" alt width>`, an address OmnaBase signed for
 * this visitor; the visitor's browser loads it from OmnaBase directly. `wp_kses` cannot check a path, so these two passes do: before it, any
 * picture whose address is not exactly that path and one token leaves (another host, another path, a look-alike host, `..`, `data:`); after
 * it, the picture keeps `src`, `alt` and a whole-number `width`, and its address is checked once more.
 */
final class PortalPictures {

	/** One token after the path: letters, digits and `._~-`, no slash, so nothing climbs out of the path. */
	private const TOKEN = '#^[A-Za-z0-9._~-]{1,2000}$#';

	/**
	 * The HTML with every picture that is not one of the portal's own removed.
	 *
	 * @param string $html The message's HTML.
	 */
	public static function onlyPortal( string $html ): string {
		return (string) preg_replace_callback(
			'#<img\b[^>]*>#i',
			static function ( array $m ): string {
				$tag = new \WP_HTML_Tag_Processor( $m[0] );
				return $tag->next_tag( 'img' ) && self::isPortal( (string) $tag->get_attribute( 'src' ) ) ? $m[0] : '';
			},
			$html
		);
	}

	/**
	 * The cleaned HTML with each picture's address checked again and a width that is no whole number taken off.
	 *
	 * @param string $html The cleaned HTML.
	 */
	public static function settle( string $html ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'img' ) ) {
			if ( ! self::isPortal( (string) $tags->get_attribute( 'src' ) ) ) {
				$tags->remove_attribute( 'src' );
			}
			$width = (string) $tags->get_attribute( 'width' );
			if ( null !== $tags->get_attribute( 'width' ) && ! preg_match( '#^[1-9][0-9]{0,3}$#', $width ) ) {
				$tags->remove_attribute( 'width' );
			}
		}
		return $tags->get_updated_html();
	}

	/**
	 * Whether an address is OmnaBase's portal path followed by one token.
	 *
	 * @param string $src The picture's address.
	 */
	private static function isPortal( string $src ): bool {
		$prefix = ApiUrl::publicApi( '/portal/files/' );
		$src    = html_entity_decode( trim( $src ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return str_starts_with( $src, $prefix ) && 1 === preg_match( self::TOKEN, substr( $src, strlen( $prefix ) ) );
	}
}
