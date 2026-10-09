<?php
/**
 * One field of a facts answer, fitted to OmnaBase's contract: text, links, licence keys, usermeta values.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers, the rules of OmnaBase's `siteFacts.ts` field by field. Text is repaired (invalid UTF-8), stripped of HTML, trimmed as
 * JavaScript trims and cut to the contract's length in code points (zod counts code points), so OmnaBase never refuses it for its length.
 * A value that cannot be text (a list, an object, an infinite number) is null: the caller leaves the field out.
 */
final class Field {

	/** JavaScript's String.prototype.trim: white space and line terminators, Unicode's included. */
	private const SPACE = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

	public const MAX_HREF = 2000;

	/**
	 * Text of at most `$max` code points, or null when the value is no text (or empty while required).
	 *
	 * @param mixed $value    The raw value.
	 * @param int   $max      The contract's longest.
	 * @param bool  $required Whether an empty value is refused.
	 */
	public static function text( $value, int $max, bool $required = false ): ?string {
		if ( is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$text = self::trim( wp_strip_all_tags( wp_check_invalid_utf8( $value, true ) ) );
		$text = self::trim( mb_substr( $text, 0, $max, 'UTF-8' ) );
		return $required && '' === $text ? null : $text;
	}

	/**
	 * An https link of at most 2 000 characters, or null (OmnaBase drops other links anyway, and refuses a longer one).
	 *
	 * @param mixed $value The raw link.
	 */
	public static function href( $value ): ?string {
		if ( ! is_string( $value ) || '' === $value || mb_strlen( $value, 'UTF-8' ) > self::MAX_HREF ) {
			return null;
		}
		$url = esc_url_raw( trim( $value ) );
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && mb_strlen( $url, 'UTF-8' ) <= self::MAX_HREF ? $url : null;
	}

	/**
	 * A licence key as OmnaBase shows it: `••••-` and its last four characters; a key that is masked already stays as it is.
	 *
	 * @param mixed $value The key, full or masked.
	 */
	public static function maskedKey( $value ): ?string {
		$key = self::text( $value, 1000 );
		if ( null === $key || '' === $key ) {
			return null;
		}
		if ( 1 === preg_match( '/^••••-?.{0,4}$/u', $key ) ) {
			return $key;
		}
		return mb_strlen( $key, 'UTF-8' ) <= 4 ? '••••' : '••••-' . mb_substr( $key, -4, null, 'UTF-8' );
	}

	/**
	 * A usermeta value OmnaBase reads (text up to 1 000, a finite number, true/false or null), or the reason it cannot be one.
	 *
	 * @param mixed $value The stored value.
	 * @return array{0:bool,1:mixed} Whether it can be sent, and the value to send.
	 */
	public static function metaValue( $value ): array {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return array( true, $value );
		}
		if ( is_float( $value ) ) {
			return array( is_finite( $value ), $value );
		}
		$text = is_string( $value ) ? self::text( $value, 1000 ) : null;
		return array( null !== $text, $text );
	}

	/**
	 * Trims as JavaScript does.
	 *
	 * @param string $text The text.
	 */
	public static function trim( string $text ): string {
		return (string) preg_replace( '/^' . self::SPACE . '+|' . self::SPACE . '+$/u', '', $text );
	}
}
