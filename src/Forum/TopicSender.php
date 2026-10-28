<?php
/**
 * A topic in a marked forum becomes a ticket: `POST /tickets`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Outbox\Job;
use OmnaBase\WordPress\Outbox\Step;

defined( 'ABSPATH' ) || exit;

/**
 * The outbox row `forum_topic` (one per topic). When its turn comes the topic is read again: gone, unpublished, filed already, in a forum no
 * longer marked, or a guest's while guests are switched off → nothing is sent. A topic without an email address (a guest who left none)
 * waits for the administrator. Otherwise the ticket: the connection's first project, the author's address and name, the title and the first
 * post as plain text, the site's reference `topic-<id>` with the topic's address, replies back to the site, `public` for every forum but a
 * hidden one, on the `api` channel, with the row's idempotency key. OmnaBase's answer (201, or 200 for the topic it filed already) gives the
 * ticket number, kept on the topic. The site-fields round: a forum with declared post fields also sends `replyFields` (every declared field)
 * and `fields` (those with a value on the topic); a forum without any sends today's body.
 */
final class TopicSender implements Job {

	public const KIND = 'forum_topic';

	/** The longest name OmnaBase takes. */
	private const NAME_MAX = 120;

	/**
	 * Builds the sender.
	 *
	 * @param Settings       $settings   The forum settings.
	 * @param ConnectionInfo $connection The connection's projects.
	 * @param PostFields     $fields     The declared post fields.
	 */
	public function __construct( private Settings $settings, private ConnectionInfo $connection, private PostFields $fields ) {}

	/** {@inheritDoc} */
	public function kind(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Forum topic to ticket', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function subject( array $row ): string {
		$title = get_the_title( (int) $row['subject_id'] );
		return '' !== $title ? $title : '#' . (int) $row['subject_id'];
	}

	/** {@inheritDoc} */
	public function request( array $row ): Step {
		$topic = get_post( (int) $row['subject_id'] );
		$skip  = $this->whyNot( $topic );
		if ( null !== $skip ) {
			return Step::skip( $skip );
		}
		$author = Posts::author( $topic );
		if ( ! is_email( $author['email'] ) ) {
			return Step::attention( __( 'This topic has no usable email address (a guest who left none), so OmnaBase cannot file it. Discard it, or Retry once the topic has one.', 'omnabase' ) );
		}
		$project = $this->connection->firstProjectId();
		if ( '' === $project ) {
			return Step::attention( __( 'The connection names no project for new tickets: connect the site again.', 'omnabase' ) );
		}
		return Step::send( 'POST', '/tickets', $this->body( $topic, $author, $project ), 15, true );
	}

	/** {@inheritDoc} */
	public function sent( array $row, ?array $answer ): void {
		$number = (int) ( $answer['number'] ?? 0 );
		if ( $number > 0 ) {
			Posts::rememberTicket( (int) $row['subject_id'], $number );
		}
	}

	/**
	 * Why a topic is no longer sent, in words; null when it is.
	 *
	 * @param \WP_Post|null $topic The topic.
	 */
	private function whyNot( ?\WP_Post $topic ): ?string {
		if ( ! Posts::isTopic( $topic ) || ! in_array( $topic->post_status, Posts::shownTopicStatuses(), true ) ) {
			return __( 'Not sent: the topic was removed or unpublished before its turn.', 'omnabase' );
		}
		if ( Posts::ticketOf( $topic->ID ) > 0 ) {
			return __( 'Not sent: the topic is a ticket already.', 'omnabase' );
		}
		if ( ! $this->settings->isMarked( Posts::forumOf( $topic->ID ) ) ) {
			return __( 'Not sent: its forum is no longer marked.', 'omnabase' );
		}
		if ( 0 === (int) $topic->post_author && ! $this->settings->guests() ) {
			return __( 'Not sent: guests\' topics are switched off.', 'omnabase' );
		}
		return null;
	}

	/**
	 * The new ticket.
	 *
	 * @param \WP_Post                                $topic   The topic.
	 * @param array{user:int,email:string,name:string} $author  Who wrote it.
	 * @param string                                  $project The project's id.
	 * @return array<string,mixed>
	 */
	private function body( \WP_Post $topic, array $author, string $project ): array {
		$subject  = PlainText::subject( $topic->post_title );
		$subject  = '' !== $subject ? $subject : __( 'Forum topic', 'omnabase' );
		$message  = PlainText::of( (string) $topic->post_content );
		$customer = array( 'email' => $author['email'] );
		$name     = mb_substr( trim( $author['name'] ), 0, self::NAME_MAX );
		if ( '' !== $name ) {
			$customer['name'] = $name;
		}
		$body = array(
			'project'  => $project,
			'customer' => $customer,
			'subject'  => $subject,
			'message'  => '' !== $message ? $message : $subject,
			'external' => array( 'id' => 'topic-' . $topic->ID, 'url' => (string) get_permalink( $topic ) ),
			'replyVia' => 'site',
			'public'   => Posts::isPublicForum( Posts::forumOf( $topic->ID ) ),
			'channel'  => 'api',
		);
		return $body + $this->fieldsOf( $topic->ID );
	}

	/**
	 * The site-fields round: the topic's post fields and the fields a reply may carry back; nothing without declared fields.
	 *
	 * @param int $topic_id The topic.
	 * @return array<string,mixed>
	 */
	private function fieldsOf( int $topic_id ): array {
		$reply = $this->fields->replyFields();
		if ( array() === $reply ) {
			return array();
		}
		$values = $this->fields->valuesOf( $topic_id );
		return ( array() === $values ? array() : array( 'fields' => $values ) ) + array( 'replyFields' => $reply );
	}
}
