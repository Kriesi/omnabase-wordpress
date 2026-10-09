<?php
/**
 * M13 round two (the red team): OmnaBase's reader of a facts answer, written again in PHP for the tests alone, so a test can say "OmnaBase
 * would accept this" without OmnaBase running. It mirrors, rule for rule, `readEndpointAnswer` (OmnaBase `packages/server/src/integrations/
 * endpoint.ts`), the block's schema (`packages/shared/src/siteFacts.ts`, zod 4.6) and the 128 KB the call reads (`guardedPost`). Its
 * fidelity is not taken on trust: `FactsShaperTest` first checks it against `tests/fixtures/site-facts-zod.json`, verdicts made by OmnaBase's
 * own code (`tests/fixtures/site-facts-zod.mts`). This is the tests' judge, never the plugin's code: the plugin's own copy of the rules
 * (`Facts\ContractCheck`) is what these tests examine.
 *
 * The rules, as zod applies them: text is trimmed (JavaScript's trim: every Unicode space) and then counted in code points; a list is cut to
 * its first 20 (roles 20, sites 20) before its items are read; every meta entry is read, even beyond the 30 kept; `null` is never a missing
 * field (only an absent key is); unknown keys are ignored; one bad field refuses the whole answer.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit\Support;

final class SiteFactsOracle {

	public const MAX_BYTES = 131072;

	/**
	 * Null when OmnaBase accepts the answer, else why it refuses it.
	 *
	 * @param string $json The answer's body as sent.
	 */
	public static function refusal( string $json ): ?string {
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return 'too_large';
		}
		try {
			$answer = json_decode( $json, false, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			return 'not JSON';
		}
		if ( ! $answer instanceof \stdClass ) {
			return 'not a JSON object of fields';
		}
		$fields = get_object_vars( $answer );
		$block  = $fields['omnabase'] ?? null;
		unset( $fields['omnabase'], $fields['__proto__'] );
		return self::flat( $fields ) ?? ( null === $block ? null : self::block( $block ) );
	}

	/** Whether OmnaBase accepts the answer. */
	public static function accepts( string $json ): bool {
		return null === self::refusal( $json );
	}

	/** Version 1's flat fields: at most 50, names up to 64 characters (the plugin sends none, so only the counts are mirrored). */
	private static function flat( array $fields ): ?string {
		if ( count( $fields ) > 50 ) {
			return 'more than 50 fields';
		}
		foreach ( array_keys( $fields ) as $k ) {
			if ( self::len( (string) $k ) > 64 ) {
				return 'a field name over 64';
			}
		}
		return null;
	}

	/** @param mixed $b The block. */
	private static function block( $b ): ?string {
		if ( ! $b instanceof \stdClass ) {
			return 'omnabase: expected an object';
		}
		if ( property_exists( $b, 'v' ) && ! ( ( is_int( $b->v ) || is_float( $b->v ) ) && 1 == $b->v ) ) { // phpcs:ignore Universal.Operators.StrictComparisons -- 1 and 1.0 are one JSON number
			return 'omnabase.v: expected 1';
		}
		if ( property_exists( $b, 'profile' ) && null !== $b->profile && null !== ( $why = self::profile( $b->profile ) ) ) {
			return $why;
		}
		$sections = array(
			'orders'        => array( 'number' => array( 60, true ), 'date' => array( 40, false ), 'total' => array( 60, false ), 'status' => array( 60, false ) ),
			'subscriptions' => array( 'product' => array( 120, true ), 'status' => array( 60, false ), 'renewsAt' => array( 40, false ) ),
			'licences'      => array( 'product' => array( 120, true ), 'keyMasked' => array( 120, false ), 'status' => array( 60, false ), 'version' => array( 60, false ), 'expiresAt' => array( 40, false ) ),
		);
		foreach ( $sections as $name => $texts ) {
			if ( ! property_exists( $b, $name ) ) {
				continue;
			}
			if ( ! is_array( $b->$name ) ) {
				return "omnabase.{$name}: expected a list";
			}
			foreach ( array_slice( $b->$name, 0, 20 ) as $i => $item ) {
				$why = self::item( $item, $texts, 'licences' === $name, "omnabase.{$name}.{$i}" );
				if ( null !== $why ) {
					return $why;
				}
			}
		}
		return null;
	}

	/** @param mixed $p The profile. */
	private static function profile( $p ): ?string {
		if ( ! $p instanceof \stdClass ) {
			return 'omnabase.profile: expected an object';
		}
		if ( property_exists( $p, 'registeredAt' ) && null !== ( $why = self::text( $p->registeredAt, 40, false ) ) ) {
			return "omnabase.profile.registeredAt: {$why}";
		}
		if ( property_exists( $p, 'roles' ) ) {
			if ( ! is_array( $p->roles ) ) {
				return 'omnabase.profile.roles: expected a list';
			}
			foreach ( array_slice( $p->roles, 0, 20 ) as $i => $role ) {
				if ( null !== ( $why = self::text( $role, 60, false ) ) ) {
					return "omnabase.profile.roles.{$i}: {$why}";
				}
			}
		}
		if ( property_exists( $p, 'meta' ) ) {
			if ( ! $p->meta instanceof \stdClass ) {
				return 'omnabase.profile.meta: expected a record';
			}
			foreach ( get_object_vars( $p->meta ) as $k => $v ) {
				if ( self::len( self::trim( (string) $k ) ) > 64 ) {
					return 'omnabase.profile.meta: a key over 64';
				}
				$scalar = null === $v || is_bool( $v ) || is_int( $v ) || is_float( $v );
				if ( ! $scalar && null !== self::text( $v, 1000, false ) ) {
					return "omnabase.profile.meta.{$k}: not text up to 1000, a number, true/false or null";
				}
			}
		}
		return null;
	}

	/**
	 * An order, subscription or licence.
	 *
	 * @param mixed                            $item     The item.
	 * @param array<string,array{int,bool}>    $texts    Field => [max, required].
	 * @param bool                             $licence  Whether `sites` applies.
	 * @param string                           $where    The path for the message.
	 */
	private static function item( $item, array $texts, bool $licence, string $where ): ?string {
		if ( ! $item instanceof \stdClass ) {
			return "{$where}: expected an object";
		}
		foreach ( $texts as $field => [ $max, $required ] ) {
			if ( ! property_exists( $item, $field ) ) {
				if ( $required ) {
					return "{$where}.{$field}: expected a string";
				}
				continue;
			}
			if ( null !== ( $why = self::text( $item->$field, $max, $required ) ) ) {
				return "{$where}.{$field}: {$why}";
			}
		}
		if ( property_exists( $item, 'href' ) && ( ! is_string( $item->href ) || self::len( $item->href ) > 2000 ) ) {
			return "{$where}.href: a string up to 2000";
		}
		if ( $licence && property_exists( $item, 'sites' ) ) {
			if ( ! is_array( $item->sites ) ) {
				return "{$where}.sites: expected a list";
			}
			foreach ( array_slice( $item->sites, 0, 20 ) as $i => $site ) {
				if ( null !== ( $why = self::text( $site, 200, false ) ) ) {
					return "{$where}.sites.{$i}: {$why}";
				}
			}
		}
		return null;
	}

	/**
	 * Null when the value is text that fits after trimming.
	 *
	 * @param mixed $v        The value.
	 * @param int   $max      The longest, in code points.
	 * @param bool  $required Whether it must hold at least one character.
	 */
	private static function text( $v, int $max, bool $required ): ?string {
		if ( ! is_string( $v ) ) {
			return 'expected a string';
		}
		$n = self::len( self::trim( $v ) );
		if ( $n > $max ) {
			return "over {$max}";
		}
		return $required && 0 === $n ? 'empty' : null;
	}

	/** JavaScript's String.prototype.trim: white space and line terminators, Unicode's included. */
	private static function trim( string $s ): string {
		return (string) preg_replace( '/^[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+|[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+$/u', '', $s );
	}

	/** Length in code points (zod 4.6 counts code points). */
	private static function len( string $s ): int {
		return (int) mb_strlen( $s, 'UTF-8' );
	}
}
