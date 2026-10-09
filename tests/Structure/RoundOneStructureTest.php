<?php
/**
 * M13 round one, the red team's failing tests: the structure guard extended (file-level checks, no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): choice 1 "**Structure guards, lightly**: the credentials read only by
 * the vault; every `register_rest_route` with a real `permission_callback` (named exceptions: the two signed receivers …)"; choice 10
 * "Outgoing calls go to one pinned host … `OMNABASE_API_URL`/`OMNABASE_APP_URL` constants for development"; "Round one": the classes
 * `Connect/Pkce`, `Connect/Flow`, `Security/Vault`, `Security/Signature`, `Api/Client`, `Rest/Events`, `Rest/Customer`,
 * `Admin/SettingsScreen`, `Admin/Health`, `Admin/Notices`; the plugin's `CLAUDE.md`: "From round one, Connect needs OmnaBase's web app too
 * (the connect page on Vite, port 4391): `bin/omnabase-e2e.sh` grows to start it"; DECISIONS "M13 round zero": "round one replaces it
 * [the plain option] with the vault, and the guard 'credentials read only by the vault' starts then". The coordinator: "the structure guard
 * extended (the options list, the uninstall list, the vault class, no `wp_remote_*` to any host but OmnaBase's)"; "`bin/omnabase-e2e.sh`
 * must start it — pin the script does"; "the plugin's changelog line".
 *
 * Shapes the red team pins (chosen where marked):
 * - The round's files under `src/` by the brief's names, plus `tests/e2e/connect.spec.ts`; round zero's `Api/StoredKey` is gone.
 * - (chosen) The vault's credentials option is named only inside `src/Security/Vault.php` (a constant on `Options` is fine: its name may
 *   appear in `Options.php` and `uninstall.php`, nowhere else); no template calls the vault.
 * - (chosen) Outgoing HTTP (`wp_remote_*`, `wp_safe_remote_*`, `WP_Http`, `curl_`) only in files under `src/Api/`, and no `http(s)://`
 *   address written into `src/` except in `src/Api/ApiUrl.php` (the pinned host and its development override). The health loopback (the
 *   brief's own-`/events` self-test) is allowed under `src/Api/` too, its address from `rest_url()`.
 * - `.wp-env.json` defines `OMNABASE_APP_URL` as `http://localhost:4391` (where the connect page runs in the flows).
 *
 * Why these fail today: none of the round's files exist, StoredKey is still there, the script starts only the API, the changelog has no
 * Connect line, `.wp-env.json` has no app address.
 *
 * Existing tests this round must change (the builder's): `tests/Unit/StoredKeyTest.php` goes with StoredKey; `tests/e2e/round-trip.spec.ts`
 * and the harness's `set-key`/`forget-key` move to the vault (or to a real connect).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use OmnaBase\WordPress\Options;
use PHPUnit\Framework\TestCase;

final class RoundOneStructureTest extends TestCase {

	private const ROUND_ONE_FILES = array(
		'src/Connect/Pkce.php',
		'src/Connect/Flow.php',
		'src/Security/Vault.php',
		'src/Security/Signature.php',
		'src/Api/Client.php',
		'src/Rest/Events.php',
		'src/Rest/Customer.php',
		'src/Admin/SettingsScreen.php',
		'src/Admin/Health.php',
		'src/Admin/Notices.php',
		'tests/e2e/connect.spec.ts',
	);

	/**
	 * What it checks: Round one's parts exist under the names the brief gave them, and round zero's plain-text key store is gone.
	 * Passes when: every listed file exists and src/Api/StoredKey.php does not.
	 * Fails when: a part is missing or the old store lingers beside the vault, so the key could still be written in clear text.
	 */
	public function test_the_round_one_files_exist_and_the_plain_key_store_is_gone(): void {
		$missing = array_values( array_filter( self::ROUND_ONE_FILES, static fn ( string $f ) => ! file_exists( OMNABASE_REPO . '/' . $f ) ) );
		$this->assertSame( array(), $missing, 'missing: ' . implode( ', ', $missing ) );
		$this->assertFileDoesNotExist( OMNABASE_REPO . '/src/Api/StoredKey.php', 'round zero\'s plain option is replaced by the vault' );
		$this->assertSame( array(), $this->filesMentioning( '/\bStoredKey\b/', array( 'src', 'templates', 'tests/wp-env' ) ), 'nothing uses StoredKey any more' );
	}

	/**
	 * What it checks: The key and the secrets are read and written in exactly one place, the vault.
	 * Passes when: the vault uses libsodium's secretbox; the name of the option the vault stores in appears in no other runtime file than
	 *   the vault, the option list and uninstall.php; no template calls the vault.
	 * Fails when: another class reads the stored credentials directly (and could print or log them), or the vault does not really
	 *   encrypt.
	 */
	public function test_the_credentials_are_read_only_by_the_vault(): void {
		$vault = $this->read( 'src/Security/Vault.php' );
		$this->assertMatchesRegularExpression( '/sodium_crypto_secretbox\s*\(/', $vault, 'secretbox (libsodium or sodium_compat)' );
		$this->assertMatchesRegularExpression( '/sodium_crypto_secretbox_open\s*\(/', $vault );
		$this->assertStringContainsString( 'OMNABASE_ENCRYPTION_KEY', $vault );

		$names = $this->vaultOptionNames( $vault );
		$this->assertNotSame( array(), $names, 'the vault names the option it stores in (a literal or an Options:: constant)' );
		$elsewhere = array();
		foreach ( $this->runtimeFiles() as $file ) {
			$rel = $this->rel( $file );
			if ( in_array( $rel, array( 'src/Security/Vault.php', 'src/Options.php', 'uninstall.php' ), true ) ) {
				continue;
			}
			$text = (string) file_get_contents( $file );
			foreach ( $names as $name ) {
				if ( str_contains( $text, $name ) ) {
					$elsewhere[] = "{$rel}: {$name}";
				}
			}
		}
		$this->assertSame( array(), $elsewhere, 'the vault\'s option is touched outside the vault' );
		$this->assertSame( array(), $this->filesMentioning( '/\bVault\b|->read\(\s*\)/', array( 'templates' ) ), 'templates never call the vault' );
	}

	/**
	 * What it checks: The plugin calls out to OmnaBase only, from one place.
	 * Passes when: every outgoing HTTP call in src/ sits in a file under src/Api/, and the only web address written into src/ is in
	 *   src/Api/ApiUrl.php (the pinned https://app.omnabase.com and the development override).
	 * Fails when: some class sends data to another host (a customer's data could leave for a third party), or the pinned host is copied
	 *   around and drifts.
	 */
	public function test_outgoing_calls_go_only_through_the_api_folder_to_the_pinned_host(): void {
		$outside = array();
		$urls    = array();
		foreach ( $this->runtimeFiles( array( 'src' ) ) as $file ) {
			$rel  = $this->rel( $file );
			$text = $this->withoutComments( (string) file_get_contents( $file ) );
			if ( preg_match( '/\b(wp_remote_\w+|wp_safe_remote_\w+|WP_Http\b|curl_\w+|file_get_contents\s*\(\s*[\'"]https?:)/', $text, $m ) && ! str_starts_with( $rel, 'src/Api/' ) ) {
				$outside[] = "{$rel}: {$m[1]}";
			}
			if ( 'src/Api/ApiUrl.php' !== $rel && preg_match( '#[\'"]https?://#', $text ) ) {
				$urls[] = $rel;
			}
		}
		$this->assertSame( array(), $outside, 'outgoing calls outside src/Api/' );
		$this->assertSame( array(), $urls, 'web addresses written outside src/Api/ApiUrl.php' );
		$this->assertMatchesRegularExpression( '/OMNABASE_APP_URL/', $this->read( 'src/Api/ApiUrl.php' ), 'the connect page\'s address has its development override too (choice 10)' );
	}

	/**
	 * What it checks: Every REST address the plugin opens says who may call it; the two receivers check OmnaBase's signature there.
	 * Passes when: every register_rest_route in src/ has a permission_callback that is not __return_true, and the events and customer
	 *   routes are registered under omnabase/v1.
	 * Fails when: an address answers anyone (the customer receiver would hand out customer data to whoever asks).
	 */
	public function test_every_rest_route_has_a_real_permission_check(): void {
		$routes = 0;
		$bad    = array();
		foreach ( $this->runtimeFiles( array( 'src' ) ) as $file ) {
			$text = $this->withoutComments( (string) file_get_contents( $file ) );
			foreach ( $this->calls( $text, 'register_rest_route' ) as $call ) {
				++$routes;
				if ( ! str_contains( $call, 'permission_callback' ) || preg_match( '/permission_callback[\'"]?\s*=>\s*[\'"]__return_true[\'"]/', $call ) ) {
					$bad[] = $this->rel( $file );
				}
			}
		}
		$this->assertGreaterThanOrEqual( 2, $routes, 'the two receivers are registered' );
		$this->assertSame( array(), $bad );
		$src = implode( "\n", array_map( static fn ( $f ) => (string) file_get_contents( $f ), $this->runtimeFiles( array( 'src' ) ) ) );
		$this->assertMatchesRegularExpression( '#[\'"]omnabase/v1[\'"]#', $src );
		$this->assertMatchesRegularExpression( '#[\'"]/events[\'"]#', $src );
		$this->assertMatchesRegularExpression( '#[\'"]/customer[\'"]#', $src );
	}

	/**
	 * What it checks: Deleting the plugin removes what round one stores: the options (the list), each admin's pending connect (user meta)
	 *   and temporary values.
	 * Passes when: the option list holds more than round zero's one option, every option on it is removed by uninstall.php (the older
	 *   guard), and uninstall.php also removes the plugin's user meta and transients.
	 * Fails when: a pending connect's secret or the delivery log stays on the customer's site after they deleted the plugin.
	 */
	public function test_uninstall_also_removes_user_meta_and_transients(): void {
		$this->assertGreaterThan( 1, count( Options::ALL ), 'round one adds options (the vault, the connection\'s names and scopes, the deliveries)' );
		$uninstall = $this->read( 'uninstall.php' );
		$this->assertMatchesRegularExpression( '/delete_metadata\s*\(\s*[\'"]user[\'"]|delete_user_meta\s*\(|usermeta/', $uninstall, 'the pending connects kept per admin' );
		$this->assertMatchesRegularExpression( '/delete_transient\s*\(|_transient_/', $uninstall, 'temporary values' );
	}

	/**
	 * What it checks: The browser flows can reach OmnaBase's connect page: the local WordPress points at it and the start script runs it.
	 * Passes when: .wp-env.json defines OMNABASE_APP_URL as http://localhost:4391, and bin/omnabase-e2e.sh starts OmnaBase's web app (Vite)
	 *   on 4391 beside the API.
	 * Fails when: the Connect button in the flows leads to the real app.omnabase.com or to nothing, so round one's flow cannot run.
	 */
	public function test_the_flows_reach_omnabases_connect_page(): void {
		$env = json_decode( $this->read( '.wp-env.json' ), true );
		$this->assertSame( 'http://localhost:4391', $env['config']['OMNABASE_APP_URL'] ?? null );
		$script = $this->read( 'bin/omnabase-e2e.sh' );
		$this->assertMatchesRegularExpression( '/vite/', $script, 'the web app (Vite) is started' );
		$this->assertMatchesRegularExpression( '/4391/', $script );
	}

	/**
	 * What it checks: The customer changelog and the decisions record round one.
	 * Passes when: changelog.txt's unreleased section has a line about connecting the site from Settings › OmnaBase, and DECISIONS.md has a
	 *   dated "M13 round one" heading.
	 * Fails when: the release notes do not tell site owners that Connect exists, or round one's choices are not written down.
	 */
	public function test_the_changelog_and_decisions_record_round_one(): void {
		$changelog = $this->read( 'changelog.txt' );
		$top       = (string) preg_replace( '/^.*?^= \d+\.\d+\.\d+ — unreleased =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$this->assertMatchesRegularExpression( '/^\* .*\bConnect/m', $top, 'a changelog line about connecting the site' );
		$this->assertMatchesRegularExpression( '/^## \d{4}-\d{2}-\d{2} — M13 round one/m', $this->read( 'DECISIONS.md' ) );
	}

	/**
	 * The option names the vault stores under: string literals passed to the option functions, or Options:: constants resolved.
	 *
	 * @param string $vault The vault's source.
	 * @return string[]
	 */
	private function vaultOptionNames( string $vault ): array {
		$names = array();
		preg_match_all( "/\\b(?:get|update|add|delete)_option\\(\\s*'([^']+)'/", $vault, $lit );
		$names = array_merge( $names, $lit[1] );
		preg_match_all( '/\bOptions::([A-Z_]+)/', $vault, $const );
		foreach ( array_unique( $const[1] ) as $c ) {
			if ( defined( Options::class . '::' . $c ) ) {
				$names[] = (string) constant( Options::class . '::' . $c );
			}
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * The argument text of each call to a function (balanced parentheses).
	 *
	 * @param string $text The source.
	 * @param string $fn   The function name.
	 * @return string[]
	 */
	private function calls( string $text, string $fn ): array {
		$out    = array();
		$offset = 0;
		while ( preg_match( '/\b' . preg_quote( $fn, '/' ) . '\s*\(/', $text, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$start = strpos( $text, '(', (int) $m[0][1] );
			$depth = 0;
			for ( $i = $start, $n = strlen( $text ); $i < $n; $i++ ) {
				$depth += '(' === $text[ $i ] ? 1 : ( ')' === $text[ $i ] ? -1 : 0 );
				if ( 0 === $depth ) {
					break;
				}
			}
			$out[]  = substr( $text, $start, $i - $start + 1 );
			$offset = $i + 1;
		}
		return $out;
	}

	/** The source without comments. */
	private function withoutComments( string $text ): string {
		$out = '';
		foreach ( token_get_all( $text ) as $t ) {
			$out .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $out;
	}

	/**
	 * Files under the given folders whose text matches.
	 *
	 * @param string   $pattern A regular expression.
	 * @param string[] $dirs    Folders relative to the repository.
	 * @return string[]
	 */
	private function filesMentioning( string $pattern, array $dirs ): array {
		$hits = array();
		foreach ( $this->runtimeFiles( $dirs, false ) as $file ) {
			if ( preg_match( $pattern, $this->withoutComments( (string) file_get_contents( $file ) ) ) ) {
				$hits[] = $this->rel( $file );
			}
		}
		return $hits;
	}

	/**
	 * PHP files under folders (plus the two root files when asked).
	 *
	 * @param string[] $dirs      Folders relative to the repository.
	 * @param bool     $with_root Whether omnabase.php and uninstall.php are included.
	 * @return string[]
	 */
	private function runtimeFiles( array $dirs = array( 'src', 'templates' ), bool $with_root = true ): array {
		$files = $with_root ? array( OMNABASE_REPO . '/omnabase.php', OMNABASE_REPO . '/uninstall.php' ) : array();
		foreach ( $dirs as $dir ) {
			if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
				continue;
			}
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

	/** A path relative to the repository. */
	private function rel( string $file ): string {
		return str_replace( OMNABASE_REPO . '/', '', $file );
	}

	/** A repository file's text. */
	private function read( string $path ): string {
		return is_file( OMNABASE_REPO . '/' . $path ) ? (string) file_get_contents( OMNABASE_REPO . '/' . $path ) : '';
	}
}
