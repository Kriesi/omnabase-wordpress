<?php
/**
 * M13 round four, the red team's failing tests: the time-token route (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 8: "On load the form's JS fetches a **single-use time token** from the
 * plugin; the token's id becomes the submission's `Idempotency-Key` (a double click is one ticket; a cached page never shares one)"; choice 10:
 * "Every REST route is signed … or protected (… the time-token route: limits)"; choice 1: "every `register_rest_route` with a real
 * `permission_callback` (named exceptions: … the form and time-token routes)". The coordinator: "`GET /wp-json/omnabase/v1/form-token` on
 * load: single-use, …, bound to the project".
 *
 * Shapes the red team pins: `RoundFourTestCase`. (chosen) The answer tells caches not to keep it (`Cache-Control` with `no-store` or
 * `no-cache`); a token is handed out only for a project the form may use (the key's projects); "limits" means one address cannot fetch
 * tokens without end: of 100 in a row, at least one is refused with 429, and another address still gets one.
 *
 * Why these fail today: the route does not exist (WordPress answers 404 `rest_no_route`).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Security\Vault;

final class FormTokenTest extends RoundFourTestCase {

	/**
	 * What it checks: Every page view gets its own token, and caches are told not to keep the answer.
	 * Passes when: two calls to the time-token route answer 200 with two different, non-empty tokens, each with a Cache-Control header saying
	 *   no-store or no-cache.
	 * Fails when: two visitors share a token (the second one's message refused or merged into the first's), or a cache in front of the site
	 *   hands out one token to everybody.
	 */
	public function test_each_view_gets_its_own_token_and_caches_keep_none(): void {
		$a = $this->tokenResponse();
		$b = $this->tokenResponse();
		foreach ( array( $a, $b ) as $r ) {
			$this->assertSame( 200, $r->get_status(), (string) wp_json_encode( $r->get_data() ) );
			$headers = array_change_key_case( $r->get_headers(), CASE_LOWER );
			$this->assertMatchesRegularExpression( '/no-store|no-cache/i', (string) ( $headers['cache-control'] ?? '' ), 'caches told not to keep it' );
		}
		$ta = (string) ( $a->get_data()['token'] ?? '' );
		$tb = (string) ( $b->get_data()['token'] ?? '' );
		$this->assertNotSame( '', $ta );
		$this->assertNotSame( $ta, $tb );
	}

	/**
	 * What it checks: Tokens are handed out only for a form that can send: a project of the site's key, on a connected site.
	 * Passes when: asking for a token for "photoshop" (not one of the key's projects) and asking on a site that is not connected are both
	 *   refused (a 4xx answer without a token).
	 * Fails when: anyone can collect tokens for forms that do not exist, a cheap way to fill the site's storage.
	 */
	public function test_tokens_only_for_a_project_the_form_may_use(): void {
		$r = $this->tokenResponse( 'photoshop' );
		$this->assertGreaterThanOrEqual( 400, $r->get_status() );
		$this->assertLessThan( 500, $r->get_status() );
		$this->assertArrayNotHasKey( 'token', (array) $r->get_data() );

		( new Vault() )->clear();
		delete_option( 'omnabase_site' );
		$r = $this->tokenResponse( 'enfold' );
		$this->assertGreaterThanOrEqual( 400, $r->get_status() );
		$this->assertArrayNotHasKey( 'token', (array) $r->get_data() );
	}

	/**
	 * What it checks: One network address cannot fetch tokens without end (the brief's "the time-token route: limits").
	 * Passes when: of 100 token requests in a row from one address at least one is refused with 429, and a request from another address right
	 *   after gets a token.
	 * Fails when: a bot fills the site's database with millions of tokens, or one busy address blocks the form for everybody.
	 */
	public function test_one_address_cannot_fetch_tokens_without_end(): void {
		$refused = 0;
		for ( $i = 0; $i < 100; $i++ ) {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
			if ( 429 === $this->tokenResponse()->get_status() ) {
				++$refused;
			}
		}
		$this->assertGreaterThan( 0, $refused, 'some of the 100 refused' );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.21';
		$this->assertSame( 200, $this->tokenResponse()->get_status(), 'another address still gets a token' );
	}
}
