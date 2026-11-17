<?php
/**
 * Whether OmnaBase may ask a topic's author for a rating (the customer rating round, 2026-11-17).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * While the switch "OmnaBase asks the customer for a rating" is on, a reply the plugin posts is marked (`_omnabase_rating_ask`), and the
 * plugin's answer to OmnaBase about that reply says `rating: "ask"`: OmnaBase then mails the topic's author one rating question after the
 * ticket is resolved. Decided once, when the reply is posted, like the answer mail's mark. Never for a guest's topic (no account) nor for a
 * password-protected one: OmnaBase cannot tell either on its own, so it asks nobody the plugin did not mark.
 */
final class RatingAsk {

	/**
	 * Builds the part.
	 *
	 * @param Settings $settings The forum settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Marks a newly posted reply when OmnaBase may ask its topic's author for a rating.
	 *
	 * @param int      $reply_id The reply the plugin posted.
	 * @param \WP_Post $topic    Its topic.
	 */
	public function decide( int $reply_id, \WP_Post $topic ): void {
		if ( $this->settings->askRating() && (int) $topic->post_author > 0 && '' === (string) $topic->post_password ) {
			update_post_meta( $reply_id, Options::RATING_ASK_META, '1' );
		}
	}

	/**
	 * Whether OmnaBase may ask the topic's author for a rating about this reply (decided when it was posted).
	 *
	 * @param int $reply_id The reply.
	 */
	public static function isMarked( int $reply_id ): bool {
		return '1' === (string) get_post_meta( $reply_id, Options::RATING_ASK_META, true );
	}
}
