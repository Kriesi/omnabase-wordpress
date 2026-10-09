<?php
/**
 * Reading bbPress's posts: which topic and forum a post belongs to, who wrote it, the site's reference for it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Pure reads through WordPress's and bbPress's own functions (no SQL). A topic's reference at OmnaBase is `topic-<id>`, a reply's
 * `reply-<id>`. A guest (bbPress's anonymous poster) is the name and address bbPress stored with the post, never checked by anyone.
 */
final class Posts {

	/** The statuses a topic shows to readers (published or closed). */
	public static function shownTopicStatuses(): array {
		return array( bbp_get_public_status_id(), bbp_get_closed_status_id() );
	}

	/**
	 * Whether a post is a bbPress topic.
	 *
	 * @param \WP_Post|null $post The post.
	 */
	public static function isTopic( ?\WP_Post $post ): bool {
		return null !== $post && bbp_get_topic_post_type() === $post->post_type;
	}

	/**
	 * Whether a post is a bbPress reply.
	 *
	 * @param \WP_Post|null $post The post.
	 */
	public static function isReply( ?\WP_Post $post ): bool {
		return null !== $post && bbp_get_reply_post_type() === $post->post_type;
	}

	/**
	 * The topic a reply belongs to (its parent; bbPress's meta while the parent is not set yet).
	 *
	 * @param \WP_Post $reply The reply.
	 */
	public static function topicOf( \WP_Post $reply ): int {
		$parent = (int) $reply->post_parent;
		return $parent > 0 ? $parent : (int) get_post_meta( $reply->ID, '_bbp_topic_id', true );
	}

	/**
	 * The forum a topic belongs to.
	 *
	 * @param int $topic_id The topic.
	 */
	public static function forumOf( int $topic_id ): int {
		return (int) bbp_get_topic_forum_id( $topic_id );
	}

	/**
	 * Whether a forum's topics count as public at OmnaBase: every forum but a hidden one (a private forum is read by every logged-in
	 * customer, so a customer's orders must not go into an answer posted there; review B1).
	 *
	 * @param int $forum_id The forum.
	 */
	public static function isPublicForum( int $forum_id ): bool {
		return 'hidden' !== bbp_get_forum_visibility( $forum_id );
	}

	/**
	 * Who wrote a post: the user (0 for a guest), the address and the name.
	 *
	 * @param \WP_Post $post The topic or reply.
	 * @return array{user:int,email:string,name:string}
	 */
	public static function author( \WP_Post $post ): array {
		$user = (int) $post->post_author;
		if ( $user > 0 ) {
			$data = get_userdata( $user );
			return array( 'user' => $user, 'email' => $data ? (string) $data->user_email : '', 'name' => $data ? (string) $data->display_name : '' );
		}
		return array(
			'user'  => 0,
			'email' => (string) get_post_meta( $post->ID, '_bbp_anonymous_email', true ),
			'name'  => (string) get_post_meta( $post->ID, '_bbp_anonymous_name', true ),
		);
	}

	/**
	 * Whether a reply was written by its topic's author (the same user, or for a guest's topic a guest with the same address).
	 *
	 * @param \WP_Post $reply The reply.
	 * @param \WP_Post $topic The topic.
	 */
	public static function byTopicAuthor( \WP_Post $reply, \WP_Post $topic ): bool {
		$writer = self::author( $reply );
		$asker  = self::author( $topic );
		if ( $asker['user'] > 0 || $writer['user'] > 0 ) {
			return $asker['user'] === $writer['user'];
		}
		return '' !== $asker['email'] && 0 === strcasecmp( $asker['email'], $writer['email'] );
	}

	/**
	 * The OmnaBase ticket number a topic became, or 0.
	 *
	 * @param int $topic_id The topic.
	 */
	public static function ticketOf( int $topic_id ): int {
		return (int) get_post_meta( $topic_id, Options::TICKET_META, true );
	}

	/**
	 * Remembers the ticket a topic became.
	 *
	 * @param int $topic_id The topic.
	 * @param int $number   The ticket number.
	 */
	public static function rememberTicket( int $topic_id, int $number ): void {
		update_post_meta( $topic_id, Options::TICKET_META, $number );
	}

	/**
	 * OmnaBase's message id on a reply the plugin posted, or an empty string.
	 *
	 * @param int $reply_id The reply.
	 */
	public static function messageOf( int $reply_id ): string {
		return (string) get_post_meta( $reply_id, Options::MESSAGE_META, true );
	}

	/**
	 * Marks a reply the plugin posted with OmnaBase's message id (the echo marker).
	 *
	 * @param int    $reply_id   The reply.
	 * @param string $message_id OmnaBase's message id.
	 */
	public static function markPosted( int $reply_id, string $message_id ): void {
		update_post_meta( $reply_id, Options::MESSAGE_META, $message_id );
	}

	/**
	 * The reply the plugin posted for an OmnaBase message, whatever its status now, or 0.
	 *
	 * @param string $message_id OmnaBase's message id.
	 */
	public static function postedFor( string $message_id ): int {
		$query = new \WP_Query(
			array(
				'post_type'      => bbp_get_reply_post_type(),
				'post_status'    => array_keys( get_post_stati() ),
				'meta_key'       => Options::MESSAGE_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- one indexed meta key, at most one row.
				'meta_value'     => $message_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return (int) ( $query->posts[0] ?? 0 );
	}

	/**
	 * The topic a ticket's site reference names (`topic-<id>`), or 0.
	 *
	 * @param mixed $reference The ticket's `external.id`.
	 */
	public static function topicFromReference( $reference ): int {
		return is_string( $reference ) && preg_match( '/^topic-(\d{1,19})$/', $reference, $m ) ? (int) $m[1] : 0;
	}
}
