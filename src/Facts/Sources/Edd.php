<?php
/**
 * Easy Digital Downloads 3 orders.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Person;

defined( 'ABSPATH' ) || exit;

/**
 * Through EDD 3's `edd_get_orders`, the newest 20 sales of the person's EDD customer (found by account or by any of its addresses).
 */
final class Edd implements FactsSource {

	/** {@inheritDoc} */
	public function id(): string {
		return 'edd';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Easy Digital Downloads orders', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'orders';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return function_exists( 'edd_get_orders' ) && function_exists( 'edd_get_customer_by' );
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		$customer = EddCustomer::of( $person );
		if ( null === $customer ) {
			return $person->isUser() ? array() : null;
		}
		$orders = (array) edd_get_orders(
			array(
				'customer_id' => (int) $customer->id,
				'type'        => 'sale',
				'number'      => 20,
				'orderby'     => 'date_created',
				'order'       => 'DESC',
			)
		);
		return array_values( array_map( array( $this, 'order' ), $orders ) );
	}

	/**
	 * One order in the contract's words.
	 *
	 * @param object $order EDD's order.
	 * @return array<string,mixed>
	 */
	private function order( object $order ): array {
		$currency = (string) $order->currency;
		$decimals = function_exists( 'edd_currency_decimal_filter' ) ? (int) edd_currency_decimal_filter( 2, $currency ) : 2;
		$created  = strtotime( (string) $order->date_created . ' UTC' );
		return array(
			'number' => (string) $order->get_number(),
			'date'   => false !== $created ? Format::day( $created ) : null,
			'total'  => Format::money( $order->total, $currency, $decimals ),
			'status' => (string) $order->status,
			'href'   => Format::adminLink( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id=' . (int) $order->id ),
		);
	}
}
