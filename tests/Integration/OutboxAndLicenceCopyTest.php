<?php
/**
 * M13 round two, the red team's failing tests: the outbox (the plugin's lasting list of calls to OmnaBase still to make) and the licence
 * copy (OmnaBase keeps a copy of a customer's licences, refreshed when the site says they changed) in a real WordPress with the shops.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 4: "A custom table `{prefix}omnabase_outbox` (kind, the post or user it
 * is about, a unique dedupe key …, attempts, next try, state, the last answer in words, created and sent times), made on activation and
 * dropped on uninstall. … A WP-Cron job every minute (and right after a new row) works up to 20 rows under a lock. **It never drops a row**:
 * a `429` waits exactly the `Retry-After` …; network errors and 5xx back off …; an answer that will never succeed … moves the row to 'needs
 * attention'"; choice 5: "**Licence copy**: `woocommerce_order_status_changed` (both storages), `woocommerce_subscription_status_updated`,
 * `edd_transition_order_status`, `edd_sl_post_set_status`, `edd_sl_activate_license` and License Manager's licence events (names verified in
 * round two) queue one `POST /customers/by-external/{id}/refresh` per user known to OmnaBase (deduped per user; 15-second timeout …)"; round
 * two's *Red team*: "each named hook queues one refresh, two within a minute one; with OmnaBase down, the refresh waits and goes later". The
 * coordinator: "a custom table … made on activation/upgrade, migrated by a version option; rows: kind, payload, attempts, next_at, last_error;
 * a WP-Cron event every minute plus an immediate try; never drops a row". OmnaBase `apps/api/src/public/refresh.ts`: the route is
 * `POST /api/public/v1/customers/by-external/{id}/refresh` and answers 404 for a user OmnaBase does not know by that id.
 *
 * License Manager's events, verified on its version 3.0.20 (the one wp-env installs): the free plugin fires no action when a licence is
 * edited or activated; the one licence event it has is `lmfwc_event_post_order_license_keys( [ 'orderId', 'licenses' ] )` when an order's
 * keys are delivered. So License Manager's copy refreshes on that event and on the order's status change (see the report).
 *
 * Shapes the red team pins: those of `RoundTwoTestCase`. (chosen) A user counts as known to OmnaBase once the plugin's link was accepted,
 * or once OmnaBase asked about them by their site id; a licence change for anyone else queues nothing (OmnaBase would answer 404).
 *
 * Why these fail today: there is no outbox table, no worker, no licence hooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * The customer-data tests need the shops: bin/test-integration.sh runs them in its second run.
 *
 * @group shops
 */
final class OutboxAndLicenceCopyTest extends RoundTwoTestCase {

	/**
	 * What it checks: The outbox's table exists with the columns the worker needs, and its schema version is recorded so an update can
	 *   change it later.
	 * Passes when: the table `wp_omnabase_outbox` has id, kind, attempts, next_at, state and last_error, and the option omnabase_db_version holds
	 *   a version.
	 * Fails when: the table is missing on a site that updated the plugin without re-activating it, and every licence change is lost.
	 */
	public function test_the_outbox_table_and_its_schema_version_exist(): void {
		global $wpdb;
		$columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$this->outboxTable()}" ); // phpcs:ignore WordPress.DB

