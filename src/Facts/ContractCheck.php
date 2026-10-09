<?php
/**
 * The plugin's own copy of OmnaBase's facts contract: which sections of a block OmnaBase would refuse.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a block as OmnaBase's reader does (`siteFacts.ts`, zod): text trimmed and counted in code points, the first 20 items a list read,
 * every meta entry read, `null` never a missing field. The shaper already writes every field to these rules; this check is the last word
 * before sending, so a section that would still fail is left out (and named in the log line) instead of refusing the whole block.
 */
final class ContractCheck {

	private const TEXTS = array(
		'orders'        => array( 'number' => array( 60, true ), 'date' => array( 40, false ), 'total' => array( 60, false ), 'status' => array( 60, false ) ),
		'subscriptions' => array( 'product' => array( 120, true ), 'status' => array( 60, false ), 'renewsAt' => array( 40, false ) ),
		'licences'      => array( 'product' => array( 120, true ), 'keyMasked' => array( 120, false ), 'status' => array( 60, false ), 'version' => array( 60, false ), 'expiresAt' => array( 40, false ) ),
	);

	/**
	 * The sections OmnaBase would refuse.
	 *
	 * @param array<string,mixed> $block The shaped block (sections by name).
	 * @return string[]
	 */
	public function failing( array $block ): array {
		$failing = array();
		foreach ( $block as $name => $section ) {
			$json = wp_json_encode( $section );
			$read = false === $json ? null : json_decode( $json );
			$ok   = false !== $json && ( 'profile' === $name ? $this->profileOk( $read ) : $this->listOk( $read, self::TEXTS[ $name ] ?? array(), 'licences' === $name ) );
			if ( ! $ok ) {
				$failing[] = (string) $name;
			}
		}
		return $failing;
	}

	/**
	 * A profile: an object (or null) with registeredAt, roles and meta of the right shapes.
	 *
	 * @param mixed $p The profile as OmnaBase decodes it.
	 */
	private function profileOk( $p ): bool {
		if ( null === $p ) {
			return true;
		}
		if ( ! $p instanceof \stdClass ) {
			return false;
		}
		if ( property_exists( $p, 'registeredAt' ) && ! self::text( $p->registeredAt, 40, false ) ) {
			return false;
		}
		if ( property_exists( $p, 'roles' ) && ! ( is_array( $p->roles ) && self::allTexts( array_slice( $p->roles, 0, 20 ), 60 ) ) ) {
			return false;
		}
		return ! property_exists( $p, 'meta' ) || $this->metaOk( $p->meta );
	}

	/**
	 * Meta: an object of short keys and scalar values.
	 *
	 * @param mixed $meta The meta as decoded.
	 */
	private function metaOk( $meta ): bool {
		if ( ! $meta instanceof \stdClass ) {
			return false;
		}
		foreach ( get_object_vars( $meta ) as $key => $value ) {
			$scalar = null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value );
			if ( mb_strlen( Field::trim( (string) $key ), 'UTF-8' ) > MetaPolicy::MAX_KEY || ( ! $scalar && ! self::text( $value, 1000, false ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A list of items with their text fields, links and (licences) sites.
	 *
	 * @param mixed                             $list    The list as decoded.
	 * @param array<string,array{0:int,1:bool}> $texts   Field => [ longest, required ].
	 * @param bool                              $licence Whether `sites` applies.
	 */
	private function listOk( $list, array $texts, bool $licence ): bool {
		if ( ! is_array( $list ) ) {
			return false;
		}
		foreach ( array_slice( $list, 0, 20 ) as $item ) {
			if ( ! $item instanceof \stdClass ) {
				return false;
			}
			foreach ( $texts as $field => [ $max, $required ] ) {
				$present = property_exists( $item, $field );
				if ( ( $required && ! $present ) || ( $present && ! self::text( $item->$field, $max, $required ) ) ) {
					return false;
				}
			}
			if ( property_exists( $item, 'href' ) && ! ( is_string( $item->href ) && mb_strlen( $item->href, 'UTF-8' ) <= Field::MAX_HREF ) ) {
				return false;
			}
			if ( $licence && property_exists( $item, 'sites' ) && ! ( is_array( $item->sites ) && self::allTexts( array_slice( $item->sites, 0, 20 ), 200 ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether every value is text that fits.
	 *
	 * @param array<int,mixed> $values The values.
	 * @param int              $max    The longest.
	 */
	private static function allTexts( array $values, int $max ): bool {
		foreach ( $values as $value ) {
			if ( ! self::text( $value, $max, false ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is text that fits after trimming.
	 *
	 * @param mixed $value    The value.
	 * @param int   $max      The longest, in code points.
	 * @param bool  $required Whether it must hold a character.
	 */
	private static function text( $value, int $max, bool $required ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}
		$length = mb_strlen( Field::trim( $value ), 'UTF-8' );
		return $length <= $max && ( ! $required || $length > 0 );
	}
}
