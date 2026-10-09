<?php
/**
 * M13 round five, the red team's failing tests: what the message centre shows and whom it asks about (inside wp-env's tests WordPress, the
 * plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 9: "`[omnabase_messages]` on the page the admin picks"; "Each view
 * first makes sure OmnaBase knows the user: `PUT /customers/by-external/<site id> { email, name }` …"; "the list, a thread
 * (`?omnabase_ticket=<number>`, only a number; another visitor's ticket is OmnaBase's 404, shown as 'not found')"; "The identity is always the
 * current user's site id in `OmnaBase-Customer`, **never from the address, a form field or a cookie of ours**"; "**Logged out**: a login link
 * back, or the form with `guest="form"`"; choice 6 (a reused user number gets a new id); choice 10 (no stored value on any page); round five's
 * *Red team*: "visitor A cannot open B's ticket by number; a `?customer=` or header changes nothing; no note or system line in any thread; a
 * script in a message shown without it; … logged out sees the login link". The coordinator: "the list (subject, status, last activity;
 * paged)", "the identity never inherited (a reused WordPress user id → a new suffix → empty list: a test)".
 *
 * Shapes the red team pins: `RoundFiveTestCase`. (chosen) A message whose kind is not one of the portal's three (`you`, `team`, `other`) is
 * never shown, whatever OmnaBase sends: the plugin's own fence behind OmnaBase's.
 *
 * Why these fail today: no `[omnabase_messages]` shortcode is registered, so the page shows the tag as typed and nothing asks OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Sync\SiteId;

final class MessageCentreViewTest extends RoundFiveTestCase {

	/**
	 * What it checks: A visitor who is not signed in gets a way to sign in that brings them back, and nothing else.
	 * Passes when: the page shows no raw shortcode, a link to WordPress's login page whose return address is the message centre's page, no
	 *   ticket, and OmnaBase was not asked anything.
	 * Fails when: logged-out visitors see the tag "[omnabase_messages]", an empty box, someone's tickets, or land on the home page after signing
	 *   in.
	 */
	public function test_a_logged_out_visitor_sees_a_login_link_back_and_nothing_else(): void {
		$this->ticketFor( $this->ada, 'Ada private question' );
		$html = $this->view( 0 );
		$this->assertStringNotContainsString( '[omnabase_messages', $html, 'the shortcode is replaced' );
		$links = array_map( static fn ( $a ) => html_entity_decode( (string) $a->getAttribute( 'href' ) ), iterator_to_array( self::nodes( $html, "//a[contains(@href,'wp-login.php')]" ) ) );
		$this->assertNotEmpty( $links, 'a login link' );
		parse_str( (string) wp_parse_url( $links[0], PHP_URL_QUERY ), $q );
		$this->assertSame( (string) wp_parse_url( (string) get_permalink( $this->picked ), PHP_URL_PATH ), (string) wp_parse_url( (string) ( $q['redirect_to'] ?? '' ), PHP_URL_PATH ), 'back to the message centre after signing in' );
		$this->assertStringNotContainsString( 'Ada private question', $html );
		$this->assertSame( array(), $this->portalCalls(), 'OmnaBase is not asked for nobody' );
		$this->assertSame( array(), $this->callsTo( 'PUT', '' ), 'no user is sent' );
	}

	/**
	 * What it checks: With `guest="form"`, a logged-out visitor gets the contact form instead of the login link.
	 * Passes when: `[omnabase_messages guest="form"]` for nobody signed in shows round four's form (a message box and an email field) and no
	 *   portal call is made.
	 * Fails when: a shop without customer accounts cannot let visitors write from the message centre's page.
	 */
	public function test_with_guest_form_a_logged_out_visitor_gets_the_contact_form(): void {
		wp_update_post( array( 'ID' => $this->picked, 'post_content' => '[omnabase_messages guest="form"]' ) );
		$html = $this->view( 0 );
		$this->assertSame( 1, self::nodes( $html, "//form//textarea[@name='message']" )->length, 'the contact form\'s message box' );
		$this->assertSame( 1, self::nodes( $html, "//form//input[@name='email']" )->length, 'the contact form\'s email field' );
		$this->assertSame( array(), $this->portalCalls() );
	}

	/**
	 * What it checks: The signed-in customer is named to OmnaBase only by their site id, with their current address, before anything is read.
	 * Passes when: the first call is `PUT /customers/by-external/<Ada's site id>` with exactly her address and name (no other fields); every
	 *   portal call carries `OmnaBase-Customer: <Ada's site id>`, never her bare user number or her address; the list was asked.
	 * Fails when: the message centre identifies a customer by email alone (the review's S6: a second account with the same address would read
	 *   the first's tickets) or by a number a deleted account can hand on.
	 */
	public function test_a_signed_in_customer_is_named_by_their_site_id_and_current_address(): void {
		$this->view( $this->ada );
		$id = self::siteIdOf( $this->ada );
		$this->assertMatchesRegularExpression( '/^' . $this->ada . '-[a-z0-9]{8}$/', $id );
		$this->assertNotEmpty( $this->calls, 'OmnaBase was asked' );
		$this->assertSame( 'PUT', $this->calls[0]['method'], 'the user is made known first' );
		$this->assertStringEndsWith( '/api/public/v1/customers/by-external/' . rawurlencode( $id ), (string) wp_parse_url( $this->calls[0]['url'], PHP_URL_PATH ) );
		$this->assertSame( array( 'email' => self::ADA_EMAIL, 'name' => 'Ada Lovelace' ), self::bodyOf( $this->calls[0] ) );
		$this->assertNotEmpty( $this->portalCalls( 'GET', '/portal/tickets' ), 'the list was asked' );
		$this->assertSame( array( $id ), $this->namedVisitors(), 'every portal call names Ada by her site id' );
	}

	/**
	 * What it checks: The list shows each ticket's subject, status and last activity, newest change first, each opening its thread.
	 * Passes when: three tickets show their subjects in OmnaBase's order (newest change first), each row a status word and the year of its
	 *   last change, each linked with its number to the thread address.
	 * Fails when: customers cannot tell which question is answered, find the oldest on top, or cannot open a conversation.
	 */
	public function test_the_list_shows_subject_status_and_last_activity_newest_first(): void {
		$this->omnabaseKnows( $this->ada, true );
		$old    = $this->ticketFor( $this->ada, 'Licence not activating', array(), array( 'status' => 'resolved', 'updatedAt' => '2025-11-03T08:00:00Z' ) );
		$middle = $this->ticketFor( $this->ada, 'Invoice address wrong', array(), array( 'status' => 'pending', 'updatedAt' => '2026-09-30T10:00:00Z' ) );
		$new    = $this->ticketFor( $this->ada, 'Slider stopped after update', array(), array( 'status' => 'open', 'updatedAt' => '2026-10-08T09:30:00Z' ) );
		$text   = self::text( $this->view( $this->ada ) );
		$a = strpos( $text, 'Slider stopped after update' );
		$b = strpos( $text, 'Invoice address wrong' );
		$c = strpos( $text, 'Licence not activating' );
		$this->assertNotFalse( $a );
		$this->assertNotFalse( $b );
		$this->assertNotFalse( $c );
		$this->assertTrue( $a < $b && $b < $c, 'newest change first' );
		$html = $this->view( $this->ada );
		foreach ( array( $new => '2026', $middle => '2026', $old => '2025' ) as $n => $year ) {
			$row = self::rowOf( $html, $n );
			$this->assertNotSame( '', $row, "ticket {$n} links to its thread" );
			$this->assertMatchesRegularExpression( '/open|pending|waiting|answered|resolved|closed|done/i', $row, "ticket {$n}'s status" );
			$this->assertStringContainsString( $year, $row, "ticket {$n}'s last activity" );
		}
	}

	/**
	 * What it checks: A long list comes a page at a time, and the next page continues where the first ended.
	 * Passes when: with OmnaBase answering two rows a page, the first view shows the two newest of three subjects and a link to more; that link
	 *   asks OmnaBase with the cursor it gave (`after`) and shows the third.
	 * Fails when: customers with many tickets see only the first page, or the next page repeats the first.
	 */
	public function test_a_long_list_comes_a_page_at_a_time(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->pageSize = 2;
		foreach ( array( 'First question', 'Second question', 'Third question' ) as $s ) {
			$this->ticketFor( $this->ada, $s );
		}
		$html = $this->view( $this->ada );
		$this->assertStringContainsString( 'Third question', $html );
		$this->assertStringContainsString( 'Second question', $html );
		$this->assertStringNotContainsString( 'First question', $html );
		$more = null;
		foreach ( self::nodes( $html, '//a[@href]' ) as $a ) {
			$href = html_entity_decode( (string) $a->getAttribute( 'href' ) );
			if ( str_contains( $href, base64_encode( '2' ) ) || str_contains( $href, rawurlencode( base64_encode( '2' ) ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				$more = $href;
			}
		}
		$this->assertNotNull( $more, 'a link to the next page carrying OmnaBase\'s cursor' );
		$this->calls = array();
		parse_str( (string) wp_parse_url( $more, PHP_URL_QUERY ), $q );
		$next = $this->view( $this->ada, $q );
		$this->assertStringContainsString( 'First question', $next );
		$lists = $this->portalCalls( 'GET', '/portal/tickets' );
		$this->assertNotEmpty( $lists );
		parse_str( (string) wp_parse_url( end( $lists )['url'], PHP_URL_QUERY ), $asked );
		$this->assertSame( base64_encode( '2' ), $asked['after'] ?? null, 'the next page asks after OmnaBase\'s cursor' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * What it checks: A thread shows the customer's and the team's messages with their names, nothing OmnaBase would never send, and never runs
	 *   a script someone wrote into a message.
	 * Passes when: `?omnabase_ticket=<n>` shows the visitor's words, the team's answer under the team's name and another participant's words
	 *   under "Another participant"; a message marked note or system never appears; a script and an image with an error handler in a message
	 *   leave no script element and no handler, their harmless text still readable.
	 * Fails when: a customer reads an internal line in their thread, or a message with a script runs code in the customer's signed-in browser
	 *   (where it could act as them).
	 */
	public function test_a_thread_shows_customer_visible_messages_without_script(): void {
		$this->omnabaseKnows( $this->ada, true );
		$n = $this->ticketFor(
			$this->ada,
			'Slider stopped',
			array(
				array( 'kind' => 'you', 'name' => 'You', 'bodyText' => 'Since the update the slider is gone. <script>alert("you")</script>' ),
				array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'Please clear the cache.', 'bodyHtml' => '<p>Please clear the cache.</p><script>alert("team")</script><img src="x" onerror="alert(2)">' ),
				array( 'kind' => 'other', 'name' => 'Another participant', 'bodyText' => 'Same here.' ),
				array( 'kind' => 'note', 'name' => 'Olga', 'bodyText' => 'INTERNAL-NOTE refunded twice' ),
				array( 'kind' => 'system', 'name' => 'System', 'bodyText' => 'SYSTEM-LINE assigned to Olga' ),
			)
		);
		$html = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$this->assertNotEmpty( $this->portalCalls( 'GET', '/portal/tickets/' . $n ), 'the thread was asked by its number' );
		$text = self::text( $html );
		foreach ( array( 'Since the update the slider is gone.', 'Please clear the cache.', 'Kriesi Support', 'Same here.', 'Another participant' ) as $shown ) {
			$this->assertStringContainsString( $shown, $text );
		}
		$this->assertStringNotContainsString( 'INTERNAL-NOTE', $html );
		$this->assertStringNotContainsString( 'SYSTEM-LINE', $html );
		$this->assertSame( 0, self::nodes( $html, '//script | //*[@onerror] | //*[@onload]' )->length, 'no script, no handler' );
		$this->assertStringNotContainsString( '<script>alert', $html );
	}

	/**
	 * What it checks: Another customer's ticket number opens nothing, and only a number is ever passed on.
	 * Passes when: Ada's address with Ben's ticket number shows a "not found" sentence and none of Ben's words; a ticket "number" with letters
	 *   or a path in it is never sent to OmnaBase as typed.
	 * Fails when: one customer reads another's conversation by changing a number in the address bar, or odd input reaches OmnaBase's paths.
	 */
	public function test_another_customers_ticket_number_reads_not_found(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->omnabaseKnows( $this->ben, true );
		$bens = $this->ticketFor( $this->ben, 'Ben billing question', array( array( 'bodyText' => 'BEN-SECRET my card was charged twice' ) ) );
		$html = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $bens ) );
		$this->assertMatchesRegularExpression( '/not found/i', self::text( $html ) );
		$this->assertStringNotContainsString( 'BEN-SECRET', $html );
		$this->assertStringNotContainsString( 'Ben billing question', $html );
		$this->assertSame( array( self::siteIdOf( $this->ada ) ), $this->namedVisitors(), 'Ada is still the one asked about' );
		foreach ( array( $bens . '/../../tickets', '12 OR 1=1', 'abc' ) as $odd ) {
			$this->calls = array();
			$this->view( $this->ada, array( self::TICKET_QUERY => $odd ) );
			foreach ( $this->portalCalls() as $c ) {
				$this->assertMatchesRegularExpression( '#/api/public/v1/portal/(tickets|tickets/\d+|confirm)$#', (string) wp_parse_url( $c['url'], PHP_URL_PATH ), "'{$odd}' never reaches OmnaBase's path" );
			}
		}
	}

	/**
	 * What it checks: Nothing a visitor can put in the request changes whom OmnaBase is asked about.
	 * Passes when: with `?customer=`, `?omnabase_customer=`, `?externalId=`, `?user_id=`, `?email=` naming Ben, a cookie and an
	 *   `OmnaBase-Customer` request header naming Ben's site id, Ada's view still sends only her own site id and her own address, and shows
	 *   none of Ben's tickets.
	 * Fails when: a customer reads someone else's support history by editing the address bar or a cookie (the brief's red-team line).
	 */
	public function test_nothing_in_the_request_changes_who_is_asked(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->omnabaseKnows( $this->ben, true );
		$this->ticketFor( $this->ben, 'Ben private question' );
		$ben = self::siteIdOf( $this->ben );
		$_COOKIE['omnabase_customer']       = $ben;
		$_COOKIE['omnabase_site_id']        = $ben;
		$_SERVER['HTTP_OMNABASE_CUSTOMER']  = $ben;
		$html = $this->view( $this->ada, array( 'customer' => $ben, 'omnabase_customer' => $ben, 'externalId' => $ben, 'user_id' => (string) $this->ben, 'email' => self::BEN_EMAIL ) );
		$this->assertStringNotContainsString( 'Ben private question', $html );
		$this->assertSame( array( self::siteIdOf( $this->ada ) ), $this->namedVisitors() );
		foreach ( $this->callsTo( 'PUT', '' ) as $c ) {
			$this->assertStringEndsWith( rawurlencode( self::siteIdOf( $this->ada ) ), (string) wp_parse_url( $c['url'], PHP_URL_PATH ) );
			$this->assertSame( self::ADA_EMAIL, self::bodyOf( $c )['email'] ?? null );
		}
	}

	/**
	 * What it checks: Two customers on the same page each see only their own list, also one right after the other (nothing is kept between
	 *   them on the site).
	 * Passes when: Ada's view holds her subject and not Ben's; Ben's view straight after holds his and not hers, and names only Ben.
	 * Fails when: a list cached for one customer is shown to the next, which is exactly what a page cache or a careless transient would do.
	 */
	public function test_two_customers_never_see_each_others_list(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->omnabaseKnows( $this->ben, true );
		$this->ticketFor( $this->ada, 'Ada asks about Enfold' );
		$this->ticketFor( $this->ben, 'Ben asks about refunds' );
		$adas = $this->view( $this->ada );
		$this->assertStringContainsString( 'Ada asks about Enfold', $adas );
		$this->assertStringNotContainsString( 'Ben asks about refunds', $adas );
		$this->calls = array();
		$bens = $this->view( $this->ben );
		$this->assertStringContainsString( 'Ben asks about refunds', $bens );
		$this->assertStringNotContainsString( 'Ada asks about Enfold', $bens );
		$this->assertSame( array( self::siteIdOf( $this->ben ) ), $this->namedVisitors() );
	}

	/**
	 * What it checks: A new account that receives a deleted account's user number starts with an empty message centre (choice 6).
	 * Passes when: after Ada's account is deleted and a new account is made under her old number, the new account's view names a different site
	 *   id (same number, a new suffix) and shows none of Ada's tickets.
	 * Fails when: a database restore that reuses user numbers hands a stranger the deleted customer's whole support history.
	 */
	public function test_a_reused_user_number_starts_empty(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->ticketFor( $this->ada, 'Ada old account question' );
		$this->view( $this->ada );
		$old = self::siteIdOf( $this->ada );
		$number = $this->ada;
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $number );
		global $wpdb;
		$wpdb->insert( $wpdb->users, array( 'ID' => $number, 'user_login' => 'newcomer', 'user_nicename' => 'newcomer', 'user_pass' => wp_hash_password( 'x' ), 'user_email' => 'newcomer@example.org', 'display_name' => 'Newcomer', 'user_registered' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_user_cache( $number );
		$this->calls = array();
		$html  = $this->view( $number );
		$fresh = ( new SiteId() )->forUser( $number );
		$this->assertNotSame( $old, $fresh, 'a new suffix for the reused number' );
		$this->assertStringNotContainsString( 'Ada old account question', $html );
		$this->assertNotContains( $old, $this->namedVisitors(), 'the old id is never asked about' );
		$this->assertSame( array( $fresh ), $this->namedVisitors() );
	}

	/**
	 * What it checks: The site's key and secrets never appear on the message centre's page, for a visitor, a customer or a thread.
	 * Passes when: none of the three stored values is in the logged-out page, a customer's list, a thread or the page's scripts.
	 * Fails when: the key that can read every ticket is readable in any customer's page source.
	 */
	public function test_no_stored_value_reaches_the_message_centre(): void {
		$this->omnabaseKnows( $this->ada, false );
		$n   = $this->ticketFor( $this->ada, 'Slider', array( array( 'bodyText' => 'Hello' ) ) );
		$out = $this->view( 0 ) . $this->view( $this->ada ) . $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) ) . $this->pageAssets();
		$this->assertStringContainsString( 'Slider', $out, 'the page rendered' );
		foreach ( array( self::KEY, self::WEBHOOKS, self::FACTS ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $out );
		}
	}

	/**
	 * The text of a ticket's row: the largest element around the link to its thread that links to no other ticket.
	 *
	 * @param string $html The page.
	 * @param int    $n    The ticket's number.
	 */
	private static function rowOf( string $html, int $n ): string {
		$links = self::nodes( $html, "//a[contains(@href,'" . self::TICKET_QUERY . '=' . $n . "')]" );
		if ( 0 === $links->length ) {
			return '';
		}
		$row = $links->item( 0 );
		while ( $row->parentNode instanceof \DOMElement ) {
			$numbers = array();
			foreach ( ( new \DOMXPath( $row->ownerDocument ) )->query( ".//a[contains(@href,'" . self::TICKET_QUERY . "=')]", $row->parentNode ) as $a ) {
				preg_match( '/' . self::TICKET_QUERY . '=(\d+)/', (string) $a->getAttribute( 'href' ), $m );
				$numbers[ $m[1] ?? '' ] = true;
			}
			if ( count( $numbers ) > 1 ) {
				break;
			}
			$row = $row->parentNode;
		}
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $row->textContent ) );
	}
}
