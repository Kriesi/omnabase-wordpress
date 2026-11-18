<?php
/**
 * What the plugin last sent of a forum post, as a fingerprint kept beside the post.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The forum edits and pictures round (2026-11-18, finding 19): "a save that changes nothing sends nothing" is decided at the edit row's turn
 * by comparing the post's stored text and declared field values with what was last sent, never the shown form (a link preview filled in
 * later is no edit) and never at the hook (some field plugins save their values after WordPress's moment). The fingerprint is a hash of
 * both, kept as the post meta `_omnabase_sent_fingerprint` (post meta: a named value WordPress keeps beside a post); the title is not part of
 * it (a topic's title edit is not sent). Written when a first send builds its body and after an edit was accepted.
 */
final class Fingerprint {

	/**
	 * Builds the reader.
	 *
	 * @param PostFields $fields The declared post fields.
	 */
	public function __construct( private PostFields $fields ) {}

	/**
	 * Remembers a post as sent now.
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	public function remember( \WP_Post $post ): void {
		update_post_meta( $post->ID, Options::SENT_FINGERPRINT_META, $this->of( $post ) );
	}

	/**
	 * Whether a post still reads as it was last sent.
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	public function unchanged( \WP_Post $post ): bool {
		$last = (string) get_post_meta( $post->ID, Options::SENT_FINGERPRINT_META, true );
		return '' !== $last && hash_equals( $last, $this->of( $post ) );
	}

	/**
	 * The fingerprint of a post's stored text and field values.
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private function of( \WP_Post $post ): string {
		return hash( 'sha256', (string) $post->post_content . "\0" . (string) wp_json_encode( $this->fields->valuesOf( $post->ID ) ) );
	}
}
