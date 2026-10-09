<?php
/**
 * A forum reply joins its topic's ticket: `POST /tickets/{number}/messages`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Job;
use OmnaBase\WordPress\Outbox\Step;
use OmnaBase\WordPress\Outbox\Table;

defined( 'ABSPATH' ) || exit;

/**
 * The outbox row `forum_reply` (one per reply). When its turn comes the reply is read again (review S3: decided here, not in the hook):
 * - gone, unpublished, posted by the plugin itself (`_omnabase_message_id`) or by the support user, in a forum no longer marked → nothing;
 * - its topic has no ticket yet: while the topic's own row waits (say for OmnaBase's daily limit) or needs attention, the reply waits and
 *   goes after it; a topic that was never sent (published before its forum was marked) takes its replies with it: nothing;
 * - by the topic's author → the customer's message (`from: customer`);
 * - by someone with moderator rights → the team's answer already on the forum (`from: team`, `delivered: true`, `siteAuthor` with the
 *   support user's name, the moderator's address, which OmnaBase matches to a member, and their forum role): never mailed, never a note;
 * - by anyone else → nothing (another customer's words never land on Ada's ticket).
 * Each with the site's reference `reply-<id>` and the row's idempotency key.
 */
final class ReplySender implements Job {

	public const KIND = 'forum_reply';

	/** How long a reply waits for its topic's ticket before it looks again. */
	private const TOPIC_WAIT_SECONDS = 300;

	private const NAME_MAX = 120;
	private const ROLE_MAX = 60;

	/**
	 * Builds the sender.
	 *
	 * @param Settings $settings The forum settings.
	 * @param Table    $table    The outbox's table (whether the topic is still on its way).
	 */
	public function __construct( private Settings $settings, private Table $table ) {}

	/** {@inheritDoc} */
	public function kind(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Forum reply to ticket', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function subject( array $row ): string {
		$reply = get_post( (int) $row['subject_id'] );
		$title = Posts::isReply( $reply ) ? get_the_title( Posts::topicOf( $reply ) ) : '';
		return '' !== $title ? $title : '#' . (int) $row['subject_id'];
	}

	/** {@inheritDoc} */
	public function request( array $row ): Step {
		$reply = get_post( (int) $row['subject_id'] );
		$skip  = $this->whyNot( $reply );
		if ( null !== $skip ) {
			return Step::skip( $skip );
		}
		$topic  = get_post( Posts::topicOf( $reply ) );
		$number = Posts::ticketOf( $topic->ID );
		if ( $number < 1 ) {
			return $this->table->isOpen( TopicSender::KIND . ':' . $topic->ID )
				? Step::wait( self::TOPIC_WAIT_SECONDS, __( 'Waiting for its topic to become a ticket.', 'omnabase' ) )
				: Step::skip( __( 'Not sent: its topic was never sent to OmnaBase (published before its forum was marked).', 'omnabase' ) );
		}
		$body = $this->body( $reply, $topic );
		return null === $body
			? Step::skip( __( 'Not sent: only the topic\'s author and moderators speak on the ticket.', 'omnabase' ) )
			: Step::send( 'POST', '/tickets/' . $number . '/messages', $body, 15, true );
	}

	/** {@inheritDoc} */
	public function sent( array $row, ?array $answer ): void {}

	/**
	 * Why a reply is not sent, in words; null when it may be.
	 *
	 * @param \WP_Post|null $reply The reply.
	 */
	private function whyNot( ?\WP_Post $reply ): ?string {
		if ( ! Posts::isReply( $reply ) || bbp_get_public_status_id() !== $reply->post_status ) {
			return __( 'Not sent: the reply was removed or unpublished before its turn.', 'omnabase' );
		}
		$support = $this->settings->supportUser();
		if ( '' !== Posts::messageOf( $reply->ID ) || ( $support > 0 && (int) $reply->post_author === $support ) ) {
			return __( 'Not sent: the reply came from OmnaBase.', 'omnabase' );
		}
		$topic = get_post( Posts::topicOf( $reply ) );
		if ( ! Posts::isTopic( $topic ) || 'trash' === $topic->post_status ) {
			return __( 'Not sent: its topic was removed.', 'omnabase' );
		}
		return $this->settings->isMarked( Posts::forumOf( $topic->ID ) ) ? null : __( 'Not sent: its forum is no longer marked.', 'omnabase' );
	}

	/**
	 * The message: the customer's, the team's, or null for anyone else.
	 *
	 * @param \WP_Post $reply The reply.
	 * @param \WP_Post $topic Its topic.
	 * @return array<string,mixed>|null
	 */
	private function body( \WP_Post $reply, \WP_Post $topic ): ?array {
		$text = PlainText::of( (string) $reply->post_content );
		$base = array(
			'body'     => '' !== $text ? $text : (string) get_permalink( $reply ),
			'external' => array( 'id' => 'reply-' . $reply->ID ),
		);
		if ( Posts::byTopicAuthor( $reply, $topic ) ) {
			return array( 'from' => 'customer' ) + $base;
		}
		$author = (int) $reply->post_author;
		if ( ! Settings::canModerate( $author ) ) {
			return null;
		}
		return array( 'from' => 'team', 'delivered' => true, 'siteAuthor' => $this->siteAuthor( $author ) ) + $base;
	}

	/**
	 * The team member as the site names them: the support user's name (the name the forum shows OmnaBase's answers under), the moderator's
	 * own address (OmnaBase shows the answer as that member when the address is a member's) and their forum role.
	 *
	 * @param int $author The moderator.
	 * @return array{name:string,email?:string,role?:string}
	 */
	private function siteAuthor( int $author ): array {
		$moderator = get_userdata( $author );
		$support   = get_userdata( $this->settings->supportUser() );
		$name      = trim( (string) ( $support ? $support->display_name : ( $moderator ? $moderator->display_name : '' ) ) );
		$out       = array( 'name' => mb_substr( '' !== $name ? $name : __( 'Support', 'omnabase' ), 0, self::NAME_MAX ) );
		if ( $moderator && is_email( $moderator->user_email ) ) {
			$out['email'] = (string) $moderator->user_email;
		}
		$role = function_exists( 'bbp_get_user_display_role' ) ? trim( wp_strip_all_tags( (string) bbp_get_user_display_role( $author ) ) ) : '';
		if ( '' !== $role ) {
			$out['role'] = mb_substr( $role, 0, self::ROLE_MAX );
		}
		return $out;
	}
}
