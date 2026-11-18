<?php
/**
 * What a job says about one of its rows when its turn comes: send this call, wait, skip it, or ask the administrator.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * A job decides when the row is sent, from the post or user as it is then (review S3: "decide in the sending job, not the hook"):
 * - `send`: the call to make; `idempotent` sends the row's own Idempotency-Key with it (the same on every try of that row);
 * - `wait`: not yet (a reply whose topic has no ticket yet), tried again after the given seconds;
 * - `skip`: nothing to send any more (the post went away, an echo of the plugin's own post): the row is discarded with the reason;
 * - `attention`: it cannot go without the administrator (a guest topic without an email address).
 * A `send` may also say that OmnaBase's 404 ends the row quietly (the forum edits and pictures round: an edit of a post OmnaBase never
 * filed is dropped, never "needs attention").
 */
final class Step {

	public const SEND      = 'send';
	public const WAIT      = 'wait';
	public const SKIP      = 'skip';
	public const ATTENTION = 'attention';

	/**
	 * Builds a step (use the named constructors).
	 *
	 * @param string                                                                $kind    One of the constants.
	 * @param array{method:string,path:string,body:mixed,timeout:int,idempotent:bool}|null $request The call, for `send`.
	 * @param string                                                                $reason  Why, in words, for the others.
	 * @param int                                                                   $seconds How long to wait, for `wait`.
	 * @param bool                                                                  $dropsNotFound Whether OmnaBase's 404 discards the row quietly.
	 */
	private function __construct( public readonly string $kind, public readonly ?array $request, public readonly string $reason, public readonly int $seconds, public readonly bool $dropsNotFound = false ) {}

	/** The same call, but OmnaBase's 404 ("no such post") discards the row instead of asking the administrator. */
	public function droppingNotFound(): self {
		return new self( $this->kind, $this->request, $this->reason, $this->seconds, true );
	}

	/**
	 * Send this call.
	 *
	 * @param string $method     The HTTP method.
	 * @param string $path       The path under /api/public/v1.
	 * @param mixed  $body       The JSON body.
	 * @param int    $timeout    Seconds for the whole call.
	 * @param bool   $idempotent Whether the row's Idempotency-Key goes with it.
	 */
	public static function send( string $method, string $path, $body, int $timeout, bool $idempotent = false ): self {
		return new self( self::SEND, array( 'method' => $method, 'path' => $path, 'body' => $body, 'timeout' => $timeout, 'idempotent' => $idempotent ), '', 0 );
	}

	/**
	 * Not yet: try again later.
	 *
	 * @param int    $seconds How long to wait.
	 * @param string $reason  Why, in words.
	 */
	public static function wait( int $seconds, string $reason ): self {
		return new self( self::WAIT, null, $reason, max( 1, $seconds ) );
	}

	/**
	 * Nothing to send any more.
	 *
	 * @param string $reason Why, in words.
	 */
	public static function skip( string $reason ): self {
		return new self( self::SKIP, null, $reason, 0 );
	}

	/**
	 * The administrator has to look.
	 *
	 * @param string $reason Why, in words.
	 */
	public static function attention( string $reason ): self {
		return new self( self::ATTENTION, null, $reason, 0 );
	}
}
