<?php
/**
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: WooCommerce's My Account switch moves to the "Forms and
 * messages" tab, inside the Shortcodes block of that tab's one save form (brief `docs/briefs/m14-settings-cleanup.md`, choice 1: "the
 * message centre's page picker, the 'theme's styles only' switch, WooCommerce's My Account link, one save action `omnabase_save_pages`").
 * The first test now finds the switch inside the Shortcodes block of that tab (stronger than "somewhere on the screen"); `flip()` reads it
 * on that tab. Nothing else changed.
 *
 * M13 round six, the red team's failing tests: WooCommerce's My Account gets a "Support" link to the message centre, behind a switch.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 9: "`[omnabase_messages]` on the page the admin picks (a 'Support'
 * link in Woo's My Account when turned on)"; the plugin's DECISIONS "M13 round five", "Left for round six": "WooCommerce's My Account 'Support'
 * link (the brief's 'when turned on', a switch on the screen)". The coordinator: "WooCommerce active + the switch on → 'Support' in My
 * Account's navigation pointing at the picked page; off → absent".
 *
 * The words: *My Account*, WooCommerce's page where a signed-in customer sees orders, addresses and account details, with a menu down its
 * side; *menu item*, one entry of that menu (WooCommerce builds it through its `woocommerce_account_menu_items` filter, a named moment where
 * plugins may change a list).
 *
 * Shapes pinned (`RoundSixTestCase`): the switch is a checkbox labelled with "My Account" somewhere on the settings screen, saved through its
 * own form with the form's nonce. The link's words are "Support" (the brief's); its address is the picked page's own address, read through
 * WooCommerce's `wc_get_account_endpoint_url()` for the item's key.
 *
 * Why these fail today: there is no switch and nothing adds to WooCommerce's menu.
 *
 * @package OmnaBase
 * @group shops
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * Needs WooCommerce: bin/test-integration.sh runs it in its second run.
 *
 * @group shops
 */
final class MyAccountLinkTest extends RoundSixTestCase {

	private const SWITCH = '/my account/i';

	/** The key of the My Account menu item reading "Support", or null. */
	private static function supportItem(): ?string {
		foreach ( wc_get_account_menu_items() as $key => $label ) {
			if ( 'support' === strtolower( trim( wp_strip_all_tags( (string) $label ) ) ) ) {
				return (string) $key;
			}
		}
		return null;
	}

	/**
	 * What it checks: Without the switch, WooCommerce's My Account menu is left as WooCommerce made it.
	 * Passes when: the Forms and messages tab offers the "My Account" switch inside its Shortcodes block, unticked, and the My Account menu
	 *   has no "Support" item.
	 * Fails when: the plugin changes a shop's account menu the owner never asked to change, or the owner cannot find the switch.
	 */
	public function test_off_by_default_no_support_link(): void {
		// Builder's adjustment (M14 round one, DECISIONS "2026-10-10 — M14 round one"): the tab's one save form wraps the whole Shortcodes
		// block, so the block's own HTML holds no form and the form-reading helper found nothing in it. The switch is read with its form from
		// the whole tab, and a second assertion still pins it inside the Shortcodes block.
		$html = $this->screen( 'pages' );
		$box  = self::checkbox( $html, self::SWITCH );
		$this->assertNotNull( $box, 'the switch is offered while WooCommerce is active' );
		$this->assertSame( 1, self::nodes( self::section( $html, 'ob-wp-settings__shortcodes' ), "//input[@type='checkbox'][@name='" . $box['name'] . "']" )->length, 'the switch inside the Shortcodes block' );
		$this->assertFalse( $box['checked'] );
		$this->asUserOnTheFront( $this->ada );
		$this->assertNull( self::supportItem() );
	}

	/**
	 * What it checks: Turned on, My Account shows "Support" leading to the message centre's page, and the menu's last entry stays "Log out".
	 * Passes when: after ticking the switch, the menu holds a "Support" item whose address is the picked page's address, placed before the
	 *   log-out entry.
	 * Fails when: customers cannot find the message centre from their account, the link leads to a WooCommerce page that does not exist, or it
	 *   lands after "Log out" where nobody looks.
	 */
	public function test_on_adds_support_pointing_at_the_picked_page(): void {
		$this->flip( self::SWITCH, true );
		$this->asUserOnTheFront( $this->ada );
		$key = self::supportItem();
		$this->assertNotNull( $key, 'a "Support" item in My Account' );
		$this->assertSame( (string) get_permalink( $this->picked ), wc_get_account_endpoint_url( (string) $key ) );
		$keys = array_keys( wc_get_account_menu_items() );
		if ( in_array( 'customer-logout', $keys, true ) ) {
			$this->assertLessThan( array_search( 'customer-logout', $keys, true ), array_search( $key, $keys, true ), '"Support" comes before "Log out"' );
		}
	}

	/**
	 * What it checks: With the switch on but no message-centre page picked, there is no link to nowhere.
	 * Passes when: the page pick is cleared and the menu holds no "Support" item.
	 * Fails when: customers click "Support" and land on the shop's home page or a missing page.
	 */
	public function test_on_without_a_picked_page_shows_nothing(): void {
		$this->flip( self::SWITCH, true );
		update_option( self::PORTAL_OPTION, array( 'page' => 0 ) );
		$this->asUserOnTheFront( $this->ada );
		$this->assertNull( self::supportItem() );
	}

	/**
	 * What it checks: The switch changes only with the form's token.
	 * Passes when: posting the switch ticked without the nonce leaves My Account without "Support".
	 * Fails when: a forged request adds a link to every customer's account menu.
	 */
	public function test_the_switch_needs_the_forms_token(): void {
		$this->flip( self::SWITCH, true, false );
		$this->asUserOnTheFront( $this->ada );
		$this->assertNull( self::supportItem() );
	}
}
