<?php
/**
 * Which key locks the vault: chosen once when the vault saves, found again by what the vault recorded.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Security;

defined( 'ABSPATH' ) || exit;

/**
 * The order on a save: `constant` (a site owner's own 32-byte key, base64, defined as OMNABASE_ENCRYPTION_KEY), else `file` (the random key
 * in the plugin's key file, made on the first save), else `salts` (derived from WordPress's salts, also when those live in the database).
 * The vault records the source and a key id beside what it locked: a 16-character fingerprint of the key (a slice of a keyed hash, never a
 * piece of the key). Finding the key again uses only the recorded source, never creates a file, and keeps nothing in memory.
 */
final class VaultKey {

	public const CONSTANT = 'constant';
	public const FILE     = 'file';
	public const SALTS    = 'salts';

	private const CONTEXT = 'omnabase-vault|';

	/**
	 * Builds the chooser.
	 *
	 * @param KeyFile $file The key file.
	 */
	public function __construct( private KeyFile $file = new KeyFile() ) {}

	/**
	 * The key for a new save (or a seal): the first source that has one, with its key id.
	 *
	 * @return array{source:string,kid:string,key:string}
	 */
	public function choose(): array {
		$constant = $this->fromConstant();
		if ( null !== $constant ) {
			return self::described( self::CONSTANT, $constant );
		}
		$file = $this->file->ensure();
		return null !== $file ? self::described( self::FILE, $file ) : self::described( self::SALTS, $this->fromSalts() );
	}

	/**
	 * The key a source holds today, or null when it has none (the constant gone, the key file gone).
	 *
	 * @param string $source A recorded source.
	 */
	public function find( string $source ): ?string {
		switch ( $source ) {
			case self::CONSTANT:
				return $this->fromConstant();
			case self::FILE:
				return $this->file->read();
			case self::SALTS:
				return $this->fromSalts();
		}
		return null;
	}

	/**
	 * A key's fingerprint: 16 hex characters of a keyed hash, which tell two keys apart and give nothing of either away.
	 *
	 * @param string $key 32 raw bytes.
	 */
	public static function kid( string $key ): string {
		return substr( bin2hex( sodium_crypto_generichash( 'omnabase-kid', $key, 16 ) ), 0, 16 );
	}

	/** The site owner's own key, when defined (32 raw bytes; any other text is hashed into a key). */
	private function fromConstant(): ?string {
		if ( ! defined( 'OMNABASE_ENCRYPTION_KEY' ) || '' === (string) constant( 'OMNABASE_ENCRYPTION_KEY' ) ) {
			return null;
		}
		$value = (string) constant( 'OMNABASE_ENCRYPTION_KEY' );
		$raw   = base64_decode( $value, true );
		return ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) ? $raw : self::derived( $value );
	}

	/** The key derived from WordPress's salts. */
	private function fromSalts(): string {
		return self::derived( wp_salt( 'auth' ) );
	}

	/**
	 * A 32-byte key from some text.
	 *
	 * @param string $text The text.
	 */
	private static function derived( string $text ): string {
		return sodium_crypto_generichash( self::CONTEXT . $text, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * A key with its source and fingerprint.
	 *
	 * @param string $source The source.
	 * @param string $key    32 raw bytes.
	 * @return array{source:string,kid:string,key:string}
	 */
	private static function described( string $source, string $key ): array {
		return array(
			'source' => $source,
			'kid'    => self::kid( $key ),
			'key'    => $key,
		);
	}
}
