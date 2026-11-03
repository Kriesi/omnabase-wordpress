<?php
/**
 * A licence's end as another plugin stored it: a date text or a Unix time, read into a day and the moment it ends.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The licence-and-fields round (2026-11-04), with the outside red team's three traps fixed:
 * - a Unix time is a number, or a text of 9 to 11 digits; an 8-digit text that is a real calendar day (`20270312`, ACF's way) is that day;
 * - any other text counts only when it names one fixed moment (`tomorrow` or `+1 week` name another day each day and are unreadable);
 * - zero, a negative number, `0000-00-00` and empty text are unreadable.
 * A date without a time lasts the whole day in the site's time zone. The day is `Y-m-d`, the format of the plugin's other sources: a text by
 * its own date part, a Unix time in the site's zone. Unreadable is null: the licence then stays, without a date (DECISIONS.md).
 */
final class ExpiryDate {

	/** The largest Unix time read (eleven digits: the year 5138); a longer number is no time in seconds. */
	private const MAX_SECONDS = 99999999999;

	/**
	 * The day and the last moment of the licence, or null when the value names no date.
	 *
	 * @param mixed $value The stored value.
	 * @return array{day:string,until:int}|null
	 */
	public static function read( $value ): ?array {
		if ( is_int( $value ) ) {
			return self::fromSeconds( $value );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$text = trim( $value );
		if ( 1 === preg_match( '/^\d{8}$/', $text ) ) {
			return self::fromEightDigits( $text );
		}
		if ( 1 === preg_match( '/^\d{9,11}$/', $text ) ) {
			return self::fromSeconds( (int) $text );
		}
		return self::fromText( $text );
	}

	/**
	 * A Unix time: the exact moment, its day in the site's zone.
	 *
	 * @param int $seconds Seconds since 1970.
	 * @return array{day:string,until:int}|null
	 */
	private static function fromSeconds( int $seconds ): ?array {
		if ( $seconds <= 0 || $seconds > self::MAX_SECONDS ) {
			return null;
		}
		return array( 'day' => (string) wp_date( 'Y-m-d', $seconds ), 'until' => $seconds );
	}

	/**
	 * Eight digits: a calendar day (year, month, day), lasting until its end.
	 *
	 * @param string $digits The digits.
	 * @return array{day:string,until:int}|null
	 */
	private static function fromEightDigits( string $digits ): ?array {
		$year  = (int) substr( $digits, 0, 4 );
		$month = (int) substr( $digits, 4, 2 );
		$day   = (int) substr( $digits, 6, 2 );
		if ( $year < 1 || ! checkdate( $month, $day, $year ) ) {
			return null;
		}
		return self::wholeDay( sprintf( '%04d-%02d-%02d', $year, $month, $day ) );
	}

	/**
	 * A date text: read only when it names one fixed moment; a text without a time lasts the whole day.
	 *
	 * @param string $text The trimmed text.
	 * @return array{day:string,until:int}|null
	 */
	private static function fromText( string $text ): ?array {
		if ( '' === $text || str_starts_with( $text, '0000-00-00' ) || ! self::isFixedMoment( $text ) ) {
			return null;
		}
		try {
			$moment = new \DateTimeImmutable( $text, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
		$day = 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $text, $m ) ? $m[1] : $moment->format( 'Y-m-d' );
		if ( 1 !== preg_match( '/\d:\d\d/', $text ) ) {
			return self::wholeDay( $day );
		}
		return array( 'day' => $day, 'until' => $moment->getTimestamp() );
	}

	/**
	 * Whether a text names the same moment whatever "now" is (PHP's date reader also accepts "tomorrow" and "+1 week").
	 *
	 * @param string $text The text.
	 */
	private static function isFixedMoment( string $text ): bool {
		$early = strtotime( $text, 0 );
		return false !== $early && strtotime( $text, 86400 * 400 ) === $early;
	}

	/**
	 * A day that lasts until 23:59:59 in the site's time zone.
	 *
	 * @param string $day The day, `Y-m-d`.
	 * @return array{day:string,until:int}|null
	 */
	private static function wholeDay( string $day ): ?array {
		$end = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $day . ' 23:59:59', wp_timezone() );
		return false === $end ? null : array( 'day' => $day, 'until' => $end->getTimestamp() );
	}
}
