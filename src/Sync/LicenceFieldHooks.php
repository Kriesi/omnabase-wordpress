<?php
/**
 * The licence field's changes (added, updated, deleted), each queuing one refresh like a shop event.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Facts\Sources\UserMetaLicence;
use OmnaBase\WordPress\Facts\Switches;

defined( 'ABSPATH' ) || exit;

/**
 * The licence-sources round (2026-11-03): WordPress's three user-field moments (hooks: named moments where a plugin's code runs). A change
 * of the field named as the licence, while its source is on, hands the user to `LicenceRefresh::changed` (which keeps its own guards: a user
 * OmnaBase knows, one waiting row per user). A user being deleted queues nothing: WordPress removes their fields one by one first, and
 * OmnaBase learns of the deletion otherwise. On a network, a field written on another site of it fires no moment here (DECISIONS.md).
 */
final class LicenceFieldHooks {

	/** The user WordPress is deleting right now, or 0. */
	private int $leaving = 0;

	/**
	 * Builds the hooks.
	 *
	 * @param LicenceRefresh $refresh  The refresh.
	 * @param Switches       $switches The licence field's settings.
	 */
	public function __construct( private LicenceRefresh $refresh, private Switches $switches ) {}

	/** Registers the hooks. */
	public function register(): void {
		add_action( 'added_user_meta', array( $this, 'changed' ), 20, 3 );
		add_action( 'updated_user_meta', array( $this, 'changed' ), 20, 3 );
		add_action( 'deleted_user_meta', array( $this, 'changed' ), 20, 3 );
		add_action( 'delete_user', array( $this, 'leaving' ), 10, 1 );
		add_action( 'wpmu_delete_user', array( $this, 'leaving' ), 10, 1 );
	}

	/**
	 * A user field was added, updated or deleted.
	 *
	 * @param mixed  $meta_id  The field's row (or rows, when deleted).
	 * @param int    $user_id  The user.
	 * @param string $meta_key The field's name.
	 */
	public function changed( $meta_id, $user_id, $meta_key ): void {
		if ( (int) $user_id !== $this->leaving && $this->isLicenceField( (string) $meta_key ) ) {
			$this->refresh->changed( (int) $user_id );
		}
	}

	/**
	 * WordPress starts deleting a user.
	 *
	 * @param int $user_id The user.
	 */
	public function leaving( $user_id ): void {
		$this->leaving = (int) $user_id;
	}

	/**
	 * Whether the field is the one named as the licence, with its source on.
	 *
	 * @param string $meta_key The field's name.
	 */
	private function isLicenceField( string $meta_key ): bool {
		$key = $this->switches->licenceMeta()['key'];
		return '' !== $key && $key === $meta_key && $this->switches->isOn( UserMetaLicence::ID );
	}
}
