<?php
/**
 * The site's OmnaBase key, as round zero stores it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Api;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Round zero only: the key in a plain, non-autoloaded option, written by the wp-env test harness. Round one replaces this class with
 * the vault (the key and both secrets encrypted, read nowhere else), so nothing outside reads the option directly.
 */
final class StoredKey {

	/** The stored key, or null when the site is not connected. */
	public function get(): ?string {
		$stored = get_option( Options::CONNECTION, array() );
		$key    = is_array( $stored ) && isset( $stored['key'] ) ? (string) $stored['key'] : '';
		return '' === $key ? null : $key;
	}

	/**
	 * Stores a key.
	 *
	 * @param string $key The `obw_…` key.
	 */
	public function save( string $key ): void {
		update_option( Options::CONNECTION, array( 'key' => $key ), false );
	}

	/** Forgets the key. */
	public function forget(): void {
		delete_option( Options::CONNECTION );
	}
}
