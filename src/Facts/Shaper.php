<?php
/**
 * The shaper: every customer-data answer written to OmnaBase's rules before it is sent.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * OmnaBase reads the site's `omnabase` block strictly: one field of the wrong type or length refuses the whole block (`siteFacts.ts`). So
 * each section is fitted field by field (text cut to its length, list and object usermeta values left out, banned usermeta never, licence
 * keys masked, the newest 20 items a list), checked once more by the plugin's own copy of the contract (a section that would still fail is
 * left out), and the whole answer is kept under 128 KB as WordPress sends it (every accented letter a six-byte escape): when it is larger,
 * the profile goes first, then subscriptions, then orders, licences last (what OmnaBase copies on a refresh). Nothing found is `{}`.
 * `shape()` answers `[ 'answer' => <object to send>, 'dropped' => <names of the sections left out> ]` for the log line.
 */
final class Shaper {

	/** 128 KB, less a little room for anything WordPress adds to the response body. */
	public const BUDGET = 128 * 1024 - 1024;

	public const ITEMS = 20;

	/** The order in which sections are left out of an answer that is too large. */
	/** The log's name for "the oldest licences left out" (the screen words it, `Admin\FactsView`). */
	public const OLDEST_LICENCES = 'licences_oldest';

	private const DROP_ORDER = array( 'profile', 'subscriptions', 'orders', 'licences' );

	private const ORDER        = array( 'number' => array( 60, true ), 'date' => array( 40, false ), 'total' => array( 60, false ), 'status' => array( 60, false ) );
	private const SUBSCRIPTION = array( 'product' => array( 120, true ), 'status' => array( 60, false ), 'renewsAt' => array( 40, false ) );
	private const LICENCE      = array( 'product' => array( 120, true ), 'status' => array( 60, false ), 'version' => array( 60, false ), 'expiresAt' => array( 40, false ) );

	private ContractCheck $check;

	/** Builds the shaper. */
	public function __construct() {
		$this->check = new ContractCheck();
	}

	/**
	 * The answer to send, and the sections left out.
	 *
	 * @param array<string,mixed> $facts What the switched-on sources produced, in the contract's field names (lists newest first).
	 * @return array{answer:object,dropped:string[]}
	 */
	public function shape( array $facts ): array {
		$block   = $this->sections( $facts );
		$dropped = array();
		foreach ( $this->check->failing( $block ) as $section ) {
			unset( $block[ $section ] );
			$dropped[] = $section;
		}
		[ $block, $too_large ] = $this->fit( $block );
		return array( 'answer' => self::answer( $block ), 'dropped' => array_merge( $dropped, $too_large ) );
	}

	/**
	 * Each section present in the raw facts, fitted.
	 *
	 * @param array<string,mixed> $facts The raw facts.
	 * @return array<string,mixed>
	 */
	private function sections( array $facts ): array {
		$block = array();
		if ( array_key_exists( 'profile', $facts ) ) {
			$block['profile'] = $this->profile( $facts['profile'] );
		}
		$lists = array(
			'orders'        => fn ( $item ) => $this->item( $item, self::ORDER ),
			'subscriptions' => fn ( $item ) => $this->item( $item, self::SUBSCRIPTION ),
			'licences'      => fn ( $item ) => $this->licence( $item ),
		);
		foreach ( $lists as $name => $fit ) {
			if ( array_key_exists( $name, $facts ) ) {
				$block[ $name ] = $this->list( $facts[ $name ], $fit );
			}
		}
		return $block;
	}

	/**
	 * The profile: registered since, roles, the usermeta that may be sent.
	 *
	 * @param mixed $raw The raw profile.
	 */
	private function profile( $raw ): ?object {
		if ( ! is_array( $raw ) && ! is_object( $raw ) ) {
			return null;
		}
		$raw     = (array) $raw;
		$profile = array();
		$since   = Field::text( $raw['registeredAt'] ?? null, 40 );
		if ( null !== $since && '' !== $since ) {
			$profile['registeredAt'] = $since;
		}
		if ( isset( $raw['roles'] ) && is_array( $raw['roles'] ) ) {
			$roles            = array_map( static fn ( $role ) => is_string( $role ) && Field::trim( $role ) !== '' && mb_strlen( Field::trim( $role ), 'UTF-8' ) <= 60 ? Field::trim( $role ) : null, $raw['roles'] );
			$profile['roles'] = array_slice( array_values( array_filter( $roles, 'is_string' ) ), 0, self::ITEMS );
		}
		$meta = $this->meta( $raw['meta'] ?? null );
		if ( array() !== $meta ) {
			$profile['meta'] = (object) $meta;
		}
		return (object) $profile;
	}

