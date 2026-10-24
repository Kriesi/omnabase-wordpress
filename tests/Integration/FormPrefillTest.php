<?php
/**
 * The plugin's forms round (2026-10-25), the red team's failing tests: the signed-in visitor's verified prefill (inside wp-env's tests
 * WordPress, the plain run; OmnaBase faked at the HTTP layer).
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`) outcome 2 ("For a logged-in visitor the plugin's server (never the browser) sends name,
 * address and the last order … so OmnaBase marks them verified … A visitor who is not logged in gets a plain form.") and "Verified prefill"
 * ("the plugin's server fetches a short-lived prefill token from OmnaBase with the site key for the logged-in user's facts (`POST
 * /api/public/v1/forms/prefill` …) and prints it on the container … The browser itself never gets the site key."). Report findings 1 to 4:
 * the token is asked by the server at render time, kept per person and form for an hour, never printed for a visitor who is not signed in, and
 * a page that carries one is kept out of page caches (question 2's default); OmnaBase being unreachable leaves a plain form.
 *
 * The words as in `FormEmbedTestCase`; *LiteSpeed's no-cache action*, the signal the LiteSpeed Cache plugin listens for to skip keeping a page;
 * *DONOTCACHEPAGE*, the constant WP Super Cache, W3 Total Cache, WP Rocket and others read for the same (the message centre already sends both).
 *
 * Why these fail today: the Classic form prints the signed-in user's name and address as plain field values, asks OmnaBase for no token,
 * prints no container and marks no page as uncacheable.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class FormPrefillTest extends FormEmbedTestCase {

	private const ADA_FORM  = 'ada.form@example.org';
	private const ADA_NAME  = 'Ada Formfill';
	private const BEN_FORM  = 'ben.form@example.org';
	private const NO_CACHE  = 'litespeed_control_set_nocache';

	/**
	 * What it checks: For a signed-in customer the site's server asks OmnaBase to vouch for their address and name, and the page carries
	 *   OmnaBase's note, never the site's key.
	 * Passes when: Ada's view of `[omnabase_form id="support"]` makes one prefill call with the site's key, for the form "support" and the
	 *   project "enfold", with her address and display name; the container carries the token OmnaBase handed for her address.
	 * Fails when: a shop's signed-in customers reach the team as unverified strangers, or the browser is handed the means to vouch for anyone.
	 */
	public function test_a_signed_in_customer_gets_a_token_for_their_own_details(): void {
		$ada = $this->customerNamed( self::ADA_FORM, self::ADA_NAME );

		$html = $this->renderAs( $ada, 'id="support"' );

		$body  = $this->thePrefillBody();
		$calls = $this->prefillCalls();
		$this->assertSame( 'Bearer ' . self::KEY, self::headerOf( $calls[0]['args'], 'Authorization' ), 'the site\'s key, server to server' );
		$this->assertSame( 'support', $body['form'] ?? null );
		$this->assertSame( 'enfold', $body['project'] ?? null );
		$this->assertSame( self::ADA_FORM, $body['values']['email'] ?? null );
		$this->assertSame( self::ADA_NAME, $body['values']['name'] ?? null );
		$this->assertSame( self::prefillTokenFor( self::ADA_FORM ), $this->theContainer( $html )->getAttribute( 'data-prefill-token' ) );
	}

	/**
	 * What it checks: A visitor who is not signed in gets a plain form, and every such visitor gets exactly the same page, so a page cache may
	 *   keep it.
	 * Passes when: two visitor views of the same shortcode make no prefill call, carry no token, and are the same HTML byte for byte.
	 * Fails when: anonymous visitors cost a call to OmnaBase each, or a cached page hands one visitor's note to the next.
	 */
	public function test_a_visitor_gets_a_plain_form_and_the_same_page_as_every_visitor(): void {
		$first  = $this->renderAs( 0, 'id="support"' );
		$second = $this->renderAs( 0, 'id="support"' );

		$this->assertSame( array(), $this->prefillCalls(), 'no prefill call for a visitor' );
		$this->assertFalse( $this->theContainer( $first )->hasAttribute( 'data-prefill-token' ), 'no token' );
		$this->assertSame( $first, $second, 'the same HTML for every visitor' );
	}

	/**
	 * What it checks: Each person gets their own note, and a person who opens the page again does not cost another call for a while.
	 * Passes when: Ada's second view makes no new call and shows her token again; Ben's view makes its own call and shows his token, never
	 *   Ada's.
	 * Fails when: one customer's details appear in another customer's form, or every page view of a signed-in customer waits for OmnaBase.
	 */
	public function test_each_person_gets_their_own_token_kept_for_a_while(): void {
		$ada = $this->customerNamed( self::ADA_FORM, self::ADA_NAME );
		$ben = $this->customerNamed( self::BEN_FORM, 'Ben Formfill' );

		$this->renderAs( $ada, 'id="support"' );
		$again = $this->renderAs( $ada, 'id="support"' );
		$this->assertCount( 1, $this->prefillCalls(), 'Ada\'s second view reuses her token' );
		$this->assertSame( self::prefillTokenFor( self::ADA_FORM ), $this->theContainer( $again )->getAttribute( 'data-prefill-token' ) );

		$bens = $this->renderAs( $ben, 'id="support"' );
		$this->assertCount( 2, $this->prefillCalls(), 'Ben gets his own' );
		$this->assertSame( self::prefillTokenFor( self::BEN_FORM ), $this->theContainer( $bens )->getAttribute( 'data-prefill-token' ) );
	}

	/**
	 * What it checks: A page that carries a signed-in customer's note is kept out of page caches; a visitor's page is not.
	 * Passes when: rendering the form for Ada sends LiteSpeed's no-cache action and leaves DONOTCACHEPAGE defined; rendering it for a visitor
	 *   sends no such action.
	 * Fails when: a caching plugin keeps Ada's page and serves her name, address and note to the next person, who can then send messages
	 *   marked as checked by the shop in her name.
	 */
	public function test_a_page_with_a_token_is_kept_out_of_page_caches(): void {
		$ada = $this->customerNamed( self::ADA_FORM, self::ADA_NAME );

		$before = did_action( self::NO_CACHE );
		$this->renderAs( 0, 'id="support"' );
		$this->assertSame( $before, did_action( self::NO_CACHE ), 'a visitor\'s page stays cacheable' );

		$this->renderAs( $ada, 'id="support"' );
		$this->assertGreaterThan( $before, did_action( self::NO_CACHE ), 'LiteSpeed is told not to keep it' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'the other caches are told too' );
	}

	/**
	 * What it checks: When OmnaBase cannot hand a note (unreachable, or refusing), the signed-in customer still gets the form, just unverified.
	 * Passes when: with the prefill call failing at the network, and again with OmnaBase answering 404, Ada's view prints the container without
	 *   a token and nothing a customer would read as an error.
	 * Fails when: a short OmnaBase outage takes the contact form away from exactly the customers who are signed in.
	 */
	public function test_without_omnabases_note_the_form_still_shows_unverified(): void {
		$ada = $this->customerNamed( self::ADA_FORM, self::ADA_NAME );
		foreach ( array( new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), self::refusal( 404, 'not_found', 'No such form' ) ) as $i => $answer ) {
			$this->omnabaseHandsPrefillTokens( $answer );
			$user = 0 === $i ? $ada : $this->customerNamed( 'ada.second.' . $i . '@example.org', self::ADA_NAME ); // a fresh person: nothing kept from the first try
			$html = $this->renderAs( $user, 'id="support"' );
			$box  = $this->theContainer( $html );
			$this->assertFalse( $box->hasAttribute( 'data-prefill-token' ), 'no token' );
			$this->assertDoesNotMatchRegularExpression( '/error|could not|curl|timed out|not found/i', wp_strip_all_tags( $html ), 'nothing a customer reads as an error' );
		}
	}

	/**
	 * What it checks: Without a shop the site vouches only for what WordPress knows: the address and the name.
	 * Passes when: in the plain WordPress (no shop loaded), Ada's prefill call sends values with exactly the keys email and name.
	 * Fails when: the plugin sends empty or made-up order and licence values that OmnaBase would then show as verified.
	 */
	public function test_without_a_shop_only_the_address_and_name_are_vouched_for(): void {
		$ada = $this->customerNamed( self::ADA_FORM, self::ADA_NAME );
		$this->renderAs( $ada, 'id="support"' );

		$values = (array) ( $this->thePrefillBody()['values'] ?? array() );
		ksort( $values );
		$this->assertSame( array( 'email', 'name' ), array_keys( $values ) );
	}
}
