<?php
/**
 * What the site vouches for about a signed-in customer on a form.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Facts\Switches;

defined( 'ABSPATH' ) || exit;

/**
 * Always the user's address and display name (what WordPress itself knows); the newest WooCommerce order's number only while the owner's
 * WooCommerce customer-data switch is on; a licence key only when the user holds exactly one among the licence shops whose switch is on
 * (License Manager for WooCommerce, EDD Software Licensing): with several, the person says which. EDD orders are not vouched for yet. The
 * values are named by the form builder's mappings (`email`, `name`, `order`, `licence`), as OmnaBase's prefill token takes them.
 */
final class Vouched {

	/**
	 * Builds the reader.
	 *
	 * @param Switches $switches The customer-data switches.
	 */
	public function __construct( private Switches $switches = new Switches() ) {}

	/**
	 * The values for a user, by mapping (only those the site knows).
	 *
	 * @param \WP_User $user The signed-in user.
	 * @return array<string,string>
	 */
	public function of( \WP_User $user ): array {
		$values = array(
			'email'   => (string) $user->user_email,
			'name'    => trim( (string) $user->display_name ),
			'order'   => $this->newestOrder( (int) $user->ID ),
			'licence' => $this->onlyLicence( (int) $user->ID ),
		);
		return array_filter( $values, static fn ( string $v ) => '' !== $v );
	}

	/**
	 * The number of the user's newest WooCommerce order ('' without one, without WooCommerce or with its switch off).
	 *
	 * @param int $user The user's id.
	 */
	private function newestOrder( int $user ): string {
		if ( ! function_exists( 'wc_get_orders' ) || ! $this->switches->isOn( 'woocommerce' ) ) {
			return '';
		}
		$orders = (array) wc_get_orders( array( 'customer_id' => $user, 'limit' => 1, 'orderby' => 'date', 'order' => 'DESC', 'return' => 'objects' ) );
		$newest = reset( $orders );
		return $newest instanceof \WC_Order ? (string) $newest->get_order_number() : '';
	}

	/**
	 * The user's licence key when they hold exactly one in the switched-on licence shops ('' otherwise).
	 *
	 * @param int $user The user's id.
	 */
	private function onlyLicence( int $user ): string {
		$keys = array_merge( $this->licenseManagerKeys( $user ), $this->eddKeys( $user ) );
		return 1 === count( $keys ) ? $keys[0] : '';
	}

	/**
	 * License Manager for WooCommerce's keys of a user (none with its switch off).
	 *
	 * @param int $user The user's id.
	 * @return string[]
	 */
	private function licenseManagerKeys( int $user ): array {
		if ( ! function_exists( 'lmfwc_get_licenses' ) || ! $this->switches->isOn( 'lmfw' ) ) {
			return array();
		}
		$licences = array_filter( (array) lmfwc_get_licenses( array( 'user_id' => $user ) ), 'is_object' );
		return self::nonEmpty( array_map( static fn ( object $l ) => method_exists( $l, 'getDecryptedLicenseKey' ) ? (string) $l->getDecryptedLicenseKey() : '', $licences ) );
	}

	/**
	 * EDD Software Licensing's keys of a user (none with its switch off).
	 *
	 * @param int $user The user's id.
	 * @return string[]
	 */
	private function eddKeys( int $user ): array {
		if ( ! function_exists( 'edd_software_licensing' ) || ! $this->switches->isOn( 'edd_software_licensing' ) ) {
			return array();
		}
		$licences = array_filter( (array) edd_software_licensing()->licenses_db->get_licenses( array( 'user_id' => $user, 'number' => 2 ) ), 'is_object' );
		return self::nonEmpty( array_map( static fn ( object $l ) => (string) ( $l->key ?? '' ), $licences ) );
	}

	/**
	 * The non-empty keys, in order.
	 *
	 * @param string[] $keys The keys.
	 * @return string[]
	 */
	private static function nonEmpty( array $keys ): array {
		return array_values( array_filter( array_map( 'trim', $keys ), static fn ( string $k ) => '' !== $k ) );
	}
}