	/**
	 * The usermeta that may be sent: banned names never, list and object values never, at most 30 keys of at most 64 characters.
	 *
	 * @param mixed $raw The raw meta map.
	 * @return array<string,mixed>
	 */
	private function meta( $raw ): array {
		$meta = array();
		foreach ( is_array( $raw ) || is_object( $raw ) ? (array) $raw : array() as $key => $value ) {
			$key = Field::trim( (string) $key );
			if ( '' === $key || mb_strlen( $key, 'UTF-8' ) > MetaPolicy::MAX_KEY || MetaPolicy::isBanned( $key ) ) {
				continue;
			}
			[ $ok, $value ] = Field::metaValue( $value );
			if ( $ok ) {
				$meta[ $key ] = $value;
			}
			if ( count( $meta ) >= 30 ) {
				break;
			}
		}
		return $meta;
	}

	/**
	 * A list's items fitted, the broken ones left out, the newest 20 kept.
	 *
	 * @param mixed    $raw The raw list.
	 * @param callable $fit Fits one item, or answers null for a broken one.
	 * @return array<int,object>
	 */
	private function list( $raw, callable $fit ): array {
		$out = array();
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			$fitted = $fit( $item );
			if ( null !== $fitted ) {
				$out[] = $fitted;
			}
			if ( count( $out ) >= self::ITEMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * An order or subscription: its text fields and its link.
	 *
	 * @param mixed                            $raw    The raw item.
	 * @param array<string,array{0:int,1:bool}> $fields Field => [ longest, required ].
	 */
	private function item( $raw, array $fields ): ?object {
		if ( ! is_array( $raw ) && ! is_object( $raw ) ) {
			return null;
		}
		$raw  = (array) $raw;
		$item = array();
		foreach ( $fields as $field => [ $max, $required ] ) {
			$text = Field::text( $raw[ $field ] ?? null, $max, $required );
			if ( null === $text && $required ) {
				return null;
			}
			if ( null !== $text && ( '' !== $text || $required ) ) {
				$item[ $field ] = $text;
			}
		}
		$href = Field::href( $raw['href'] ?? null );
		if ( null !== $href ) {
			$item['href'] = $href;
		}
		return (object) $item;
	}

	/**
	 * A licence: an item with its key masked and its sites.
	 *
	 * @param mixed $raw The raw licence.
	 */
	private function licence( $raw ): ?object {
		$licence = $this->item( $raw, self::LICENCE );
		if ( null === $licence ) {
			return null;
		}
		$raw = (array) $raw;
		$key = Field::maskedKey( $raw['keyMasked'] ?? null );
		if ( null !== $key ) {
			$licence->keyMasked = $key;
		}
		if ( isset( $raw['sites'] ) && is_array( $raw['sites'] ) ) {
			$sites          = array_map( static fn ( $site ) => Field::text( $site, 200 ), $raw['sites'] );
			$licence->sites = array_slice( array_values( array_filter( $sites, static fn ( $site ) => null !== $site && '' !== $site ) ), 0, self::ITEMS );
		}
		return $licence;
	}

	/**
	 * The block under 128 KB as sent: whole sections left out in the drop order, then, if the licences alone are too large, their oldest.
	 *
	 * @param array<string,mixed> $block The fitted block.
	 * @return array{0:array<string,mixed>,1:string[]}
	 */
	private function fit( array $block ): array {
		$dropped = array();
		foreach ( self::DROP_ORDER as $section ) {
			if ( self::size( $block ) <= self::BUDGET ) {
				return array( $block, $dropped );
			}
			if ( 'licences' !== $section && array_key_exists( $section, $block ) ) {
				unset( $block[ $section ] );
				$dropped[] = $section;
			}
		}
		while ( self::size( $block ) > self::BUDGET && ! empty( $block['licences'] ) ) {
			array_pop( $block['licences'] );
			$dropped = array_values( array_unique( array_merge( $dropped, array( self::OLDEST_LICENCES ) ) ) );
		}
		return array( $block, $dropped );
	}

	/**
	 * The answer's size as WordPress's REST server sends it (no flags: `\uXXXX` and `\/` escapes).
	 *
	 * @param array<string,mixed> $block The block.
	 */
	private static function size( array $block ): int {
		$json = wp_json_encode( self::answer( $block ) );
		return false === $json ? PHP_INT_MAX : strlen( $json );
	}

	/**
	 * The whole answer: `{ "omnabase": { "v": 1, … } }`, or `{}` when no section is left.
	 *
	 * @param array<string,mixed> $block The block.
	 */
	private static function answer( array $block ): object {
		return array() === $block ? new \stdClass() : (object) array( 'omnabase' => (object) ( array( 'v' => 1 ) + $block ) );
	}
}
