<?php
/**
 * M13 round one, the red team's failing tests: the PKCE pair (the plugin keeps a secret, the "verifier", and sends only its hash, the
 * "challenge", so a stolen one-time code is useless without the secret) and the state (a random value the plugin sends out and expects
 * back, so a forged return is recognised).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): choice 3 "The plugin makes the verifier (32 random bytes, base64url) and
 * the state"; "What exists": "the challenge is base64url(SHA-256(verifier)), 43 characters, the verifier 43–128"; round one's *Red team*:
 * "the PKCE pair matches OmnaBase's recipe". OmnaBase's recipe is `createHash("sha256").update(verifier).digest("base64url")`
 * (`apps/api/src/public/connect.ts`, `challengeOf`); the expected value below was computed with exactly that line.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - `OmnaBase\WordPress\Connect\Pkce` with static `verifier(): string`, `challenge( string $verifier ): string`, `state(): string`.
 *
 * Why this fails today: there is no `Connect\Pkce` class.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use OmnaBase\WordPress\Connect\Pkce;
use PHPUnit\Framework\TestCase as PlainTestCase;

/**
 * What it checks: The secret the site keeps and the fingerprint it sends to OmnaBase are made the way OmnaBase checks them.
 * Passes when: a known secret gives exactly the fingerprint OmnaBase's own code computes; a fresh secret is 43 URL-safe characters
 *   (32 random bytes) and differs every time; its fingerprint is 43 URL-safe characters without padding; the state is URL-safe and
 *   differs every time.
 * Fails when: the fingerprint is computed another way (hex, padded, a different hash), so OmnaBase refuses every exchange and no site can
 *   connect; or the secret or state repeats, so a code or a return link could be replayed by someone who saw an earlier one.
 */
final class PkceTest extends PlainTestCase {

	private const KNOWN_VERIFIER  = 'dBjftJeZ4CVP-mJ92K9pDdKm6P0-3sWZUjcN-oM9_Wk';
	private const KNOWN_CHALLENGE = 'laT4uhNwtKG28QO0RbpUgAYEotBkjmTtjKTINFmvsN0'; // node: createHash("sha256").update(v).digest("base64url")

	public function test_a_known_verifier_gives_omnabases_challenge(): void {
		$this->assertSame( self::KNOWN_CHALLENGE, Pkce::challenge( self::KNOWN_VERIFIER ) );
	}

	public function test_a_fresh_verifier_is_32_random_bytes_in_base64url(): void {
		$a = Pkce::verifier();
		$b = Pkce::verifier();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $a, 'base64url of 32 bytes, no padding: 43 characters' );
		$this->assertSame( 32, strlen( (string) base64_decode( strtr( $a, '-_', '+/' ), true ) ) );
		$this->assertNotSame( $a, $b, 'a new secret every time' );
	}

	public function test_the_challenge_is_43_urlsafe_characters_without_padding(): void {
		$challenge = Pkce::challenge( Pkce::verifier() );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $challenge, 'what OmnaBase\'s approval accepts as S256' );
	}

	public function test_the_state_is_urlsafe_long_enough_and_fresh(): void {
		$a = Pkce::state();
		$b = Pkce::state();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{16,128}$/', $a, 'URL-safe, at least 16 characters' );
		$this->assertNotSame( $a, $b );
	}
}
