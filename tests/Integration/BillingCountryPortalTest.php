<?php
/**
 * The customer sidebar round (OmnaBase's brief `docs/briefs/customer-sidebar-round.md`, rule 2 "the plugin's user sync sends `country`";
 * report `docs/reviews/customer-sidebar-round-red-team-report.md`, finding 17: the brief names only the outbox's link, but the message
 * centre sends the same call on its own on a signed-in customer's visit, and it is the only call that runs again after the first link).
 *
 * The words: the *message centre* is the plugin's page where a signed-in customer reads their support conversations; on a visit it tells
 * OmnaBase who they are (`PUT /customers/by-external/<site id>`) unless a stored *fingerprint* (a short summary of what OmnaBase was last told)
 * says nothing changed; *billing country* is the country of the customer's billing address (WooCommerce's user field `billing_country`).
 *
 * Shapes the red team pins (the builder may rename and tell the red team):
 * - The message centre's call carries `country` like the outbox's (two upper-case letters; absent without one).
 * - The fingerprint includes the country, so a changed billing country is sent on the customer's next visit.
 *
 * Why these fail today: the message centre's call sends the address and the name only, and the fingerprint covers only those two.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class BillingCountryPortalTest extends RoundFiveTestCase {

	/**
	 * What it checks: A signed-in customer's visit to the message centre tells OmnaBase their billing country with their address and name.
	 * Passes when: Ada (billing country "AT") opens the message centre and the call to OmnaBase carries country "AT"; Ben (none) is sent without
	 *   a country key.
	 * Fails when: customers who use the message centre never get a country in OmnaBase, or an empty one wipes what OmnaBase knew.
	 */
	public function test_the_message_centre_sends_the_country(): void {
		update_user_meta( $this->ada, 'billing_country', 'AT' );
		$this->view( $this->ada );
		$ada = $this->syncCalls( $this->ada );
		$this->assertNotEmpty( $ada, '(guard) the visit told OmnaBase about Ada' );
		$this->assertSame( 'AT', self::bodyOf( $ada[0] )['country'] ?? null );
		$this->view( $this->ben );
		$ben = $this->syncCalls( $this->ben );
		$this->assertNotEmpty( $ben, '(guard) the visit told OmnaBase about Ben' );
		$this->assertArrayNotHasKey( 'country', self::bodyOf( $ben[0] ) );
	}

	/**
	 * What it checks: When a customer's billing country changes, OmnaBase hears it on their next message-centre visit.
	 * Passes when: a confirmed Ada whose visit was already remembered changes her billing country to "CH"; her next visit sends a call with
	 *   country "CH". A visit with nothing changed sends nothing (as before).
	 * Fails when: OmnaBase keeps a customer's old country for ever after they moved.
	 */
	public function test_a_changed_country_is_sent_on_the_next_visit(): void {
		$this->omnabaseKnows( $this->ada, true );
		update_user_meta( $this->ada, 'billing_country', 'AT' );
		$this->view( $this->ada );
		$this->calls = array();
		$this->view( $this->ada );
		$this->assertSame( array(), $this->syncCalls( $this->ada ), '(guard) nothing changed: no call' );
		update_user_meta( $this->ada, 'billing_country', 'CH' );
		$this->view( $this->ada );
		$sent = $this->syncCalls( $this->ada );
		$this->assertNotEmpty( $sent, 'the new country is sent' );
		$this->assertSame( 'CH', self::bodyOf( $sent[0] )['country'] ?? null );
	}
}
