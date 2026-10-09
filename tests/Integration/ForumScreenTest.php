<?php
/**
 * M13 round three, the red team's failing tests: Settings › OmnaBase gains the forum section (a tick per forum, the support user, the closing
 * line, the guest switch, closing on resolve).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 2: "the forums to redirect, the guest-topic switch and the support
 * user"; choice 7: "The admin marks forums. **Every forum except hidden ones counts as public** …; the screen says why in one line"; "Guest
 * topics forward by default (a switch; the screen says the address is unchecked)"; "the agent's name in a closing line"; "`ticket.updated` to
 * resolved closes the topic only when the admin turns that on"; "Edits and deletions are not synced in v1 (said on the screen)"; Q2: "one
 * support user". The plugin's `CLAUDE.md`: every description at most 30 words; "a nonce and `current_user_can( 'manage_options' )` on every
 * admin action". The coordinator: "the settings screen's forum list with a tick per forum (the option in the uninstall list; the support user
 * picker: a user with moderator rights; the closing-line setting)". The words: **nonce**, a short-lived form token against forged requests;
 * **moderator rights**, bbPress's permission to moderate (`moderate`).
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team), besides `RoundThreeTestCase`'s:
 * - The forum section is `.ob-wp-settings__forum`: a form to `admin-post.php` with the action `omnabase_save_forum` (its nonce of the same
 *   name): a checkbox per forum `omnabase_forum[forums][]` (value: the forum's id), a select `omnabase_forum[support_user]`, a text field
 *   `omnabase_forum[closing_line]`, checkboxes `omnabase_forum[guests]` and `omnabase_forum[close_resolved]` (value `1`).
 * - Without bbPress the section says so and offers nothing to tick (not tested here: this run has bbPress).
 *
 * Why these fail today: the screen has no forum section and the action does not exist.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumScreenTest extends RoundThreeTestCase {

	private const SAVE = 'omnabase_save_forum';

	/**
	 * Posts the forum form.
	 *
	 * @param array $fields The form's fields under `omnabase_forum`.
	 * @param bool  $nonce  Whether the nonce is sent.
	 * @return string|null Where the handler redirected, or null.
	 */
	private function save( array $fields, bool $nonce = true ): ?string {
		$_POST    = array( 'action' => self::SAVE, 'omnabase_forum' => $fields ) + ( $nonce ? array( '_wpnonce' => wp_create_nonce( self::SAVE ) ) : array() );
		$_REQUEST = $_POST;
		try {
			do_action( 'admin_post_' . self::SAVE );
		} catch ( Redirected $r ) {
			return $r->location;
		} catch ( \WPDieException $e ) {
			return null;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return null;
	}

	/** The forum section's HTML (empty when missing). */
	private function section(): string {
		wp_set_current_user( $this->admin );
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $this->renderScreen() . '</div>' );
		libxml_clear_errors();
		$node = ( new \DOMXPath( $doc ) )->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ob-wp-settings__forum ")]' )->item( 0 );
		return $node ? (string) $doc->saveHTML( $node ) : '';
	}

	/** The nodes matching an XPath query in some HTML. */
	private static function nodes( string $html, string $xpath ): array {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		return iterator_to_array( ( new \DOMXPath( $doc ) )->query( $xpath ) );
	}

	/** Text without tags, spaces collapsed. */
	private static function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
	}

	/**
	 * What it checks: The screen lists every forum of the site with a tick, the marked ones ticked, and says in one line why a hidden forum's
	 *   topics are not public.
	 * Passes when: the forum section has one checkbox per forum (Support, Members, Staff, Off topic) named by the forum's id, the three marked
	 *   forums checked and Off topic not; the section's text names each forum, says "not public" for the hidden one, says that edits and
	 *   deletions are not sent, and that guests' addresses are unchecked.
	 * Fails when: the site owner cannot see or choose which forums feed the inbox, or does not learn which topics OmnaBase treats as public.
	 */
	public function test_the_forum_section_lists_every_forum_with_a_tick(): void {
		$html = $this->section();
		$this->assertNotSame( '', $html, 'the forum section is on the screen' );

		$boxes = array();
		foreach ( self::nodes( $html, '//input[@type="checkbox"][@name="omnabase_forum[forums][]"]' ) as $box ) {
			$boxes[ (int) $box->getAttribute( 'value' ) ] = $box->hasAttribute( 'checked' );
		}
		$this->assertSame( array( $this->publicForum => true, $this->privateForum => true, $this->hiddenForum => true, $this->unmarkedForum => false ), array_intersect_key( $boxes, array_flip( array( $this->publicForum, $this->privateForum, $this->hiddenForum, $this->unmarkedForum ) ) ) );
		$text = self::text( $html );
		foreach ( array( 'Support', 'Members', 'Staff', 'Off topic' ) as $title ) {
			$this->assertStringContainsString( $title, $text );
		}
		$this->assertMatchesRegularExpression( '/not public/i', $text );
		$this->assertMatchesRegularExpression( '/edit.{0,80}(not|never)|(not|never).{0,80}edit/i', $text );
		$this->assertMatchesRegularExpression( '/unchecked|not checked|unverified/i', $text );
	}

	/**
	 * What it checks: Saving the forum section stores exactly what the administrator chose.
	 * Passes when: ticking only Support and Off topic, choosing Nina as the support user, a closing line, guests off and closing on resolve on,
	 *   then saving, leaves the forum option with exactly those forums, Nina, the line, guests false and closing true, and goes back to the
	 *   screen.
	 * Fails when: ticks are lost, a forum stays marked after unticking it, or the switches cannot be turned off.
	 */
	public function test_saving_stores_exactly_the_choices(): void {
		$back = $this->save( array( 'forums' => array( (string) $this->publicForum, (string) $this->unmarkedForum ), 'support_user' => (string) $this->moderator, 'closing_line' => 'Cheers, {agent} <b>from</b> {workspace}' , 'close_resolved' => '1' ) );

		$this->assertNotNull( $back );
		$this->assertStringContainsString( 'page=omnabase', (string) $back );
		$saved = (array) get_option( self::FORUM_OPTION, array() );
		$this->assertEqualsCanonicalizing( array( $this->publicForum, $this->unmarkedForum ), array_map( 'intval', (array) ( $saved['forums'] ?? array() ) ) );
		$this->assertSame( $this->moderator, (int) ( $saved['support_user'] ?? 0 ) );
		$this->assertSame( 'Cheers, {agent} from {workspace}', $saved['closing_line'] ?? null, 'stored as plain text' );
		$this->assertFalse( (bool) ( $saved['guests'] ?? true ), 'unticked guests switch' );
		$this->assertTrue( (bool) ( $saved['close_resolved'] ?? false ) );
	}

	/**
	 * What it checks: The support user can only be someone with moderator rights in the forum.
	 * Passes when: the support user picker offers the support user and Nina (moderators) and neither Ada nor Ben (participants); posting Ben's
	 *   id anyway leaves the stored support user unchanged.
	 * Fails when: OmnaBase's answers are posted under a customer's account, or under someone who could not reply on a closed topic.
	 */
	public function test_the_support_user_must_have_moderator_rights(): void {
		$options = array_map( static fn ( $o ) => (int) $o->getAttribute( 'value' ), self::nodes( $this->section(), '//select[@name="omnabase_forum[support_user]"]/option' ) );
		$this->assertContains( $this->support, $options );
		$this->assertContains( $this->moderator, $options );
		$this->assertNotContains( $this->ada, $options );
		$this->assertNotContains( $this->ben, $options );

		$this->save( array( 'forums' => array( (string) $this->publicForum ), 'support_user' => (string) $this->ben, 'guests' => '1' ) );

		$this->assertSame( $this->support, (int) ( ( (array) get_option( self::FORUM_OPTION, array() ) )['support_user'] ?? 0 ) );
	}

	/**
	 * What it checks: Only an administrator, through the screen's own form, can change the forum settings.
	 * Passes when: a save without the form token changes nothing; a save by an editor changes nothing.
	 * Fails when: a forged link or a lower account turns every forum into a ticket source or changes whose name OmnaBase posts under.
	 */
	public function test_only_an_administrator_with_the_form_token_can_save(): void {
		$before = get_option( self::FORUM_OPTION );

		$this->save( array( 'forums' => array( (string) $this->unmarkedForum ) ), false );
		$this->assertSame( $before, get_option( self::FORUM_OPTION ), 'no token' );

		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->save( array( 'forums' => array( (string) $this->unmarkedForum ) ) );
		$this->assertSame( $before, get_option( self::FORUM_OPTION ), 'an editor' );
	}

	/**
	 * What it checks: The forum section's descriptions stay short (the plugin's settings copy rule).
	 * Passes when: every paragraph or description in the forum section has at most 30 words.
	 * Fails when: the section grows walls of text the site owner skips.
	 */
	public function test_the_forum_sections_descriptions_are_at_most_30_words(): void {
		$html = $this->section();
		$this->assertNotSame( '', $html );
		foreach ( self::nodes( $html, '//p | //*[contains(concat(" ", normalize-space(@class), " "), " description ")]' ) as $node ) {
			$words = str_word_count( self::text( (string) $node->textContent ) );
			$this->assertLessThanOrEqual( 30, $words, self::text( (string) $node->textContent ) );
		}
	}
}
