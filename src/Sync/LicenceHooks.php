<?php
/**
 * The shop events that change a customer's licences, each queuing one refresh.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks (named moments where a plugin's code runs) of each shop, verified in round two: WooCommerce's order status change (both order
 * storages fire it), WooCommerce Subscriptions' status change, EDD 3's order status change, EDD Software Licensing's status change and
 * activation, and License Manager for WooCommerce's delivery of an order's keys (its free version 3.0.20 fires nothing when a licence is
 * edited or activated; an order's status change covers the rest). Each callback only finds the user and hands over: thin, never a call to
 * OmnaBase inside the shop's own step.
 */
final class LicenceHooks {

	/**
	 * Builds the hooks.
	 *
	 * @param LicenceRefresh $refresh The refresh.
	 */
	public function __construct( private LicenceRefresh $refresh ) {}

	/** Registers the hooks. */
	public function register(): void {
		add_action( 'woocommerce_order_status_changed', array( $this, 'wooOrder' ), 20, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'wooSubscription' ), 20, 1 );
		add_action( 'edd_transition_order_status', array( $this, 'eddOrder' ), 20, 3 );
		add_action( 'edd_sl_post_set_status', array( $this, 'eddLicence' ), 20, 1 );
		add_action( 'edd_sl_activate_license', array( $this, 'eddLicence' ), 20, 1 );
		add_action( 'lmfwc_event_post_order_license_keys', array( $this, 'licenseManagerKeys' ), 20, 1 );
	}

	/**
	 * A WooCommerce order changed status.
	 *
	 * @param int $order_id The order.
	 */
	public function wooOrder( $order_id ): void {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : false;
		$this->refresh->changed( $order ? (int) $order->get_customer_id() : 0 );
	}

	/**
	 * A WooCommerce subscription changed status.
	 *
	 * @param object $subscription The subscription.
	 */
	public function wooSubscription( $subscription ): void {
		$this->refresh->changed( is_object( $subscription ) && method_exists( $subscription, 'get_user_id' ) ? (int) $subscription->get_user_id() : 0 );
	}

	/**
	 * An EDD order changed status.
	 *
	 * @param string $old_status The old status.
	 * @param string $new_status The new status.
	 * @param int    $order_id   The order.
	 */
	public function eddOrder( $old_status, $new_status, $order_id ): void {
		$order = function_exists( 'edd_get_order' ) ? edd_get_order( (int) $order_id ) : false;
		$this->refresh->changed( $order ? (int) $order->user_id : 0 );
	}

	/**
	 * An EDD Software Licensing licence changed status or was activated.
	 *
	 * @param int $licence_id The licence.
	 */
	public function eddLicence( $licence_id ): void {
		$licence = function_exists( 'edd_software_licensing' ) ? edd_software_licensing()->get_license( (int) $licence_id ) : false;
		$this->refresh->changed( is_object( $licence ) ? (int) $licence->user_id : 0 );
	}

	/**
	 * License Manager delivered an order's keys.
	 *
	 * @param array{orderId?:int} $event The event.
	 */
	public function licenseManagerKeys( $event ): void {
		$order = function_exists( 'wc_get_order' ) && is_array( $event ) ? wc_get_order( (int) ( $event['orderId'] ?? 0 ) ) : false;
		$this->refresh->changed( $order ? (int) $order->get_customer_id() : 0 );
	}
}
