<?php
/**
 * M14 round two, the red team's failing tests: the shortcode builder's files and words outside the screen (file-level checks, no WordPress
 * needed): the builder's script, where it is queued from, the translation template, the readme and the customer changelog.
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 2 ("a little vanilla JS in `assets/admin.js` loaded only on the
 * plugin's screen …; a Copy button (the clipboard API, falling back to a selected field)"; "WordPress's admin styles, no library"); review N7
 * (load the script only on the plugin's own screen); the coordinator's round-two order ("every visible string translated and in the `.pot`;
 * readme's Shortcodes section and FAQ mention the builder; a changelog line"; the revoked state's "Forget this connection"). The plugin's
 * `CLAUDE.md`: "WordPress's own admin styles and a little vanilla JS. No Vue, no build step for the admin"; "`languages/omnabase.pot` … the
 * structure guard and the release check compare it with the code"; "Changelog: … One line per customer-visible change".
 *
 * The words: *vanilla JS*, plain JavaScript without a library such as jQuery; *clipboard API*, the browser's own way for a page to copy text;
 * *enqueue*, WordPress's list of scripts a page loads (`admin_enqueue_scripts` is the moment a plugin adds scripts to admin pages); *`.pot`*,
 * the template file translators start from; *readme*, readme.txt, the plugin's page on WordPress.org; *changelog*, changelog.txt, the
 * customer release notes.
 *
 * Shapes pinned: the file `assets/admin.js`; the brief's words (matched without regard to capitals, so "See a sign-in link" passes too); the
 * readme's "= Shortcodes =" part of the Description and its FAQ name "Build a shortcode". Chosen here (the builder may argue): the script
 * never writes HTML from text (no `innerHTML`), since the subject an owner types must stay text.
 *
 * Why these fail today: no `assets/admin.js`, none of the builder's words in the `.pot`, the readme and the changelog.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class M14RoundTwoStructureTest extends TestCase {

	/** Sentences and labels of the builder and the revoked state that the `.pot` must hold (compared without regard to capitals). */
	private const POT_WORDS = array(
		'Build a shortcode',
		"Contact form: visitors' messages become tickets",
		'Message centre: signed-in customers read and answer their tickets',
		'Subject starts with',
		'Visitors who are not signed in',
		'see a sign-in link',
		'see the contact form instead',
		'Copy',
		'Paste it into any page or post.',
		'Create the page for me',
		'Contact support',
		'Support',
		'Forget this connection',
	);

	/** The message centre's line, which may go on with the picked page (a placeholder) after these words. */
	private const PICKED_LINE = 'It works only on the page picked as the message centre page';

	/**
	 * What it checks: The builder's script exists and is plain JavaScript that opens the browser's own dialog and copies with the browser's
	 *   own clipboard, falling back to selecting the field.
	 * Passes when: assets/admin.js exists; it opens the dialog the browser's way ("showModal"), uses the clipboard API and a selected-field
	 *   fallback (".select("); it uses no jQuery, writes no HTML from text ("innerHTML") and holds no test-mode switch.
	 * Fails when: the builder has no script, depends on a library the admin screen does not promise, puts an owner's typed subject into the
	 *   page as HTML, or carries test-only code to customers.
	 */
	public function test_the_builders_script_exists_and_is_plain_javascript(): void {
		$js = $this->read( 'assets/admin.js' );
		$this->assertNotSame( '', $js, 'assets/admin.js' );
		$this->assertStringContainsString( 'showModal', $js, 'the browser\'s own dialog' );
		$this->assertStringContainsString( 'navigator.clipboard', $js, 'the clipboard API' );
		$this->assertStringContainsString( '.select(', $js, 'the selected-field fallback' );
		$this->assertDoesNotMatchRegularExpression( '/\bjQuery\b|\$\(/', $js, 'no jQuery' );
		$this->assertStringNotContainsString( 'innerHTML', $js, 'no HTML written from text' );
		$this->assertStringNotContainsString( 'OMNABASE_TEST_MODE', $js );
	}

	/**
	 * What it checks: The script is queued from the admin side of the plugin, at WordPress's moment for admin scripts, and never by the public
	 *   pieces.
	 * Passes when: a PHP file under src/ names "assets/admin.js" and hooks into "admin_enqueue_scripts"; neither the form's nor the message
	 *   centre's code names it.
	 * Fails when: the builder's script reaches visitors' pages, or is printed by hand outside WordPress's script list (no version, no
	 *   caching rules).
	 */
	public function test_the_script_is_queued_from_the_admin_side_only(): void {
		$queuing = array_filter( $this->phpUnder( 'src' ), fn ( $f ) => str_contains( $this->read( $f ), 'assets/admin.js' ) );
		$this->assertNotSame( array(), $queuing, 'some PHP under src/ queues assets/admin.js' );
		$this->assertNotSame( array(), array_filter( $queuing, fn ( $f ) => str_contains( $this->read( $f ), 'admin_enqueue_scripts' ) ), 'at admin_enqueue_scripts' );
		foreach ( array_merge( $this->phpUnder( 'src/Forms' ), $this->phpUnder( 'src/Portal' ) ) as $file ) {
			$this->assertStringNotContainsString( 'assets/admin.js', $this->read( $file ), $file );
		}
	}

	/**
	 * What it checks: Every word of the builder and of "Forget this connection" can be translated.
	 * Passes when: languages/omnabase.pot holds each of the builder's labels and sentences (the two cards, "Subject starts with", "Visitors who
	 *   are not signed in" and its two choices, "Copy", "Paste it into any page or post.", "Create the page for me", the two page titles,
	 *   "Build a shortcode", "Forget this connection") and a sentence starting "It works only on the page picked as the message centre page".
	 * Fails when: a translated site shows the builder half in English, or the .pot was not regenerated after the round.
	 */
	public function test_the_pot_holds_the_builders_words(): void {
		$ids = array_map( 'strtolower', $this->msgids( $this->read( 'languages/omnabase.pot' ) ) );
		$this->assertNotSame( array(), $ids, 'languages/omnabase.pot' );
		$missing = array_values( array_filter( self::POT_WORDS, static fn ( $w ) => ! in_array( strtolower( $w ), $ids, true ) ) );
		$this->assertSame( array(), $missing, 'missing from the .pot' );
		$line = strtolower( self::PICKED_LINE );
		$this->assertNotSame( array(), array_filter( $ids, static fn ( $id ) => str_starts_with( $id, $line ) ), self::PICKED_LINE );
	}

	/**
	 * What it checks: The readme tells owners about the builder where they look for the shortcodes.
	 * Passes when: the readme's "Shortcodes" part of the Description and its FAQ both name "Build a shortcode".
	 * Fails when: owners reading the plugin's page still copy tags by hand from a list and never learn that the plugin can make the page for
	 *   them.
	 */
	public function test_the_readme_mentions_the_builder(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^= Shortcodes =$(.*?)(?=^==? |\z)/ms', $readme, $m ), 'the Shortcodes part of the Description' );
		$this->assertStringContainsString( 'Build a shortcode', $m[1] );
		$this->assertSame( 1, preg_match( '/^== Frequently Asked Questions ==$(.*?)(?=^== |\z)/ms', $readme, $faq ) );
		$this->assertStringContainsString( 'Build a shortcode', $faq[1] );
	}

	/**
	 * What it checks: The unreleased changelog tells customers about the builder and "Forget this connection".
	 * Passes when: changelog.txt's top section is still "0.1.0 — unreleased" and has a line naming the builder ("Build a shortcode" or
	 *   "shortcode builder") and a line naming "Forget this connection".
	 * Fails when: customers are not told about the round's two visible changes, or a dated (released) section was edited.
	 */
	public function test_the_changelog_names_the_builder_and_forget(): void {
		$changelog = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= 0\.1\.0 — unreleased =$(.*?)(?:^= |\z)/ms', $changelog, $m ), 'the top section is 0.1.0, unreleased' );
		$this->assertMatchesRegularExpression( '/^\* .*(Build a shortcode|shortcode builder)/mi', $m[1], 'a line about the builder' );
		$this->assertMatchesRegularExpression( '/^\* .*Forget this connection/m', $m[1], 'a line about Forget this connection' );
	}

	/** The msgids of a .pot (multi-line ids joined, PO escapes undone). */
	private function msgids( string $pot ): array {
		preg_match_all( '/^msgid ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $pot, $m );
		$ids = array();
		foreach ( $m[1] as $raw ) {
			preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );
			$ids[] = stripcslashes( implode( '', $parts[1] ) );
		}
		return array_values( array_filter( $ids, static fn ( $id ) => '' !== $id ) );
	}

	/** The PHP files under a folder, relative to the repository. */
	private function phpUnder( string $dir ): array {
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( 'php' === $f->getExtension() ) {
				$out[] = str_replace( OMNABASE_REPO . '/', '', $f->getPathname() );
			}
		}
		sort( $out );
		return $out;
	}

	/** A repository file's contents ('' when missing). */
	private function read( string $path ): string {
		$file = OMNABASE_REPO . '/' . $path;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
