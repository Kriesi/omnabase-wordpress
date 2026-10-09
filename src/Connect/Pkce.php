<?php
/**
 * The PKCE pair and the state of a connect.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * PKCE (Proof Key for Code Exchange): the site keeps a secret, the verifier, and sends OmnaBase only its fingerprint, the challenge, so a
 * one-time code stolen on the way back is useless without the secret. Made OmnaBase's way: the verifier is 32 random bytes in base64url
 * (43 characters), the challenge base64url(SHA-256(verifier)) without padding (OmnaBase's `challengeOf`). The state is a random value the
 * site sends out and expects back, so a way back the site did not start is recognised.
 */
final class Pkce {

	/** A fresh verifier: 32 random bytes, base64url, 43 characters. */
	public static function verifier(): string {
		return self::base64url( random_bytes( 32 ) );
	}

	/**
	 * The challenge OmnaBase checks the verifier against.
	 *
	 * @param string $verifier The verifier.
	 */
	public static function challenge( string $verifier ): string {
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	/** A fresh state: 24 random bytes, base64url, 32 characters. */
	public static function state(): string {
		return self::base64url( random_bytes( 24 ) );
	}

	/**
	 * URL-safe base64 without padding.
	 *
	 * @param string $bytes The bytes.
	 */
	private static function base64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
