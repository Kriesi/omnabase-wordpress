<?php
/**
 * OmnaBase's mail to a topic's author about an answer it posted (the forum reply mails round, 2026-11-03).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * While the switch "OmnaBase mails the customer about answers" is on, OmnaBase mails a topic's author about each answer the plugin posts,
 * and bbPress's own subscriber mail about that answer leaves the author out (so nobody gets two mails about one answer). The choice is
 * made once, when the reply is posted, and kept on the reply (`_omnabase_customer_mail`): the subscriber job runs later and reads the mark,
 * so a switch flipped in between never gives the author two mails or none. Never for a guest's topic (no account: bbPress never mails a
 * guest and nobody checked the address) nor for a password-protected one (bbPress leaves its words out of its own mails).
 */
final class CustomerMail {

	/** bbPress's filter on the users it mails about a reply (bbPress 2.6, inside `bbp_notify_topic_subscribers`). */
	public const SUBSCRIBERS_FILTER = 'bbp_topic_subscription_user_ids';

	/**
	 * Builds the part.
	 *
	 * @param Settings $settings The forum settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Marks a newly posted reply when OmnaBase mails its topic's author about it.
	 *
	 * @param int      $reply_id The reply the plugin posted.
	 * @param \WP_Post $topic    Its topic.
	 */
	public function decide( int $reply_id, \WP_Post $topic ): void {
		if ( $this->settings->customerMail() && (int) $topic->post_author > 0 && '' === (string) $topic->post_password ) {
			update_post_meta( $reply_id, Options::CUSTOMER_MAIL_META, '1' );
		}
	}

	/**
	 * Whether OmnaBase mails the topic's author about this reply (decided when it was posted).
	 *
	 * @param int $reply_id The reply.
	 */
	public static function isMarked( int $reply_id ): bool {
		return '1' === (string) get_post_meta( $reply_id, Options::CUSTOMER_MAIL_META, true );
	}

	/**
	 * Runs bbPress's subscriber mail for a reply; for a marked reply the topic's author leaves bbPress's list through its own filter, added
	 * just around the call and removed after it.
	 *
	 * @param int      $reply_id The reply.
	 * @param int      $topic_id Its topic.
	 * @param callable $mail     The call to bbPress's subscriber mail.
	 */
	public static function aroundSubscriberMail( int $reply_id, int $topic_id, callable $mail ): void {
		if ( ! self::isMarked( $reply_id ) ) {
			$mail();
			return;
		}
		$author = (int) get_post_field( 'post_author', $topic_id );
		$out    = static fn ( $ids ) => array_values( array_filter( (array) $ids, static fn ( $id ) => (int) $id !== $author ) );
		add_filter( self::SUBSCRIBERS_FILTER, $out, 10, 1 );
		try {
			$mail();
		} finally {
			remove_filter( self::SUBSCRIBERS_FILTER, $out, 10 );
		}
	}
}
