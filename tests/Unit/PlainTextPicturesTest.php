<?php
/**
 * The forum edits and pictures round (OmnaBase `docs/briefs/forum-edits-and-pictures-round.md`, Part B items 13 and 15; report
 * `docs/reviews/forum-edits-and-pictures-round-red-team-report.md` in the OmnaBase repository, finding 20), the red team's failing unit tests:
 * a picture inside a forum post names itself in the plain text OmnaBase takes. No WordPress loaded: the piece is pure, WordPress's tag
 * stripper faked with PHP's own.
 *
 * The words: the *plain text* is the version of a post the plugin sends for OmnaBase's AI, search and the user's own AI app (they read words,
 * not pictures); a *picture tag* is the HTML mark `<img src="…">` a post shows a picture with.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team): `PlainText::of()` turns each picture tag with an http(s)
 * address into that address on a line of its own, where the picture stood; a picture without such an address (an embedded `data:` picture)
 * leaves nothing, as today; every other rule of the plain text stays.
 *
 * Why these fail today: a picture tag vanishes from the plain text without a trace.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Forum\PlainText;

final class PlainTextPicturesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $s ) ) ) );
	}

	/**
	 * What it checks: A picture the customer put into their post shows up in the plain text as its address.
	 * Passes when: a post with words, a picture between two sentences and a picture inside a link comes out with each picture's address on a
	 *   line of its own, in the place it stood, and the words around them unchanged.
	 * Fails when: the AI and the search never learn a picture is there, and the team answers "there are no screenshots".
	 */
	public function test_a_picture_becomes_its_address_on_a_line(): void {
		$html = "The switcher is gone, see <img src=\"https://i.ibb.co/abc/shot-1.png\" alt=\"one\" /> and also\n\n<a href=\"https://i.ibb.co/abc/shot-2.png\"><img src=\"https://i.ibb.co/abc/shot-2-thumb.png\"></a>\n\nThanks!";
		$text = PlainText::of( $html );
		$this->assertSame( "The switcher is gone, see\nhttps://i.ibb.co/abc/shot-1.png\nand also\n\nhttps://i.ibb.co/abc/shot-2-thumb.png\n\nThanks!", $text );
	}

	/**
	 * What it checks: (guard) A picture with no web address leaves nothing behind, and a post without pictures reads as today.
	 * Passes when: an embedded picture (a `data:` address) adds no line, and paragraphs still become lines with the tags gone.
	 * Fails when: kilobytes of picture data land in the ticket's text, or the change disturbs posts without pictures.
	 */
	public function test_guard_no_address_no_line_and_text_unchanged(): void {
		$this->assertSame( 'Before after', PlainText::of( 'Before <img src="data:image/png;base64,iVBORw0KGgo=" />after' ) );
		$this->assertSame( "First line.\nSecond line.", PlainText::of( '<p>First line.</p><p>Second line.</p>' ) );
	}
}
