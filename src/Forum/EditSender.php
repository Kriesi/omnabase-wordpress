<?php
/**
 * An edited forum post changes its message in the ticket: `PATCH /tickets/{number}/messages/by-external/{topic-<id>|reply-<id>}`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Job;
use OmnaBase\WordPress\Outbox\Step;
use OmnaBase\WordPress\Outbox\Table;

defined( 'ABSPATH' ) || exit;

/**
 * The outbox row `forum_edit` (one waiting row per post, so three quick edits send the last words once; the forum edits and pictures round,
 * 2026-11-18). When its turn comes the post is read again:
 * - gone, trashed, spammed or unpublished, or a reply with OmnaBase's sign (the team's own reply is not followed: finding 8) → nothing;
 * - its own first send (or, for a reply, its topic's) still open → it waits, so an edit made during that send is not lost;
 * - OmnaBase never had it (no ticket, a reply never delivered) → nothing: the first send read the post at its turn;
 * - its stored text and declared fields match what was last sent (`Fingerprint`) → nothing;
 * - otherwise the edit: the plain text, the shown form (`ShownForm`) and the post's whole declared fields (left out: none now; never for
 *   another member's post), without the row's idempotency key (an edit's body changes by nature, and setting a text twice is harmless).
 *   OmnaBase's 404 discards the row quietly. The title never travels: the ticket's subject is the team's.
 */
final class EditSender implements Job {

	public const KIND = 'forum_edit';

	/** How long an edit waits for its post's own first send before it looks again. */
	private const SEND_WAIT_SECONDS = 60;

	/**
	 * Builds the sender.
	 *
	 * @param Table       $table       The outbox's table (whether the post's own send is still open).
	 * @param PostFields  $fields      The declared post fields.
	 * @param Fingerprint $fingerprint What was last sent of a post.
	 */
	public function __construct( private Table $table, private PostFields $fields, private Fingerprint $fingerprint ) {}

	/** {@inheritDoc} */
	public function kind(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Forum edit to ticket', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function subject( array $row ): string {
		$post  = get_post( (int) $row['subject_id'] );
		$title = null !== $post ? get_the_title( self::topicOf( $post ) ) : '';
		return '' !== $title ? $title : '#' . (int) $row['subject_id'];
	}

	/** {@inheritDoc} */
	public function request( array $row ): Step {
		$post = get_post( (int) $row['subject_id'] );
		$skip = self::whyNot( $post );
		if ( null !== $skip ) {
			return Step::skip( $skip );
		}
		if ( $this->sendIsOpen( $post ) ) {
			return Step::wait( self::SEND_WAIT_SECONDS, __( 'Waiting for the post itself to reach OmnaBase first.', 'omnabase' ) );
		}
		$number = Posts::ticketOf( self::topicOf( $post ) );
		if ( $number < 1 || ( Posts::isReply( $post ) && ! Posts::isDelivered( $post->ID ) ) ) {
			return Step::skip( __( 'Not sent: OmnaBase never received this post, so there is nothing to change.', 'omnabase' ) );
		}
		if ( $this->fingerprint->unchanged( $post ) ) {
			return Step::skip( __( 'Not sent: nothing changed since the post was last sent.', 'omnabase' ) );
		}
		return Step::send( 'PATCH', '/tickets/' . $number . '/messages/by-external/' . self::reference( $post ), $this->body( $post ), 15 )->droppingNotFound();
	}

	/** {@inheritDoc} */
	public function sent( array $row, ?array $answer ): void {
		$post = get_post( (int) $row['subject_id'] );
		if ( $post instanceof \WP_Post ) {
			$this->fingerprint->remember( $post );
		}
	}

	/**
	 * Why an edit is not sent, in words; null when it may be.
	 *
	 * @param \WP_Post|null $post The topic or reply.
	 */
	private static function whyNot( ?\WP_Post $post ): ?string {
		$shown = Posts::isTopic( $post ) ? in_array( $post->post_status, Posts::shownTopicStatuses(), true ) : ( Posts::isReply( $post ) && bbp_get_public_status_id() === $post->post_status );
		if ( ! $shown ) {
			return __( 'Not sent: the post was removed or unpublished before its turn.', 'omnabase' );
		}
		if ( Posts::isReply( $post ) && ( '' !== Posts::messageOf( $post->ID ) || '' !== Posts::postedAsOf( $post->ID ) ) ) {
			return __( 'Not sent: the reply came from OmnaBase.', 'omnabase' );
		}
		return null;
	}

	/**
	 * Whether the post's own first send (or its topic's, for a reply) still waits or needs attention.
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private function sendIsOpen( \WP_Post $post ): bool {
		$topic = $this->table->isOpen( TopicSender::KIND . ':' . self::topicOf( $post ) );
		return $topic || ( Posts::isReply( $post ) && $this->table->isOpen( ReplySender::KIND . ':' . $post->ID ) );
	}

	/**
	 * The edit: the new words, the shown form, the fields.
	 *
	 * @param \WP_Post $post The topic or reply.
	 * @return array<string,mixed>
	 */
	private function body( \WP_Post $post ): array {
		$body = array( 'body' => self::text( $post ) ) + ShownForm::of( $post );
		if ( ! $this->carriesFields( $post ) ) {
			return $body;
		}
		$values = $this->fields->valuesOf( $post->ID );
		return array() !== $values ? $body + array( 'fields' => $values ) : $body;
	}

	/**
	 * Whether a post's fields travel: the topic's, the customer's and a moderator's replies; never another member's (their private data must
	 * not land on the customer's ticket).
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private function carriesFields( \WP_Post $post ): bool {
		if ( Posts::isTopic( $post ) ) {
			return true;
		}
		$topic = get_post( self::topicOf( $post ) );
		return ( $topic instanceof \WP_Post && Posts::byTopicAuthor( $post, $topic ) ) || Settings::canModerate( (int) $post->post_author );
	}

	/**
	 * The post's plain text; for an empty one the topic's title or the reply's address, as at the first send.
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private static function text( \WP_Post $post ): string {
		$text = PlainText::of( (string) $post->post_content );
		if ( '' !== $text ) {
			return $text;
		}
		if ( ! Posts::isTopic( $post ) ) {
			return (string) get_permalink( $post );
		}
		$subject = PlainText::subject( $post->post_title );
		return '' !== $subject ? $subject : __( 'Forum topic', 'omnabase' );
	}

	/**
	 * The topic a post belongs to (itself, for a topic).
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private static function topicOf( \WP_Post $post ): int {
		return Posts::isTopic( $post ) ? $post->ID : Posts::topicOf( $post );
	}

	/**
	 * The site's own id for a post at OmnaBase.
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private static function reference( \WP_Post $post ): string {
		return ( Posts::isTopic( $post ) ? 'topic-' : 'reply-' ) . $post->ID;
	}
}
