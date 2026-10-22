<?php
/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED (the release notes' reading only). What changed and why:
 * 0.1.0 was released and dated on 2026-10-13 (commit "Release 0.1.0 (beta)"), and the founder's changelog rule freezes a dated section: new
 * work goes into a new "unreleased" section above it (this round's `FrontendDesignStructureTest` asks for one). This file's release-notes
 * test read "the top section, 0.1.0, unreleased", which has failed since the release and would read the new round's section instead of the
 * one that carries this round's lines. It now reads the 0.1.0 section by its version, whatever its date, and asks the same lines of it; where
 * it asked that the section stay undated, it now asks that the released section keep its release date (frozen). No line it asks for changed.
 * Also adjusted here: `test_the_readme_names_the_tabs` asks that the FAQ's theme-styles answer name the Appearance tab instead of Forms and
 * messages, because the brief (rule 2) moves the "Theme styles only" switch to the new Appearance tab; the rest of that test is unchanged.
 *
 * M14 round one, the red team's failing tests: the words outside the screen follow the screen (file-level checks, no WordPress needed): the
 * plugin's description on the Plugins list, the readme's Description, Installation, FAQ and External services, and the customer changelog.
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 3: "The readme's FAQ, External services, the header description and
 * the changelog follow the new words; the `.pot` is regenerated and the structure guard's pinned entries move with it"; choice 5: "the
 * plugin's row description ends with 'Set it up under Settings › OmnaBase.'"; choice 1 (the tab names); review S1 ("the readme's FAQ ('the
 * Shortcodes section')"). The founder's global rules, "Customer-Facing Changelog": one line per customer-visible change, in the unreleased
 * section. The plugin's `CLAUDE.md`, "Changelog": "`= 0.1.0 — unreleased =` until the release day".
 *
 * How the banned list is applied to these files (the screen's own guard is `PlainWordsTest`): the same words, matched the same way (whole
 * words, `REST` with its case, plurals of the nouns), after leaving out short bracketed names (three words at most) and text in backticks: a
 * file cannot fold text away behind "Details", so a technical name may follow its plain meaning in brackets ("WordPress's timer (WP-Cron)"),
 * but never carry the sentence, and a long bracketed aside counts as a sentence.
 *
 * The words: *header description*, the sentence under the plugin's name on wp-admin's Plugins list, read from omnabase.php's header; *readme*,
 * readme.txt, the plugin's page on WordPress.org; *FAQ*, its Frequently Asked Questions; *changelog*, the customer release notes
 * (changelog.txt); *tab*, one of the settings screen's five panels.
 *
 * Shapes pinned: the brief's sentences and tab names. Why these fail today: the header description ends "… on every ticket.", the readme
 * says "WP-Cron", "cron job" and "the Shortcodes section", Installation names no tab, and the changelog has no line about tabs, the
 * fourth state or the Settings link (and says "WP-Cron").
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class M14RoundOneStructureTest extends TestCase {

	/** The one banned list (M14 brief, choice 3), as `RoundOneTestCase::BANNED` in the integration layer. */
	private const BANNED = array( 'wp-json', 'REST', 'WP-Cron', 'cron', 'DISABLE_WP_CRON', 'HMAC', 'signature', 'usermeta', 'meta key', 'salts', 'encryption key', 'nonce', 'endpoint', 'webhook', 'scope', 'JSON', 'HTTP', '401', '409', '5xx' );

	/** Nouns whose plural counts too. */
	private const PLURALS = array( 'signature', 'meta key', 'encryption key', 'nonce', 'endpoint', 'webhook', 'scope' );

	private const SET_IT_UP = 'Set it up under Settings › OmnaBase.';

	/**
	 * What it checks: The plugin's description on the Plugins list ends by saying where to set it up, in plain words.
	 * Passes when: omnabase.php's header description ends with "Set it up under Settings › OmnaBase." and holds no banned word.
	 * Fails when: an owner who just activated the plugin reads what it does but not where to go next.
	 */
	public function test_the_header_description_says_where_to_set_it_up(): void {
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Description:\s*(.+?)\s*$/m', $this->read( 'omnabase.php' ), $m ), 'the header has a description' );
		$this->assertStringEndsWith( self::SET_IT_UP, $m[1] );
		$this->assertSame( array(), self::bannedIn( $m[1] ) );
	}

	/**
	 * What it checks: The readme's Description, Installation, FAQ and External services speak the screen's plain words.
	 * Passes when: outside short bracketed names and backticks, none of the four sections holds a banned word (so "WP-Cron", "cron job" and
	 *   "wp-cron.php" are gone from the FAQ and the Description, or stand only as a bracketed name after plain words).
	 * Fails when: the screen says "WordPress's timer" while the plugin's own page tells owners about "a real cron job calling wp-cron.php".
	 */
	public function test_the_readme_speaks_the_screens_plain_words(): void {
		$readme = $this->read( 'readme.txt' );
		foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'External services' ) as $name ) {
			$section = self::sectionOf( $readme, $name );
			$this->assertNotSame( '', $section, $name );
			$this->assertSame( array(), self::bannedIn( self::withoutAsides( $section ) ), $name );
		}
	}

	/**
	 * What it checks: The readme sends owners to the tabs by the names the screen shows.
	 * Passes when: the FAQ's answer about the theme's styles names the "Appearance" tab (where the switch moved in the front-end design round)
	 *   and no longer says "Shortcodes section"; the Installation steps name the tabs "Customer data", "Forums" and "Forms and messages".
	 * Fails when: an owner follows the readme to a "Shortcodes section" that no longer exists as such, or to "the same screen" without knowing
	 *   which tab.
	 */
	public function test_the_readme_names_the_tabs(): void {
		$readme = $this->read( 'readme.txt' );
		$faq    = self::sectionOf( $readme, 'Frequently Asked Questions' );
		$this->assertDoesNotMatchRegularExpression( '/Shortcodes section/i', $faq );
		$this->assertMatchesRegularExpression( '/theme[^=]*\?\s*=\s*\R+[^=]*Appearance/i', $faq, 'the theme-styles answer names the Appearance tab (front-end design round: the switch moved there)' );
		$install = self::sectionOf( $readme, 'Installation' );
		foreach ( array( 'Customer data', 'Forums', 'Forms and messages' ) as $tab ) {
			$this->assertStringContainsString( $tab, $install, $tab );
		}
	}

	/**
	 * What it checks: The release notes tell customers about this round's changes, in plain words.
	 * Passes when: changelog.txt's 0.1.0 section (released 2026-10-13, frozen) and has a line about the settings tabs, a line about "Disconnected
	 *   in OmnaBase", and a line about the Settings link on the Plugins list; outside short bracketed names and backticks, none of its lines
	 *   holds a banned word.
	 * Fails when: customers are not told the screen changed, or the release notes still say "WP-Cron" while the screen says "timer".
	 */
	public function test_the_changelog_names_this_rounds_changes_in_plain_words(): void {
		$changelog = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= 0\.1\.0 — [^=]+ =$(.*?)(?:^= |\z)/ms', $changelog, $m ), 'the 0.1.0 section (released 2026-10-13)' );
		$section = $m[1];
		$this->assertMatchesRegularExpression( '/^\* .*\btabs?\b/mi', $section, 'a line about the tabs' );
		$this->assertMatchesRegularExpression( '/^\* .*Disconnected in OmnaBase/m', $section, 'a line about the fourth state' );
		$this->assertMatchesRegularExpression( '/^\* .*Settings.{0,20}link.*Plugins|^\* .*Plugins.*Settings.{0,20}link/mi', $section, 'a line about the Settings link on the Plugins list' );
		$this->assertSame( array(), self::bannedIn( self::withoutAsides( $section ) ) );
	}

	/**
	 * What it checks: The guard of this file catches what it must and leaves ordinary words alone.
	 * Passes when: "a real cron job calling wp-cron.php" yields two hits, "Webhooks" one, "the REST API" one, a long bracketed aside "(a
	 *   turned-off WP-Cron, an outdated shop plugin)" one; "WordPress's timer (WP-Cron)" and "restore the rest over https" yield none.
	 * Fails when: the readme and changelog checks above pass for nothing, or flag plain English.
	 */
	public function test_the_guard_matches_as_the_brief_says(): void {
		$this->assertCount( 2, self::bannedIn( 'a real cron job calling wp-cron.php' ) );
		$this->assertCount( 1, self::bannedIn( 'Webhooks arrive' ) );
		$this->assertCount( 1, self::bannedIn( 'the REST API' ) );
		$this->assertSame( array(), self::bannedIn( self::withoutAsides( "WordPress's timer (WP-Cron) and `[omnabase_form]`" ) ) );
		$this->assertSame( array(), self::bannedIn( 'restore the rest over https' ) );
		$this->assertCount( 1, self::bannedIn( self::withoutAsides( 'warns about (a turned-off WP-Cron, an outdated shop plugin)' ) ) );
	}

	/** Text without its short bracketed names (at most three words, such as "(WP-Cron)") and backticked code. */
	private static function withoutAsides( string $text ): string {
		return (string) preg_replace( array( '/\(\s*[^()\s]+(?:\s+[^()\s]+){0,2}\s*\)/u', '/`[^`]*`/' ), ' ', $text );
	}

	/**
	 * Every banned word in a text (whole words; `REST` with its case; plurals of the nouns), with a little context.
	 *
	 * @return string[]
	 */
	private static function bannedIn( string $text ): array {
		$found = array();
		foreach ( self::BANNED as $word ) {
			$body  = str_replace( ' ', '\s+', preg_quote( $word, '/' ) ) . ( in_array( $word, self::PLURALS, true ) ? 's?' : '' );
			$edge  = '[\p{L}\p{N}_-]';
			$regex = '/(?<!' . $edge . ')' . $body . '(?!' . $edge . ')/' . ( 'REST' === $word ? 'u' : 'iu' );
			if ( preg_match_all( $regex, $text, $m, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $m[0] as [ $hit, $at ] ) {
					$found[] = '"' . $word . '" in "…' . substr( $text, max( 0, $at - 40 ), strlen( $hit ) + 80 ) . '…"';
				}
			}
		}
		return $found;
	}

	/** A readme section's text, up to the next "== … ==" heading. */
	private static function sectionOf( string $readme, string $name ): string {
		return preg_match( '/^== ' . preg_quote( $name, '/' ) . ' ==$(.*?)(?=^== |\z)/ms', $readme, $m ) ? $m[1] : '';
	}

	/** A repository file's contents ('' when missing). */
	private function read( string $path ): string {
		$file = OMNABASE_REPO . '/' . $path;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
