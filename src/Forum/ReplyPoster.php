<?php
/**
 * OmnaBase's reply on a topic's ticket appears on the forum as a reply by the member's own moderator account, else by the support user.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Look\MessageHtml;
use OmnaBase\WordPress\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the events receiver's `omnabase_webhook` for `message.sent`: a team reply leaving OmnaBase (`direction: out`) on a ticket whose
 * site reference is one of this site's topics (`topic-<id>`). Anything else is answered 200 and posts nothing (a customer's message, a
 * ticket that is not one of our topics, a message the site itself sent). The reply goes through `bbp_insert_reply` (so the topic's
 * freshness, reply count and last reply follow, as bbPress's own form does) by the poster `ReplyAuthor` chooses (the post-as-member round:
 * the moderator with the member's email address, else the support user), always `publish` (on a closed topic
 * bbPress would otherwise store it "closed" and hide it), its HTML through the plugin's fence (`MessageHtml`), the agent's name in the
 * closing line, marked with OmnaBase's message id: one message is one reply, however often it is delivered. The receiver says 2xx only once the reply exists:
 * - the topic trashed, deleted or unpublished → 409 (OmnaBase retries, then mails; never the answer that switches the address off);
 * - nobody can post it (no moderator with the member's address and no support user chosen) → 503 (OmnaBase retries while the
 *   administrator picks one);
 * - WordPress refused to save the reply → 500.
 * The topic's subscribers are mailed afterwards by a job of the plugin's (`SubscriberMails`), never inside OmnaBase's five seconds.
 * The site-fields round: the message's `fields` whose keys are declared now are written as the reply's post meta right after it exists and
 * before it is marked as OmnaBase's (a redelivery that finds the mark posts nothing more), then `omnabase_forum_field_saved` fires.
 * The post-as-member round: the reply also keeps how its author was chosen (`_omnabase_posted_as`: `member` | `support`), and a poster who
 * was subscribed to the topic stays subscribed (bbPress before 2.6.20 drops the poster's subscription inside its insert).
 */
final class ReplyPoster {

	/** Whether the plugin is posting OmnaBase's reply right now (the triggers then queue nothing: the echo). */
	private static bool $posting = false;

	/**
	 * Builds the poster.
	 *
	 * @param Settings        $settings   The forum settings.
	 * @param SubscriberMails $mails      The subscriber mails, for later.
	 * @param ConnectionInfo  $connection The workspace's name.
	 * @param PostFields      $fields     The declared post fields.
	 * @param ReplyAuthor     $author     Who posts a reply.
	 */
	public function __construct( private Settings $settings, private SubscriberMails $mails, private ConnectionInfo $connection, private PostFields $fields, private ReplyAuthor $author ) {}

	/** Whether the plugin is posting OmnaBase's reply right now. */
	public static function isPosting(): bool {
		return self::$posting;
	}

	/**
	 * A signed, new webhook.
	 *
	 * @param string              $type    The event.
	 * @param array<string,mixed> $payload The event as sent.
	 * @throws Refusal When the reply cannot be posted now.
	 */
	public function handle( $type, $payload ): void {
		$data     = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		$message  = is_array( $data['message'] ?? null ) ? $data['message'] : array();
		$topic_id = Posts::topicFromReference( $data['ticket']['external']['id'] ?? null );
		$id       = is_string( $message['id'] ?? null ) ? $message['id'] : '';
		if ( 'message.sent' !== $type || 0 === $topic_id || '' === $id || 'out' !== ( $message['direction'] ?? '' ) || ! empty( $message['external'] ) ) {
			return;
		}
		if ( Posts::postedFor( $id ) > 0 ) {
			return;
		}
		$topic = get_post( $topic_id );
		if ( ! Posts::isTopic( $topic ) || ! in_array( $topic->post_status, Posts::shownTopicStatuses(), true ) ) {
			throw new Refusal( 409, esc_html__( 'The topic of this ticket is no longer on the forum.', 'omnabase' ) );
		}
		$poster = $this->author->of( $message, $topic );
		if ( 0 === $poster ) {
			throw new Refusal( 503, esc_html__( 'No support user is chosen under Settings › OmnaBase, so the reply cannot be posted yet.', 'omnabase' ) );
		}
		$this->mails->later( $this->post( $topic, $poster, $message, $this->workspace( $payload ) ) );
	}

