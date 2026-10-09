<?php
/**
 * License Manager for WooCommerce: licences with product, masked key, status and expiry.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\Field;
use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Person;

defined( 'ABSPATH' ) || exit;

/**
 * Through its public `lmfwc_get_licenses`, a user's licences, the newest 20. Its free version records an activation count, not site names,
 * so no `sites`. The key is masked here already. Its numeric statuses in words.
 */
final class LicenseManager implements FactsSource {

	private const STATUSES = array( 1 => 'sold', 2 => 'delivered', 3 => 'active', 4 => 'inactive' );

	/** {@inheritDoc} */
	public function id(): string {
		return 'lmfw';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'License Manager for WooCommerce licences', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'licences';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return function_exists( 'lmfwc_get_licenses' );
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		if ( ! $person->isUser() ) {
			return null;
		}
		$licences = array_filter( (array) lmfwc_get_licenses( array( 'user_id' => (int) $person->userId ) ), 'is_object' );
		return array_map( array( $this, 'licence' ), Format::newestFirst( array_values( $licences ), static fn ( $l ) => $l->getId() ) );
	}

	/**
	 * One licence in the contract's words.
	 *
	 * @param object $licence License Manager's licence.
	 * @return array<string,mixed>
	 */
	private function licence( object $licence ): array {
		return array(
			'product'   => (string) get_the_title( (int) $licence->getProductId() ),
			'keyMasked' => Field::maskedKey( (string) $licence->getDecryptedLicenseKey() ),
			'status'    => self::STATUSES[ (int) $licence->getStatus() ] ?? '',
			'expiresAt' => Format::day( (string) $licence->getExpiresAt() ),
			'href'      => Format::adminLink( 'admin.php?page=lmfwc_licenses&action=edit&id=' . (int) $licence->getId() ),
		);
	}
}
