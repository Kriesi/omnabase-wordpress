<?php
/**
 * M13 round two, the red team's failing tests: the customer receiver's Easy Digital Downloads sources in a real WordPress with EDD 3:
 * orders, and the paid add-ons Software Licensing (licence keys) and Recurring (subscriptions) through their fakes.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 5: "**EDD 3** orders, **Recurring**, **Software Licensing** (product,
 * masked key, status, version, expiry, activated sites)"; "guest records by address (… the EDD customer by any of its addresses)"; round
 * two's *Red team*: "an EDD customer with two addresses found by either; … a switched-off source is absent; keys masked". The plugin's
 * `CLAUDE.md`: "`tests/fakes` the paid add-ons' fakes (from round two)"; DECISIONS "M13 round zero": the seed gives Ada an EDD customer with
 * a second address. The words: an **EDD customer** is EDD's own record of a buyer, which can hold several addresses and need not be a
 * WordPress user.
 *
 * Shapes the red team pins: those of `RoundTwoTestCase`; the add-ons' names as `tests/fakes/edd-software-licensing.php` and
 * `tests/fakes/edd-recurring.php` fake them (the real add-ons' public API). (chosen) A licence's version is its download's version.
 *
 * Why these fail today: the receiver answers `{}` to every call.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * The customer-data tests need the shops: bin/test-integration.sh runs them in its second run.
 *
 * @group shops
 */
final class FactsEddTest extends RoundTwoTestCase {

	/** An EDD customer with an order of 39 EUR for the download. */
	private function eddOrder( int $customer, int $user, string $email, int $download, string $status = 'complete', string $completed = '2026-09-15 10:00:00' ): int {
		$id = (int) edd_add_order( array( 'user_id' => $user, 'customer_id' => $customer, 'email' => $email, 'status' => $status, 'type' => 'sale', 'gateway' => 'manual', 'currency' => 'EUR', 'subtotal' => 39, 'total' => 39, 'date_created' => $completed, 'date_completed' => $completed ) );
		edd_add_order_item( array( 'order_id' => $id, 'product_id' => $download, 'product_name' => get_the_title( $download ), 'status' => $status, 'quantity' => 1, 'amount' => 39, 'subtotal' => 39, 'total' => 39 ) );
		return $id;
	}

	/**
	 * What it checks: The team sees a customer's EDD orders, found through EDD 3's own records.
	 * Passes when: Ada's complete order of 39 EUR arrives with EDD's order number, the date, total "39.00 EUR" (plain text) and status
	 *   "complete".
	 * Fails when: EDD buyers show nothing on the team's card, or the total arrives with HTML or without its currency.
	 */
	public function test_edd_orders_are_answered(): void {
		$ada      = $this->customer( 'ada@example.org' );
		$customer = (int) edd_add_customer( array( 'email' => 'ada@example.org', 'name' => 'Ada Lovelace', 'user_id' => $ada ) );
		$order    = $this->eddOrder( $customer, $ada, 'ada@example.org', $this->download() );
		$this->switchOn( array( 'edd' ) );

		$o = $this->blockFor( 'ada@example.org' )->orders[0] ?? null;

		$this->assertNotNull( $o );
		$this->assertSame( (string) edd_get_order( $order )->get_number(), $o->number );
		$this->assertSame( '2026-09-15', substr( (string) ( $o->date ?? '' ), 0, 10 ) );
		$this->assertSame( '39.00 EUR', $o->total ?? null );
		$this->assertSame( 'complete', $o->status ?? null );
	}

	/**
	 * What it checks: An EDD buyer with two addresses (and no WordPress account) is recognised by either address.
	 * Passes when: an EDD customer with grace@example.org and grace.work@example.org and one order is answered with that order for each
	 *   address.
	 * Fails when: a customer who writes from their work address looks like a stranger, though the shop knows both addresses.
	 */
	public function test_an_edd_customer_with_two_addresses_is_found_by_either(): void {
		$customer = (int) edd_add_customer( array( 'email' => 'grace@example.org', 'name' => 'Grace Guest' ) );
		edd_add_customer_email_address( array( 'customer_id' => $customer, 'email' => 'grace.work@example.org', 'type' => 'secondary' ) );
		$order = $this->eddOrder( $customer, 0, 'grace@example.org', $this->download() );
		$this->switchOn( array( 'edd' ) );

		foreach ( array( 'grace@example.org', 'grace.work@example.org' ) as $address ) {
			$numbers = array_map( static fn ( $o ) => $o->number, $this->blockFor( $address )->orders ?? array() );
			$this->assertSame( array( (string) edd_get_order( $order )->get_number() ), $numbers, $address );
		}
	}

