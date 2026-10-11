<?php
/**
 * Fixing round "the vault's key from a file" (2026-10-11), the red team's failing tests: where the vault (the one place the site's OmnaBase
 * key and its two secrets are kept, scrambled with a key kept elsewhere) gets that scrambling key, and how it remembers where it got it.
 *
 * The founder (2026-10-11): "go with the file-based key, remove the block." The coordinator's design: the key source is chosen once, when the
 * vault saves, and recorded beside the scrambled values, in this order: (a) `OMNABASE_ENCRYPTION_KEY` when wp-config.php (WordPress's own
 * settings file) defines it; (b) a random 32-byte key the plugin makes once into a guarded file under wp-content (a PHP file that ends the
 * request before the key, an `index.php` and a `.htaccess` deny beside it, mode 0600, made in one step, never overwritten, its mode checked
 * on reading with a warning logged, never shown, when anyone may read it); (c) when no file can be written, the site's salts (the secret
 * phrases in wp-config.php) as today; (d) when those live in the database too, the salts anyway, silently. On a network (multisite) one file
 * for the network, one vault per site. The vault records `source: constant|file|salts` and a key id (a short fingerprint of the key, never
 * the key); reading uses the recorded source; an unreadable vault is "reconnect needed", with the reason in plain words.
 *
 * The words: *salts*, the secret phrases in wp-config.php WordPress mixes into its cookies (a security plugin's "log everyone out" changes
 * them); *option*, a stored setting in WordPress's database; *mode 0600*, file permissions under which only the file's owner may read or
 * write it; *world-readable*, a file anyone on the server may read; *network*, several sites sharing one WordPress (multisite); *key id*,
 * a short fingerprint that tells two keys apart without revealing either.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - `new Vault()` with no arguments keeps working (uninstall.php, the test harness and every test build it so).
 * - The vault's stored option (`Options::VAULT`) is an array holding `source` (`constant`, `file` or `salts`) and the key id under `kid`
 *   (or `key_id`): a short text of at most 16 characters.
 * - `Vault::unreadableReason(): ?string`: null while the vault reads or is empty; `file_gone` when it was locked with the key file and that
 *   file is missing; `keys_changed` when it was locked with the salts and they changed.
 * - The key folder is named `omnabase`, under `WP_CONTENT_DIR` or under the uploads folder (the main site's on a network); the key file is
 *   a `.php` file in it other than `index.php`; beside it `index.php` and a `.htaccess` refusing every request ("Require all denied" or
 *   "Deny from all").
 * - The key file holds the key as text (base64 or hex) behind a PHP line that ends the request, so running the file prints nothing.
 * - A warning about a world-readable key file goes to PHP's error log (`error_log()`), `trigger_error()` or `wp_trigger_error()`.
 * - Each test runs in its own PHP process with its own temporary wp-content (`WP_CONTENT_DIR` is a constant and cannot be changed back).
 *   The WordPress functions faked below are the ones a file-based key plausibly needs; a builder whose code calls another one may add a fake
 *   for it in `setUp()` without changing any assertion.
 *
 * Why these fail today: the vault knows no key file, records no source and no key id, and has no reason for being unreadable.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Security\Vault;

/**
 * Each test in its own PHP process (its own `WP_CONTENT_DIR`).
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class VaultKeyTest extends TestCase {

	private const CREDENTIALS = array(
		'key'      => 'obw_k3j9x2qak3j9x2qak3j9x2qak3j9x2qak3j9x2qak3j_ab7Q2X',
		'webhooks' => 'whsec_vaultKeyTestWebhookSecret000001',
		'facts'    => 'dmF1bHQta2V5LXRlc3QtZmFjdHMtc2VjcmV0LTAwMQ',
	);

	private const OTHER = array(
		'key'      => 'obw_zz88yy77xx66ww55vv44uu33tt22ss11rr00qq9_cd4E5F',
		'webhooks' => 'whsec_vaultKeyTestWebhookSecret000002',
		'facts'    => 'dmF1bHQta2V5LXRlc3QtZmFjdHMtc2VjcmV0LTAwMg',
	);

	/** this test's temporary WordPress folder (wp-content lives inside) */
	private string $root = '';

	/** @var array<int,array<string,mixed>> the fake options table, per site of the network */
	private array $options = array();

	/** @var array<string,mixed> the fake network-wide options */
	private array $network = array();

	/** the site WordPress is on (1 = the main site) */
	private int $blog = 1;

	/** @var int[] the sites `switch_to_blog()` left */
	private array $switched = array();

	/** whether WordPress is a network */
	private bool $multisite = false;

	/** the fake `wp_salt()`'s current answer */
	private string $salt = 'salt-generation-one';

	/** @var string[] warnings raised through `trigger_error()` or `wp_trigger_error()` */
	private array $raised = array();

	/** @var callable|null PHPUnit's own error handler, handed everything but the warnings collected here */
	private $previousHandler = null;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/omnabase-vault-key-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/wp-content/uploads', 0755, true );
		define( 'WP_CONTENT_DIR', $this->root . '/wp-content' );
		define( 'WP_DEBUG', true );
		ini_set( 'error_log', $this->root . '/php-error.log' ); // phpcs:ignore WordPress.PHP.IniSet
		$this->previousHandler = set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			function ( $level, $message, $file = '', $line = 0 ) {
				if ( in_array( $level, array( E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED, E_USER_ERROR ), true ) ) {
					$this->raised[] = (string) $message;
					return true;
				}
				return null === $this->previousHandler ? false : ( $this->previousHandler )( $level, $message, $file, $line );
			}
		);
		$this->fakeWordPress();
	}

	protected function tearDown(): void {
		restore_error_handler();
		$this->unlockFolders();
		self::removeTree( $this->root );
		parent::tearDown();
	}

	/**
	 * What it checks: A key the site owner put into wp-config.php (OMNABASE_ENCRYPTION_KEY) wins over everything else, and then no key file
	 *   is made.
	 * Passes when: with the constant defined and wp-content writable, saving records the source "constant", no key file appears anywhere,
	 *   the credentials read back, and changing the salts does not matter.
	 * Fails when: a power user's own key is ignored in favour of a file, or the plugin litters wp-content with a key file it never uses.
	 */
	public function test_the_constant_wins_and_no_file_is_made(): void {
		define( 'OMNABASE_ENCRYPTION_KEY', base64_encode( str_repeat( "\x11", 16 ) . str_repeat( "\x5c", 16 ) ) );

		( new Vault() )->save( self::CREDENTIALS );

		$this->assertSame( 'constant', $this->stored()['source'] ?? null, 'the source is recorded as the constant' );
		$this->assertSame( array(), $this->keyFiles(), 'no key file is made while the constant wins' );
		$this->salt = 'salt-generation-two';
		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ), 'other salts do not matter' );
	}

	/**
	 * What it checks: On an ordinary site the plugin makes one key file in a protected folder, and that file gives nothing away when someone
	 *   opens its address.
	 * Passes when: after the first save with wp-content writable, exactly one key file exists in a folder named "omnabase" (under wp-content
	 *   or its uploads folder), beside an index.php and a .htaccess that refuses every request; only its owner may read it (mode 0600 or
	 *   stricter); it starts with "<?php", running it prints nothing, it holds the key as text; the source is recorded as "file"; the
	 *   credentials read back.
	 * Fails when: the key lands in a file a browser can download, a file any other account on a shared server may read, or nowhere at all
	 *   (the vault still hangs on the salts and a "log everyone out" disconnects the site).
	 */
	public function test_a_writable_folder_gets_one_guarded_key_file(): void {
		( new Vault() )->save( self::CREDENTIALS );

		$files = $this->keyFiles();
		$this->assertCount( 1, $files, 'one key file' );
		$file   = $files[0];
		$folder = dirname( $file );
		$this->assertSame( 'omnabase', basename( $folder ), 'the folder is named omnabase' );
		$this->assertContains( dirname( $folder ), array( WP_CONTENT_DIR, WP_CONTENT_DIR . '/uploads' ), 'under wp-content or its uploads folder' );
		$this->assertFileExists( $folder . '/index.php', 'an index.php beside it (no folder listing)' );
		$this->assertFileExists( $folder . '/.htaccess', 'a .htaccess beside it' );
		$this->assertMatchesRegularExpression( '/Require\s+all\s+denied|Deny\s+from\s+all/i', (string) file_get_contents( $folder . '/.htaccess' ), 'the .htaccess refuses every request' );
		$this->assertSame( 0, fileperms( $file ) & 0077, sprintf( 'nobody but the owner may touch the key file (mode %o)', fileperms( $file ) & 0777 ) );
		$this->assertNotSame( 0, fileperms( $file ) & 0400, 'the owner may read it' );

		$content = (string) file_get_contents( $file );
		$this->assertStringStartsWith( '<?php', $content, 'a PHP file' );
		$this->assertSame( '', self::runAsTheWebWould( $file ), 'running the file (what a web request does) prints nothing' );
		$this->assertNotSame( array(), self::material( $content ), 'the file holds the key as base64 or hex text' );

		$this->assertSame( 'file', $this->stored()['source'] ?? null, 'the source is recorded as the file' );
		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ) );
		$this->salt = 'salt-generation-two';
		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ), 'a "log everyone out" no longer locks the vault' );
	}

	/**
	 * What it checks: The key file is made once and then only read, also across Disconnect and a new connection.
	 * Passes when: after a first save, a second save with other credentials, clearing the vault (Disconnect) and a third save, the key file is
	 *   the same file with the same content throughout, the recorded key id never changes, the last credentials read back; and a save still
	 *   works when the key file is read-only.
	 * Fails when: every save makes a new key (two browser tabs saving at once would lock each other out), Disconnect deletes the file, or a
	 *   site whose host marks the file read-only can no longer connect.
	 */
	public function test_the_key_file_is_made_once_and_reused_never_rewritten(): void {
		( new Vault() )->save( self::CREDENTIALS );
		$files = $this->keyFiles();
		$this->assertCount( 1, $files );
		$file    = $files[0];
		$content = (string) file_get_contents( $file );
		$inode   = fileinode( $file );
		$kid     = self::kidOf( $this->stored() );

		chmod( $file, 0400 );
		( new Vault() )->save( self::OTHER );
		( new Vault() )->clear();
		$this->assertSame( array( $file ), $this->keyFiles(), 'clearing the vault (Disconnect) keeps the key file' );
		( new Vault() )->save( self::CREDENTIALS );

		clearstatcache();
		$this->assertSame( array( $file ), $this->keyFiles(), 'still the one file' );
		$this->assertSame( $content, (string) file_get_contents( $file ), 'its content never changed' );
		$this->assertSame( $inode, fileinode( $file ), 'never replaced by a new file' );
		$this->assertSame( $kid, self::kidOf( $this->stored() ), 'the same key id on every save' );
		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ) );
	}

	/**
	 * What it checks: A host where the plugin may not write under wp-content still gets a working, locked vault: the salts, as before.
	 * Passes when: with wp-content and its uploads folder read-only, saving records the source "salts", no key file exists anywhere, no PHP
	 *   warning is raised, and the credentials read back.
	 * Fails when: Connect breaks on locked-down hosts, the screen fills with "Permission denied" warnings, or the credentials are stored
	 *   unscrambled.
	 */
	public function test_an_unwritable_folder_falls_back_to_the_salts(): void {
		$this->lockFolders();

		( new Vault() )->save( self::CREDENTIALS );

		$this->assertSame( 'salts', $this->stored()['source'] ?? null );
		$this->assertSame( array(), $this->keyFiles(), 'no key file' );
		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ) );
	}

	/**
	 * What it checks: A site whose salts live in the database (wp-config.php still holds WordPress's placeholder) and which cannot write a
	 *   file is still locked with the salts, without any fuss.
	 * Passes when: with the placeholder salts and wp-content read-only, saving records "salts", the credentials read back, and no warning of
	 *   any kind is raised or logged.
	 * Fails when: such a site cannot connect, or the plugin nags about a weakness the owner can do nothing about (the founder removed that
	 *   advice from the screen).
	 */
	public function test_salts_in_the_database_still_lock_the_vault_silently(): void {
		define( 'AUTH_KEY', 'put your unique phrase here' );
		define( 'AUTH_SALT', 'put your unique phrase here' );
		$this->lockFolders();

		( new Vault() )->save( self::CREDENTIALS );

		$this->assertSame( 'salts', $this->stored()['source'] ?? null );
		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ) );
		$this->assertSame( array(), $this->warnings(), 'silently' );
	}

	/**
	 * What it checks: A vault locked with the salts keeps using the salts after the host starts allowing files.
	 * Passes when: saved while wp-content was read-only (source "salts"), then wp-content becomes writable: the credentials still read back,
	 *   the recorded source is still "salts", and changing the salts now makes the vault unreadable (proof it still reads with the salts).
	 * Fails when: a host change (a migration, a permissions fix) makes the vault try a new key file, so the stored connection suddenly cannot
	 *   be read and the site stops receiving replies.
	 */
	public function test_a_vault_locked_with_the_salts_stays_with_the_salts_when_a_folder_becomes_writable(): void {
		$this->lockFolders();
		( new Vault() )->save( self::CREDENTIALS );
		$this->unlockFolders();

		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ) );
		$this->assertSame( 'salts', $this->stored()['source'] ?? null, 'the recorded source is untouched by reading' );
		$this->salt = 'salt-generation-two';
		$this->assertSame( 'unreadable', ( new Vault() )->state(), 'it still reads with the salts' );
	}

	/**
	 * What it checks: A vault locked with the key file keeps using the file after someone adds OMNABASE_ENCRYPTION_KEY to wp-config.php.
	 * Passes when: saved with the key file (source "file"), then the constant is defined: the credentials still read back with the source
	 *   still "file"; deleting the key file then makes the vault unreadable (proof it still reads with the file).
	 * Fails when: adding the constant (for example from a hardening guide) silently locks the site out of its own stored connection.
	 */
	public function test_a_vault_locked_with_the_file_stays_with_the_file_when_the_constant_appears(): void {
		( new Vault() )->save( self::CREDENTIALS );
		$this->assertSame( 'file', $this->stored()['source'] ?? null );

		define( 'OMNABASE_ENCRYPTION_KEY', base64_encode( str_repeat( "\x22", 32 ) ) );

		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ) );
		$this->assertSame( 'file', $this->stored()['source'] ?? null );
		foreach ( $this->keyFiles() as $file ) {
			unlink( $file );
		}
		$this->assertSame( 'unreadable', ( new Vault() )->state(), 'it still reads with the file' );
	}

	/**
	 * What it checks: A connection saved later chooses its key afresh: once the host allows files, the next connection uses the file.
	 * Passes when: saved while wp-content was read-only (source "salts"), then wp-content becomes writable and the vault saves again (a new
	 *   Connect): the source is now "file", a key file exists, and the credentials read back.
	 * Fails when: a site stays tied to the salts forever although it could now have the sturdier key file.
	 */
	public function test_the_next_save_chooses_again(): void {
		$this->lockFolders();
		( new Vault() )->save( self::CREDENTIALS );
		$this->unlockFolders();

		( new Vault() )->save( self::OTHER );

		$this->assertSame( 'file', $this->stored()['source'] ?? null );
		$this->assertCount( 1, $this->keyFiles() );
		$this->assertSame( self::OTHER, self::only3( ( new Vault() )->read() ) );
	}

	/**
	 * What it checks: When the key file is lost (a restore without wp-content, a cleanup plugin), the site shows "reconnect needed" and knows
	 *   why.
	 * Passes when: after the key file is deleted, the state is "unreadable" (not "empty", not "ok"), reading gives nothing, the reason is
	 *   "file_gone"; reading did not quietly make a new key file; before the loss the reason was empty.
	 * Fails when: the vault hands out garbage, pretends the site was never connected (no warning appears), blames the salts for a missing
	 *   file (the owner hunts the wrong cause), or replaces the lost key with a new one behind the owner's back.
	 */
	public function test_a_lost_key_file_means_reconnect_needed_with_its_reason(): void {
		( new Vault() )->save( self::CREDENTIALS );
		$this->assertNull( ( new Vault() )->unreadableReason(), 'no reason while it reads' );
		foreach ( $this->keyFiles() as $file ) {
			unlink( $file );
		}

		$vault = new Vault();
		$this->assertSame( 'unreadable', $vault->state() );
		$this->assertNull( $vault->read() );
		$this->assertSame( 'file_gone', $vault->unreadableReason() );
		$this->assertSame( array(), $this->keyFiles(), 'reading made no new key file' );
		$this->assertSame( 'file_gone', ( new Vault() )->unreadableReason(), 'still the same reason afterwards' );
	}

	/**
	 * What it checks: When a vault locked with the salts meets changed salts, the site shows "reconnect needed" and says the secret keys
	 *   changed.
	 * Passes when: saved with wp-content read-only (source "salts"), after the salts change the state is "unreadable" with the reason
	 *   "keys_changed"; with the salts back it reads again and has no reason; an empty vault has no reason either.
	 * Fails when: the owner is told a file is missing on a site that never had one, or the warning stays after the salts are back.
	 */
	public function test_changed_salts_mean_reconnect_needed_with_their_reason(): void {
		$this->assertNull( ( new Vault() )->unreadableReason(), 'an empty vault has no reason' );
		$this->lockFolders();
		( new Vault() )->save( self::CREDENTIALS );

		$this->salt = 'salt-generation-two';
		$this->assertSame( 'unreadable', ( new Vault() )->state() );
		$this->assertSame( 'keys_changed', ( new Vault() )->unreadableReason() );

		$this->salt = 'salt-generation-one';
		$this->assertSame( 'ok', ( new Vault() )->state() );
		$this->assertNull( ( new Vault() )->unreadableReason() );
	}

	/**
	 * What it checks: The vault notes which key locked it by a short fingerprint, never by the key itself.
	 * Passes when: for each of the three sources (here: the file and the salts) the stored record holds a key id of 1 to 16 characters; for
	 *   the file it is none of the key's written forms (the text in the file, the raw bytes, base64, hex) and contains none of them.
	 * Fails when: the "key id" is the key, so a database dump or backup carries both the scrambled credentials and the key that opens them.
	 */
	public function test_the_key_id_is_short_and_not_the_key(): void {
		( new Vault() )->save( self::CREDENTIALS );
		$kid = self::kidOf( $this->stored() );
		$this->assertGreaterThanOrEqual( 1, strlen( $kid ) );
		$this->assertLessThanOrEqual( 16, strlen( $kid ), 'a short fingerprint' );
		foreach ( self::material( (string) file_get_contents( $this->theKeyFile() ) ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $kid, 'the key id gives away no part of the key' );
			$this->assertStringNotContainsString( $kid, $needle, 'the key id is not a slice of the key' );
		}

		( new Vault() )->clear();
		foreach ( $this->keyFiles() as $file ) {
			unlink( $file );
		}
		$this->lockFolders();
		( new Vault() )->save( self::CREDENTIALS );
		$this->assertSame( 'salts', $this->stored()['source'] ?? null );
		$this->assertLessThanOrEqual( 16, strlen( self::kidOf( $this->stored() ) ), 'the salts get a short key id too' );
	}

	/**
	 * What it checks: The key in the file never leaves the file: not into the database, not into a log line.
	 * Passes when: after saving, reading, a read that logs a warning (the file made world-readable), Disconnect and a new save, none of the
	 *   key's written forms (the text in the file, raw bytes, base64, hex) appears in any option of any site, in the network's options, or in
	 *   any warning or log line.
	 * Fails when: a database backup, a debug log or a support ticket with a pasted log carries the key that opens the stored credentials.
	 */
	public function test_the_key_never_reaches_an_option_or_a_log_line(): void {
		( new Vault() )->save( self::CREDENTIALS );
		( new Vault() )->read();
		$file = $this->theKeyFile();
		chmod( $file, 0644 );
		( new Vault() )->read();
		( new Vault() )->clear();
		( new Vault() )->save( self::OTHER );

		$dump = serialize( array( $this->options, $this->network ) ) . json_encode( array( $this->options, $this->network ) );
		$logs = implode( "\n", $this->warnings() );
		$this->assertNotSame( array(), self::material( (string) file_get_contents( $file ) ) );
		foreach ( self::material( (string) file_get_contents( $file ) ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $dump, 'the key is in the database' );
			$this->assertStringNotContainsString( $needle, $logs, 'the key is in a log line' );
		}
	}

	/**
	 * What it checks: A key file anyone on the server may read still works, and the plugin notes the risk in the error log, never on screen.
	 * Passes when: reading a key file with mode 0600 logs nothing; after the file is made readable by everyone (0644), the credentials still
	 *   read back, at least one warning reaches the error log (or PHP's warning channel), and nothing is printed.
	 * Fails when: a host's permissive umask (the default mode for new files) breaks the connection, the risk goes unnoticed, or the warning
	 *   lands on the admin screen or a visitor's page.
	 */
	public function test_a_world_readable_key_file_is_read_and_logged_never_shown(): void {
		( new Vault() )->save( self::CREDENTIALS );
		( new Vault() )->read();
		$this->assertSame( array(), $this->warnings(), 'no warning while the file is private' );

		chmod( $this->theKeyFile(), 0644 );
		clearstatcache();

		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ) );
		$this->assertNotSame( array(), $this->warnings(), 'the world-readable file is logged' );
	}

	/**
	 * What it checks: On a network, the sites share one key file, and each site keeps its own vault.
	 * Passes when: on a two-site network, the main site saves one set of credentials and the second site another: exactly one key file exists
	 *   (not one per site's uploads folder), both sites record the same key id, each site reads back its own credentials, and the second
	 *   site's save left the main site's stored record unchanged.
	 * Fails when: every sub-site makes its own key file (a file per site under uploads/sites/…), or one site's save overwrites another
	 *   site's connection.
	 */
	public function test_one_key_file_per_network_one_vault_per_site(): void {
		$this->multisite = true;
		mkdir( WP_CONTENT_DIR . '/uploads/sites/2', 0755, true );

		( new Vault() )->save( self::CREDENTIALS );
		$main = $this->stored();
		$this->blog = 2;
		( new Vault() )->save( self::OTHER );
		$second = $this->stored();

		$this->assertCount( 1, $this->keyFiles(), 'one key file for the network' );
		$this->assertStringNotContainsString( '/sites/', $this->theKeyFile(), 'not in a sub-site\'s uploads folder' );
		$this->assertSame( 'file', $second['source'] ?? null );
		$this->assertSame( self::kidOf( $main ), self::kidOf( $second ), 'the same key id on both sites' );
		$this->assertSame( self::OTHER, self::only3( ( new Vault() )->read() ), 'the second site reads its own' );
		$this->blog = 1;
		$this->assertSame( $main, $this->stored(), 'the main site\'s record is untouched' );
		$this->assertSame( self::CREDENTIALS, self::only3( ( new Vault() )->read() ), 'the main site reads its own' );
	}

	/* ---------- the fake WordPress ---------- */

	/** The WordPress functions a file-based key plausibly calls, faked over this test's temporary folder. */
	private function fakeWordPress(): void {
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $v, $flags = 0 ) => json_encode( $v, $flags ) );
		Functions\when( 'wp_salt' )->alias( fn ( $scheme = 'auth' ) => $this->salt . '|' . $scheme );
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => array_key_exists( $name, $this->options[ $this->blog ] ?? array() ) ? $this->options[ $this->blog ][ $name ] : $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $this->blog ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '' ) {
				if ( array_key_exists( $name, $this->options[ $this->blog ] ?? array() ) ) {
					return false;
				}
				$this->options[ $this->blog ][ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $this->blog ][ $name ] );
				return true;
			}
		);
		foreach ( array( 'site', 'network' ) as $kind ) {
			$shift = 'network' === $kind ? 1 : 0; // the network functions take the network's id first
			Functions\when( "get_{$kind}_option" )->alias( fn ( ...$a ) => $this->network[ $a[ $shift ] ] ?? ( $a[ $shift + 1 ] ?? false ) );
			Functions\when( "update_{$kind}_option" )->alias(
				function ( ...$a ) use ( $shift ) {
					$this->network[ $a[ $shift ] ] = $a[ $shift + 1 ];
					return true;
				}
			);
			Functions\when( "add_{$kind}_option" )->alias(
				function ( ...$a ) use ( $shift ) {
					if ( array_key_exists( $a[ $shift ], $this->network ) ) {
						return false;
					}
					$this->network[ $a[ $shift ] ] = $a[ $shift + 1 ];
					return true;
				}
			);
			Functions\when( "delete_{$kind}_option" )->alias(
				function ( ...$a ) use ( $shift ) {
					unset( $this->network[ $a[ $shift ] ] );
					return true;
				}
			);
		}
		Functions\when( 'is_multisite' )->alias( fn () => $this->multisite );
		Functions\when( 'get_current_blog_id' )->alias( fn () => $this->blog );
		Functions\when( 'get_main_site_id' )->justReturn( 1 );
		Functions\when( 'get_current_network_id' )->justReturn( 1 );
		Functions\when( 'is_main_site' )->alias( fn ( $id = null ) => 1 === (int) ( $id ?? $this->blog ) );
		Functions\when( 'switch_to_blog' )->alias(
			function ( $id ) {
				$this->switched[] = $this->blog;
				$this->blog       = (int) $id;
				return true;
			}
		);
		Functions\when( 'restore_current_blog' )->alias(
			function () {
				$this->blog = (int) ( array_pop( $this->switched ) ?? 1 );
				return true;
			}
		);
		Functions\when( 'ms_is_switched' )->alias( fn () => array() !== $this->switched );
		Functions\when( 'wp_upload_dir' )->alias(
			function () {
				$base = WP_CONTENT_DIR . '/uploads' . ( $this->multisite && 1 !== $this->blog ? '/sites/' . $this->blog : '' );
				return array( 'path' => $base, 'url' => 'https://shop.example.org/wp-content/uploads', 'subdir' => '', 'basedir' => $base, 'baseurl' => 'https://shop.example.org/wp-content/uploads', 'error' => false );
			}
		);
		Functions\when( 'wp_mkdir_p' )->alias( static fn ( $dir ) => is_dir( $dir ) || @mkdir( $dir, 0755, true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		Functions\when( 'wp_is_writable' )->alias( static fn ( $path ) => is_writable( $path ) );
		Functions\when( 'wp_delete_file' )->alias( static fn ( $path ) => is_file( $path ) && @unlink( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		Functions\when( 'trailingslashit' )->alias( static fn ( $s ) => rtrim( (string) $s, '/\\' ) . '/' );
		Functions\when( 'untrailingslashit' )->alias( static fn ( $s ) => rtrim( (string) $s, '/\\' ) );
		Functions\when( 'wp_normalize_path' )->alias( static fn ( $p ) => (string) preg_replace( '|(?<=.)/+|', '/', str_replace( '\\', '/', (string) $p ) ) );
		Functions\when( 'get_temp_dir' )->alias( fn () => $this->root . '/' );
		Functions\when( 'wp_generate_password' )->alias( static fn ( $length = 12 ) => substr( bin2hex( random_bytes( (int) $length ) ), 0, (int) $length ) );
		Functions\when( 'wp_trigger_error' )->alias(
			function ( $function_name, $message ) {
				$this->raised[] = (string) $message;
			}
		);
	}

	/* ---------- the helpers ---------- */

	/**
	 * The vault's stored record on the current site.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(): array {
		$stored = $this->options[ $this->blog ][ Options::VAULT ] ?? null;
		$this->assertIsArray( $stored, 'the vault keeps its record as an array option' );
		return $stored;
	}

	/**
	 * The key id of a stored record (under `kid`, or `key_id`).
	 *
	 * @param array<string,mixed> $stored The record.
	 */
	private static function kidOf( array $stored ): string {
		$kid = $stored['kid'] ?? $stored['key_id'] ?? null;
		self::assertIsString( $kid, 'the record holds a key id' );
		return $kid;
	}

	/**
	 * Every key file under this test's wp-content: a `.php` file inside a folder named `omnabase`, other than `index.php`.
	 *
	 * @return string[]
	 */
	private function keyFiles(): array {
		clearstatcache();
		$found = array();
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root . '/wp-content', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( $item->isFile() && 'php' === $item->getExtension() && 'index.php' !== $item->getFilename() && 'omnabase' === basename( $item->getPath() ) ) {
				$found[] = $item->getPathname();
			}
		}
		sort( $found );
		return $found;
	}

	/** The one key file (asserts there is exactly one). */
	private function theKeyFile(): string {
		$files = $this->keyFiles();
		$this->assertCount( 1, $files, 'exactly one key file' );
		return $files[0];
	}

	/** Makes wp-content and every folder under it read-only, so no folder or file can be made anywhere in it. */
	private function lockFolders(): void {
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( WP_CONTENT_DIR, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				chmod( $item->getPathname(), 0555 );
			}
		}
		chmod( WP_CONTENT_DIR, 0555 );
		clearstatcache();
		if ( is_writable( WP_CONTENT_DIR ) ) {
			$this->markTestSkipped( 'this account writes into read-only folders (root?): the fallback cannot be simulated' );
		}
	}

	/** Makes every folder of this test's tree writable again. */
	private function unlockFolders(): void {
		if ( '' === $this->root || ! is_dir( $this->root ) ) {
			return;
		}
		chmod( $this->root . '/wp-content', 0755 );
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@chmod( $item->getPathname(), 0755 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		clearstatcache();
	}

	/**
	 * Every warning raised or logged during the test.
	 *
	 * @return string[]
	 */
	private function warnings(): array {
		$log   = $this->root . '/php-error.log';
		$lines = is_file( $log ) ? array_values( array_filter( array_map( 'trim', file( $log ) ?: array() ) ) ) : array();
		return array_merge( $this->raised, $lines );
	}

	/**
	 * What a web server prints when it runs a PHP file directly (no WordPress loaded, so no ABSPATH).
	 *
	 * @param string $file The file.
	 */
	private static function runAsTheWebWould( string $file ): string {
		$process = proc_open( array( PHP_BINARY, '-n', '-d', 'display_errors=stderr', $file ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process, 'PHP can run the key file' );
		$out = (string) stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		return $out;
	}

	/**
	 * The key's written forms found in a key file: each base64 or hex run long enough to be a 32-byte key, with its raw bytes, its base64
	 * (plain and web-safe) and its hex.
	 *
	 * @param string $content The key file's content.
	 * @return string[]
	 */
	private static function material( string $content ): array {
		$needles = array();
		preg_match_all( '~[A-Za-z0-9+/_=-]{32,}~', $content, $m );
		foreach ( $m[0] as $run ) {
			$raw = null;
			if ( ctype_xdigit( $run ) && strlen( $run ) >= 64 && 0 === strlen( $run ) % 2 ) {
				$raw = hex2bin( $run );
			} else {
				$decoded = base64_decode( strtr( $run, '-_', '+/' ), true );
				$raw     = ( false !== $decoded && strlen( $decoded ) >= 32 ) ? $decoded : null;
			}
			if ( null === $raw || false === $raw ) {
				continue;
			}
			$b64       = base64_encode( $raw );
			$needles[] = $run;
			$needles[] = $raw;
			$needles[] = rtrim( $b64, '=' );
			$needles[] = rtrim( strtr( $b64, '+/', '-_' ), '=' );
			$needles[] = bin2hex( $raw );
		}
		return array_values( array_unique( $needles ) );
	}

	/**
	 * The three credentials of a read, in a fixed order.
	 *
	 * @param array<string,mixed>|null $read What the vault read.
	 * @return array{key:mixed,webhooks:mixed,facts:mixed}|null
	 */
	private static function only3( ?array $read ): ?array {
		return null === $read ? null : array(
			'key'      => $read['key'] ?? null,
			'webhooks' => $read['webhooks'] ?? null,
			'facts'    => $read['facts'] ?? null,
		);
	}

	/**
	 * Removes a folder and everything in it.
	 *
	 * @param string $dir The folder.
	 */
	private static function removeTree( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
