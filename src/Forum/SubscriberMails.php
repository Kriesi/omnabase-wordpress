<?php
/**
 * bbPress's mails to a topic's subscribers about OmnaBase's reply, sent after the receiver answered.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * bbPress mails a topic's subscribers from its own form's moment, which a reply posted through `bbp_insert_reply` never fires; and mail can
 * be slow, while OmnaBase waits five seconds for the receiver's answer. So the poster leaves a one-off WP-Cron job (WordPress's timer)
 * `omnabase_forum_mails` with the reply's id, WP-Cron is woken at the end of the request, and the job hands the reply to bbPress's own
 * subscriber mail (which leaves out the reply's author and anyone who cannot read the topic).
 */
final class SubscriberMails {

	public const HOOK = 'omnabase_forum_mails';

	/** Whether this request left a job (WP-Cron is then woken at its end). */
	private bool $woken = false;

	/** Registers the job and the wake-up. */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'send' ), 10, 1 );
		add_action( 'shutdown', array( $this, 'wake' ) );
	}

	/**
	 * Leaves the mails about a reply for later.
	 *
	 * @param int $reply_id The reply the plugin posted.
	 */
	public function later( int $reply_id ): void {
		wp_schedule_single_event( time(), self::HOOK, array( $reply_id ) );
		$this->woken = true;
	}

	/**
	 * The job: bbPress mails the topic's subscribers about the reply.
	 *
	 * @param int|mixed $reply_id The reply.
	 */
	public function send( $reply_id ): void {
		$reply = get_post( (int) $reply_id );
		if ( ! Posts::isReply( $reply ) || '' === Posts::messageOf( $reply->ID ) || ! function_exists( 'bbp_notify_topic_subscribers' ) ) {
			return;
		}
		$topic = Posts::topicOf( $reply );
		bbp_notify_topic_subscribers( $reply->ID, $topic, Posts::forumOf( $topic ), array(), (int) $reply->post_author );
	}

	/** At the end of a request that left a job, WP-Cron is woken (not from WP-Cron itself or the command line). */
	public function wake(): void {
		if ( $this->woken && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->woken = false;
			spawn_cron();
		}
	}

	/** Clears every waiting job (deactivation). */
	public static function clear(): void {
		wp_unschedule_hook( self::HOOK );
	}
}
