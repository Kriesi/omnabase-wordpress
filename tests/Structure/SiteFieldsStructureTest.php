<?php
/**
 * The site-fields round (2026-10-29), the red team's failing tests: file-level guards (no WordPress needed). Replaces the earlier round's
 * parked `PrivateTopicsStructureTest.php` (private topics are dropped: no forwarding filter, no private mark, no snippet).
 *
 * Brief (OmnaBase `docs/briefs/site-fields-round.md`) rule 5; report `docs/reviews/site-fields-round-red-team-report.md` in the OmnaBase
 * repository, findings 15 to 21. The words: *filter* and *action*, named hooks where a developer's code changes a value or hears that
 * something happened; *post meta*, a named value WordPress keeps beside a post; *datalist*, the browser's list of suggestions under an input
 * (auto-complete); *transient*, a value WordPress keeps for a while (a cache).
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The three hooks by name in src/: the filter `omnabase_forum_fields` (an array key ⇒ label), the filter
 *   `omnabase_forum_field_value( $value, $key, $post_id )`, the action `omnabase_forum_field_saved( $reply_id, $key, $value )`.
 * - The declared fields live in one class `src/Forum/PostFields.php` (direct-access guard first).
 * - (chosen, finding 18) `ReplyPoster` writes the fields right after `bbp_insert_reply` and BEFORE it marks the reply as OmnaBase's
 *   (`Posts::markPosted`): a redelivery that finds the mark posts nothing more, so a field written after the mark would be lost for good when
 *   the request dies in between.
 * - (chosen, finding 17) The stored value is never put through `sanitize_textarea_field`, which removes every `%2F`-style sequence and so
 *   breaks a login address like `wp-login.php?redirect_to=%2Fwp-admin` (the value is stripped of tags with `wp_kses` and an empty list).
 * - (chosen, finding 19) The auto-complete's meta keys are read with one bounded query (`LIMIT 100`, `_bbp_` left out) and kept in a
 *   transient, never once per page view over every post's meta (kriesi.at's forum has millions of meta rows).
 * - The Forums tab's template holds the "Post fields" rows (`omnabase_forum[fields][…][key]` and `[label]`) with a `<datalist>`, and the
 *   brief's help sentence (30 words at most).
 * - readme.txt names the setting and the three hooks; the customer changelog's unreleased section has a line about post fields.
 *
 * Why these fail today: no hook, no class, no rows, no help, no readme or changelog line. The order and query guards fail at their first step.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class SiteFieldsStructureTest extends TestCase {

	/**
	 * What it checks: A developer can add fields, change a value on its way out and hear when a value was saved, under the names the readme
	 *   gives.
	 * Passes when: the plugin's code applies the filters `omnabase_forum_fields` and `omnabase_forum_field_value` and fires the action
	 *   `omnabase_forum_field_saved`, and readme.txt names all three and the "Post fields" setting.
	 * Fails when: a site that keeps its private data elsewhere has no way in, or the readme names hooks the plugin never calls.
	 */
	public function test_the_three_hooks(): void {
		$src = implode( "\n", array_map( fn ( string $f ) => $this->code( $f ), $this->phpUnder( 'src' ) ) );
		$this->assertMatchesRegularExpression( '/apply_filters\s*\(\s*[\'"]omnabase_forum_fields[\'"]/', $src );
		$this->assertMatchesRegularExpression( '/apply_filters\s*\(\s*[\'"]omnabase_forum_field_value[\'"]/', $src );
		$this->assertMatchesRegularExpression( '/do_action\s*\(\s*[\'"]omnabase_forum_field_saved[\'"]/', $src );
		$readme = $this->read( 'readme.txt' );
		foreach ( array( 'omnabase_forum_fields', 'omnabase_forum_field_value', 'omnabase_forum_field_saved', 'Post fields' ) as $needed ) {
			$this->assertStringContainsString( $needed, $readme, $needed . ' in readme.txt' );
		}
	}

	/**
	 * What it checks: The rules for which fields travel sit in one small class.
	 * Passes when: src/Forum/PostFields.php exists and starts with WordPress's direct-access guard.
	 * Fails when: the limits (10 fields, 60-character names, 10,000-character values, the forbidden keys) are copied into the senders, the
	 *   poster and the settings, and drift apart.
	 */
	public function test_the_declared_fields_have_their_own_class(): void {
		$this->assertFileExists( OMNABASE_REPO . '/src/Forum/PostFields.php' );
		$this->assertStringContainsString( "defined( 'ABSPATH' ) || exit;", $this->read( 'src/Forum/PostFields.php' ) );
	}

	/**
	 * What it checks: The team's private data is on the forum reply before the reply counts as delivered, and arrives intact.
	 * Passes when: in ReplyPoster the field write (PostFields or update_post_meta) comes after bbp_insert_reply and before Posts::markPosted;
	 *   neither ReplyPoster nor PostFields calls sanitize_textarea_field.
	 * Fails when: a request that dies at the wrong moment leaves a reply without its private data that no redelivery repairs, or a login
	 *   address loses its %2F parts and does not work.
	 */
	public function test_the_poster_writes_fields_before_the_mark_and_keeps_the_value(): void {
		$poster = $this->code( 'src/Forum/ReplyPoster.php' );
		$insert = strpos( $poster, 'bbp_insert_reply' );
		$mark   = strpos( $poster, 'Posts::markPosted' );
		$this->assertNotFalse( $insert );
		$this->assertNotFalse( $mark );
		$this->assertSame( 1, preg_match( '/PostFields::\w+\s*\(|update_post_meta\s*\(/', $poster, $m, PREG_OFFSET_CAPTURE ), 'the poster writes the fields' );
		$write = (int) $m[0][1];
		$this->assertGreaterThan( $insert, $write, 'after the reply exists' );
		$this->assertLessThan( $mark, $write, 'before the reply is marked as OmnaBase\'s' );
		$this->assertDoesNotMatchRegularExpression( '/sanitize_textarea_field\s*\(/', $poster . $this->code( 'src/Forum/PostFields.php' ) );
	}

	/**
	 * What it checks: The settings page's auto-complete stays cheap on a big forum.
	 * Passes when: the code that reads the site's meta keys for the Field inputs holds LIMIT 100, leaves out `_bbp_` keys and keeps the answer in
	 *   a transient.
	 * Fails when: opening Settings › OmnaBase › Forums on kriesi.at scans millions of meta rows on every visit.
	 */
	public function test_the_meta_keys_query_is_bounded_and_cached(): void {
		$code = $this->code( 'src/Forum/PostFields.php' ) . $this->code( 'src/Admin/ForumView.php' );
		$this->assertMatchesRegularExpression( '/meta_key/', $code );
		$this->assertMatchesRegularExpression( '/LIMIT\s+(100|%d)/i', $code );
		$this->assertStringContainsString( '_bbp_', $code );
		$this->assertMatchesRegularExpression( '/set_transient\s*\(/', $code );
	}

	/**
	 * What it checks: The Forums tab offers the Post fields rows with auto-complete and one short help line.
	 * Passes when: the forum templates hold inputs named omnabase_forum[fields][…][key] and [label], a datalist, and the brief's help sentence
	 *   (at most 30 words).
	 * Fails when: the administrator has nowhere to name AviaSupport's field, or the help line grows into a paragraph.
	 */
	public function test_the_rows_and_the_help(): void {
		$tpl = '';
		foreach ( glob( OMNABASE_REPO . '/templates/admin/partials/forum*.php' ) ?: array() as $f ) {
			$tpl .= (string) file_get_contents( $f );
		}
		$this->assertMatchesRegularExpression( '/omnabase_forum\[fields\]\[[^\]]*\]\[key\]/', $tpl );
		$this->assertMatchesRegularExpression( '/omnabase_forum\[fields\]\[[^\]]*\]\[label\]/', $tpl );
		$this->assertStringContainsString( '<datalist', $tpl );
		$help = 'Fields of a topic or reply (post meta) that travel with the message to OmnaBase and back onto the team\'s replies.';
		$this->assertStringContainsString( $help, str_replace( "\\'", "'", $tpl ) );
		$this->assertLessThanOrEqual( 30, str_word_count( $help ) );
	}

	/**
	 * What it checks: Site owners read about the new setting in the plugin's release notes.
	 * Passes when: the top unreleased section of changelog.txt has a line about post fields or private data.
	 * Fails when: an owner updates and cannot tell that forum private data now travels to OmnaBase and back.
	 */
	public function test_the_changelog_records_the_round(): void {
		$log = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= [0-9.]+ — unreleased =$(.*?)(?=^= |\z)/msu', $log, $m ), 'an unreleased section' );
		$this->assertMatchesRegularExpression( '/post field|private data/i', $m[1] );
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
