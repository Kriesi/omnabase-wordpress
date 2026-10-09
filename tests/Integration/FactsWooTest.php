<?php
/**
 * M13 round two, the red team's failing tests: the customer receiver's WooCommerce sources in a real WordPress with WooCommerce and
 * License Manager for WooCommerce: orders (both order storages), WooCommerce Subscriptions (through its fake) and License Manager's licences.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 5: "**WooCommerce** orders through `wc_get_orders` (number, date,
 * total as plain text, status, the admin link; HPOS compatibility declared); **WooCommerce Subscriptions**; … **License Manager for
 * WooCommerce** (… its free version records an activation count, not site names, so no `sites`)"; "guest records by address (Woo orders by
 * billing address …)"; "the newest 20 per list"; "Every query bounded so the answer comes well inside 5 seconds"; round two's *Red team*:
 * "guest Woo orders found by billing address; HPOS on and off answer alike; … 25 orders → the newest 20; … a switched-off source is absent;
 * keys masked; under 128 KB and inside one second with 500 orders". The words: **HPOS**, WooCommerce's newer order storage in its own tables
 * (the older one keeps orders as posts). OmnaBase's contract (`siteFacts.ts`): an order is `{ number, date?, total?, status?, href? }`, a
 * subscription `{ product, status?, renewsAt?, href? }`, a licence `{ product, keyMasked?, status?, version?, expiresAt?, sites?, href? }`;
 * the docs' example total is "59.00 EUR" (the currency inside the text: the contract has no currency or items field, see the report).
 *
 * Shapes the red team pins: those of `RoundTwoTestCase`; WooCommerce Subscriptions' names as `tests/fakes/woocommerce-subscriptions.php`
 * fakes them. (chosen) Dates go out as `Y-m-d` (the docs' example); an order's link is its admin edit page on the site's https address.
 *
 * Why these fail today: the receiver answers `{}` to every call.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;

/**
 * The customer-data tests need the shops: bin/test-integration.sh runs them in its second run.
 *
 * @group shops
 */
final class FactsWooTest extends RoundTwoTestCase {

	/**
	 * What it checks: The team sees a customer's WooCommerce orders as OmnaBase shows them: number, date, total with its currency, status, and
	 *   a link to the order in the shop's admin.
	 * Passes when: Ada's completed order of 59.00 EUR on 2026-09-30 arrives with its number as text, date 2026-09-30, total "59.00 EUR" (plain
	 *   text, no HTML), status "completed" and an https link into the site's wp-admin.
	 * Fails when: the card shows HTML price markup, a number OmnaBase refuses, or no way to open the order.
	 */
	public function test_orders_carry_number_date_total_status_and_link(): void {
		$ada   = $this->customer( 'ada@example.org' );
		$order = $this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00' );
		$this->switchOn( array( 'woocommerce' ) );

		$o = $this->blockFor( 'ada@example.org' )->orders[0] ?? null;

		$this->assertNotNull( $o );
		$this->assertSame( (string) $order->get_order_number(), $o->number );
		$this->assertSame( '2026-09-30', substr( (string) ( $o->date ?? '' ), 0, 10 ) );
		$this->assertSame( '59.00 EUR', $o->total ?? null );
		$this->assertSame( 'completed', $o->status ?? null );
		$this->assertStringStartsWith( self::SITE . '/wp-admin/', (string) ( $o->href ?? '' ) );
	}

	/**
	 * What it checks: A long-time customer's answer lists their 20 newest orders, newest first.
	 * Passes when: of 25 orders made a day apart, the answer holds 20, the newest first, and none of the 5 oldest.
	 * Fails when: the card shows years-old orders and misses yesterday's purchase, or the answer grows with every order.
	 */
	public function test_twenty_five_orders_give_the_newest_twenty(): void {
		$ada     = $this->customer( 'ada@example.org' );
		$product = $this->product();
		$numbers = array();
		for ( $day = 1; $day <= 25; $day++ ) {
			$numbers[ $day ] = (string) $this->wooOrder( $ada, 'ada@example.org', sprintf( '2026-08-%02d 10:00:00', $day ), 'completed', $product )->get_order_number();
		}
		$this->switchOn( array( 'woocommerce' ) );

		$got = array_map( static fn ( $o ) => $o->number, $this->blockFor( 'ada@example.org' )->orders );

		$this->assertSame( array_values( array_reverse( array_slice( $numbers, 5, 20, true ) ) ), $got );
	}

