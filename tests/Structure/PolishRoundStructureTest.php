<?php
/**
 * The polish round (2026-10-31), the red team's failing tests: file-level guards for the closing line as a few lines (no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/polish-round.md`) outcome 2 and rule 2 "The closing line as a textarea"; report
 * `docs/reviews/polish-round-red-team-report.md` in the OmnaBase repository, findings 17 to 21. The words: *closing line*, the line the
 * plugin adds under every team answer it posts on the forum ("Best regards / Günter, Kriesi"); *textarea*, a text box of several lines;
 * *sanitise*, clean a value before storing it (WordPress's `sanitize_text_field` removes line breaks, `sanitize_textarea_field` keeps them);
 * *unreleased section*, the changelog's newest section while it is still being built.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The Forums tab's field is a `<textarea>` named `omnabase_forum[closing_line]` with `rows="3"`, its value printed with `esc_textarea`;
 *   no `<input>` of that name is left.
 * - The help reads "{agent} becomes the team member's customer-facing name, {workspace} your workspace's name. Each line becomes a line of
 *   the post." (at most 30 words); the old "{agent} becomes the team member's name and {workspace} your workspace's." is gone.
 * - `Settings::DEFAULT_CLOSING_LINE` is "Best regards\n{agent}, {workspace}" (a real line break).
 * - (finding 17) The admin action no longer flattens the line before it reaches the settings: `ForumActions.php` does not put
 *   `closing_line` through `sanitize_text_field` (the settings class cleans it once, keeping line breaks).
 * - (guard) The poster never runs `sanitize_textarea_field` (the site-fields round's rule, it eats `%2F` in addresses): the cleaning happens
 *   at save, in the settings class.
 * - Records: changelog.txt's 0.2.1 section (still unreleased) has a line about the closing line's several lines and "Best regards" with the
 *   customer-facing name; readme.txt describes the line's lines.
 *
 * Why these fail today: the field is an `<input type="text">`, the help is the old one, the default is "— {agent}, {workspace}", the admin
 * action runs `sanitize_text_field` on the line, and neither record mentions it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class PolishRoundStructureTest extends TestCase {

	private const HELP     = '{agent} becomes the team member\'s customer-facing name, {workspace} your workspace\'s name. Each line becomes a line of the post.';
	private const OLD_HELP = '{agent} becomes the team member\'s name and {workspace} your workspace\'s.';

	/**
	 * What it checks: The Forums tab offers the line under each answer as a small box of a few lines, so "Best regards" can sit above the name.
	 * Passes when: the forum template holds a textarea named omnabase_forum[closing_line] with rows="3" whose value is printed with
	 *   esc_textarea, and no input of that name.
	 * Fails when: the owner can only type one line, so the founder's "Best regards" and the name end up on one line of the forum post.
	 */
	public function test_the_field_is_a_textarea_of_three_rows(): void {
		$tpl = $this->read( 'templates/admin/partials/forum-rows.php' );
		$this->assertMatchesRegularExpression( '/<textarea\b[^>]*name="omnabase_forum\[closing_line\]"[^>]*>/', $tpl );
		$this->assertSame( 1, preg_match( '/<textarea\b[^>]*name="omnabase_forum\[closing_line\]"[^>]*>/', $tpl, $m ) );
		$this->assertMatchesRegularExpression( '/\brows="3"/', $m[0], 'three rows' );
		$this->assertMatchesRegularExpression( '/<textarea\b[^>]*closing_line[^>]*>\s*<\?php\s+echo\s+esc_textarea\(/', $tpl, 'the value printed with esc_textarea' );
		$this->assertDoesNotMatchRegularExpression( '/<input\b[^>]*name="omnabase_forum\[closing_line\]"/', $tpl, 'no one-line field left' );
	}

	/**
	 * What it checks: The help under the field says the name is the customer-facing one and that each line becomes a line of the post.
	 * Passes when: the template carries the new sentence (30 words at most) and not the old one.
	 * Fails when: the owner expects the team name they see in OmnaBase, or does not know a second line is possible.
	 */
	public function test_the_help_says_customer_facing_and_lines(): void {
		$tpl = str_replace( "\\'", "'", $this->read( 'templates/admin/partials/forum-rows.php' ) );
		$this->assertStringContainsString( self::HELP, $tpl );
		$this->assertLessThanOrEqual( 30, count( preg_split( '/\s+/', trim( self::HELP ) ) ) );
		$this->assertStringNotContainsString( self::OLD_HELP, $tpl );
	}

	/**
	 * What it checks: A site that never set the line gets the founder's new default: "Best regards", then the name and the workspace.
	 * Passes when: the settings class's default closing line is "Best regards", a line break, "{agent}, {workspace}".
	 * Fails when: new sites keep "— {agent}, {workspace}", which the founder replaced.
	 */
	public function test_the_default_is_best_regards_then_the_name(): void {
		$code = $this->code( 'src/Forum/Settings.php' );
		$this->assertMatchesRegularExpression( '/DEFAULT_CLOSING_LINE\s*=\s*(?:"Best regards\\\\n\{agent\}, \{workspace\}"|\'Best regards\'\s*\.\s*"\\\\n"\s*\.\s*\'\{agent\}, \{workspace\}\')\s*;/', $code );
	}

	/**
	 * What it checks: The line breaks the owner typed survive the save.
	 * Passes when: the forum section's admin action does not put closing_line through sanitize_text_field (which removes line breaks); the
	 *   settings class cleans it with sanitize_textarea_field; the poster runs no sanitize_textarea_field (guard).
	 * Fails when: the textarea accepts two lines but the save squashes them into one, so the new box does nothing.
	 */
	public function test_the_save_keeps_line_breaks(): void {
		$actions = $this->code( 'src/Admin/ForumActions.php' );
		$this->assertDoesNotMatchRegularExpression( '/sanitize_text_field\([^;]*closing_line/s', $actions, 'no one-line clean-up in the admin action' );
		$this->assertMatchesRegularExpression( '/sanitize_textarea_field\s*\(/', $this->code( 'src/Forum/Settings.php' ) );
		$this->assertDoesNotMatchRegularExpression( '/sanitize_textarea_field\s*\(/', $this->code( 'src/Forum/ReplyPoster.php' ), '(guard) not in the poster' );
	}

	/**
	 * What it checks: The customers' notes and the plugin's description tell site owners about the new line.
	 * Passes when: changelog.txt's top section is still "= 0.2.1 — unreleased =" and has a line naming the closing line's several lines (or
	 *   "Best regards") and the customer-facing name; readme.txt's description of the closing line says it can have several lines.
	 * Fails when: an owner updates and finds a different sign-off on the forum without a word why.
	 */
	public function test_the_records(): void {
		$log = $this->read( 'changelog.txt' );
		preg_match_all( '/^= [^\n]+ =$/m', $log, $headings );
		$this->assertSame( '= 0.2.1 — unreleased =', $headings[0][0] ?? null, 'still the unreleased 0.2.1 section' );
		$top = $this->section( $log, '= 0.2.1 — unreleased =' );
		$this->assertMatchesRegularExpression( '/closing line|line under (?:each|a) (?:forum )?(?:answer|reply)/i', $top );
		$this->assertMatchesRegularExpression( '/Best regards|several lines|more than one line/i', $top );
		$this->assertMatchesRegularExpression( '/customer-facing name/i', $top );
		$this->assertMatchesRegularExpression( '/closing line[^.\n]*(?:several|more than one|lines)|(?:several|more than one) lines?[^.\n]*closing line/i', $this->read( 'readme.txt' ) );
	}

	/** The text of one changelog section, from its heading to the next. */
	private function section( string $log, string $heading ): string {
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $heading, '/' ) . '$(.*?)(?=^= |\z)/msu', $log, $m ), $heading );
		return $m[1];
	}

	/** A PHP file's code without its comments ('' when missing). */
	private function code( string $path ): string {
		$text = $this->read( $path );
		if ( '' === $text ) {
			return '';
		}
		$out = '';
		foreach ( token_get_all( $text ) as $t ) {
			$out .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $out;
	}

	/** A repository file's text ('' when missing). */
	private function read( string $path ): string {
		$p = OMNABASE_REPO . '/' . $path;
		return is_file( $p ) ? (string) file_get_contents( $p ) : '';
	}
}
