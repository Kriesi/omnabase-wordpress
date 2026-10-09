<?php
/**
 * Who OmnaBase asks about: the site id first, then the address.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

use OmnaBase\WordPress\Sync\SiteId;

defined( 'ABSPATH' ) || exit;

/**
 * `customer.external` wins when it names a user of this site (the site id), even when the ticket came from another address: a customer
 * writing from a colleague's address must not see the colleague's orders. Else the WordPress user with that address, else a guest by that
 * address. No address and no id names nobody.
 */
final class Identity {

	/**
	 * Builds the lookup.
	 *
	 * @param SiteId $site_ids The site ids.
	 */
	public function __construct( private SiteId $site_ids ) {}

	/**
	 * The person, or null when the call names nobody this site could look up.
	 *
	 * @param string      $email    The ticket's address.
	 * @param string|null $external This site's id for the person, when OmnaBase knows it.
	 */
	public function resolve( string $email, ?string $external ): ?Person {
		$user_id = null !== $external && '' !== $external ? $this->site_ids->userFor( $external ) : null;
		if ( null !== $user_id ) {
			$user = get_userdata( $user_id );
			return $user ? new Person( $user_id, (string) $user->user_email, true ) : null;
		}
		$email = sanitize_email( $email );
		if ( '' === $email ) {
			return null;
		}
		$user = get_user_by( 'email', $email );
		return $user ? new Person( (int) $user->ID, (string) $user->user_email, false ) : new Person( null, $email, false );
	}
}
