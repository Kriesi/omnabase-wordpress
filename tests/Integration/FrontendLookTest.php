<?php
/**
 * THE PLUGIN'S FORMS ROUND (2026-10-25), THE BUILDER: THREE CLASSIC TESTS DELETED, AS THE RED TEAM'S REPORT LISTS THEM. What changed and why:
 * the Classic contact form (its success card, its fields with their error lines, its kit controls) left with the round (OmnaBase
 * `docs/briefs/plugin-forms-round.md`); the form is now drawn by OmnaBase's embed, whose look `form-look.spec.ts` checks in a real browser. The
 * three tests of that markup went with the feature; the four look tests (the wrapper's marks, the accent, "Theme styles only", the font) and
 * the message centre's tests stay unchanged.
 *
 * The front-end design round (2026-10-22), the red team's failing tests: what the contact form and the message centre print after each
 * Appearance choice, and the markup of the new pieces (the success card, the field errors' wiring, the status pills, the thread's authors, the
 * kit's controls) (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/plugin-frontend-design.md`) rule 2 (the wrapper "carries `data-theme` and the accent as the app does"; the font
 * "loads it only then"; "Theme styles only" "(the plugin's stylesheet off entirely)"); rule 3: "the kit's field layout (label above, the
 * field, … the error under the field … `aria-describedby`), the kit's `.ob-input`, `.ob-textarea`, `.ob-select` and `.ob-btn--primary`; … the
 * success state replaces the form with a card: the kit's check icon on the accent, "Thanks. Your reference is #123", "We answer by email.",
 * and a "Send another" link that brings the form back; … The honeypot, the time token, the rate limits and the field names stay exactly as
 * they are"; rule 4: "the status pill in the app's status colours and words: Open, Answered, Resolved, Closed; … the paging links as the
 * kit's outline buttons; … the visitor's messages aligned and tinted …, the team's with the mark … when the API gives one), the reply box as
 * the app's composer (the kit's textarea and primary button), the back link as the kit's ghost button; the confirmation prompt as the app's
 * `.ob-portal-confirm` card; … notices as the kit's notice".
 *
 * The words: *wrapper*, *enqueue*, *as the app does* as in `AppearanceTestCase`; *success card*, the box that takes the form's place once a
 * message went; *screen reader*, software that reads the page aloud (it learns which error belongs to which field through
 * `aria-describedby`, an attribute naming the error's id, and that a field is wrong through `aria-invalid`); *status pill*, the small rounded
 * label with a conversation's state; *kind*, OmnaBase's word for who wrote a message in the message centre (`you`, the visitor; `team`; `other`,
 * someone else on the conversation); *honeypot*, a field hidden from people that only bots fill in.
 *
 * Shapes the red team pins (chosen where the brief names none; the builder may rename and tell the red team): the success card is in the
 * form's markup from the start, hidden, outside the `<form>` and inside the wrapper (so its words are translated by WordPress like every other
 * sentence: the form's script holds no translated words today), with a `role="status"` or `aria-live` so its words are read out; its "Send
 * another" is a button or a link; each field's error element has an id the field's `aria-describedby` names; a message's author kind is
 * its modifier class `ob-wp-messages__message--<kind>` (today's, kept); the status pill is the kit's `ob-pill` with a modifier per status.
 *
 * Why these fail today: no Appearance choices exist, so no wrapper carries `data-theme` or an accent; there is no font stylesheet; the form has
 * no success card and no `aria-describedby`; the controls wear the plugin's own classes (`ob-wp-form__input` …), not the kit's; the pills,
 * the back link, the paging links, the reply box, the prompt and the notices are the plugin's own too.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class FrontendLookTest extends AppearanceTestCase {

	/**
	 * What it checks: Each theme choice reaches both shortcodes' wrappers the way OmnaBase's app marks its page.
	 * Passes when: after saving Light, the contact form's wrapper, the message centre's and the logged-out form of `[omnabase_messages
	 *   guest="form"]` carry `data-theme="light"`; after Dark, `data-theme="dark"`; after Follow the visitor's system, no `data-theme` at all
	 *   (the app's "system": the attribute absent, the stylesheet follows the device).
	 * Fails when: the owner picks Dark and the form stays light (the dark colours copied but never switched on), one of the two shortcodes
	 *   ignores the choice, or "system" writes a value the stylesheet does not know.
	 */
	public function test_the_wrappers_carry_the_theme_per_choice(): void {
		$this->omnabaseKnows( $this->ada, true );
		foreach ( array( 'light' => 'light', 'dark' => 'dark', 'system' => null ) as $choice => $mark ) {
			$this->saveAppearance( array( 'theme' => $choice ) );
			$pieces = array( 'the contact form' => $this->formAsVisitor(), 'the message centre' => $this->centreAs( $this->ada ) );
			wp_update_post( array( 'ID' => $this->picked, 'post_content' => '[omnabase_messages guest="form"]' ) );
			self::freshQueue();
			$pieces['the logged-out form of the message centre'] = $this->view( 0 );
			wp_update_post( array( 'ID' => $this->picked, 'post_content' => '[omnabase_messages]' ) );
			foreach ( $pieces as $what => $html ) {
				$wrapper = self::wrapperOf( $html );
				$this->assertNotNull( $wrapper, "{$choice}: {$what} has its wrapper" );
				if ( null === $mark ) {
					$this->assertFalse( $wrapper->hasAttribute( 'data-theme' ), "{$choice}: {$what} carries no theme mark (the device decides)" );
				} else {
					$this->assertSame( $mark, $wrapper->getAttribute( 'data-theme' ), "{$choice}: {$what}" );
				}
			}
		}
	}

	/**
	 * What it checks: The owner's colour reaches the wrapper as OmnaBase's app writes it, with the text colour the app would choose.
	 * Passes when: with OmnaBase's blue the form's wrapper has no accent mark and no accent variable; with teal (#0f7c8a) it has
	 *   `data-accent="custom"`, `--ob-accent-user: #0f7c8a` and `--ob-on-accent-user: #ffffff`; with a pale yellow (#f5e663) the text colour is
	 *   #1a1a1a; the message centre's wrapper carries the same.
	 * Fails when: the owner's colour never reaches the page, the Send button's words are white on pale yellow (unreadable), or the plugin's
	 *   rule differs from the app's so the same workspace looks different on the site.
	 */
	public function test_the_accent_reaches_the_wrapper_as_the_app_writes_it(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->saveAppearance( array( 'accent' => 'blue' ) );
		$wrapper = self::wrapperOf( $this->formAsVisitor() );
		$this->assertNotNull( $wrapper );
		$this->assertFalse( $wrapper->hasAttribute( 'data-accent' ), 'OmnaBase\'s blue: no accent mark' );
		$this->assertArrayNotHasKey( '--ob-accent-user', self::inlineVariables( $wrapper ), 'OmnaBase\'s blue: no accent variable' );

		foreach ( array( self::TEAL => self::ON_DARK, self::PALE => self::ON_LIGHT ) as $colour => $text ) {
			$this->saveAppearance( array( 'accent' => $colour ) );
			foreach ( array( 'the form' => $this->formAsVisitor(), 'the message centre' => $this->centreAs( $this->ada ) ) as $what => $html ) {
				$wrapper = self::wrapperOf( $html );
				$this->assertNotNull( $wrapper, $what );
				$this->assertSame( 'custom', $wrapper->getAttribute( 'data-accent' ), "{$colour}: {$what} is marked" );
				$vars = self::inlineVariables( $wrapper );
				$this->assertSame( $colour, $vars['--ob-accent-user'] ?? null, "{$colour}: {$what}'s accent" );
				$this->assertSame( $text, $vars['--ob-on-accent-user'] ?? null, "{$colour}: {$what}'s text on the accent" );
			}
		}
	}

	/**
	 * What it checks: "Theme styles only" keeps every OmnaBase stylesheet away, the font's too, but the markup and its theme mark stay.
	 * Passes when: with Dark, OmnaBase's font and "Theme styles only" saved, rendering the form and the message centre queues no stylesheet
	 *   from the plugin's folder at all, and both wrappers still carry `data-theme="dark"`; turned off again, the form's stylesheet and the font's
	 *   stylesheet are queued.
	 * Fails when: the owner asked for the theme's look alone and still gets OmnaBase's font or colours, or a theme that styles the plugin's
	 *   markup by its theme mark loses that mark when the stylesheet goes.
	 */
	public function test_theme_styles_only_loads_nothing_but_keeps_the_marks(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->saveAppearance( array( 'theme' => 'dark', 'font' => 'load', 'themeOnly' => true ) );
		$form = $this->formAsVisitor();
		$this->assertSame( array(), $this->pluginStyles(), 'the form loads nothing from the plugin\'s folder' );
		$this->assertSame( 'dark', self::wrapperOf( $form )?->getAttribute( 'data-theme' ), 'the form keeps its theme mark' );
		$centre = $this->centreAs( $this->ada );
		$this->assertSame( array(), $this->pluginStyles(), 'the message centre loads nothing from the plugin\'s folder' );
		$this->assertSame( 'dark', self::wrapperOf( $centre )?->getAttribute( 'data-theme' ), 'the message centre keeps its theme mark' );

		$this->saveAppearance( array( 'themeOnly' => false ) );
		$this->formAsVisitor();
		$this->assertTrue( $this->formCssQueued(), 'the form\'s stylesheet is back' );
		$this->assertNotSame( array(), $this->fontStyles(), 'and the font\'s, since the font was chosen' );
	}

	/**
	 * What it checks: The font's stylesheet is queued only when the owner chose OmnaBase's font, for both shortcodes, and never on the admin.
	 * Passes when: with "Inherit the theme's font" neither the form nor the message centre queues a stylesheet that declares a font; with "Load
	 *   OmnaBase's font" both queue exactly one, besides the form's own stylesheet; the settings screen queues none.
	 * Fails when: every visitor downloads the font although the owner kept the theme's (slower pages, the brief's "loads it only then"), or
	 *   the choice works for one shortcode only.
	 */
	public function test_the_font_loads_only_when_chosen(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->saveAppearance( array( 'font' => 'inherit' ) );
		$this->formAsVisitor();
		$this->assertTrue( $this->formCssQueued(), 'the form\'s stylesheet as usual' );
		$this->assertSame( array(), $this->fontStyles(), 'the form: no font' );
		$this->centreAs( $this->ada );
		$this->assertSame( array(), $this->fontStyles(), 'the message centre: no font' );

		$this->saveAppearance( array( 'font' => 'load' ) );
		$this->formAsVisitor();
		$this->assertCount( 1, $this->fontStyles(), 'the form: the font\'s stylesheet, once' );
		$this->centreAs( $this->ada );
		$this->assertCount( 1, $this->fontStyles(), 'the message centre: the font\'s stylesheet, once' );

		self::freshQueue();
		$this->asAdminOnTheScreen( $this->admin );
		do_action( 'admin_enqueue_scripts', 'settings_page_omnabase' );
		$this->assertSame( array(), $this->fontStyles(), 'the settings screen keeps WordPress\'s font' );
	}

	/**
	 * What it checks: Each conversation's status reads as a word in the kit's pill, one look per status, in the list and in the thread.
	 * Passes when: for four conversations OmnaBase reports as open, pending, resolved and archived, each row's pill carries the kit's `ob-pill`,
	 *   a modifier naming its status, and reads Open, Answered, Resolved and Closed; the thread of the archived one shows the same pill.
	 * Fails when: the state shows as colour alone (a colour-blind customer cannot tell Answered from Open), a word changes, or two different
	 *   states share one look.
	 */
	public function test_the_status_pills_carry_the_words(): void {
		$words = array( 'open' => 'Open', 'pending' => 'Answered', 'resolved' => 'Resolved', 'archived' => 'Closed' );
		$numbers = array();
		foreach ( array_keys( $words ) as $status ) {
			$numbers[ $status ] = $this->ticketFor( $this->ada, "Pill {$status}", array( array( 'kind' => 'you', 'name' => 'Ada Lovelace', 'bodyText' => 'Hello.' ) ), array( 'status' => $status ) );
		}
		$html = $this->centreAs( $this->ada );
		$modifiers = array();
		foreach ( $words as $status => $word ) {
			$row = self::nodes( $html, "//li[.//a[contains(@href,'omnabase_ticket=" . $numbers[ $status ] . "')]]" )->item( 0 );
			$this->assertInstanceOf( \DOMElement::class, $row, "the row of the {$status} conversation" );
			$pill = ( new \DOMXPath( $row->ownerDocument ) )->query( './/*[' . self::hasClass( 'ob-pill' ) . ']', $row )->item( 0 );
			$this->assertInstanceOf( \DOMElement::class, $pill, "{$status}: the kit's pill" );
			$this->assertSame( $word, self::wordsOf( $pill ), "{$status}: its word" );
			$classes = preg_split( '/\s+/', trim( (string) $pill->getAttribute( 'class' ) ) );
			$mine    = preg_grep( '/--' . $status . '$/', $classes );
			$this->assertNotEmpty( $mine, "{$status}: a modifier naming the status" );
			$modifiers[ $status ] = reset( $mine );
		}
		$this->assertCount( 4, array_unique( $modifiers ), 'four states, four looks' );
		$thread = $this->centreAs( $this->ada, array( self::TICKET_QUERY => $numbers['archived'] ) );
		$this->assertSame( 'Closed', self::wordsOf( self::nodes( $thread, '//*[' . self::hasClass( 'ob-pill' ) . ']' )->item( 0 ) ?? new \DOMText( '' ) ), 'the thread shows the same pill' );
	}

	/**
	 * What it checks: Each message in a thread is marked with who wrote it, exactly as OmnaBase says, never swapped (a guard: today's markup
	 *   already does this; the redesign must keep it).
	 * Passes when: for a thread with the visitor's message, the team's answer and a third person's line, each message carries the modifier of
	 *   its own kind (you, team, other) and no other kind's; the team's answer never carries the visitor's mark, nor the visitor's the team's;
	 *   each shows its author's name as OmnaBase sends it ("You", the team member's name, "Another participant").
	 * Fails when: the redesign inverts the tinting, so the customer reads the team's answer as their own words (or a third person's line as
	 *   theirs).
	 */
	public function test_each_message_is_marked_with_its_author(): void {
		$n = $this->ticketFor(
			$this->ada,
			'Who wrote what',
			array(
				array( 'kind' => 'you', 'name' => 'You', 'bodyText' => 'MY-QUESTION' ),
				array( 'kind' => 'team', 'name' => 'Kriesi support', 'bodyText' => 'TEAM-ANSWER' ),
				array( 'kind' => 'other', 'name' => 'Another participant', 'bodyText' => 'THIRD-LINE' ),
			)
		);
		$html = $this->centreAs( $this->ada, array( self::TICKET_QUERY => $n ) );
		foreach ( array( 'MY-QUESTION' => array( 'you', 'You' ), 'TEAM-ANSWER' => array( 'team', 'Kriesi support' ), 'THIRD-LINE' => array( 'other', 'Another participant' ) ) as $text => [ $kind, $name ] ) {
			$item = self::nodes( $html, "//*[" . self::hasClass( 'ob-wp-messages__message' ) . "][contains(., '{$text}')]" )->item( 0 );
			$this->assertInstanceOf( \DOMElement::class, $item, "the message {$text}" );
			$classes = (string) $item->getAttribute( 'class' );
			$this->assertMatchesRegularExpression( '/(^|\s)ob-wp-messages__message--' . $kind . '(\s|$)/', $classes, "{$text}: marked {$kind}" );
			foreach ( array_diff( array( 'you', 'team', 'other' ), array( $kind ) ) as $not ) {
				$this->assertDoesNotMatchRegularExpression( '/(^|\s)ob-wp-messages__message--' . $not . '(\s|$)/', $classes, "{$text}: never marked {$not}" );
			}
			$this->assertStringContainsString( $name, self::wordsOf( $item ), "{$text}: its author's name" );
		}
	}

	/**
	 * What it checks: The message centre's pieces wear the kit's look: the reply box, the back link, the paging links, the confirmation prompt
	 *   and the notices.
	 * Passes when: the reply form's text box carries the kit's `ob-textarea` and its submit the kit's `ob-btn--primary`; the "All conversations"
	 *   link the kit's `ob-btn--ghost`; with more conversations than one page holds, the paging link the kit's `ob-btn--outline`; the prompt to
	 *   confirm the address sits in the kit's `ob-portal-confirm` card with a primary button; a notice after a reply is the kit's `ob-notice`,
	 *   and no picture shows in the thread (the API gives no logo).
	 * Fails when: the message centre keeps the plugin's own controls next to a redesigned form (two looks on one site), or shows a broken
	 *   picture where a logo would go.
	 */
	public function test_the_message_centre_wears_the_kits_pieces(): void {
		$this->pageSize = 2;
		foreach ( range( 1, 3 ) as $i ) {
			$this->ticketFor( $this->ada, "Paged {$i}" );
		}
		$n = $this->ticketFor( $this->ada, 'Thread', array( array( 'kind' => 'you', 'name' => 'Ada Lovelace', 'bodyText' => 'Hi.' ), array( 'kind' => 'team', 'name' => 'Kriesi support', 'bodyText' => 'Hello.' ) ) );

		$list = $this->centreAs( $this->ada, array(), false );
		$this->assertSame( 1, self::nodes( $list, '//*[' . self::hasClass( 'ob-portal-confirm' ) . "][.//button[" . self::hasClass( 'ob-btn--primary' ) . ']]' )->length, 'the prompt: the kit\'s confirm card with a primary button' );
		$this->assertGreaterThan( 0, self::nodes( $list, "//a[contains(@href,'omnabase_') and not(contains(@href,'omnabase_ticket='))][" . self::hasClass( 'ob-btn--outline' ) . ']' )->length, 'the paging link: the kit\'s outline button' );

		$this->linkOpened( $this->ada );
		$thread = $this->centreAs( $this->ada, array( self::TICKET_QUERY => $n ) );
		$this->assertSame( 1, self::nodes( $thread, '//a[' . self::hasClass( 'ob-btn--ghost' ) . "][contains(normalize-space(.), 'All conversations')]" )->length, 'the back link: the kit\'s ghost button' );
		$reply = self::formIn( $thread, self::replyForm() );
		$this->assertNotNull( $reply, 'the reply form' );
		$this->assertSame( 1, self::nodes( $thread, "//form[.//textarea and not(.//input[@name='subject'])]//textarea[" . self::hasClass( 'ob-textarea' ) . ']' )->length, 'the reply box: the kit\'s textarea' );
		$this->assertSame( 1, self::nodes( $thread, "//form[.//textarea and not(.//input[@name='subject'])]//button[" . self::hasClass( 'ob-btn--primary' ) . ']' )->length, 'its button: the kit\'s primary' );
		$this->assertSame( 0, self::nodes( $thread, "//*[" . self::hasClass( 'ob-wp-messages__thread' ) . "]//img" )->length, 'no picture where no logo was given' );

		$sent = $this->send( $this->ada, $thread, self::replyForm(), array( 'textarea' => 'Thanks!' ) );
		$this->assertNotNull( $sent['location'], 'the reply redirects back' );
		$this->assertSame( 1, self::nodes( $sent['html'], '//*[' . self::hasClass( 'ob-notice' ) . "][contains(normalize-space(.), 'Your answer was sent.')]" )->length, 'the notice: the kit\'s notice' );
	}
}
