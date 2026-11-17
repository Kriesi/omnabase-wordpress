<?php
/**
 * The marks a reply the plugin posts gets at the moment it is posted (the customer rating round, 2026-11-17).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * One place for the posting's decisions that the plugin later tells OmnaBase in its answer: whether OmnaBase mails the topic's author about
 * the answer (`CustomerMail`) and whether OmnaBase may ask them for a rating (`RatingAsk`). Each is decided once, when the reply is posted.
 */
final class ReplyMarks {

	/**
	 * Builds the part.
	 *
	 * @param CustomerMail $mail   OmnaBase's mail about the answer.
	 * @param RatingAsk    $rating OmnaBase's rating question.
	 */
	public function __construct( private CustomerMail $mail, private RatingAsk $rating ) {}

	/**
	 * Decides both marks for a newly posted reply.
	 *
	 * @param int      $reply_id The reply the plugin posted.
	 * @param \WP_Post $topic    Its topic.
	 */
	public function decide( int $reply_id, \WP_Post $topic ): void {
		$this->mail->decide( $reply_id, $topic );
		$this->rating->decide( $reply_id, $topic );
	}
}
