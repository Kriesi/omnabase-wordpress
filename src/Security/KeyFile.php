<?php
/**
 * The key file: the random key the vault locks the stored connection with, kept in a guarded file the plugin makes once.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Security;

defined( 'ABSPATH' ) || exit;

/**
 * One file per WordPress (one for a whole network): `omnabase/key.php` under wp-content, or under the main site's uploads folder when
 * wp-content cannot be written. The file is a PHP file that ends the request before the key (a browser opening it gets an empty page),
 * with an `index.php` (no folder listing) and a `.htaccess` refusing every request beside it. It is made in one step and private from the
 * start (written to a temporary file with mode 0600, then hard-linked into place, which fails when the file is already there), so two
 * requests at the same moment end up with the same key; once present it is never rewritten. Reading never creates anything and keeps
 * nothing in memory; a file anyone on the server may read still works and is noted in PHP's error log, never on a screen.
 */
final class KeyFile {

	private const FOLDER = 'omnabase';
	private const FILE   = 'key.php';
	private const MARK   = '// omnabase-key ';

	/** Whether this request already noted a key file others may read (once per request is enough for the log). */
	private static bool $noted = false;

	/** The key in the existing file (32 raw bytes), or null when there is none or it cannot be read. Never makes a file. */
	public function read(): ?string {
		foreach ( $this->folders() as $folder ) {
			$file = $folder . '/' . self::FILE;
			if ( is_file( $file ) ) {
				return $this->keyIn( $file );
			}
		}
		return null;
	}

	/** The key in the existing file, else a new key in a new file; null when no file can be made or read (the vault then uses the salts). */
	public function ensure(): ?string {
		$key = $this->read();
		if ( null !== $key ) {
			return $key;
		}
		foreach ( $this->folders() as $folder ) {
			if ( is_file( $folder . '/' . self::FILE ) ) {
				return null; // there, but unreadable for this process: never replaced
			}
			if ( $this->prepare( $folder ) && $this->create( $folder . '/' . self::FILE ) ) {
				return $this->keyIn( $folder . '/' . self::FILE );
			}
		}
		return null;
	}

	/** Removes the key file and its folder wherever they are (the plugin's uninstall, once for the whole network). */
	public function remove(): void {
		foreach ( $this->folders() as $folder ) {
			if ( ! is_dir( $folder ) ) {
				continue;
			}
			foreach ( array( self::FILE, 'index.php', '.htaccess' ) as $name ) {
				if ( is_file( $folder . '/' . $name ) ) {
					wp_delete_file( $folder . '/' . $name );
				}
			}
			foreach ( glob( $folder . '/.key-*.tmp' ) ?: array() as $leftover ) {
				wp_delete_file( $leftover );
			}
			@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- only when empty; a folder holding someone else's files stays.
		}
	}

	/**
	 * Where the folder may be, in order: under wp-content, then under the main site's uploads folder.
	 *
	 * @return string[]
	 */
	private function folders(): array {
		$uploads = untrailingslashit( (string) ( $this->mainUploads()['basedir'] ?? '' ) );
		$folders = array( untrailingslashit( WP_CONTENT_DIR ) . '/' . self::FOLDER );
		if ( '' !== $uploads ) {
			$folders[] = $uploads . '/' . self::FOLDER;
		}
		return array_values( array_unique( $folders ) );
	}

	/**
	 * The main site's uploads folder (a network shares one key file, never one per site's uploads folder).
	 *
	 * @return array<string,mixed>
	 */
	private function mainUploads(): array {
		if ( ! is_multisite() || get_main_site_id() === get_current_blog_id() ) {
			return (array) wp_upload_dir( null, false );
		}
		switch_to_blog( get_main_site_id() );
		$uploads = (array) wp_upload_dir( null, false );
		restore_current_blog();
		return $uploads;
	}

