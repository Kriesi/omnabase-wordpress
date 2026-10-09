<?php
/**
 * The structure guard: file-level checks over the repository, no WordPress needed.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\PluginInfo;
use PHPUnit\Framework\TestCase;

/**
 * The repository keeps the files, header fields and promises the plugin is built on. Each test below carries its own description.
 */
final class RepositoryStructureTest extends TestCase {

	/** The files every round relies on (brief, round zero). */
	private const REQUIRED_FILES = array(
		'omnabase.php',
		'uninstall.php',
		'readme.txt',
		'README.md',
		'CLAUDE.md',
		'AGENTS.md',
		'DECISIONS.md',
		'changelog.txt',
		'composer.json',
		'composer.lock',
		'phpcs.xml.dist',
		'phpunit.xml',
		'phpunit-integration.xml',
		'package.json',
		'package-lock.json',
		'playwright.config.ts',
		'.wp-env.json',
		'.gitignore',
		'.editorconfig',
		'bin/build-dist.sh',
		'bin/check-version.sh',
		'bin/seed.sh',
		'bin/test-integration.sh',
		'.github/workflows/checks.yml',
		'src/Bootstrap.php',
		'src/Plugin.php',
		'src/Options.php',
		'src/Admin/SettingsScreen.php',
		'templates/admin/settings.php',
		'tests/e2e/round-trip.spec.ts',
		'tests/e2e/global-setup.ts',
		'tests/wp-env/mu-plugins/omnabase-test-harness.php',
	);

	/**
	 * Patterns that look like a credential. The guard proves each one matches its example before trusting it.
	 *
	 * M13 round two (DECISIONS "Round two"): the long random-looking string skips a name made only of lower-case letters and underscores
	 * (a WordPress hook or option name such as `woocommerce_order_status_changed`, which the shops' licence events need): no generated
	 * secret looks like that, and the example still proves the pattern.
	 */
	private const SECRET_PATTERNS = array(
		'an OmnaBase key'        => array( '/obw_[A-Za-z0-9]{20,}/', "'obw_k3j9x2qak3j9x2qak3j9x2qa_7Q2XAB'" ),
		'a webhook secret'       => array( '/whsec_[A-Za-z0-9]{10,}/', "'whsec_0123456789abcdef'" ),
		'a private key'          => array( '/-----BEGIN [A-Z ]*PRIVATE KEY-----/', '-----BEGIN RSA PRIVATE KEY-----' ),
		'a Stripe-style key'     => array( '/\b[spr]k_(live|test)_[A-Za-z0-9]{10,}/', "'sk_live_0123456789ab'" ),
		'an AWS access key'      => array( '/AKIA[0-9A-Z]{16}/', "'AKIAABCDEFGHIJKLMNOP'" ),
		'a long random-looking string' => array( '/[\'"](?![a-z_]+[\'"])[A-Za-z0-9+\/=_-]{32,}[\'"]/', "'MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY='" ),
	);

	/**
	 * What it checks: every file the plugin and its tooling are built from is in the repository.
	 * Passes when: each listed file exists.
	 * Fails when: one is missing, so a later round, the build or the founder's checks would break or silently skip a step.
	 */
	public function test_the_required_files_exist(): void {
		$missing = array_values( array_filter( self::REQUIRED_FILES, static fn ( string $f ) => ! file_exists( OMNABASE_REPO . '/' . $f ) ) );

		$this->assertSame( array(), $missing, 'missing files: ' . implode( ', ', $missing ) );
	}

