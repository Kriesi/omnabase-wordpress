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
 * it, the picture keeps `src`, `alt` and a whole-number `width`, and its address is checked once more (both passes: `PictureFence`, since the
 * forum reply pictures round shared with the forum's saved pictures).
 */
final class PortalPictures {

	/** One token after the path: letters, digits and `._~-`, no slash, so nothing climbs out of the path. */
	private const TOKEN = '#^[A-Za-z0-9._~-]{1,2000}$#';

	/** The rule: an address that is OmnaBase's portal path followed by one token. */
	public static function rule(): callable {
		return static fn ( string $src ): bool => self::isPortal( $src );
	}

	/**
	 * Whether an address is OmnaBase's portal path followed by one token.
	 *
	 * @param string $src The picture's address.
	 */
	private static function isPortal( string $src ): bool {
		$prefix = ApiUrl::publicApi( '/portal/files/' );
		return str_starts_with( $src, $prefix ) && 1 === preg_match( self::TOKEN, substr( $src, strlen( $prefix ) ) );
	}
}
