<?php
/**
 * A forum post's HTML as the plain text OmnaBase takes.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * Paragraphs and line breaks become new lines, tags and scripts go (with their text), entities are decoded, runs of blank lines collapse,
 * and the text is cut to OmnaBase's limit (a message at most 50 000 characters, a subject at most 200): a topic OmnaBase would refuse for
 * its length would wait for ever. Pure.
 */
final class PlainText {

	public const MESSAGE_MAX = 50000;
	public const SUBJECT_MAX = 200;

	/**
	 * A post's content as plain text.
	 *
	 * @param string $html The post's content.
	 * @param int    $max  The longest text kept.
	 */
	public static function of( string $html, int $max = self::MESSAGE_MAX ): string {
		$html = (string) preg_replace( '#<(br|/p|/div|/li|/h[1-6]|/blockquote|/pre)\b[^>]*>#i', "$0\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $text ) );
		return trim( mb_substr( $text, 0, $max ) );
	}

	/**
	 * A title as a subject: one line, plain, at most 200 characters.
	 *
	 * @param string $title The topic's title.
	 */
	public static function subject( string $title ): string {
		$text = html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( mb_substr( trim( (string) preg_replace( '/\s+/', ' ', $text ) ), 0, self::SUBJECT_MAX ) );
	}
}
