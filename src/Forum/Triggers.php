<?php
/**
 * The moments bbPress publishes a topic or a reply: each queues one outbox row.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks (named moments where a plugin's code runs): bbPress's front-end forms (`bbp_new_topic`, `bbp_new_reply`), a held post approved
 * (`bbp_approved_topic`, `bbp_approved_reply`), a post marked not spam (`bbp_unspammed_topic`, `bbp_unspammed_reply`), and WordPress's
 * `transition_post_status` into `publish` from any other status (topics and replies written in wp-admin). Several of them fire for one post;
 * the outbox keeps one waiting row per post. Only cheap checks run here (published, in a marked forum, not the plugin's own posting, not
 * filed already); the sending job decides the rest when the row goes. Edits (`publish` to `publish`) and deletions queue nothing.
 */
final class Triggers {

	/**
	 * Builds the triggers.
	 *
	 * @param Queue    $queue    The outbox.
	 * @param Settings $settings The forum settings.
	 */
	public function __construct( private Queue $queue, private Settings $settings ) {}

	/** Registers the seven moments. */
	public function register(): void {
		add_action( 'bbp_new_topic', array( $this, 'topic' ), 20, 1 );
		add_action( 'bbp_approved_topic', array( $this, 'topic' ), 20, 1 );
		add_action( 'bbp_unspammed_topic', array( $this, 'topic' ), 20, 1 );
		add_action( 'bbp_new_reply', array( $this, 'reply' ), 20, 1 );
		add_action( 'bbp_approved_reply', array( $this, 'reply' ), 20, 1 );
		add_action( 'bbp_unspammed_reply', array( $this, 'reply' ), 20, 1 );
		add_action( 'transition_post_status', array( $this, 'transition' ), 20, 3 );
	}

	/**
	 * A post moved into `publish` (wp-admin's editor, an approval, a rescue from spam, bbPress's own insert).
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old status.
	 * @param \WP_Post $post       The post.
	 */
	public function transition( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( Posts::isTopic( $post ) ) {
			$this->topic( $post->ID );
		} elseif ( Posts::isReply( $post ) ) {
			$this->reply( $post->ID );
		}
	}

	/**
	 * A topic was published: queued when its forum is marked and it is no ticket yet.
	 *
	 * @param int|mixed $topic_id The topic.
	 */
	public function topic( $topic_id ): void {
		$topic = get_post( (int) $topic_id );
		if ( ! Posts::isTopic( $topic ) || bbp_get_public_status_id() !== $topic->post_status || Posts::ticketOf( $topic->ID ) > 0 ) {
			return;
		}
		if ( ! $this->settings->isMarked( Posts::forumOf( $topic->ID ) ) || ( 0 === (int) $topic->post_author && ! $this->settings->guests() ) ) {
			return;
		}
		$this->queue->add( TopicSender::KIND, $topic->ID );
	}

	/**
	 * A reply was published: queued when its topic's forum is marked and it is not the plugin's own posting. The support user replies round
	 * (2026-11-04): OmnaBase's sign alone (the message id, or the posted-as mark written in the reply's own save) says a reply is the plugin's;
	 * the support user's hand-written reply is queued like any moderator's. The "posting right now" switch stays as the first guard.
	 *
	 * @param int|mixed $reply_id The reply.
	 */
	public function reply( $reply_id ): void {
		$reply = get_post( (int) $reply_id );
		if ( ReplyPoster::isPosting() || ! Posts::isReply( $reply ) || bbp_get_public_status_id() !== $reply->post_status ) {
			return;
		}
		if ( '' !== Posts::messageOf( $reply->ID ) || '' !== Posts::postedAsOf( $reply->ID ) ) {
			return; // a reply the plugin posted carries OmnaBase's sign, whoever it was posted under
		}
		if ( $this->settings->isMarked( Posts::forumOf( Posts::topicOf( $reply ) ) ) ) {
			$this->queue->add( ReplySender::KIND, $reply->ID );
		}
	}
}
