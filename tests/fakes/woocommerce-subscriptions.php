<?php
/**
 * A fake of WooCommerce Subscriptions (a paid add-on) for the tests, never shipped: the few names the plugin reads, shaped like the real
 * add-on's public API. Inert when the real add-on is loaded (the founder's zip in `paid/`).
 *
 * The real names it stands in for: the main class `WC_Subscriptions` (how the plugin detects the add-on); `wcs_get_users_subscriptions(
 * $user_id )` (a user's subscriptions, keyed by id); `wcs_get_subscription( $id )`; a subscription's `get_id()`, `get_status()` ("active",
 * without "wc-"), `get_date( 'next_payment' )` ("Y-m-d H:i:s" in UTC, or 0), `get_customer_id()`, `get_user_id()`, `get_items()` (each with
 * `get_name()`), `get_edit_order_url()`; the action `woocommerce_subscription_status_updated( $subscription, $new_status, $old_status )`.
 *
 * Its records live in the option `omnabase_fake_wcs` (a list of rows: id, user_id, status, next_payment, items, edit_url), so the
 * integration tests and the wp-env seed write them the same way.
 *
 * @package OmnaBase
 */

// phpcs:ignoreFile -- a test fake named after the real add-on's classes and functions.

if ( class_exists( 'WC_Subscriptions' ) ) {
	return;
}

final class WC_Subscriptions {
	public static $version = '7.0.0-omnabase-fake';
}

final class WC_Subscription {
	private array $row;

	public function __construct( array $row ) {
		$this->row = $row + array( 'id' => 0, 'user_id' => 0, 'status' => 'active', 'next_payment' => '', 'items' => array(), 'edit_url' => '' );
	}

	public function get_id(): int {
		return (int) $this->row['id'];
	}

	public function get_status(): string {
		return (string) $this->row['status'];
	}

	/** @return string|int */
	public function get_date( string $type ) {
		return 'next_payment' === $type && '' !== (string) $this->row['next_payment'] ? (string) $this->row['next_payment'] : 0;
	}

	public function get_customer_id(): int {
		return (int) $this->row['user_id'];
	}

	public function get_user_id(): int {
		return (int) $this->row['user_id'];
	}

	public function get_items(): array {
		$items = array();
		foreach ( (array) $this->row['items'] as $i => $name ) {
			$items[ $i + 1 ] = new class( (string) $name ) {
				public function __construct( private string $name ) {}
				public function get_name(): string {
					return $this->name;
				}
			};
		}
		return $items;
	}

	public function get_edit_order_url(): string {
		return (string) $this->row['edit_url'];
	}
}

/** @return array<int,WC_Subscription> */
function wcs_get_users_subscriptions( $user_id = 0 ) {
	$out = array();
	foreach ( (array) get_option( 'omnabase_fake_wcs', array() ) as $row ) {
		if ( (int) ( $row['user_id'] ?? 0 ) === (int) $user_id ) {
			$out[ (int) $row['id'] ] = new WC_Subscription( $row );
		}
	}
	return $out;
}

/** @return WC_Subscription|false */
function wcs_get_subscription( $id ) {
	foreach ( (array) get_option( 'omnabase_fake_wcs', array() ) as $row ) {
		if ( (int) ( $row['id'] ?? 0 ) === (int) $id ) {
			return new WC_Subscription( $row );
		}
	}
	return false;
}
