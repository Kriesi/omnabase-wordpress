<?php
/**
 * The oldest version of each shop plugin the customer-data sources were built against.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * Each source reads its plugin through public functions that older versions may lack or answer differently; an installed version below the
 * minimum is named on the settings screen ("unsupported version"), so a broken customer card has a visible reason. A version the plugin
 * cannot read (not every add-on publishes one) is not judged.
 */
final class ShopVersions {

	/** Per source: where its plugin keeps its version, and the oldest version supported. */
	private const MINIMUMS = array(
		'woocommerce'               => array( 'constant', 'WC_VERSION', '8.2' ),
		'woocommerce_subscriptions' => array( 'property', 'WC_Subscriptions', '5.0' ),
		'edd'                       => array( 'constant', 'EDD_VERSION', '3.0' ),
		'edd_recurring'             => array( 'constant', 'EDD_RECURRING_VERSION', '2.11' ),
		'edd_software_licensing'    => array( 'constant', 'EDD_SL_VERSION', '3.8' ),
		'lmfw'                      => array( 'constant', 'LMFWC_VERSION', '2.2' ),
	);

	/**
	 * The source's installed version and the minimum when it is too old, else null.
	 *
	 * @param string $source The source's id.
	 * @return array{installed:string,minimum:string}|null
	 */
	public function unsupported( string $source ): ?array {
		if ( ! isset( self::MINIMUMS[ $source ] ) ) {
			return null;
		}
		[ $kind, $where, $minimum ] = self::MINIMUMS[ $source ];
		$installed                  = 'constant' === $kind ? self::constant( $where ) : self::property( $where );
		if ( '' === $installed || version_compare( $installed, $minimum, '>=' ) ) {
			return null;
		}
		return array( 'installed' => $installed, 'minimum' => $minimum );
	}

	/**
	 * A version constant's value ('' when undefined).
	 *
	 * @param string $name The constant.
	 */
	private static function constant( string $name ): string {
		return defined( $name ) ? (string) constant( $name ) : '';
	}

	/**
	 * A class's public static `$version` ('' when missing), as WooCommerce Subscriptions keeps it.
	 *
	 * @param string $class The class.
	 */
	private static function property( string $class ): string {
		return class_exists( $class ) && property_exists( $class, 'version' ) ? (string) $class::$version : '';
	}
}
