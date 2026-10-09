<?php
/**
 * EDD Recurring (a paid add-on): subscriptions with product, status and renewal.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Person;

defined( 'ABSPATH' ) || exit;

/**
 * Through the add-on's subscriber (`EDD_Recurring_Subscriber`, by account or by the EDD customer's address), the newest 20.
 */
final class EddRecurring implements FactsSource {

	/** {@inheritDoc} */
	public function id(): string {
		return 'edd_recurring';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'EDD Recurring subscriptions', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'subscriptions';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return class_exists( 'EDD_Recurring' ) && class_exists( 'EDD_Recurring_Subscriber' );
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		if ( $person->isUser() ) {
			$subscriber = new \EDD_Recurring_Subscriber( (int) $person->userId, true );
		} else {
			$customer = EddCustomer::of( $person );
			if ( null === $customer ) {
				return null;
			}
			$subscriber = new \EDD_Recurring_Subscriber( (string) $customer->email );
		}
		$subscriptions = Format::newestFirst( array_values( (array) $subscriber->get_subscriptions() ), static fn ( $s ) => $s->id );
		return array_map( array( $this, 'subscription' ), $subscriptions );
	}

	/**
	 * One subscription in the contract's words.
	 *
	 * @param object $subscription The add-on's subscription.
	 * @return array<string,mixed>
	 */
	private function subscription( object $subscription ): array {
		return array(
			'product'  => (string) get_the_title( (int) $subscription->product_id ),
			'status'   => (string) $subscription->status,
			'renewsAt' => Format::day( (string) $subscription->expiration ),
		);
	}
}
