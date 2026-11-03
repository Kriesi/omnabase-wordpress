<?php
/**
 * The licence-and-fields round, piece A (the outside red team's failing tests, 2026-11-04): file-level guards (no WordPress needed) for the
 * records the brief asks of the licence configurator: the readme, the customer changelog, a decision and the translation template.
 *
 * Brief: OmnaBase `docs/briefs/licence-and-fields-round.md`, piece A, rule 5. The words: *unreleased section*, the changelog's newest section
 * while it is still being built (it gets its date on the release day); *translation template*, `languages/omnabase.pot`, the list of every
 * sentence translators translate.
 *
 * Shapes the red team pins (chosen; the builder may reword and tell the red team):
 * - readme.txt's "Customer data:" bullet (what the plugin sends) says a licence's end date (expiry) may be sent.
 * - changelog.txt's top section is unreleased and has a customer line naming the licence and its end date or expiry.
 * - DECISIONS.md has an entry dated November 2026 whose heading names the condition or the expiry and whose text explains the `field.key`
 *   rule (the first dot splits the field from the key).
 * - The translation template holds the two new labels "Expiry field" and "Condition".
 *
 * Why these fail today: none of the four mention a condition or an expiry.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class LicenceConditionExpiryStructureTest extends TestCase {

	/**
	 * What it checks: The readme tells site owners that a licence's end date may now leave the site with the masked licence.
	 * Passes when: readme.txt's "Customer data:" bullet names an end date, an expiry or "expires" beside the licence kept in a user field.
	 * Fails when: the public description of what the plugin sends is incomplete (WordPress.org's rules and the owner's privacy page rely on it).
	 */
	public function test_the_readme_names_the_expiry(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^\* \*\*Customer data:\*\*[^\n]*$/m', $readme, $m ), 'the "Customer data:" bullet' );
		$this->assertMatchesRegularExpression( '/expir|end date|support end/i', $m[0] );
	}

	/**
	 * What it checks: Customers read about the condition and the expiry in the release notes still being written.
	 * Passes when: changelog.txt's first section is unreleased and has a line naming a licence and an end date or expiry.
	 * Fails when: the change ships without a release note, or the note lands in notes already released.
	 */
	public function test_the_unreleased_changelog_has_the_line(): void {
		$log = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^(= [^\n]+ — unreleased =)$(.*?)(?=^= |\z)/msu', $log, $m ), 'an unreleased section' );
		preg_match( '/^= [^\n]+ =$/m', $log, $first );
		$this->assertSame( $m[1], $first[0] ?? null, 'the unreleased section is the top one' );
		$this->assertMatchesRegularExpression( '/^\*[^\n]*licen[cs]e[^\n]*(expir|end date|support end)/mi', $m[2] );
	}

	/**
	 * What it checks: The rules a later round must not undo are written down: how `field.key` is read and why an unreadable date keeps the
	 *   licence.
	 * Passes when: DECISIONS.md has an entry dated November 2026 whose heading names the condition or the expiry, and whose text names
	 *   `field.key` (or the dot) and the unreadable date.
	 * Fails when: a later round "tidies" the parsing and kriesi.at's support dates vanish or its licences disappear on a bad date, because
	 *   nobody knew why the rule was there.
	 */
	public function test_the_decision_records_the_rules(): void {
		$decisions = $this->read( 'DECISIONS.md' );
		$this->assertSame( 1, preg_match( '/^## 2026-11-\d\d — [^\n]*(expir|condition)[^\n]*$(.*?)(?=^## |\z)/msiu', $decisions, $m ), 'a dated entry about the expiry or the condition' );
		$this->assertMatchesRegularExpression( '/field\.key|first dot/i', $m[2] );
		$this->assertMatchesRegularExpression( '/unreadable|cannot be read|without a date/i', $m[2] );
	}

	/**
	 * What it checks: The new settings' words can be translated.
	 * Passes when: languages/omnabase.pot holds the labels "Expiry field" and "Condition".
	 * Fails when: the new settings show in English on every translated site (the .pot is what translators work from).
	 */
	public function test_the_translation_template_has_the_new_labels(): void {
		$pot = $this->read( 'languages/omnabase.pot' );
		$this->assertStringContainsString( 'msgid "Expiry field"', $pot );
		$this->assertStringContainsString( 'msgid "Condition"', $pot );
	}

	/** A file of the repository, or '' when it is missing. */
	private function read( string $path ): string {
		$p = OMNABASE_REPO . '/' . $path;
		return is_file( $p ) ? (string) file_get_contents( $p ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
