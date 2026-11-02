<?php
/**
 * The moments a filed topic is closed or opened in the forum: each queues the topic's one status row.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks (named moments where a plugin's code runs), the forum sync round (2026-11-02): bbPress's Close and Open on the forum page
 * (`bbp_closed_topic`, `bbp_opened_topic`) and WordPress's `transition_post_status` between `publish` and `closed` (the status set in
 * wp-admin's editor; bbPress's own Close fires it too). Only a topic that is a ticket queues, and never while the plugin itself closes or
 * reopens it on OmnaBase's word. The outbox keeps one waiting row per topic, so the two moments of one close make one row, and the row sends
 * the topic's state when it goes (`StatusSender`). A topic moved to spam or the bin, or held for moderation, sends nothing.
 */
final class StatusTriggers {

	/**
	 * Builds the triggers.
	 *
	 * @param Queue $queue The outbox.
	 */
	public function __construct( private Queue $queue ) {}

	/** Registers the three moments. */
	public function register(): void {
		add_action( 'bbp_closed_topic', array( $this, 'changed' ), 20, 1 );
		add_action( 'bbp_opened_topic', array( $this, 'changed' ), 20, 1 );
		add_action( 'transition_post_status', array( $this, 'transition' ), 20, 3 );
	}

	/**
	 * A post's status moved: only a topic going between open (`publish`) and `closed`, either way, counts.
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old status.
	 * @param \WP_Post $post       The post.
	 */
	public function transition( $new_status, $old_status, $post ): void {
		$both = array( bbp_get_public_status_id(), bbp_get_closed_status_id() );
		if ( $new_status === $old_status || ! in_array( $new_status, $both, true ) || ! in_array( $old_status, $both, true ) || ! $post instanceof \WP_Post ) {
			return;
		}
		$this->changed( $post->ID );
	}

	/**
	 * A topic was closed or opened: queued when it is a ticket and the change is not the plugin's own.
	 *
	 * @param int|mixed $topic_id The topic.
	 */
	public function changed( $topic_id ): void {
		$topic = get_post( (int) $topic_id );
		if ( TopicStatus::isApplying() || ! Posts::isTopic( $topic ) || Posts::ticketOf( $topic->ID ) < 1 ) {
			return;
		}
		$this->queue->add( StatusSender::KIND, $topic->ID );
	}
}
