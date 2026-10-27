<?php
/**
 * THE PLUGIN'S MESSAGE-CENTRE HTML ROUND (2026-10-28), THE RED TEAM: ADJUSTED, NOT WEAKENED (the forum hooks test's cleaner only). What
 * changed and why: the brief (OmnaBase `docs/briefs/plugin-message-centre-html-round.md`, rule 2) moves the forum reply's cleaning from
 * WordPress's broad `wp_kses_post` (which keeps pictures, inline styles and classes) to the plugin's own allow-list in one helper
 * (`MessageHtml`, the brief's suggested name), the same the message centre prints through; its rule names this pin. The test still asks
 * that answers go through bbPress (`bbp_insert_reply`) and through a cleaner; the cleaner it asks for is now the plugin's helper, and
 * `wp_kses_post` must no longer be the poster's. Every hook it asks for is unchanged.
 *
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED (the release notes' reading only). What changed and why:
 * 0.1.0 was released and dated on 2026-10-13 (commit "Release 0.1.0 (beta)"), and the founder's changelog rule freezes a dated section: new
 * work goes into a new "unreleased" section above it (this round's `FrontendDesignStructureTest` asks for one). This file's release-notes
 * test read "the top section, 0.1.0, unreleased", which has failed since the release and would read the new round's section instead of the
 * one that carries this round's lines. It now reads the 0.1.0 section by its version, whatever its date, and asks the same lines of it; where
 * it asked that the section stay undated, it now asks that the released section keep its release date (frozen). No line it asks for changed.
 *
 * M13 round three, the red team's failing tests: the structure guard extended for the forum (file-level checks, no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) "Round three": "`Forum/Triggers`, `Forum/TopicSender`, `Forum/ReplySender`,
 * `Forum/ReplyPoster`, `Forum/SubscriberMails`, `Forum/Closer`, the forum settings"; choice 7: "`bbp_new_topic` / `bbp_new_reply` …,
 * `bbp_approved_topic` / `bbp_approved_reply` …, `bbp_unspammed_topic` / `bbp_unspammed_reply` …, and `transition_post_status` …"; "the
 * plugin's own replies carry `_omnabase_message_id` post meta"; "`bbp_insert_reply` by the **support user** … HTML through `wp_kses_post`";
 * "A topic gone or trashed → 409 (never 410)"; choice 4: "the idempotency key" as a column of the outbox; choice 10: "Uninstall removes every
 * option, usermeta, post meta, transient, scheduled job and the outbox table"; DECISIONS "M13 round two": "an idempotency key column when
 * topics join the outbox". The coordinator: "the structure guard additions (the hooks named, the marker, the options)".
 *
 * Shapes the red team pins: the brief's class names under `src/Forum/`; the option `omnabase_forum`, the post meta `_omnabase_ticket` and
 * `_omnabase_message_id` (`RoundThreeTestCase`); an outbox column named `idempotency_key`.
 *
 * Why these fail today: no `src/Forum/`, no forum option, no post meta in uninstall.php, no idempotency column, no changelog line.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use OmnaBase\WordPress\Options;
use PHPUnit\Framework\TestCase;

final class RoundThreeStructureTest extends TestCase {

	private const ROUND_THREE_FILES = array(
		'src/Forum/Triggers.php',
		'src/Forum/TopicSender.php',
		'src/Forum/ReplySender.php',
		'src/Forum/ReplyPoster.php',
		'src/Forum/SubscriberMails.php',
		'src/Forum/Closer.php',
		'tests/e2e/forum.spec.ts',
	);

	private const HOOKS = array( 'bbp_new_topic', 'bbp_new_reply', 'bbp_approved_topic', 'bbp_approved_reply', 'bbp_unspammed_topic', 'bbp_unspammed_reply', 'transition_post_status' );

	/**
	 * What it checks: Round three's parts exist under the brief's names.
	 * Passes when: every listed file exists (the triggers, the topic and reply senders, the reply poster, the subscriber mails, the closer, the
	 *   browser flow).
	 * Fails when: the forum's work is tangled into one class, so a change to how topics go out risks breaking how answers come back.
	 */
	public function test_the_round_three_parts_exist(): void {
		$missing = array_values( array_filter( self::ROUND_THREE_FILES, static fn ( string $f ) => ! file_exists( OMNABASE_REPO . '/' . $f ) ) );
		$this->assertSame( array(), $missing, 'missing: ' . implode( ', ', $missing ) );
	}

	/**
	 * What it checks: The forum code listens at every moment bbPress publishes a post (review S3), and posts answers through bbPress and the
	 *   plugin's own HTML cleaner (adjusted 2026-10-28 for the message-centre HTML round: the cleaner was WordPress's `wp_kses_post`).
	 * Passes when: the code under src/Forum/ registers each of bbp_new_topic, bbp_new_reply, bbp_approved_topic, bbp_approved_reply,
	 *   bbp_unspammed_topic, bbp_unspammed_reply and transition_post_status, calls bbp_insert_reply and the plugin's message cleaner
	 *   (`MessageHtml::`), and no longer calls wp_kses_post.
	 * Fails when: topics made in wp-admin, approved or rescued from spam are silently missed, or a reply's HTML reaches the forum uncleaned or
	 *   cleaned by WordPress's broad cleaner, which lets pictures and inline styles through.
	 */
	public function test_the_forum_hooks_are_named_and_answers_go_through_bbpress(): void {
		$code = $this->forumCode();
		foreach ( self::HOOKS as $hook ) {
			$this->assertMatchesRegularExpression( '/add_action\s*\(\s*[\'"]' . preg_quote( $hook, '/' ) . '[\'"]/', $code, $hook );
		}
		$this->assertMatchesRegularExpression( '/\bbbp_insert_reply\s*\(/', $code );
		$this->assertMatchesRegularExpression( '/\bMessageHtml::/', $code, 'the plugin\'s own cleaner (adjusted 2026-10-28)' );
		$this->assertDoesNotMatchRegularExpression( '/\bwp_kses_post\s*\(/', $code, 'no longer WordPress\'s broad cleaner (adjusted 2026-10-28)' );
	}

	/**
	 * What it checks: The marker that stops the echo is written once and read by the sending side.
	 * Passes when: `_omnabase_message_id` is named in exactly one file under src/ (a constant the poster and the sender share), and the ticket
	 *   number's meta `_omnabase_ticket` is named in src/.
	 * Fails when: the poster writes one marker and the sender checks another, and every team answer comes back to OmnaBase.
	 */
	public function test_the_echo_marker_is_written_once(): void {
		$hits = array_filter( $this->phpUnder( 'src' ), fn ( string $f ) => str_contains( $this->code( $f ), '_omnabase_message_id' ) );
		$this->assertCount( 1, $hits, 'files naming the marker: ' . implode( ', ', $hits ) );
		$this->assertNotSame( array(), array_filter( $this->phpUnder( 'src' ), fn ( string $f ) => str_contains( $this->code( $f ), '_omnabase_ticket' ) ) );
	}

	/**
	 * What it checks: Nothing in the receivers or the forum code can answer OmnaBase with 410, which would switch the site's address off for good.
	 * Passes when: no file under src/Rest/ or src/Forum/ holds the number 410.
	 * Fails when: one trashed topic stops every forum answer from reaching the site.
	 */
	public function test_nothing_answers_410(): void {
		foreach ( array_merge( $this->phpUnder( 'src/Rest' ), $this->phpUnder( 'src/Forum' ) ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/\b410\b/', $this->code( $file ), $file );
		}
		$this->assertNotSame( array(), $this->phpUnder( 'src/Forum' ), 'the forum code exists' );
	}

	/**
	 * What it checks: The outbox keeps one idempotency key per row (the one-time id that makes a resent topic count once), in a new version of
	 *   its table.
	 * Passes when: the outbox table's definition has an `idempotency_key` column and its schema version is no longer "1".
	 * Fails when: the key is made afresh on every try (a lost answer turns one topic into two tickets), or sites that updated keep the old table.
	 */
	public function test_the_outbox_keeps_an_idempotency_key_per_row(): void {
		$table = $this->code( 'src/Outbox/Table.php' );
		$this->assertMatchesRegularExpression( '/\bidempotency_key\b/', $table );
		$this->assertDoesNotMatchRegularExpression( '/const\s+VERSION\s*=\s*[\'"]1[\'"]/', $table );
	}

	/**
	 * What it checks: Deleting the plugin removes what round three stores: the forum settings, the ticket numbers on topics, the markers on the
	 *   posted replies and the subscriber-mail job.
	 * Passes when: the option list holds omnabase_forum (removed by uninstall.php through the older guard), uninstall.php deletes the post meta
	 *   `_omnabase_ticket` and `_omnabase_message_id` for every post, and clears at least three scheduled jobs (the outbox's two and the forum's).
	 * Fails when: a shop owner deletes the plugin and its forum choices and markers stay in the database for ever.
	 */
	public function test_uninstall_removes_the_forum_option_meta_and_job(): void {
		$this->assertContains( 'omnabase_forum', Options::ALL );
		$uninstall = $this->code( 'uninstall.php' );
		foreach ( array( '_omnabase_ticket', '_omnabase_message_id' ) as $meta ) {
			$this->assertMatchesRegularExpression( '/(delete_metadata\s*\(\s*[\'"]post[\'"]\s*,\s*0\s*,|delete_post_meta_by_key\s*\()\s*[\'"]' . preg_quote( $meta, '/' ) . '[\'"]/', $uninstall, $meta );
		}
		$this->assertGreaterThanOrEqual( 3, preg_match_all( '/wp_(clear_scheduled_hook|unschedule_hook)\s*\(/', $uninstall ) );
	}

	/**
	 * What it checks: The customer changelog and the decisions record round three.
	 * Passes when: changelog.txt's 0.1.0 section (released 2026-10-13) has a line about forum topics becoming tickets, and DECISIONS.md has a dated "M13 round
	 *   three" heading.
	 * Fails when: site owners are not told their forum topics now reach OmnaBase, or the round's choices are not written down.
	 */
	public function test_the_changelog_and_decisions_record_round_three(): void {
		$changelog = $this->read( 'changelog.txt' );
		$top       = (string) preg_replace( '/^.*?^= 0\.1\.0 — [^=]+ =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$this->assertMatchesRegularExpression( '/^\* .*\bforums?\b.*\b(topics?|tickets?)\b/mi', $top );
		$this->assertMatchesRegularExpression( '/^## \d{4}-\d{2}-\d{2} — M13 round three/m', $this->read( 'DECISIONS.md' ) );
	}

	/** The code under src/Forum/, without comments, as one text. */
	private function forumCode(): string {
		return implode( "\n", array_map( fn ( $f ) => $this->code( $f ), $this->phpUnder( 'src/Forum' ) ) );
	}

	/** PHP files under a folder, relative. */
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

	/** A file's code without comments. */
	private function code( string $path ): string {
		$out = '';
		foreach ( token_get_all( $this->read( $path ) ) as $t ) {
			$out .= is_array( $t ) ? ( in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ? '' : $t[1] ) : $t;
		}
		return $out;
	}

	/** A repository file's text. */
	private function read( string $path ): string {
		return is_file( OMNABASE_REPO . '/' . $path ) ? (string) file_get_contents( OMNABASE_REPO . '/' . $path ) : '';
	}
}
