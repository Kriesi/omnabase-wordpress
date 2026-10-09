<?php
/**
 * What the message centre's three forms ask of OmnaBase: a new ticket, an answer, the confirmation link.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

defined( 'ABSPATH' ) || exit;

/**
 * Each request is made as the visitor the caller names (their site id) and comes back as a notice code (`Notices`) and, for a new
 * ticket, its number. A new ticket's Idempotency-Key comes from the token printed in its form with the words sent, so the same form sent
 * twice (a double click, the browser's resend) is one ticket and a freshly loaded form is a new question. The fields follow OmnaBase's own
 * limits (a subject of 1 to 200 characters, a message or answer of 1 to 50 000).
 */
final class Requests {

	private const TOKEN_LENGTH = 32;
	private const SUBJECT_MAX  = 200;
	private const MESSAGE_MAX  = 50000;

	/**
	 * Builds the requests.
	 *
	 * @param PortalApi $api   The calls.
	 * @param LinkLimit $limit The confirmation links' limit.
	 */
	public function __construct( private PortalApi $api, private LinkLimit $limit ) {}

	/** A fresh token for a New ticket form. */
	public static function token(): string {
		return wp_generate_password( self::TOKEN_LENGTH, false, false );
	}

	/**
	 * Files the visitor's new ticket.
	 *
	 * @param string $site_id The visitor.
	 * @param string $subject The subject.
	 * @param string $message The message.
	 * @param string $token   The form's token.
	 * @param string $project The project's id ('' when OmnaBase picks the key's one project).
	 * @return array{notice:string,ticket:?int}
	 */
	public function create( string $site_id, string $subject, string $message, string $token, string $project ): array {
		$subject = trim( $subject );
		$message = trim( $message );
		if ( self::TOKEN_LENGTH !== strlen( $token ) || ! ctype_alnum( $token ) ) {
			return self::outcome( Notices::EXPIRED );
		}
		if ( '' === $subject || '' === $message || mb_strlen( $subject ) > self::SUBJECT_MAX || mb_strlen( $message ) > self::MESSAGE_MAX ) {
			return self::outcome( Notices::FIELDS );
		}
		$body   = array( 'subject' => $subject, 'message' => $message ) + ( '' === $project ? array() : array( 'project' => $project ) );
		$key    = 'obwp-portal-' . substr( hash( 'sha256', implode( '|', array( 'portal', $token, $site_id, $subject, $message ) ) ), 0, 40 );
		$answer = $this->api->create( $site_id, $body, $key );
		if ( in_array( $answer['status'], array( 200, 201 ), true ) && isset( $answer['body']['number'] ) ) {
			return self::outcome( Notices::CREATED, (int) $answer['body']['number'] );
		}
		// a 404 here is the project, gone from the key: nothing the visitor can fix
		return self::outcome( 404 === $answer['status'] ? Notices::UNAVAILABLE : self::refused( $answer['status'] ) );
	}

	/**
	 * Sends the visitor's answer on one of their tickets.
	 *
	 * @param string $site_id The visitor.
	 * @param int    $number  The ticket's number.
	 * @param string $body    The answer.
	 * @return array{notice:string,ticket:?int}
	 */
	public function reply( string $site_id, int $number, string $body ): array {
		$body = trim( $body );
		if ( '' === $body || mb_strlen( $body ) > self::MESSAGE_MAX ) {
			return self::outcome( Notices::NO_ANSWER, $number );
		}
		$status = $this->api->reply( $site_id, $number, $body )['status'];
		return self::outcome( in_array( $status, array( 200, 201 ), true ) ? Notices::REPLIED : self::refused( $status ), $number );
	}

	/**
	 * Asks for the confirmation link, at most three times a day per user.
	 *
	 * @param int    $user_id The user.
	 * @param string $site_id Their site id.
	 * @return array{notice:string,ticket:?int}
	 */
	public function confirm( int $user_id, string $site_id ): array {
		if ( ! $this->limit->allow( $user_id ) ) {
			return self::outcome( Notices::LINKS_USED );
		}
		return self::outcome( 200 === $this->api->confirm( $site_id )['status'] ? Notices::LINK_SENT : Notices::LINK_LATER );
	}

	/**
	 * The notice for a refused or failed write.
	 *
	 * @param int|null $status OmnaBase's status, or null when no answer came.
	 */
	private static function refused( ?int $status ): string {
		return match ( $status ) {
			404     => Notices::NOT_FOUND,
			403     => Notices::UNAVAILABLE,
			400     => Notices::FAILED,
			default => Notices::LATER,
		};
	}

	/**
	 * A result.
	 *
	 * @param string   $notice The notice's code.
	 * @param int|null $ticket The ticket it is about.
	 * @return array{notice:string,ticket:?int}
	 */
	private static function outcome( string $notice, ?int $ticket = null ): array {
		return array( 'notice' => $notice, 'ticket' => $ticket );
	}
}
