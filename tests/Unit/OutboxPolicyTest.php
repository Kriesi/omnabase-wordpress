<?php
/**
 * M13 round two, the red team's failing tests: the outbox's rule for what happens to a row after each try (the outbox: the plugin's lasting
 * list of calls to OmnaBase still to make, worked through until each has gone).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 4: "**It never drops a row**: a `429` waits exactly the `Retry-After`
 * it names (the daily limit: until tomorrow); network errors and 5xx back off 1, 5, 15, 60 minutes, then hourly; an answer that will never
 * succeed (400, a 404 ticket, `409 forgotten`) moves the row to 'needs attention' with Retry and Discard for the admin, never silently away";
 * choice 5: the licence refresh "15-second timeout, since OmnaBase asks the site's facts endpoint during the call". OmnaBase
 * `docs/public-api.md`: the refresh's "facts endpoint that does not answer is `409` (`"facts_failed"`)", "a site without a facts endpoint …
 * `409 conflict` (`details.reason: "no_endpoint"`)", `PUT /customers/by-external/{id}` "`409 conflict`, `details.reason: "address_taken"`" and
 * "`forgotten`"; errors are `{ "error": { "code", "message", "details" } }`. The coordinator: "waits for `Retry-After` on 429; backoff on 5xx;
 * a dead-letter state after N days shown on the screen with 'Retry'".
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - `OmnaBase\WordPress\Outbox\Policy`, built with no arguments; `decide( array $outcome, array $row, int $now ): array` with
 *   `$outcome = [ 'status' => int|null (null: no answer, a network error), 'headers' => array (lower-case names), 'body' => array|null,
 *   'error' => string|null ]` and `$row = [ 'attempts' => <failed tries before this one>, 'created_at' => <unix time> ]`; it answers
 *   `[ 'state' => 'sent'|'waiting'|'attention', 'next_at' => int|null, 'reason' => string ]`.
 * - (chosen) A row that keeps failing with errors that might pass (network, 5xx, `facts_failed`) moves to "needs attention" once it has
 *   been trying for more than 3 days (the coordinator's dead-letter; OmnaBase itself retries its webhooks about 45 hours); a 429 never does,
 *   because OmnaBase's daily limit may hold a busy forum's rows for days and they must still go.
 * - (chosen) `409 facts_failed` is a passing failure (the site's own endpoint did not answer OmnaBase in time), `409 no_endpoint` a lasting one.
 *
 * Why these fail today: there is no outbox (round two builds it).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Outbox\Policy;

final class OutboxPolicyTest extends TestCase {

	private const NOW = 1791540000; // 2026-10-09 10:00:00 UTC
	private const DAY = 86400;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	/**
	 * What it checks: A call OmnaBase accepted is done.
	 * Passes when: 200, 201 and 204 each mark the row sent, with no next try.
	 * Fails when: a delivered licence refresh is sent again and again.
	 */
	public function test_a_2xx_answer_marks_the_row_sent(): void {
		foreach ( array( 200, 201, 204 ) as $status ) {
			$d = $this->decide( $status );
			$this->assertSame( 'sent', $d['state'], (string) $status );
			$this->assertNull( $d['next_at'] );
		}
	}

	/**
	 * What it checks: When OmnaBase says "too many, come back later", the row waits exactly as long as OmnaBase asked.
	 * Passes when: `Retry-After: 120` sets the next try to now + 120 seconds; a Retry-After of 13 hours (the daily limit, until tomorrow)
	 *   to now + 13 hours; an HTTP date to that moment; each time the row keeps waiting (never "needs attention").
	 * Fails when: the plugin hammers OmnaBase every minute through the daily limit, or gives up on a busy day's forum topics.
	 */
	public function test_a_429_waits_exactly_the_retry_after(): void {
		$this->assertSame( array( 'waiting', self::NOW + 120 ), $this->stateAndNext( $this->decide( 429, array( 'retry-after' => '120' ) ) ) );
		$this->assertSame( array( 'waiting', self::NOW + 46800 ), $this->stateAndNext( $this->decide( 429, array( 'retry-after' => '46800' ), null, 7 ) ) );
		$date = gmdate( 'D, d M Y H:i:s', self::NOW + 900 ) . ' GMT';
		$this->assertSame( array( 'waiting', self::NOW + 900 ), $this->stateAndNext( $this->decide( 429, array( 'retry-after' => $date ) ) ) );
	}

	/**
	 * What it checks: A 429 never moves a row to "needs attention", however long it has waited.
	 * Passes when: a row created five days ago that meets another 429 is still waiting, its next try at the Retry-After.
	 * Fails when: a week of a full daily limit turns every waiting forum topic into an admin chore, though each would have gone by itself.
	 */
	public function test_a_429_never_dead_letters(): void {
		$d = $this->decide( 429, array( 'retry-after' => '3600' ), null, 40, self::NOW - 5 * self::DAY );
		$this->assertSame( array( 'waiting', self::NOW + 3600 ), $this->stateAndNext( $d ) );
	}

	/**
	 * What it checks: When OmnaBase cannot be reached or fails on its side, the row tries again later, a little later each time.
	 * Passes when: a network error or a 5xx on the first, second, third and fourth failed try waits 1, 5, 15 and 60 minutes, and every try
	 *   after that waits an hour; the row keeps waiting and its reason is words, never empty.
	 * Fails when: an outage loses the call, or the plugin retries every minute for hours and adds to OmnaBase's trouble.
	 */
	public function test_network_errors_and_5xx_back_off_1_5_15_60_then_hourly(): void {
		$expected = array( 0 => 60, 1 => 300, 2 => 900, 3 => 3600, 4 => 3600, 9 => 3600 );
		foreach ( array( null, 500, 502, 503 ) as $status ) {
			foreach ( $expected as $before => $wait ) {
				$d = $this->decide( $status, array(), null, $before );
				$this->assertSame( array( 'waiting', self::NOW + $wait ), $this->stateAndNext( $d ), ( $status ?? 'network' ) . " after {$before} failed tries" );
				$this->assertNotSame( '', trim( $d['reason'] ) );
			}
		}
	}

	/**
	 * What it checks: An answer that will never succeed asks the administrator instead of retrying for ever, and the row stays.
	 * Passes when: a 400, a 404, a 409 "forgotten", a 409 "address taken" and a 409 "no facts endpoint" each move the row to "needs attention"
	 *   with no next try and a reason in words (not OmnaBase's raw JSON).
	 * Fails when: a hopeless call is retried hourly for ever, or quietly vanishes, so the admin never learns why a customer's data did not go.
	 */
	public function test_hopeless_answers_need_attention(): void {
		$cases = array(
			'400'               => array( 400, self::error( 'bad_request', 'The request did not match its schema' ) ),
			'404'               => array( 404, self::error( 'not_found', 'No such user of this site' ) ),
			'409 forgotten'     => array( 409, self::error( 'conflict', 'This person asked to be forgotten', 'forgotten' ) ),
			'409 address taken' => array( 409, self::error( 'conflict', 'That address belongs to another customer', 'address_taken' ) ),
			'409 no endpoint'   => array( 409, self::error( 'conflict', 'This site has no facts endpoint', 'no_endpoint' ) ),
		);
		foreach ( $cases as $name => [ $status, $body ] ) {
			$d = $this->decide( $status, array(), $body );
			$this->assertSame( 'attention', $d['state'], $name );
			$this->assertNull( $d['next_at'], $name );
			$this->assertNotSame( '', trim( $d['reason'] ), $name );
			$this->assertStringNotContainsString( '{"', $d['reason'], $name );
		}
	}

	/**
	 * What it checks: A licence refresh that failed because OmnaBase could not reach the site's own customer endpoint in time is tried again.
	 * Passes when: a 409 with the reason "facts_failed" keeps the row waiting with the usual back-off (1 minute after the first failure).
	 * Fails when: one slow moment on the shop (OmnaBase allows it 5 seconds) leaves the licence copy stale until an admin clicks Retry.
	 */
	public function test_a_facts_failed_refresh_is_retried(): void {
		$d = $this->decide( 409, array(), self::error( 'conflict', 'The site did not answer: timeout', 'facts_failed' ) );
		$this->assertSame( array( 'waiting', self::NOW + 60 ), $this->stateAndNext( $d ) );
	}

	/**
	 * What it checks: A row that has failed for days with errors that might pass is handed to the administrator, never deleted.
	 * Passes when: a 503 on a row created 3 days and 1 second ago moves it to "needs attention"; the same 503 on a row created 2 days ago keeps
	 *   it waiting.
	 * Fails when: a broken connection is retried silently for weeks, or the row is thrown away and the customer's licence copy never updates.
	 */
	public function test_failing_for_more_than_three_days_needs_attention(): void {
		$old = $this->decide( 503, array(), null, 60, self::NOW - 3 * self::DAY - 1 );
		$this->assertSame( 'attention', $old['state'] );
		$this->assertNotSame( '', trim( $old['reason'] ) );
		$young = $this->decide( 503, array(), null, 40, self::NOW - 2 * self::DAY );
		$this->assertSame( 'waiting', $young['state'] );
	}

	/**
	 * One decision.
	 *
	 * @param int|null   $status  The answer's status, null for no answer.
	 * @param array      $headers Its headers.
	 * @param array|null $body    Its body.
	 * @param int        $before  Failed tries before this one.
	 * @param int|null   $created When the row was made.
	 */
	private function decide( ?int $status, array $headers = array(), ?array $body = null, int $before = 0, ?int $created = null ): array {
		$outcome = array( 'status' => $status, 'headers' => $headers, 'body' => $body, 'error' => null === $status ? 'cURL error 7: Failed to connect to app.omnabase.com' : null );
		$d       = ( new Policy() )->decide( $outcome, array( 'attempts' => $before, 'created_at' => $created ?? self::NOW - 30 ), self::NOW );
		$this->assertIsArray( $d );
		$this->assertContains( $d['state'] ?? null, array( 'sent', 'waiting', 'attention' ) );
		$this->assertArrayHasKey( 'next_at', $d );
		$this->assertIsString( $d['reason'] ?? null );
		return $d;
	}

	/** The state and the next try. */
	private function stateAndNext( array $d ): array {
		return array( $d['state'], $d['next_at'] );
	}

	/** OmnaBase's error body. */
	private static function error( string $code, string $message, ?string $reason = null ): array {
		return array( 'error' => array( 'code' => $code, 'message' => $message ) + ( null === $reason ? array() : array( 'details' => array( 'reason' => $reason ) ) ) );
	}
}
