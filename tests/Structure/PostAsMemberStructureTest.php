<?php
/**
 * The post-as-member round (2026-10-29), the red team's failing tests: file-level guards (no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/post-as-member-round.md`) rule 2 "Records"; report `docs/reviews/post-as-member-round-red-team-report.md` in
 * the OmnaBase repository, findings 10 to 14. The words: *filter*, a named hook where site code changes a value; *post meta*, a named value
 * WordPress keeps beside a post; *unreleased section*, the changelog's newest section while it is still being built (it gets its date on the
 * release day; a dated section is never changed again).
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - changelog.txt opens `= 0.2.1 — unreleased =` directly above the frozen `= 0.2.0 — 2026-10-29 =`, with a customer line about answers
 *   appearing under the team member's own forum account; the 0.2.0 section stays as released.
 * - The version is 0.2.1 in the plugin header, `OMNABASE_VERSION`, readme.txt's Stable tag and package.json; `bin/check-version.sh` agrees.
 * - The plugin applies the filter `omnabase_forum_reply_author`; readme.txt names it and no longer says answers appear "under one support
 *   user".
 * - (chosen, finding 12) The mark `_omnabase_posted_as` is named on `Options` and removed by uninstall.php with the plugin's other post meta.
 * - (chosen, finding 13) The Forums tab: the support user's help is "Posts the AI's replies, and a team member's reply when the site has no
 *   moderator with their email address." (at most 30 words); the card's intro and its "choose a support user" warning no longer say every
 *   answer appears under the chosen name or waits for it.
 * - (guard) The outbox's reply job skips the plugin's own posts by their mark before it looks at the support user.
 *
 * Why these fail today: the top section is the dated 0.2.0, the version is 0.2.0 everywhere, there is no filter, no mark, no new help.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class PostAsMemberStructureTest extends TestCase {

	private const NEXT      = '0.2.1';
	private const RELEASED  = '= 0.2.0 — 2026-10-29 =';
	private const HELP      = 'Posts the AI\'s replies, and a team member\'s reply when the site has no moderator with their email address.';

	/**
	 * What it checks: The round's release note goes into a new, unreleased 0.2.1 section, and the 0.2.0 notes customers already downloaded
	 *   stay as published.
	 * Passes when: changelog.txt's first section heading is "= 0.2.1 — unreleased =", the next heading is the dated 0.2.0 one, the new section
	 *   has a line about answers appearing under the team member's own forum account, and the 0.2.0 section says nothing of it.
	 * Fails when: the line is added to the released 0.2.0 notes (they would describe code nobody received), or the new section carries a date
	 *   before it ships.
	 */
	public function test_the_changelog_opens_0_2_1_unreleased_above_the_frozen_0_2_0(): void {
		$log = $this->read( 'changelog.txt' );
		preg_match_all( '/^= [^\n]+ =$/m', $log, $headings );
		$this->assertSame( '= ' . self::NEXT . ' — unreleased =', $headings[0][0] ?? null, 'the top section' );
		$this->assertSame( self::RELEASED, $headings[0][1] ?? null, 'the released section right below it' );
		$top = $this->section( $log, '= ' . self::NEXT . ' — unreleased =' );
		$this->assertMatchesRegularExpression( '/own (?:forum )?account|under (?:their|the (?:team )?member\'s) (?:own )?(?:forum )?(?:account|name)/i', $top, 'a line about answers under the member\'s own forum account' );
		$this->assertDoesNotMatchRegularExpression( '/own (?:forum )?account/i', $this->section( $log, self::RELEASED ), 'nothing of this round in 0.2.0' );
	}

	/**
	 * What it checks: The version is 0.2.1 everywhere the release check reads it.
	 * Passes when: the plugin header, the version constant, readme.txt's Stable tag and package.json say 0.2.1, and bin/check-version.sh
	 *   exits 0.
	 * Fails when: the release zip's build refuses because one file still says 0.2.0, or the site shows one version and the plugins list another.
	 */
	public function test_the_version_is_0_2_1_in_the_four_places(): void {
		$main = $this->read( 'omnabase.php' );
		$this->assertSame( 1, preg_match( '/^\s*\*?\s*Version:\s*(\S+)/m', $main, $header ) );
		$this->assertSame( self::NEXT, $header[1], 'the header' );
		$this->assertSame( 1, preg_match( "/define\(\s*'OMNABASE_VERSION',\s*'([^']+)'/", $main, $constant ) );
		$this->assertSame( self::NEXT, $constant[1], 'OMNABASE_VERSION' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)/m', $this->read( 'readme.txt' ), $stable ) );
		$this->assertSame( self::NEXT, $stable[1], 'readme.txt Stable tag' );
		$this->assertSame( self::NEXT, (string) ( json_decode( $this->read( 'package.json' ), true )['version'] ?? '' ), 'package.json' );
		$out  = array();
		$code = 1;
		exec( 'bash ' . escapeshellarg( OMNABASE_REPO . '/bin/check-version.sh' ) . ' 2>&1', $out, $code );
		$this->assertSame( 0, $code, 'bin/check-version.sh: ' . implode( ' / ', $out ) );
	}

	/**
	 * What it checks: Site developers can choose who posts the team's answer, under the name the readme gives, and the readme describes the
	 *   new behaviour.
	 * Passes when: the plugin's code applies the filter `omnabase_forum_reply_author`; readme.txt names it and no longer says the answers
	 *   appear "under one support user".
	 * Fails when: the readme promises a hook the plugin never calls, or tells site owners every answer appears under the support user when it
	 *   now appears under the member's account.
	 */
	public function test_the_filter_and_the_readme(): void {
		$src = implode( "\n", array_map( fn ( string $f ) => $this->code( $f ), $this->phpUnder( 'src' ) ) );
		$this->assertMatchesRegularExpression( '/apply_filters\s*\(\s*[\'"]omnabase_forum_reply_author[\'"]/', $src );
		$readme = $this->read( 'readme.txt' );
		$this->assertStringContainsString( 'omnabase_forum_reply_author', $readme );
		$description = (string) strstr( (string) strstr( $readme, '== Description ==' ), '== Installation ==', true );
		$this->assertStringNotContainsString( 'under one support user', $description );
	}

	/**
	 * What it checks: (chosen) The new mark on posted replies is cleaned up with the plugin's other marks when the plugin is deleted.
	 * Passes when: the options class names `_omnabase_posted_as`, and uninstall.php removes it from every post.
	 * Fails when: deleting the plugin leaves a mark on every forum reply it ever posted.
	 */
	public function test_the_posted_as_mark_is_named_and_uninstalled(): void {
		$this->assertStringContainsString( "'_omnabase_posted_as'", $this->code( 'src/Options.php' ) );
		$this->assertMatchesRegularExpression( '/delete_post_meta_by_key\(\s*(?:\'_omnabase_posted_as\'|Options::\w+)\s*\)/', $this->code( 'uninstall.php' ) );
		$this->assertStringContainsString( '_omnabase_posted_as', $this->read( 'uninstall.php' ) );
	}

	/**
	 * What it checks: (chosen) The Forums tab tells the administrator what the support user is for now, in at most 30 words, and no longer
	 *   says every answer appears under it.
	 * Passes when: the support user's help reads the pinned sentence (30 words at most); the forum card no longer says the team's answers
	 *   appear "under the name you choose below" nor that "until then your team's answers wait at OmnaBase".
	 * Fails when: an administrator reads that every answer appears under the support user and is surprised to see a colleague's account, or
	 *   believes nothing is posted until a support user is chosen.
	 */
	public function test_the_forums_tab_words(): void {
		$tpl = str_replace( "\\'", "'", $this->read( 'templates/admin/partials/forum-rows.php' ) . $this->read( 'templates/admin/partials/forum.php' ) );
		$this->assertStringContainsString( self::HELP, $tpl );
		$this->assertLessThanOrEqual( 30, str_word_count( self::HELP ) );
		$this->assertStringNotContainsString( 'under the name you choose below', $tpl );
		$this->assertStringNotContainsString( "until then your team's answers wait at OmnaBase", $tpl );
	}

	/**
	 * What it checks: (guard) A reply the plugin posted is recognised by its own mark first, whoever it was posted under.
	 * Passes when: in the outbox's reply job the check of the plugin's mark comes before the comparison with the support user.
	 * Fails when: the echo guard leans on "posted by the support user", which no longer holds for a member's reply.
	 */
	public function test_the_echo_guard_reads_the_mark_first(): void {
		$code = $this->code( 'src/Forum/ReplySender.php' );
		$this->assertSame( 1, preg_match( '/function whyNot\b(.*?)\n\t}\n/s', $code, $m ), 'whyNot' );
		$mark    = strpos( $m[1], 'Posts::messageOf' );
		$support = strpos( $m[1], 'post_author' );
		$this->assertNotFalse( $mark, 'the mark is checked' );
		$this->assertTrue( false === $support || $mark < $support, 'the mark before the support user' );
	}

	/** The text of one changelog section, from its heading to the next. */
	private function section( string $log, string $heading ): string {
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $heading, '/' ) . '$(.*?)(?=^= |\z)/msu', $log, $m ), $heading );
		return $m[1];
	}

	/** The repository's PHP files under a folder. */
	private function phpUnder( string $dir ): array {
		$out = array();
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && 'php' === $f->getExtension() ) {
				$out[] = str_replace( OMNABASE_REPO . '/', '', $f->getPathname() );
			}
		}
		sort( $out );
		return $out;
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
