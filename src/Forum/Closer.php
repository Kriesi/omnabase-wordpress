<?php
/**
 * A ticket's status in OmnaBase puts its forum topic in step: resolved closes it, open or pending reopens it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to `omnabase_webhook` (the forum sync round, 2026-11-02, overturning round three's "No reopening"). With the switch "Keep topics
 * in step with tickets" on (its default):
 * - `ticket.resolved` closes the topic its site reference names, through bbPress's own Close (only moderators can still reply);
 * - `ticket.updated` whose change is the status `open` or `pending` reopens a closed topic, so the customer can answer the team;
 * - `ticket.updated` with the status `resolved` changes nothing: that is how OmnaBase sends the AI's automatic answer that resolved the
 *   ticket, and the topic stays open so the customer can say it did not help (the founder's decision of 2026-11-02).
 * A message older than the newest one the topic applied (a smaller `seq`) changes nothing. The plugin's own close or reopen is never sent
 * back (`TopicStatus`).
 */
final class Closer {

	/**
	 * Builds the closer.
	 *
	 * @param Settings $settings The forum settings.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * A signed, new webhook.
	 *
	 * @param string              $type    The event.
	 * @param array<string,mixed> $payload The event as sent.
	 */
	public function handle( $type, $payload ): void {
		$data   = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		$closed = self::closes( (string) $type, $data );
		if ( null === $closed || ! $this->settings->inStep() ) {
			return;
		}
		$topic = get_post( Posts::topicFromReference( $data['ticket']['external']['id'] ?? null ) );
		$seq   = is_int( $payload['seq'] ?? null ) ? $payload['seq'] : null;
		if ( ! Posts::isTopic( $topic ) || ! TopicStatus::isNewer( $topic->ID, $seq ) ) {
			return;
		}
		TopicStatus::remember( $topic->ID, $seq );
		TopicStatus::apply( $topic, $closed );
	}

	/**
	 * Whether the event closes the topic (true), reopens it (false), or leaves it alone (null).
	 *
	 * @param string              $type The event.
	 * @param array<string,mixed> $data Its data.
	 */
	private static function closes( string $type, array $data ): ?bool {
		if ( 'ticket.resolved' === $type ) {
			return true;
		}
		$changes = is_array( $data['changes'] ?? null ) ? $data['changes'] : array();
		if ( 'ticket.updated' !== $type || ! in_array( $changes['status'] ?? null, array( 'open', 'pending' ), true ) ) {
			return null;
		}
		return false;
	}
}
