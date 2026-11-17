<?php
/**
 * An old topic's earlier posts, as OmnaBase takes them with the new ticket (the old-topics-and-undelivered round, 2026-11-16).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Table;

defined( 'ABSPATH' ) || exit;

/**
 * The history is the topic's published replies older than the oldest reply that still has its own outbox row (the reply that started the
 * hand-over and any written while the topic waited: they follow through their own call), never one carrying OmnaBase's sign, the newest
 * 30, oldest first. Each post is plain text of at most 10,000 characters (a longer one is cut with "…"; the full post stays in the forum),
 * dated in GMT, with the site's own id and its writer: the customer, a moderator by name (`siteAuthor`), anyone else by name
 * (`participant`). No site fields, no files. The same list is read when the topic is accepted, to mark every post that travelled as
 * delivered, so a resend by hand answers "already sent".
 */
final class TopicHistory {

	/** The most earlier posts one call carries. */
	public const MAX = 30;

	/** The longest earlier post OmnaBase takes. */
	public const BODY_MAX = 10000;

	/**
	 * Builds the reader.
	 *
	 * @param Table $table The outbox's table (which replies still have their own row).
	 */
	public function __construct( private Table $table ) {}

	/**
	 * The fields the topic's call gains: `postedAt`, `earlier` and `earlierLeftOut`; nothing for a topic without earlier posts.
	 *
	 * @param \WP_Post $topic The topic.
	 * @return array<string,mixed>
	 */
	public function fieldsOf( \WP_Post $topic ): array {
		$all = $this->earlierReplies( $topic );
		if ( array() === $all ) {
			return array();
		}
		$kept = array_slice( $all, -self::MAX );
		$out  = array(
			'postedAt' => self::gmt( $topic ),
			'earlier'  => array_map( fn ( \WP_Post $reply ) => $this->post( $reply, $topic ), $kept ),
		);
		$left = count( $all ) - count( $kept );
		return $left > 0 ? $out + array( 'earlierLeftOut' => $left ) : $out;
	}

	/**
	 * The replies that travel with the topic (at most the newest 30).
	 *
	 * @param \WP_Post $topic The topic.
	 * @return \WP_Post[]
	 */
	public function travelling( \WP_Post $topic ): array {
		return array_slice( $this->earlierReplies( $topic ), -self::MAX );
	}

	/**
	 * Every earlier reply, oldest first: published, not OmnaBase's, older than the oldest one with its own row.
	 *
	 * @param \WP_Post $topic The topic.
	 * @return \WP_Post[]
	 */
	private function earlierReplies( \WP_Post $topic ): array {
		$out = array();
		foreach ( $this->repliesOf( $topic->ID ) as $reply ) {
			if ( $this->table->isOpen( ReplySender::KIND . ':' . $reply->ID ) ) {
				break; // this one and every later one go through their own call
			}
			if ( '' === Posts::messageOf( $reply->ID ) && '' === Posts::postedAsOf( $reply->ID ) ) {
				$out[] = $reply;
			}
		}
		return $out;
	}

	/**
	 * The topic's published replies, oldest first.
	 *
	 * @param int $topic_id The topic.
	 * @return \WP_Post[]
	 */
	private function repliesOf( int $topic_id ): array {
		$posts = get_posts(
			array(
				'post_type'        => bbp_get_reply_post_type(),
				'post_parent'      => $topic_id,
				'post_status'      => bbp_get_public_status_id(),
				'orderby'          => array( 'date' => 'ASC', 'ID' => 'ASC' ),
				'posts_per_page'   => -1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return array_values( array_filter( (array) $posts, static fn ( $p ) => $p instanceof \WP_Post ) );
	}

	/**
	 * One earlier post.
	 *
	 * @param \WP_Post $reply The reply.
	 * @param \WP_Post $topic Its topic.
	 * @return array<string,mixed>
	 */
	private function post( \WP_Post $reply, \WP_Post $topic ): array {
		$base = array(
			'body'     => self::text( $reply ),
			'postedAt' => self::gmt( $reply ),
			'external' => array( 'id' => 'reply-' . $reply->ID ),
		);
		if ( Posts::byTopicAuthor( $reply, $topic ) ) {
			return array( 'from' => 'customer' ) + $base;
		}
		$author = (int) $reply->post_author;
		if ( Settings::canModerate( $author ) ) {
			return array( 'from' => 'team' ) + $base + array( 'siteAuthor' => ReplyWriter::siteAuthor( $author ) );
		}
		return array( 'from' => 'other' ) + $base + array( 'participant' => array( 'name' => ReplyWriter::participantName( $reply ) ) );
	}

	/**
	 * A post's plain text, cut to 10,000 characters with "…"; the post's address when it holds no text.
	 *
	 * @param \WP_Post $reply The reply.
	 */
	private static function text( \WP_Post $reply ): string {
		$text = PlainText::of( (string) $reply->post_content );
		if ( '' === $text ) {
			return (string) get_permalink( $reply );
		}
		return mb_strlen( $text ) > self::BODY_MAX ? rtrim( mb_substr( $text, 0, self::BODY_MAX - 1 ) ) . '…' : $text;
	}

	/**
	 * A post's date in GMT, as OmnaBase reads it (`2025-02-03T10:00:00Z`).
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private static function gmt( \WP_Post $post ): string {
		$stamp = strtotime( (string) $post->post_date_gmt . ' UTC' );
		return gmdate( 'Y-m-d\TH:i:s\Z', false !== $stamp ? $stamp : time() );
	}
}