	/**
	 * What it checks: Someone who bought as a guest (no account) is still recognised by the billing address on their orders.
	 * Passes when: a guest order billed to grace@example.org appears in the answer for grace@example.org, with no profile (there is no account).
	 * Fails when: the team sees nothing for a buyer who checked out without an account, the most common case in many shops.
	 */
	public function test_guest_orders_are_found_by_billing_address(): void {
		$order = $this->wooOrder( 0, 'grace@example.org' );
		$this->switchOn( array( 'profile', 'woocommerce' ) );

		$block = $this->blockFor( 'grace@example.org' );

		$this->assertSame( array( (string) $order->get_order_number() ), array_map( static fn ( $o ) => $o->number, $block->orders ) );
		$this->assertTrue( ! isset( $block->profile ) || null === $block->profile, 'a guest has no account to show' );
	}

	/**
	 * What it checks: The answer is the same whichever order storage the shop uses (posts, or WooCommerce's newer order tables).
	 * Passes when: Ada's two orders are answered identically with the older storage and after switching the shop to the newer one (orders kept
	 *   in sync, as WooCommerce's own switch does).
	 * Fails when: a shop on the newer storage (the default for new shops) shows no orders on the team's card.
	 */
	public function test_both_order_storages_answer_alike(): void {
		$sync = wc_get_container()->get( DataSynchronizer::class );
		$sync->create_database_tables();
		update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'yes' );
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
		$ada     = $this->customer( 'ada@example.org' );
		$product = $this->product();
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-01 10:00:00', 'completed', $product );
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-20 10:00:00', 'processing', $product );
		$this->switchOn( array( 'woocommerce' ) );
		$legacy = $this->blockFor( 'ada@example.org' )->orders;

		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		$this->assertSame( 'yes', get_option( 'woocommerce_custom_orders_table_enabled' ), 'the shop now reads its own order tables' );
		$hpos = $this->blockFor( 'ada@example.org' )->orders;