	/**
	 * Makes the folder and its two guards; false when it cannot be written.
	 *
	 * @param string $folder The key folder.
	 */
	private function prepare( string $folder ): bool {
		if ( ! is_dir( $folder ) && ( file_exists( $folder ) || ! wp_is_writable( dirname( $folder ) ) || ! wp_mkdir_p( $folder ) ) ) {
			return false;
		}
		if ( ! wp_is_writable( $folder ) ) {
			return false;
		}
		return $this->guard( $folder . '/index.php', "<?php\n// Silence is golden.\n" ) && $this->guard( $folder . '/.htaccess', self::htaccess() );
	}

	/**
	 * Writes a guard file unless it is there.
	 *
	 * @param string $file    The guard's path.
	 * @param string $content What it holds.
	 */
	private function guard( string $file, string $content ): bool {
		return is_file( $file ) || false !== @file_put_contents( $file, $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- a plain local file; WP_Filesystem may ask for FTP credentials.
	}

	/**
	 * Makes the key file in one step: a private temporary file, hard-linked into place (fails when the file is already there), else moved
	 * into place while it is still absent (file systems without hard links). False when nothing could be made.
	 *
	 * @param string $file The key file's path.
	 */
	private function create( string $file ): bool {
		$temp   = dirname( $file ) . '/.key-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		$handle = @fopen( $temp, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- "x": only a new file; WP_Filesystem cannot create a file only when absent.
		if ( false === $handle ) {
			return false;
		}
		@chmod( $temp, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- private before the key is written into it.
		$written = false !== fwrite( $handle, self::content( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$placed = $written && ( @link( $temp, $file ) || ( ! file_exists( $file ) && @rename( $temp, $file ) ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- a hard link never overwrites; rename only while absent.
		if ( file_exists( $temp ) ) {
			wp_delete_file( $temp );
		}
		return $placed || is_file( $file );
	}

	/**
	 * The key in a file (32 raw bytes), or null when the file holds none; notes in the error log when others may read the file.
	 *
	 * @param string $file The key file.
	 */
	private function keyIn( string $file ): ?string {
		$content = is_readable( $file ) ? (string) @file_get_contents( $file ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- a local file.
		if ( ! preg_match( '~^' . preg_quote( self::MARK, '~' ) . '([A-Za-z0-9+/=]{44})$~m', $content, $match ) ) {
			return null;
		}
		$key = base64_decode( $match[1], true );
		if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			return null;
		}
		$this->noteWhenShared( $file );
		return $key;
	}

	/**
	 * Notes in PHP's error log (never on a screen) when anyone on the server may read the key file; once per request.
	 *
	 * @param string $file The key file.
	 */
	private function noteWhenShared( string $file ): void {
		$mode = (int) @fileperms( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( self::$noted || '\\' === DIRECTORY_SEPARATOR || 0 === ( $mode & 0077 ) ) {
			return;
		}
		self::$noted = true;
		error_log( 'OmnaBase: key_file_readable_by_others (mode ' . decoct( $mode & 0777 ) . ') ' . $file ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the server's log only, never a screen; no key in it.
	}

	/**
	 * The key file's content: a PHP line that ends the request, then the key as base64 text.
	 *
	 * @param string $key 32 raw bytes.
	 */
	private static function content( string $key ): string {
		$note = <<<'NOTE'
<?php
/**
 * OmnaBase for WordPress: the key the site's stored OmnaBase connection is kept with.
 * Keep this file. Deleting or changing it means clicking Connect again. Never share it.
 */
exit;

NOTE;
		return $note . self::MARK . base64_encode( $key ) . "\n";
	}

	/** The folder's `.htaccess`: every request refused (Apache 2.4, and 2.2's older words); server directives, not words for people. */
	private static function htaccess(): string {
		return <<<'HTACCESS'
<IfModule mod_authz_core.c>
	Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
	Order deny,allow
	Deny from all
</IfModule>

HTACCESS;
	}
}
