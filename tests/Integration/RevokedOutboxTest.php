<?php
/**
 * M14 round one, the builder's test for a ruling the red team left open (report, point 5; DECISIONS "2026-10-10 — M14 round one"): while
 * OmnaBase refuses the site's key (it answered 401: someone disconnected the site there), the outbox pauses too. A row that meets the 401
 * keeps waiting with its tries untouched, no further row is tried, and everything goes after the administrator clicks Connect (the plain run).
 *
 * The words: *outbox*, the plugin's lasting list of calls to OmnaBase still to make, worked by a timer every minute; *401*, OmnaBase's answer
 * "this key is not (or no longer) valid"; *needs attention*, a row the outbox gave up on until the administrator retries or discards it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Outbox\Table;
use OmnaBase\WordPress\Security\Vault;

final class RevokedOutboxTest extends TabsTestCase {

	/** The outbox's timer hook (one run of the worker). */
	private const OUTBOX_HOOK = 'omnabase_outbox';

	/** A row as stored. */
	private static function row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Table::name(), $id ), ARRAY_A );
	}

	/** Makes a row due now. */
	private static function dueNow( int $id ): void {
		global $wpdb;
		$wpdb->update( Table::name(), array( 'next_at' => time() - 1, 'claimed_until' => 0 ), array( 'id' => $id ), array( '%d', '%d' ), array( '%d' ) );
	}

	/** The calls the plugin made to OmnaBase's API since the list was emptied. */
	private function omnabaseCalls(): array {
		return array_values( array_filter( $this->calls, static fn ( $c ) => str_contains( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), '/api/public/v1/' ) ) );
	}

	/**
	 * What it checks: When someone disconnects the site in OmnaBase, what the site still has to send waits instead of failing, and goes once
	 *   the administrator connects again.
	 * Passes when: a waiting call that meets OmnaBase's 401 stays waiting (not "needs attention", its tries unchanged) and the site counts as
	 *   disconnected in OmnaBase; the next timer runs call OmnaBase not at all; after Connect the same call is sent.
	 * Fails when: every queued call ends as "needs attention" after a disconnect (the owner must retry each by hand), the site keeps knocking
	 *   at OmnaBase with a dead key every minute, or the calls never go after reconnecting.
	 */
	public function test_a_refused_key_pauses_the_outbox_and_connect_resumes_it(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$first  = $this->plantOutboxRow( false );
		$second = $this->plantOutboxRow( false );
		self::dueNow( $first );
		self::dueNow( $second );
		$attempts                              = (int) self::row( $first )['attempts'];
		$this->answers['POST /refresh']        = self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) );
		$this->calls                           = array();

		do_action( self::OUTBOX_HOOK );

		$this->assertCount( 1, $this->omnabaseCalls(), 'one call met the 401; the second row was not tried with the dead key' );
		$this->assertTrue( ( new Vault() )->isRevoked(), 'the site counts as disconnected in OmnaBase' );
		foreach ( array( $first, $second ) as $id ) {
			$row = self::row( $id );
			$this->assertSame( 'waiting', $row['state'], 'the row waits' );
			$this->assertLessThanOrEqual( time(), (int) $row['next_at'], 'due as soon as the outbox may call again' );
		}
		$this->assertSame( $attempts, (int) self::row( $first )['attempts'], 'the refused try does not count' );

		$this->calls = array();
		do_action( self::OUTBOX_HOOK );
		$this->assertSame( array(), $this->omnabaseCalls(), 'no call while the key is refused' );

		$this->connectFully( self::exchangeAnswer( 'obw_n3wk3yn3wk3yn3wk3yn3wk3yn3wk3yn3wk3yn3wk3y_cdZ9Y8' ) );
		$this->answers['POST /refresh'] = self::answer( 200, array( 'ok' => true ) );
		do_action( self::OUTBOX_HOOK );
		$this->assertSame( 'sent', self::row( $first )['state'], 'sent after Connect' );
		$this->assertSame( 'sent', self::row( $second )['state'], 'sent after Connect' );
	}
}
