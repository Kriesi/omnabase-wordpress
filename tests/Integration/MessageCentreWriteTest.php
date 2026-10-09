<?php
/**
 * M13 round five, the red team's failing tests: New ticket and Reply in the message centre (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 9: "'New ticket' and reply as form posts with `wp_nonce_field`,
 * handled at `template_redirect` and redirected back. The identity is always the current user's site id in `OmnaBase-Customer`, **never from
 * the address, a form field or a cookie of ours**"; `docs/public-api.md` "The message centre": `POST /portal/tickets { project?, subject,
 * message }`, `POST /portal/tickets/{number}/messages { body }` ("it brings a waiting ticket back, as a mail does"); round four's form ("Errors
 * in plain words …; the plan never named"). The coordinator: "New ticket (subject, message …; through the portal's create route; the honeypot
 * and the token as the form has), Reply (through the portal's reply route; the ticket reopens)"; "the refusals in plain words; never a plan's
 * name".
 *
 * Shapes the red team pins: `RoundFiveTestCase`. (chosen) The token a form carries makes a resent form one ticket: the same rendered form posted
 * twice sends one `Idempotency-Key` (or only once); two separately rendered forms are two questions. (chosen) The honeypot `omnabase_hp` of
 * round four is on the New ticket form too; filled, nothing is sent. The product select of the coordinator's line is not pinned: the portal's
 * create route takes no product (see the report).
 *
 * Why these fail today: no message centre exists, so there is no form to post and nothing handles a post.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class MessageCentreWriteTest extends RoundFiveTestCase {

	/**
	 * What it checks: A customer's new ticket goes through OmnaBase's message-centre route as them, and they land back on their message centre
	 *   seeing it.
	 * Passes when: sending the New ticket form makes one `POST /portal/tickets` naming Ada by her site id, with an Idempotency-Key and a body of
	 *   only the subject, the message and (at most) the site's one project; the browser is sent back to the message centre, whose page then
	 *   shows the subject.
	 * Fails when: questions from the message centre never arrive, arrive under someone else, carry an address or name a visitor could change, or
	 *   leave the customer on a blank page.
	 */
	public function test_a_new_ticket_goes_through_the_portal_as_the_customer(): void {
		$this->omnabaseKnows( $this->ada, true );
		$html = $this->view( $this->ada );
		$this->calls = array();
		$sent = $this->send( $this->ada, $html, self::newTicketForm(), array( 'subject' => 'Licence key rejected', 'textarea' => 'The key from my order is refused.' ) );
		$this->assertNotNull( $sent['location'], 'redirected back after the post' );
		$creates = $this->portalCalls( 'POST', '/portal/tickets' );
		$this->assertCount( 1, $creates );
		$this->assertSame( self::siteIdOf( $this->ada ), self::headerOf( (array) $creates[0]['args'], self::PORTAL_HEADER ) );
		$this->assertNotSame( '', self::headerOf( (array) $creates[0]['args'], 'Idempotency-Key' ), 'a resend counts once' );
		$body = self::bodyOf( $creates[0] );
		$this->assertSame( 'Licence key rejected', $body['subject'] ?? null );
		$this->assertSame( 'The key from my order is refused.', $body['message'] ?? null );
		$this->assertSame( array(), array_diff( array_keys( $body ), array( 'subject', 'message', 'project' ) ), 'nothing else in the body' );
		if ( isset( $body['project'] ) ) {
			$this->assertSame( self::PROJECT, $body['project'], 'the site\'s one project' );
		}
		$this->assertStringContainsString( 'Licence key rejected', $sent['html'] );
	}

	/**
	 * What it checks: Sending the same form twice (a double click, the browser's resend) files one ticket; two separate questions file two.
	 * Passes when: the same rendered form posted twice reaches OmnaBase with one Idempotency-Key (or once), so OmnaBase holds one ticket; a
	 *   freshly rendered form posted afterwards uses a different key and makes a second ticket.
	 * Fails when: an impatient double click files the question twice, or a customer's second question is swallowed as a repeat of the first.
	 */
	public function test_the_same_form_twice_is_one_ticket_two_forms_are_two(): void {
		$this->omnabaseKnows( $this->ada, true );
		$form = self::formIn( $this->view( $this->ada ), self::newTicketForm() );
		$this->assertNotNull( $form, 'the New ticket form' );
		$area = (string) self::nodes( $this->view( $this->ada ), '//form[' . self::newTicketForm() . ']//textarea' )->item( 0 )->getAttribute( 'name' );
		$fields = array_merge( $form['fields'], array( 'subject' => 'Double click', $area => 'Sent twice by accident.' ) );
		$this->calls = array();
		$this->postTo( $this->ada, $fields );
		$this->postTo( $this->ada, $fields );
		$keys = array_values( array_unique( array_map( static fn ( $c ) => self::headerOf( (array) $c['args'], 'Idempotency-Key' ), $this->portalCalls( 'POST', '/portal/tickets' ) ) ) );
		$this->assertCount( 1, $keys, 'one key for one form' );
		$this->assertCount( 1, $this->tickets[ self::siteIdOf( $this->ada ) ] ?? array(), 'one ticket' );

		$second = self::formIn( $this->view( $this->ada ), self::newTicketForm() );
		$this->postTo( $this->ada, array_merge( $second['fields'], array( 'subject' => 'Another question', $area => 'A different one.' ) ) );
		$this->assertCount( 2, $this->tickets[ self::siteIdOf( $this->ada ) ] ?? array(), 'a second form is a second ticket' );
	}

	/**
	 * What it checks: The New ticket form carries round four's trap field, and a filled trap sends nothing.
	 * Passes when: the form has the `omnabase_hp` field; posting it filled sends no `POST /portal/tickets`.
	 * Fails when: a bot riding a signed-in session (or a browser extension) files tickets through the message centre.
	 */
	public function test_a_filled_trap_field_sends_nothing(): void {
		$this->omnabaseKnows( $this->ada, true );
		$html = $this->view( $this->ada );
		$form = self::formIn( $html, self::newTicketForm() );
		$this->assertArrayHasKey( self::HONEYPOT, (array) ( $form['fields'] ?? array() ), 'the trap field' );
		$this->calls = array();
		$this->send( $this->ada, $html, self::newTicketForm(), array( 'subject' => 'Buy pills', 'textarea' => 'Cheap.', self::HONEYPOT => 'http://spam.example' ) );
		$this->assertCount( 0, $this->portalCalls( 'POST', '/portal/tickets' ) );
	}

	/**
	 * What it checks: A new ticket is a form post protected against forgery, and only for the signed-in customer.
	 * Passes when: the New ticket post without its nonce, and the same post with the nonce by a logged-out visitor, send nothing to OmnaBase.
	 * Fails when: another website makes a signed-in customer's browser file tickets in their name with a hidden form.
	 */
	public function test_a_new_ticket_needs_the_nonce_and_a_signed_in_customer(): void {
		$this->omnabaseKnows( $this->ada, true );
		$html = $this->view( $this->ada );
		$form = self::formIn( $html, self::newTicketForm() );
		$this->assertNotNull( $form, 'the New ticket form' );
		$area = (string) self::nodes( $html, '//form[' . self::newTicketForm() . ']//textarea' )->item( 0 )->getAttribute( 'name' );
		$fields = array_merge( $form['fields'], array( 'subject' => 'Forged', $area => 'Forged message.' ) );
		$without = array_filter( $fields, static fn ( $v, $k ) => ! preg_match( '/nonce/i', (string) $k ) && ! preg_match( '/^[a-f0-9]{10}$/', (string) $v ), ARRAY_FILTER_USE_BOTH );
		$this->assertLessThan( count( $fields ), count( $without ), 'the form carries a nonce' );
		$this->calls = array();
		$this->postTo( $this->ada, $without );
		$this->postTo( 0, $fields );
		$this->assertCount( 0, $this->portalCalls( 'POST', '/portal/tickets' ) );
	}

	/**
	 * What it checks: A reply in a thread goes through OmnaBase's reply route as the customer, brings a waiting ticket back, and the customer
	 *   lands on the thread seeing their words.
	 * Passes when: on a resolved ticket's thread, sending the reply form makes one `POST /portal/tickets/<n>/messages` with only `body`, naming
	 *   Ada by her site id; the redirect leads back to that thread, which shows the reply and an open status.
	 * Fails when: answers from the message centre vanish, go to another ticket, or the ticket stays resolved so nobody on the team sees it.
	 */
	public function test_a_reply_goes_through_the_portal_and_reopens_the_ticket(): void {
		$this->omnabaseKnows( $this->ada, true );
		$n    = $this->ticketFor( $this->ada, 'Slider stopped', array( array( 'bodyText' => 'It is gone.' ), array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'Fixed in 7.2.' ) ), array( 'status' => 'resolved' ) );
		$html = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$this->calls = array();
		$sent = $this->send( $this->ada, $html, self::replyForm(), array( 'textarea' => 'Still broken on 7.2, sorry.' ) );
		$replies = $this->portalCalls( 'POST', '/portal/tickets/' . $n . '/messages' );
		$this->assertCount( 1, $replies );
		$this->assertSame( array( 'body' => 'Still broken on 7.2, sorry.' ), self::bodyOf( $replies[0] ) );
		$this->assertSame( self::siteIdOf( $this->ada ), self::headerOf( (array) $replies[0]['args'], self::PORTAL_HEADER ) );
		parse_str( (string) wp_parse_url( (string) $sent['location'], PHP_URL_QUERY ), $q );
		$this->assertSame( (string) $n, (string) ( $q[ self::TICKET_QUERY ] ?? '' ), 'back on the thread' );
		$this->assertStringContainsString( 'Still broken on 7.2, sorry.', $sent['html'] );
		$this->assertMatchesRegularExpression( '/\bopen\b/i', self::text( $sent['html'] ), 'the ticket is open again' );
	}

	/**
	 * What it checks: Changing the ticket number in a reply form cannot write into someone else's conversation.
	 * Passes when: Ada's reply form with every mention of her ticket number changed to Ben's leaves Ben's ticket untouched, the call names Ada
	 *   (OmnaBase answers 404), and the page says "not found".
	 * Fails when: a customer posts into another customer's support conversation by editing a hidden field.
	 */
	public function test_a_reply_to_another_customers_ticket_number_writes_nothing(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->omnabaseKnows( $this->ben, true );
		$mine = $this->ticketFor( $this->ada, 'Ada question', array( array( 'bodyText' => 'Mine.' ) ) );
		$bens = $this->ticketFor( $this->ben, 'Ben question', array( array( 'bodyText' => 'His.' ) ) );
		$html = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $mine ) );
		$form = self::formIn( $html, self::replyForm() );
		$this->assertNotNull( $form, 'the reply form' );
		$area   = (string) self::nodes( $html, '//form[' . self::replyForm() . ']//textarea' )->item( 0 )->getAttribute( 'name' );
		$fields = array_map( static fn ( $v ) => (string) $mine === (string) $v ? (string) $bens : $v, $form['fields'] );
		$fields[ $area ] = 'ADA-WAS-HERE';
		$action = str_replace( self::TICKET_QUERY . '=' . $mine, self::TICKET_QUERY . '=' . $bens, html_entity_decode( $form['action'] ) ?: add_query_arg( self::TICKET_QUERY, (string) $bens, (string) get_permalink( $this->picked ) ) );
		$this->calls = array();
		$location = $this->postTo( $this->ada, $fields, $action );
		$this->assertCount( 1, $this->tickets[ self::siteIdOf( $this->ben ) ][ $bens ]['messages'], 'Ben\'s ticket is untouched' );
		$this->assertNotContains( self::siteIdOf( $this->ben ), $this->namedVisitors() );
		$this->assertNotNull( $location, 'redirected back' );
		$this->assertMatchesRegularExpression( '/not found/i', self::text( $this->follow( $this->ada, (string) $location ) ) );
	}

	/**
	 * What it checks: When OmnaBase refuses a new ticket, the customer reads a plain sentence, never the owner's plan or OmnaBase's own wording.
	 * Passes when: for OmnaBase's 429 (the day's site tickets used up), 403 `plan_limit` and 400, the page after the post holds a sentence,
	 *   claims no success, and holds none of "plan", "allowance", "workspace", "429", "403" or OmnaBase's message.
	 * Fails when: a shop's customers read about the shop owner's subscription, or a technical error, or believe a refused question was sent.
	 */
	public function test_refusals_of_a_new_ticket_read_in_plain_words(): void {
		$this->omnabaseKnows( $this->ada, true );
		$cases = array(
			'429' => self::refusal( 429, 'rate_limited', 'Today\'s allowance of tickets from connected sites is used; it starts over tomorrow', array( 'retry-after' => '3600' ) ),
			'403' => self::refusal( 403, 'plan_limit', 'The workspace reached its plan\'s monthly tickets (Starter plan)' ),
			'400' => self::refusal( 400, 'bad_request', 'The request did not match its schema' ),
		);
		foreach ( $cases as $case => $refusal ) {
			$this->refusals['create'] = $refusal;
			$sent = $this->send( $this->ada, $this->view( $this->ada ), self::newTicketForm(), array( 'subject' => "Refused {$case}", 'textarea' => 'Please help.' ) );
			$this->assertNotNull( $sent['location'], "{$case}: redirected back" );
			$text = self::text( $sent['html'] );
			$this->assertNotSame( '', $text, "{$case}: the page says something" );
			$this->assertDoesNotMatchRegularExpression( '/\bplan\b|allowance|workspace|\b429\b|\b403\b|did not match|schema/i', $text, "{$case}: plain words only" );
			$this->assertDoesNotMatchRegularExpression( '/\bthanks?\b|has been (sent|created|filed)|your reference/i', $text, "{$case}: no success claimed" );
		}
	}
}
