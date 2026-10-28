<?php
/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED (the release notes' reading only). What changed and why:
 * 0.1.0 was released and dated on 2026-10-13 (commit "Release 0.1.0 (beta)"), and the founder's changelog rule freezes a dated section: new
 * work goes into a new "unreleased" section above it (this round's `FrontendDesignStructureTest` asks for one). This file's release-notes
 * test read "the top section, 0.1.0, unreleased", which has failed since the release and would read the new round's section instead of the
 * one that carries this round's lines. It now reads the 0.1.0 section by its version, whatever its date, and asks the same lines of it; where
 * it asked that the section stay undated, it now asks that the released section keep its release date (frozen). No line it asks for changed.
 *
 * M13 round two, the red team's failing tests: the structure guard extended for customer data and the outbox (file-level checks, no
 * WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) "Round two": "`Facts/Responder`, `Facts/Identity`, one class per source behind
 * `FactsSource`, `Facts/Shaper`, `Facts/ContractCheck`, `Outbox/Table`, `Outbox/Worker`, `Sync/SiteId`, `Sync/UserLink`, `Sync/LicenceHooks`, the
 * switches and the picker"; choice 4: the outbox table "made on activation and dropped on uninstall"; choice 5: "WooCommerce orders through
 * `wc_get_orders` (… HPOS compatibility declared)"; choice 10: "Uninstall removes every option, usermeta, post meta, transient, scheduled job
 * and the outbox table"; risks: "WooCommerce and EDD change: public functions only". The plugin's `CLAUDE.md`: "Logic lives in services; SQL
 * only in the outbox's table class (always `$wpdb->prepare`)"; "Test-only code lives under `tests/`"; "`tests/fakes` the paid add-ons'
 * fakes (from round two)"; the customer changelog. The coordinator: "the structure guard additions", "the switches' options in the uninstall
 * list", the outbox "in the uninstall list".
 *
 * Shapes the red team pins: the brief's class names (files under `src/`); `RoundTwoTestCase`'s names for the option `omnabase_facts`, the
 * schema version `omnabase_db_version`, the cron hook `omnabase_outbox`, the user meta holding the site id (any key starting `omnabase_`).
 *
 * Why these fail today: none of the round's files exist; uninstall knows no table, job or site id; no changelog line about customer data.
 *
 * FIXING ROUND "SITES IN A SUBFOLDER" (2026-10-11), THE BUILDER: TWO TESTS ADJUSTED, NOT WEAKENED (DECISIONS, "Sites in a subfolder; a
 * multisite's sub-sites"). On a WordPress network every site shares the users' meta, so the five user notes that belong to one site's
 * connection became WordPress user options, stored under the site's table prefix (`wp_2_omnabase_known`); the red team's new guard forbids
 * deleting them by their plain names. (1) The SQL guard lets a file read the site's table prefix (`global $wpdb;` and
 * `$wpdb->get_blog_prefix()`, which runs no query) and still refuses every other use of `$wpdb` outside the outbox's table class. (2) The
 * uninstall guard asks for the shared site id under its plain name and the per-site notes (the pending connect among them) under the site's
 * prefix, instead of two plain names.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use OmnaBase\WordPress\Options;
use PHPUnit\Framework\TestCase;

final class RoundTwoStructureTest extends TestCase {

	private const ROUND_TWO_FILES = array(
		'src/Facts/Responder.php',
		'src/Facts/Identity.php',
		'src/Facts/FactsSource.php',
		'src/Facts/Shaper.php',
		'src/Facts/ContractCheck.php',
		'src/Outbox/Table.php',
		'src/Outbox/Worker.php',
		'src/Outbox/Policy.php',
		'src/Sync/SiteId.php',
		'src/Sync/UserLink.php',
		'src/Sync/LicenceHooks.php',
		'tests/fakes/woocommerce-subscriptions.php',
		'tests/fakes/edd-recurring.php',
		'tests/fakes/edd-software-licensing.php',
		'tests/e2e/facts.spec.ts',
	);

	/**
	 * What it checks: Round two's parts exist under the brief's names, with one class per customer-data source.
	 * Passes when: every listed file exists, and at least seven classes under src/ implement FactsSource (profile, WooCommerce orders,
	 *   WooCommerce Subscriptions, EDD orders, EDD Recurring, EDD Software Licensing, License Manager).
	 * Fails when: the sources are tangled into one class, so a change in one shop plugin risks breaking every other source.
	 */
	public function test_the_round_two_parts_exist_with_one_class_per_source(): void {
		$missing = array_values( array_filter( self::ROUND_TWO_FILES, static fn ( string $f ) => ! file_exists( OMNABASE_REPO . '/' . $f ) ) );
		$this->assertSame( array(), $missing, 'missing: ' . implode( ', ', $missing ) );
		$sources = array_filter( $this->srcFiles(), fn ( string $f ) => (bool) preg_match( '/\bimplements\b[^{]*\bFactsSource\b/', $this->code( $f ) ) );
		$this->assertGreaterThanOrEqual( 7, count( $sources ), 'classes implementing FactsSource' );
	}

	/**
	 * What it checks: Database queries are written in one place, the outbox's table class, and orders are read through the shops' own
	 *   functions.
	 * Passes when: no file under src/ but src/Outbox/Table.php uses `$wpdb` (reading the site's table prefix, which runs no query, is
	 *   allowed: the per-site user notes need it on a network; since the site-fields round the Forums tab's one bounded read of the forum's
	 *   meta key names in src/Forum/PostFields.php is the named exception); no file queries WooCommerce's orders as posts (`shop_order`), and the
	 *   WooCommerce orders source calls `wc_get_orders`.
	 * Fails when: SQL spreads through the plugin (harder to keep safe from injection), or orders are read in a way that misses shops on
	 *   WooCommerce's newer order storage.
	 */
	public function test_sql_lives_only_in_the_outbox_table_and_orders_come_through_wc_get_orders(): void {
		$sql = array();
		$cpt = array();
		$wc  = false;
		foreach ( $this->srcFiles() as $file ) {
			$code = $this->code( $file );
			$queries = preg_replace( '/global\s+\$wpdb\s*;|\$wpdb->get_blog_prefix\s*\(/', '', $code );
			// Adjusted, not weakened (the site-fields round, the builder, 2026-10-29; OmnaBase's brief docs/briefs/site-fields-round.md, the red
			// team's finding 25 and its own guard SiteFieldsStructureTest): the Post fields' auto-complete reads distinct meta keys with one bounded,
			// prepared query that no WordPress function offers, so src/Forum/PostFields.php is the one named exception beside the outbox's table.
			if ( ! in_array( $file, array( 'src/Outbox/Table.php', 'src/Forum/PostFields.php' ), true ) && preg_match( '/\$wpdb\b/', (string) $queries ) ) {
				$sql[] = $file;
			}
			if ( preg_match( '/[\'"]shop_order[\'"]/', $code ) ) {
				$cpt[] = $file;
			}
			$wc = $wc || (bool) preg_match( '/\bwc_get_orders\s*\(/', $code );
		}
		$this->assertSame( array(), $sql, '$wpdb outside the outbox table class' );
		$this->assertSame( array(), $cpt, 'orders read as posts' );
		$this->assertTrue( $wc, 'WooCommerce orders through wc_get_orders' );
	}

	/**
	 * What it checks: The plugin tells WooCommerce it works with the newer order storage.
	 * Passes when: the code declares compatibility with `custom_order_tables` through WooCommerce's FeaturesUtil.
	 * Fails when: WooCommerce marks the plugin incompatible and nags the shop owner to switch it off.
	 */
	public function test_hpos_compatibility_is_declared(): void {
		$all = $this->code( 'omnabase.php' ) . implode( "\n", array_map( fn ( $f ) => $this->code( $f ), $this->srcFiles() ) );
		$this->assertMatchesRegularExpression( '/FeaturesUtil::declare_compatibility\s*\(\s*[\'"]custom_order_tables[\'"]/', $all );
	}

	/**
	 * What it checks: Deleting the plugin removes what round two stores: the outbox table, the scheduled job, the switches, the schema version
	 *   and each user's site id.
	 * Passes when: the option list holds omnabase_facts and omnabase_db_version (both removed by uninstall.php through the older guard),
	 *   uninstall.php drops the table `omnabase_outbox`, clears the scheduled job omnabase_outbox and deletes the plugin's user meta for all
	 *   users: the site id under its plain name (shared by a network's sites), the pending connect and the other per-site notes under the
	 *   site's table prefix.
	 * Fails when: a shop owner deletes the plugin and the customer-data choices, the queued calls with customers' ids, or the ids themselves stay
	 *   in their database.
	 */
	public function test_uninstall_removes_the_table_the_job_the_switches_and_the_site_ids(): void {
		$this->assertContains( 'omnabase_facts', Options::ALL );
		$this->assertContains( 'omnabase_db_version', Options::ALL );
		$uninstall = $this->code( 'uninstall.php' );
		$this->assertMatchesRegularExpression( '/DROP\s+TABLE\s+IF\s+EXISTS[^;]*omnabase_outbox/i', $uninstall );
		$this->assertMatchesRegularExpression( '/wp_clear_scheduled_hook\s*\(\s*[\'"]omnabase_outbox[\'"]|wp_unschedule_hook\s*\(\s*[\'"]omnabase_outbox[\'"]/', $uninstall );
		$this->assertMatchesRegularExpression( '/delete_metadata\s*\(\s*[\'"]user[\'"]\s*,\s*0\s*,\s*[\'"]omnabase_site_id[\'"]/', $uninstall, 'the site id, for every user' );
		$this->assertMatchesRegularExpression( '/delete_metadata\s*\(\s*[\'"]user[\'"]\s*,\s*0\s*,\s*\$wpdb->get_blog_prefix\s*\(\s*\)\s*\./', $uninstall, 'the per-site notes, for every user, under the site\'s prefix' );
		$this->assertStringContainsString( "'omnabase_pending_connect'", $uninstall, 'the pending connect among them' );
	}

	/**
	 * What it checks: The paid add-ons' fakes are test code only: the plugin never loads or names them, and the release zip leaves them out.
	 * Passes when: nothing under src/, templates/ or the two root files mentions tests/fakes or the fakes' storage options, and the build's file
	 *   list does not include tests/.
	 * Fails when: a fake WooCommerce Subscriptions class ships to customers' sites and shadows the real add-on.
	 */
	public function test_the_fakes_stay_in_the_tests(): void {
		$files = array_merge( $this->srcFiles(), array( 'omnabase.php', 'uninstall.php' ), $this->phpUnder( 'templates' ) );
		foreach ( $files as $file ) {
			$this->assertDoesNotMatchRegularExpression( '#tests/fakes|omnabase_fake_#', $this->code( $file ), $file );
		}
		$this->assertDoesNotMatchRegularExpression( '#(^|[\s"\'(])tests/?([\s"\')]|$)#m', $this->read( 'bin/build-dist.sh' ), 'the zip\'s file list leaves tests/ out' );
	}

	/**
	 * What it checks: The banned usermeta names are written once, in one list the shaper and the picker share.
	 * Passes when: exactly one file under src/ names `_two_factor_backup_codes` (the brief's own example of a banned key).
	 * Fails when: the picker and the shaper keep their own lists, and a name added to one is still offered or sent by the other.
	 */
	public function test_the_banned_meta_list_is_written_once(): void {
		$hits = array_filter( $this->srcFiles(), fn ( string $f ) => str_contains( $this->code( $f ), '_two_factor_backup_codes' ) );
		$this->assertCount( 1, $hits, 'files naming the banned list: ' . implode( ', ', $hits ) );
	}

	/**
	 * What it checks: The customer changelog and the decisions record round two.
	 * Passes when: changelog.txt's 0.1.0 section (released 2026-10-13) has a line about customers' orders or licences on tickets, and DECISIONS.md has a dated
	 *   "M13 round two" heading.
	 * Fails when: site owners are not told their customers' data can now reach OmnaBase, or the round's choices are not written down.
	 */
	public function test_the_changelog_and_decisions_record_round_two(): void {
		$changelog = $this->read( 'changelog.txt' );
		$top       = (string) preg_replace( '/^.*?^= 0\.1\.0 — [^=]+ =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$this->assertMatchesRegularExpression( '/^\* .*\b(orders|licen[cs]es)\b/mi', $top );
		$this->assertMatchesRegularExpression( '/^## \d{4}-\d{2}-\d{2} — M13 round two/m', $this->read( 'DECISIONS.md' ) );
	}

	/** PHP files under src/, relative. */
	private function srcFiles(): array {
		return $this->phpUnder( 'src' );
	}

	/** PHP files under a folder, relative. */
	private function phpUnder( string $dir ): array {
		$out = array();
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && 'php' === $f->getExtension() ) {
				$out[] = str_replace( OMNABASE_REPO . '/', '', $f->getPathname() );
			}
		}
		sort( $out );
		return $out;
	}

	/** A file's code without comments. */
	private function code( string $path ): string {
		$out = '';
		foreach ( token_get_all( $this->read( $path ) ) as $t ) {
			$out .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $out;
	}

	/** A repository file's text. */
	private function read( string $path ): string {
		return is_file( OMNABASE_REPO . '/' . $path ) ? (string) file_get_contents( OMNABASE_REPO . '/' . $path ) : '';
	}
}
