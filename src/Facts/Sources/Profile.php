<?php
/**
 * The account: registered since, roles, and the usermeta the administrator ticked.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\MetaPolicy;
use OmnaBase\WordPress\Facts\Person;
use OmnaBase\WordPress\Facts\Switches;

defined( 'ABSPATH' ) || exit;

/**
 * Only for a user with an account. Usermeta only when ticked, never a banned key (the shaper checks again), and only keys the user has.
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
		return __( 'Profile: registered since, roles and the ticked user fields', 'omnabase' );
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
		$meta = array();
		foreach ( $this->switches->meta() as $key ) {
			if ( ! MetaPolicy::isBanned( $key ) && metadata_exists( 'user', (int) $user->ID, $key ) ) {
				$meta[ $key ] = get_user_meta( (int) $user->ID, $key, true );
			}
		}
		return array(
			'registeredAt' => Format::day( (string) $user->user_registered ),
			'roles'        => array_values( (array) $user->roles ),
			'meta'         => $meta,
		);
	}
}
