<?php
/**
 * The customer sidebar round (OmnaBase's brief `docs/briefs/customer-sidebar-round.md`, rule 2 "the facts `profile` may carry `country` …";
 * report `docs/reviews/customer-sidebar-round-red-team-report.md`, finding 16), the red team's failing tests: the shaper, which fits every
 * customer-data answer to OmnaBase's rules before it is sent, now carries the customer's country in the profile.
 *
 * The words: the *shaper* writes the answer to OmnaBase's rules (lengths, shapes) before it leaves the site; the *profile* is the account part
 * of the answer (registered since, roles, ticked user fields); the *contract check* is the plugin's own copy of OmnaBase's reader, the last
 * word before sending; an *ISO country code* is the standard two-letter form ("AT" for Austria).
 *
 * Shapes the red team pins (the builder may rename and tell the red team):
 * - `profile.country` passes the shaper as two upper-case letters ("at" → "AT"); anything else (a name, three letters, an empty value, a list)
 *   is left out, never sent and never a reason to drop the profile.
 * - The contract check accepts a profile with `country` (text up to 60, OmnaBase's reader since this round).
 * - The plugin sends no `language` of its own (chosen, finding 16: a WordPress user's language is usually the site's, not the customer's).
 *
 * Why these fail today: the shaper keeps only registeredAt, roles and meta, so a country handed to it is dropped.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Facts\ContractCheck;
use OmnaBase\WordPress\Facts\Shaper;
use OmnaBase\WordPress\Tests\Unit\Support\SiteFactsOracle;

final class BillingCountryShaperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'wp_check_invalid_utf8' )->alias( static fn ( $s, $strip = false ) => mb_check_encoding( (string) $s, 'UTF-8' ) ? (string) $s : '' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( (string) $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	/** The shaped profile as OmnaBase decodes it. */
	private function profileOf( array $profile ): ?object {
		$result = ( new Shaper() )->shape( array( 'profile' => $profile ) );
		$json   = (string) json_encode( $result['answer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertNull( SiteFactsOracle::refusal( $json ), 'OmnaBase\'s reader accepts the answer: ' . $json );
		return json_decode( $json )->omnabase->profile ?? null;
	}

	/**
	 * What it checks: The customer's billing country travels with the account part of the answer, written the standard way.
	 * Passes when: a profile with country "at" arrives as "AT", beside the registration date and roles, which are unchanged.
	 * Fails when: the country is dropped on the way, so OmnaBase's Country row stays empty for every shop customer.
	 */
	public function test_a_two_letter_country_is_sent_upper_case(): void {
		$p = $this->profileOf( array( 'registeredAt' => '2021-03-04', 'roles' => array( 'customer' ), 'country' => 'at' ) );
		$this->assertSame( 'AT', $p->country ?? null );
		$this->assertSame( '2021-03-04', $p->registeredAt ?? null, '(guard) the registration date stays' );
		$this->assertSame( array( 'customer' ), $p->roles ?? null, '(guard) the roles stay' );
	}

	/**
	 * What it checks: Anything that is not a two-letter country code is left out, without losing the rest of the account part.
	 * Passes when: "Austria", "AUT", "", "A1" and a list each give a profile without a country, still carrying the registration date.
	 * Fails when: a free-text country or a broken value reaches OmnaBase, or one odd value blanks the customer's whole account part.
	 * (guard) Passes today, since no country is sent at all; it must keep passing once the country is.
	 */
	public function test_anything_else_is_left_out(): void {
		foreach ( array( 'Austria', 'AUT', '', 'A1', array( 'AT' ) ) as $odd ) {
			$p = $this->profileOf( array( 'registeredAt' => '2021-03-04', 'country' => $odd ) );
			$this->assertNotNull( $p, 'the profile stays: ' . wp_json_encode( $odd ) );
			$this->assertFalse( property_exists( $p, 'country' ), 'no country for ' . wp_json_encode( $odd ) );
			$this->assertSame( '2021-03-04', $p->registeredAt ?? null );
		}
	}

	/**
	 * What it checks: The plugin's last check before sending knows the new field, and the plugin never invents a language.
	 * Passes when: the contract check finds nothing wrong with a profile carrying country "AT"; a language handed to the shaper is not sent.
	 * Fails when: the last check drops the whole account part because of the country, or the site's own language is passed off as the
	 *   customer's.
	 */
	public function test_the_contract_check_accepts_it_and_no_language_is_sent(): void {
		$this->assertSame( array(), ( new ContractCheck() )->failing( array( 'profile' => (object) array( 'registeredAt' => '2021-03-04', 'country' => 'AT' ) ) ) );
		$p = $this->profileOf( array( 'registeredAt' => '2021-03-04', 'country' => 'DE', 'language' => 'de' ) );
		$this->assertSame( 'DE', $p->country ?? null );
		$this->assertFalse( property_exists( $p, 'language' ), 'no language from the plugin' );
	}
}
