<?php
/**
 * The small fix round of 2026-11-18 (the founder's report: a reply posted from OmnaBase lost its line breaks on the forum): OmnaBase's reply
 * in the shape the forum itself stores a post in. No WordPress loaded: the piece is pure.
 *
 * The words: *bbPress*, the forum plugin; *paragraph tag* and *line-break tag*, the HTML marks `<p>` and `<br>`; bbPress keeps a post as text
 * with line breaks and removes both tags whenever a post is edited in the forum.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use OmnaBase\WordPress\Forum\PostShape;

final class PostShapeTest extends TestCase {

	/**
	 * What it checks: A reply written in OmnaBase with paragraphs and line breaks is handed to the forum as text with line breaks.
	 * Passes when: the founder's own reply of 2026-11-18 (a greeting, a paragraph with a code word, two more paragraphs set off by double line
	 *   breaks, a sign-off) comes out without a single paragraph or line-break tag, with an empty line between its five parts, and the code
	 *   word still marked as code.
	 * Fails when: the forum post keeps the tags, and the first edit in the forum glues "that we posted." to "Maybe you removed it?".
	 */
	public function test_paragraphs_and_line_breaks_become_plain_line_breaks(): void {
		$html = '<p>Hi,</p><p>Thanks for the login. The rule <code>.flag img { height: auto; } </code>is missing.<br /><br />Maybe you removed it?<br /><br />Since there is a lot of CSS I left it.<br /><br />Best Regards!</p>';

		$shape = PostShape::of( $html );

		$this->assertDoesNotMatchRegularExpression( '#</?p\b|<br\b#i', $shape, 'no paragraph or line-break tag is left' );
		$this->assertSame(
			"Hi,\n\nThanks for the login. The rule <code>.flag img { height: auto; } </code>is missing.\n\nMaybe you removed it?\n\nSince there is a lot of CSS I left it.\n\nBest Regards!",
			$shape
		);
	}

	/**
	 * What it checks: A single line break inside a paragraph stays a single line break, in every way the tag is written.
	 * Passes when: "Hey!" and the next sentence, separated by one line-break tag (written with or without a slash, in capitals, followed by a
	 *   line end or not), come out on two lines with no empty line between them.
	 * Fails when: an address block or a short list of lines runs together, or doubles into separate paragraphs.
	 */
	public function test_a_single_line_break_stays_single(): void {
		foreach ( array( '<br>', '<br/>', '<br />', '<BR>', "<br />\n" ) as $break ) {
			$this->assertSame( "Hey!\nIt seems the CSS is not applied.", PostShape::of( '<p>Hey!' . $break . 'It seems the CSS is not applied.</p>' ), $break );
		}
	}

	/**
	 * What it checks: Everything that is not a paragraph or a line break stays as the plugin's cleaning left it.
	 * Passes when: bold words, a list with its two items, a link with its address, a picture and a block of code with its own line breaks and
	 *   indentation come out unchanged, each set off from the text around it.
	 * Fails when: the forum answer loses its list or link, or a code example loses its indentation.
	 */
	public function test_everything_else_stays(): void {
		$html  = '<p>Please <strong>update</strong>:</p><ul><li>Clear the cache</li><li>Reload</li></ul><p><img src="https://site.example/a.png" alt="a" width="300"></p>';
		$html .= "<pre><code>.home {\n    padding-top: 0;\n}</code></pre><p>Read <a href=\"https://example.com/docs\">the docs</a>.</p>";

		$shape = PostShape::of( $html );

		$this->assertStringContainsString( "Please <strong>update</strong>:\n\n<ul><li>Clear the cache</li><li>Reload</li></ul>", $shape );
		$this->assertStringContainsString( '<img src="https://site.example/a.png" alt="a" width="300">', $shape );
		$this->assertStringContainsString( "<pre><code>.home {\n    padding-top: 0;\n}</code></pre>", $shape, 'the code block keeps its lines and indentation' );
		$this->assertStringEndsWith( 'Read <a href="https://example.com/docs">the docs</a>.', $shape );
		$this->assertDoesNotMatchRegularExpression( '#</?p\b|<br\b#i', $shape );
	}

	/**
	 * What it checks: A list or a quote whose entries are written as paragraphs (as OmnaBase's reply box writes them) stays a tidy list or
	 *   quote.
	 * Passes when: a numbered list of two entries comes out as the two entries with no empty line inside an entry, and a quote of two
	 *   paragraphs keeps one empty line between them and none before its end.
	 * Fails when: the forum draws an empty paragraph inside every list entry, so a short list of steps is stretched apart.
	 */
	public function test_paragraphs_inside_a_list_or_a_quote_leave_no_empty_line_at_their_end(): void {
		$this->assertSame( '<ol><li>First</li><li>Second</li></ol>', PostShape::of( '<ol><li><p>First</p></li><li><p>Second</p></li></ol>' ) );
		$this->assertSame( "<blockquote>Quoted one\n\nQuoted two</blockquote>Answer", PostShape::of( '<blockquote><p>Quoted one</p><p>Quoted two</p></blockquote><p>Answer</p>' ) );
	}

	/**
	 * What it checks: The sign-off under a forum answer is written the same way: after an empty line, each of its lines on its own line.
	 * Passes when: the lines "Best regards" and "Ada, Team Kriesi" come out as an empty line, "Best regards", a line break, "Ada, Team Kriesi",
	 *   with no tag at all.
	 * Fails when: an edit in the forum turns the sign-off into "Best regardsAda, Team Kriesi".
	 */
	public function test_the_closing_lines_stand_on_their_own_lines(): void {
		$this->assertSame( "\n\nBest regards\nAda, Team Kriesi", PostShape::closing( array( 'Best regards', 'Ada, Team Kriesi' ) ) );
	}
}
