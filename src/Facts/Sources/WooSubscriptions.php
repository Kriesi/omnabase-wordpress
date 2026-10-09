<?php
/**
 * WooCommerce Subscriptions (a paid add-on): product, status, next renewal.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Person;

defined( 'ABSPATH' ) || exit;

/**
 * Through the add-on's `wcs_get_users_subscriptions` (subscriptions always belong to an account), the newest 20.
 */
final class WooSubscriptions implements FactsSource {

	/** {@inheritDoc} */
	public function id(): string {
		return 'woocommerce_subscriptions';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'WooCommerce Subscriptions', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'subscriptions';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return class_exists( 'WC_Subscriptions' ) && function_exists( 'wcs_get_users_subscriptions' );
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		if ( ! $person->isUser() ) {
			return null;
		}
		$subscriptions = Format::newestFirst( array_values( (array) wcs_get_users_subscriptions( (int) $person->userId ) ), static fn ( $s ) => $s->get_id() );
		return array_map( array( $this, 'subscription' ), $subscriptions );
	}

	/**
	 * One subscription in the contract's words.
	 *
	 * @param object $subscription The add-on's subscription.
	 * @return array<string,mixed>
	 */
	private function subscription( object $subscription ): array {
		$names = array_map( static fn ( $item ) => (string) $item->get_name(), array_values( (array) $subscription->get_items() ) );
		return array(
			'product'  => implode( ', ', $names ),
			'status'   => (string) $subscription->get_status(),
			'renewsAt' => Format::day( $subscription->get_date( 'next_payment' ) ),
			'href'     => (string) $subscription->get_edit_order_url(),
		);
	}
}
