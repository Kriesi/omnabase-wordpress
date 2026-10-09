<?php
/**
 * M13 round one, the red team's failing tests: the vault (the one place the site's OmnaBase key and its two secrets are kept, encrypted at
 * rest: stored scrambled, readable only with a key kept elsewhere).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): choice 3 "**The vault**: the key and both secrets encrypted with
 * secretbox (libsodium, or WordPress's bundled `sodium_compat`) under a key from `OMNABASE_ENCRYPTION_KEY` when `wp-config.php` defines
 * it (32 random bytes, base64 …), else from `AUTH_KEY`/`AUTH_SALT` when they are real constants and not the placeholder … A **check value**
 * encrypted with the credentials detects a salt rotation"; round one's *Red team*: "the vault round-trips, uses the constant over the salts,
 * fails closed with other salts"; review `docs/reviews/m13-wordpress-plugin-review.md` S1 (salts in the database, the placeholder).
 * The coordinator: "a site with salts in the database gets no extra protection — a doc line, not a test".
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - `OmnaBase\WordPress\Security\Vault`, built with `new Vault()`; `save( array $credentials ): void` with
 *   `[ 'key' => string, 'webhooks' => ?string, 'facts' => ?string ]`; `read(): ?array` (the same three, null when empty or unreadable);
 *   `state(): string`, one of `empty`, `ok`, `unreadable`; `clear(): void`; `keySource(): string`, one of `constant`, `salts`, `database`.
 * - The salts are read through WordPress's `wp_salt()` (which itself answers from `AUTH_KEY`/`AUTH_SALT` or, when those are missing or the
 *   placeholder, from salts it keeps in the database), so a rotation is one changed `wp_salt()` answer; `OMNABASE_ENCRYPTION_KEY`, when
 *   defined, wins and `wp_salt()` no longer matters. The vault stores through `update_option`/`add_option` (any option name of the plugin's
 *   list), never autoloaded.
 * - Tests that define a constant run in their own PHP process (a constant cannot be undefined again).
 *
 * Why this fails today: there is no `Security\Vault` class (round zero's `Api\StoredKey` keeps the key in plain text).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Security\Vault;

final class VaultTest extends TestCase {

	private const CREDENTIALS = array(
		'key'      => 'obw_k3j9x2qak3j9x2qak3j9x2qak3j9x2qak3j9x2qak3j_ab7Q2X',
		'webhooks' => 'whsec_vaultTestWebhookSecret00000001',
		'facts'    => 'Zm9yLXRoZS12YXVsdC10ZXN0LWZhY3RzLXNlY3JldDE', // the facts secret has no prefix
	);

	/** @var array<string,mixed> the fake options table */
	private array $options = array();

	/** @var array<string,mixed> the autoload flag each option was last written with */
	private array $autoload = array();

	/** the fake wp_salt()'s current answer, per scheme */
	private string $salt = 'salt-generation-one';

	protected function setUp(): void {
		parent::setUp();
		$this->options  = array();
		$this->autoload = array();
		$this->salt     = 'salt-generation-one';
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $v, $flags = 0 ) => json_encode( $v, $flags ) );
		Functions\when( 'wp_salt' )->alias( fn ( $scheme = 'auth' ) => $this->salt . '|' . $scheme );
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				if ( array_key_exists( $name, $this->options ) ) {
					return false;
				}
				$this->options[ $name ]  = $value;
				$this->autoload[ $name ] = $autoload;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	/**
	 * What it checks: What the vault stores comes back exactly, and nothing of it is readable in the database.
	 * Passes when: saving the key and both secrets and reading them back gives the same three values (also with no facts secret); the
	 *   stored options hold none of the three values in plain text; the vault's options are not loaded on every page.
	 * Fails when: a value comes back changed (the site would sign nothing correctly and every call from OmnaBase would be refused), or a
	 *   database dump or backup shows the key in clear text, or the key is loaded into memory on every page view.
	 */
	public function test_the_credentials_round_trip_and_are_never_stored_in_plain_text(): void {
		$vault = new Vault();
		$vault->save( self::CREDENTIALS );

		$this->assertSame( self::CREDENTIALS, $this->only3( $vault->read() ) );
		$this->assertSame( 'ok', $vault->state() );

		$dump = serialize( $this->options ) . json_encode( $this->options );
		$this->assertNotSame( array(), $this->options, 'the vault stores something' );
		foreach ( self::CREDENTIALS as $name => $value ) {
			$this->assertStringNotContainsString( $value, $dump, "the {$name} value is stored in plain text" );
			$this->assertStringNotContainsString( base64_encode( $value ), $dump, "the {$name} value is stored merely base64-encoded" );
		}
		foreach ( $this->autoload as $name => $flag ) {
			$this->assertContains( $flag, array( false, 'no', 'off' ), "the option {$name} is autoloaded" );
		}

		$vault->save( array( 'facts' => null ) + self::CREDENTIALS );
		$this->assertNull( $this->only3( ( new Vault() )->read() )['facts'], 'no facts secret (another system answers customer questions) reads back as null' );
	}

	/**
	 * What it checks: A site that never connected, or disconnected, reads as empty, never as broken.
	 * Passes when: before saving the state is "empty" and reading gives nothing; after clearing it is "empty" again and nothing of the
	 *   credentials is left in the options.
	 * Fails when: a fresh site shows "reconnect needed", or Disconnect leaves the encrypted key behind.
	 */
	public function test_empty_before_saving_and_after_clearing(): void {
		$vault = new Vault();
		$this->assertSame( 'empty', $vault->state() );
		$this->assertNull( $vault->read() );

		$vault->save( self::CREDENTIALS );
		$vault->clear();

		$this->assertSame( 'empty', ( new Vault() )->state() );
		$this->assertNull( ( new Vault() )->read() );
		$this->assertSame( array(), array_filter( $this->options ), 'nothing of the credentials is left after clearing' );
	}

	/**
	 * What it checks: When the site's salts change (security plugins' "log everyone out" does this), the vault notices and says so instead
	 *   of handing out garbage.
	 * Passes when: after the salts change, the state is "unreadable" (not "empty", not "ok") and reading gives nothing, on a fresh vault
	 *   object too; changing the salts back makes it readable again.
	 * Fails when: the vault returns scrambled values as if they were the key (every signature check fails silently and OmnaBase eventually
	 *   switches the site off), or it pretends the site was never connected, so no "reconnect needed" warning appears.
	 */
	public function test_other_salts_fail_closed_and_say_unreadable(): void {
		( new Vault() )->save( self::CREDENTIALS );

		$this->salt = 'salt-generation-two';

		$vault = new Vault();
		$this->assertSame( 'unreadable', $vault->state() );
		$this->assertNull( $vault->read() );

		$this->salt = 'salt-generation-one';
		$this->assertSame( self::CREDENTIALS, $this->only3( ( new Vault() )->read() ), 'the same salts read it again' );
	}

	/**
	 * What it checks: A damaged stored value is treated like other salts: unreadable, never a crash.
	 * Passes when: after the stored options are overwritten with junk, the state is "unreadable" and reading gives nothing.
	 * Fails when: a damaged database row breaks every admin page with a PHP error, or the junk is used as a key.
	 */
	public function test_a_damaged_value_reads_as_unreadable(): void {
		( new Vault() )->save( self::CREDENTIALS );
		foreach ( array_keys( $this->options ) as $name ) {
			$this->options[ $name ] = is_array( $this->options[ $name ] ) ? array_map( static fn () => 'junk', $this->options[ $name ] ) : 'junk';
		}

		$this->assertSame( 'unreadable', ( new Vault() )->state() );
		$this->assertNull( ( new Vault() )->read() );
	}

	/**
	 * What it checks: Without a dedicated encryption key and without real salt constants, the vault says honestly that its key lives in
	 *   the database.
	 * Passes when: with neither OMNABASE_ENCRYPTION_KEY nor AUTH_KEY/AUTH_SALT defined, the key source is "database".
	 * Fails when: the screen would claim protection the site does not have.
	 */
	public function test_without_constants_the_key_source_is_the_database(): void {
		$this->assertSame( 'database', ( new Vault() )->keySource() );
	}

	/**
	 * What it checks: A dedicated encryption key in wp-config.php wins over the salts, so a salt rotation no longer locks the vault.
	 * Passes when: with OMNABASE_ENCRYPTION_KEY defined (32 random bytes, base64), the key source is "constant", and after the salts
	 *   change the credentials still read back.
	 * Fails when: the vault ignores the dedicated key, so a "log everyone out" disconnects the site anyway.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_encryption_key_constant_wins_over_the_salts(): void {
		define( 'OMNABASE_ENCRYPTION_KEY', base64_encode( str_repeat( "\x07", 16 ) . str_repeat( "\x2a", 16 ) ) );

		( new Vault() )->save( self::CREDENTIALS );
		$this->salt = 'salt-generation-two';

		$vault = new Vault();
		$this->assertSame( 'constant', $vault->keySource() );
		$this->assertSame( 'ok', $vault->state() );
		$this->assertSame( self::CREDENTIALS, $this->only3( $vault->read() ) );
	}

	/**
	 * What it checks: Real salt constants in wp-config.php count as salts kept outside the database.
	 * Passes when: with AUTH_KEY and AUTH_SALT defined as real values, the key source is "salts".
	 * Fails when: a properly configured site is told its key lives in the database.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_real_salt_constants_are_the_salts(): void {
		define( 'AUTH_KEY', 'q9#Lk2@vP!x7zR4m^T1w&Y8n*B3c(E6s' );
		define( 'AUTH_SALT', 'H5$jN0%dF2-uG9+oK4=aM7~iQ1<lS8>e' );

		$this->assertSame( 'salts', ( new Vault() )->keySource() );
	}

	/**
	 * What it checks: WordPress's placeholder salt is not a real salt.
	 * Passes when: with AUTH_KEY and AUTH_SALT set to "put your unique phrase here", the key source is "database".
	 * Fails when: a site that never replaced the sample wp-config.php is told it is protected.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_placeholder_salt_counts_as_the_database(): void {
		define( 'AUTH_KEY', 'put your unique phrase here' );
		define( 'AUTH_SALT', 'put your unique phrase here' );

		$this->assertSame( 'database', ( new Vault() )->keySource() );
	}

	/**
	 * The three credentials of a read, in a fixed order (a vault may keep more fields beside them).
	 *
	 * @param array<string,mixed>|null $read What the vault read.
	 * @return array{key:mixed,webhooks:mixed,facts:mixed}|null
	 */
	private function only3( ?array $read ): ?array {
		if ( null === $read ) {
			return null;
		}
		return array(
			'key'      => $read['key'] ?? null,
			'webhooks' => $read['webhooks'] ?? null,
			'facts'    => $read['facts'] ?? null,
		);
	}
}
