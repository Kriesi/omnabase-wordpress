<?php
/**
 * The EDD customer a person is: by their account, else by any of the customer's addresses.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\Person;

defined( 'ABSPATH' ) || exit;

/**
 * EDD keeps its own record of a buyer (an "EDD customer"), which can hold several addresses and need not be a WordPress user. EDD's
 * `edd_get_customer_by( 'email' )` searches all of a customer's addresses, so a buyer writing from their second address is found.
 */
final class EddCustomer {

	/**
	 * The customer, or null.
	 *
	 * @param Person $person Who OmnaBase asks about.
	 */
	public static function of( Person $person ): ?object {
		if ( ! function_exists( 'edd_get_customer_by' ) ) {
			return null;
		}
		$customer = $person->isUser() ? edd_get_customer_by( 'user_id', (int) $person->userId ) : false;
		if ( ! $customer && '' !== $person->email ) {
			$customer = edd_get_customer_by( 'email', $person->email );
		}
		return is_object( $customer ) ? $customer : null;
	}
}