		foreach ( array( 'id', 'kind', 'attempts', 'next_at', 'state', 'last_error' ) as $column ) {
			$this->assertContains( $column, $columns, $column );
		}
		$this->assertNotSame( '', (string) get_option( 'omnabase_db_version', '' ) );
	}

	/**
	 * What it checks: The outbox is worked every minute by WordPress's timer (WP-Cron).
	 * Passes when: the job omnabase_outbox is scheduled, on a schedule of 60 seconds.
	 * Fails when: queued calls sit until an administrator happens to click something.
	 */
	public function test_the_worker_is_scheduled_every_minute(): void {
		$this->assertNotFalse( wp_next_scheduled( self::OUTBOX_HOOK ), 'scheduled' );
		$schedule = wp_get_schedule( self::OUTBOX_HOOK );
		$this->assertNotFalse( $schedule );
		$this->assertSame( 60, (int) ( wp_get_schedules()[ $schedule ]['interval'] ?? 0 ) );
	}

	/**
	 * What it checks: Each shop event that can change a customer's licences queues exactly one refresh for that customer.
	 * Passes when: for each event below, fired for Ada (known to OmnaBase), exactly one waiting "licence_refresh" row exists afterwards.
	 * Fails when: OmnaBase's licence copy goes stale after a renewal, refund, expiry or activation, and the team answers with old facts.
	 *
	 * @dataProvider licenceEvents
	 *
	 * @param callable $fire Fires the event for the user.
	 */
	public function test_each_licence_event_queues_one_refresh( callable $fire ): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );

		$fire( $ada, $this );

		$rows = $this->outboxRows( 'licence_refresh' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'waiting', $rows[0]['state'] );
	}

	/** The events, each fired as the shop fires it. */
	public static function licenceEvents(): array {
		return array(
			'a WooCommerce order changes status'          => array(
				static function ( int $user, self $t ) {
					$order = $t->wooOrder( $user, 'ada@example.org', '2026-09-30 10:00:00', 'processing' );
					$t->clearOutbox();
					$order->update_status( 'completed' );
				},
			),
			'a WooCommerce subscription changes status'   => array(
				static function ( int $user ) {
					update_option( 'omnabase_fake_wcs', array( array( 'id' => 901, 'user_id' => $user, 'status' => 'on-hold', 'items' => array( 'Enfold updates' ) ) ) );
					do_action( 'woocommerce_subscription_status_updated', wcs_get_subscription( 901 ), 'on-hold', 'active' );
				},
			),
			'an EDD order changes status'                 => array(
				static function ( int $user, self $t ) {
					$customer = (int) edd_add_customer( array( 'email' => 'ada@example.org', 'name' => 'Ada Lovelace', 'user_id' => $user ) );
					$order    = (int) edd_add_order( array( 'user_id' => $user, 'customer_id' => $customer, 'email' => 'ada@example.org', 'status' => 'pending', 'type' => 'sale', 'currency' => 'EUR', 'total' => 39 ) );
					$t->clearOutbox();
					edd_update_order( $order, array( 'status' => 'complete' ) );
				},
			),
			'an EDD licence changes status'               => array(
				static function ( int $user, self $t ) {
					update_option( 'omnabase_fake_edd_sl', array( array( 'id' => 71, 'user_id' => $user, 'download_id' => $t->download( 'Omnalingo Pro' ), 'key' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b49K4M', 'status' => 'expired', 'expiration' => 0 ) ) );
					do_action( 'edd_sl_post_set_status', 71, 'expired' );
				},
			),
			'an EDD licence is activated on a site'       => array(
				static function ( int $user, self $t ) {
					$download = $t->download( 'Omnalingo Pro' );
					update_option( 'omnabase_fake_edd_sl', array( array( 'id' => 71, 'user_id' => $user, 'download_id' => $download, 'key' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b49K4M', 'status' => 'active', 'expiration' => 0, 'sites' => array( 'https://kunde.example' ) ) ) );
					do_action( 'edd_sl_activate_license', 71, $download );
				},
			),
			'License Manager delivers an order\'s keys'   => array(
				static function ( int $user, self $t ) {
					$product = $t->product( 'Enfold Theme' );
					$order   = $t->wooOrder( $user, 'ada@example.org', '2026-09-30 10:00:00', 'completed', $product );
					$license = lmfwc_add_license( 'OMNA-TEST-0000-7Q2X', array( 'order_id' => $order->get_id(), 'product_id' => $product->get_id(), 'user_id' => $user, 'status' => 2 ) );
					$t->clearOutbox();
					do_action( 'lmfwc_event_post_order_license_keys', array( 'orderId' => $order->get_id(), 'licenses' => array( $license ) ) );
				},
			),
		);
	}

	/**
	 * What it checks: Several changes for one customer in quick succession make one refresh, while a change after it was sent makes another.
	 * Passes when: an EDD licence's status change and its activation, fired one after the other, leave one waiting refresh; once the worker
	 *   sent it, the next status change queues a new one.
	 * Fails when: a checkout's burst of events sends OmnaBase five refreshes (each makes OmnaBase call the site back), or a later change is
	 *   swallowed because an old row was sent already.
	 */
	public function test_changes_in_quick_succession_make_one_refresh(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		update_option( 'omnabase_fake_edd_sl', array( array( 'id' => 71, 'user_id' => $ada, 'download_id' => $this->download( 'Omnalingo Pro' ), 'key' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b49K4M', 'status' => 'active', 'expiration' => 0 ) ) );

		do_action( 'edd_sl_post_set_status', 71, 'active' );
		do_action( 'edd_sl_activate_license', 71, 0 );
		$this->assertCount( 1, $this->outboxRows( 'licence_refresh' ), 'two changes, one refresh' );

		$this->omnabaseTakes( $ada );
		$this->makeDue();
		$this->runOutbox();
		do_action( 'edd_sl_post_set_status', 71, 'expired' );

		$states = array_column( $this->outboxRows( 'licence_refresh' ), 'state' );
		$this->assertEqualsCanonicalizing( array( 'sent', 'waiting' ), $states, 'the sent one stays (kept seven days) and a new one waits' );
	}

	/**
	 * What it checks: A licence change for someone OmnaBase does not know queues nothing, and someone OmnaBase asked about by site id counts
	 *   as known.
	 * Passes when: an EDD licence change for Ben (never linked, never asked about) queues no row; after OmnaBase asked about Carla by her site
	 *   id, her licence change queues one refresh and no link.
	 * Fails when: the outbox fills with refreshes OmnaBase answers 404 for, or a known customer's change waits for a link that is not needed.
	 */
	public function test_only_users_known_to_omnabase_get_refreshes(): void {
		$ben      = $this->customer( 'ben@example.org', 'Ben Weber' );
		$carla    = $this->customer( 'carla@example.org', 'Carla Kunde' );
		$download = $this->download( 'Omnalingo Pro' );
		$this->switchOn( array( 'profile', 'edd_software_licensing' ) );
		update_option(
			'omnabase_fake_edd_sl',
			array(
				array( 'id' => 72, 'user_id' => $ben, 'download_id' => $download, 'key' => 'ffffffffffffffffffffffffffffBEN1', 'status' => 'active', 'expiration' => 0 ),
				array( 'id' => 73, 'user_id' => $carla, 'download_id' => $download, 'key' => 'ccccccccccccccccccccccccccccCAR1', 'status' => 'active', 'expiration' => 0 ),
			)
		);

		do_action( 'edd_sl_post_set_status', 72, 'expired' );
		$this->assertSame( array(), $this->outboxRows(), 'nothing for a user OmnaBase does not know' );

		$this->askFacts( 'carla@example.org', self::siteIdOf( $carla ) );
		do_action( 'edd_sl_post_set_status', 73, 'expired' );
		$this->assertCount( 1, $this->outboxRows( 'licence_refresh' ) );
		$this->assertCount( 0, $this->outboxRows( 'user_link' ) );
	}

	/**
	 * What it checks: The worker sends a refresh to OmnaBase's refresh address for the customer, with the key and a timeout long enough for
	 *   OmnaBase to call the site back.
	 * Passes when: one `POST /api/public/v1/customers/by-external/<Ada's site id>/refresh` goes out with the site's key and a timeout of at least
	 *   15 seconds, and OmnaBase's 200 marks the row sent.
	 * Fails when: the refresh times out every time (OmnaBase asks the site's customer endpoint during the call, which may take 5 seconds), or
	 *   goes to the wrong customer.
	 */
	public function test_the_worker_sends_the_refresh_with_a_15_second_timeout(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$this->omnabaseTakes( $ada );
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		$this->makeDue();
		$this->calls = array();

		$this->runOutbox();

		$posts = $this->callsFor( $ada, 'POST', true );
		$this->assertCount( 1, $posts );
		$this->assertSame( 'Bearer ' . self::KEY, $posts[0]['args']['headers']['Authorization'] ?? null );
		$this->assertGreaterThanOrEqual( 15, (int) ( $posts[0]['args']['timeout'] ?? 0 ) );
		$this->assertSame( 'sent', $this->outboxRows( 'licence_refresh' )[0]['state'] );
	}

	/**
	 * What it checks: With OmnaBase down, the refresh waits and goes once OmnaBase is back; the row is never lost.
	 * Passes when: the first try meets a network error: the row keeps waiting with one attempt, a reason in words and a next try about a minute
	 *   later; once that time has come and OmnaBase answers, the next run sends it and the row is marked sent.
	 * Fails when: an outage at OmnaBase silently loses the licence change, or the worker gives up after one try.
	 */
	public function test_with_omnabase_down_the_refresh_waits_and_goes_later(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$this->answers = array(); // OmnaBase answers nothing: every call fails as a network error would
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		$this->makeDue();

		$this->runOutbox();

		$row = $this->outboxRows( 'licence_refresh' )[0] ?? null;
		$this->assertNotNull( $row, 'the row is still there' );
		$this->assertSame( 'waiting', $row['state'] );
		$this->assertSame( 1, (int) $row['attempts'] );
		$this->assertNotSame( '', trim( (string) $row['last_error'] ) );
		$this->assertEqualsWithDelta( time() + 60, self::nextAt( $row ), 15, 'the next try about a minute later' );

		$this->omnabaseTakes( $ada );
		$this->makeDue();
		$this->runOutbox();

		$this->assertSame( 'sent', $this->outboxRows( 'licence_refresh' )[0]['state'] );
		$this->assertCount( 1, $this->outboxRows( 'licence_refresh' ), 'the same row, sent' );
	}

	/**
	 * What it checks: When OmnaBase answers "too many requests", the row waits exactly as long as OmnaBase said, and is not tried before then.
	 * Passes when: a 429 with Retry-After 3600 sets the next try an hour later; another run straight away makes no call; the row keeps waiting.
	 * Fails when: the plugin retries every minute through OmnaBase's limit, or drops the row.
	 */
	public function test_a_429_waits_its_retry_after_and_is_not_tried_before(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$id = rawurlencode( self::siteIdOf( $ada ) );
		$this->answers[ "POST /customers/by-external/{$id}/refresh" ] = array( 'headers' => array( 'retry-after' => '3600', 'content-type' => 'application/json' ), 'body' => '{"error":{"code":"rate_limited","message":"Too many requests"}}', 'response' => array( 'code' => 429, 'message' => 'Too Many Requests' ), 'cookies' => array(), 'filename' => null );
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		$this->makeDue();

		$this->runOutbox();
		$row = $this->outboxRows( 'licence_refresh' )[0];
		$this->calls = array();
		$this->runOutbox();

		$this->assertSame( 'waiting', $row['state'] );
		$this->assertEqualsWithDelta( time() + 3600, self::nextAt( $row ), 10 );
		$this->assertSame( array(), $this->callsFor( $ada, 'POST', true ), 'not tried again before the hour is up' );
	}

	/**
	 * What it checks: A hopeless answer moves the row to "needs attention", and it stays in the table.
	 * Passes when: OmnaBase answers 400 to the refresh: the row's state is "attention" with the reason kept, and the row still exists.
	 * Fails when: a refused call vanishes without a trace, or is retried hourly for ever.
	 */
	public function test_a_hopeless_answer_needs_attention_and_the_row_stays(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$id = rawurlencode( self::siteIdOf( $ada ) );
		$this->answers[ "POST /customers/by-external/{$id}/refresh" ] = self::answer( 400, array( 'error' => array( 'code' => 'bad_request', 'message' => 'The request did not match its schema' ) ) );
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		$this->makeDue();

		$this->runOutbox();

		$rows = $this->outboxRows( 'licence_refresh' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'attention', $rows[0]['state'] );
		$this->assertNotSame( '', trim( (string) $rows[0]['last_error'] ) );
	}

	/**
	 * What it checks: A new row is tried right away, not only at the next minute's run, yet never inside the shop's own request step.
	 * Passes when: right after a licence change no call has gone out yet (the checkout does not wait for OmnaBase); by the end of the request
	 *   (WordPress's shutdown) either the refresh was sent, or a run of the worker is scheduled within five seconds.
	 * Fails when: on a quiet site (WP-Cron runs only when someone visits) a licence change waits many minutes for no reason, or a customer's
	 *   checkout hangs up to 15 seconds while the plugin talks to OmnaBase.
	 */
	public function test_a_new_row_is_tried_at_once(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$this->omnabaseTakes( $ada );
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		$this->assertSame( array(), $this->callsFor( $ada, 'POST', true ), 'nothing sent inside the shop\'s own step' );

		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 ); // core's output flush would close PHPUnit's own buffer
		do_action( 'shutdown' );

		$sent_at_once = array() !== $this->callsFor( $ada, 'POST', true );
		$soon         = false;
		foreach ( (array) _get_cron_array() as $time => $hooks ) {
			foreach ( array_keys( (array) $hooks ) as $hook ) {
				if ( str_starts_with( (string) $hook, 'omnabase_outbox' ) && (int) $time <= time() + 5 ) {
					$soon = true;
				}
			}
		}
		$this->assertTrue( $sent_at_once || $soon, 'sent at shutdown, or a worker run within 5 seconds' );
	}

	/**
	 * What it checks: Two workers running at once (two visits starting WP-Cron together) never send the same row twice.
	 * Passes when: a second run started while the first is waiting for OmnaBase's answer sends nothing, and the refresh goes out once.
	 * Fails when: two overlapping runs send a forum topic twice (two tickets) or a refresh twice.
	 */
	public function test_overlapping_runs_send_a_row_once(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$id     = rawurlencode( self::siteIdOf( $ada ) );
		$nested = false;
		$this->answers[ "POST /customers/by-external/{$id}/refresh" ] = function () use ( &$nested ) {
			if ( ! $nested ) {
				$nested = true;
				$this->runOutbox(); // another run starts while this call is in flight
			}
			return self::answer( 200, array( 'ok' => true, 'licences' => 1 ) );
		};
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		$this->makeDue();
		$this->calls = array();

		$this->runOutbox();

		$this->assertTrue( $nested, 'the overlapping run happened' );
		$this->assertCount( 1, $this->callsFor( $ada, 'POST', true ) );
	}

	/**
	 * What it checks: One run works at most 20 rows, so a backlog never makes a single page visit slow.
	 * Passes when: with 25 known customers each holding a due refresh, one run sends 20 refreshes and leaves 5 waiting.
	 * Fails when: after an outage the first visitor's page load waits for dozens of calls to OmnaBase.
	 */
	public function test_one_run_works_at_most_twenty_rows(): void {
		$this->switchOn( array( 'profile', 'edd_software_licensing' ) );
		$download = $this->download( 'Omnalingo Pro' );
		$licences = array();
		$users    = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$user       = $this->customer( "buyer{$i}@example.org", "Buyer {$i}" );
			$users[]    = $user;
			$licences[] = array( 'id' => 100 + $i, 'user_id' => $user, 'download_id' => $download, 'key' => str_repeat( 'a', 28 ) . sprintf( '%04d', $i ), 'status' => 'active', 'expiration' => 0 );
			$this->askFacts( "buyer{$i}@example.org", self::siteIdOf( $user ) ); // OmnaBase knows them by site id
		}
		update_option( 'omnabase_fake_edd_sl', $licences );
		$this->omnabaseTakes( ...$users );
		for ( $i = 1; $i <= 25; $i++ ) {
			do_action( 'edd_sl_post_set_status', 100 + $i, 'expired' );
		}
		$this->makeDue();
		$this->calls = array();

		$this->runOutbox();

		$states = array_count_values( array_column( $this->outboxRows( 'licence_refresh' ), 'state' ) );
		$this->assertSame( 20, $states['sent'] ?? 0 );
		$this->assertSame( 5, $states['waiting'] ?? 0 );
	}

	/**
	 * What it checks: Without a readable connection, rows wait; they are neither sent nor given up.
	 * Passes when: with the vault emptied after the row was queued, a run makes no call and the row is still waiting.
	 * Fails when: a disconnected or salt-rotated site turns its queued licence changes into errors or deletes them before the admin reconnects.
	 */
	public function test_without_a_connection_rows_wait(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		( new \OmnaBase\WordPress\Security\Vault() )->clear();
		$this->makeDue();
		$this->calls = array();

		$this->runOutbox();

		$this->assertSame( array(), $this->calls );
		$this->assertSame( 'waiting', $this->outboxRows( 'licence_refresh' )[0]['state'] ?? null );
	}
}
