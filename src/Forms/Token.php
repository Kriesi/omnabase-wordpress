<?php
/**
 * The form's single-use time token.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The form's script fetches a token when the page loads (the page itself never holds one, so a cached page is shared safely). A token
 * belongs to one project and is taken from 3 seconds after it was handed out (faster than any person fills a form) until 2 hours after.
 * It is kept as a transient under a hash of itself: the project, when it was handed out, and once used the fingerprint of the message it
 * carried and the answer the visitor read. Used again with the same words (a double click) it answers the first answer again; with other
 * words it is refused. Its Idempotency-Key for OmnaBase comes from the token, so every try of one message is one ticket.
 */
final class Token {

	public const FRESH   = 'fresh';
	public const REPEAT  = 'repeat';
	public const USED    = 'used';
	public const TOO_NEW = 'too_new';
	public const INVALID = 'invalid';

	private const MIN_AGE = 3;
	private const MAX_AGE = 2 * HOUR_IN_SECONDS;
	private const LENGTH  = 32;

	/**
	 * A new token for a project.
	 *
	 * @param string $slug The project's slug.
	 */
	public function issue( string $slug ): string {
		$token = wp_generate_password( self::LENGTH, false, false );
		set_transient( self::name( $token ), array( 'project' => $slug, 'issued' => time(), 'fingerprint' => '', 'answer' => null ), self::MAX_AGE );
		return $token;
	}

	/**
	 * Checks a token for a message and, when it is fresh, marks it used by that message.
	 *
	 * @param string $token       The token the form sent.
	 * @param string $slug        The project the form sends to.
	 * @param string $fingerprint The message's fingerprint.
	 * @return array{state:string,answer:?Answer}
	 */
	public function take( string $token, string $slug, string $fingerprint ): array {
		$record = self::valid( $token ) ? get_transient( self::name( $token ) ) : false;
		$age    = is_array( $record ) ? time() - (int) ( $record['issued'] ?? 0 ) : PHP_INT_MAX;
		if ( ! is_array( $record ) || $slug !== ( $record['project'] ?? '' ) || $age > self::MAX_AGE ) {
			return self::result( self::INVALID );
		}
		if ( '' !== (string) ( $record['fingerprint'] ?? '' ) ) {
			$same = hash_equals( (string) $record['fingerprint'], $fingerprint );
			return self::result( $same ? self::REPEAT : self::USED, $same && is_array( $record['answer'] ) ? Answer::fromArray( $record['answer'] ) : null );
		}
		if ( $age < self::MIN_AGE ) {
			return self::result( self::TOO_NEW );
		}
		$record['fingerprint'] = $fingerprint;
		$this->keep( $token, $record );
		return self::result( self::FRESH );
	}

	/**
	 * Keeps the answer a used token's message got, for a repeat.
	 *
	 * @param string $token  The token.
	 * @param Answer $answer The answer.
	 */
	public function remember( string $token, Answer $answer ): void {
		$record = get_transient( self::name( $token ) );
		if ( is_array( $record ) ) {
			$record['answer'] = $answer->toArray();
			$this->keep( $token, $record );
		}
	}

	/**
	 * The Idempotency-Key a token's message is sent with (at most 64 characters, the outbox's column).
	 *
	 * @param string $token The token.
	 */
	public static function idempotencyKey( string $token ): string {
		return 'obwp-form-' . substr( hash( 'sha256', 'idempotency|' . $token ), 0, 40 );
	}

	/**
	 * Stores a token's record for the rest of its two hours.
	 *
	 * @param string              $token  The token.
	 * @param array<string,mixed> $record Its record.
	 */
	private function keep( string $token, array $record ): void {
		$left = self::MAX_AGE - ( time() - (int) $record['issued'] );
		set_transient( self::name( $token ), $record, max( 1, $left ) );
	}

	/**
	 * Whether a string can be a token at all.
	 *
	 * @param string $token The string.
	 */
	private static function valid( string $token ): bool {
		return self::LENGTH === strlen( $token ) && ctype_alnum( $token );
	}

	/**
	 * The transient's name: a hash of the token, never the token itself.
	 *
	 * @param string $token The token.
	 */
	private static function name( string $token ): string {
		return Options::FORM_TRANSIENT . 't_' . substr( hash( 'sha256', $token ), 0, 40 );
	}

	/**
	 * A check's result.
	 *
	 * @param string      $state  One of the constants.
	 * @param Answer|null $answer The first answer, for a repeat.
	 * @return array{state:string,answer:?Answer}
	 */
	private static function result( string $state, ?Answer $answer = null ): array {
		return array( 'state' => $state, 'answer' => $answer );
	}
}
