<?php
/**
 * What happens to an outbox row after a try: sent, waiting for the next try, or needing the administrator.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * The outbox never drops a row. A 2xx is sent. A 429 waits exactly the `Retry-After` OmnaBase names (seconds or a date) and never needs
 * attention, however long OmnaBase's daily limit holds it. A failure that may pass (no answer, a 5xx, a refresh whose facts call failed:
 * `409 facts_failed`) waits 1, 5, 15, 60 minutes, then hourly, and needs attention once the row has been trying for more than 3 days
 * (OmnaBase retries its own webhooks about 45 hours). Any other answer (400, 404, `409 forgotten`, `address_taken`, `no_endpoint`, a topic's
 * `409 conflict`) will never succeed by waiting: it needs attention at once, with the reason in words. Round three: a 429 for OmnaBase's daily
 * limit of site tickets (`api_tickets`) says so; `409 idempotency_in_flight` waits like a 5xx; `409 idempotency_mismatch` (the post was
 * edited between a lost answer and the resend, so the same key came with another body) needs attention with a plain reason.
 */
final class Policy {

	public const SENT      = 'sent';
	public const WAITING   = 'waiting';
	public const ATTENTION = 'attention';
	public const DISCARDED = 'discarded';

	/** Seconds to wait after the first, second, third, fourth failed try; every later one waits the last. */
	private const BACKOFF = array( 60, 300, 900, 3600 );

	private const GIVE_UP_AFTER = 3 * 86400;

	/** Without a usable `Retry-After`, a 429 waits a minute. */
	private const DEFAULT_RETRY_AFTER = 60;

	/**
	 * The row's next state.
	 *
	 * @param array{status:?int,headers:array<string,string>,body:?array,error:?string} $outcome What the try answered (status null: no answer).
	 * @param array{attempts:int,created_at:int}                                       $row     The row: failed tries before this one, when it was made.
	 * @param int                                                                      $now     The time now.
	 * @return array{state:string,next_at:?int,reason:string}
	 */
	public function decide( array $outcome, array $row, int $now ): array {
		$status = $outcome['status'];
		if ( null !== $status && $status >= 200 && $status < 300 ) {
			return self::result( self::SENT, null, '' );
		}
		if ( 429 === $status ) {
			$next = $now + self::retryAfter( (string) ( $outcome['headers']['retry-after'] ?? '' ), $now );
			return self::result( self::WAITING, $next, 'api_tickets' === self::detail( $outcome ) ? self::dailyLimit() : __( 'OmnaBase asked the site to slow down (too many calls); the next try is when it said.', 'omnabase' ) );
		}
		$reason = self::reason( $outcome );
		if ( ! self::passing( $outcome ) ) {
			return self::result( self::ATTENTION, null, $reason );
		}
		if ( $now - (int) $row['created_at'] > self::GIVE_UP_AFTER ) {
			/* translators: %s: the last failure in words. */
			return self::result( self::ATTENTION, null, sprintf( __( 'Still failing after 3 days: %s', 'omnabase' ), $reason ) );
		}
		$wait = self::BACKOFF[ min( max( 0, (int) $row['attempts'] ), count( self::BACKOFF ) - 1 ) ];
		return self::result( self::WAITING, $now + $wait, $reason );
	}

	/**
	 * A row its job holds back (a reply whose topic has no ticket yet): it waits the given seconds, and needs attention once it has been
	 * waiting for more than 3 days.
	 *
	 * @param int                     $seconds How long to wait.
	 * @param string                  $reason  Why, in words.
	 * @param array{created_at:int}   $row     The row.
	 * @param int                     $now     The time now.
	 * @return array{state:string,next_at:?int,reason:string}
	 */
	public function hold( int $seconds, string $reason, array $row, int $now ): array {
		if ( $now - (int) $row['created_at'] > self::GIVE_UP_AFTER ) {
			/* translators: %s: why the call waits, in words. */
			return self::result( self::ATTENTION, null, sprintf( __( 'Still waiting after 3 days: %s', 'omnabase' ), $reason ) );
		}
		return self::result( self::WAITING, $now + $seconds, $reason );
	}

