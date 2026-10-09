<?php
/**
 * A ticket resolved in OmnaBase closes its forum topic, when the administrator turned that on.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to `omnabase_webhook`. A resolve arrives as `ticket.resolved` (OmnaBase's fan-out; Connect asks for it since round three) or as
 * `ticket.updated` whose change is the status `resolved`. With the switch on, the topic its site reference names is closed through bbPress
 * (`bbp_close_topic`), so new replies stop as bbPress's own "Close" does; moderators can still reply. Nothing reopens it: bbPress lets no
 * customer reply on a closed topic, so a customer's follow-up starts a new topic (DECISIONS, round three).
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
		$data = is_array( $payload['data'] ?? null ) ? $payload['data'] : array();
		if ( ! self::isResolve( (string) $type, $data ) || ! $this->settings->closeResolved() ) {
			return;
		}
		$topic = get_post( Posts::topicFromReference( $data['ticket']['external']['id'] ?? null ) );
		if ( Posts::isTopic( $topic ) && bbp_get_public_status_id() === $topic->post_status ) {
			bbp_close_topic( $topic->ID );
		}
	}

	/**
	 * Whether the event says the ticket was resolved.
	 *
	 * @param string              $type The event.
	 * @param array<string,mixed> $data Its data.
	 */
	private static function isResolve( string $type, array $data ): bool {
		if ( 'ticket.resolved' === $type ) {
			return true;
		}
		if ( 'ticket.updated' !== $type ) {
			return false;
		}
		$changes = is_array( $data['changes'] ?? null ) ? $data['changes'] : array();
		$status  = array_key_exists( 'status', $changes ) ? $changes['status'] : ( isset( $data['changes'] ) ? null : ( $data['ticket']['status'] ?? null ) );
		return 'resolved' === $status;
	}
}
