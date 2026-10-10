<?php
/**
 * The vault: the one place the site's OmnaBase key and its two secrets are kept.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Security;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the key, the webhook secret and the facts secret encrypted at rest with libsodium's secretbox (WordPress bundles sodium_compat,
 * so it works on every host). The encryption key comes from `OMNABASE_ENCRYPTION_KEY` when wp-config.php defines it (32 random bytes,
 * base64), else from WordPress's salts through `wp_salt()`. A site whose salts live in the database gets no real protection from this
 * (`keySource()` says "database" and the screen says so).
 *
 * A check value is encrypted beside the credentials: when the salts change (a security plugin's "log everyone out"), it no longer opens and
 * the vault reads "unreadable", never garbage. Nothing else reads or writes the vault's option (the structure guard checks). When OmnaBase
 * refuses the key (401), the vault keeps a "revoked" mark beside it until the next save or clear.
 */
final class Vault {

	public const EMPTY      = 'empty';
	public const OK         = 'ok';
	public const UNREADABLE = 'unreadable';

	private const CHECK       = 'omnabase-vault-check-v1';
	private const PLACEHOLDER = 'put your unique phrase here';
	private const CONTEXT     = 'omnabase-vault|';

	/**
	 * Stores the credentials, replacing what was there.
	 *
	 * @param array{key:string,webhooks:?string,facts:?string} $credentials The key and both secrets (a secret may be null).
	 */
	public function save( array $credentials ): void {
		$key     = $this->encryptionKey();
		$payload = (string) wp_json_encode(
			array(
				'key'      => (string) ( $credentials['key'] ?? '' ),
				'webhooks' => isset( $credentials['webhooks'] ) ? (string) $credentials['webhooks'] : null,
				'facts'    => isset( $credentials['facts'] ) ? (string) $credentials['facts'] : null,
			)
		);
		$stored  = array(
			'v'     => 1,
			'check' => $this->sealWith( self::CHECK, $key ),
			'data'  => $this->sealWith( $payload, $key ),
		);
		update_option( Options::VAULT, $stored, false );
	}

	/**
	 * The credentials, or null when the vault is empty or cannot be read.
	 *
	 * @return array{key:string,webhooks:?string,facts:?string}|null
	 */
	public function read(): ?array {
		$stored = $this->stored();
		if ( ! is_array( $stored ) || self::OK !== $this->stateOf( $stored ) ) {
			return null;
		}
		$json = $this->openWith( (string) ( $stored['data'] ?? '' ), $this->encryptionKey() );
		$data = null === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || ! is_string( $data['key'] ?? null ) || '' === $data['key'] ) {
			return null;
		}
		return array(
			'key'      => $data['key'],
			'webhooks' => is_string( $data['webhooks'] ?? null ) ? $data['webhooks'] : null,
			'facts'    => is_string( $data['facts'] ?? null ) ? $data['facts'] : null,
		);
	}

	/** `empty` (never connected or disconnected), `ok`, or `unreadable` (other salts, or a damaged value). */
	public function state(): string {
		$stored = $this->stored();
		if ( null === $stored ) {
			return self::EMPTY;
		}
		return is_array( $stored ) ? $this->stateOf( $stored ) : self::UNREADABLE;
	}

	/** Forgets everything the vault holds. */
	public function clear(): void {
		delete_option( Options::VAULT );
	}

	/**
	 * Marks the stored key as refused by OmnaBase (it answered 401: someone disconnected the site there). The mark lives beside the key, so a
	 * new key (Connect saves afresh) or Disconnect (clear) ends it.
	 */
	public function markRevoked(): void {
		$stored = $this->stored();
		if ( is_array( $stored ) && empty( $stored['revoked'] ) ) {
			update_option( Options::VAULT, array( 'revoked' => time() ) + $stored, false );
		}
	}

	/** Whether OmnaBase refused the stored key (the site's calls stop until Connect). */
	public function isRevoked(): bool {
		$stored = $this->stored();
		return is_array( $stored ) && ! empty( $stored['revoked'] );
	}

	/** Where the encryption key comes from: `constant` (OMNABASE_ENCRYPTION_KEY), `salts` (real salt constants) or `database`. */
	public function keySource(): string {
		if ( '' !== self::constantValue( 'OMNABASE_ENCRYPTION_KEY' ) ) {
			return 'constant';
		}
		foreach ( array( 'AUTH_KEY', 'AUTH_SALT' ) as $name ) {
			$value = self::constantValue( $name );
			if ( '' === $value || self::PLACEHOLDER === $value ) {
				return 'database';
			}
		}
		return 'salts';
	}

	/**
	 * Encrypts a small value under the vault's key (an administrator's pending connect keeps its verifier this way).
	 *
	 * @param string $plain The value.
	 */
	public function seal( string $plain ): string {
		return $this->sealWith( $plain, $this->encryptionKey() );
	}

	/**
	 * Opens a value sealed by `seal()`; null when it cannot be opened (other salts, damaged).
	 *
	 * @param string $sealed The sealed value.
	 */
	public function open( string $sealed ): ?string {
		return $this->openWith( $sealed, $this->encryptionKey() );
	}

	/**
	 * The stored option: null when there is nothing, else what is there (an array when intact).
	 *
	 * @return mixed
	 */
	private function stored() {
		$stored = get_option( Options::VAULT, null );
		return ( null === $stored || false === $stored || '' === $stored || array() === $stored ) ? null : $stored;
	}

	/**
	 * Whether a stored array opens with today's key.
	 *
	 * @param array<string,mixed> $stored The stored option.
	 */
	private function stateOf( array $stored ): string {
		return self::CHECK === $this->openWith( (string) ( $stored['check'] ?? '' ), $this->encryptionKey() ) ? self::OK : self::UNREADABLE;
	}

	/** The 32-byte secretbox key: the dedicated constant when defined, else derived from WordPress's salts. */
	private function encryptionKey(): string {
		$constant = self::constantValue( 'OMNABASE_ENCRYPTION_KEY' );
		if ( '' !== $constant ) {
			$raw = base64_decode( $constant, true );
			return ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) ? $raw : sodium_crypto_generichash( self::CONTEXT . $constant, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		return sodium_crypto_generichash( self::CONTEXT . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypts with a fresh nonce: base64 of nonce and box.
	 *
	 * @param string $plain The value.
	 * @param string $key   The secretbox key.
	 */
	private function sealWith( string $plain, string $key ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}

	/**
	 * Decrypts; null for anything that does not open.
	 *
	 * @param string $sealed The base64 of nonce and box.
	 * @param string $key    The secretbox key.
	 */
	private function openWith( string $sealed, string $key ): ?string {
		$raw = base64_decode( $sealed, true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $key );
		} catch ( \SodiumException $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * A wp-config.php constant as a string, empty when undefined.
	 *
	 * @param string $name The constant's name.
	 */
	private static function constantValue( string $name ): string {
		return defined( $name ) ? (string) constant( $name ) : '';
	}
}
