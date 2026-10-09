<?php
/**
 * A fake of EDD Recurring (a paid add-on) for the tests, never shipped: the few names the plugin reads, shaped like the real add-on's
 * public API. Inert when the real add-on is loaded (the founder's zip in `paid/`).
 *
 * The real names it stands in for: the main class `EDD_Recurring` (detection); `new EDD_Recurring_Subscriber( $user_id, true )` (the
 * subscriber found by WordPress user id; `new EDD_Recurring_Subscriber( $email )` by address) and its `get_subscriptions( $product_id = 0,
 * $statuses = array() )`; a subscription's public `id`, `customer_id`, `product_id` (a download: its name through EDD's own
 * `edd_get_download_name()` or the post title), `status` ("active", "cancelled", "expired" …) and `expiration` ("Y-m-d H:i:s").
 *
 * Its records live in the option `omnabase_fake_edd_recurring` (a list of rows: id, user_id, email, customer_id, product_id, status,
 * expiration).
 *
 * @package OmnaBase
 */

// phpcs:ignoreFile -- a test fake named after the real add-on's classes.

if ( class_exists( 'EDD_Recurring' ) ) {
	return;
}

final class EDD_Recurring {
	public static $plugin_path = '';
}

class EDD_Subscription {
	public $id          = 0;
	public $customer_id = 0;
	public $product_id  = 0;
	public $status      = 'active';
	public $expiration  = '';

	public function __construct( array $row = array() ) {
		foreach ( array( 'id', 'customer_id', 'product_id', 'status', 'expiration' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$this->$field = $row[ $field ];
			}
		}
	}
}

class EDD_Recurring_Subscriber {
	public $id      = 0;
	public $user_id = 0;
	public $email   = '';

	/**
	 * @param int|string $id_or_email A user id (with $by_user_id) or an address.
	 * @param bool       $by_user_id  Whether the first argument is a WordPress user id.
	 */
	public function __construct( $id_or_email = 0, $by_user_id = false ) {
		if ( $by_user_id ) {
			$this->user_id = (int) $id_or_email;
		} else {
			$this->email = (string) $id_or_email;
		}
	}

	/** @return EDD_Subscription[] */
	public function get_subscriptions( $product_id = 0, $statuses = array() ) {
		$out = array();
		foreach ( (array) get_option( 'omnabase_fake_edd_recurring', array() ) as $row ) {
			$mine = $this->user_id ? (int) ( $row['user_id'] ?? 0 ) === $this->user_id : ( '' !== $this->email && strtolower( (string) ( $row['email'] ?? '' ) ) === strtolower( $this->email ) );
			if ( $mine && ( ! $product_id || (int) $row['product_id'] === (int) $product_id ) && ( ! $statuses || in_array( $row['status'] ?? '', (array) $statuses, true ) ) ) {
				$out[] = new EDD_Subscription( $row );
			}
		}
		return $out;
	}
}
