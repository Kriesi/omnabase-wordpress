<?php
/**
 * The pictures OmnaBase lists with a team reply, and their marked paragraphs in the reply's HTML.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * The forum reply pictures round (OmnaBase, 2026-11-17; OmnaBase's brief "Part B, pinned", its red team's findings 11, 13 and 15). OmnaBase's
 * `message.sent` to this site lists the pictures of the reply's text as `pictures: [ { id, name, mime, size, width, url } ]`, and each
 * one's paragraph reads `<p data-omnabase-picture="<id>">[Picture: <name>]</p>`. This reads the list (only entries whose id is OmnaBase's
 * id form, a UUID, at most ten; the `url` is never kept: the plugin builds the address itself) and turns the marked paragraphs into
 * pictures (`<p><img src alt width></p>`) for the pictures saved, or into their plain words for every other mark. Pure.
 */
final class PictureMarks {

	/** At most this many pictures of one reply are fetched; the rest keep their words. */
	public const MOST = 10;

	/** OmnaBase's id form. */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/** A marked paragraph: the id in the first group, the words in the second. */
	private const MARKED = '#<p\s+data-omnabase-picture="([^"]*)"\s*>(.*?)</p>#is';

	/**
	 * Whether a string is OmnaBase's id form (a UUID), so it may stand in an address.
	 *
	 * @param mixed $id The id.
	 */
	public static function isId( $id ): bool {
		return is_string( $id ) && 1 === preg_match( self::UUID, $id );
	}

	/**
	 * The listed pictures this site may fetch: the first ten entries with an id of OmnaBase's form, each `{ id, name, size, width }`; none
	 * when the message's own id is not of that form.
	 *
	 * @param array<string,mixed> $message OmnaBase's message.
	 * @return array<int,array{id:string,name:string,size:int,width:?int}>
	 */
	public static function entries( array $message ): array {
		if ( ! self::isId( $message['id'] ?? null ) || ! is_array( $message['pictures'] ?? null ) ) {
			return array();
		}
		$out = array();
		foreach ( $message['pictures'] as $entry ) {
			if ( is_array( $entry ) && self::isId( $entry['id'] ?? null ) ) {
				$out[] = self::entry( $entry );
			}
		}
		return array_slice( $out, 0, self::MOST );
	}

	/**
	 * The HTML with each marked paragraph of a saved picture as that picture and every other marked paragraph as its words.
	 *
	 * @param string                                                                $html    OmnaBase's HTML.
	 * @param array<int,array{id:string,name:string,size:int,width:?int}>            $entries The listed pictures.
	 * @param array<string,string>                                                  $saved   The saved pictures' addresses by OmnaBase's file id.
	 */
	public static function place( string $html, array $entries, array $saved ): string {
		$named = array_column( $entries, null, 'id' );
		return (string) preg_replace_callback(
			self::MARKED,
			static function ( array $m ) use ( $named, $saved ): string {
				$id = $m[1];
				return isset( $saved[ $id ], $named[ $id ] ) ? '<p>' . self::tag( $saved[ $id ], $named[ $id ] ) . '</p>' : '<p>' . $m[2] . '</p>';
			},
			$html
		);
	}

	/**
	 * One entry in the plugin's shape: the name (a plain one when missing), the announced size in bytes, a whole width from 1 to 9999 or none.
	 *
	 * @param array<string,mixed> $entry OmnaBase's entry.
	 * @return array{id:string,name:string,size:int,width:?int}
	 */
	private static function entry( array $entry ): array {
		$name  = is_string( $entry['name'] ?? null ) && '' !== trim( $entry['name'] ) ? trim( $entry['name'] ) : __( 'Picture', 'omnabase' );
		$width = is_int( $entry['width'] ?? null ) && $entry['width'] >= 1 && $entry['width'] <= 9999 ? $entry['width'] : null;
		return array(
			'id'    => strtolower( (string) $entry['id'] ),
			'name'  => $name,
			'size'  => is_int( $entry['size'] ?? null ) ? max( 0, $entry['size'] ) : 0,
			'width' => $width,
		);
	}

	/**
	 * The picture's tag: its address, its name as its words and its drawn width, nothing else.
	 *
	 * @param string                                          $src   The saved picture's address.
	 * @param array{id:string,name:string,size:int,width:?int} $entry The listed picture.
	 */
	private static function tag( string $src, array $entry ): string {
		$width = null !== $entry['width'] ? ' width="' . (int) $entry['width'] . '"' : '';
		return '<img src="' . esc_attr( $src ) . '" alt="' . esc_attr( $entry['name'] ) . '"' . $width . '>';
	}
}
