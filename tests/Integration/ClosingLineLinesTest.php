<?php
/**
 * The polish round (2026-10-31), the red team's failing tests: the line under each forum answer as a few lines (inside wp-env's tests
 * WordPress with bbPress: bin/test-integration.sh's third run, the group "forum").
 *
 * Brief (OmnaBase `docs/briefs/polish-round.md`) outcome 2 and rule 2: "The field is a `<textarea rows="3">`; the stored value keeps line
 * breaks: `sanitize_textarea_field`, lines trimmed, at most 5 lines and 300 characters, tags stripped as today … `DEFAULT_CLOSING_LINE`
 * becomes 'Best regards\n{agent}, {workspace}' … The post renders the line as one `<p>` with `<br>` between lines (each line escaped), after
 * the body as today; blank lines dropped." Report `docs/reviews/polish-round-red-team-report.md` in the OmnaBase repository, findings 17 to
 * 21. The words: *closing line*, the line the plugin adds under every team answer it posts on the forum; *option*, a stored setting
 * (`omnabase_forum`); *escape*, turn `<` and `&` into harmless text so a name can never become HTML on the forum.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The posted reply ends with one paragraph: the lines joined by `<br>` (or `<br />`), each line escaped, blank lines left out; (chosen,
 *   finding 20) no raw line break inside that paragraph, so a theme's automatic paragraphs cannot double the breaks.
 * - Saving keeps the lines: Windows line ends become plain ones, each line trimmed, empty lines dropped, tags stripped, at most 5 lines and
 *   300 characters.
 * - (chosen, finding 18) Saving exactly the default stores no line (empty), so a site that never changed it follows a later default; the
 *   screen shows the default in the box all the same.
 * - (chosen, question 7's default, finding 18) A site whose stored line is still the old default "— {agent}, {workspace}" (every site that
 *   saved the Forums tab before this round, kriesi.at among them) is treated as never set and gets the new default.
 *
 * Why these fail today: the default is "— {agent}, {workspace}", the save runs `sanitize_text_field` (line breaks become spaces) and keeps 200
 * characters, the poster prints the line as one escaped paragraph, and the stored old default is used as typed.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ClosingLineLinesTest extends RoundThreeTestCase {

	private const SAVE = 'omnabase_save_forum';

	/**
	 * What it checks: A site that never set the line signs forum answers the way the founder asked: "Best regards" on one line, the person and
	 *   the workspace on the next.
	 * Passes when: with no line stored, the reply OmnaBase hands over for Ada ends with one paragraph "Best regards<br>Ada, Team Kriesi" and
	 *   that paragraph holds no raw line break.
	 * Fails when: the forum post still ends "— Ada, Team Kriesi", or both lines run together, or a theme doubles the break.
	 */
	public function test_the_default_is_two_lines(): void {
		$topic = $this->filedTopic();
		$this->deliverEvent( 'message.sent', 'dlv-cl-default', $this->teamReply( $topic, 'msg-cl-default' ) );
		$content = $this->lastReply( $topic );
		$this->assertMatchesRegularExpression( '#<p>Best regards<br\s*/?>Ada, Team Kriesi</p>\s*$#', $content );
		$this->assertDoesNotMatchRegularExpression( "#<p>Best regards[^<]*\n#", $content, 'no raw line break inside the closing paragraph' );
	}

	/**
	 * What it checks: An owner's own line of several lines appears line by line, empty lines left out, and a name can never become HTML.
	 * Passes when: with the stored line "Cheers,", an empty line, "{agent} from {workspace}", the reply for an agent named "Ada <b>&" ends with
	 *   "Cheers,<br>Ada &lt;b&gt;&amp; from Team Kriesi" in one paragraph.
	 * Fails when: the forum shows an empty line, a raw tag, or loses the second line.
	 */
	public function test_an_own_line_of_several_lines_is_printed_line_by_line(): void {
		$topic = $this->filedTopic();
		$this->markForums( array( $this->publicForum ), array( 'closing_line' => "Cheers,\n\n{agent} from {workspace}" ) );
		$this->deliverEvent( 'message.sent', 'dlv-cl-own', $this->teamReply( $topic, 'msg-cl-own', 'Ada <b>&' ) );
		$this->assertMatchesRegularExpression( '#<p>Cheers,<br\s*/?>Ada &lt;b&gt;&amp;(?:amp;)? from Team Kriesi</p>\s*$#', $this->lastReply( $topic ) );
	}

	/**
	 * What it checks: Saving the Forums tab keeps the lines the owner typed, cleaned.
	 * Passes when: posting "  Cheers, ", "{agent} <b>from</b> {workspace}  ", an empty line (Windows line ends) stores "Cheers," and
	 *   "{agent} from {workspace}" separated by one plain line break.
	 * Fails when: the save squashes the lines into one (WordPress's one-line clean-up), keeps the tag, or stores trailing blanks.
	 */
	public function test_saving_keeps_trimmed_lines_without_tags(): void {
		$this->save( array( 'closing_line' => "  Cheers, \r\n{agent} <b>from</b> {workspace}  \r\n\r\n" ) );
		$this->assertSame( "Cheers,\n{agent} from {workspace}", $this->stored() );
	}

	/**
	 * What it checks: The line stays short: at most five lines and 300 characters.
	 * Passes when: seven lines save as their first five; one line of 400 characters saves as at most 300.
	 * Fails when: a pasted signature block of twenty lines lands under every forum answer.
	 */
	public function test_saving_caps_lines_and_characters(): void {
		$this->save( array( 'closing_line' => implode( "\n", array( 'one', 'two', 'three', 'four', 'five', 'six', 'seven' ) ) ) );
		$this->assertSame( "one\ntwo\nthree\nfour\nfive", $this->stored() );
		$this->save( array( 'closing_line' => str_repeat( 'x', 400 ) ) );
		$this->assertLessThanOrEqual( 300, mb_strlen( $this->stored() ) );
		$this->assertGreaterThanOrEqual( 250, mb_strlen( $this->stored() ), 'cut at the cap, not emptied' );
	}

	/**
	 * What it checks: (chosen) Saving the Forums tab without touching the line keeps the site on the default, so a later default reaches it.
	 * Passes when: posting exactly the default (as the box shows it, with Windows line ends) stores an empty line, and the screen's box still
	 *   shows "Best regards" and "{agent}, {workspace}" on two lines.
	 * Fails when: every site that ever saved the tab is frozen on the default of that day, as kriesi.at is on "— {agent}, {workspace}" now.
	 */
	public function test_saving_the_default_stores_nothing(): void {
		$this->save( array( 'closing_line' => "Best regards\r\n{agent}, {workspace}" ) );
		$this->assertSame( '', $this->stored() );
		$box = $this->box();
		$this->assertNotNull( $box, 'the textarea is on the Forums tab' );
		$this->assertSame( "Best regards\n{agent}, {workspace}", trim( str_replace( "\r\n", "\n", $box->textContent ) ) );
	}

	/**
	 * What it checks: (chosen, question 7) A site still holding the old default gets the new one, without anyone re-typing it.
	 * Passes when: with the stored line "— {agent}, {workspace}", the reply for Ada ends with "Best regards<br>Ada, Team Kriesi".
	 * Fails when: the founder updates kriesi.at and still reads "— Günter, Kriesi" under every answer, and thinks the change did not ship.
	 */
	public function test_the_old_default_counts_as_not_set(): void {
		$topic = $this->filedTopic();
		$this->markForums( array( $this->publicForum ), array( 'closing_line' => '— {agent}, {workspace}' ) );
		$this->deliverEvent( 'message.sent', 'dlv-cl-old', $this->teamReply( $topic, 'msg-cl-old' ) );
		$this->assertMatchesRegularExpression( '#<p>Best regards<br\s*/?>Ada, Team Kriesi</p>\s*$#', $this->lastReply( $topic ) );
	}

	/**
	 * What it checks: (the founder's decision of 2026-10-31, overturning the pinned default "AI") The AI's own forum answers sign off as the
	 *   workspace's team, not as "AI".
	 * Passes when: with no line stored, an answer OmnaBase's AI wrote (kind "ai", author "AI") ends with "Best regards<br>The Team Kriesi team",
	 *   without a repeated ", Team Kriesi"; a person's answer still ends "Best regards<br>Ada, Team Kriesi" (the default test above).
	 * Fails when: forum visitors read "Best regards / AI, Kriesi" under the automatic answers, or "The Kriesi team, Kriesi".
	 * Added 2026-10-31 by the builder on the founder's decision (question 8 overturned; no red-team test had pinned "AI, Kriesi").
	 */
	public function test_the_ai_signs_as_the_workspace_team(): void {
		$topic = $this->filedTopic();
		$event = $this->teamReply( $topic, 'msg-cl-ai', 'AI' );
		$event['message']['kind'] = 'ai';
		$this->deliverEvent( 'message.sent', 'dlv-cl-ai', $event );
		$content = $this->lastReply( $topic );
		$this->assertMatchesRegularExpression( '#<p>Best regards<br\s*/?>The Team Kriesi team</p>\s*$#', $content );
		$this->assertStringNotContainsString( 'AI, Team Kriesi', wp_strip_all_tags( $content ) );
	}

	/** The newest reply's stored content on a topic. */
	private function lastReply( int $topic ): string {
		$replies = self::repliesOn( $topic );
		$this->assertNotEmpty( $replies, 'a reply was posted' );
		return (string) end( $replies )->post_content;
	}

	/** The stored closing line ('' when none). */
	private function stored(): string {
		$saved = (array) get_option( self::FORUM_OPTION, array() );
		return (string) ( $saved['closing_line'] ?? '' );
	}

	/** The Forums tab's closing-line box, or null. */
	private function box(): ?\DOMElement {
		wp_set_current_user( $this->admin );
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $this->openTab( 'forums' ) . '</div>' );
		libxml_clear_errors();
		$node = ( new \DOMXPath( $doc ) )->query( "//textarea[@name='omnabase_forum[closing_line]']" )->item( 0 );
		return $node instanceof \DOMElement ? $node : null;
	}

	/**
	 * Posts the forum form as the administrator.
	 *
	 * @param array $fields The form's fields under `omnabase_forum`.
	 */
	private function save( array $fields ): void {
		wp_set_current_user( $this->admin );
		$_POST    = array(
			'action'         => self::SAVE,
			'omnabase_forum' => $fields + array( 'forums' => array( (string) $this->publicForum ), 'support_user' => (string) $this->support ),
			'_wpnonce'       => wp_create_nonce( self::SAVE ),
		);
		$_REQUEST = $_POST;
		try {
			do_action( 'admin_post_' . self::SAVE );
		} catch ( Redirected $r ) {
			unset( $r );
		} catch ( \WPDieException $e ) {
			unset( $e );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
	}
}
