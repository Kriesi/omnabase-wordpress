<?php
/**
 * A listener's "not now" for the events receiver: the delivery is answered with this status and OmnaBase tries again.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a webhook listener that could not do its work (the forum's topic is gone: 409; no support user: 503; WordPress did not save the
 * reply: 500). The receiver answers the status, logs the delivery as refused and does not record it as handled, so a retry or a
 * "Redeliver" does the work once the cause is gone. Listeners only ever choose a status from 400 to 599 that keeps the address on.
 */
final class Refusal extends \RuntimeException {

	/**
	 * Builds the refusal.
	 *
	 * @param int    $status The answer's status.
	 * @param string $reason Why, in words (answered to OmnaBase, never content).
	 */
	public function __construct( public readonly int $status, string $reason ) {
		parent::__construct( $reason );
	}
}
