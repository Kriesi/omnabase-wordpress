<?php
/**
 * What the plugin answers OmnaBase about a reply it posted (the forum reply mails round, 2026-11-03).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to the events receiver's answer filter (`omnabase_webhook_answer`): for a `message.sent` the plugin posted, the 200 answer gains
 * `posted: { id: "reply-<reply id>", url: <the reply's address> }`, and `mail: "omnabase"` when the reply is marked for OmnaBase's mail to
 * the topic's author. The answer is read from the posted reply itself, so a repeated delivery (the same delivery id, or the same message
 * under a new one) answers the same `posted` as the first time and a retry after a slow first answer never loses the mail.
 */
final class PostedAnswer {

	public const FILTER = 'omnabase_webhook_answer';

	/** Registers the filter. */
	public function register(): void {
		add_filter( self::FILTER, array( $this, 'add' ), 10, 3 );
	}

	/**
	 * The answer's body with `posted` for a reply the plugin posted.
	 *
	 * @param array<string,mixed>|mixed $body    The answer so far.
	 * @param string                    $type    The event.
	 * @param array<string,mixed>|mixed $payload The event as sent.
	 * @return array<string,mixed>
	 */
	public function add( $body, $type, $payload ): array {
		$body = is_array( $body ) ? $body : array();
		$id   = is_array( $payload ) && is_string( $payload['data']['message']['id'] ?? null ) ? $payload['data']['message']['id'] : '';
		if ( 'message.sent' !== $type || '' === $id ) {
			return $body;
		}
		$reply = Posts::postedFor( $id );
		if ( $reply < 1 ) {
			return $body;
		}
		$posted = array( 'id' => 'reply-' . $reply, 'url' => bbp_get_reply_url( $reply ) );
		if ( CustomerMail::isMarked( $reply ) ) {
			$posted['mail'] = 'omnabase';
		}
		return array( 'posted' => $posted ) + $body;
	}
}
