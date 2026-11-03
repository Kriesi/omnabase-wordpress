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
 * 'product' => <name>, 'condition' => 'any'|'equals'|'contains', 'condition_text' => <text>, 'expiry' => <usermeta key> ] ]`. Every source
 * is off until the administrator turns it on (a missing option is all off). Banned or malformed usermeta keys are never stored as ticked
 * keys, so they can never be sent. The licence field (the licence-sources round, 2026-11-03) may hold a banned word, never one of
 * WordPress's own secrets (`MetaPolicy::isLicenceField`), and its user field is never a ticked key at the same time. The licence-and-fields
 * round (2026-11-04): the licence field and the expiry may be `field.key` (`FieldPath`); a condition without text, or a word the screen never
 * offers, is stored as "any".
 */
final class Switches {

	/** The longest product name stored (the contract's). */
	private const MAX_PRODUCT = 120;

	/** The longest condition text stored. */
	private const MAX_CONDITION_TEXT = 120;

	/** The conditions a licence value can meet. */
	public const CONDITIONS = array( 'any', 'equals', 'contains' );

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
	 * The licence kept in a user field: the field's name, the product's name, the condition with its text and the expiry; empty strings
	 * and "any" when unset.
	 *
	 * @return array{key:string,product:string,condition:string,condition_text:string,expiry:string}
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
	 * @param array<string,mixed> $licence The licence field: `[ 'key', 'product', 'condition', 'condition_text', 'expiry' ]`.
	 */
	public function save( array $known, array $sources, array $meta, array $licence = array() ): void {
		$on      = array_combine( $known, array_map( static fn ( $id ) => in_array( $id, $sources, true ), $known ) );
		$licence = self::licenceSettings( $licence );
		$field   = FieldPath::field( $licence['key'] );
		$keys    = array_values( array_unique( array_filter( $meta, static fn ( $k ) => is_string( $k ) && MetaPolicy::isWellFormed( $k ) && ! MetaPolicy::isBanned( $k ) && $k !== $field ) ) );
		update_option( Options::FACTS, array( 'sources' => $on ? $on : array(), 'meta' => $keys, 'licence_meta' => $licence ), false );
	}

	/**
	 * The licence field's settings as stored: trimmed names the source can read (else empty), a trimmed product name and a condition that
	 * can work (else "any" without text).
	 *
	 * @param mixed $raw The settings as posted or stored.
	 * @return array{key:string,product:string,condition:string,condition_text:string,expiry:string}
	 */
	private static function licenceSettings( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		return array(
			'key'     => self::path( $raw['key'] ?? null ),
			'product' => mb_substr( self::trimmed( $raw['product'] ?? null ), 0, self::MAX_PRODUCT, 'UTF-8' ),
		) + self::condition( $raw['condition'] ?? null, $raw['condition_text'] ?? null ) + array(
			'expiry' => self::path( $raw['expiry'] ?? null ),
		);
	}

	/**
	 * A condition and its text; one without text, or unknown, is "any" (an "equals nothing" must never take every licence away).
	 *
	 * @param mixed $condition The condition word.
	 * @param mixed $text      Its text.
	 * @return array{condition:string,condition_text:string}
	 */
	private static function condition( $condition, $text ): array {
		$text = mb_substr( self::trimmed( $text ), 0, self::MAX_CONDITION_TEXT, 'UTF-8' );
		if ( 'any' === $condition || '' === $text || ! in_array( $condition, self::CONDITIONS, true ) ) {
			return array( 'condition' => 'any', 'condition_text' => '' );
		}
		return array( 'condition' => $condition, 'condition_text' => $text );
	}

	/**
	 * A trimmed user field or `field.key` the source can read, else ''.
	 *
	 * @param mixed $raw The name as posted or stored.
	 */
	private static function path( $raw ): string {
		$path = self::trimmed( $raw );
		return FieldPath::isValid( $path ) ? $path : '';
	}

	/**
	 * A text trimmed, or '' for anything else.
	 *
	 * @param mixed $raw The value.
	 */
	private static function trimmed( $raw ): string {
		return is_string( $raw ) ? trim( $raw ) : '';
	}

	/**
	 * The option, normalised.
	 *
	 * @return array{sources:array<string,bool>,meta:string[],licence_meta:array{key:string,product:string,condition:string,condition_text:string,expiry:string}}
	 */
	private function read(): array {
		$stored  = get_option( Options::FACTS, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$sources = is_array( $stored['sources'] ?? null ) ? array_map( static fn ( $on ) => true === $on || 1 === $on || '1' === $on, $stored['sources'] ) : array();
		$meta    = is_array( $stored['meta'] ?? null ) ? array_values( array_filter( $stored['meta'], 'is_string' ) ) : array();
		return array( 'sources' => $sources, 'meta' => $meta, 'licence_meta' => self::licenceSettings( $stored['licence_meta'] ?? null ) );
	}
}
