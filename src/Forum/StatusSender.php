<?php
/**
 * A topic closed or opened in the forum moves its ticket: `PATCH /tickets/{number}`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Job;
use OmnaBase\WordPress\Outbox\Step;

defined( 'ABSPATH' ) || exit;

/**
 * The outbox row `forum_status` (one per topic, the forum sync round, 2026-11-02). When its turn comes the topic is read again and its state
 * then is sent, so a close and a reopen within a minute send "open" once: a closed topic sends `{ "status": "resolved" }`, an open one
 * `{ "status": "open" }`. A topic that is gone, no ticket, or neither open nor closed (spam, the bin, held for moderation) sends nothing.
 * OmnaBase treats the status the ticket already has as no change, and never sends the change back to this site.
 */
final class StatusSender implements Job {

	public const KIND = 'forum_status';

	/** Seconds for the whole call. */
	private const TIMEOUT = 15;

	/** {@inheritDoc} */
	public function kind(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Forum topic closed or opened', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function subject( array $row ): string {
		$title = get_the_title( (int) $row['subject_id'] );
		return '' !== $title ? $title : '#' . (int) $row['subject_id'];
	}

	/** {@inheritDoc} */
	public function request( array $row ): Step {
		$topic  = get_post( (int) $row['subject_id'] );
		$number = Posts::isTopic( $topic ) ? Posts::ticketOf( $topic->ID ) : 0;
		if ( $number < 1 ) {
			return Step::skip( __( 'Not sent: the topic was removed or is no ticket.', 'omnabase' ) );
		}
		$status = self::statusOf( $topic );
		if ( null === $status ) {
			return Step::skip( __( 'Not sent: the topic is neither open nor closed now.', 'omnabase' ) );
		}
		return Step::send( 'PATCH', '/tickets/' . $number, array( 'status' => $status ), self::TIMEOUT );
	}

	/** {@inheritDoc} */
	public function sent( array $row, ?array $answer ): void {}

	/**
	 * The ticket's status for the topic's state now, or null when it has none to send.
	 *
	 * @param \WP_Post $topic The topic.
	 */
	private static function statusOf( \WP_Post $topic ): ?string {
		return match ( $topic->post_status ) {
			bbp_get_closed_status_id() => 'resolved',
			bbp_get_public_status_id() => 'open',
			default                    => null,
		};
	}
}
