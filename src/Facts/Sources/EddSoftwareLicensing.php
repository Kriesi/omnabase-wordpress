<?php
/**
 * EDD Software Licensing (a paid add-on): licences with product, masked key, status, version, expiry and activated sites.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\Field;
use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Person;

defined( 'ABSPATH' ) || exit;

/**
 * Through the add-on's licence table (`edd_software_licensing()->licenses_db`), the newest 20 of the user's licences, or of the EDD
 * customer's for a guest. The key is masked here already, so the full key never leaves this class. A lifetime licence has no expiry.
 */
final class EddSoftwareLicensing implements FactsSource {

	/** {@inheritDoc} */
	public function id(): string {
		return 'edd_software_licensing';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'EDD Software Licensing licences', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'licences';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return class_exists( 'EDD_Software_Licensing' ) && function_exists( 'edd_software_licensing' );
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		$query = array( 'number' => 20, 'orderby' => 'id', 'order' => 'DESC' );
		if ( $person->isUser() ) {
			$query['user_id'] = (int) $person->userId;
		} else {
			$customer = EddCustomer::of( $person );
			if ( null === $customer ) {
				return null;
			}
			$query['customer_id'] = (int) $customer->id;
		}
		$licences = (array) edd_software_licensing()->licenses_db->get_licenses( $query );
		return array_values( array_map( array( $this, 'licence' ), array_filter( $licences, 'is_object' ) ) );
	}

	/**
	 * One licence in the contract's words.
	 *
	 * @param object $licence The add-on's licence.
	 * @return array<string,mixed>
	 */
	private function licence( object $licence ): array {
		$download   = $licence->get_download();
		$expiration = (int) $licence->expiration;
		return array(
			'product'   => (string) $download->get_name(),
			'keyMasked' => Field::maskedKey( (string) $licence->key ),
			'status'    => (string) $licence->status,
			'version'   => (string) $download->get_version(),
			'expiresAt' => $expiration > 0 ? Format::day( $expiration ) : null,
			'sites'     => array_values( array_map( 'strval', (array) $licence->sites ) ),
			'href'      => Format::adminLink( 'edit.php?post_type=download&page=edd-licenses&view=overview&license_id=' . (int) $licence->ID ),
		);
	}
}
