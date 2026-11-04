<?php
/**
 * OmnaBase's "site test call" round (2026-11-04; brief and red-team report in the OmnaBase repository,
 * `docs/briefs/site-test-call-round.md` and `docs/reviews/site-test-call-round-red-team-report.md`, finding 3): a test call from OmnaBase's
 * settings asks the site about the team member who pressed "Send a test call". The site answers it as any customer question, but it does
 * not queue the link "tell OmnaBase the site's id for this user": OmnaBase refuses a team member's address as a customer, so the link would
 * wait on the site as a call needing attention.
 *
 * The words: the *outbox* is the plugin's queue of calls to OmnaBase (a table row per call, sent by WordPress's timer); the *link* is the
 * outbox call that tells OmnaBase the site's own id for a user found by address. WordPress is faked (brain/monkey); the outbox's table is a
 * small stand-in for WordPress's database object that records each row written.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Facts\FactsLog;
use OmnaBase\WordPress\Facts\Identity;
use OmnaBase\WordPress\Facts\Responder;
use OmnaBase\WordPress\Facts\Shaper;
use OmnaBase\WordPress\Facts\Switches;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Outbox\Queue;
use OmnaBase\WordPress\Outbox\Schedule;
use OmnaBase\WordPress\Outbox\Table;
use OmnaBase\WordPress\Sync\KnownUsers;
use OmnaBase\WordPress\Sync\SiteId;
use OmnaBase\WordPress\Sync\UserLink;

final class ResponderTestCallTest extends TestCase {

	/** @var object the stand-in for WordPress's database object: every outbox row written */
	private object $db;

	protected function setUp(): void {
		parent::setUp();
		$this->db = new class() {
			public string $prefix = 'wp_';
			/** @var array<int,array<string,mixed>> */
			public array $inserted = array();
			public function prepare( $query, ...$args ) {
				return (string) $query;
			}
			public function get_var( $sql ) {
				return null;
			}
			public function insert( $table, $row, $formats = null ) {
				$this->inserted[] = $row;
				return 1;
			}
		};
		$GLOBALS['wpdb'] = $this->db;
		// one source switched on; the facts log starts empty
		Functions\when( 'get_option' )->alias( static fn ( $name, $fallback = false ) => Options::FACTS === $name ? array( 'sources' => array( 'wp_profile' => true ) ) : $fallback );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'sanitize_email' )->returnArg();
		// the address belongs to a user of the site, not yet known to OmnaBase
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 7, 'user_email' => 'kriesi@example.test' ) );
		Functions\when( 'get_user_option' )->justReturn( false );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->justReturn( 'k3y' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	private function responder(): Responder {
		$links = new UserLink( new Queue( new Table(), new Schedule() ), new SiteId(), new KnownUsers() );
		return new Responder( new Switches(), new Identity( new SiteId() ), array(), new Shaper(), $links, new FactsLog() );
	}

	/** @param array<string,mixed> $extra */
	private static function call( array $extra = array() ): array {
		return array_merge( array( 'v' => 2, 'project' => null, 'customer' => array( 'email' => 'kriesi@example.test', 'external' => null ), 'ticket' => null, 'sentAt' => '2026-11-04T01:16:00.000Z' ), $extra );
	}

	/**
	 * What it checks: A customer question about a site user OmnaBase does not know yet still queues the link, as before (a guard).
	 * Passes when: the plugin answers an ordinary call about the user and the outbox holds one link row about that user.
	 * Fails when: the site stops telling OmnaBase its id for customers found by address, so their later lookups and licence updates cannot
	 *   name them by that id.
	 */
	public function test_an_ordinary_call_queues_the_link(): void {
		$this->responder()->respond( self::call() );
		$this->assertSame( array( array( UserLink::KIND, 7 ) ), array_map( static fn ( $r ) => array( $r['kind'], $r['subject_id'] ), $this->db->inserted ) );
	}

	/**
	 * What it checks: A test call from OmnaBase's settings is answered without linking the team member who pressed the button.
	 * Passes when: the call carries `test: true`, the plugin answers it, and the outbox stays empty.
	 * Fails when: every test on a site where the presser has an account queues a link that OmnaBase refuses, so the site shows a call needing
	 *   attention after each test.
	 */
	public function test_a_test_call_queues_no_link(): void {
		$answer = $this->responder()->respond( self::call( array( 'test' => true ) ) );
		$this->assertIsObject( $answer );
		$this->assertSame( array(), $this->db->inserted );
	}
}
