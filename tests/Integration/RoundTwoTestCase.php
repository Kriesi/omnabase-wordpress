<?php
/**
 * M13 round two, the red team's integration helpers (inside wp-env's tests WordPress, with WooCommerce, EDD and License Manager for
 * WooCommerce loaded by the bootstrap, and the paid add-ons' fakes from `tests/fakes/`): a connected site, customers with orders and
 * licences, OmnaBase's signed customer question (the facts call), the outbox's rows and its worker.
 *
 * Shapes the red team pins here (chosen; the builder may rename and tell the red team; every name sits in this file):
 * - The facts switches and the usermeta allow-list live in one option, `omnabase_facts`: `[ 'sources' => [ <source> => true|false ],
 *   'meta' => [ <usermeta key>, … ] ]`; every source is off when the option is missing. The sources: `profile`, `woocommerce`,
 *   `woocommerce_subscriptions`, `edd`, `edd_recurring`, `edd_software_licensing`, `lmfw` (License Manager for WooCommerce).
 * - The site's id for a user: `OmnaBase\WordPress\Sync\SiteId` with `forUser( int $user_id ): string` (made once, `<user id>-<8 random
 *   lower-case letters or digits>`, kept) and `userFor( string $site_id ): ?int`.
 * - The outbox table is `{prefix}omnabase_outbox` with at least the columns `id`, `kind`, `attempts`, `next_at` (a unix time or a UTC
 *   DATETIME), `state` (`waiting`, `sent`, `attention`), `last_error`; the plugin makes it when its schema version (the option
 *   `omnabase_db_version`) is missing or older, on every load, so it exists before the first test. Kinds: `user_link` (the `PUT
 *   /customers/by-external/{id}`), `licence_refresh` (the `POST …/{id}/refresh`).
 * - The worker runs at the WP-Cron hook `omnabase_outbox`, scheduled every minute.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Security\Vault;
use OmnaBase\WordPress\Sync\SiteId;

abstract class RoundTwoTestCase extends RoundOneTestCase {

	protected const FACTS_OPTION = 'omnabase_facts';
	protected const OUTBOX_HOOK  = 'omnabase_outbox';
	protected const ALL_SOURCES  = array( 'profile', 'woocommerce', 'woocommerce_subscriptions', 'edd', 'edd_recurring', 'edd_software_licensing', 'lmfw' );
	protected const PROJECT      = 'project-enfold';

	public function set_up(): void {
		parent::set_up();
		( new Vault() )->save( self::CREDENTIALS );
		delete_option( self::FACTS_OPTION );
		update_option( 'woocommerce_currency', 'EUR' );
		update_option( 'edd_settings', array_merge( (array) get_option( 'edd_settings', array() ), array( 'currency' => 'EUR' ) ) );
		foreach ( array( 'omnabase_fake_wcs', 'omnabase_fake_edd_recurring', 'omnabase_fake_edd_sl' ) as $fake ) {
			delete_option( $fake );
		}
		$this->clearOutbox();
	}

	/* ---------- the switches ---------- */

	/**
	 * Turns sources on (all others off) and sets the usermeta allow-list.
	 *
	 * @param string[] $sources The sources to turn on.
	 * @param string[] $meta    The usermeta keys the admin ticked.
	 */
	protected function switchOn( array $sources, array $meta = array() ): void {
		update_option( self::FACTS_OPTION, array( 'sources' => array_combine( self::ALL_SOURCES, array_map( static fn ( $s ) => in_array( $s, $sources, true ), self::ALL_SOURCES ) ), 'meta' => $meta ) );
	}

	/* ---------- the facts call ---------- */

	/**
	 * OmnaBase's customer question, signed with the facts secret, as `factsCallBody` builds it.
	 *
	 * Builder's adjustment (DECISIONS "Round two"): the call is made logged out, as OmnaBase's is (round one's `deliver`), and the test's
	 * own user is signed in again afterwards, so a test that asks first and then renders the settings screen or posts its forms does so as
	 * the administrator it set up (the screen tests' "a row needing attention" asks on the way).
	 *
	 * @param string      $email    The ticket's address.
	 * @param string|null $external This site's id for the person, when OmnaBase knows it.
	 * @return array{status:int,raw:string,answer:mixed}
	 */
	protected function askFacts( string $email, ?string $external = null ): array {
		$body     = (string) wp_json_encode(
			array(
				'v'        => 2,
				'project'  => self::PROJECT,
				'customer' => array( 'email' => $email, 'external' => $external ),
				'ticket'   => array( 'subject' => 'Where is my licence?' ),
				'sentAt'   => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			)
		);
		$user     = get_current_user_id();
		$response = $this->deliver( '/omnabase/v1/customer', $body, self::FACTS );
		wp_set_current_user( $user );
		$server   = $this->rest();
		$raw      = (string) wp_json_encode( $server->response_to_data( $response, false ) ); // what WordPress's REST server sends: no flags
		return array( 'status' => $response->get_status(), 'raw' => $raw, 'answer' => json_decode( $raw ) );
	}

	/** The `omnabase` block of a facts answer (an object), asserting the answer was a 200 that OmnaBase accepts. */
	protected function blockFor( string $email, ?string $external = null ): object {
		$a = $this->askFacts( $email, $external );
		$this->assertSame( 200, $a['status'], $a['raw'] );
		$this->assertNull( \OmnaBase\WordPress\Tests\Unit\Support\SiteFactsOracle::refusal( $a['raw'] ), 'OmnaBase\'s reader accepts the answer: ' . substr( $a['raw'], 0, 300 ) );
		$this->assertIsObject( $a['answer']->omnabase ?? null, 'the answer carries the omnabase block: ' . substr( $a['raw'], 0, 300 ) );
		return $a['answer']->omnabase;
	}

	/* ---------- people and shops ---------- */

	/**
	 * A customer of the shop.
	 *
	 * @param string $email      The address.
	 * @param string $name       The display name.
	 * @param string $registered When they registered (UTC).
	 */
	protected function customer( string $email, string $name = 'Ada Lovelace', string $registered = '2021-03-04 09:00:00' ): int {
		return (int) self::factory()->user->create( array( 'user_email' => $email, 'display_name' => $name, 'first_name' => strtok( $name, ' ' ), 'role' => 'customer', 'user_registered' => $registered ) );
	}

	/** A WooCommerce product. */
	protected function product( string $name = 'Enfold Theme', string $price = '59.00' ): \WC_Product_Simple {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( $price );
		$p->set_virtual( true );
		$p->save();
		return $p;
	}

	/**
	 * A WooCommerce order.
	 *
	 * @param int    $user    The customer (0 for a guest).
	 * @param string $billing The billing address.
	 * @param string $created When it was made (UTC).
	 * @param string $status  Its status.
	 */
	protected function wooOrder( int $user, string $billing, string $created = '2026-09-30 10:00:00', string $status = 'completed', ?\WC_Product $product = null ): \WC_Order {
		$order = wc_create_order( array( 'customer_id' => $user ) );
		$order->add_product( $product ?? $this->product(), 1 );
		$order->set_billing_email( $billing );
		$order->set_billing_first_name( 'Ada' );
		$order->set_currency( 'EUR' );
		$order->set_date_created( strtotime( $created . ' UTC' ) );
		$order->calculate_totals();
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	/** An EDD download (a product). */
	protected function download( string $name = 'Omnalingo Pro', string $version = '' ): int {
		$id = (int) self::factory()->post->create( array( 'post_type' => 'download', 'post_title' => $name, 'post_status' => 'publish' ) );
		if ( '' !== $version ) {
			update_post_meta( $id, '_edd_sl_version', $version );
		}
		return $id;
	}

	/* ---------- the outbox ---------- */

	protected function outboxTable(): string {
		global $wpdb;
		return $wpdb->prefix . 'omnabase_outbox';
	}

	/** Every row, oldest first. */
	protected function outboxRows( ?string $kind = null ): array {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		$rows  = (array) $wpdb->get_results( "SELECT * FROM {$this->outboxTable()} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $quiet );
		return array_values( null === $kind ? $rows : array_filter( $rows, static fn ( $r ) => $kind === $r['kind'] ) );
	}

	protected function clearOutbox(): void {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true ); // before round two there is no table: the test then fails at its own assertion
		$wpdb->query( "DELETE FROM {$this->outboxTable()}" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( $quiet );
	}

	/** A row's next try as a unix time. */
	protected static function nextAt( array $row ): int {
		$v = (string) $row['next_at'];
		return ctype_digit( $v ) ? (int) $v : (int) strtotime( $v . ' UTC' );
	}

	/** Every waiting row becomes due (time passes). */
	protected function makeDue(): void {
		global $wpdb;
		$type = (string) $wpdb->get_var( $wpdb->prepare( "SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'next_at'", $this->outboxTable() ) ); // phpcs:ignore WordPress.DB
		$past = in_array( strtolower( $type ), array( 'int', 'bigint', 'integer' ), true ) || '' === $type ? (string) ( time() - 1 ) : gmdate( 'Y-m-d H:i:s', time() - 1 );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->outboxTable()} SET next_at = %s WHERE state = 'waiting'", $past ) ); // phpcs:ignore WordPress.DB
	}

	/** One worker run (the WP-Cron job). */
	protected function runOutbox(): void {
		do_action( self::OUTBOX_HOOK );
	}

	/** The site's id for a user. */
	protected static function siteIdOf( int $user ): string {
		return ( new SiteId() )->forUser( $user );
	}

	/**
	 * OmnaBase answers `PUT /customers/by-external/{id}` (201) and its refresh (200) for these users.
	 *
	 * @param int ...$users The users.
	 */
	protected function omnabaseTakes( int ...$users ): void {
		foreach ( $users as $user ) {
			$id = rawurlencode( self::siteIdOf( $user ) );
			$this->answers[ "POST /customers/by-external/{$id}/refresh" ] = self::answer( 200, array( 'ok' => true, 'licences' => 1 ) );
			$this->answers[ "PUT /customers/by-external/{$id}" ]          = self::answer( 201, array( 'externalId' => self::siteIdOf( $user ), 'confirmed' => false, 'customer' => null ) );
		}
	}

	/** The calls the plugin made to OmnaBase for a user's link or refresh. */
	protected function callsFor( int $user, string $method, bool $refresh ): array {
		$path = '/api/public/v1/customers/by-external/' . rawurlencode( self::siteIdOf( $user ) ) . ( $refresh ? '/refresh' : '' );
		return array_values( array_filter( $this->calls, static fn ( $c ) => $method === $c['method'] && (string) wp_parse_url( $c['url'], PHP_URL_PATH ) === $path ) );
	}

	/**
	 * The user becomes known to OmnaBase the way a real site does: OmnaBase asks about their address, the plugin queues the link, the
	 * worker sends it and OmnaBase answers 201.
	 */
	protected function linkToOmnaBase( int $user ): void {
		$this->omnabaseTakes( $user );
		$this->switchOn( array( 'profile' ) );
		$this->askFacts( (string) get_userdata( $user )->user_email );
		$this->makeDue();
		$this->runOutbox();
		$this->assertCount( 1, $this->callsFor( $user, 'PUT', false ), 'the user was linked to OmnaBase with their site id' );
		$this->clearOutbox();
		$this->calls = array();
	}
}
