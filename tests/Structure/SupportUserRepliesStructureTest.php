<?php
/**
 * The "support user replies" round (2026-11-04), the red team's failing tests: file-level guards (no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/support-user-replies-round.md`) part one, rule 2: "The hook that queues replies (`Triggers::reply`) applies
 * the same rule; the comment at the top of `ReplySender` is updated"; part two, rules 1 and 3: "A WP-CLI command `wp omnabase forum resend
 * <reply id>`"; "Readme …, `changelog.txt` top unreleased section in customer words, `DECISIONS.md` (the echo guard is the mark alone;
 * why)". Report `docs/reviews/support-user-replies-round-red-team-report.md` in the OmnaBase repository, findings 2, 3, 6 and 8.
 *
 * The words: *hook*, a named moment where a plugin's code runs (here bbPress publishing a reply); *WP-CLI*, WordPress's command line,
 * which a site owner with shell access runs on the server; *the mark*, the post meta (a named value WordPress keeps beside a post)
 * `_omnabase_message_id` on every reply the plugin posts for OmnaBase; *unreleased section*, the changelog's newest section while it is
 * still being built.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The reply job's skip check (`ReplySender::whyNot`) and the hook (`Triggers::reply`) no longer ask who the support user is.
 * - (guard) The hook still refuses to queue while the plugin itself is posting OmnaBase's answer, and still reads the mark.
 * - The WP-CLI command is registered with `WP_CLI::add_command( 'omnabase forum', … )` in a file under `src/`, only when WP-CLI runs
 *   (`defined( 'WP_CLI' )` in the same file), and it has a `resend` subcommand.
 * - readme.txt names `wp omnabase forum resend`; changelog.txt's top section is still `= 0.2.1 — unreleased =` and has one line naming the
 *   support user and saying their forum replies now reach the ticket; DECISIONS.md has a 2026-11-04 entry saying the echo guard is the mark alone.
 * - (chosen, finding 6) A reply that reached OmnaBase is remembered on the reply itself (post meta named `Options::DELIVERED_META`, a key
 *   starting `_omnabase_`), so "already sent" holds after the outbox forgets its sent rows (seven days); uninstall.php removes it with the
 *   plugin's other post meta.
 *
 * - (chosen, finding 9) The zip is stamped with its build (`build.php` defining `OMNABASE_BUILD`), the settings screen shows it next to
 *   the version, and every call to OmnaBase names it in its User-Agent.
 *
 * Why these fail today: both checks compare the reply's author with the support user; there is no command, no readme line, no changelog
 * line, no decision, no delivered mark and no build stamp.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class SupportUserRepliesStructureTest extends TestCase {

	/**
	 * What it checks: Neither the reply job nor the moment bbPress publishes a reply drops a post because the support user wrote it.
	 * Passes when: the reply job's "why not sent" check and the hook's reply check no longer call the support-user setting.
	 * Fails when: one of the two keeps the old rule, so a support user's hand-written answer is still dropped before or at its turn.
	 */
	public function test_neither_check_asks_for_the_support_user(): void {
		$this->assertStringNotContainsString( 'supportUser', $this->method( 'src/Forum/ReplySender.php', 'whyNot' ), 'the reply job' );
		$this->assertStringNotContainsString( 'supportUser', $this->method( 'src/Forum/Triggers.php', 'reply' ), 'the hook' );
	}

	/**
	 * What it checks: (guard) The hook still holds back the plugin's own posting, the only thing that stops OmnaBase's answer from being
	 *   queued at the moment bbPress publishes it (its mark is written a moment later).
	 * Passes when: the hook's reply check still asks whether the plugin is posting right now and still reads the mark.
	 * Fails when: the clean-up drops the "posting right now" check, and every team answer is queued to travel back to its own ticket.
	 */
	public function test_the_hook_still_holds_back_the_plugins_own_posting(): void {
		$body = $this->method( 'src/Forum/Triggers.php', 'reply' );
		$this->assertStringContainsString( 'ReplyPoster::isPosting()', $body );
		$this->assertStringContainsString( 'Posts::messageOf', $body );
	}

	/**
	 * What it checks: The reply job's description no longer promises that the support user's posts are skipped.
	 * Passes when: the comment above the reply job does not say a reply "or by the support user" is not sent.
	 * Fails when: the next reader trusts the comment and puts the old rule back.
	 */
	public function test_the_reply_jobs_comment_follows(): void {
		$text = $this->read( 'src/Forum/ReplySender.php' );
		$this->assertSame( 1, preg_match( '#/\*\*(.*?)\*/\s*final class ReplySender#s', $text, $m ), 'the class comment' );
		$this->assertStringNotContainsString( 'or by the support user', $m[1] );
	}

	/**
	 * What it checks: A site owner with shell access can send a skipped forum reply again with one command.
	 * Passes when: a file under the plugin's code registers the command "omnabase forum" with WP-CLI only when WP-CLI is running, and the
	 *   command has a "resend" step.
	 * Fails when: the command does not exist, or the plugin loads WP-CLI's code on every page view and breaks sites without it.
	 */
	public function test_the_resend_command_is_registered_for_wp_cli_only(): void {
		$found = '';
		foreach ( $this->phpUnder( 'src' ) as $file ) {
			if ( str_contains( $this->code( $file ), "WP_CLI::add_command( 'omnabase forum'" ) ) {
				$found = $file;
			}
		}
		$this->assertNotSame( '', $found, 'a file registers "omnabase forum"' );
		$this->assertStringContainsString( "defined( 'WP_CLI' )", $this->code( $found ), 'only when WP-CLI runs' );
		$all = '';
		foreach ( $this->phpUnder( 'src' ) as $file ) {
			$all .= $this->code( $file );
		}
		$this->assertMatchesRegularExpression( '/public function resend\s*\(/', $all, 'a resend subcommand' );
	}

	/**
	 * What it checks: The round's change is written down for customers, for administrators and for the next developer.
	 * Passes when: readme.txt names "wp omnabase forum resend"; changelog.txt still opens with "= 0.2.1 — unreleased =" and that section has a
	 *   line naming the support user; DECISIONS.md has a 2026-11-04 heading about the support user's replies or the mark.
	 * Fails when: a site owner never learns how to recover a skipped post, or the release notes stay silent about answers that now reach
	 *   the ticket.
	 */
	public function test_readme_changelog_and_decisions(): void {
		$this->assertStringContainsString( 'wp omnabase forum resend', $this->read( 'readme.txt' ) );
		$log = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= (.+?) =$/mu', $log, $first ), 'a first section' );
		$this->assertSame( '0.2.1 — unreleased', $first[1], 'the top section is still the unreleased 0.2.1' );
		$this->assertSame( 1, preg_match( '/^= 0\.2\.1 — unreleased =$(.*?)(?=^= )/msu', $log, $section ) );
		$this->assertMatchesRegularExpression( '/^\*[^\n]*(support user[^\n]*reach|reach[^\n]*support user)/mi', $section[1], 'a customer line: the support user\'s own forum replies now reach the ticket' );
		$this->assertMatchesRegularExpression( '/^## 2026-11-04 — .*(support user|mark)/mi', $this->read( 'DECISIONS.md' ) );
	}

	/**
	 * What it checks: (chosen, finding 6) The plugin remembers on the reply itself that it reached OmnaBase, and forgets it on uninstall.
	 * Passes when: the plugin's list of names has a delivered mark whose key starts "_omnabase_", and uninstall.php removes it.
	 * Fails when: "already sent" can only be answered while the outbox still lists the sent row (seven days), or the mark stays behind on
	 *   every forum reply after the plugin is deleted.
	 */
	public function test_the_delivered_mark_is_named_and_removed_on_uninstall(): void {
		$this->assertSame( 1, preg_match( "/const DELIVERED_META\s*=\s*'(_omnabase_[a-z_]+)'/", $this->code( 'src/Options.php' ), $m ), 'Options::DELIVERED_META' );
		$this->assertStringContainsString( 'DELIVERED_META', $this->code( 'uninstall.php' ) );
	}

	/**
	 * What it checks: (chosen, finding 9) Every zip says which build it was made from, so a site's screen and OmnaBase can tell an old copy
	 *   from today's.
	 * Passes when: the zip script writes "build.php" with the constant OMNABASE_BUILD from the commit's short code (git's "rev-parse --short
	 *   HEAD"), the day, and "+changes" for unsaved edits; omnabase.php loads build.php and otherwise defines OMNABASE_BUILD empty.
	 * Fails when: kriesi.at runs a copy from the night before the fix again and every screen still just says "0.2.1".
	 */
	public function test_the_zip_is_stamped_with_its_build(): void {
		$script = $this->read( 'bin/build-dist.sh' );
		foreach ( array( 'git rev-parse --short HEAD', 'build.php', 'OMNABASE_BUILD', '+changes' ) as $needle ) {
			$this->assertStringContainsString( $needle, $script, 'the zip script: ' . $needle );
		}
		$main = $this->code( 'omnabase.php' );
		$this->assertStringContainsString( 'build.php', $main, 'omnabase.php loads the stamp' );
		$this->assertMatchesRegularExpression( "/define\(\s*'OMNABASE_BUILD',\s*''\s*\)/", $main, 'an empty build without the stamp' );
	}

	/**
	 * What it checks: (chosen, finding 9) The build shows next to the version on Settings › OmnaBase (the Connection tab included) and goes
	 *   with every call to OmnaBase, so OmnaBase's logs name the build of each site.
	 * Passes when: the settings screen's template prints the build beside the version, and the plugin's calls carry a User-Agent naming the
	 *   version and the build.
	 * Fails when: the founder has to open the server's files to learn which code a site runs, as on 2026-11-04.
	 */
	public function test_the_build_shows_on_the_screen_and_travels_with_every_call(): void {
		$this->assertStringContainsString( "\$view['build']", $this->code( 'templates/admin/settings.php' ), 'the screen prints the build' );
		$client = $this->code( 'src/Api/Client.php' );
		$this->assertStringContainsString( "'User-Agent'", $client, 'a User-Agent on the calls' );
		$this->assertMatchesRegularExpression( '/OMNABASE_BUILD|->build\(\)/', $client, 'naming the build' );
	}

	/** One method's body (without comments): from its `function` line to the first line that closes it at one tab. */
	private function method( string $path, string $name ): string {
		$this->assertSame( 1, preg_match( '/function ' . preg_quote( $name, '/' ) . '\b(.*?)\n\t}\n/s', $this->code( $path ), $m ), $path . ' ' . $name );
		return $m[1];
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
