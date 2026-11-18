<?php
/**
 * OmnaBase's reply in the shape the forum itself stores a post in.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * bbPress stores a post as text with line breaks and makes the paragraphs when it shows it; its own list of allowed tags has no paragraph
 * and no line-break tag, so a moderator's edit in the forum removes both. A reply stored with them lost every paragraph and line at its
 * first edit (the founder's report of 2026-11-18). So a paragraph's end becomes an empty line and a line-break tag a line break, and no
 * paragraph or line-break tag is left: the reply reads the same before and after an edit. Everything else stays as the fence left it. Pure.
 */
final class PostShape {

	/**
	 * The cleaned HTML with its paragraphs and line breaks as plain line breaks.
	 *
	 * @param string $html The reply's cleaned HTML.
	 */
	public static function of( string $html ): string {
		$text = (string) preg_replace( '#<br\b[^>]*>\n?#i', "\n", $html );
		$text = (string) preg_replace( '#[ \t]*</p\s*>\s*#i', "\n\n", $text );
		$text = (string) preg_replace( '#<p\b[^>]*>#i', '', $text );
		$text = (string) preg_replace( '#\n+(</(?:li|blockquote|td|th)\s*>)#i', '$1', $text ); // a paragraph that ends its list item or quote leaves no empty line inside it
		return trim( $text );
	}

	/**
	 * A closing line's lines under the reply: an empty line, then each line on its own.
	 *
	 * @param string[] $lines The lines, already escaped.
	 */
	public static function closing( array $lines ): string {
		return "\n\n" . implode( "\n", $lines );
	}
}
