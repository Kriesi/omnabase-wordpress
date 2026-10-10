<?php
/**
 * M14 round one, the red team's failing tests: the tabs that change when the shops are installed (Customer data with every source and an
 * outdated add-on; Forms and messages with WooCommerce's My Account link) speak plainly, and the My Account link sits in the Shortcodes block
 * of the one save form (the shops run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1, Pages: "the message centre's page picker, the 'theme's styles
 * only' switch, WooCommerce's My Account link, one save action `omnabase_save_pages` … the theme switch sits inside the block"; choice 3 (the
 * banned list; the 30-word rule).
 *
 * The words: *source*, one place customer data comes from (WooCommerce, its Subscriptions add-on, Easy Digital Downloads and its add-ons,
 * License Manager for WooCommerce); *fake*, the test stand-in for a paid add-on, which keeps its version where the real one does; *My Account
 * link*, the "Support" entry the plugin can add to WooCommerce's customer account menu.
 *
 * Shapes pinned: `PagesTabTest`'s and `TabsTestCase`'s; the My Account switch is the checkbox `omnabase_my_account` (today's name, kept).
 *
 * Why these fail today: one page with "Webhook deliveries" on every tab, and the My Account switch in a form of its own
 * (`omnabase_save_display`).
 *
 * @group shops
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * Needs the shops: bin/test-integration.sh runs it in its second run.
 *
 * @group shops
 */
final class PlainWordsShopsTest extends RoundTwoTestCase {

	private string $version = '';

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
		$this->version = (string) \WC_Subscriptions::$version;
		$this->asAdminOnTheScreen( $this->admin );
	}

	public function tear_down(): void {
		\WC_Subscriptions::$version = $this->version;
		parent::tear_down();
	}

	/**
	 * What it checks: With every shop source and an outdated add-on, the Customer data tab still speaks plainly and briefly.
	 * Passes when: all seven sources switched on and WooCommerce Subscriptions at 2.0.0, the tab's visible words hold no banned word, still
	 *   name WooCommerce Subscriptions as an unsupported version, and every paragraph, description and list item has at most 30 words.
	 * Fails when: the richest shop setup, the one most owners with shops have, brings back developer words or long paragraphs.
	 */
	public function test_the_customer_data_tab_with_every_source_speaks_plainly(): void {
		$this->switchOn( self::ALL_SOURCES );
		\WC_Subscriptions::$version = '2.0.0';
		$html = $this->openTab( 'data' );
		$seen = self::visibleText( $html );

		$this->assertSame( array(), self::bannedIn( $seen ) );
		$this->assertMatchesRegularExpression( '/WooCommerce Subscriptions[^|]{0,120}unsupported version/i', $seen, 'the outdated add-on is still named' );
		foreach ( self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings' ) . ']//*[self::p or self::li or ' . self::hasClass( 'description' ) . ']' ) as $node ) {
			$this->assertLessThanOrEqual( 30, str_word_count( self::wordsOf( $node ) ), self::wordsOf( $node ) );
		}
	}

	/**
	 * What it checks: With WooCommerce active, the My Account switch sits in the Shortcodes block of the Forms and messages tab's one save form,
	 *   and the tab speaks plainly.
	 * Passes when: the checkbox `omnabase_my_account` is inside `.ob-wp-settings__shortcodes`, inside the form whose action is
	 *   `omnabase_save_pages`; the tab's visible words hold no banned word.
	 * Fails when: the My Account link keeps a form and a button of its own, so the tab has two saves again.
	 */
	public function test_the_my_account_switch_sits_in_the_one_pages_form(): void {
		$html = $this->openTab( 'pages' );
		$box  = self::query( $html, "//input[@type='checkbox'][@name='omnabase_my_account']" )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $box, 'the My Account switch' );
		$xpath = new \DOMXPath( $box->ownerDocument );
		$this->assertGreaterThan( 0, $xpath->query( 'ancestor::*[' . self::hasClass( 'ob-wp-settings__shortcodes' ) . ']', $box )->length, 'inside the Shortcodes block' );
		$form = $xpath->query( 'ancestor::form', $box )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $form );
		$this->assertSame( 'omnabase_save_pages', self::formFields( $form )['action'] ?? null, 'inside the one save form' );
		$this->assertSame( array(), self::bannedIn( self::visibleText( $html ) ) );
	}
}
