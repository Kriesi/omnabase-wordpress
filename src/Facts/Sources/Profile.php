<?php
/**
 * The account: registered since, roles, and the usermeta the administrator ticked.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\BillingCountry;
use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\MetaPolicy;
use OmnaBase\WordPress\Facts\Person;
use OmnaBase\WordPress\Facts\Switches;

defined( 'ABSPATH' ) || exit;

/**
 * Only for a user with an account. Usermeta only when ticked, never a banned key (the shaper checks again), and only keys the user has. The
 * customer sidebar round (2026-11-01): the billing country (`BillingCountry`) when the shop knows one; no language. The licence-sources
 * round (2026-11-03): never the field named as the licence.
 */
final class Profile implements FactsSource {

	/**
	 * Builds the source.
	 *
	 * @param Switches $switches The ticked usermeta.
	 */
	public function __construct( private Switches $switches ) {}

	/** {@inheritDoc} */
	public function id(): string {
		return 'profile';
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Profile: registered since, roles, billing country and the ticked user fields', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function section(): string {
		return 'profile';
	}

	/** {@inheritDoc} */
	public function isAvailable(): bool {
		return true;
	}

	/** {@inheritDoc} */
	public function collect( Person $person ): ?array {
		$user = $person->isUser() ? get_userdata( (int) $person->userId ) : false;
		if ( ! $user ) {
			return null;
		}
		$profile = array(
			'registeredAt' => Format::day( (string) $user->user_registered ),
			'roles'        => array_values( (array) $user->roles ),
			'meta'         => $this->meta( (int) $user->ID ),
		);
		$country = BillingCountry::ofUser( (int) $user->ID );
		return null === $country ? $profile : $profile + array( 'country' => $country );
	}

	/**
	 * The ticked usermeta the user has: never a banned key, never the field named as the licence (only its masked form leaves, through
	 * `UserMetaLicence`), even when its name looks harmless and it was ticked.
	 *
	 * @param int $user_id The user.
	 * @return array<string,mixed>
	 */
	private function meta( int $user_id ): array {
		$licence = $this->switches->licenceMeta()['key'];
		$meta    = array();
		foreach ( $this->switches->meta() as $key ) {
			if ( $key !== $licence && ! MetaPolicy::isBanned( $key ) && metadata_exists( 'user', $user_id, $key ) ) {
				$meta[ $key ] = get_user_meta( $user_id, $key, true );
			}
		}
		return $meta;
	}
}
