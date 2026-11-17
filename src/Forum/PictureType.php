<?php
/**
 * A picture's type, read from its first bytes.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * The forum reply pictures round (OmnaBase, 2026-11-17; OmnaBase's red team's finding 16): the first bytes decide, never the name or the
 * type OmnaBase announced. JPEG (`FF D8 FF`), PNG (its eight-byte signature), GIF (`GIF87a`, `GIF89a`) and WebP (`RIFF`, four bytes, `WEBP`);
 * everything else (an SVG, a web page, a PHP file) is no picture. The saved file's ending comes from this type. Pure.
 */
final class PictureType {

	/** Each type's MIME type and file ending. */
	private const TYPES = array(
		'jpeg' => array( 'image/jpeg', 'jpg' ),
		'png'  => array( 'image/png', 'png' ),
		'gif'  => array( 'image/gif', 'gif' ),
		'webp' => array( 'image/webp', 'webp' ),
	);

	/**
	 * The type of a file's first bytes: `{ mime, ext }`, or null for anything that is not one of the four pictures.
	 *
	 * @param string $head At least the file's first twelve bytes.
	 * @return array{mime:string,ext:string}|null
	 */
	public static function of( string $head ): ?array {
		$name = self::nameOf( $head );
		return null === $name ? null : array( 'mime' => self::TYPES[ $name ][0], 'ext' => self::TYPES[ $name ][1] );
	}

	/**
	 * The type's short name, or null.
	 *
	 * @param string $head The first bytes.
	 */
	private static function nameOf( string $head ): ?string {
		if ( str_starts_with( $head, "\xFF\xD8\xFF" ) ) {
			return 'jpeg';
		}
		if ( str_starts_with( $head, "\x89PNG\r\n\x1A\n" ) ) {
			return 'png';
		}
		if ( str_starts_with( $head, 'GIF87a' ) || str_starts_with( $head, 'GIF89a' ) ) {
			return 'gif';
		}
		return str_starts_with( $head, 'RIFF' ) && 'WEBP' === substr( $head, 8, 4 ) ? 'webp' : null;
	}
}
