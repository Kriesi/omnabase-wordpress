<?php
/**
 * The licence refresh: telling OmnaBase a known user's licences changed.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Sync;

use OmnaBase\WordPress\Facts\Switches;
use OmnaBase\WordPress\Outbox\Job;
use OmnaBase\WordPress\Outbox\Queue;
use OmnaBase\WordPress\Outbox\Step;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /customers/by-external/<site id>/refresh`: OmnaBase then asks the site's customer endpoint about the user during the call and copies
 * the licences it lists, so the call gets 15 seconds (OmnaBase allows the site 5). Queued only for a user OmnaBase knows (anyone else is
 * OmnaBase's 404) and only while a licence source is switched on (the refresh copies licences, nothing else); one waiting row per user.
 */
final class LicenceRefresh implements Job {

	public const KIND = 'licence_refresh';

	private const LICENCE_SOURCES = array( 'edd_software_licensing', 'lmfw' );

	/**
	 * Builds the refresh.
	 *
	 * @param Queue      $queue    The outbox.
	 * @param SiteId     $site_ids The site ids.
	 * @param KnownUsers $known    Who OmnaBase knows.
	 * @param Switches   $switches The switches.
	 */
	public function __construct( private Queue $queue, private SiteId $site_ids, private KnownUsers $known, private Switches $switches ) {}

	/**
	 * A shop event changed a user's licences, orders or subscriptions.
	 *
	 * @param int $user_id The user (0: a guest, nothing to refresh).
	 */
	public function changed( int $user_id ): void {
		if ( $user_id > 0 && $this->licencesShared() && $this->known->isKnown( $user_id ) ) {
			$this->queue->add( self::KIND, $user_id );
		}
	}

	/** {@inheritDoc} */
	public function kind(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Licence refresh', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function subject( array $row ): string {
		return KnownUsers::nameOf( (int) $row['subject_id'] );
	}

	/** {@inheritDoc} */
	public function request( array $row ): Step {
		$site_id = $this->site_ids->forUser( (int) $row['subject_id'] );
		if ( '' === $site_id ) {
			return Step::attention( KnownUsers::goneReason() );
		}
		return Step::send( 'POST', '/customers/by-external/' . rawurlencode( $site_id ) . '/refresh', new \stdClass(), 15 );
	}

	/** {@inheritDoc} */
	public function sent( array $row, ?array $answer ): void {}

	/** Whether a licence source is on. */
	private function licencesShared(): bool {
		foreach ( self::LICENCE_SOURCES as $source ) {
			if ( $this->switches->isOn( $source ) ) {
				return true;
			}
		}
		return false;
	}
}
