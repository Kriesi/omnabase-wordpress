<?php
/**
 * The customer-data switches and the usermeta the administrator ticked.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One option: `[ 'sources' => [ <source id> => true|false ], 'meta' => [ <usermeta key>, … ] ]`. Every source is off until the
 * administrator turns it on (a missing option is all off). Banned or malformed usermeta keys are never stored, so they can never be sent.
 */
final class Switches {

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
	 * Stores exactly the ticked sources (all others off) and the ticked keys that may be sent.
	 *
	 * @param string[] $known   Every source id the plugin has.
	 * @param string[] $sources The ticked source ids.
	 * @param string[] $meta    The ticked usermeta keys.
	 */
	public function save( array $known, array $sources, array $meta ): void {
		$on   = array_combine( $known, array_map( static fn ( $id ) => in_array( $id, $sources, true ), $known ) );
		$keys = array_values( array_unique( array_filter( $meta, static fn ( $k ) => is_string( $k ) && MetaPolicy::isWellFormed( $k ) && ! MetaPolicy::isBanned( $k ) ) ) );
		update_option( Options::FACTS, array( 'sources' => $on ? $on : array(), 'meta' => $keys ), false );
	}

	/**
	 * The option, normalised.
	 *
	 * @return array{sources:array<string,bool>,meta:string[]}
	 */
	private function read(): array {
		$stored  = get_option( Options::FACTS, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$sources = is_array( $stored['sources'] ?? null ) ? array_map( static fn ( $on ) => true === $on || 1 === $on || '1' === $on, $stored['sources'] ) : array();
		$meta    = is_array( $stored['meta'] ?? null ) ? array_values( array_filter( $stored['meta'], 'is_string' ) ) : array();
		return array( 'sources' => $sources, 'meta' => $meta );
	}
}
