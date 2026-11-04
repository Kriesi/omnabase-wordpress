<?php
/**
 * The "support user replies" round (2026-11-04), the red team's failing tests for the coordinator's addition: which build of the plugin a
 * site runs. Every development zip says "0.2.1", so nobody could tell from kriesi.at's screens that it held the files of 2026-11-03 01:33,
 * from before "another member's reply reaches the ticket" (13:59 that day), and guenni007's post on ticket 253 was dropped by that older
 * build. Report `docs/reviews/support-user-replies-round-red-team-report.md` in the OmnaBase repository, finding 9.
 *
 * The words: *build*, one exact state of the plugin's code a zip was made from, named by the short code of its commit (a saved step in the
 * code's history) and the day the zip was made; *zip*, the file the founder uploads under Plugins › Add New › Upload; *constant*, a fixed
 * value PHP code can read anywhere.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - bin/build-dist.sh writes a small file `build.php` beside omnabase.php in the zip: `defined( 'ABSPATH' ) || exit;` and
 *   `define( 'OMNABASE_BUILD', '<short commit> <YYYY-MM-DD>' )`, with ` +changes` when the zip was made from a working copy with unsaved
 *   edits (e.g. `128bbca 2026-11-03`, `98b13d2 2026-11-04 +changes`). omnabase.php loads it when present and defines `OMNABASE_BUILD` as
 *   an empty string otherwise (a copy linked straight from the repository).
 * - `PluginInfo::build(): string` reads that value from `build.php` beside the main file without running it (as the header is read),
 *   trimmed, at most 60 characters of letters, digits, spaces and `.:+-`; an empty string when the file is missing or holds nothing usable.
 *   The settings screen shows it next to the version; every call to OmnaBase names it in its User-Agent (pinned in the structure test).
 *
 * Why these fail today: there is no build file and no `build()`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use OmnaBase\WordPress\PluginInfo;
use PHPUnit\Framework\TestCase;

final class PluginBuildTest extends TestCase {

	/** A plugin folder with the real header and, when given, a `build.php` defining this value (or holding this raw text). */
	private static function folder( ?string $build, bool $raw = false ): string {
		$dir = sys_get_temp_dir() . '/omnabase-build-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- a temporary folder of the test
		copy( OMNABASE_REPO . '/omnabase.php', $dir . '/omnabase.php' );
		if ( null !== $build ) {
			$text = $raw ? $build : "<?php\ndefined( 'ABSPATH' ) || exit;\ndefine( 'OMNABASE_BUILD', '" . $build . "' );\n";
			file_put_contents( $dir . '/build.php', $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary folder of the test
		}
		return $dir . '/omnabase.php';
	}

	/**
	 * What it checks: The plugin knows which build it is, so the settings screen and OmnaBase's logs can name it.
	 * Passes when: with a build file naming "128bbca 2026-11-03" the plugin answers "128bbca 2026-11-03"; with "98b13d2 2026-11-04 +changes",
	 *   that.
	 * Fails when: every development zip still reads only "0.2.1", and nobody can tell whether a site runs the code that sends another
	 *   member's reply.
	 */
	public function test_the_build_file_names_the_build(): void {
		$this->assertTrue( method_exists( PluginInfo::class, 'build' ), 'PluginInfo::build()' );
		$this->assertSame( '128bbca 2026-11-03', PluginInfo::fromFile( self::folder( '128bbca 2026-11-03' ) )->build() );
		$this->assertSame( '98b13d2 2026-11-04 +changes', PluginInfo::fromFile( self::folder( '98b13d2 2026-11-04 +changes' ) )->build() );
	}

	/**
	 * What it checks: A copy without a build file, or with a damaged one, names no build and shows nothing odd.
	 * Passes when: without the file the answer is empty; a file whose value holds markup and a very long tail is answered without a "<" and
	 *   in at most 60 characters; a file with no such line at all is answered empty.
	 * Fails when: a hand-edited or broken file puts markup on the settings screen or into the calls to OmnaBase.
	 */
	public function test_no_or_a_damaged_build_file_names_nothing_odd(): void {
		$this->assertTrue( method_exists( PluginInfo::class, 'build' ), 'PluginInfo::build()' );
		$this->assertSame( '', PluginInfo::fromFile( self::folder( null ) )->build() );
		$odd = PluginInfo::fromFile( self::folder( '<b>128bbca</b>' . str_repeat( 'x', 200 ) ) )->build();
		$this->assertStringNotContainsString( '<', $odd );
		$this->assertLessThanOrEqual( 60, strlen( $odd ) );
		$this->assertSame( '', PluginInfo::fromFile( self::folder( "<?php\n// nothing here\n", true ) )->build() );
	}
}
