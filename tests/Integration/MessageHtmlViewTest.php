<?php
/**
 * The plugin's message-centre HTML round (2026-10-28), the red team's failing tests: a thread in the message centre shows each message's
 * shape (inside wp-env's tests WordPress, the plain run, OmnaBase faked as `RoundFiveTestCase` fakes it).
 *
 * Brief (OmnaBase `docs/briefs/plugin-message-centre-html-round.md`) outcome 1: "A customer reads the reply as the team wrote it.
 * Paragraphs, bold and italic words, lists, links they can click, tables, quotes and code … Their own messages too"; outcome 2: "Nothing a
 * message centre should not show gets through. No pictures …, no quoted mail history, no scripts or styles: the app sends the message already
 * in its display shape and the plugin cleans it once more with its own allow-list before printing"; rule 2: "`bodyHtml` through `wp_kses`
 * with the plugin's own allow-list (p, br, b, strong, i, em, u, s, a, ul, ol, li, blockquote, pre, code, h1–h4, table, thead, tbody, tr,
 * td, th, hr, span, div; on `a` href, title, target and rel; on `td`/`th` colspan; links only http, https and mailto), else the text as
 * paragraphs (`wpautop` of the escaped text) when `bodyHtml` is empty"; "A link keeps `target="_blank"` and `rel="noopener noreferrer"`".
 *
 * The words: *allow-list*, the tags and attributes that may stay, everything else removed; *wp_kses*, WordPress's own cleaner that keeps only
 * an allow-list; *inline style*, colours or sizes written into a tag (`style="…"`); *event handler*, code in a tag that runs on a click or a
 * load (`onclick`, `onerror`); *message bubble*, one message's box in the thread.
 *
 * Shapes the red team pins (chosen where marked; the builder may change them and tell the red team):
 * - The message's words sit in the bubble's `.ob-wp-messages__body` (today's class); every check looks inside it.
 * - (chosen, review finding 6) The plugin is a fence, not a second display rule: whatever OmnaBase sends, a link that is not http, https or
 *   mailto keeps its words and loses its link (WordPress's cleaner alone would leave a broken address such as `alert(1)`), and an http or
 *   https link always opens a new tab without handing over the page, even when OmnaBase's HTML left target or rel out.
 * - (chosen, review finding 9; question 4) A heading inside a message never outranks the thread's own title (an `h2`): `h1` and `h2` print as
 *   `h3`; `h3` and `h4` stay.
 * - (chosen, review finding 3) A message whose HTML is left empty by the cleaning (a lone picture) shows its text instead of an empty bubble.
 *
 * Why these fail today: the thread prints `bodyText` through `esc_html` (an HTML-only message loses its markup), so no paragraph, list,
 * table or link element ever appears inside a bubble, and the text's line breaks are kept by the stylesheet's `pre-line`, not by paragraphs.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class MessageHtmlViewTest extends RoundFiveTestCase {

	private const BODY = "//*[contains(concat(' ', normalize-space(@class), ' '), ' ob-wp-messages__body ')]";

	/**
	 * What it checks: A team answer shows in the customer's thread with its paragraphs, bold words, list, table and a link they can click.
	 * Passes when: the bubble of the team's answer holds two paragraphs, a `strong`, a list with two items, a table with a header cell and a cell
	 *   spanning two columns, and a link to its address that opens a new tab with `rel` noopener and noreferrer.
	 * Fails when: the customer reads "the docs (https://…)" and "- Clear the cache" in one grey block instead of the reply the team wrote.
	 */
	public function test_a_team_answer_shows_its_paragraphs_list_table_and_link(): void {
		$this->omnabaseKnows( $this->ada, true );
		$html = '<p>Please <strong>update</strong> first.</p><ul><li>Clear the cache</li><li>Reload</li></ul>'
			. '<table><thead><tr><th>Plan</th><th>Price</th></tr></thead><tbody><tr><td colspan="2">Pro, 49 a year</td></tr></tbody></table>'
			. '<p>Read <a href="https://example.com/docs" target="_blank" rel="noopener noreferrer">the docs</a>.</p>';
		$n    = $this->ticketFor( $this->ada, 'Shaped answer', array( array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'Please update first.', 'bodyHtml' => $html ) ) );
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );

		$this->assertSame( 1, self::nodes( $page, self::BODY )->length, 'one bubble' );
		$this->assertSame( 2, self::nodes( $page, self::BODY . '/p' )->length, 'two paragraphs' );
		$this->assertSame( array( 'update' ), self::texts( $page, self::BODY . '//strong' ) );
		$this->assertSame( array( 'Clear the cache', 'Reload' ), self::texts( $page, self::BODY . '//ul/li' ) );
		$this->assertSame( array( 'Plan', 'Price' ), self::texts( $page, self::BODY . '//table//th' ) );
		$cell = self::nodes( $page, self::BODY . '//table//td' )->item( 0 );
		$this->assertNotNull( $cell, 'a table cell' );
		$this->assertSame( '2', $cell->getAttribute( 'colspan' ), 'the cell keeps its span' );
		$link = self::nodes( $page, self::BODY . "//a[@href='https://example.com/docs']" )->item( 0 );
		$this->assertNotNull( $link, 'the link is clickable, with its address' );
		$this->assertSame( 'the docs', trim( $link->textContent ) );
		$this->assertSame( '_blank', $link->getAttribute( 'target' ) );
		$this->assertSame( array( 'noopener', 'noreferrer' ), self::words( $link->getAttribute( 'rel' ) ) );
	}

	/**
	 * What it checks: Quotes, code, italic, underlined and struck words and a horizontal rule keep their shape too, and the customer's own
	 *   message shows its link as well (outcome 1: "Their own messages too").
	 * Passes when: the team's bubble holds a `blockquote`, a `pre` with a `code`, an `em`, a `u`, an `s` and an `hr`; the customer's own bubble
	 *   holds a link to the address they typed.
	 * Fails when: a code sample loses its lines, a quote is not set apart, or the customer's own address is not clickable.
	 */
	public function test_quotes_code_and_the_customers_own_link_keep_their_shape(): void {
		$this->omnabaseKnows( $this->ada, true );
		$n    = $this->ticketFor(
			$this->ada,
			'More shapes',
			array(
				array( 'kind' => 'you', 'name' => 'You', 'bodyText' => 'See https://shop.example/order/12', 'bodyHtml' => '<p>See <a href="https://shop.example/order/12" target="_blank" rel="noopener noreferrer">https://shop.example/order/12</a></p>' ),
				array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'x', 'bodyHtml' => '<blockquote><p>You wrote this.</p></blockquote><pre><code>add_filter( "x", "__return_true" );' . "\n" . 'echo 1;</code></pre><p><em>Note</em> <u>this</u> <s>not that</s></p><hr>' ),
			)
		);
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$mine = "//*[contains(@class,'ob-wp-messages__message--you')]" . self::BODY;
		$team = "//*[contains(@class,'ob-wp-messages__message--team')]" . self::BODY;
		$this->assertSame( 1, self::nodes( $page, $mine . "//a[@href='https://shop.example/order/12']" )->length, 'the customer\'s own link' );
		foreach ( array( 'blockquote', 'pre/code', 'em', 'u', 's', 'hr' ) as $shape ) {
			$this->assertSame( 1, self::nodes( $page, $team . '//' . $shape )->length, "the team's {$shape}" );
		}
		$this->assertStringContainsString( "\n", (string) self::nodes( $page, $team . '//pre' )->item( 0 )?->textContent, 'the code keeps its line break' );
	}

	/**
	 * What it checks: Whatever HTML a message carries, the customer's signed-in page never runs it, never loads a picture or frame from
	 *   anywhere, and never lets a message dress up as the page's own buttons or notices (outcome 2).
	 * Passes when: for a message holding a script, a style block, an inline style, a picture with an error handler, a picture from another
	 *   server, a frame, a form with a field, a `class` and an `id`, and links to `javascript:` and `data:` addresses: the bubble holds no script,
	 *   style, picture, frame, form or field element, no `style`, `class`, `id` or `on…` attribute, and every remaining link starts with http,
	 *   https or mailto; the harmless words ("Read me", "Click", "Data") are still there; the script's and the style block's own text is not
 *   printed as words (chosen: WordPress's cleaner alone keeps them as text).
	 * Fails when: a message runs code as the signed-in customer (it could act as them on the site), a tracking picture tells another server who
	 *   read it, or a message shows a fake "Pay now" button in the plugin's own look.
	 */
	public function test_nothing_unsafe_survives_and_unsafe_links_keep_only_their_words(): void {
		$this->omnabaseKnows( $this->ada, true );
		$bad  = '<style>body{display:none}</style><p style="color:red" class="ob-btn ob-btn--primary" id="pay" onclick="steal()">Read me</p>'
			. '<script>alert(1)</script><img src="x" onerror="alert(2)"><img src="https://tracker.example/p.png"><iframe src="https://evil.example"></iframe>'
			. '<form action="https://evil.example"><input name="card"></form>'
			. '<p><a href="javascript:alert(3)">Click</a> <a href="data:text/html;base64,PHNjcmlwdD4=">Data</a> <a href="https://example.com/ok">Fine</a></p>';
		$n    = $this->ticketFor( $this->ada, 'Hostile', array( array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'Read me', 'bodyHtml' => $bad ) ) );
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );

		$this->assertSame( 1, self::nodes( $page, self::BODY . "//a[@href='https://example.com/ok']" )->length, 'the safe link is printed as a link' );
		$this->assertSame( 0, self::nodes( $page, self::BODY . '//*[self::script or self::style or self::img or self::iframe or self::form or self::input]' )->length, 'no script, style, picture, frame or form' );
		$this->assertSame( 0, self::nodes( $page, self::BODY . "//*[@style or @class or @id or @onclick or @onerror or @onload]" )->length, 'no style, class, id or handler' );
		foreach ( self::nodes( $page, self::BODY . '//a[@href]' ) as $a ) {
			$this->assertMatchesRegularExpression( '#^(https?://|mailto:)#i', (string) $a->getAttribute( 'href' ), 'only http, https and mailto links' );
		}
		$text = self::text( implode( ' ', self::texts( $page, self::BODY ) ) );
		foreach ( array( 'Read me', 'Click', 'Data', 'Fine' ) as $words ) {
			$this->assertStringContainsString( $words, $text, 'the words stay' );
		}
		$this->assertStringNotContainsString( 'tracker.example', $page );
		$this->assertStringNotContainsString( 'alert(', $page, 'a script\'s words never show either' );
		$this->assertStringNotContainsString( 'display:none', $page, 'nor a style block\'s rules' );
	}

	/**
	 * What it checks: An http or https link always opens a new tab without handing over the message centre, and a mail link stays a plain mail
	 *   link, even when OmnaBase's HTML left target or rel out or set others (the plugin's own fence).
	 * Passes when: a link sent with `target="_self"` and `rel="opener"` prints with target `_blank` and rel noopener noreferrer; a link sent with
	 *   neither gets both; a `mailto:` link gets no target.
	 * Fails when: the opened page can redirect the customer's signed-in tab elsewhere (reverse tabnabbing), or a mail link opens an empty tab.
	 */
	public function test_web_links_open_a_new_tab_and_mail_links_do_not(): void {
		$this->omnabaseKnows( $this->ada, true );
		$html = '<p><a href="https://a.example/" target="_self" rel="opener">A</a> <a href="https://b.example/">B</a> <a href="mailto:help@example.com">Mail</a></p>';
		$n    = $this->ticketFor( $this->ada, 'Links', array( array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'A B Mail', 'bodyHtml' => $html ) ) );
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		foreach ( array( 'https://a.example/', 'https://b.example/' ) as $href ) {
			$a = self::nodes( $page, self::BODY . "//a[@href='{$href}']" )->item( 0 );
			$this->assertNotNull( $a, "{$href} is a link" );
			$this->assertSame( '_blank', $a->getAttribute( 'target' ), "{$href} opens a new tab" );
			$this->assertSame( array( 'noopener', 'noreferrer' ), self::words( $a->getAttribute( 'rel' ) ), "{$href} hands nothing over" );
		}
		$mail = self::nodes( $page, self::BODY . "//a[@href='mailto:help@example.com']" )->item( 0 );
		$this->assertNotNull( $mail, 'the mail link' );
		$this->assertSame( '', $mail->getAttribute( 'target' ), 'a mail link opens no tab' );
	}

	/**
	 * What it checks: A message without HTML shows its text as paragraphs, and the text's own angle brackets stay words.
	 * Passes when: a message with an empty HTML and the text "Line one⏎Line two⏎⏎Second paragraph <b>not bold</b>" shows two paragraphs, a line
	 *   break inside the first, no `b` element, and the words "<b>not bold</b>" readable as typed.
	 * Fails when: the customer reads one run-on line, or typed angle brackets turn into formatting or vanish.
	 */
	public function test_an_empty_html_falls_back_to_the_text_as_paragraphs(): void {
		$this->omnabaseKnows( $this->ada, true );
		$n    = $this->ticketFor( $this->ada, 'Text only', array( array( 'kind' => 'you', 'name' => 'You', 'bodyText' => "Line one\nLine two\n\nSecond paragraph <b>not bold</b>", 'bodyHtml' => '' ) ) );
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$this->assertSame( 2, self::nodes( $page, self::BODY . '/p' )->length, 'two paragraphs' );
		$this->assertSame( 1, self::nodes( $page, self::BODY . '/p[1]/br' )->length, 'the single line break' );
		$this->assertSame( 0, self::nodes( $page, self::BODY . '//b' )->length, 'typed tags stay words' );
		$this->assertStringContainsString( '<b>not bold</b>', implode( ' ', self::texts( $page, self::BODY ) ) );
	}

	/**
	 * What it checks: (chosen) A message whose HTML is nothing but a picture still shows its words, never an empty bubble.
	 * Passes when: a message whose HTML is one outside picture and whose text is "See the picture." shows "See the picture." in a paragraph and
	 *   no picture.
	 * Fails when: the customer sees an empty box where the team's message should be.
	 */
	public function test_a_picture_only_html_shows_the_text(): void {
		$this->omnabaseKnows( $this->ada, true );
		$n    = $this->ticketFor( $this->ada, 'Picture only', array( array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'See the picture.', 'bodyHtml' => '<p><img src="https://cdn.example/a.png" alt=""></p>' ) ) );
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$this->assertSame( array( 'See the picture.' ), self::texts( $page, self::BODY . '/p' ) );
		$this->assertSame( 0, self::nodes( $page, self::BODY . '//img' )->length );
	}

	/**
	 * What it checks: (chosen; question 4) A heading inside a message never outranks the conversation's own title, so the page's outline stays
	 *   readable for screen readers and the theme's giant page headings never land inside a bubble.
	 * Passes when: a message with an `h1`, an `h2`, an `h3` and an `h4` prints no `h1` or `h2` inside its bubble, and four headings of rank three
	 *   or four with the same words.
	 * Fails when: a mail's big title becomes the page's second main heading, styled by the theme as the page title.
	 */
	public function test_headings_inside_a_message_rank_below_the_threads_title(): void {
		$this->omnabaseKnows( $this->ada, true );
		$n    = $this->ticketFor( $this->ada, 'Headings', array( array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'One Two Three Four', 'bodyHtml' => '<h1>One</h1><h2>Two</h2><h3>Three</h3><h4>Four</h4>' ) ) );
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$this->assertSame( 0, self::nodes( $page, self::BODY . '//*[self::h1 or self::h2]' )->length, 'nothing above the thread\'s h2' );
		$this->assertSame( array( 'One', 'Two', 'Three', 'Four' ), self::texts( $page, self::BODY . '//*[self::h3 or self::h4]' ) );
	}

	/** The words of an attribute such as rel, sorted. */
	private static function words( string $value ): array {
		$w = preg_split( '/\s+/', strtolower( trim( $value ) ) ) ?: array();
		sort( $w );
		return array_values( array_filter( $w ) );
	}
}
