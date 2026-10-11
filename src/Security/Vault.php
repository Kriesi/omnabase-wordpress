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
 * so it works on every host). The key that locks them is chosen once, on a save, by `VaultKey`: OMNABASE_ENCRYPTION_KEY when a site owner
 * defined one, else the random key in the plugin's key file, else WordPress's salts. The choice is recorded beside the encrypted values
 * (`source`, and `kid`, the key's fingerprint), and reading uses only that record: nothing is re-chosen, no file is made, no key is kept
 * in memory between calls.
 *
 * A check value is encrypted beside the credentials: when the recorded key is lost (the key file gone, the salts changed by a security
 * plugin's "log everyone out"), it no longer opens and the vault reads "unreadable", never garbage, with the reason (`unreadableReason()`).
 * Nothing else reads or writes the vault's option (the structure guard checks). When OmnaBase refuses the key (401), the vault keeps a
 * "revoked" mark beside it until the next save or clear.
 */
final class Vault {

	public const EMPTY      = 'empty';
	public const OK         = 'ok';
	public const UNREADABLE = 'unreadable';

	/** Why a vault cannot be read: the key file it was locked with is gone. */
	public const FILE_GONE = 'file_gone';

	/** Why a vault cannot be read: the site's salts it was locked with changed. */
	public const KEYS_CHANGED = 'keys_changed';

	private const CHECK = 'omnabase-vault-check-v1';

	/** The key chooser (a new one per vault, so nothing outlives the call that needs it). */
	private VaultKey $keys;

	/**
	 * Builds the vault.
	 *
	 * @param VaultKey|null $keys The key chooser (the plugin's own by default).
	 */
	public function __construct( ?VaultKey $keys = null ) {
		$this->keys = $keys ?? new VaultKey();
	}

	/**
	 * Stores the credentials, replacing what was there, under a key chosen afresh (OMNABASE_ENCRYPTION_KEY, the key file, the salts).
	 *
	 * @param array{key:string,webhooks:?string,facts:?string} $credentials The key and both secrets (a secret may be null).
	 */
	public function save( array $credentials ): void {
		$chosen  = $this->keys->choose();
		$payload = (string) wp_json_encode(
			array(
				'key'      => (string) ( $credentials['key'] ?? '' ),
				'webhooks' => isset( $credentials['webhooks'] ) ? (string) $credentials['webhooks'] : null,
				'facts'    => isset( $credentials['facts'] ) ? (string) $credentials['facts'] : null,
			)
		);
		$stored  = array(
			'v'      => 2,
			'source' => $chosen['source'],
			'kid'    => $chosen['kid'],
			'check'  => $this->sealWith( self::CHECK, $chosen['key'] ),
			'data'   => $this->sealWith( $payload, $chosen['key'] ),
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
		$key    = is_array( $stored ) ? $this->openingKey( $stored ) : null;
		if ( null === $key ) {
			return null;
		}
		$json = $this->openWith( (string) ( $stored['data'] ?? '' ), $key );
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

	/** `empty` (never connected or disconnected), `ok`, or `unreadable` (the recorded key is lost, or a damaged value). */
	public function state(): string {
		$stored = $this->stored();
		if ( null === $stored ) {
			return self::EMPTY;
		}
		return is_array( $stored ) && null !== $this->openingKey( $stored ) ? self::OK : self::UNREADABLE;
	}

	/**
	 * Why the vault cannot be read: `file_gone` (locked with the key file, which is gone), `keys_changed` (locked with the salts, which
	 * changed), else null (it reads, it is empty, or the stored value is damaged).
	 */
	public function unreadableReason(): ?string {
		$stored = $this->stored();
		if ( ! is_array( $stored ) || null !== $this->openingKey( $stored ) ) {
			return null;
		}
		$source = (string) ( $stored['source'] ?? '' );
		$key    = $this->keys->find( $source );
		if ( VaultKey::FILE === $source && ( null === $key || ! self::sameKid( $stored, $key ) ) ) {
			return self::FILE_GONE;
		}
		return VaultKey::SALTS === $source && null !== $key && ! self::sameKid( $stored, $key ) ? self::KEYS_CHANGED : null;
	}

	/** Forgets everything the vault holds (the key file stays: a reconnect reuses it). */
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

	/**
	 * Encrypts a small value the way a save does (an administrator's pending connect keeps its verifier this way): under a key chosen afresh,
	 * with its source and fingerprint in front (`<source>.<kid>.<sealed>`), so `open()` finds the same key.
	 *
	 * @param string $plain The value.
	 */
	public function seal( string $plain ): string {
		$chosen = $this->keys->choose();
		return $chosen['source'] . '.' . $chosen['kid'] . '.' . $this->sealWith( $plain, $chosen['key'] );
	}

	/**
	 * Opens a value sealed by `seal()`; null when it cannot be opened (its key lost, damaged).
	 *
	 * @param string $sealed The sealed value.
	 */
	public function open( string $sealed ): ?string {
		$parts = explode( '.', $sealed, 3 );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$key = $this->keys->find( $parts[0] );
		return null !== $key && hash_equals( VaultKey::kid( $key ), $parts[1] ) ? $this->openWith( $parts[2], $key ) : null;
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
	 * The key that opens a stored array (found by its recorded source, its check value opening), or null.
	 *
	 * @param array<string,mixed> $stored The stored option.
	 */
	private function openingKey( array $stored ): ?string {
		$key = $this->keys->find( (string) ( $stored['source'] ?? '' ) );
		return null !== $key && self::CHECK === $this->openWith( (string) ( $stored['check'] ?? '' ), $key ) ? $key : null;
	}

	/**
	 * Whether a key is the one the stored record names.
	 *
	 * @param array<string,mixed> $stored The stored option.
	 * @param string              $key    A key.
	 */
	private static function sameKid( array $stored, string $key ): bool {
		return hash_equals( (string) ( $stored['kid'] ?? '' ), VaultKey::kid( $key ) );
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
}
