<?php
/**
 * A message from OmnaBase as the HTML the site prints: the message centre's thread and the forum's reply.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Look;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's fence behind OmnaBase's own display rule (the plugin's message-centre HTML round, 2026-10-28): OmnaBase's portal already sends
 * a message in its display shape (no pictures, no quoted history), and this cleans it once more for HTML that did not come through that rule
 * (an older row, the forum's webhook, a changed server). It is not a second display rule: no quoted-history logic, no pictures at all.
 * - script and style blocks go with their content (WordPress's cleaner keeps their text as words);
 * - `h1` and `h2` print as `h3`, so nothing inside a message outranks the page's title or the thread's subject;
 * - `wp_kses` with the allow-list below and the protocols http, https and mailto;
 * - one pass over the links: an http(s) link opens a new tab without handing over the page, a mail link opens none, any other link keeps
 *   its words and loses its address;
 * - nothing with words left (a message that was only a picture): the text as paragraphs.
 * The pictures-in-the-text round (OmnaBase, 2026-10-29): in the message centre (`$pictures` true) a team reply's own picture stays where the
 * team put it, but only when its address starts with exactly OmnaBase's signed portal path (`ApiUrl::publicApi( '/portal/files/' )`)
 * followed by one token; it keeps `src`, `alt` and a whole-number `width`, nothing else. Every other picture still goes: another host, another
 * path, a look-alike host, a climbing path, an embedded picture. The forum's reply never takes a picture: a portal address is made for one
 * signed-in visitor and must not reach a public page.
 * The one place in the plugin that names the allow-list; the template echoes through `wp_kses( …, MessageHtml::allowed( true ) )`.
 */
final class MessageHtml {

	private const PROTOCOLS = array( 'http', 'https', 'mailto' );
	private const LINK_REL  = array( 'noopener', 'noreferrer' ); // the opened page gets no handle on the message centre's tab
	private const BARE_TAGS = array( 'p', 'br', 'b', 'strong', 'i', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'h3', 'h4', 'table', 'thead', 'tbody', 'tr', 'hr', 'span', 'div' );

	/**
	 * The tags and attributes a message may keep, in `wp_kses`'s shape (no `h1`/`h2`: they arrive as `h3`).
	 *
	 * @param bool $pictures Whether the portal's own pictures may stay (the message centre, never the forum).
	 * @return array<string,array<string,bool>>
	 */
	public static function allowed( bool $pictures = false ): array {
		$allowed       = array_fill_keys( self::BARE_TAGS, array() );
		$allowed['a']  = array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true );
		$allowed['td'] = array( 'colspan' => true );
		$allowed['th'] = array( 'colspan' => true );
		if ( $pictures ) {
			$allowed['img'] = array( 'src' => true, 'alt' => true, 'width' => true );
		}
		return $allowed;
	}

	/**
	 * The HTML to print for a message.
	 *
	 * @param string|null $html     OmnaBase's HTML (null or '' when there is none).
	 * @param string      $text     OmnaBase's plain text, the fallback.
	 * @param bool        $pictures Whether the portal's own pictures may stay (the message centre, never the forum).
	 */
	public static function of( ?string $html, string $text, bool $pictures = false ): string {
		$source = self::lowerHeadings( self::withoutBlocks( (string) $html ) );
		if ( $pictures ) {
			$source = PortalPictures::onlyPortal( $source );
		}
		$clean = self::fenceLinks( wp_kses( $source, self::allowed( $pictures ), self::PROTOCOLS ) );
		if ( $pictures ) {
			$clean = PortalPictures::settle( $clean );
		}
		return self::hasWords( $clean ) || ( $pictures && str_contains( $clean, '<img' ) ) ? $clean : wpautop( esc_html( $text ) );
	}

	/**
	 * The HTML without its script and style blocks, their content included (an unclosed one runs to the end).
	 *
	 * @param string $html The HTML.
	 */
	private static function withoutBlocks( string $html ): string {
		return (string) preg_replace( '#<(script|style)\b[^>]*>.*?(?:</\1\s*>|\z)#is', '', $html );
	}

	/**
	 * The HTML with `h1` and `h2` as `h3`.
	 *
	 * @param string $html The HTML.
	 */
	private static function lowerHeadings( string $html ): string {
		return (string) preg_replace( '#<(/?)h[12](?=[\s>/])#i', '<$1h3', $html );
	}

	/**
	 * The cleaned HTML with every link's address and tab settled.
	 *
	 * @param string $html The cleaned HTML.
	 */
	private static function fenceLinks( string $html ): string {
		$links = new \WP_HTML_Tag_Processor( $html );
		while ( $links->next_tag( 'a' ) ) {
			$href = trim( (string) $links->get_attribute( 'href' ) );
			if ( preg_match( '#^https?://#i', $href ) ) {
				$links->set_attribute( 'target', '_blank' );
				$links->set_attribute( 'rel', implode( ' ', self::LINK_REL ) );
				continue;
			}
			if ( ! preg_match( '#^mailto:#i', $href ) ) {
				$links->remove_attribute( 'href' );
			}
			$links->remove_attribute( 'target' );
			$links->remove_attribute( 'rel' );
		}
		return $links->get_updated_html();
	}

	/**
	 * Whether the HTML still has words to show.
	 *
	 * @param string $html The cleaned HTML.
	 */
	private static function hasWords( string $html ): bool {
		return '' !== trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\u{00A0}" );
	}
}
