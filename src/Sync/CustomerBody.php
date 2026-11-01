<?php
/**
 * What the site tells OmnaBase about one of its users.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Facts\BillingCountry;

defined( 'ABSPATH' ) || exit;

/**
 * One body for both calls that send `PUT /customers/by-external/<site id>` (the outbox's first link and the message centre's own call):
 * the address, the name when there is one, and (the customer sidebar round, 2026-11-01) the billing country when the shop knows one. A key
 * without a value is left out, never sent empty: OmnaBase empties a field it is sent as null.
 */
final class CustomerBody {

	/**
	 * The body for a user.
	 *
	 * @param \WP_User $user The user.
	 * @return array<string,string>
	 */
	public static function of( \WP_User $user ): array {
		$body    = array( 'email' => trim( (string) $user->user_email ) );
		$name    = trim( (string) $user->display_name );
		$country = BillingCountry::ofUser( (int) $user->ID );
		if ( '' !== $name ) {
			$body['name'] = $name;
		}
		if ( null !== $country ) {
			$body['country'] = $country;
		}
		return $body;
	}
}