	/**
	 * A row met OmnaBase's 401 (the key was revoked there): it waits, due at once, and goes with the first run after Connect.
	 *
	 * @param int $now The time now.
	 * @return array{state:string,next_at:?int,reason:string}
	 */
	public static function paused( int $now ): array {
		return self::result( self::WAITING, $now, __( 'Waiting: someone disconnected this site in OmnaBase. It goes once you click Connect.', 'omnabase' ) );
	}

	/** The reason a topic waits for OmnaBase's daily limit of new tickets from connected sites (the screen counts the rows that say it). */
	public static function dailyLimit(): string {
		return __( 'OmnaBase\'s daily limit for new tickets from connected sites is reached; the next try is when it said.', 'omnabase' );
	}

	/**
	 * Whether a failure may pass by waiting: no answer, a 5xx, a refresh whose facts call to the site failed, or OmnaBase still working on
	 * an earlier try with the same idempotency key.
	 *
	 * @param array{status:?int,body:?array} $outcome The answer.
	 */
	private static function passing( array $outcome ): bool {
		$status = $outcome['status'];
		return null === $status || $status >= 500 || ( 409 === $status && in_array( self::detail( $outcome ), array( 'facts_failed', 'idempotency_in_flight' ), true ) );
	}

	/**
	 * The seconds a `Retry-After` names (a number of seconds or an HTTP date).
	 *
	 * @param string $header The header.
	 * @param int    $now    The time now.
	 */
	private static function retryAfter( string $header, int $now ): int {
		$header = trim( $header );
		if ( '' !== $header && ctype_digit( $header ) ) {
			return max( 1, (int) $header );
		}
		$when = '' !== $header ? strtotime( $header ) : false;
		return false !== $when && $when > $now ? $when - $now : self::DEFAULT_RETRY_AFTER;
	}

	/**
	 * What went wrong, in words (OmnaBase's message, never its raw answer).
	 *
	 * @param array{status:?int,body:?array,error:?string} $outcome The answer.
	 */
	private static function reason( array $outcome ): string {
		if ( null === $outcome['status'] ) {
			/* translators: %s: the network error. */
			return sprintf( __( 'OmnaBase could not be reached: %s', 'omnabase' ), (string) ( $outcome['error'] ?? __( 'no answer', 'omnabase' ) ) );
		}
		$detail = self::detail( $outcome );
		if ( 'idempotency_mismatch' === $detail ) {
			return __( 'The post changed after a try whose answer was lost, so OmnaBase refused the resend. Check OmnaBase for the ticket, then Retry or Discard.', 'omnabase' );
		}
		$message = $outcome['body']['error']['message'] ?? '';
		$message = is_string( $message ) ? mb_substr( trim( $message ), 0, 300 ) : '';
		/* translators: 1: the HTTP status, 2: OmnaBase's message, 3: OmnaBase's reason code in brackets, or nothing. */
		return trim( sprintf( __( 'OmnaBase answered %1$d. %2$s %3$s', 'omnabase' ), $outcome['status'], $message, '' !== $detail ? '(' . $detail . ')' : '' ) );
	}

	/**
	 * OmnaBase's `details.reason`, or an empty string.
	 *
	 * @param array{body:?array} $outcome The answer.
	 */
	private static function detail( array $outcome ): string {
		$detail = $outcome['body']['error']['details']['reason'] ?? '';
		return is_string( $detail ) ? (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( $detail ) ) : '';
	}

	/**
	 * A decision.
	 *
	 * @param string   $state  The state.
	 * @param int|null $next   The next try.
	 * @param string   $reason Why, in words.
	 * @return array{state:string,next_at:?int,reason:string}
	 */
	private static function result( string $state, ?int $next, string $reason ): array {
		return array( 'state' => $state, 'next_at' => $next, 'reason' => $reason );
	}
}