	/**
	 * Posts the reply and marks it.
	 *
	 * @param \WP_Post            $topic     The topic.
	 * @param int                 $poster    The user it is posted under.
	 * @param array<string,mixed> $message   OmnaBase's message.
	 * @param string              $workspace The workspace's name.
	 * @return int The reply.
	 * @throws Refusal When WordPress did not save it.
	 */
	private function post( \WP_Post $topic, int $poster, array $message, string $workspace ): int {
		$data = array(
			'post_parent'  => $topic->ID,
			'post_author'  => $poster,
			'post_title'   => sprintf( /* translators: %s: the topic's title. */ __( 'Reply To: %s', 'omnabase' ), $topic->post_title ),
			'post_content' => $this->content( $message, $workspace ),
			'post_status'  => bbp_get_public_status_id(),
		);
		$subscribed = bbp_is_user_subscribed( $poster, $topic->ID );
		self::$posting = true;
		try {
			$reply = bbp_insert_reply( $data, array( 'forum_id' => Posts::forumOf( $topic->ID ), 'topic_id' => $topic->ID ) );
		} finally {
			self::$posting = false;
		}
		self::keepSubscribed( $poster, $topic->ID, $subscribed );
		if ( ! is_int( $reply ) || $reply < 1 ) {
			throw new Refusal( 500, esc_html__( 'WordPress did not save the reply; OmnaBase will try again.', 'omnabase' ) );
		}
		PostFields::write( $reply, $message['fields'] ?? null, $this->fields->declared() );
		Posts::markPostedAs( $reply, $poster === $this->settings->supportUser() ? Posts::POSTED_AS_SUPPORT : Posts::POSTED_AS_MEMBER );
		Posts::markPosted( $reply, (string) $message['id'] );
		return $reply;
	}

	/**
	 * Gives the poster back a subscription to the topic that the insert dropped (bbPress before 2.6.20 does, when the request carries no
	 * "notify me" tick; later versions keep it, and this does nothing).
	 *
	 * @param int  $poster     The user the reply was posted under.
	 * @param int  $topic_id   The topic.
	 * @param bool $subscribed Whether they were subscribed before the insert.
	 */
	private static function keepSubscribed( int $poster, int $topic_id, bool $subscribed ): void {
		if ( $subscribed && ! bbp_is_user_subscribed( $poster, $topic_id ) ) {
			bbp_add_user_subscription( $poster, $topic_id );
		}
	}

	/**
	 * The reply's HTML: OmnaBase's HTML through the plugin's fence (no pictures, inline styles or classes; its text when it has none), then
	 * the closing line.
	 *
	 * @param array<string,mixed> $message   OmnaBase's message.
	 * @param string              $workspace The workspace's name.
	 */
	private function content( array $message, string $workspace ): string {
		$body  = MessageHtml::of( is_string( $message['bodyHtml'] ?? null ) ? $message['bodyHtml'] : null, (string) ( $message['bodyText'] ?? '' ) );
		$agent = is_string( $message['author']['name'] ?? null ) && '' !== trim( $message['author']['name'] ) ? trim( $message['author']['name'] ) : __( 'the team', 'omnabase' );
		$line  = strtr( $this->settings->closingLine(), array( '{agent}' => $agent, '{workspace}' => $workspace ) );
		return $body . "\n\n<p>" . esc_html( $line ) . '</p>';
	}

	/**
	 * The workspace's name: the event's, else the connection's.
	 *
	 * @param array<string,mixed> $payload The event.
	 */
	private function workspace( array $payload ): string {
		$name = is_string( $payload['workspace']['name'] ?? null ) ? trim( $payload['workspace']['name'] ) : '';
		return '' !== $name ? $name : $this->connection->workspaceName();
	}
}
