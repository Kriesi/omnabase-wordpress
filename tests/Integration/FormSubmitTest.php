<?php
/**
 * M13 round four, the red team's failing tests: sending the shortcode form (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 8: "the token's id becomes the submission's `Idempotency-Key` (a
 * double click is one ticket; a cached page never shares one). The plugin's `POST /wp-json/omnabase/v1/form` checks a honeypot (filled:
 * "sent" is shown, nothing goes), the token (unused, older than 3 seconds, younger than 2 hours), 5 submissions an hour per network address
 * (`REMOTE_ADDR`; a forwarded address only through a filter the admin sets for a proxy like Cloudflare) and **25 an hour per site** (under
 * OmnaBase's 30 per key and project), then calls `POST /forms/{slug}/submissions` directly (a visitor waits for the answer; only a network
 * failure falls back to the outbox with "Thanks, your message is on its way") … Success: "Thanks. Your reference is #1234; we answer by
 * email." Errors in plain words (a field beside the field; "This form is not available right now"; "Please try again in an hour"; "Your
 * message could not be sent" with the support address); the plan never named"; round four's *Red team*: "honeypot filled → nothing sent; no
 * token, a used one, a 1-second-old or a 3-hour-old one refused; two visitors behind one cached page get two tickets; a double submit is one;
 * the sixth an hour from one address 429; the 26th an hour on the site refused before OmnaBase is asked; … each API error in its words, the
 * plan never named"; `docs/public-api.md` "Forms" (the body OmnaBase takes is strict: `name, email, subject, message, product?, licence?`).
 *
 * Shapes the red team pins: `RoundFourTestCase`. Choices where the brief and the coordinator differ (see the report): the brief's numbers win
 * (5 an hour per address and 25 an hour per site; a token valid from 3 seconds to 2 hours); "This form is not available right now" for
 * OmnaBase's 403 and 404 (the coordinator wrote "We can't take messages right now"); (chosen, a reading of "a network failure") an answer of
 * 5xx from OmnaBase is treated like no answer: the outbox carries the message; a 429 from OmnaBase is "Please try again in an hour", never
 * queued. (chosen) A token is bound to its project; a token reused for the same fields answers the first submission's reference again, a
 * token reused for other fields is refused; once the outbox sent a message, its text is gone from the site's database.
 *
 * Why these fail today: the form route does not exist (WordPress answers 404 `rest_no_route`), and nothing calls OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class FormSubmitTest extends RoundFourTestCase {

	/**
	 * What it checks: A visitor's message reaches OmnaBase at once with exactly the fields OmnaBase takes, and the visitor reads their
	 *   reference.
	 * Passes when: one submission call goes to the project's address with the site's key, a non-empty Idempotency-Key of at most 200
	 *   characters and a body of only name, email, subject, message, product and licence (no token, honeypot or project), holding the visitor's
	 *   words; the visitor reads "Thanks. Your reference is #2001; we answer by email."
	 * Fails when: messages never arrive, OmnaBase refuses the body for an extra field, or the visitor is left without a reference.
	 */
	public function test_a_message_reaches_omnabase_and_the_visitor_reads_the_reference(): void {
		$r = $this->sendFrom( self::VISITOR_IP, array( 'product' => 'Enfold Pro', 'licence' => 'ENF-1111-2222-9Q7Z' ) );

		$this->assertTrue( self::ok( $r ), $r->get_status() . ' ' . wp_json_encode( $r->get_data() ) );
		$calls = $this->submissionCalls();
		$this->assertCount( 1, $calls, 'one submission call' );
		$this->assertSame( 'Bearer ' . self::KEY, self::headerOf( $calls[0]['args'], 'Authorization' ), 'sent with the site\'s key' );
		$key = self::headerOf( $calls[0]['args'], 'Idempotency-Key' );
		$this->assertNotSame( '', $key, 'an Idempotency-Key' );
		$this->assertLessThanOrEqual( 200, strlen( $key ) );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( array(), array_values( array_diff( array_keys( $body ), array( 'name', 'email', 'subject', 'message', 'product', 'licence' ) ) ), 'only the fields OmnaBase takes' );
		$this->assertSame( array( 'Vera Visitor', 'vera@example.org', 'The slider stopped', 'Since the update the slider on my front page is gone.', 'Enfold Pro', 'ENF-1111-2222-9Q7Z' ), array( $body['name'] ?? null, $body['email'] ?? null, $body['subject'] ?? null, $body['message'] ?? null, $body['product'] ?? null, $body['licence'] ?? null ) );
		$said = self::said( $r );
		$this->assertStringContainsString( 'Thanks.', $said );
		$this->assertStringContainsString( 'Your reference is #2001', $said );
		$this->assertStringContainsString( 'we answer by email', $said );
		$this->assertSame( array(), $this->formRows(), 'nothing left in the outbox' );
	}

	/**
	 * What it checks: Empty optional fields are left out, since OmnaBase refuses an empty licence or product.
	 * Passes when: a submission with the product and licence fields empty sends a body without product and licence.
	 * Fails when: every visitor who leaves the licence empty gets "could not be sent".
	 */
	public function test_empty_optional_fields_are_left_out(): void {
		$this->sendFrom( self::VISITOR_IP, array( 'product' => '', 'licence' => '' ) );
		$calls = $this->submissionCalls();
		$this->assertCount( 1, $calls );
		$this->assertArrayNotHasKey( 'product', self::bodyOf( $calls[0] ) );
		$this->assertArrayNotHasKey( 'licence', self::bodyOf( $calls[0] ) );
	}

	/**
	 * What it checks: The plugin checks the fields itself and names the field that is wrong, before asking OmnaBase.
	 * Passes when: an empty name, an address that is not one, an empty subject, a 201-character subject, an empty message and a message of
	 *   20 001 characters each answer 400 with an error under that field's name, and none reaches OmnaBase.
	 * Fails when: visitors get one vague error for a typo in their address, or OmnaBase is called with what it must refuse.
	 */
	public function test_wrong_fields_are_named_and_never_sent(): void {
		$cases = array(
			'name'    => array( 'name' => '' ),
			'email'   => array( 'email' => 'not-an-address' ),
			'subject' => array( 'subject' => '' ),
			'message' => array( 'message' => '' ),
		);
		$long  = array(
			'subject' => array( 'subject' => str_repeat( 's', 201 ) ),
			'message' => array( 'message' => str_repeat( 'm', 20001 ) ),
		);
		$n = 0;
		foreach ( array( $cases, $long ) as $set ) {
			foreach ( $set as $field => $wrong ) {
				$r = $this->sendFrom( '198.51.100.1' . ( ++$n ), $wrong ); // an address each, so no limit answers first
				$this->assertSame( 400, $r->get_status(), $field . ': ' . wp_json_encode( $r->get_data() ) );
				$this->assertNotSame( '', self::fieldError( $r->get_data(), $field ), "an error beside the {$field} field" );
			}
		}
		$this->assertSame( array(), $this->submissionCalls(), 'none reached OmnaBase' );
	}

	/**
	 * What it checks: A bot that fills the trap field is told "sent" and nothing goes anywhere.
	 * Passes when: a submission with the trap field filled answers success with a sentence starting "Thanks", makes no call to OmnaBase and
	 *   leaves no outbox row.
	 * Fails when: spam bots file tickets, or learn from an error that they were caught.
	 */
	public function test_a_filled_trap_field_is_told_sent_and_nothing_goes(): void {
		$r = $this->sendFrom( self::VISITOR_IP, array( self::HONEYPOT => 'http://cheap-pills.example' ) );
		$this->assertTrue( self::ok( $r ), (string) wp_json_encode( $r->get_data() ) );
		$this->assertStringStartsWith( 'Thanks', self::said( $r ) );
		$this->assertSame( array(), $this->submissionCalls() );
		$this->assertSame( array(), $this->formRows() );
	}

	/**
	 * What it checks: A submission needs a token the site handed out, unused, at least 3 seconds and at most 2 hours old, for the same project.
	 * Passes when: no token, a made-up token, a token used once already for other words, a token 1 second old, one 3 hours old and one handed out
	 *   for another project are each refused (no success answer, no reference) and none reaches OmnaBase beyond the one first use.
	 * Fails when: bots post straight to the address without loading the page, replay one captured token, or submit faster than any person types.
	 */
	public function test_only_a_fresh_unused_token_of_the_project_is_taken(): void {
		$this->connectedWith( array( array( 'id' => self::PROJECT, 'slug' => 'enfold', 'name' => 'Enfold' ), array( 'id' => 'project-omnalingo', 'slug' => 'omnalingo', 'name' => 'Omnalingo' ) ) );
		$this->formSettings( self::SETTINGS, 'omnalingo' );
		$refused = array();

		// an address each (the token fetched from it too), so no per-address limit answers first
		$at     = function ( string $ip ): string {
			$_SERVER['REMOTE_ADDR'] = $ip;
			return $ip;
		};
		$fields = self::fields( 'x' );
		unset( $fields['token'] );
		$refused['no token']   = $this->submit( $fields, $at( '198.51.100.101' ) );
		$refused['made up']    = $this->submit( self::fields( 'made-up-token-' . str_repeat( 'a', 32 ) ), $at( '198.51.100.102' ) );
		$at( '198.51.100.103' );
		$used = $this->token();
		$this->assertTrue( self::ok( $this->submit( self::fields( $used ), '198.51.100.103' ) ), 'the first use is taken' );
		$refused['used again'] = $this->submit( self::fields( $used, array( 'message' => 'Another message with the same token.' ) ), $at( '198.51.100.104' ) );
		$at( '198.51.100.105' );
		$refused['1 second'] = $this->submit( self::fields( $this->token( 'enfold', false ) ), '198.51.100.105' );
		$at( '198.51.100.106' );
		$old = $this->token( 'enfold', false );
		$this->ageFormState( 3 * HOUR_IN_SECONDS );
		$refused['3 hours'] = $this->submit( self::fields( $old ), '198.51.100.106' );
		$at( '198.51.100.107' );
		$refused['other project'] = $this->submit( self::fields( $this->token( 'enfold' ), array( 'project' => 'omnalingo' ) ), '198.51.100.107' );

		foreach ( $refused as $case => $r ) {
			$this->assertFalse( self::ok( $r ), "{$case}: refused (" . $r->get_status() . ')' );
			$this->assertStringNotContainsString( 'reference', strtolower( (string) wp_json_encode( $r->get_data() ) ), "{$case}: no reference" );
		}
		$this->assertCount( 1, $this->submissionCalls(), 'only the first use reached OmnaBase' );
		$this->assertSame( array(), $this->submissionCalls( 'omnalingo' ) );
	}

	/**
	 * What it checks: A double click is one ticket.
	 * Passes when: the same fields sent twice with the same token both answer success with "#2001"; every call that reached OmnaBase carried
	 *   the same Idempotency-Key, so OmnaBase filed one ticket.
	 * Fails when: an impatient visitor's second click files the question twice, or tells them it failed.
	 */
	public function test_a_double_click_is_one_ticket(): void {
		$fields = self::fields( $this->token() );
		$a      = $this->submit( $fields );
		$b      = $this->submit( $fields );
		$this->assertTrue( self::ok( $a ) && self::ok( $b ), $a->get_status() . '/' . $b->get_status() );
		$this->assertStringContainsString( '#2001', self::said( $a ) );
		$this->assertStringContainsString( '#2001', self::said( $b ) );
		$keys = array_unique( array_map( static fn ( $c ) => self::headerOf( $c['args'], 'Idempotency-Key' ), $this->submissionCalls() ) );
		$this->assertCount( 1, $keys, 'one Idempotency-Key for both' );
		$this->assertSame( array( 2001 ), array_values( array_unique( $this->replays ) ), 'one ticket at OmnaBase' );
	}

	/**
	 * What it checks: Two visitors behind one cached page each get their own ticket.
	 * Passes when: two visitors each fetch a token on the same page and send different questions; two calls reach OmnaBase with two different
	 *   Idempotency-Keys and the visitors read #2001 and #2002.
	 * Fails when: the second visitor's question is merged into the first's ticket or refused as a repeat.
	 */
	public function test_two_visitors_behind_one_cached_page_get_two_tickets(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.31';
		$first                  = $this->token( 'enfold', false );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.32';
		$second                 = $this->token();
		$a                      = $this->submit( self::fields( $first, array( 'email' => 'anna@example.org', 'subject' => 'Anna asks' ) ), '198.51.100.31' );
		$b                      = $this->submit( self::fields( $second, array( 'email' => 'bert@example.org', 'subject' => 'Bert asks' ) ), '198.51.100.32' );
		$calls                  = $this->submissionCalls();
		$this->assertCount( 2, $calls );
		$this->assertNotSame( self::headerOf( $calls[0]['args'], 'Idempotency-Key' ), self::headerOf( $calls[1]['args'], 'Idempotency-Key' ) );
		$this->assertStringContainsString( '#2001', self::said( $a ) );
		$this->assertStringContainsString( '#2002', self::said( $b ) );
	}

	/**
	 * What it checks: One network address sends at most 5 messages an hour; others go on.
	 * Passes when: five submissions from one address succeed, the sixth answers 429 with "Please try again in an hour" and never reaches
	 *   OmnaBase, and a submission from another address right after succeeds.
	 * Fails when: one bot fills the inbox through the site's form, or one person's retries block the form for everybody.
	 */
	public function test_the_sixth_message_an_hour_from_one_address_waits(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( self::ok( $this->sendFrom( '198.51.100.40', array( 'email' => "five{$i}@example.org" ) ) ), "submission {$i}" );
		}
		$sixth = $this->sendFrom( '198.51.100.40', array( 'email' => 'six@example.org' ) );
		$this->assertSame( 429, $sixth->get_status() );
		$this->assertStringContainsString( 'Please try again in an hour', self::said( $sixth ) );
		$this->assertCount( 5, $this->submissionCalls() );
		$this->assertTrue( self::ok( $this->sendFrom( '198.51.100.41', array( 'email' => 'other@example.org' ) ) ), 'another address goes on' );
	}

	/**
	 * What it checks: A forwarded address (the X-Forwarded-For header anyone can write) counts only when the site owner says so through the
	 *   filter for a proxy like Cloudflare.
	 * Passes when: from one connection address, five submissions with five different forwarded addresses succeed and the sixth is refused
	 *   (429); once the filter hands the forwarded address over, a submission naming a new forwarded address succeeds.
	 * Fails when: a bot escapes the limit by writing a new header each time, or a site behind Cloudflare shares 5 messages an hour among all
	 *   its visitors with no way out.
	 */
	public function test_a_forwarded_address_counts_only_through_the_filter(): void {
		$send = function ( string $forwarded, string $email ): \WP_REST_Response {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = $forwarded;
			$_SERVER['REMOTE_ADDR']          = '198.51.100.50';
			return $this->submit( self::fields( $this->token(), array( 'email' => $email ) ), '198.51.100.50', array( 'x-forwarded-for' => $forwarded ) );
		};
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( self::ok( $send( "192.0.2.{$i}", "proxy{$i}@example.org" ) ), "submission {$i}" );
		}
		$this->assertSame( 429, $send( '192.0.2.6', 'proxy6@example.org' )->get_status(), 'a new header alone does not lift the limit' );

		add_filter( 'omnabase_visitor_address', static fn () => trim( explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) )[0] ) );
		$this->assertTrue( self::ok( $send( '192.0.2.99', 'proxy99@example.org' ) ), 'with the filter the visitor\'s own address counts' );
	}

	/**
	 * What it checks: The whole site sends at most 25 messages an hour, under OmnaBase's 30, so OmnaBase never has to refuse the site.
	 * Passes when: 25 submissions from five addresses succeed, and the 26th from a sixth address is refused with "Please try again in an hour"
	 *   without OmnaBase being asked (25 calls in all).
	 * Fails when: a spread-out bot attack makes OmnaBase refuse the site's key, and real visitors' messages fail with it.
	 */
	public function test_the_26th_message_an_hour_on_the_site_is_refused_before_omnabase_is_asked(): void {
		for ( $ip = 1; $ip <= 5; $ip++ ) {
			for ( $i = 1; $i <= 5; $i++ ) {
				$this->assertTrue( self::ok( $this->sendFrom( "198.51.100.6{$ip}", array( 'email' => "site{$ip}x{$i}@example.org" ) ) ), "address {$ip}, submission {$i}" );
			}
		}
		$last = $this->sendFrom( '198.51.100.70', array( 'email' => 'site26@example.org' ) );
		$this->assertFalse( self::ok( $last ) );
		$this->assertStringContainsString( 'Please try again in an hour', self::said( $last ) );
		$this->assertCount( 25, $this->submissionCalls() );
	}

	/**
	 * What it checks: OmnaBase's refusals reach the visitor in plain words that never name the plan, the workspace or OmnaBase's own text,
	 *   and none is queued.
	 * Passes when: OmnaBase's 404 (no such form), 403 plan_limit and 403 suspended each give "This form is not available right now"; a 429
	 *   gives "Please try again in an hour"; a 400 gives "Your message could not be sent" with an email address to write to; none of the
	 *   sentences holds "plan", "suspend", "workspace" or OmnaBase's message; none is a success and none leaves an outbox row.
	 * Fails when: a visitor reads about the site owner's subscription, gets OmnaBase's technical wording, or a refused message is retried for
	 *   ever.
	 */
	public function test_omnabases_refusals_in_plain_words(): void {
		$cases = array(
			'404'        => array( self::refusal( 404, 'not_found', 'No such form' ), 'This form is not available right now' ),
			'plan_limit' => array( self::refusal( 403, 'plan_limit', 'This workspace reached its plan\'s monthly ticket limit' ), 'This form is not available right now' ),
			'suspended'  => array( self::refusal( 403, 'forbidden', 'This workspace is suspended: spam' ), 'This form is not available right now' ),
			'429'        => array( self::refusal( 429, 'rate_limited', 'Too many submissions', array( 'retry-after' => '3600' ) ), 'Please try again in an hour' ),
			'400'        => array( self::refusal( 400, 'bad_request', 'Pick a product from the list' ), 'Your message could not be sent' ),
		);
		$i = 0;
		foreach ( $cases as $case => [ $answer, $words ] ) {
			$this->omnabaseAnswersSubmissions( $answer );
			$r    = $this->sendFrom( '198.51.100.8' . ( ++$i ), array( 'email' => "refused{$i}@example.org" ) );
			$said = self::said( $r );
			$this->assertFalse( self::ok( $r ), "{$case}: not a success" );
			$this->assertStringContainsString( $words, $said, $case );
			foreach ( array( 'plan', 'suspend', 'workspace', json_decode( (string) $answer['body'], true )['error']['message'] ) as $never ) {
				$this->assertStringNotContainsStringIgnoringCase( $never, $said, "{$case}: never \"{$never}\"" );
			}
			if ( '400' === $case ) {
				$this->assertMatchesRegularExpression( '/[^\s@]+@[^\s@]+\.[a-z]{2,}/i', $said, 'an address to write to' );
			}
		}
		$this->assertSame( array(), $this->formRows(), 'nothing queued' );
	}

	/**
	 * What it checks: When OmnaBase cannot be reached, the message is kept and sent later, once, with the same key; afterwards the visitor's
	 *   words are not left on the site.
	 * Passes when: with the network failing, the visitor reads "Thanks, your message is on its way" after one immediate try, and one waiting
	 *   outbox row about a form exists; once OmnaBase answers again and the outbox runs, a second call goes with the same Idempotency-Key and
	 *   the same name, address, subject and message, the row is sent, and the message's text is found nowhere in the site's options or the
	 *   outbox table.
	 * Fails when: a question sent during an outage is lost, filed twice, or kept on the site for ever after it reached OmnaBase.
	 */
	public function test_a_network_failure_is_carried_by_the_outbox(): void {
		$words = 'Outage message ' . wp_generate_password( 12, false );
		$this->omnabaseAnswersSubmissions( new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to app.omnabase.com' ) );
		$r = $this->sendFrom( self::VISITOR_IP, array( 'message' => $words ) );

		$this->assertTrue( self::ok( $r ), (string) wp_json_encode( $r->get_data() ) );
		$this->assertStringContainsString( 'on its way', self::said( $r ) );
		$first = $this->submissionCalls();
		$this->assertCount( 1, $first, 'one immediate try' );
		$rows = $this->formRows();
		$this->assertCount( 1, $rows, 'one outbox row about a form' );
		$this->assertSame( 'waiting', $rows[0]['state'] );

		$this->omnabaseTakesSubmissions();
		$this->makeDue();
		$this->runOutbox();

		$calls = $this->submissionCalls();
		$this->assertCount( 2, $calls, 'sent once more by the outbox' );
		$this->assertSame( self::headerOf( $first[0]['args'], 'Idempotency-Key' ), self::headerOf( $calls[1]['args'], 'Idempotency-Key' ), 'the same key' );
		$this->assertSame( array_intersect_key( self::bodyOf( $first[0] ), array_flip( array( 'name', 'email', 'subject', 'message' ) ) ), array_intersect_key( self::bodyOf( $calls[1] ), array_flip( array( 'name', 'email', 'subject', 'message' ) ) ) );
		$this->assertSame( $words, self::bodyOf( $calls[1] )['message'] ?? null );
		$this->assertSame( 'sent', $this->formRows()[0]['state'] ?? null );
		$this->assertStringNotContainsString( $words, $this->databaseText(), 'the words are gone from the site once sent' );
	}

	/**
	 * What it checks: An OmnaBase that answers with a server error (a 5xx, as its proxy does while it restarts) is treated like one that cannot
	 *   be reached.
	 * Passes when: with OmnaBase answering 503, the visitor reads "on its way" and one waiting outbox row about a form exists.
	 * Fails when: every message sent during a deploy fails with "could not be sent" although OmnaBase is back a minute later.
	 */
	public function test_a_server_error_is_carried_by_the_outbox_too(): void {
		$this->omnabaseAnswersSubmissions( self::answer( 503, '<html>Service Unavailable</html>' ) );
		$r = $this->sendFrom( self::VISITOR_IP );
		$this->assertTrue( self::ok( $r ), (string) wp_json_encode( $r->get_data() ) );
		$this->assertStringContainsString( 'on its way', self::said( $r ) );
		$this->assertCount( 1, $this->formRows() );
	}

	/**
	 * What it checks: Everything the form keeps on the site (tokens, counters, cached settings, waiting messages) is under a name the plugin's
	 *   uninstall removes.
	 * Passes when: after a view, a token, a submission and an outage, every new stored setting or temporary value whose name holds "omnabase" is
	 *   either on the plugin's list of options or matched by a name uninstall.php deletes.
	 * Fails when: a site owner deletes the plugin and visitors' addresses or tokens stay in the database for ever.
	 */
	public function test_what_the_form_keeps_is_removed_on_uninstall(): void {
		global $wpdb;
		$names  = static fn () => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%omnabase%'" ); // phpcs:ignore WordPress.DB
		$before = $names();
		$this->render( 'project="enfold"' );
		$this->sendFrom( self::VISITOR_IP );
		$this->omnabaseAnswersSubmissions( new \WP_Error( 'http_request_failed', 'down' ) );
		$this->sendFrom( '198.51.100.90', array( 'email' => 'later@example.org' ) );
		$new = array_diff( $names(), $before );
		$this->assertNotEmpty( $new, 'the form keeps something (at least its tokens)' );

		preg_match_all( "/'([a-z0-9_]*omnabase[a-z0-9_]*)'/", (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' ), $m ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$removed = array_unique( $m[1] );
		foreach ( $new as $name ) {
			$bare    = (string) preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $name );
			$covered = in_array( $bare, \OmnaBase\WordPress\Options::ALL, true ) || in_array( $bare, $removed, true );
			foreach ( $removed as $prefix ) {
				$covered = $covered || ( str_ends_with( $prefix, '_' ) && str_starts_with( $bare, $prefix ) );
			}
			$this->assertTrue( $covered, "uninstall.php removes {$name}" );
		}
	}

	/** Every option's value and every outbox row, as one text. */
	private function databaseText(): string {
		global $wpdb;
		$options = (array) $wpdb->get_col( "SELECT option_value FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB
		return implode( "\n", $options ) . "\n" . (string) wp_json_encode( $this->outboxRows() );
	}
}
