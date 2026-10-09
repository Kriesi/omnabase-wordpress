<?php
/**
 * WooCommerce orders, from either order storage.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Person;

defined( 'ABSPATH' ) || exit;

/**
 * Through `wc_get_orders` (WooCommerce answers it from its own order tables or from posts, whichever the shop uses), the newest 20: a user's
 * by their account, a guest's by the billing address. The link is the order's classic edit address, the same on both storages (WooCommerce
 * forwards it to its newer order screen).
 */
final class WooCommerce implements FactsSource {

	/** {@inheritDoc} */
	public function id(): string {
		return 'woocommerce';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'WooCommerce orders', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'orders';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return function_exists( 'wc_get_orders' );
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		$query  = array( 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' );
		$query += $person->isUser() ? array( 'customer_id' => (int) $person->userId ) : array( 'billing_email' => $person->email );
		$orders = array_filter( (array) wc_get_orders( $query ), static fn ( $o ) => $o instanceof \WC_Order );
		if ( ! $person->isUser() && array() === $orders ) {
			return null;
		}
		return array_values( array_map( array( $this, 'order' ), $orders ) );
	}

	/**
	 * One order in the contract's words.
	 *
	 * @param \WC_Order $order The order.
	 * @return array<string,mixed>
	 */
	private function order( \WC_Order $order ): array {
		$created = $order->get_date_created();
		return array(
			'number' => (string) $order->get_order_number(),
			'date'   => $created ? Format::day( $created->getTimestamp() ) : null,
			'total'  => Format::money( $order->get_total(), (string) $order->get_currency(), wc_get_price_decimals() ),
			'status' => (string) $order->get_status(),
			'href'   => Format::adminLink( 'post.php?post=' . (int) $order->get_id() . '&action=edit' ),
		);
	}
}
