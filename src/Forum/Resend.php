<?php
/**
 * A forum reply that never reached its ticket is sent again, once (the support user replies round, 2026-11-04).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Outbox\Policy;
use OmnaBase\WordPress\Outbox\Queue;
use OmnaBase\WordPress\Outbox\Table;

defined( 'ABSPATH' ) || exit;

/**
 * One reply per call, the doors' shared service (today the WP-CLI command `wp omnabase forum resend <id>`). It answers a status and one
 * line of words:
 * - `sent`: the reply carries the delivered mark (OmnaBase accepted it): nothing to do;
 * - `refused`: the reply job's own skip rule says no (`ReplySender::whyNot`, the same words), or its topic is no ticket and none is on its way;
 * - `waiting`: a row for it waits already: made due now (the Activity tab's Send now), never a second row;
 * - `queued`: a row needing attention is tried again with a fresh key (the Activity tab's Retry), else a new row waits, due now.
 * A queued row goes with the next outbox run: on the command line WordPress's timer is not woken (`Schedule::wake`), so it waits for the next
 * visit, or `wp cron event run omnabase_outbox` sends it at once.
 */
final class Resend {

	/**
	 * Builds the service.
	 *
	 * @param Settings $settings The forum settings.
	 * @param Queue    $queue    The outbox.
	 * @param Table    $table    The outbox's table (the reply's open rows).
	 */
	public function __construct( private Settings $settings, private Queue $queue, private Table $table ) {}

	/**
	 * Sends one reply again, or says why not.
	 *
	 * @param int $reply_id The reply.
	 * @return array{status:string,words:string}
	 */
	public function resend( int $reply_id ): array {
		$reply = get_post( $reply_id );
		$reply = $reply instanceof \WP_Post ? $reply : null;
		if ( Posts::isReply( $reply ) && Posts::isDelivered( $reply->ID ) ) {
			/* translators: %d: the ticket's number. */
			return self::answer( 'sent', sprintf( __( 'Already sent to ticket #%d: nothing to do.', 'omnabase' ), Posts::ticketOf( Posts::topicOf( $reply ) ) ) );
		}
		$why = ReplySender::whyNot( $reply, $this->settings );
		if ( null !== $why ) {
			return self::answer( 'refused', $why );
		}
		$topic  = Posts::topicOf( $reply );
		$number = Posts::ticketOf( $topic );
		if ( $number < 1 && ! $this->table->isOpen( TopicSender::KIND . ':' . $topic ) ) {
			return self::answer( 'refused', __( 'Not sent: its topic is no ticket at OmnaBase.', 'omnabase' ) );
		}
		return $this->queueFor( $reply->ID, $number );
	}

	/**
	 * The reply's row: an open one goes again, else a new one waits.
	 *
	 * @param int $reply_id The reply.
	 * @param int $number   Its topic's ticket number (0 while the topic is on its way).
	 * @return array{status:string,words:string}
	 */
	private function queueFor( int $reply_id, int $number ): array {
		$open = $this->table->openOf( ReplySender::KIND, $reply_id )[0] ?? null;
		if ( null !== $open && Policy::WAITING === $open['state'] ) {
			$this->queue->sendNow( (int) $open['id'] );
			/* translators: %s: where the reply goes ("ticket #253"). */
			return self::answer( 'waiting', self::goes( __( 'Already waiting for %s: now due, it goes with the next outbox run.', 'omnabase' ), $number ) );
		}
		if ( null !== $open ) {
			$this->queue->retry( (int) $open['id'] );
		} else {
			$this->queue->add( ReplySender::KIND, $reply_id );
		}
		/* translators: %s: where the reply goes ("ticket #253"). */
		return self::answer( 'queued', self::goes( __( 'Queued for %s: it goes with the next outbox run.', 'omnabase' ), $number ) );
	}

	/**
	 * A sentence naming where the reply goes: its ticket, or its topic's ticket still to come.
	 *
	 * @param string $sentence The sentence, `%s` for the place.
	 * @param int    $number   The ticket number, or 0.
	 */
	private static function goes( string $sentence, int $number ): string {
		/* translators: %d: the ticket's number. */
		$place = $number > 0 ? sprintf( __( 'ticket #%d', 'omnabase' ), $number ) : __( 'its topic\'s ticket, once the topic is sent', 'omnabase' );
		return sprintf( $sentence, $place );
	}

	/**
	 * One answer.
	 *
	 * @param string $status `queued`, `waiting`, `sent` or `refused`.
	 * @param string $words  One line.
	 * @return array{status:string,words:string}
	 */
	private static function answer( string $status, string $words ): array {
		return array( 'status' => $status, 'words' => $words );
	}
}
