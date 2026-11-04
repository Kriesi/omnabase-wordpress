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
 * - gone, unpublished, posted by the plugin itself (OmnaBase's sign: the message id `_omnabase_message_id` or the posted-as mark
 *   `_omnabase_posted_as`, written in the reply's own save), in a forum no longer marked → nothing. Since the support user replies round
 *   (2026-11-04) the sign alone says "came from OmnaBase": a reply the support user wrote by hand is a moderator's answer like any other
 *   (until then every support-user post was skipped, and ticket 253 lost such remarks);
 * - its topic has no ticket yet: while the topic's own row waits (say for OmnaBase's daily limit) or needs attention, the reply waits and
 *   goes after it; a topic that was never sent (published before its forum was marked) takes its replies with it: nothing;
 * - by the topic's author → the customer's message (`from: customer`);
 * - by someone with moderator rights → the team's answer already on the forum (`from: team`, `delivered: true`, `siteAuthor` with the
 *   support user's name, the moderator's address, which OmnaBase matches to a member, and their forum role): never mailed, never a note;
 * - by anyone else, a guest included → another participant's post (`from: other`, `participant` with the display name or the guest's given
 *   name, nothing else: since the composer-and-fixes round, 2026-11-03; until then nothing was sent, and ticket 395 lacked another member's
 *   answer). OmnaBase keeps it on the customer's side under that name, never as the customer, and reopens the ticket like a customer's post.
 * Each with the site's reference `reply-<id>` and the row's idempotency key; the customer's and the moderator's also with (the site-fields
 * round) `fields`: the reply's declared post fields that hold a value. Another participant's post never carries fields: another person's
 * private data must not land on the customer's ticket.
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
	 * @param Table      $table    The outbox's table (whether the topic is still on its way).
	 * @param PostFields $fields   The declared post fields.
	 */
	public function __construct( private Settings $settings, private Table $table, private PostFields $fields ) {}

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
		$skip  = self::whyNot( $reply, $this->settings );
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
		return Step::send( 'POST', '/tickets/' . $number . '/messages', $this->body( $reply, $topic ), 15, true );
	}

	/**
	 * OmnaBase accepted the reply: it is marked delivered (the support user replies round), so a resend answers "already sent" after the
	 * outbox has forgotten the row.
	 *
	 * @param array<string,mixed>      $row    The row.
	 * @param array<string,mixed>|null $answer OmnaBase's answer.
	 */
	public function sent( array $row, ?array $answer ): void {
		Posts::markDelivered( (int) $row['subject_id'] );
	}

	/**
	 * Why a reply is not sent, in words; null when it may be. Shared with the resend (`Resend`): one rule, two doors.
	 *
	 * @param \WP_Post|null $reply    The reply.
	 * @param Settings      $settings The forum settings.
	 */
	public static function whyNot( ?\WP_Post $reply, Settings $settings ): ?string {
		if ( ! Posts::isReply( $reply ) || bbp_get_public_status_id() !== $reply->post_status ) {
			return __( 'Not sent: the reply was removed or unpublished before its turn.', 'omnabase' );
		}
		if ( '' !== Posts::messageOf( $reply->ID ) || '' !== Posts::postedAsOf( $reply->ID ) ) {
			return __( 'Not sent: the reply came from OmnaBase.', 'omnabase' );
		}
		$topic = get_post( Posts::topicOf( $reply ) );
		if ( ! Posts::isTopic( $topic ) || 'trash' === $topic->post_status ) {
			return __( 'Not sent: its topic was removed.', 'omnabase' );
		}
		return $settings->isMarked( Posts::forumOf( $topic->ID ) ) ? null : __( 'Not sent: its forum is no longer marked.', 'omnabase' );
	}

	/**
	 * The message: the customer's, the team's, or another participant's.
	 *
	 * @param \WP_Post $reply The reply.
	 * @param \WP_Post $topic Its topic.
	 * @return array<string,mixed>
	 */
	private function body( \WP_Post $reply, \WP_Post $topic ): array {
		$text = PlainText::of( (string) $reply->post_content );
		$base = array(
			'body'     => '' !== $text ? $text : (string) get_permalink( $reply ),
			'external' => array( 'id' => 'reply-' . $reply->ID ),
		);
		if ( Posts::byTopicAuthor( $reply, $topic ) ) {
			return array( 'from' => 'customer' ) + $base + $this->fieldsOf( $reply );
		}
		$author = (int) $reply->post_author;
		if ( Settings::canModerate( $author ) ) {
			return array( 'from' => 'team', 'delivered' => true, 'siteAuthor' => $this->siteAuthor( $author ) ) + $base + $this->fieldsOf( $reply );
		}
		return array( 'from' => 'other', 'participant' => array( 'name' => $this->participantName( $reply ) ) ) + $base;
	}

	/**
	 * The reply's declared post fields that hold a value, as `fields`; nothing when none holds one.
	 *
	 * @param \WP_Post $reply The reply.
	 * @return array<string,mixed>
	 */
	private function fieldsOf( \WP_Post $reply ): array {
		$values = $this->fields->valuesOf( $reply->ID );
		return array() !== $values ? array( 'fields' => $values ) : array();
	}

	/**
	 * Another participant's name as the forum shows it (the composer-and-fixes round): a member's display name (else their login), a guest's
	 * name as given (bbPress keeps it on the reply); never an address or a role.
	 *
	 * @param \WP_Post $reply The reply.
	 */
	private function participantName( \WP_Post $reply ): string {
		$author = (int) $reply->post_author;
		$user   = $author > 0 ? get_userdata( $author ) : false;
		$name   = $user ? ( '' !== trim( (string) $user->display_name ) ? $user->display_name : $user->user_login ) : get_post_meta( $reply->ID, '_bbp_anonymous_name', true );
		$name   = trim( wp_strip_all_tags( (string) $name ) );
		return mb_substr( '' !== $name ? $name : __( 'Forum member', 'omnabase' ), 0, self::NAME_MAX );
	}

	/**
	 * The team member as the site names them: the moderator's own forum display name (since 2026-11-03; until then the support user's name,
	 * which made every moderator read as the support user in OmnaBase), their own address (OmnaBase shows the answer as that member when the
	 * address is a member's) and their forum role.
	 *
	 * @param int $author The moderator.
	 * @return array{name:string,email?:string,role?:string}
	 */
	private function siteAuthor( int $author ): array {
		$moderator = get_userdata( $author );
		$name      = trim( (string) ( $moderator ? $moderator->display_name : '' ) );
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
