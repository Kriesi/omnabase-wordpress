<?php
/**
 * The forum reply pictures round (OmnaBase, 2026-11-17), the red team's file-level guards (no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/forum-reply-pictures-round.md`) Part B, rules 6 and 9; report
 * `docs/reviews/forum-reply-pictures-round-red-team-report.md` in the OmnaBase repository, findings 12, 16, 19 and 21.
 *
 * The words: *download cap*, WordPress's `limit_response_size`, which stops reading an answer once it holds that many bytes; *background
 * job*, a task WordPress runs shortly after a request (a WP-Cron hook); *uninstall*, what runs when the site owner deletes the plugin;
 * *deactivation*, switching the plugin off; *post meta*, a named value WordPress keeps beside a post (here beside a saved picture).
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The picture download goes through the plugin's own call to OmnaBase with a download cap, never through WordPress's helpers that fetch
 *   any address (`download_url`, `media_sideload_image`, `wp_safe_remote_get`): those send no key, follow redirects and refuse the
 *   development addresses (`OMNABASE_API_URL` on a local host).
 * - (chosen) The saved pictures' mark `_omnabase_file_id` is removed on uninstall; the pictures themselves stay in the media library.
 * - (guard) Every background job hook the plugin names (`const HOOK… = 'omnabase_…'`) is cleared on uninstall and its class's `clear()` is
 *   called on deactivation.
 * - changelog.txt's top section is still unreleased and has a line about pictures in forum answers.
 *
 * Why these fail today: nothing downloads a picture, the mark does not exist, and the changelog has no line about it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class ForumReplyPicturesStructureTest extends TestCase {

	/**
	 * What it checks: The picture is downloaded through the plugin's own call to OmnaBase with a size cap held during the download, never
	 *   through WordPress's helpers that fetch any address.
	 * Passes when: some file under src/ names OmnaBase's picture path and sets the download cap; no file under src/ uses download_url,
	 *   media_sideload_image or wp_safe_remote_get.
	 * Fails when: a huge answer is read whole into the site's memory or disk before its size is checked, or the download goes out without
	 *   the key and follows redirects to anywhere.
	 */
	public function test_the_picture_download_is_the_plugins_own_capped_call(): void {
		$all = '';
		foreach ( $this->phpUnder( 'src' ) as $file ) {
			$all .= $this->code( $file ) . "\n";
		}
		$this->assertTrue( str_contains( $all, '/pictures/' ), 'OmnaBase\'s picture path is named under src/' );
		$this->assertTrue( str_contains( $all, 'limit_response_size' ), 'a download cap is set under src/' );
		foreach ( array( 'download_url', 'media_sideload_image', 'wp_safe_remote_get' ) as $helper ) {
			$this->assertSame( 0, preg_match( '/\b' . $helper . '\s*\(/', $all ), $helper . ' is not used' );
		}
	}

	/**
	 * What it checks: (chosen) Deleting the plugin removes its mark on the saved pictures but leaves the pictures, which forum posts show.
	 * Passes when: uninstall.php removes the post meta `_omnabase_file_id` (by name or through the plugin's list of names) and deletes no
	 *   attachment.
	 * Fails when: the plugin leaves its data behind after it is deleted, or deleting it breaks every forum post with a picture.
	 */
	public function test_the_file_mark_goes_on_uninstall_and_the_pictures_stay(): void {
		$uninstall = $this->code( 'uninstall.php' );
		$options   = $this->code( 'src/Options.php' );
		$named     = str_contains( $uninstall, "'_omnabase_file_id'" ) || ( (bool) preg_match( "/const (\w+)\s*=\s*'_omnabase_file_id'/", $options, $m ) && str_contains( $uninstall, 'Options::' . $m[1] ) );
		$this->assertTrue( $named, 'uninstall removes the saved pictures\' mark' );
		$this->assertDoesNotMatchRegularExpression( '/wp_delete_attachment\s*\(/', $uninstall, 'the pictures stay' );
	}

	/**
	 * What it checks: (guard) Every background job of the plugin is cleared when the plugin is switched off or deleted, so no job of a
	 *   removed plugin keeps waking up.
	 * Passes when: each hook name a class under src/ declares as `const HOOK… = 'omnabase_…'` appears in uninstall.php, and each such class's
	 *   `clear()` is called from the plugin's deactivation.
	 * Fails when: the new "fetch the pictures later" job keeps running for a site that switched the plugin off.
	 */
	public function test_every_job_hook_is_cleared(): void {
		$uninstall = $this->code( 'uninstall.php' );
		$bootstrap = $this->code( 'src/Bootstrap.php' );
		$found     = 0;
		foreach ( $this->phpUnder( 'src' ) as $file ) {
			if ( ! preg_match_all( "/const HOOK\w*\s*=\s*'(omnabase_[a-z_]+)'/", $this->code( $file ), $m ) ) {
				continue;
			}
			foreach ( $m[1] as $hook ) {
				$this->assertStringContainsString( "'" . $hook . "'", $uninstall, $hook . ' cleared on uninstall' );
				++$found;
			}
			$this->assertStringContainsString( basename( $file, '.php' ) . '::clear()', $bootstrap, $file . ' cleared on deactivation' );
		}
		$this->assertGreaterThanOrEqual( 3, $found, 'the outbox\'s two and the forum mails\' hook at least' );
	}

	/**
	 * What it checks: The customer changelog tells site owners that the team's pictures now show in forum answers.
	 * Passes when: changelog.txt's top section is headed "unreleased" and one of its lines speaks of pictures (or screenshots) and the forum.
	 * Fails when: owners update and do not learn why their media library suddenly holds the team's screenshots.
	 */
	public function test_the_changelog_names_the_pictures(): void {
		$text = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= (\S+) — ([^=]+) =$\n(.*?)(?=^= |\z)/ms', $text, $m ), 'a top section' );
		$this->assertSame( 'unreleased', trim( $m[2] ) );
		$lines = array_filter( explode( "\n", $m[3] ), static fn ( string $l ) => (bool) preg_match( '/pictures?|screenshots?|images?/i', $l ) && (bool) preg_match( '/forum/i', $l ) );
		$this->assertNotSame( array(), $lines, 'a line about pictures in forum answers' );
	}

	/** The repository's PHP files under a folder. */
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

	/** A repository file's PHP without its comments ('' when missing). */
	private function code( string $path ): string {
		$text = $this->read( $path );
		$out  = '';
		foreach ( '' !== $text ? token_get_all( $text ) : array() as $t ) {
			$out .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $out;
	}

	/** A repository file's text ('' when missing). */
	private function read( string $path ): string {
		$p = OMNABASE_REPO . '/' . $path;
		return is_file( $p ) ? (string) file_get_contents( $p ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a repository file, read by a test
	}
}
