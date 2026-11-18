<?php
/**
 * The forum edits and pictures round (OmnaBase, 2026-11-18), the red team's file-level guards (no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/forum-edits-and-pictures-round.md`) Part A item 9, Part B item 11; report
 * `docs/reviews/forum-edits-and-pictures-round-red-team-report.md` in the OmnaBase repository, findings 16, 17, 19 and 25.
 *
 * The words: a *hook*, a named moment where a plugin's code runs; `bbp_edit_topic` and `bbp_edit_reply` fire after an edit in the forum,
 * `post_updated` after any save of a post (the admin's editor included); the *outbox*, the plugin's list of calls waiting to be sent; the
 * *idempotency key*, a token that lets OmnaBase recognise a repeat of one call; *uninstall*, what runs when the site owner deletes the plugin.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - The forum's triggers listen to the three edit moments, and their description no longer says edits queue nothing.
 * - Some outbox job under src/ sends `PATCH` on `/messages/by-external/`, and that call is made without the row's idempotency key.
 * - Every post meta key the round adds (say, what was last sent of a post) is removed on uninstall (CLAUDE.md: uninstall.php grows with every
 *   meta key): checked as "every `_omnabase_` meta name under src/ is named in uninstall.php or on `Options`, which uninstall reads".
 * - changelog.txt's top section is still unreleased and has a line about edited posts and one about pictures from the forum.
 *
 * Why these fail today: the triggers know no edit moment, no job sends an edit, and the changelog has no such lines.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class ForumEditsStructureTest extends TestCase {

	/**
	 * What it checks: The forum's triggers listen to an edit in the forum and to a save in the admin.
	 * Passes when: the triggers register `bbp_edit_topic`, `bbp_edit_reply` and `post_updated`, and their description no longer says that
	 *   edits queue nothing.
	 * Fails when: a customer's edit in the forum stays on the forum alone, as on ticket 435.
	 */
	public function test_the_triggers_listen_to_edits(): void {
		$src = $this->read( 'src/Forum/Triggers.php' );
		foreach ( array( 'bbp_edit_topic', 'bbp_edit_reply', 'post_updated' ) as $hook ) {
			$this->assertMatchesRegularExpression( "/add_action\\(\\s*'" . $hook . "'/", $src, $hook . ' is listened to' );
		}
		$this->assertStringNotContainsString( 'Edits (`publish` to `publish`) and deletions queue nothing', $src );
	}

	/**
	 * What it checks: The edit goes out as OmnaBase's edit call, without an idempotency key.
	 * Passes when: some file under src/ builds a PATCH on the `/messages/by-external/` address, and that same line does not ask for the row's
	 *   idempotency key (the last argument of the send step is not `true`).
	 * Fails when: two edits between a lost answer and its retry meet OmnaBase's "the post changed" refusal and the row needs the administrator.
	 */
	public function test_the_edit_call_carries_no_idempotency_key(): void {
		$lines = array();
		foreach ( $this->phpUnder( 'src' ) as $file ) {
			foreach ( explode( "\n", $this->code( $file ) ) as $line ) {
				if ( str_contains( $line, "'PATCH'" ) && str_contains( $line, '/messages/by-external/' ) ) {
					$lines[] = $line;
				}
			}
		}
		$this->assertNotSame( array(), $lines, 'a PATCH on /messages/by-external/ under src/' );
		foreach ( $lines as $line ) {
			$this->assertDoesNotMatchRegularExpression( '/,\s*true\s*\)\s*;\s*$/', trim( $line ), 'no idempotency key on the edit' );
		}
	}

	/**
	 * What it checks: (guard) Whatever the plugin keeps beside a post is removed when the site owner deletes the plugin.
	 * Passes when: every `_omnabase_…` post meta name written under src/ appears in uninstall.php or is a constant on `Options` (which
	 *   uninstall.php reads).
	 * Fails when: deleting the plugin leaves the round's notes behind on every forum post.
	 */
	public function test_every_meta_key_is_removed_on_uninstall(): void {
		$all = '';
		foreach ( $this->phpUnder( 'src' ) as $file ) {
			$all .= $this->read( $file ) . "\n";
		}
		$uninstall = $this->read( 'uninstall.php' ) . $this->read( 'src/Options.php' );
		preg_match_all( "/'(_omnabase_[a-z0-9_]+)'/", $all, $m );
		foreach ( array_unique( $m[1] ) as $meta ) {
			$this->assertStringContainsString( $meta, $uninstall, $meta . ' is removed on uninstall' );
		}
	}

	/**
	 * What it checks: The customer changelog tells site owners that edits now reach the ticket and that the forum's pictures show there.
	 * Passes when: changelog.txt's top section is still unreleased and holds a line naming edits and the ticket, and one naming pictures and the
	 *   ticket.
	 * Fails when: a site owner updates the plugin without learning that edits now travel to OmnaBase.
	 */
	public function test_the_changelog_names_both(): void {
		$text = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= (\S+) — ([^=]+) =$/m', $text, $top, PREG_OFFSET_CAPTURE ) );
		$this->assertSame( 'unreleased', trim( $top[2][0] ), 'the top section is unreleased' );
		$start = $top[0][1] + strlen( $top[0][0] );
		$next  = preg_match( '/^= /m', $text, $n, PREG_OFFSET_CAPTURE, $start ) ? $n[0][1] : strlen( $text );
		$section = substr( $text, $start, $next - $start );
		$this->assertMatchesRegularExpression( '/\bedit[^\n]*\bticket|\bticket[^\n]*\bedit/i', $section, 'a line about edited posts reaching the ticket' );
		$this->assertMatchesRegularExpression( '/picture[^\n]*ticket|ticket[^\n]*picture/i', $section, 'a line about the forum\'s pictures in the ticket' );
	}

	/** Every PHP file under a folder, relative to the repository. */
	private function phpUnder( string $dir ): array {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && 'php' === $f->getExtension() ) {
				$out[] = str_replace( OMNABASE_REPO . '/', '', $f->getPathname() );
			}
		}
		sort( $out );
		return $out;
	}

	/** A file's code without its comments. */
	private function code( string $path ): string {
		$text = $this->read( $path );
		$out  = '';
		foreach ( '' !== $text ? token_get_all( $text ) : array() as $t ) {
			$out .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $out;
	}

	/** A repository file's text, empty when it is missing. */
	private function read( string $path ): string {
		$p = OMNABASE_REPO . '/' . $path;
		return is_file( $p ) ? (string) file_get_contents( $p ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file, read by a test
	}
}
