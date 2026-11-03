<?php
/**
 * The customer-data switches, the usermeta the administrator ticked and the licence kept in a user field.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One option: `[ 'sources' => [ <source id> => true|false ], 'meta' => [ <usermeta key>, … ], 'licence_meta' => [ 'key' => <usermeta key>,
 * 'product' => <name> ] ]`. Every source is off until the administrator turns it on (a missing option is all off). Banned or malformed
 * usermeta keys are never stored as ticked keys, so they can never be sent. The licence field (the licence-sources round, 2026-11-03) may
 * hold a banned word, never one of WordPress's own secrets (`MetaPolicy::isLicenceField`), and is never a ticked key at the same time.
 */
final class Switches {

	/** The longest product name stored (the contract's). */
	private const MAX_PRODUCT = 120;

	/**
	 * Whether a source is on.
	 *
	 * @param string $source A source id.
	 */
	public function isOn( string $source ): bool {
		return true === ( $this->read()['sources'][ $source ] ?? false );
	}

	/** Whether any source is on. */
	public function anyOn(): bool {
		return in_array( true, $this->read()['sources'], true );
	}

	/**
	 * The ticked usermeta keys.
	 *
	 * @return string[]
	 */
	public function meta(): array {
		return $this->read()['meta'];
	}

	/**
	 * The licence kept in a user field: the field's name and the product's name, empty strings when unset.
	 *
	 * @return array{key:string,product:string}
	 */
	public function licenceMeta(): array {
		return $this->read()['licence_meta'];
	}

	/**
	 * Stores exactly the ticked sources (all others off), the ticked keys that may be sent and the licence field's settings.
	 *
	 * @param string[]            $known   Every source id the plugin has.
	 * @param string[]            $sources The ticked source ids.
	 * @param string[]            $meta    The ticked usermeta keys.
	 * @param array<string,mixed> $licence The licence field: `[ 'key' => …, 'product' => … ]`.
	 */
	public function save( array $known, array $sources, array $meta, array $licence = array() ): void {
		$on      = array_combine( $known, array_map( static fn ( $id ) => in_array( $id, $sources, true ), $known ) );
		$licence = self::licenceSettings( $licence );
		$keys    = array_values( array_unique( array_filter( $meta, static fn ( $k ) => is_string( $k ) && MetaPolicy::isWellFormed( $k ) && ! MetaPolicy::isBanned( $k ) && $k !== $licence['key'] ) ) );
		update_option( Options::FACTS, array( 'sources' => $on ? $on : array(), 'meta' => $keys, 'licence_meta' => $licence ), false );
	}

	/**
	 * The licence field's settings as stored: a trimmed name the policy accepts (else empty) and a trimmed product name.
	 *
	 * @param mixed $raw The settings as posted or stored.
	 * @return array{key:string,product:string}
	 */
	private static function licenceSettings( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : array();
		$key     = is_string( $raw['key'] ?? null ) ? trim( $raw['key'] ) : '';
		$product = is_string( $raw['product'] ?? null ) ? trim( $raw['product'] ) : '';
		return array(
			'key'     => MetaPolicy::isLicenceField( $key ) ? $key : '',
			'product' => mb_substr( $product, 0, self::MAX_PRODUCT, 'UTF-8' ),
		);
	}

	/**
	 * The option, normalised.
	 *
	 * @return array{sources:array<string,bool>,meta:string[],licence_meta:array{key:string,product:string}}
	 */
	private function read(): array {
		$stored  = get_option( Options::FACTS, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$sources = is_array( $stored['sources'] ?? null ) ? array_map( static fn ( $on ) => true === $on || 1 === $on || '1' === $on, $stored['sources'] ) : array();
		$meta    = is_array( $stored['meta'] ?? null ) ? array_values( array_filter( $stored['meta'], 'is_string' ) ) : array();
		return array( 'sources' => $sources, 'meta' => $meta, 'licence_meta' => self::licenceSettings( $stored['licence_meta'] ?? null ) );
	}
}
