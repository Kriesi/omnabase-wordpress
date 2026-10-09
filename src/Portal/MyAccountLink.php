<?php
/**
 * WooCommerce's My Account "Support" link to the message centre.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Forms\Display;

defined( 'ABSPATH' ) || exit;

/**
 * While the administrator's switch is on and a published page is picked, My Account's menu (WooCommerce's `woocommerce_account_menu_items`
 * list) gets "Support" before "Log out", and WooCommerce's address for that entry (`woocommerce_get_endpoint_url`) is the picked page's own
 * address. Otherwise the menu stays as WooCommerce made it: no link to nowhere.
 */
final class MyAccountLink {

	public const ITEM   = 'omnabase-support';
	private const LOGOUT = 'customer-logout';

	/**
	 * Builds the link.
	 *
	 * @param PickedPage $page    The message centre's page.
	 * @param Display    $display The switch.
	 */
	public function __construct( private PickedPage $page, private Display $display ) {}

	/** Registers the two WooCommerce filters (inert without WooCommerce). */
	public function register(): void {
		add_filter( 'woocommerce_account_menu_items', array( $this, 'addItem' ), 20 );
		add_filter( 'woocommerce_get_endpoint_url', array( $this, 'url' ), 10, 2 );
	}

	/**
	 * Adds "Support" before "Log out" (at the end when there is none).
	 *
	 * @param mixed $items WooCommerce's menu, key => label.
	 * @return mixed
	 */
	public function addItem( $items ) {
		if ( ! is_array( $items ) || '' === $this->target() ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			if ( self::LOGOUT === $key ) {
				$out[ self::ITEM ] = __( 'Support', 'omnabase' );
			}
			$out[ $key ] = $label;
		}
		$out[ self::ITEM ] ??= __( 'Support', 'omnabase' );
		return $out;
	}

	/**
	 * The entry's address: the picked page.
	 *
	 * @param mixed $url      WooCommerce's address.
	 * @param mixed $endpoint The entry's key.
	 * @return mixed
	 */
	public function url( $url, $endpoint ) {
		$target = self::ITEM === $endpoint ? $this->target() : '';
		return '' === $target ? $url : $target;
	}

	/** The picked page's address while the switch is on and the page is published, else ''. */
	private function target(): string {
		$id = $this->page->id();
		if ( ! $this->display->myAccount() || $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return '';
		}
		return (string) get_permalink( $id );
	}
}
