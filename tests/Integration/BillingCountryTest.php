<?php
/**
 * The customer sidebar round (OmnaBase's brief `docs/briefs/customer-sidebar-round.md`, rule 2: "The plugin's user sync sends `country` (ISO
 * 3166-1 alpha-2) from WooCommerce's billing country, else EDD's customer address, when it has one; the facts `profile` may carry `country`";
 * report `docs/reviews/customer-sidebar-round-red-team-report.md`, findings 16 and 17), the red team's failing tests, inside wp-env with the
 * shops loaded.
 *
 * The words: the *user sync* is the plugin telling OmnaBase about one of the site's users (`PUT /customers/by-external/<site id>`, queued in the
 * plugin's outbox, a waiting list of calls); the *facts answer* is what the site answers when OmnaBase asks about a customer (its `omnabase`
 * block's `profile` is the account part); *billing country* is the country of the customer's billing address in WooCommerce (stored as the
 * user field `billing_country`) or in Easy Digital Downloads (the EDD customer's address).
 *
 * Shapes the red team pins (the builder may rename and tell the red team):
 * - The user sync's body carries `country` (two upper-case letters) when the user has a billing country: WooCommerce's first, else the EDD
 *   customer's address; without one the key is absent (never `null`: OmnaBase's sync empties a field it is sent as null).
 * - The facts answer's `profile.country` follows the same rule (the profile source is on).
 * - No `language` is sent in either (chosen: a WordPress user's language is usually the site's, not the customer's).
 *
 * Why these fail today: the sync sends only the address and the name; the profile only the registration date, roles and ticked fields.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * The customer-data tests need the shops: bin/test-integration.sh runs them in its second run.
 *
 * @group shops
 */
final class BillingCountryTest extends RoundTwoTestCase {

	/** The body of the user sync the worker sent for a user. */
	private function linkBodyFor( int $user ): array {
		$this->omnabaseTakes( $user );
		$this->switchOn( array( 'profile' ) );
		$this->askFacts( (string) get_userdata( $user )->user_email );
		$this->makeDue();
		$this->runOutbox();
		$puts = $this->callsFor( $user, 'PUT', false );
		$this->assertCount( 1, $puts, 'one user sync' );
		return (array) json_decode( (string) $puts[0]['args']['body'], true );
	}

	/**
	 * What it checks: When the plugin tells OmnaBase about a shop customer, it includes the country of their WooCommerce billing address.
	 * Passes when: Ada, whose billing country is "at", is sent with country "AT", her address and name; no language is sent.
	 * Fails when: OmnaBase's Country row stays empty for every shop customer although the shop knows where they live.
	 */
	public function test_the_user_sync_carries_the_woocommerce_billing_country(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'billing_country', 'at' );
		$body = $this->linkBodyFor( $ada );
		$this->assertSame( 'ada@example.org', $body['email'] ?? null, '(guard) the address' );
		$this->assertSame( 'AT', $body['country'] ?? null );
		$this->assertArrayNotHasKey( 'language', $body );
	}

	/**
	 * What it checks: A customer without a billing country is sent without one, so OmnaBase keeps whatever it knows.
	 * Passes when: Ben, with no billing country anywhere, is sent without a "country" key at all (not even an empty one).
	 * Fails when: the plugin sends an empty country and wipes the country the team or another source set in OmnaBase.
	 * (guard) Passes today (no country is sent at all); it must keep passing once the country is.
	 */
	public function test_no_billing_country_no_key(): void {
		$ben  = $this->customer( 'ben@example.org', 'Ben Weber' );
		$body = $this->linkBodyFor( $ben );
		$this->assertSame( 'ben@example.org', $body['email'] ?? null, '(guard) the address' );
		$this->assertArrayNotHasKey( 'country', $body );
	}

	/**
	 * What it checks: For a customer who buys through Easy Digital Downloads, the country of their EDD address is used.
	 * Passes when: Cleo, with no WooCommerce billing country and an EDD billing address in "DE", is sent with country "DE".
	 * Fails when: EDD shops never get a country into OmnaBase.
	 */
	public function test_the_edd_address_when_woocommerce_has_none(): void {
		$cleo     = $this->customer( 'cleo@example.org', 'Cleo Digital' );
		$customer = (int) edd_add_customer( array( 'email' => 'cleo@example.org', 'name' => 'Cleo Digital', 'user_id' => $cleo ) );
		edd_add_customer_address( array( 'customer_id' => $customer, 'type' => 'billing', 'is_primary' => 1, 'country' => 'DE', 'city' => 'Berlin' ) );
		$body = $this->linkBodyFor( $cleo );
		$this->assertSame( 'DE', $body['country'] ?? null );
	}

	/**
	 * What it checks: When OmnaBase asks about a customer, the account part of the answer carries the billing country too.
	 * Passes when: Ada's answer, with the profile source on, has profile.country "AT" beside her registration date; Ben's has no country; neither
	 *   carries a language.
	 * Fails when: the card's account line and OmnaBase's own record miss the country the shop knows.
	 */
	public function test_the_facts_profile_carries_the_country(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'billing_country', 'AT' );
		$this->customer( 'ben@example.org', 'Ben Weber' );
		$this->switchOn( array( 'profile' ) );
		$a = $this->blockFor( 'ada@example.org' )->profile ?? null;
		$this->assertSame( '2021-03-04', $a->registeredAt ?? null, '(guard) the registration date' );
		$this->assertSame( 'AT', $a->country ?? null );
		$this->assertFalse( property_exists( $a, 'language' ) );
		$b = $this->blockFor( 'ben@example.org' )->profile ?? null;
		$this->assertFalse( property_exists( (object) $b, 'country' ), 'no country for Ben' );
	}
}
