<?php
/**
 * The plugin's forms round (2026-10-25), the red team's failing tests: what the site vouches for when a shop is there (inside wp-env's tests
 * WordPress, the second run with WooCommerce, EDD and License Manager for WooCommerce loaded: `bin/test-integration.sh`, group "shops").
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`) outcome 2: "name, address and the last order …; WooCommerce's last order number and the
 * licence the site holds for that user where the site has one." Report question 4's defaults: the order and the licence go into the prefill
 * only when the owner switched that shop's customer data on (Settings › OmnaBase › Customer data, the same switches that decide what the team
 * sees), the order is the newest WooCommerce order's number, and a licence only when the user holds exactly one (with several, the person
 * says which).
 *
 * The words as in `FormEmbedTestCase`; *customer-data switches*, the owner's choices of which shops' records the site shares with OmnaBase;
 * *License Manager for WooCommerce*, a free plugin that sells licence keys through WooCommerce.
 *
 * Why these fail today: no prefill call exists at all.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * The customer-data tests with a shop need the shops: bin/test-integration.sh runs them in its second run.
 *
 * @group shops
 */
final class FormPrefillShopsTest extends FormEmbedTestCase {

	private const ADA = 'ada.shop.form@example.org';

	/** The values of the one prefill call made for a user's view of the Support form. */
	private function vouchedFor( int $user ): array {
		$this->calls = array();
		$this->renderAs( $user, 'id="support"' );
		return (array) ( $this->thePrefillBody()['values'] ?? array() );
	}

	/**
	 * What it checks: With WooCommerce's customer data switched on, the site vouches for the customer's newest order number.
	 * Passes when: Ada has orders from 30 September and 10 October; with the WooCommerce switch on, the prefill sends the October order's number
	 *   as the order; with it off, a fresh customer's prefill sends no order at all.
	 * Fails when: the team sees an old order as the one the question is about, or the form shares shop records the owner chose to keep private.
	 */
	public function test_the_newest_woocommerce_order_only_when_its_switch_is_on(): void {
		$ada = $this->customer( self::ADA, 'Ada Shopper' );
		$this->wooOrder( $ada, self::ADA, '2026-09-30 10:00:00' );
		$newest = $this->wooOrder( $ada, self::ADA, '2026-10-10 10:00:00' );
		$this->switchOn( array( 'profile', 'woocommerce' ) );
		$this->assertSame( (string) $newest->get_order_number(), (string) ( $this->vouchedFor( $ada )['order'] ?? '' ) );

		$this->switchOn( array( 'profile' ) );
		$ben = $this->customer( 'ben.shop.form@example.org', 'Ben Shopper' );
		$this->wooOrder( $ben, 'ben.shop.form@example.org' );
		$this->assertArrayNotHasKey( 'order', $this->vouchedFor( $ben ), 'switch off: no order' );
	}

	/**
	 * What it checks: The site vouches for a licence only when the customer holds exactly one, and only with the licence shop's switch on.
	 * Passes when: with License Manager's switch on, Ada (one licence) gets her key in the prefill; Ben (two licences) gets no licence; with the
	 *   switch off, Cleo (one licence) gets none.
	 * Fails when: a customer with several licences finds one picked for them and shown to the team as the one they asked about, or keys are
	 *   shared when the owner switched licences off.
	 */
	public function test_a_licence_only_when_there_is_exactly_one_and_its_switch_is_on(): void {
		$product = $this->product( 'Enfold Theme' );
		$ada     = $this->customer( self::ADA, 'Ada Shopper' );
		$ben     = $this->customer( 'ben.lic.form@example.org', 'Ben Shopper' );
		$cleo    = $this->customer( 'cleo.lic.form@example.org', 'Cleo Shopper' );
		lmfwc_add_license( 'OMNA-FORM-0001-ADA1', array( 'product_id' => $product->get_id(), 'user_id' => $ada, 'status' => 2 ) );
		lmfwc_add_license( 'OMNA-FORM-0002-BEN1', array( 'product_id' => $product->get_id(), 'user_id' => $ben, 'status' => 2 ) );
		lmfwc_add_license( 'OMNA-FORM-0003-BEN2', array( 'product_id' => $product->get_id(), 'user_id' => $ben, 'status' => 2 ) );
		lmfwc_add_license( 'OMNA-FORM-0004-CLE1', array( 'product_id' => $product->get_id(), 'user_id' => $cleo, 'status' => 2 ) );

		$this->switchOn( array( 'profile', 'lmfw' ) );
		$this->assertSame( 'OMNA-FORM-0001-ADA1', $this->vouchedFor( $ada )['licence'] ?? null, 'one licence: vouched for' );
		$this->assertArrayNotHasKey( 'licence', $this->vouchedFor( $ben ), 'two licences: the person says which' );

		$this->switchOn( array( 'profile' ) );
		$this->assertArrayNotHasKey( 'licence', $this->vouchedFor( $cleo ), 'switch off: no licence' );
	}
}
