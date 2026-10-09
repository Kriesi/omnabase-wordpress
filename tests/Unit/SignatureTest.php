<?php
/**
 * M13 round one, the red team's failing tests: the signature check of the two receivers (OmnaBase signs every call to the site with a
 * shared secret, an HMAC: a checksum only the holder of the secret can make, so the site knows the call is OmnaBase's and unaltered).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): "What exists": "`X-OmnaBase-Timestamp`, `X-OmnaBase-Signature:
 * v1=<hex HMAC-SHA-256 of "<timestamp>.<raw body>">`"; choice 3 "timestamp within 300 seconds, `hash_equals`"; round one's *Red team*:
 * "the signature accepts the docs' example and refuses one changed byte, a 301-second-old timestamp, a missing or `v0=` header".
 * OmnaBase `docs/public-api.md`, "Checking a message: the signature" (the PHP lines: `abs(time() - (int) $ts) <= 300 && hash_equals(...)`).
 * The example signature below was computed with OmnaBase's own `signEndpointCall` recipe (`packages/server/src/integrations/guardedPost.ts`).
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - `OmnaBase\WordPress\Security\Signature::verify( string $secret, string $timestamp, string $raw_body, string $header, ?int $now = null ): bool`
 *   (`$header` is the `X-OmnaBase-Signature` value as received; `$now` defaults to the clock).
 *
 * Why this fails today: there is no `Security\Signature` class.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use OmnaBase\WordPress\Security\Signature;
use PHPUnit\Framework\TestCase as PlainTestCase;

/**
 * What it checks: The site accepts a call only when it carries OmnaBase's signature over exactly the bytes received, made within the last
 *   five minutes.
 * Passes when: the documented example is accepted; the same call with one byte of the body changed, another timestamp, another secret, a
 *   timestamp 301 seconds old (or 301 seconds ahead), no signature, an empty one or one marked `v0=` is refused; exactly 300 seconds is
 *   still accepted.
 * Fails when: a forged or replayed call is taken as OmnaBase's, so anyone could post fake replies into the forum or read customer data;
 *   or a genuine call is refused, so replies from the team never reach the site.
 */
final class SignatureTest extends PlainTestCase {

	private const SECRET = 'whsec_m13RedTeamExampleSecret0001';
	private const TS     = '1791540000';
	private const BODY   = '{"id":"ping_01","type":"ping","data":{}}';
	private const SIG    = 'v1=13d90e5cd5c40e0fee08899215dd3a013b3ef1f79a302262c8cd3acbd8b37ec3'; // node: OmnaBase's signEndpointCall(SECRET, TS, BODY)

	public function test_the_documented_example_is_accepted(): void {
		$this->assertTrue( Signature::verify( self::SECRET, self::TS, self::BODY, self::SIG, (int) self::TS + 10 ) );
	}

	public function test_exactly_five_minutes_is_still_accepted_either_way(): void {
		$this->assertTrue( Signature::verify( self::SECRET, self::TS, self::BODY, self::SIG, (int) self::TS + 300 ) );
		$this->assertTrue( Signature::verify( self::SECRET, self::TS, self::BODY, self::SIG, (int) self::TS - 300 ) );
	}

	/**
	 * @dataProvider refused
	 *
	 * @param string $secret The secret the site holds.
	 * @param string $ts     The timestamp header.
	 * @param string $body   The raw body.
	 * @param string $header The signature header.
	 * @param int    $now    The site's clock.
	 */
	public function test_a_call_that_is_not_exactly_omnabases_is_refused( string $secret, string $ts, string $body, string $header, int $now ): void {
		$this->assertFalse( Signature::verify( $secret, $ts, $body, $header, $now ) );
	}

	/** @return array<string,array{string,string,string,string,int}> */
	public static function refused(): array {
		$now = (int) self::TS + 10;
		return array(
			'one byte of the body changed' => array( self::SECRET, self::TS, str_replace( 'ping_01', 'ping_02', self::BODY ), self::SIG, $now ),
			'a trailing newline added'     => array( self::SECRET, self::TS, self::BODY . "\n", self::SIG, $now ),
			'another timestamp'            => array( self::SECRET, (string) ( (int) self::TS + 1 ), self::BODY, self::SIG, $now ),
			'another secret'               => array( 'whsec_someoneElsesSecret000001', self::TS, self::BODY, self::SIG, $now ),
			'301 seconds old'              => array( self::SECRET, self::TS, self::BODY, self::SIG, (int) self::TS + 301 ),
			'301 seconds ahead'            => array( self::SECRET, self::TS, self::BODY, self::SIG, (int) self::TS - 301 ),
			'no signature'                 => array( self::SECRET, self::TS, self::BODY, '', $now ),
			'no timestamp'                 => array( self::SECRET, '', self::BODY, self::SIG, $now ),
			'the v0 scheme'                => array( self::SECRET, self::TS, self::BODY, 'v0=' . substr( self::SIG, 3 ), $now ),
			'the bare hex without v1='     => array( self::SECRET, self::TS, self::BODY, substr( self::SIG, 3 ), $now ),
			'upper-case hex'               => array( self::SECRET, self::TS, self::BODY, 'v1=' . strtoupper( substr( self::SIG, 3 ) ), $now ),
			'an empty secret'              => array( '', self::TS, self::BODY, 'v1=' . hash_hmac( 'sha256', self::TS . '.' . self::BODY, '' ), $now ),
		);
	}
}
