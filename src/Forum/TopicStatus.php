<?php
/**
 * A topic's open or closed state, changed on OmnaBase's word, and the newest status message applied to it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin closes and reopens a topic through bbPress's own Close and Open (`bbp_close_topic`, `bbp_open_topic`), with a flag up while it
 * does (`isApplying()`): the forum's own status listener then queues nothing, so OmnaBase's change is never sent back (the forum sync round,
 * 2026-11-02). Each topic remembers the number (`seq`, the event's place in the workspace's log) of the newest status message it applied
 * (`_omnabase_status_seq`): an older one, a retried delivery arriving late, changes nothing.
 */
final class TopicStatus {

	/** Whether the plugin is closing or reopening a topic right now. */
	private static bool $applying = false;

	/** Whether the plugin is closing or reopening a topic right now (the status listener then queues nothing: the echo). */
	public static function isApplying(): bool {
		return self::$applying;
	}

	/**
	 * Closes an open topic or reopens a closed one; a topic already in that state, or in another (spam, trash, pending), stays as it is.
	 *
	 * @param \WP_Post $topic  The topic.
	 * @param bool     $closed Whether it should end closed.
	 */
	public static function apply( \WP_Post $topic, bool $closed ): void {
		$from = $closed ? bbp_get_public_status_id() : bbp_get_closed_status_id();
		if ( $from !== $topic->post_status ) {
			return;
		}
		self::$applying = true;
		try {
			$closed ? bbp_close_topic( $topic->ID ) : bbp_open_topic( $topic->ID );
		} finally {
			self::$applying = false;
		}
	}

	/**
	 * Whether a status message is newer than the last one the topic applied (a message without a number always is).
	 *
	 * @param int      $topic_id The topic.
	 * @param int|null $seq      The message's number.
	 */
	public static function isNewer( int $topic_id, ?int $seq ): bool {
		return null === $seq || $seq > (int) get_post_meta( $topic_id, Options::STATUS_SEQ_META, true );
	}

	/**
	 * Remembers the newest status message applied to the topic.
	 *
	 * @param int      $topic_id The topic.
	 * @param int|null $seq      The message's number.
	 */
	public static function remember( int $topic_id, ?int $seq ): void {
		if ( null !== $seq ) {
			update_post_meta( $topic_id, Options::STATUS_SEQ_META, $seq );
		}
	}
}
