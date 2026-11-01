<?php
/**
 * The customer sidebar round (OmnaBase's brief `docs/briefs/customer-sidebar-round.md`, rule 5 "the plugin's `changelog.txt`, `readme.txt`
 * and `DECISIONS.md` (the billing country)"; report `docs/reviews/customer-sidebar-round-red-team-report.md`, finding 18), the red team's
 * failing structure tests: what the site tells its visitors and its owner about the new piece of data.
 *
 * The words: the *suggested privacy text* is the paragraph the plugin offers for the site's privacy policy (WordPress's Privacy tool), listing
 * what goes to OmnaBase; the *changelog* is the customer release notes (`changelog.txt`), whose top section is "unreleased" while it is built.
 *
 * Shapes the red team pins (the builder may reword and tell the red team):
 * - The suggested privacy text names the billing country among what is sent ("country").
 * - The top unreleased section of the changelog has a line naming the billing country.
 * - DECISIONS.md has an entry naming the billing country (WooCommerce first, then EDD; never null; no language).
 *
 * Why these fail today: none of it is written.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class CustomerSidebarStructureTest extends TestCase {

	/**
	 * What it checks: A shop's privacy policy text says that the customer's country goes to OmnaBase too.
	 * Passes when: the suggested privacy text mentions the country.
	 * Fails when: the site starts sending a part of the customer's address that its privacy policy does not mention.
	 */
	public function test_the_privacy_text_names_the_country(): void {
		$this->assertMatchesRegularExpression( '/country/i', $this->read( 'src/Privacy/PolicyText.php' ) );
	}

	/**
	 * What it checks: The release notes and the decisions record the new data.
	 * Passes when: the changelog's top section is unreleased and names the billing country; DECISIONS.md has an entry naming the billing
	 *   country with WooCommerce and Easy Digital Downloads.
	 * Fails when: a site owner updates without learning the plugin now sends the country, or the next agent cannot tell why language is not sent.
	 */
	public function test_the_records(): void {
		$log = $this->read( 'changelog.txt' );
		$at  = strpos( $log, '= ' );
		$top = substr( $log, (int) $at, ( strpos( $log, "\n= ", (int) $at + 2 ) ?: strlen( $log ) ) - (int) $at );
		$this->assertMatchesRegularExpression( '/^= [\d.]+ — unreleased =/u', $top );
		$this->assertMatchesRegularExpression( '/country/i', $top );
		$decisions = $this->read( 'DECISIONS.md' );
		$this->assertMatchesRegularExpression( '/billing country/i', $decisions );
		$this->assertMatchesRegularExpression( '/billing country[\s\S]{0,1500}(Easy Digital Downloads|EDD)/i', $decisions );
	}

	private function read( string $path ): string {
		$p = OMNABASE_REPO . '/' . $path;
		return is_file( $p ) ? (string) file_get_contents( $p ) : '';
	}
}
