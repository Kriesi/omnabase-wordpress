<?php
/**
 * Adds rows to the outbox and asks for a run soon.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * One waiting row per dedupe key (two changes for one customer before the row went make one call; a change after it went makes a new one).
 * A new row is tried at once, but never inside the request that made it (a customer's checkout must not wait for OmnaBase): a one-off run is
 * scheduled for now, and WordPress's timer is woken at the end of the request.
 */
final class Queue {

	/**
	 * Builds the queue.
	 *
	 * @param Table    $table    The table.
	 * @param Schedule $schedule The timer.
	 */
	public function __construct( private Table $table, private Schedule $schedule ) {}

	/**
	 * Queues a call about a user; false when the same call waits already.
	 *
	 * @param string $kind    The row's kind.
	 * @param int    $user_id The user.
	 */
	public function add( string $kind, int $user_id ): bool {
		$added = $this->table->add( $kind, $user_id, $kind . ':' . $user_id, time() );
		if ( $added ) {
			$this->schedule->soon();
		}
		return $added;
	}
}
