<?php
/**
 * M13 round five, the red team's failing tests: the confirm prompt (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 9: "**Confirm prompt** while `confirmed` is false: 'Show my email
 * conversations' → `POST /portal/confirm` → 'We sent a link to a…@example.com. Open it within 24 hours.' After confirming, the visitor also
 * sees their mail and form conversations and their forum topics on this site"; "Each view first makes sure OmnaBase knows the user: `PUT
 * /customers/by-external/<site id> { email, name }` when address or name changed (a stored fingerprint), keeping `confirmed`"; choice 2: "The
 * confirm prompt mirrors the kit's `PortalConfirm` (M12 round zero) in PHP"; "Risks": "visitors who never click see only what they filed in
 * the message centre. The prompt says why in one line." The coordinator: "`Send me the link` → `POST /portal/confirm` through the plugin,
 * rate-limited per visitor; the sentence after sending".
 *
 * Shapes the red team pins: `RoundFiveTestCase`. (chosen) While OmnaBase says `confirmed: false`, every view asks again (otherwise the prompt
 * would never leave after the link is clicked, since OmnaBase does not call the site); once confirmed and unchanged, a view sends no `PUT`
 * (the brief's fingerprint). (chosen) The plugin's own limit mirrors OmnaBase's: three links a day per visitor, the fourth never sent.
 *
 * Why these fail today: no message centre exists, so there is no prompt and nothing posts to OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class MessageCentreConfirmTest extends RoundFiveTestCase {

	/**
	 * What it checks: A customer who has not proved their address sees the prompt with a one-line reason; a confirmed customer does not.
	 * Passes when: for an unconfirmed Ada the page holds the "Show my email conversations" button and a sentence of its own beside it; for a
	 *   confirmed Ben the page has no such button.
	 * Fails when: customers never learn how to see their older conversations, or confirmed customers are nagged on every visit.
	 */
	public function test_the_prompt_shows_until_the_address_is_confirmed(): void {
		$this->omnabaseKnows( $this->ben, true );
		$ada = $this->view( $this->ada );
		$this->assertTrue( self::hasPrompt( $ada ), 'the prompt for an unconfirmed customer' );
		$form  = self::nodes( $ada, '//form[' . self::promptForm() . ']' )->item( 0 );
		$box   = $form->parentNode instanceof \DOMElement ? $form->parentNode : $form;
		$words = str_word_count( self::text( (string) $box->ownerDocument->saveHTML( $box ) ) ) - str_word_count( 'Show my email conversations' );
		$this->assertGreaterThanOrEqual( 6, $words, 'a sentence says why, beside the button' );
		$this->assertFalse( self::hasPrompt( $this->view( $this->ben ) ), 'no prompt once confirmed' );
	}

	/**
	 * What it checks: A customer who never wrote by email (nothing waits behind the link) is not asked to confirm their address.
	 * Passes when: OmnaBase says no hidden conversations exist for unconfirmed Ada, and her page has no confirm button but still her list.
	 * Fails when: every customer is asked to confirm their email although confirming would show nothing new, which reads like a needless
	 *   chore or a broken page.
	 */
	public function test_no_prompt_when_nothing_waits_behind_the_link(): void {
		$this->hiddenHistory = false;
		$html = $this->view( $this->ada );
		$this->assertFalse( self::hasPrompt( $html ), 'no prompt without hidden conversations' );
		$this->assertMatchesRegularExpression( '/Your conversations/i', self::text( $html ) );
	}

	/**
	 * What it checks: Pressing the button asks OmnaBase once to mail the link, and the customer reads where it went, with the address partly
	 *   hidden.
	 * Passes when: sending the prompt's form makes exactly one `POST /portal/confirm` naming Ada by her site id, redirects back to the message
	 *   centre, and the page then says "We sent a link to" with her address's domain, "24 hours", and not her full address.
	 * Fails when: the button does nothing, sends several mails, or prints the whole address on a page someone else may be looking at.
	 */
	public function test_pressing_the_button_asks_omnabase_once_and_says_where_the_link_went(): void {
		$html = $this->view( $this->ada );
		$this->calls = array();
		$sent = $this->send( $this->ada, $html, self::promptForm() );
		$this->assertNotNull( $sent['location'], 'the post redirects back' );
		$asks = $this->portalCalls( 'POST', '/portal/confirm' );
		$this->assertCount( 1, $asks, 'one request for the link' );
		$this->assertSame( self::siteIdOf( $this->ada ), self::headerOf( (array) $asks[0]['args'], self::PORTAL_HEADER ) );
		$text = self::text( $sent['html'] );
		$this->assertMatchesRegularExpression( '/We sent a link to/i', $text );
		$this->assertStringContainsString( '@example.com', $text );
		$this->assertStringContainsString( '24 hours', $text );
		$this->assertStringNotContainsString( self::ADA_EMAIL, $text, 'the address is partly hidden' );
	}

	/**
	 * What it checks: After the customer opens the mailed link, the next view of the message centre knows it and the prompt is gone.
	 * Passes when: Ada sees the prompt; OmnaBase then counts her as confirmed (she clicked the link in her mail); her next view asks OmnaBase
	 *   again and shows no prompt.
	 * Fails when: the plugin remembers "unconfirmed" for ever and the customer keeps seeing the prompt after doing what it asked.
	 */
	public function test_after_the_link_is_opened_the_next_view_has_no_prompt(): void {
		$this->assertTrue( self::hasPrompt( $this->view( $this->ada ) ) );
		$this->linkOpened( $this->ada );
		$this->assertFalse( self::hasPrompt( $this->view( $this->ada ) ), 'the prompt leaves once OmnaBase says confirmed' );
	}

	/**
	 * What it checks: A confirmed customer whose address and name did not change costs no extra call per view (the brief's fingerprint).
	 * Passes when: after a first view of a confirmed Ada (which tells OmnaBase), a second view sends no `PUT /customers/by-external/…`, and
	 *   still asks for the list.
	 * Fails when: every page view of every customer costs an extra API call, using up the site's allowance on a busy shop.
	 */
	public function test_a_confirmed_unchanged_customer_is_not_sent_again(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->view( $this->ada );
		$this->assertNotEmpty( $this->syncCalls( $this->ada ), 'the first view tells OmnaBase' );
		$this->calls = array();
		$this->view( $this->ada );
		$this->assertCount( 0, $this->syncCalls( $this->ada ), 'no second PUT while nothing changed' );
		$this->assertNotEmpty( $this->portalCalls( 'GET', '/portal/tickets' ), 'the list is still asked' );
	}

	/**
	 * What it checks: The site itself limits how often one customer can ask for the link, and OmnaBase's own limit reads in plain words.
	 * Passes when: three requests in a row reach OmnaBase, the fourth does not and the page says so in a sentence; when OmnaBase answers
	 *   429 the customer reads a sentence without "rate", "429" or OmnaBase's own words.
	 * Fails when: a script or an impatient customer fills someone's inbox through the site, or sees a technical error.
	 */
	public function test_requests_for_the_link_are_limited_and_refusals_are_plain(): void {
		$form = self::formIn( $this->view( $this->ada ), self::promptForm() );
		$this->assertNotNull( $form, 'the prompt\'s form' );
		$this->calls = array();
		for ( $i = 1; $i <= 4; $i++ ) {
			$location = $this->postTo( $this->ada, $form['fields'], html_entity_decode( $form['action'] ) ?: (string) get_permalink( $this->picked ) );
		}
		$this->assertCount( 3, $this->portalCalls( 'POST', '/portal/confirm' ), 'the fourth request a day never leaves the site' );
		$this->assertNotNull( $location, 'the fourth post redirects back too' );
		$this->assertNotSame( '', self::text( $this->follow( $this->ada, (string) $location ) ), 'and the page says something' );

		$this->refusals['confirm'] = self::refusal( 429, 'rate_limited', 'Too many requests', array( 'retry-after' => '3600' ) );
		$sent = $this->send( $this->ben, $this->view( $this->ben ), self::promptForm() );
		$this->assertCount( 1, array_filter( $this->portalCalls( 'POST', '/portal/confirm' ), fn ( $c ) => self::headerOf( (array) $c['args'], self::PORTAL_HEADER ) === self::siteIdOf( $this->ben ) ) );
		$text = self::text( $sent['html'] );
		$this->assertDoesNotMatchRegularExpression( '/We sent a link/i', $text, 'never claims a link went out' );
		$this->assertDoesNotMatchRegularExpression( '/rate.?limit|429|Too many requests/i', $text );
	}

	/**
	 * What it checks: The prompt's request is a form post protected against forgery.
	 * Passes when: the same post without the form's nonce (or by a logged-out visitor) sends nothing to OmnaBase.
	 * Fails when: another site can make a signed-in customer's browser send confirmation mails by a hidden form.
	 */
	public function test_the_prompt_needs_the_forms_nonce(): void {
		$form = self::formIn( $this->view( $this->ada ), self::promptForm() );
		$this->assertNotNull( $form, 'the prompt\'s form' );
		$fields = array_filter( $form['fields'], static fn ( $v, $k ) => ! preg_match( '/nonce/i', (string) $k ) && strlen( (string) $v ) !== 10, ARRAY_FILTER_USE_BOTH );
		$this->assertLessThan( count( $form['fields'] ), count( $fields ), 'the form carries a nonce' );
		$this->calls = array();
		$this->postTo( $this->ada, $fields );
		$this->postTo( 0, $form['fields'] );
		$this->assertCount( 0, $this->portalCalls( 'POST', '/portal/confirm' ) );
	}
}
