<?php
/**
 * The forum reply mails round (2026-11-03), the red team's failing tests: file-level guards (no WordPress needed).
 *
 * OmnaBase brief docs/briefs/forum-reply-mails-round.md rules 1 and 10 and the words section; report
 * docs/reviews/forum-reply-mails-round-red-team-report.md in the OmnaBase repository, findings 20, 25 and 26. The words: *option*, a stored
 * setting (the forum settings are one option, `omnabase_forum`); *post meta*, a named value WordPress keeps beside a post; *translation
 * template*, languages/omnabase.pot, the list of every sentence translators translate; *unreleased section*, the changelog's newest section
 * while it is still being built.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The forum option's description on `Options::FORUM` names `customer_mail`; uninstall.php removes the forum option as today.
 * - (chosen, finding 20) The mark `_omnabase_customer_mail` on a posted reply is named on `Options` and removed by uninstall.php with the
 *   plugin's other post meta.
 * - The translation template holds the switch's label "OmnaBase mails the customer about answers" and its help sentence.
 * - readme.txt has a paragraph naming the switch; changelog.txt's unreleased 0.2.1 section has a line about OmnaBase mailing the topic's author.
 *
 * Why these fail today: none of it exists.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class ForumReplyMailsStructureTest extends TestCase {

	private const LABEL = 'OmnaBase mails the customer about answers';
	private const HELP  = "After an answer from OmnaBase is posted, OmnaBase mails the topic's author in your project's look. The forum's own subscription mail then leaves them out.";

	/**
	 * What it checks: The new switch is described where the forum option is described, and the new mark on posted replies is cleaned up when the
	 *   plugin is deleted.
	 * Passes when: the options class's description of the forum option names `customer_mail`; the options class names `_omnabase_customer_mail`;
	 *   uninstall.php removes that post meta from every post and still removes the forum option.
	 * Fails when: deleting the plugin leaves a mark on every forum reply it ever posted, or the next developer cannot find what the option holds.
	 */
	public function test_the_option_key_and_the_mark_are_named_and_uninstalled(): void {
		$options = $this->read( 'src/Options.php' );
		$this->assertSame( 1, preg_match( '/\/\*\*((?:(?!\*\/).)*?)\*\/\s*public const FORUM\b/s', $options, $doc ), 'the forum option\'s description' );
		$this->assertStringContainsString( 'customer_mail', $doc[1] );
		$this->assertStringContainsString( "'_omnabase_customer_mail'", $this->code( 'src/Options.php' ) );
		$uninstall = $this->code( 'uninstall.php' );
		$this->assertMatchesRegularExpression( '/delete_post_meta_by_key\(\s*\'_omnabase_customer_mail\'\s*\)/', $uninstall );
		$this->assertStringContainsString( "'omnabase_forum'", $uninstall, 'the forum option still goes' );
	}

	/**
	 * What it checks: Translators get the switch's words.
	 * Passes when: languages/omnabase.pot has a msgid for the label and one for the help sentence.
	 * Fails when: the switch shows in English on every translated site, and the release check refuses the outdated template.
	 */
	public function test_the_translation_template_has_the_words(): void {
		$pot = $this->read( 'languages/omnabase.pot' );
		$this->assertStringContainsString( 'msgid "' . self::LABEL . '"', $pot );
		$this->assertStringContainsString( 'msgid "' . self::HELP . '"', $pot );
	}

	/**
	 * What it checks: Site owners can read about the switch in the readme, and the release notes say what changed.
	 * Passes when: readme.txt names "OmnaBase mails the customer about answers"; changelog.txt's top section, still "= 0.2.1 — unreleased =", has
	 *   a line about OmnaBase mailing the topic's author.
	 * Fails when: owners turn the switch on without knowing that the forum's own mail then leaves the author out, or the release notes forget it.
	 */
	public function test_the_readme_and_the_changelog(): void {
		$this->assertStringContainsString( self::LABEL, $this->read( 'readme.txt' ) );
		$log = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= 0\.2\.1 — unreleased =$(.*?)(?=^= |\z)/msu', $log, $top ), 'the unreleased 0.2.1 section on top' );
		$this->assertMatchesRegularExpression( '/OmnaBase (?:now )?(?:can )?mails? (?:the )?(?:topic\'s author|customer|author)/i', $top[1] );
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
