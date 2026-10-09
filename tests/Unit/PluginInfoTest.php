<?php
/**
 * The plugin header and version, read from omnabase.php.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use OmnaBase\WordPress\PluginInfo;
use PHPUnit\Framework\TestCase;

/**
 * What it checks: the plugin reads its own name, version and minimum PHP and WordPress versions from the header WordPress shows on
 *   the Plugins screen, so the version the plugin reports is always the one an admin sees.
 * Passes when: the real omnabase.php reads as "OmnaBase" 0.1.0, PHP 8.1, WordPress 6.8, text domain "omnabase", and a header
 *   written in other spacing or with a missing field reads the same way WordPress would read it.
 * Fails when: the reader misses or garbles a field, so the settings screen, the build and the version checks would report a
 *   different version than WordPress shows (a customer could not tell which plugin version they run).
 */
final class PluginInfoTest extends TestCase {

	public function test_the_real_header_reads_as_omnabase_with_its_version_and_minimums(): void {
		$info = PluginInfo::fromFile( OMNABASE_REPO . '/omnabase.php' );

		$this->assertSame( 'OmnaBase', $info->field( 'name' ) );
		$this->assertSame( '0.1.0', $info->version() );
		$this->assertSame( '8.1', $info->requiresPhp() );
		$this->assertSame( '6.8', $info->requiresWp() );
		$this->assertSame( 'omnabase', $info->textDomain() );
		$this->assertSame( OMNABASE_REPO . '/', $info->dir() );
	}

	public function test_the_version_constant_matches_the_header(): void {
		$source = (string) file_get_contents( OMNABASE_REPO . '/omnabase.php' );
		$this->assertMatchesRegularExpression( "/define\\(\\s*'OMNABASE_VERSION',\\s*'([^']+)'\\s*\\)/", $source );
		preg_match( "/define\\(\\s*'OMNABASE_VERSION',\\s*'([^']+)'\\s*\\)/", $source, $match );

		$this->assertSame( PluginInfo::fromFile( OMNABASE_REPO . '/omnabase.php' )->version(), $match[1] );
	}

	public function test_a_header_in_other_spacing_reads_like_wordpress_reads_it(): void {
		$text = "<?php\n/*\nPlugin Name:OmnaBase Test\n * Version:   2.3.4  \n\t* Requires PHP: 8.2 */\n";

		$fields = PluginInfo::parseHeader( $text );

		$this->assertSame( 'OmnaBase Test', $fields['name'] );
		$this->assertSame( '2.3.4', $fields['version'] );
		$this->assertSame( '8.2', $fields['requiresPhp'] );
		$this->assertSame( '', $fields['requiresWp'], 'a missing field reads as empty, as get_file_data() answers it' );
	}
}
