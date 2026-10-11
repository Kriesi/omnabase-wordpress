<?php
/**
 * Fixing round "the vault's key from a file" (2026-10-11), the red team's failing tests: the key file in a real WordPress (wp-env's tests
 * WordPress, the plain run): a real Connect makes it with its guards, the web server gives nothing away when its address is opened,
 * deleting the plugin removes it, Disconnect keeps it, its content never reaches a page or the database, and a lost key or changed salts
 * read "reconnect needed" with the reason in plain words.
 *
 * The founder (2026-10-11): "go with the file-based key, remove the block." The coordinator's design: a random 32-byte key the plugin makes
 * once into a guarded file under wp-content (a PHP file that ends the request before the key, an `index.php` and a `.htaccess` deny beside
 * it, mode 0600); when no file can be written, the salts as today; the source recorded beside the scrambled values; "an unreadable vault
 * (file lost, salts rotated) is the existing 'reconnect needed' state, and the error line says in plain words what happened ('the file this
 * site locks its connection with is gone' / 'the site's secret keys changed')"; "`uninstall.php` removes the key file and its directory (one
 * network); Disconnect keeps the file (a reconnect reuses it)".
 *
 * The words: *vault*, the plugin's one place for the site's OmnaBase key and its two secrets, kept scrambled; *key file*, the file holding
 * the key that unscrambles them; *salts*, the secret phrases in wp-config.php (WordPress's own settings file); *guard*, what keeps a browser
 * from reading the file (the PHP line that ends the request, the folder's `index.php` and `.htaccess`); *uninstall*, what runs when an
 * administrator deletes the plugin; *notice*, the coloured message box WordPress shows at the top of admin pages; *option*, a stored setting
 * in the database.
 *
 * Shapes pinned (the builder may rename and tell the red team): the key folder and the key file as in `RoundOneTestCase::keyFolders()`
 * (`omnabase` under wp-content or under the uploads folder; a `.php` file other than `index.php`); the vault's stored option records
 * `source` and `kid` (or `key_id`); the connection card keeps `data-state="reconnect"` and the notice its "OmnaBase: reconnect needed";
 * the reasons' words: the lost file's line says "file" and "gone", the salts' line "secret keys" and "changed" (the coordinator's words);
 * the tests environment's web server answers inside wp-env at `http://tests-wordpress` (or `OMNABASE_TEST_WEB`).
 *
 * Every test starts with the shared key folder moved aside, so what it finds was made by this test; `tear_down()` puts the shared one back.
 *
 * Why these fail today: no key file is ever made, the vault records no source, the reconnect line always blames the secret keys, and
 * uninstall knows no key file.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Security\Vault;

final class VaultFileTest extends RoundOneTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->hideKeyFolders();
	}

	/**
	 * What it checks: A real Connect makes the key file, private and guarded, in the plugin's folder under wp-content.
	 * Passes when: after Connect, exactly one key file exists in a folder named "omnabase" (under wp-content or its uploads folder), beside an
	 *   index.php and a .htaccess refusing every request; only its owner may read it; it starts with "<?php" and holds the key as text; the
	 *   vault records the source "file" and a key id of at most 16 characters.
	 * Fails when: Connect still locks the connection with the salts (a "log everyone out" disconnects the site), or the key file is readable by
	 *   every account on a shared server, or a browser could list the folder.
	 */
	public function test_a_real_connect_makes_the_guarded_key_file(): void {
		$this->connectFully();

		$files = self::keyFiles();
		$this->assertCount( 1, $files, 'one key file' );
		$folder = dirname( $files[0] );
		$this->assertFileExists( $folder . '/index.php', 'an index.php beside it' );
		$this->assertFileExists( $folder . '/.htaccess', 'a .htaccess beside it' );
		$this->assertMatchesRegularExpression( '/Require\s+all\s+denied|Deny\s+from\s+all/i', (string) file_get_contents( $folder . '/.htaccess' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( 0, fileperms( $files[0] ) & 0077, sprintf( 'only the owner may read it (mode %o)', fileperms( $files[0] ) & 0777 ) );
		$content = (string) file_get_contents( $files[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringStartsWith( '<?php', $content );
		$this->assertNotSame( array(), self::keyMaterial( $content ), 'the key as base64 or hex text' );
		$record = self::vaultRecord();
		$this->assertSame( 'file', $record['source'] ?? null, 'the vault records the file as its source' );
		$kid = $record['kid'] ?? $record['key_id'] ?? null;
		$this->assertIsString( $kid, 'and a key id' );
		$this->assertLessThanOrEqual( 16, strlen( $kid ) );
	}

	/**
	 * What it checks: Opening the key file's address (or its folder's) in a browser gives nothing away.
	 * Passes when: the tests WordPress's own web server answers a request for the key file either 200 with an empty page or 403 (refused), and
	 *   the answer holds none of the key's written forms; a request for the folder answers 200 empty or 403 and lists no file.
	 * Fails when: anyone who guesses the address downloads the key that opens the stored credentials, or browses the folder to find it.
	 */
	public function test_a_web_request_for_the_key_file_answers_without_the_key(): void {
		$this->connectFully();
		$file   = self::keyFiles()[0] ?? '';
		$this->assertNotSame( '', $file, 'a key file to ask for' );
		$needles = self::keyMaterial( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		foreach ( array( 'the key file' => $file, 'its folder' => dirname( $file ) . '/' ) as $what => $path ) {
			[ $status, $body ] = self::fetch( self::webAddress( $path ) );
			$this->assertNotSame( 0, $status, "the tests WordPress's web server answers at " . self::webAddress( $path ) );
			$this->assertTrue( 403 === $status || ( 200 === $status && '' === trim( $body ) ), "{$what}: 200 with an empty page, or 403 (got {$status}, " . strlen( $body ) . ' bytes)' );
			foreach ( $needles as $needle ) {
				$this->assertStringNotContainsString( $needle, $body, "{$what}: the key in the answer" );
			}
			$this->assertStringNotContainsString( basename( $file ), $body, "{$what}: the key file's name in the answer" );
		}
	}

	/**
	 * What it checks: Deleting the plugin removes the key file and its folder.
	 * Passes when: after Connect, running the plugin's uninstall the way WordPress does (OmnaBase answering the key's stop) leaves no key file
	 *   and no key folder.
	 * Fails when: a deleted plugin leaves its key behind in wp-content, against the readme's promise that it removes everything it stored.
	 */
	public function test_uninstall_removes_the_key_file_and_its_folder(): void {
		$this->connectFully();
		$this->assertCount( 1, self::keyFiles() );
		$this->answers['DELETE /api/public/v1/me'] = self::answer( 204, '' );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'omnabase/omnabase.php' );
		}
		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertSame( array(), self::keyFiles(), 'no key file left' );
		foreach ( self::keyFolders() as $folder ) {
			$this->assertDirectoryDoesNotExist( $folder, 'no key folder left' );
		}
	}

	/**
	 * What it checks: Disconnect keeps the key file, and connecting again reuses it.
	 * Passes when: after Connect and Disconnect, the vault is empty but the key file is still there with the same content; after a second
	 *   Connect the vault records the same key id and the file is unchanged.
	 * Fails when: Disconnect deletes the key (harmless today, but a second site of a network sharing the file would lose its connection), or
	 *   each Connect writes a new key.
	 */
	public function test_disconnect_keeps_the_key_file_and_a_reconnect_reuses_it(): void {
		$this->connectFully();
		$file    = self::keyFiles()[0] ?? '';
		$this->assertNotSame( '', $file );
		$content = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$kid     = self::vaultRecord()['kid'] ?? self::vaultRecord()['key_id'] ?? null;

		$this->answers['DELETE /api/public/v1/me'] = self::answer( 204, '' );
		$this->adminPost( 'omnabase_disconnect' );
		$this->assertSame( 'empty', ( new Vault() )->state(), 'disconnected' );
		$this->assertSame( array( $file ), self::keyFiles(), 'the key file stays' );
		$this->assertSame( $content, (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->connectFully();
		$this->assertSame( 'file', self::vaultRecord()['source'] ?? null );
		$this->assertSame( $kid, self::vaultRecord()['kid'] ?? self::vaultRecord()['key_id'] ?? null, 'the same key id' );
		$this->assertSame( $content, (string) file_get_contents( $file ), 'the file unchanged' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * What it checks: The key in the file never reaches a page or the database.
	 * Passes when: after Connect and a "Check connection", none of the key's written forms (the text in the file, the raw bytes, base64, hex)
	 *   appears on the settings screen, on any of its five tabs, in the admin notices, in any option or in any user's stored notes.
	 * Fails when: the key is printed into a page (a page cache or a browser extension could copy it) or saved in the database next to the
	 *   scrambled credentials it opens.
	 */
	public function test_the_key_files_content_never_reaches_a_page_or_the_database(): void {
		global $wpdb;
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array(
			'GET /api/public/v1/me'       => self::answer( 200, self::meAnswer() ),
			'POST /api/public/v1/me/ping' => self::answer( 202, array( 'ok' => true ) ),
		);
		$location = $this->adminPost( 'omnabase_check' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->asAdminOnTheScreen( $this->admin );
		$seen = array( 'the screen' => $this->renderScreen() );
		foreach ( array_keys( self::TABS ) as $tab ) {
			$seen[ "the {$tab} tab" ] = $this->openTab( $tab );
		}
		$seen['the notices']    = $this->noticesOnTheDashboardFor( $this->admin );
		$seen['the options']    = implode( "\n", $wpdb->get_col( "SELECT option_value FROM {$wpdb->options}" ) ); // phpcs:ignore WordPress.DB
		$seen['the user notes'] = implode( "\n", $wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta}" ) ); // phpcs:ignore WordPress.DB

		$this->assertCount( 1, self::keyFiles(), 'one key file' );
		$needles = self::keyMaterial( (string) file_get_contents( self::keyFiles()[0] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertNotSame( array(), $needles, 'the key file holds a key' );
		foreach ( $seen as $where => $text ) {
			foreach ( $needles as $needle ) {
				$this->assertStringNotContainsString( $needle, $text, "the key in {$where}" );
			}
		}
	}

	/**
	 * What it checks: When the key file is lost, the Connection tab and every admin page say "reconnect needed" and say in plain words that the
	 *   file is gone.
	 * Passes when: after Connect, with the key folder gone, the connection card is in the "reconnect" state, its words name the file and say
	 *   it is gone, do not blame changed secret keys, and each line is at most 30 words; the tab still ends with the Status card; the
	 *   administrators' notice says "OmnaBase: reconnect needed", names the file and does not blame the secret keys; neither names
	 *   wp-config.php, the constant, encryption or an Advanced block.
	 * Fails when: the owner is sent hunting for changed secret keys when a backup restore or a cleanup plugin deleted the file, or the line
	 *   turns into developer advice.
	 */
	public function test_a_lost_key_file_reads_reconnect_needed_in_plain_words(): void {
		$this->connectFully();
		$this->hideKeyFolders();
		$this->asAdminOnTheScreen( $this->admin );

		$html = $this->openTab( 'connection' );
		$card = $this->reconnectCard( $html );
		$this->assertMatchesRegularExpression( '/\bfile\b/i', $card, 'the line names the file' );
		$this->assertMatchesRegularExpression( '/\bgone\b/i', $card, 'and says it is gone' );
		$this->assertDoesNotMatchRegularExpression( '/secret keys changed/i', $card, 'not the salts\' reason' );
		$cards = self::query( $html, '//*[' . self::hasClass( 'ob-form__section' ) . ']' );
		$this->assertStringContainsString( 'ob-wp-settings__health', (string) $cards->item( $cards->length - 1 )?->getAttribute( 'class' ), 'the tab ends with the Status card' );

		$notice = wp_strip_all_tags( $this->noticesOnTheDashboardFor( $this->admin ) );
		$this->assertStringContainsString( 'OmnaBase: reconnect needed', $notice );
		$this->assertMatchesRegularExpression( '/\bfile\b/i', $notice, 'the notice names the file' );
		$this->assertDoesNotMatchRegularExpression( '/secret keys changed/i', $notice, 'the notice does not blame the secret keys' );
		$this->assertNoDeveloperAdvice( $card . ' ' . $notice );
	}

	/**
	 * What it checks: When the salts locked the vault (no file could be written) and they change, the screen says the site's secret keys
	 *   changed, not that a file is gone.
	 * Passes when: with no key folder possible, Connect records the source "salts" and makes no key file; after the salts change, the
	 *   connection card is in the "reconnect" state and says the secret keys changed (each line at most 30 words); the notice says
	 *   "OmnaBase: reconnect needed" and the same; neither speaks of a file being gone, nor names wp-config.php, the constant, encryption or
	 *   an Advanced block.
	 * Fails when: a host that never allowed the file is told its key file is gone, or the salts case loses its plain explanation.
	 */
	public function test_changed_salts_read_reconnect_needed_in_plain_words(): void {
		$this->blockKeyFolders();
		$this->connectFully();
		$this->assertSame( 'salts', self::vaultRecord()['source'] ?? null, 'no file could be written: the salts lock the vault' );
		$this->assertSame( array(), self::keyFiles() );

		$this->rotateSalts();
		$this->asAdminOnTheScreen( $this->admin );
		$card = $this->reconnectCard( $this->openTab( 'connection' ) );
		$this->assertMatchesRegularExpression( '/secret keys/i', $card );
		$this->assertMatchesRegularExpression( '/\bchanged\b/i', $card );
		$this->assertDoesNotMatchRegularExpression( '/\bfile\b[^.]*\bgone\b/i', $card, 'not the lost file\'s reason' );

		$notice = wp_strip_all_tags( $this->noticesOnTheDashboardFor( $this->admin ) );
		$this->assertStringContainsString( 'OmnaBase: reconnect needed', $notice );
		$this->assertMatchesRegularExpression( '/secret keys/i', $notice );
		$this->assertDoesNotMatchRegularExpression( '/\bfile\b[^.]*\bgone\b/i', $notice );
		$this->assertNoDeveloperAdvice( $card . ' ' . $notice );
	}

	/* ---------- helpers ---------- */

	/**
	 * The connection card's words in the "reconnect" state (asserts the state and the 30-word rule for each of its lines).
	 *
	 * @param string $html The Connection tab.
	 */
	private function reconnectCard( string $html ): string {
		$cards = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__connection' ) . ']' );
		$this->assertSame( 1, $cards->length, 'the connection card' );
		$card = $cards->item( 0 );
		$this->assertSame( 'reconnect', $card->getAttribute( 'data-state' ), 'the "reconnect needed" state' );
		foreach ( ( new \DOMXPath( $card->ownerDocument ) )->query( './/p', $card ) as $line ) {
			$words = self::wordsOf( $line );
			$this->assertLessThanOrEqual( 30, str_word_count( $words ), "at most 30 words: {$words}" );
		}
		return self::wordsOf( $card );
	}

	/**
	 * No developer advice in some words.
	 *
	 * @param string $words What the owner reads.
	 */
	private function assertNoDeveloperAdvice( string $words ): void {
		$this->assertDoesNotMatchRegularExpression( '/wp-config|OMNABASE_ENCRYPTION_KEY|\bencrypt\w*|\bsalts?\b/i', $words );
		$this->assertDoesNotMatchRegularExpression( '/\bAdvanced\b/', $words );
	}

	/**
	 * A file's address on the tests WordPress's own web server (inside wp-env: `http://tests-wordpress`, or `OMNABASE_TEST_WEB`).
	 *
	 * @param string $path A path under WordPress's folder.
	 */
	private static function webAddress( string $path ): string {
		$base = rtrim( (string) ( getenv( 'OMNABASE_TEST_WEB' ) ?: 'http://tests-wordpress' ), '/' );
		return $base . '/' . ltrim( substr( $path, strlen( ABSPATH ) ), '/' );
	}

	/**
	 * A plain GET to the web server, past WordPress's HTTP layer (which the tests fake): the status (0 when nothing answered) and the body.
	 *
	 * @param string $url The address.
	 * @return array{0:int,1:string}
	 */
	private static function fetch( string $url ): array {
		$curl = curl_init( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		curl_setopt_array( $curl, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$body   = curl_exec( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$status = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		curl_close( $curl ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( $status, is_string( $body ) ? $body : '' );
	}
}
