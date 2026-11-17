<?php
/**
 * The customer rating round (2026-11-17), part C, the red team's failing tests: file-level guards (no WordPress needed).
 *
 * OmnaBase brief docs/briefs/customer-rating-round.md, "Part C, the plugin"; report docs/reviews/customer-rating-round-red-team-report.md in the
 * OmnaBase repository, findings C1 to C6. The words: *option*, a stored setting (the forum settings are one option, `omnabase_forum`); *post
 * meta*, a named value WordPress keeps beside a post; *translation template*, languages/omnabase.pot, the list of every sentence translators
 * translate; *unreleased section*, the changelog's newest section while it is still being built.
 *
 * Shapes the red team pins (chosen where the brief is silent; the builder may rename and tell the red team):
 * - The Forum tab's row right after "OmnaBase mails the customer about answers": the label "OmnaBase asks the customer for a rating", the
 *   description "Whether OmnaBase mails a topic's author one rating question after their ticket is resolved.", a switch named
 *   `omnabase_forum[ask_rating]`.
 * - (finding C4) `Settings::save` stores `ask_rating` with the other switches: it rewrites the whole option, so a key it forgets is lost on the
 *   next save of the Forum tab.
 * - The mark `_omnabase_rating_ask` is named on `Options` and removed by uninstall.php; the forum option's description names `ask_rating`.
 * - `PostedAnswer` answers `rating => 'ask'` for a marked reply.
 * - The translation template holds the two sentences; readme.txt names the switch; the unreleased 0.2.1 section has a line about it.
 *
 * Why these fail today: none of it exists.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class RatingSwitchStructureTest extends TestCase {

	private const LABEL = 'OmnaBase asks the customer for a rating';
	private const HELP  = "Whether OmnaBase mails a topic's author one rating question after their ticket is resolved.";

	/**
	 * What it checks: [C] The switch sits on the Forum tab right after the answer-mail switch, with one plain sentence.
	 * Passes when: the forum rows template has, after the answer-mail row, a row with the label and the description above and a switch named
	 *   `omnabase_forum[ask_rating]`; the description is one sentence of at most 30 words.
	 * Fails when: a site owner cannot find or switch off the rating question for their forum.
	 */
	public function test_the_row(): void {
		$rows = $this->read( 'templates/admin/partials/forum-rows.php' );
		$mail = strpos( $rows, 'omnabase_forum[customer_mail]' );
		$ask  = strpos( $rows, 'omnabase_forum[ask_rating]' );
		$this->assertNotFalse( $mail );
		$this->assertNotFalse( $ask, 'the switch' );
		$this->assertGreaterThan( $mail, $ask, 'after the answer-mail switch' );
		$this->assertStringContainsString( "esc_html_e( '" . self::LABEL . "', 'omnabase' )", $rows );
		$this->assertStringContainsString( "esc_html_e( '" . str_replace( "'", "\\'", self::HELP ) . "', 'omnabase' )", $rows );
		$this->assertLessThanOrEqual( 30, count( preg_split( '/\s+/', self::HELP ) ) );
	}

	/**
	 * What it checks: [C] Saving the Forum tab keeps the new switch, and the stored key is described where the option is.
	 * Passes when: `Settings::save` writes `'ask_rating'`; the forum option's description on `Options::FORUM` names `ask_rating`.
	 * Fails when: every save of the Forum tab silently switches the rating question back to its default, whatever the owner chose.
	 */
	public function test_save_keeps_it(): void {
		$settings = $this->code( 'src/Forum/Settings.php' );
		$this->assertSame( 1, preg_match( '/function save\((.*?)\n\t}/s', $settings, $save ), 'Settings::save' );
		$this->assertStringContainsString( "'ask_rating'", $save[1] );
		$options = $this->read( 'src/Options.php' );
		$this->assertSame( 1, preg_match( '/\/\*\*((?:(?!\*\/).)*?)\*\/\s*public const FORUM\b/s', $options, $doc ) );
		$this->assertStringContainsString( 'ask_rating', $doc[1] );
	}

	/**
	 * What it checks: [C] The mark on posted replies is named, answered to OmnaBase and cleaned up when the plugin is deleted.
	 * Passes when: the options class names `_omnabase_rating_ask`; `PostedAnswer` adds `'rating' => 'ask'`; uninstall.php removes the mark from
	 *   every post.
	 * Fails when: OmnaBase never learns that a topic's author may be asked, or deleting the plugin leaves a mark on every reply it posted.
	 */
	public function test_the_mark(): void {
		$this->assertStringContainsString( "'_omnabase_rating_ask'", $this->code( 'src/Options.php' ) );
		$this->assertMatchesRegularExpression( "/'rating'\s*\]\s*=\s*'ask'|'rating'\s*=>\s*'ask'/", $this->code( 'src/Forum/PostedAnswer.php' ) );
		$this->assertMatchesRegularExpression( '/delete_post_meta_by_key\(\s*\'_omnabase_rating_ask\'\s*\)/', $this->code( 'uninstall.php' ) );
	}

	/**
	 * What it checks: [C] Translators, site owners and the release notes get the switch's words.
	 * Passes when: the translation template has both sentences; readme.txt names the switch; the unreleased 0.2.1 section has a line about the
	 *   rating question.
	 * Fails when: the switch shows in English on translated sites, the release check refuses the outdated template, or owners learn of the
	 *   question from their customers.
	 */
	public function test_words_readme_and_changelog(): void {
		$pot = $this->read( 'languages/omnabase.pot' );
		$this->assertStringContainsString( 'msgid "' . self::LABEL . '"', $pot );
		$this->assertStringContainsString( 'msgid "' . self::HELP . '"', $pot );
		$this->assertStringContainsString( self::LABEL, $this->read( 'readme.txt' ) );
		$log = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= 0\.2\.1 — unreleased =$(.*?)(?=^= |\z)/msu', $log, $top ) );
		$this->assertMatchesRegularExpression( '/rating/i', $top[1] );
	}

	/** A PHP file's code without its comments ('' when missing). */
	private function code( string $path ): string {
		$text = $this->read( $path );
		if ( '' === $text ) {
			return '';
		}
		$out = '';
		foreach ( token_get_all( $text ) as $t ) {
			if ( is_array( $t ) && in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $t ) ? $t[1] : $t;
		}
		return $out;
	}

	/** A repository file's text ('' when missing). */
	private function read( string $path ): string {
		$file = OMNABASE_REPO . '/' . $path;
		return is_file( $file ) ? (string) file_get_contents( $file ) : '';
	}
}
