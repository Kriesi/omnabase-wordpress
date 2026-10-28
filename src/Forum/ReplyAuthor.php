<?php
/**
 * Who posts OmnaBase's reply on the forum: the team member's own forum account when it is a moderator's, else the support user.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * The post-as-member round (2026-10-29). `message.sent`'s `message.author.email` names the member who wrote the reply (OmnaBase sends it
 * only on the delivery to the ticket's own site, for a current member). The poster is the site user with exactly that address (WordPress
 * compares addresses without case) when they hold bbPress's `moderate` right (they may reply on closed topics, and Avia Support shows a
 * moderator's private data to the topic's author); else the support user; else 0 (the poster then answers 503). The filter
 * `omnabase_forum_reply_author( int $user_id, array $message, \WP_Post $topic )` may choose another user; an answer without `moderate` is
 * replaced by the support user. An AI reply names no address, so it always goes out as the support user.
 */
final class ReplyAuthor {

	/**
	 * Builds the chooser.
	 *
	 * @param Settings $settings The forum settings (the support user).
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * The user who posts a message on a topic, or 0 when nobody can.
	 *
	 * @param array<string,mixed> $message OmnaBase's message.
	 * @param \WP_Post            $topic   The topic.
	 */
	public function of( array $message, \WP_Post $topic ): int {
		$support = $this->settings->supportUser();
		$member  = $this->moderatorWith( $message['author']['email'] ?? null );
		/**
		 * Filters who posts OmnaBase's reply on the forum. The answer must hold bbPress's `moderate` right, else the support user posts.
		 *
		 * @param int                 $user_id The poster chosen so far: the moderator with the member's address, else the support user, else 0.
		 * @param array<string,mixed> $message OmnaBase's message as delivered.
		 * @param \WP_Post            $topic   The topic the reply goes on.
		 */
		$chosen = (int) apply_filters( 'omnabase_forum_reply_author', $member > 0 ? $member : $support, $message, $topic );
		return Settings::canModerate( $chosen ) ? $chosen : $support;
	}

	/**
	 * The site user with this address when they can moderate, else 0.
	 *
	 * @param mixed $email The address the event names.
	 */
	private function moderatorWith( $email ): int {
		if ( ! is_string( $email ) || ! is_email( $email ) ) {
			return 0;
		}
		$user = get_user_by( 'email', $email );
		return $user instanceof \WP_User && Settings::canModerate( (int) $user->ID ) ? (int) $user->ID : 0;
	}
}
