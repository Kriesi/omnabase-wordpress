<?php
/**
 * M13 round one, the red team's failing tests: the two receivers in a real WordPress (the REST addresses OmnaBase calls: `/events` for
 * webhooks, `/customer` for customer questions), their signature check, the replay guard and the deliveries log.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): "What exists": the two addresses `https://<host>/wp-json/omnabase/v1/events`
 * and `…/customer` (OmnaBase's `pluginUrl` in `packages/shared/src/connect.ts`), "**a `410` answer switches the address off**"; choice 3 "the
 * receivers answer 503 (never 410), so OmnaBase's retries carry the gap. … check the signature in their `permission_callback` … so an
 * unsigned call never reaches a handler; a delivery id is recorded **after** it was handled and kept 30 days"; choice 2 "the last 20 webhook
 * deliveries received (time, event, ticket number, what the plugin did; never content)"; "Round one": "`Rest/Events` (verify, record,
 * dispatch), `Rest/Customer` (answers `{}` until round two)"; round one's *Red team*: "unsigned calls reach no handler; unreadable credentials
 * answer 503, never 410; … a delivery handled once and recorded only after handling". OmnaBase `docs/public-api.md`: "`X-OmnaBase-Delivery`:
 * the send's id, the same on every retry and on a redelivery from the log: process each delivery id once". The coordinator: "pin a replayed
 * event id answers 200 without acting, a bad signature 401, a stale timestamp 401, the vault unreadable 503"; "the last 20 deliveries … (no
 * personal data beyond ids and the event name)".
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - Dispatch: once a delivery passed the check and is new, the events receiver fires the WordPress action
 *   `omnabase_webhook( string $event_type, array $payload, string $delivery_id )` (round three's forum code listens there).
 * - The replay guard keys on `X-OmnaBase-Delivery` (the docs' word; for one address it is one per event, so the coordinator's "event id"
 *   is the same thing: the tests send both unchanged on a replay).
 * - `OmnaBase\WordPress\Rest\DeliveryLog` with `new DeliveryLog()` and `recent(): array` (newest first, at most 20; each row names at least
 *   the event and the delivery id).
 * - Refusals: a bad or missing signature is 401; unreadable credentials 503; never 410 for anything.
 *
 * Why these fail today: no routes (WordPress answers 404 `rest_no_route`), no vault, no log.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Rest\DeliveryLog;
use OmnaBase\WordPress\Security\Vault;

final class ReceiversTest extends RoundOneTestCase {

	/** @var array<int,array{0:string,1:array,2:string}> what the dispatch action heard */
	private array $heard = array();

	public function set_up(): void {
		parent::set_up();
		( new Vault() )->save( self::CREDENTIALS );
		$this->heard = array();
		add_action(
			'omnabase_webhook',
			function ( $type, $payload = array(), $delivery = '' ) {
				$this->heard[] = array( (string) $type, (array) $payload, (string) $delivery );
			},
			10,
			3
		);
	}

	/**
	 * What it checks: The two addresses OmnaBase calls exist where OmnaBase calls them and take POST.
	 * Passes when: WordPress's REST server lists /omnabase/v1/events and /omnabase/v1/customer, each accepting POST.
	 * Fails when: the addresses are elsewhere, so every reply and customer question from OmnaBase meets "no route".
	 */
	public function test_both_receivers_are_where_omnabase_calls_them(): void {
		$routes = $this->rest()->get_routes();
		foreach ( array( '/omnabase/v1/events', '/omnabase/v1/customer' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
			$methods = array();
			foreach ( $routes[ $route ] as $endpoint ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) ( $endpoint['methods'] ?? array() ) ) ) );
			}
			$this->assertContains( 'POST', $methods, $route );
		}
	}

	/**
	 * What it checks: A call without OmnaBase's valid signature is turned away before any of the plugin's handling runs.
	 * Passes when: unsigned, wrongly signed, 301-second-old and `v0=`-signed calls to /events each answer 401, nothing hears them, and the
	 *   deliveries log stays empty.
	 * Fails when: anyone on the internet can post a fake "reply from the team" into the site's forum, or fill the log.
	 */
	public function test_unsigned_or_badly_signed_calls_reach_no_handler(): void {
		$body = self::event( 'ping', 'evt_bad' );
		$h    = array( 'x-omnabase-event' => 'ping', 'x-omnabase-delivery' => 'del-bad' );
		$tries = array(
			'unsigned'       => $this->deliver( '/omnabase/v1/events', $body, null, $h ),
			'another secret' => $this->deliver( '/omnabase/v1/events', $body, 'whsec_notTheSitesSecret00000001', $h ),
			'301 seconds old' => $this->deliver( '/omnabase/v1/events', $body, self::WEBHOOKS, $h, time() - 301 ),
			'the v0 scheme'  => $this->deliver( '/omnabase/v1/events', $body, null, $h + array( 'x-omnabase-timestamp' => (string) time(), 'x-omnabase-signature' => 'v0=' . hash_hmac( 'sha256', time() . '.' . $body, self::WEBHOOKS ) ) ),
			'the facts secret on /events' => $this->deliver( '/omnabase/v1/events', $body, self::FACTS, $h ),
		);
		foreach ( $tries as $name => $response ) {
			$this->assertSame( 401, $response->get_status(), $name );
		}
		$this->assertSame( array(), $this->heard, 'no handler ran' );
		$this->assertSame( array(), ( new DeliveryLog() )->recent() );
	}

	/**
	 * What it checks: A genuine delivery is answered 200, handled once and logged.
	 * Passes when: a signed ping answers 200, the handling hears one "ping" with its delivery id, and the log's newest row names "ping"
	 *   and the delivery id.
	 * Fails when: OmnaBase's genuine calls are refused (the address is retried for two days, then switched off), or the screen's log
	 *   stays blank.
	 */
	public function test_a_signed_delivery_is_answered_handled_once_and_logged(): void {
		$response = $this->deliverEvent( 'ping', 'del-ping-1' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( array( 'ping', 'del-ping-1' ) ), array_map( static fn ( $h ) => array( $h[0], $h[2] ), $this->heard ) );
		$log = ( new DeliveryLog() )->recent();
		$this->assertNotEmpty( $log );
		$row = (string) wp_json_encode( $log[0] );
		$this->assertStringContainsString( 'ping', $row );
		$this->assertStringContainsString( 'del-ping-1', $row );
	}

	/**
	 * What it checks: A delivery OmnaBase sends again (a retry after a timeout, or "Redeliver" from its log) is answered 200 but not acted
	 *   on twice.
	 * Passes when: the same delivery id and event id sent three times answer 200 each time and are handled exactly once.
	 * Fails when: a retried reply is posted twice in the forum, or a replay answers an error and OmnaBase keeps retrying it for two days.
	 */
	public function test_a_replayed_delivery_is_answered_200_without_acting(): void {
		foreach ( range( 1, 3 ) as $try ) {
			$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'del-replay', self::sentData() )->get_status(), "try {$try}" );
		}
		$this->assertCount( 1, $this->heard, 'handled once' );
	}

	/**
	 * What it checks: A delivery whose handling failed is not marked as handled, so OmnaBase's retry is handled.
	 * Passes when: the first delivery's handling throws (the answer is not a 2xx, or the request fails), the same delivery sent again is
	 *   handled (heard a second time) and answered 200, and a third time is not handled again.
	 * Fails when: the delivery id is recorded before handling, so a reply that crashed half-way is never posted and OmnaBase believes it was.
	 */
	public function test_a_delivery_is_recorded_only_after_it_was_handled(): void {
		$fail = true;
		add_action(
			'omnabase_webhook',
			static function () use ( &$fail ) {
				if ( $fail ) {
					$fail = false;
					throw new \RuntimeException( 'the handling broke half-way' );
				}
			},
			20
		);

		try {
			$first = $this->deliverEvent( 'message.sent', 'del-crash', self::sentData() )->get_status();
		} catch ( \RuntimeException $e ) {
			$first = 500;
		}
		$this->assertGreaterThanOrEqual( 300, $first, 'a failed handling is not answered as success' );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'del-crash', self::sentData() )->get_status() );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'del-crash', self::sentData() )->get_status() );
		$this->assertCount( 2, $this->heard, 'the retry was handled, the replay after it was not' );
	}

	/**
	 * What it checks: The deliveries log keeps the last 20 and nothing personal.
	 * Passes when: after 25 deliveries the log holds 20, the newest first; a reply's row names the event, the delivery and the ticket number
	 *   but not the customer's address, name or the reply's text.
	 * Fails when: the log grows without end in the options table, or customer data and message text are copied into the site's database.
	 */
	public function test_the_log_keeps_the_last_20_and_no_personal_data(): void {
		$this->deliverEvent( 'message.sent', 'del-0', self::sentData() );
		$row = (string) wp_json_encode( ( new DeliveryLog() )->recent()[0] ?? array() );
		$this->assertStringContainsString( 'message.sent', $row );
		$this->assertStringContainsString( 'del-0', $row );
		$this->assertStringContainsString( '4711', $row, 'the ticket number' );
		foreach ( array( 'ada@example.com', 'Ada Lovelace', 'The switcher is back after the update' ) as $personal ) {
			$this->assertStringNotContainsString( $personal, $row );
		}

		foreach ( range( 1, 24 ) as $i ) {
			$this->deliverEvent( 'ping', "del-{$i}" );
		}
		$log = ( new DeliveryLog() )->recent();
		$this->assertCount( 20, $log );
		$this->assertStringContainsString( 'del-24', (string) wp_json_encode( $log[0] ), 'the newest first' );
	}

	/**
	 * What it checks: The customer receiver checks its own secret and, in round one, answers an empty facts object.
	 * Passes when: a call signed with the facts secret answers 200 with exactly `{}`; signed with the webhook secret, or unsigned, 401.
	 * Fails when: OmnaBase's card shows an error for every customer ("the answer does not fit"), or the customer address answers anyone.
	 */
	public function test_the_customer_receiver_answers_an_empty_object_to_its_own_secret_only(): void {
		$body = (string) wp_json_encode( array( 'v' => 2, 'project' => 'project-enfold', 'customer' => array( 'email' => 'ada@example.com', 'external' => null ), 'ticket' => null, 'sentAt' => gmdate( 'c' ) ) );

		$ok = $this->deliver( '/omnabase/v1/customer', $body, self::FACTS );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( '{}', wp_json_encode( $ok->get_data() ), 'an empty object, not an empty list' );
		$this->assertSame( 401, $this->deliver( '/omnabase/v1/customer', $body, self::WEBHOOKS )->get_status() );
		$this->assertSame( 401, $this->deliver( '/omnabase/v1/customer', $body, null )->get_status() );
	}

	/**
	 * What it checks: When the vault cannot be read (the salts changed), both receivers answer 503 so OmnaBase keeps retrying, never 410.
	 * Passes when: after a salt change, a call signed as before answers 503 at /events and /customer, and nothing hears it.
	 * Fails when: the site answers 410 and OmnaBase switches its address off for good, or 401 and the outage looks like an attack.
	 */
	public function test_unreadable_credentials_answer_503_never_410(): void {
		$this->rotateSalts();
		$this->assertSame( 'unreadable', ( new Vault() )->state() );

		$this->assertSame( 503, $this->deliverEvent( 'ping', 'del-locked' )->get_status() );
		$this->assertSame( 503, $this->deliver( '/omnabase/v1/customer', '{"v":2}', self::FACTS )->get_status() );
		$this->assertSame( array(), $this->heard );
	}

	/**
	 * What it checks: A site that is not connected never tells OmnaBase to switch its address off.
	 * Passes when: with the vault empty, a call to either receiver answers neither 2xx nor 410.
	 * Fails when: a disconnected site accepts calls, or a brief gap (between Disconnect and a reconnect) makes OmnaBase drop the address.
	 */
	public function test_a_site_that_is_not_connected_never_answers_410(): void {
		( new Vault() )->clear();
		foreach ( array( $this->deliverEvent( 'ping', 'del-empty' ), $this->deliver( '/omnabase/v1/customer', '{"v":2}', self::FACTS ) ) as $response ) {
			$this->assertNotSame( 410, $response->get_status() );
			$this->assertGreaterThanOrEqual( 300, $response->get_status() );
		}
	}

	/** A `message.sent` event's data with a customer and a reply in it. */
	private static function sentData(): array {
		return array(
			'ticket'  => array( 'number' => 4711, 'subject' => 'Switcher gone', 'status' => 'pending', 'customer' => array( 'id' => 'c-1', 'name' => 'Ada Lovelace', 'email' => 'ada@example.com' ), 'external' => array( 'id' => 'topic-7', 'url' => self::SITE . '/forums/topic/7' ) ),
			'message' => array( 'id' => 'm-1', 'direction' => 'out', 'kind' => 'team', 'author' => array( 'name' => 'Ada' ), 'bodyText' => 'The switcher is back after the update', 'bodyHtml' => '<p>The switcher is back after the update</p>', 'external' => null ),
		);
	}
}
