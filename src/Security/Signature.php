<?php
/**
 * OmnaBase's signature on a call to the site.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Checks `X-OmnaBase-Signature: v1=<hex HMAC-SHA-256 of "<timestamp>.<raw body>">` with the shared secret, the way OmnaBase's
 * `docs/public-api.md` shows it in PHP: the timestamp within five minutes of the site's clock (either way), compared with `hash_equals`.
 * The body is the raw bytes as received; one changed byte, another scheme than `v1=` or upper-case hex fails.
 */
final class Signature {

	public const WINDOW_SECONDS = 300;

	/**
	 * Whether a call carries OmnaBase's signature over exactly these bytes, made within the window.
	 *
	 * @param string   $secret    The secret the site holds for this receiver.
	 * @param string   $timestamp The `X-OmnaBase-Timestamp` header (Unix seconds).
	 * @param string   $raw_body  The body exactly as received.
	 * @param string   $header    The `X-OmnaBase-Signature` header as received.
	 * @param int|null $now       The site's clock (now by default).
	 */
	public static function verify( string $secret, string $timestamp, string $raw_body, string $header, ?int $now = null ): bool {
		if ( '' === $secret || '' === $header || ! ctype_digit( $timestamp ) ) {
			return false;
		}
		if ( abs( ( $now ?? time() ) - (int) $timestamp ) > self::WINDOW_SECONDS ) {
			return false;
		}
		return hash_equals( 'v1=' . hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret ), $header );
	}
}