	/**
	 * What it checks: EDD Software Licensing's licences (faked here) show product, masked key, status, version, expiry and the activated sites.
	 * Passes when: Ada's active licence for "Omnalingo Pro" (version 2.4.1, expiring 2027-05-01, active on two sites) arrives with those values,
	 *   the key as "••••-9K4M", and the full key nowhere in the answer; Ben's licence is not in her answer.
	 * Fails when: the team answers "which version do you run, and on which site?" without seeing it, or the full key is copied into OmnaBase.
	 */
	public function test_software_licensing_licences_show_everything_but_the_key(): void {
		$ada      = $this->customer( 'ada@example.org' );
		$ben      = $this->customer( 'ben@example.org', 'Ben Weber' );
		$download = $this->download( 'Omnalingo Pro', '2.4.1' );
		update_option(
			'omnabase_fake_edd_sl',
			array(
				array( 'id' => 71, 'user_id' => $ada, 'customer_id' => 0, 'download_id' => $download, 'key' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b49K4M', 'status' => 'active', 'expiration' => strtotime( '2027-05-01 00:00:00 UTC' ), 'sites' => array( 'https://kunde.example', 'https://staging.kunde.example' ) ),
				array( 'id' => 72, 'user_id' => $ben, 'customer_id' => 0, 'download_id' => $download, 'key' => 'ffffffffffffffffffffffffffffBEN1', 'status' => 'active', 'expiration' => 0, 'sites' => array() ),
			)
		);
		$this->switchOn( array( 'edd_software_licensing' ) );

		$a        = $this->askFacts( 'ada@example.org' );
		$licences = $this->blockFor( 'ada@example.org' )->licences ?? array();

		$this->assertCount( 1, $licences );
		$l = $licences[0];
		$this->assertSame( 'Omnalingo Pro', $l->product );
		$this->assertSame( '••••-9K4M', $l->keyMasked ?? null );
		$this->assertSame( 'active', $l->status ?? null );
		$this->assertSame( '2.4.1', $l->version ?? null );
		$this->assertSame( '2027-05-01', substr( (string) ( $l->expiresAt ?? '' ), 0, 10 ) );
		$this->assertEqualsCanonicalizing( array( 'https://kunde.example', 'https://staging.kunde.example' ), $l->sites ?? array() );
		$this->assertStringNotContainsString( 'a1b2c3d4e5f6', $a['raw'] );
	}

	/**
	 * What it checks: EDD Recurring's subscriptions (faked here) show product, status and when they renew.
	 * Passes when: Ada's active "Omnalingo Pro" subscription expiring 2027-03-01 arrives as product "Omnalingo Pro", status "active" and
	 *   renewsAt 2027-03-01.
	 * Fails when: the team cannot see whether a customer's yearly plan is still active.
	 */
	public function test_recurring_subscriptions_show_product_status_and_renewal(): void {
		$ada      = $this->customer( 'ada@example.org' );
		$download = $this->download( 'Omnalingo Pro' );
		update_option( 'omnabase_fake_edd_recurring', array( array( 'id' => 31, 'user_id' => $ada, 'email' => 'ada@example.org', 'customer_id' => 0, 'product_id' => $download, 'status' => 'active', 'expiration' => '2027-03-01 23:59:59' ) ) );
		$this->switchOn( array( 'edd_recurring' ) );

		$s = $this->blockFor( 'ada@example.org' )->subscriptions[0] ?? null;

		$this->assertNotNull( $s );
		$this->assertSame( 'Omnalingo Pro', $s->product );
		$this->assertSame( 'active', $s->status ?? null );
		$this->assertSame( '2027-03-01', substr( (string) ( $s->renewsAt ?? '' ), 0, 10 ) );
	}

	/**
	 * What it checks: Each EDD source has its own switch: orders on does not send licences, licences on does not send orders.
	 * Passes when: with only EDD orders on, Ada's answer has orders and no licences or subscriptions; with only Software Licensing on, licences
	 *   and no orders.
	 * Fails when: one switch quietly turns on another source the administrator chose to keep private.
	 */
	public function test_each_edd_source_has_its_own_switch(): void {
		$ada      = $this->customer( 'ada@example.org' );
		$download = $this->download( 'Omnalingo Pro', '2.4.1' );
		$customer = (int) edd_add_customer( array( 'email' => 'ada@example.org', 'name' => 'Ada Lovelace', 'user_id' => $ada ) );
		$this->eddOrder( $customer, $ada, 'ada@example.org', $download );
		update_option( 'omnabase_fake_edd_sl', array( array( 'id' => 71, 'user_id' => $ada, 'download_id' => $download, 'key' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b49K4M', 'status' => 'active', 'expiration' => 0, 'sites' => array() ) ) );
		update_option( 'omnabase_fake_edd_recurring', array( array( 'id' => 31, 'user_id' => $ada, 'product_id' => $download, 'status' => 'active', 'expiration' => '2027-03-01 23:59:59' ) ) );

		$this->switchOn( array( 'edd' ) );
		$orders_only = $this->blockFor( 'ada@example.org' );
		$this->switchOn( array( 'edd_software_licensing' ) );
		$licences_only = $this->blockFor( 'ada@example.org' );

		$this->assertNotEmpty( $orders_only->orders ?? array() );
		$this->assertFalse( property_exists( $orders_only, 'licences' ) );
		$this->assertFalse( property_exists( $orders_only, 'subscriptions' ) );
		$this->assertNotEmpty( $licences_only->licences ?? array() );
		$this->assertFalse( property_exists( $licences_only, 'orders' ) );
	}
}
