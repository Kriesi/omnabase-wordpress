<?php
/**
 * Fixing round "sites in a subfolder", the red team's failing tests: the plugin on a WordPress network (multisite), checked against a small
 * fake network because wp-env's tests WordPress is a single site.
 *
 * The coordinator's contract: "the plugin activated on one sub-site of a subdirectory network works there: its options, its outbox table
 * (`$wpdb->prefix` is the sub-site's), its cron, its connection are that sub-site's; … activating on another sub-site makes that sub-site's own
 * table; the uninstall removes every sub-site's data when the plugin is deleted network-wide (`uninstall.php` loops the sites if multisite);
 * network activation is allowed and each site keeps its own connection"; "the first-display marks, the message centre's page pick, the forum
 * settings, the My Account link: per site (they already use options; pin one of them)".
 *
 * The words: *network* (WordPress multisite), several sites sharing one WordPress installation; *sub-site*, one site of it (in a
 * subdirectory network at `https://example.com/shop`); each site has its own *options* (stored settings), posts, *scheduled jobs* (WP-Cron,
 * WordPress's timer) and *table prefix* (`wp_2_` …), while **users and their user meta are shared by every site**; *network activation*,
 * turning a plugin on for every site at once (WordPress then runs the plugin's activation step once, on the main site, with a "network-wide"
 * flag); *uninstall*, what runs when the plugin is deleted, which on a network happens once for all sites.
 *
 * A finding behind four of these tests (the red team's, not in the contract): the plugin keeps five things in **user meta** that belong to one
 * site's connection: the "known to OmnaBase" mark, an administrator's pending connect, the message centre's fingerprint, its confirmation-link
 * count and a refused question's kept words. User meta is shared by the whole network, so today sub-site B reads sub-site A's marks (B never
 * introduces a customer to its own OmnaBase workspace because A's workspace knows them), a new connection on A wipes B's marks, and a super
 * administrator connecting A and B one after the other loses A's pending connect. Each of these must be per site. (The customer's site id,
 * `omnabase_site_id`, may stay shared: it names the person, not the connection.)
 *
 * Shapes pinned (the builder may rename and tell the red team):
 * - The classes as they are: `Outbox\Table` (`name()`, `maybeInstall()`), `Sync\KnownUsers` (`mark`, `isKnown`, `forgetAll`),
 *   `Connect\ConnectState( Vault )` (`begin`, `take`), `Portal\Draft` (`keep`, `of`), `Portal\LinkLimit` (`allow`), `Sync\SiteId`
 *   (`forUser`), `Forms\FirstShown( Display )` (`note`), `Bootstrap` (`start`, `deactivate`), `Privacy\Holdings( Table )` (`userMeta`, keyed
 *   by the plain names in `Options`, as the exporter reads them) and `Privacy\Eraser( Holdings, Table )` (`erase`).
 * - (chosen) How a per-site user mark is stored is the builder's: a WordPress *user option* (`update_user_option`, stored under the site's
 *   prefix) or user meta under a key carrying the site. The fake network supports both; the uninstall test finds whatever the classes wrote.
 * - (chosen) Deactivation takes WordPress's network-wide flag: `Bootstrap::deactivate( true )` clears every site's timers; a sub-site's own
 *   deactivation clears only that site's.
 * - (chosen) Network activation: each site has its own table after activation or at the latest on its first page load (the plugin already
 *   makes a missing table on load; either way passes).
 * - The uninstall runs as WordPress runs it (`WP_UNINSTALL_PLUGIN` defined, the main site current); tests that require it run in their own
 *   PHP process.
 *
 * Why these fail today: the user marks are network-wide user meta; `uninstall.php` cleans only the current site (and would need to ask for
 * more than WordPress's default 100 sites); `deactivate()` clears only the current site's timers.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Bootstrap;
use OmnaBase\WordPress\Connect\ConnectState;
use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\Forms\FirstShown;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Outbox\Table;
use OmnaBase\WordPress\Portal\Draft;
use OmnaBase\WordPress\Portal\LinkLimit;
use OmnaBase\WordPress\Privacy\Eraser;
use OmnaBase\WordPress\Privacy\Holdings;
use OmnaBase\WordPress\Security\Vault;
use OmnaBase\WordPress\Sync\KnownUsers;
use OmnaBase\WordPress\Sync\SiteId;
use OmnaBase\WordPress\Tests\Unit\Support\FakeNetwork;

final class MultisiteTest extends TestCase {

	private const CUSTOMER = 7;
	private const ADMIN    = 1;

	private FakeNetwork $net;

	protected function setUp(): void {
		parent::setUp();
		$this->net = new FakeNetwork( array( 1, 2, 3 ) );
		$this->net->install();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * What it checks: The outbox's table is the current site's: on a network each site's waiting calls live in that site's own table.
	 * Passes when: on the main site the table is wp_omnabase_outbox, after switching to site 2 it is wp_2_omnabase_outbox, and back on the
	 *   main site it is wp_omnabase_outbox again (the name is never remembered from an earlier site).
	 * Fails when: site 2's forum topics wait in the main site's table and are sent with the main site's key, so they land in the wrong
	 *   workspace or never go.
	 */
	public function test_the_outbox_table_is_named_by_the_current_site(): void {
		$this->assertSame( 'wp_omnabase_outbox', Table::name() );
		switch_to_blog( 2 );
		$this->assertSame( 'wp_2_omnabase_outbox', Table::name() );
		restore_current_blog();
		$this->assertSame( 'wp_omnabase_outbox', Table::name() );
	}

	/**
	 * What it checks: A sub-site that turns the plugin on gets its own outbox table, and the main site's is left as it is.
	 * Passes when: on site 3, which has no table yet, the outbox's install step makes wp_3_omnabase_outbox and notes the table's version in
	 *   site 3's settings only; the main site has no table and no version note from it.
	 * Fails when: activating on a second sub-site reuses or alters another site's table, or notes the version on the wrong site so the
	 *   sub-site never gets its table.
	 */
	public function test_a_sub_site_gets_its_own_outbox_table(): void {
		switch_to_blog( 3 );
		( new Table() )->maybeInstall();
		restore_current_blog();

		$this->assertArrayHasKey( 'wp_3_omnabase_outbox', $this->net->tables );
		$this->assertArrayNotHasKey( 'wp_omnabase_outbox', $this->net->tables );
		$this->assertSame( Table::VERSION, (string) ( $this->net->options[3][ Options::DB_VERSION ] ?? '' ) );
		$this->assertArrayNotHasKey( Options::DB_VERSION, $this->net->options[1] ?? array() );
	}

	/**
	 * What it checks: Network activation (the plugin turned on for every site at once) leaves every site with its own outbox table, made at
	 *   activation or on the site's first page load.
	 * Passes when: the activation step runs once with the network-wide flag on the main site; then each site's first load runs the plugin's
	 *   start (which makes a missing table); afterwards sites 1, 2 and 3 each have their own table and their own version note, and the
	 *   plugin's start does make a missing table on load.
	 * Fails when: only the main site gets a table, and every other site's outbox fails on its first forum topic with a missing-table error.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_network_activation_leaves_every_site_with_its_own_table(): void {
		$activate = null;
		Functions\when( 'register_activation_hook' )->alias(
			static function ( $file, $callback ) use ( &$activate ) {
				$activate = $callback;
			}
		);
		Functions\when( 'register_deactivation_hook' )->justReturn( null );
		Bootstrap::start( OMNABASE_REPO . '/omnabase.php' );
		$this->assertIsCallable( $activate, 'the plugin registers an activation step' );

		$activate( true );
		$this->assertSame( 1, get_current_blog_id(), 'activation leaves the main site current' );
		foreach ( array( 1, 2, 3 ) as $site ) {
			$this->net->on( $site, static fn () => ( new Table() )->maybeInstall() ); // what each site's first load does
		}

		foreach ( array( 1, 2, 3 ) as $site ) {
			$this->assertArrayHasKey( $this->net->prefix( $site ) . 'omnabase_outbox', $this->net->tables, "site {$site}'s table" );
			$this->assertSame( Table::VERSION, (string) ( $this->net->options[ $site ][ Options::DB_VERSION ] ?? '' ), "site {$site}'s version note" );
		}
		$this->assertTrue( self::loadMakesAMissingTable(), 'the plugin\'s start makes a missing table on load (or activation loops the sites)' );
	}

	/**
	 * What it checks: "Known to OmnaBase" is a site's own knowledge: a customer introduced to one sub-site's OmnaBase workspace is not known
	 *   to another sub-site's.
	 * Passes when: after the customer is marked known on site 1, site 2 says not known and site 1 still says known; marking them on site 2
	 *   leaves both known.
	 * Fails when: site 2 never introduces the customer to its own workspace (it thinks it did, because site 1 did), so site 2's team sees
	 *   no customer data and licence changes are refused.
	 */
	public function test_the_known_mark_is_per_site(): void {
		$known = new KnownUsers();
		$known->mark( self::CUSTOMER );

		switch_to_blog( 2 );
		$this->assertFalse( $known->isKnown( self::CUSTOMER ), 'site 2 has not introduced the customer' );
		restore_current_blog();
		$this->assertTrue( $known->isKnown( self::CUSTOMER ), 'site 1 has' );

		switch_to_blog( 2 );
		$known->mark( self::CUSTOMER );
		$this->assertTrue( $known->isKnown( self::CUSTOMER ) );
		restore_current_blog();
		$this->assertTrue( $known->isKnown( self::CUSTOMER ) );
	}

	/**
	 * What it checks: Connecting one sub-site anew forgets only that site's "known" marks.
	 * Passes when: the customer is known on sites 1 and 2; site 2 starts afresh (a new connection); site 1 still knows the customer and
	 *   site 2 does not.
	 * Fails when: reconnecting one shop on the network makes every other shop re-introduce all its customers and pause their licence updates.
	 */
	public function test_a_new_connection_on_one_site_keeps_the_other_sites_marks(): void {
		$known = new KnownUsers();
		$known->mark( self::CUSTOMER );
		switch_to_blog( 2 );
		$known->mark( self::CUSTOMER );

		$known->forgetAll();

		$this->assertFalse( $known->isKnown( self::CUSTOMER ), 'site 2 starts afresh' );
		restore_current_blog();
		$this->assertTrue( $known->isKnown( self::CUSTOMER ), 'site 1 keeps its marks' );
	}

	/**
	 * What it checks: A network administrator who clicks Connect on two sub-sites keeps a pending connect on each.
	 * Passes when: the same administrator starts Connect on site 1, then on site 2; coming back to site 1 with site 1's state completes (the
	 *   secret opens), and coming back to site 2 with site 2's state completes too.
	 * Fails when: the second click overwrites the first, so the administrator approves site 1 on OmnaBase and is told "this way back was not
	 *   started by your Connect".
	 */
	public function test_a_pending_connect_is_per_site(): void {
		$pending = new ConnectState( new Vault() );
		$one     = $pending->begin( self::ADMIN );
		switch_to_blog( 2 );
		$two = $pending->begin( self::ADMIN );
		restore_current_blog();

		$back = $pending->take( self::ADMIN, $one['state'] );
		$this->assertNull( $back['problem'], 'site 1\'s way back completes' );
		$this->assertNotNull( $back['verifier'] );

		switch_to_blog( 2 );
		$this->assertNull( $pending->take( self::ADMIN, $two['state'] )['problem'], 'site 2\'s way back completes' );
		restore_current_blog();
	}

	/**
	 * What it checks: The message centre's per-customer notes belong to the site the customer used: a refused question's words, and the count
	 *   of confirmation links asked for.
	 * Passes when: words kept on site 1 show on site 1 and not on site 2; after three confirmation links on site 1 (the fourth refused
	 *   there), site 2 still allows one.
	 * Fails when: a question written in shop A's message centre shows up pre-filled in shop B's, or asking for links in shop A blocks the
	 *   customer from confirming their address in shop B for a day.
	 */
	public function test_the_message_centres_notes_are_per_site(): void {
		$draft = new Draft();
		$draft->keep( self::CUSTOMER, 'Licence', 'My key stopped working' );
		$limit = new LinkLimit();
		foreach ( array( 1, 2, 3 ) as $n ) {
			$this->assertTrue( $limit->allow( self::CUSTOMER ), "link {$n} on site 1" );
		}
		$this->assertFalse( $limit->allow( self::CUSTOMER ), 'the fourth on site 1 is refused' );

		switch_to_blog( 2 );
		$this->assertSame( array( 'subject' => '', 'message' => '' ), $draft->of( self::CUSTOMER ), 'nothing kept on site 2' );
		$this->assertTrue( $limit->allow( self::CUSTOMER ), 'site 2 counts its own links' );
		restore_current_blog();
		$this->assertSame( 'My key stopped working', $draft->of( self::CUSTOMER )['message'], 'still kept on site 1' );
	}

	/**
	 * What it checks: WordPress's privacy tools (export and erase a person's data) on a sub-site find and remove that sub-site's own notes
	 *   about the customer, and leave another sub-site's alone.
	 * Passes when: with the customer known and a question's words kept on sites 1 and 2, the plugin's list of what it holds about the
	 *   customer, read on site 2, names the "known" mark and the kept words; erasing the customer on site 2 leaves site 2 with neither, while
	 *   site 1 still knows the customer and keeps the words.
	 * Fails when: a customer's data export on a sub-site misses what the plugin keeps there (a privacy request answered incompletely), or an
	 *   erasure on one shop wipes another shop's records, or leaves the shop's own behind.
	 */
	public function test_the_privacy_tools_read_and_erase_this_sites_notes(): void {
		$customer     = \Mockery::mock( 'WP_User' );
		$customer->ID = self::CUSTOMER;
		Functions\when( 'get_user_by' )->justReturn( $customer );
		foreach ( array( 1, 2 ) as $site ) {
			$this->net->on(
				$site,
				static function () {
					( new KnownUsers() )->mark( self::CUSTOMER );
					( new Draft() )->keep( self::CUSTOMER, 'Licence', 'Words for this shop' );
				}
			);
		}
		$holdings = new Holdings( new Table() );

		switch_to_blog( 2 );
		$held = $holdings->userMeta( self::CUSTOMER );
		$this->assertArrayHasKey( Options::KNOWN_META, $held, 'the "known" mark, read on site 2' );
		$this->assertArrayHasKey( Options::PORTAL_DRAFT_META, $held, 'the kept words, read on site 2' );
		( new Eraser( $holdings, new Table() ) )->erase( 'customer@example.org' );
		$this->assertFalse( ( new KnownUsers() )->isKnown( self::CUSTOMER ), 'site 2\'s mark erased' );
		$this->assertSame( '', ( new Draft() )->of( self::CUSTOMER )['message'], 'site 2\'s words erased' );
		restore_current_blog();

		$this->assertTrue( ( new KnownUsers() )->isKnown( self::CUSTOMER ), 'site 1 keeps its mark' );
		$this->assertSame( 'Words for this shop', ( new Draft() )->of( self::CUSTOMER )['message'], 'site 1 keeps its words' );
	}

	/**
	 * What it checks: The "first shown" marks the setup line ticks off are per site (they are settings, which WordPress keeps per site).
	 * Passes when: the contact form's first public showing on site 2 is noted on site 2; site 1 has no such note.
	 * Fails when: site 1's setup line ticks "Add the form to a page" because another shop on the network showed its form.
	 */
	public function test_the_first_shown_marks_are_per_site(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_is_serving_rest_request' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		$display = new Display();

		switch_to_blog( 2 );
		( new FirstShown( $display ) )->note( 'form' );
		$this->assertArrayHasKey( 'form', $display->firstShown(), 'noted on site 2' );
		restore_current_blog();

		$this->assertSame( array(), $display->firstShown(), 'site 1 has no note' );
	}

	/**
	 * What it checks: Deleting the plugin from a network removes what it kept on every site and stops every connected site's key at OmnaBase.
	 * Passes when: on a network of three sites (1 and 2 connected, 3 not), each with the plugin's settings, its table, its jobs, its notes on
	 *   posts and its notes on a customer and an administrator, running the uninstall from the main site leaves nothing of the plugin anywhere
	 *   (no setting, no table, no job, no post note, no user note on any site); OmnaBase gets exactly one "stop this key" call from site 1 with
	 *   site 1's key and one from site 2 with site 2's key, none for site 3; the main site is current again afterwards.
	 * Fails when: deleting the plugin leaves sub-sites' customer notes, queued messages and keys behind (their keys stay valid at OmnaBase
	 *   and their tables stay in the database), or one site's key is used to stop another's.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_on_a_network_cleans_every_site_and_stops_each_key(): void {
		foreach ( array( 1, 2, 3 ) as $site ) {
			$this->net->on( $site, fn () => $this->plantEverything( $site, $site <= 2 ) );
		}
		$this->assertNotSame( array(), $this->net->traces(), 'the arrangement planted the plugin\'s data' );

		$this->runUninstall();

		$this->assertSame( array(), $this->net->traces(), 'nothing of the plugin is left on any site' );
		$stops = array_values( array_filter( $this->net->requests, static fn ( $r ) => 'DELETE' === $r['method'] && str_ends_with( $r['url'], '/me' ) ) );
		$this->assertCount( 2, $stops, 'one stop per connected site' );
		$by_site = array();
		foreach ( $stops as $stop ) {
			$by_site[ $stop['blog'] ] = $stop['key'];
		}
		ksort( $by_site );
		$this->assertSame( array( 1 => self::keyOf( 1 ), 2 => self::keyOf( 2 ) ), $by_site, 'each site stops its own key, from itself' );
		$this->assertSame( 1, get_current_blog_id(), 'the main site is current again' );
		$this->assertSame( array(), $this->net->stack, 'every switch was undone' );
	}

	/**
	 * What it checks: On a network larger than WordPress's default page of 100 sites, the uninstall still reaches every site.
	 * Passes when: on a network of 150 sites, each with the plugin's table and settings, the uninstall leaves no table and no setting on any
	 *   of them.
	 * Fails when: sites 101 to 150 keep their tables and settings forever, because the loop only asked WordPress for its first 100 sites.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_reaches_every_site_of_a_large_network(): void {
		$this->net = new FakeNetwork( range( 1, 150 ) );
		$this->net->install();
		foreach ( $this->net->sites as $site ) {
			$this->net->on(
				$site,
				function () use ( $site ) {
					$this->net->tables[ $this->net->prefix( $site ) . 'omnabase_outbox' ] = true;
					update_option( Options::FORUM, array( 'forums' => array( 3 ) ) );
				}
			);
		}

		$this->runUninstall();

		$this->assertSame( array(), $this->net->traces() );
	}

	/**
	 * What it checks: Deleting the plugin from a single WordPress (no network) works as before and never asks for a network's sites.
	 * Passes when: on a single site, connected, with everything planted, the uninstall leaves nothing of the plugin, makes exactly one "stop
	 *   this key" call with the site's key, and never asks WordPress for the network's sites.
	 * Fails when: the network loop breaks the common case: a single site keeps its data, or the uninstall asks a single WordPress for its
	 *   network's sites (a database error on a site without a network).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_on_a_single_site_works_as_before(): void {
		$this->net = new FakeNetwork( array( 1 ), false );
		$this->net->install();
		$this->plantEverything( 1, true );

		$this->runUninstall();

		$this->assertSame( array(), $this->net->traces() );
		$this->assertCount( 1, $this->net->requests );
		$this->assertSame( self::keyOf( 1 ), $this->net->requests[0]['key'] );
		$this->assertSame( 0, $this->net->sitesAsked, 'a single WordPress is never asked for its network\'s sites' );
	}

	/**
	 * What it checks: Turning the plugin off for the whole network stops its timers on every site; turning it off on one sub-site stops only
	 *   that site's.
	 * Passes when: with the outbox's two timers and the forum's mail job on sites 1, 2 and 3, a network-wide deactivation leaves no timer on
	 *   any site; in a second round, deactivating on site 2 alone clears site 2's timers and leaves sites 1 and 3 untouched.
	 * Fails when: after a network-wide deactivation the sub-sites' timers keep firing for a plugin that is off, or deactivating on one shop
	 *   silently stops another shop's outbox.
	 */
	public function test_deactivation_clears_the_timers_of_the_sites_it_applies_to(): void {
		$this->plantTimers();
		Bootstrap::deactivate( true );
		$this->assertSame( array(), $this->net->traces(), 'no timer left on any site' );

		$this->plantTimers();
		switch_to_blog( 2 );
		Bootstrap::deactivate( false );
		restore_current_blog();
		$left = $this->net->traces();
		$this->assertNotContains( 'site 2 job omnabase_outbox', $left );
		$this->assertContains( 'site 1 job omnabase_outbox', $left );
		$this->assertContains( 'site 3 job omnabase_outbox', $left );
	}

	/* ---------- helpers ---------- */

	/** A site's key (each site its own). */
	private static function keyOf( int $site ): string {
		return 'obw_site' . $site . '_' . str_repeat( 'k', 40 ) . '_ab7Q';
	}

	/**
	 * Plants on the current site everything the plugin keeps, through the plugin's own classes where a class writes it.
	 *
	 * @param int  $site      The site (for its key and table).
	 * @param bool $connected Whether the site holds a key.
	 */
	private function plantEverything( int $site, bool $connected ): void {
		foreach ( Options::ALL as $option ) {
			update_option( $option, array( 'planted' => $site ) );
		}
		delete_option( Options::VAULT );
		if ( $connected ) {
			( new Vault() )->save( array( 'key' => self::keyOf( $site ), 'webhooks' => 'whsec_' . $site, 'facts' => 'facts' . $site ) );
		}
		update_option( '_transient_omnabase_notice_' . self::ADMIN, 'Done' );
		update_option( '_transient_timeout_omnabase_notice_' . self::ADMIN, time() + 60 );
		update_option( '_transient_omnabase_form_token_abc', 'x' );
		update_option( '_transient_timeout_omnabase_form_token_abc', time() + 60 );
		$this->net->postmeta[ $site ][ 40 ] = array( Options::TICKET_META => 1234 );
		$this->net->postmeta[ $site ][ 41 ] = array( Options::MESSAGE_META => 'msg-1' );
		foreach ( array( 'omnabase_outbox', 'omnabase_outbox_now', 'omnabase_forum_mails' ) as $hook ) {
			$this->net->cron[ $site ][ $hook ] = true;
		}
		$this->net->tables[ $this->net->prefix( $site ) . 'omnabase_outbox' ] = true;
		( new KnownUsers() )->mark( self::CUSTOMER );
		( new SiteId() )->forUser( self::CUSTOMER );
		( new Draft() )->keep( self::CUSTOMER, 'Subject', 'Words' );
		( new LinkLimit() )->allow( self::CUSTOMER );
		( new ConnectState( new Vault() ) )->begin( self::ADMIN );
	}

	/** The outbox's two timers and the forum's mail job on every site. */
	private function plantTimers(): void {
		foreach ( $this->net->sites as $site ) {
			foreach ( array( 'omnabase_outbox', 'omnabase_outbox_now', 'omnabase_forum_mails' ) as $hook ) {
				$this->net->cron[ $site ][ $hook ] = true;
			}
		}
	}

	/** Runs uninstall.php the way WordPress does: the constant defined, the main site current. */
	private function runUninstall(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'omnabase/omnabase.php' );
		}
		$this->assertSame( $this->net->sites[0], get_current_blog_id() );
		require OMNABASE_REPO . '/uninstall.php';
	}

	/** Whether some part of the plugin's start (outside the table's own class) makes a missing outbox table on load. */
	private static function loadMakesAMissingTable(): bool {
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() && 'Table.php' !== $file->getFilename() && str_contains( (string) file_get_contents( $file->getPathname() ), '->maybeInstall()' ) ) {
				return true;
			}
		}
		return str_contains( (string) file_get_contents( OMNABASE_REPO . '/src/Bootstrap.php' ), 'get_sites' );
	}
}
