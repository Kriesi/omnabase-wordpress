<?php
/**
 * The events receiver: `POST /wp-json/omnabase/v1/events`, where OmnaBase sends the site's webhooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Verify, then handle once, then record. The signature is checked in the route's `permission_callback` (an unsigned call never reaches
 * the handler). A delivery id handled before is answered 200 without acting (OmnaBase's retry or "Redeliver"). A new one fires the action
 * `omnabase_webhook( $type, $payload, $delivery )` (round three's forum code listens there; a `ping` is also recorded for "Check connection")
 * and is recorded as handled only when that ran through; a listener's `Refusal` answers its own status (the forum: 409 for a topic that is
 * gone, 503 without a support user), any other throw answers 500, and either way OmnaBase tries again.
 */
final class Events {

	public const NAMESPACE = 'omnabase/v1';
	public const ROUTE     = '/events';

	/**
	 * Builds the receiver.
	 *
	 * @param SignedCall  $door   The signature check.
	 * @param ReplayGuard $replay The handled delivery ids.
	 * @param DeliveryLog $log    The last 20 deliveries.
	 * @param LastPing    $ping   When the last ping arrived.
	 */
	public function __construct( private SignedCall $door, private ReplayGuard $replay, private DeliveryLog $log, private LastPing $ping ) {}

	/** Registers the route at `rest_api_init`. */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoute' ) );
	}

	/** The route. */
	public function registerRoute(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'receive' ),
				'permission_callback' => fn ( \WP_REST_Request $request ) => $this->door->check( $request, 'webhooks' ),
			)
		);
	}

	/**
	 * Handles a signed delivery once.
	 *
	 * @param \WP_REST_Request $request The signed call.
	 */
	public function receive( \WP_REST_Request $request ): \WP_REST_Response {
		$payload  = json_decode( (string) $request->get_body(), true );
		$payload  = is_array( $payload ) ? $payload : array();
		$type     = is_string( $payload['type'] ?? null ) ? $payload['type'] : (string) $request->get_header( 'x-omnabase-event' );
		$delivery = (string) ( $request->get_header( 'x-omnabase-delivery' ) ?? '' );
		$delivery = '' !== $delivery ? $delivery : (string) ( $payload['id'] ?? '' );
		$ticket   = isset( $payload['data']['ticket']['number'] ) ? (int) $payload['data']['ticket']['number'] : null;

		if ( '' !== $delivery && $this->replay->handled( $delivery ) ) {
			$this->log->add( $type, $delivery, $ticket, 'repeat' );
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}
		$refusal = $this->handle( $type, $payload, $delivery );
		if ( null !== $refusal ) {
			$this->log->add( $type, $delivery, $ticket, $refusal['outcome'] );
			return new \WP_REST_Response( array( 'ok' => false, 'reason' => $refusal['reason'] ), $refusal['status'] );
		}
		if ( '' !== $delivery ) {
			$this->replay->remember( $delivery );
		}
		$this->log->add( $type, $delivery, $ticket, 'handled' );
		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The handling: the ping's record, then whoever listens. Null when it ran through, else the answer to give.
	 *
	 * @param string              $type     The event type.
	 * @param array<string,mixed> $payload  The event as sent.
	 * @param string              $delivery The delivery id.
	 * @return array{status:int,outcome:string,reason:string}|null
	 */
	private function handle( string $type, array $payload, string $delivery ): ?array {
		try {
			if ( 'ping' === $type ) {
				$this->ping->record();
			}
			/**
			 * A signed, new webhook from OmnaBase.
			 *
			 * @param string $type     The event (`ping`, `message.sent`, `ticket.updated` …).
			 * @param array  $payload  The event as OmnaBase sent it.
			 * @param string $delivery The delivery id (the same on every retry).
			 */
			do_action( 'omnabase_webhook', $type, $payload, $delivery );
		} catch ( Refusal $r ) {
			return array( 'status' => min( 599, max( 400, $r->status ) ), 'outcome' => 'refused', 'reason' => $r->getMessage() );
		} catch ( \Throwable $e ) {
			return array( 'status' => 500, 'outcome' => 'failed', 'reason' => '' );
		}
		return null;
	}
}
