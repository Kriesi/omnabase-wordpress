<?php
/**
 * The link: telling OmnaBase the site's id for a user it met by address.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Outbox\Job;
use OmnaBase\WordPress\Outbox\Queue;
use OmnaBase\WordPress\Outbox\Step;

defined( 'ABSPATH' ) || exit;

/**
 * When OmnaBase asks about an address that belongs to a user it does not know by id yet, the plugin queues one `PUT
 * /customers/by-external/<site id>` with the user's address and name; once OmnaBase accepted it, the user is known (later calls carry the
 * id, and licence refreshes can reach them). A user OmnaBase asked about by their site id is known already.
 */
final class UserLink implements Job {

	public const KIND = 'user_link';

	/**
	 * Builds the link.
	 *
	 * @param Queue      $queue    The outbox.
	 * @param SiteId     $site_ids The site ids.
	 * @param KnownUsers $known    Who OmnaBase knows.
	 */
	public function __construct( private Queue $queue, private SiteId $site_ids, private KnownUsers $known ) {}

	/**
	 * A facts call found a user: by site id they are known, else the link is queued unless they are known already.
	 *
	 * @param int  $user_id     The user.
	 * @param bool $by_external Whether OmnaBase named them by their site id.
	 */
	public function met( int $user_id, bool $by_external ): void {
		if ( $by_external ) {
			$this->known->mark( $user_id );
		} elseif ( ! $this->known->isKnown( $user_id ) ) {
			$this->queue->add( self::KIND, $user_id );
		}
	}

	/** {@inheritDoc} */
	public function kind(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Tell OmnaBase the site\'s id for a user', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function subject( array $row ): string {
		return KnownUsers::nameOf( (int) $row['subject_id'] );
	}

	/** {@inheritDoc} */
	public function request( array $row ): Step {
		$user = get_userdata( (int) $row['subject_id'] );
		if ( ! $user ) {
			return Step::attention( KnownUsers::goneReason() );
		}
		$path = '/customers/by-external/' . rawurlencode( $this->site_ids->forUser( (int) $user->ID ) );
		return Step::send( 'PUT', $path, array( 'email' => (string) $user->user_email, 'name' => (string) $user->display_name ), 10 );
	}

	/** {@inheritDoc} */
	public function sent( array $row, ?array $answer ): void {
		$this->known->mark( (int) $row['subject_id'] );
	}
}
