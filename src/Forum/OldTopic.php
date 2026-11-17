<?php
/**
 * An old topic becomes a ticket at its next reply (the old-topics-and-undelivered round, 2026-11-16).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * One rule, two doors (the reply hook and the resend command): a reply on a topic in a marked forum that is no ticket, with none on its way,
 * queues the topic's own row first when the writer is the topic's author or another member, or a guest where guests are switched on. A
 * moderator's reply never starts it; nor does a topic that the topic job would skip anyway (unpublished, a guest's while guests are off).
 * The reply's own row is queued after it by the caller and waits behind its topic through the reply job's existing "wait for the topic".
 */
final class OldTopic {

	/**
	 * Builds the rule.
	 *
	 * @param Settings $settings The forum settings.
	 * @param Queue    $queue    The outbox.
	 */
	public function __construct( private Settings $settings, private Queue $queue ) {}

	/**
	 * Queues the reply's topic when the reply starts it; true when a topic row was queued.
	 *
	 * @param \WP_Post $reply The reply.
	 */
	public function fileTopicOf( \WP_Post $reply ): bool {
		$topic = get_post( Posts::topicOf( $reply ) );
		if ( ! $topic instanceof \WP_Post || ! $this->needsFiling( $topic ) || ! $this->starts( $reply ) ) {
			return false;
		}
		return $this->queue->add( TopicSender::KIND, $topic->ID );
	}

	/**
	 * Whether a topic is no ticket, none is on its way, and the topic job would send it.
	 *
	 * @param \WP_Post $topic The topic.
	 */
	private function needsFiling( \WP_Post $topic ): bool {
		if ( ! Posts::isTopic( $topic ) || ! in_array( $topic->post_status, Posts::shownTopicStatuses(), true ) || Posts::ticketOf( $topic->ID ) > 0 ) {
			return false;
		}
		if ( ! $this->settings->isMarked( Posts::forumOf( $topic->ID ) ) || ( 0 === (int) $topic->post_author && ! $this->settings->guests() ) ) {
			return false;
		}
		return ! $this->queue->isOpen( TopicSender::KIND . ':' . $topic->ID );
	}

	/**
	 * Whether the reply's writer starts the topic: anyone but a moderator; a guest only where guests are switched on.
	 *
	 * @param \WP_Post $reply The reply.
	 */
	private function starts( \WP_Post $reply ): bool {
		$author = (int) $reply->post_author;
		if ( 0 === $author ) {
			return $this->settings->guests();
		}
		return ! Settings::canModerate( $author );
	}
}