	/**
	 * What it checks: the plugin header promises what the brief decided: the name, the slug as text domain, PHP 8.1, WordPress 6.8,
	 *   the GPL licence and a plain version number.
	 * Passes when: each header field holds its decided value.
	 * Fails when: a field drifts, so WordPress would let the plugin activate on a PHP it does not support, or translations and
	 *   WordPress.org's review would not find the plugin's text domain.
	 */
	public function test_the_header_fields_hold_the_decided_values(): void {
		$info = PluginInfo::fromFile( OMNABASE_REPO . '/omnabase.php' );

		$this->assertSame( 'OmnaBase', $info->field( 'name' ) );
		$this->assertSame( 'omnabase', $info->textDomain() );
		$this->assertSame( '/languages', $info->field( 'domainPath' ) );
		$this->assertSame( '8.1', $info->requiresPhp() );
		$this->assertTrue( version_compare( $info->requiresWp(), '6.8', '>=' ), 'Requires at least must be 6.8 or newer' );
		$this->assertSame( 'GPL-2.0-or-later', $info->field( 'license' ) );
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $info->version() );
	}

	/**
	 * What it checks: the version and the PHP minimum agree everywhere they are written: the header, the version constant,
	 *   readme.txt, the top of changelog.txt and composer.json.
	 * Passes when: every place names the same version and PHP 8.1.
	 * Fails when: one place lags behind, so WordPress.org or a customer would see one version while the plugin reports another.
	 */
	public function test_the_versions_agree_everywhere(): void {
		$info     = PluginInfo::fromFile( OMNABASE_REPO . '/omnabase.php' );
		$main     = $this->read( 'omnabase.php' );
		$readme   = $this->read( 'readme.txt' );
		$composer = json_decode( $this->read( 'composer.json' ), true );

		preg_match( "/define\\(\\s*'OMNABASE_VERSION',\\s*'([^']+)'/", $main, $constant );
		preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $stable );
		preg_match( '/^Requires PHP:\s*(\S+)/mi', $readme, $readme_php );
		preg_match( '/^Requires at least:\s*(\S+)/mi', $readme, $readme_wp );

		$this->assertSame( $info->version(), $constant[1] ?? null, 'OMNABASE_VERSION' );
		$this->assertSame( $info->version(), $stable[1] ?? null, 'readme.txt Stable tag' );
		$this->assertSame( $info->requiresPhp(), $readme_php[1] ?? null, 'readme.txt Requires PHP' );
		$this->assertSame( $info->requiresWp(), $readme_wp[1] ?? null, 'readme.txt Requires at least' );
		$this->assertSame( $info->version(), $this->topChangelogVersion(), 'the top section of changelog.txt' );
		$this->assertSame( '^8.1', $composer['require']['php'] ?? null, 'composer.json require.php' );
		$this->assertSame( '8.1', $composer['config']['platform']['php'] ?? null, 'composer.json config.platform.php' );
	}

	/**
	 * What it checks: deleting the plugin removes every option it stores: uninstall.php names each option on the plugin's one list,
	 *   and the code stores no option that is missing from that list.
	 * Passes when: every name in the option list appears in uninstall.php, and every option name the code reads or writes is on the
	 *   list.
	 * Fails when: an option is added without the list or without uninstall.php, so the plugin would leave data (perhaps a key)
	 *   behind on a customer's site after they deleted it.
	 */
	public function test_uninstall_names_every_option_the_plugin_defines(): void {
		$uninstall = $this->read( 'uninstall.php' );
		foreach ( Options::ALL as $option ) {
			$this->assertStringContainsString( "'" . $option . "'", $uninstall, "uninstall.php does not remove {$option}" );
		}

		$used = array();
		foreach ( $this->runtimeFiles() as $file ) {
			preg_match_all( "/\\b(?:get|update|add|delete)_(?:site_)?option\\(\\s*'([^']+)'/", (string) file_get_contents( $file ), $m );
			$used = array_merge( $used, $m[1] );
		}
		$unlisted = array_values( array_diff( array_unique( $used ), Options::ALL ) );
		$this->assertSame( array(), $unlisted, 'options used in the code but missing from Options::ALL: ' . implode( ', ', $unlisted ) );
	}

	/**
	 * What it checks: no key, secret or private key is written into the plugin's code.
	 * Passes when: none of the credential patterns (an OmnaBase key, a webhook secret, a private key, a payment key, an AWS key, a
	 *   long random-looking string) appears in src/, templates/, omnabase.php or uninstall.php, and each pattern is proven to catch
	 *   its own example first.
	 * Fails when: a credential lands in the code, so it would ship in the zip to every site that installs the plugin.
	 */
	public function test_no_secret_looking_literal_in_the_runtime_code(): void {
		foreach ( self::SECRET_PATTERNS as $name => [ $pattern, $example ] ) {
			$this->assertMatchesRegularExpression( $pattern, $example, "the pattern for {$name} no longer catches its example" );
		}

		$found = array();
		foreach ( $this->runtimeFiles() as $file ) {
			$text = (string) file_get_contents( $file );
			foreach ( self::SECRET_PATTERNS as $name => [ $pattern ] ) {
				if ( preg_match( $pattern, $text, $hit ) ) {
					$found[] = str_replace( OMNABASE_REPO . '/', '', $file ) . ": {$name} ({$hit[0]})";
				}
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * What it checks: the local test plumbing (the test mode, the test-only commands) lives under tests/ and never in the plugin's
	 *   runtime code.
	 * Passes when: no runtime file mentions OMNABASE_TEST_MODE or the `omnabase-test` commands.
	 * Fails when: a test door is built into the plugin itself, where a customer's site could open it.
	 */
	public function test_the_test_plumbing_stays_out_of_the_runtime_code(): void {
		$found = array();
		foreach ( $this->runtimeFiles() as $file ) {
			$text = (string) file_get_contents( $file );
			if ( str_contains( $text, 'OMNABASE_TEST_MODE' ) || str_contains( $text, 'omnabase-test' ) ) {
				$found[] = str_replace( OMNABASE_REPO . '/', '', $file );
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * What it checks: the customer changelog's top section is the current version, marked unreleased until the release day.
	 * Passes when: changelog.txt starts with a "= <version> — unreleased =" or a dated "= <version> — <date> =" heading.
	 * Fails when: the heading is missing or malformed, so release notes would describe the wrong version.
	 */
	public function test_the_changelog_opens_with_the_current_version(): void {
		$this->assertMatchesRegularExpression( '/^= \d+\.\d+\.\d+ — (unreleased|\d{4}-\d{2}-\d{2}) =$/m', $this->read( 'changelog.txt' ) );
	}

	/**
	 * What it checks: readme.txt tells a site owner, before installing, that the plugin talks to OmnaBase and who runs it
	 *   (WordPress.org requires every external service to be disclosed).
	 * Passes when: readme.txt has an "External services" section naming app.omnabase.com and Kriesi Media GmbH.
	 * Fails when: the disclosure is missing, so WordPress.org would refuse the plugin and a site owner would not know where their
	 *   data goes.
	 */
	public function test_the_readme_discloses_omnabase_as_an_external_service(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertMatchesRegularExpression( '/^== External services ==$/m', $readme );
		$section = (string) preg_replace( '/^.*?^== External services ==$(.*?)(?:^== |\z).*$/ms', '$1', $readme );
		$this->assertStringContainsString( 'app.omnabase.com', $section );
		$this->assertStringContainsString( 'Kriesi Media GmbH', $section );
	}

	/** The version of changelog.txt's first section heading. */
	private function topChangelogVersion(): ?string {
		return preg_match( '/^= (\d+\.\d+\.\d+) —/m', $this->read( 'changelog.txt' ), $m ) ? $m[1] : null;
	}

	/**
	 * The plugin's runtime PHP: what the zip ships.
	 *
	 * @return string[]
	 */
	private function runtimeFiles(): array {
		$files = array( OMNABASE_REPO . '/omnabase.php', OMNABASE_REPO . '/uninstall.php' );
		foreach ( array( 'src', 'templates' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( $f->isFile() && 'php' === $f->getExtension() ) {
					$files[] = $f->getPathname();
				}
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * A repository file's text.
	 *
	 * @param string $path Relative to the repository's root.
	 */
	private function read( string $path ): string {
		return (string) file_get_contents( OMNABASE_REPO . '/' . $path );
	}
}
