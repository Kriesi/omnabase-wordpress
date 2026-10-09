<?php
/**
 * A user's changed address, told to OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Outbox\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * At `profile_update` (WordPress's moment after a user was saved: the profile screen, WP-CLI, WooCommerce's account edit, which saves
 * through the same function) a new address queues one `PUT /customers/by-external/<site id>` through the outbox (the link's row, its body
 * built from the user when it is sent), but only for a user OmnaBase knows: the plugin never makes every user a customer. OmnaBase adds the
 * address unconfirmed and clears the confirmation, so the message centre asks again; the message centre's fingerprint is forgotten at once.
 * A save without a new address (another field, the same address in other letters) sends nothing.
 */
final class ProfileWatcher {

	/**
	 * Builds the watcher.
	 *
	 * @param Queue      $queue The outbox.
	 * @param KnownUsers $known Who OmnaBase knows.
	 */
	public function __construct( private Queue $queue, private KnownUsers $known ) {}

	/** Registers the hook. */
	public function register(): void {
		add_action( 'profile_update', array( $this, 'updated' ), 10, 2 );
	}

	/**
	 * A user was saved.
	 *
	 * @param int           $user_id The user.
	 * @param \WP_User|null $before  The user as they were.
	 */
	public function updated( $user_id, $before = null ): void {
		$user_id = (int) $user_id;
		$after   = get_userdata( $user_id );
		if ( ! $before instanceof \WP_User || ! $after instanceof \WP_User || self::same( (string) $before->user_email, (string) $after->user_email ) ) {
			return;
		}
		delete_user_meta( $user_id, Options::PORTAL_FINGERPRINT_META );
		if ( $this->known->isKnown( $user_id ) ) {
			$this->queue->add( UserLink::KIND, $user_id );
		}
	}

	/**
	 * Whether two addresses are the same as OmnaBase compares them.
	 *
	 * @param string $a One.
	 * @param string $b The other.
	 */
	private static function same( string $a, string $b ): bool {
		return strtolower( trim( $a ) ) === strtolower( trim( $b ) );
	}
}