		$this->assertCount( 2, $legacy );
		$this->assertEquals( $legacy, $hpos );
		update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
	}

	/**
	 * What it checks: A customer with hundreds of orders is answered fast and small.
	 * Passes when: with 500 orders, the answer takes under one second, holds 20 orders and is under 128 KB.
	 * Fails when: the plugin loads every order (OmnaBase allows the whole call 5 seconds; a slow shop shows an error on every card).
	 */
	public function test_five_hundred_orders_answer_inside_a_second_and_128_kb(): void {
		$ada = $this->customer( 'ada@example.org' );
		for ( $i = 0; $i < 500; $i++ ) {
			$order = new \WC_Order();
			$order->set_customer_id( $ada );
			$order->set_billing_email( 'ada@example.org' );
			$order->set_currency( 'EUR' );
			$order->set_total( '59.00' );
			$order->set_status( 'completed' );
			$order->set_date_created( time() - $i * HOUR_IN_SECONDS );
			$order->save();
		}
		$this->switchOn( array( 'profile', 'woocommerce' ) );

		$start = microtime( true );
		$a     = $this->askFacts( 'ada@example.org' );
		$took  = microtime( true ) - $start;

		$this->assertLessThan( 1.0, $took, sprintf( 'answered in %.2f s', $took ) );
		$this->assertLessThanOrEqual( 131072, strlen( $a['raw'] ) );
		$this->assertCount( 20, $a['answer']->omnabase->orders ?? array() );
	}

	/**
	 * What it checks: With the WooCommerce switch off, no order is sent even though the shop has them.
	 * Passes when: Ada has an order, WooCommerce is off and the profile on: the answer has a profile and no orders key.
	 * Fails when: switching a source off on the settings screen does not stop it.
	 */
	public function test_orders_are_absent_with_the_switch_off(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->wooOrder( $ada, 'ada@example.org' );
		$this->switchOn( array( 'profile', 'woocommerce_subscriptions', 'lmfw' ) );

		$this->assertFalse( property_exists( $this->blockFor( 'ada@example.org' ), 'orders' ) );
	}

	/**
	 * What it checks: WooCommerce Subscriptions (a paid add-on, faked here) shows each subscription's product, status and next renewal.
	 * Passes when: Ada's active "Enfold updates" subscription renewing 2027-05-01 and her cancelled one arrive as product, status and renewsAt
	 *   2027-05-01; Ben's subscription is not in her answer.
	 * Fails when: the team cannot see whether a customer's update subscription is still running.
	 */
	public function test_woocommerce_subscriptions_show_product_status_and_renewal(): void {
		$ada = $this->customer( 'ada@example.org' );
		$ben = $this->customer( 'ben@example.org', 'Ben Weber' );
		update_option(
			'omnabase_fake_wcs',
			array(
				array( 'id' => 901, 'user_id' => $ada, 'status' => 'active', 'next_payment' => '2027-05-01 08:00:00', 'items' => array( 'Enfold updates' ), 'edit_url' => self::SITE . '/wp-admin/post.php?post=901&action=edit' ),
				array( 'id' => 902, 'user_id' => $ada, 'status' => 'cancelled', 'next_payment' => '', 'items' => array( 'Omnalingo Pro yearly' ) ),
				array( 'id' => 903, 'user_id' => $ben, 'status' => 'active', 'next_payment' => '2027-01-01 08:00:00', 'items' => array( 'Ben plan' ) ),
			)
		);
		$this->switchOn( array( 'woocommerce_subscriptions' ) );

		$subs = $this->blockFor( 'ada@example.org' )->subscriptions ?? array();

		$by = array();
		foreach ( $subs as $s ) {
			$by[ $s->product ] = $s;
		}
		$this->assertEqualsCanonicalizing( array( 'Enfold updates', 'Omnalingo Pro yearly' ), array_keys( $by ), 'Ada\'s two subscriptions, not Ben\'s' );
		$this->assertSame( 'active', $by['Enfold updates']->status ?? null );
		$this->assertSame( '2027-05-01', substr( (string) ( $by['Enfold updates']->renewsAt ?? '' ), 0, 10 ) );
		$this->assertSame( 'cancelled', $by['Omnalingo Pro yearly']->status ?? null );
	}

	/**
	 * What it checks: License Manager for WooCommerce's licences show product, masked key, status and expiry, and no sites (its free version
	 *   does not know them).
	 * Passes when: Ada's delivered licence OMNA-TEST-0000-7Q2X for "Enfold Theme" expiring 2027-05-01 arrives as product "Enfold Theme",
	 *   keyMasked "••••-7Q2X", a status and expiresAt 2027-05-01; there is no sites field; the full key appears nowhere.
	 * Fails when: the team cannot see a customer's licence, or the full key is copied into OmnaBase.
	 */
	public function test_license_manager_licences_show_product_masked_key_status_and_expiry(): void {
		$ada     = $this->customer( 'ada@example.org' );
		$product = $this->product( 'Enfold Theme' );
		lmfwc_add_license( 'OMNA-TEST-0000-7Q2X', array( 'product_id' => $product->get_id(), 'user_id' => $ada, 'status' => 2, 'expires_at' => '2027-05-01 00:00:00', 'times_activated_max' => 3 ) );
		$this->switchOn( array( 'lmfw' ) );

		$a = $this->askFacts( 'ada@example.org' );
		$l = $this->blockFor( 'ada@example.org' )->licences[0] ?? null;

		$this->assertNotNull( $l );
		$this->assertSame( 'Enfold Theme', $l->product );
		$this->assertSame( '••••-7Q2X', $l->keyMasked ?? null );
		$this->assertNotSame( '', (string) ( $l->status ?? '' ) );
		$this->assertSame( '2027-05-01', substr( (string) ( $l->expiresAt ?? '' ), 0, 10 ) );
		$this->assertFalse( property_exists( $l, 'sites' ), 'License Manager\'s free version records no site names' );
		$this->assertStringNotContainsString( 'OMNA-TEST-0000', $a['raw'] );
	}
}
