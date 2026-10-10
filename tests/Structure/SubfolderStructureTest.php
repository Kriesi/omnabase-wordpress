<?php
/**
 * Fixing round "sites in a subfolder", the red team's failing tests: file-level checks (no WordPress needed) for the customer words, the
 * translation template and the multisite rules.
 *
 * The coordinator's contract: "The readme's FAQ gains 'Does it work in a subfolder or on a multisite?' (yes; one connection per site), the
 * changelog a line; the `.pot` follows"; "no `origin()` left in `src/Connect`"; "`uninstall.php` loops `get_sites()` when `is_multisite()`;
 * … the table name uses `$wpdb->prefix` at call time, never cached across `switch_to_blog`". The red team's finding (MultisiteTest's header):
 * the five user notes that belong to one site's connection must be per site, because user meta is shared by every site of a network.
 *
 * The words: *FAQ*, the readme's "Frequently Asked Questions" section, shown on the plugin's WordPress.org page; *changelog*, the customer
 * release notes (`changelog.txt`); *`.pot`*, the translation template, the list of every sentence translators translate; *network*
 * (multisite), several sites sharing one WordPress; *user meta*, notes stored on a user, shared by every site of a network; *code lines*, a
 * file's lines that are not comments.
 *
 * Shapes pinned: the FAQ heading's exact words, "Does it work in a subfolder or on a multisite?" (WordPress.org's readme format,
 * `= … =`); a changelog line under the top section (still `= 0.1.0 — unreleased =`) naming subfolders and networks (the builder's words);
 * the user meta names as in `Options` (`KNOWN_META`, `PENDING_CONNECT_META`, `PORTAL_FINGERPRINT_META`, `PORTAL_LINKS_META`,
 * `PORTAL_DRAFT_META`) and their stored names (`omnabase_known` …) in uninstall.php. The builder may rename and tell the red team.
 *
 * Why these fail today: the readme says Connect needs "WordPress installed at the root of its domain" and has no such question; the
 * changelog has no line; the `.pot` still holds the subfolder refusal; `SiteCheck::origin()` exists and `Flow` calls it; uninstall.php cleans
 * the current site only; the five notes are stored as plain user meta.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class SubfolderStructureTest extends TestCase {

	/** The FAQ question this round adds. */
	private const QUESTION = 'Does it work in a subfolder or on a multisite?';

	/** The user notes that belong to one site's connection (Options' constant names => their stored names). */
	private const PER_SITE_META = array(
		'KNOWN_META'              => 'omnabase_known',
		'PENDING_CONNECT_META'    => 'omnabase_pending_connect',
		'PORTAL_FINGERPRINT_META' => 'omnabase_portal_fingerprint',
		'PORTAL_LINKS_META'       => 'omnabase_portal_links',
		'PORTAL_DRAFT_META'       => 'omnabase_portal_draft',
	);

	/**
	 * What it checks: The readme answers whether the plugin works in a subfolder and on a multisite: yes, with one connection per site.
	 * Passes when: readme.txt's FAQ has the question "Does it work in a subfolder or on a multisite?" as a heading, and its answer starts
	 *   with "Yes" and says each site connects on its own (one connection, or its own connection, per site).
	 * Fails when: a customer with WordPress in /blog or a network of shops cannot find out before installing whether the plugin works for
	 *   them, or believes one connection covers the whole network.
	 */
	public function test_the_readme_answers_the_subfolder_and_multisite_question(): void {
		$readme = $this->read( 'readme.txt' );
		$faq    = $this->section( $readme, 'Frequently Asked Questions' );
		$this->assertNotSame( '', $faq, 'the FAQ section' );
		$this->assertTrue( str_contains( $faq, '= ' . self::QUESTION . ' =' ), 'the question as a FAQ heading' );
		$answer = $this->answerTo( $faq, self::QUESTION );
		$this->assertMatchesRegularExpression( '/^Yes\b/', $answer, 'the answer starts with Yes' );
		$this->assertMatchesRegularExpression( '/\b(one|own|its own|separate)\s+connection\b|\bconnects?\b[^.]*\b(on its own|separately|each)\b|\beach site\b[^.]*\bconnect/i', $answer, 'one connection per site' );
	}

	/**
	 * What it checks: The readme no longer tells customers WordPress must sit at the root of its domain.
	 * Passes when: readme.txt nowhere says "root of its domain" (or "root of the domain"), and the line on what Connect needs still names
	 *   pretty permalinks and https.
	 * Fails when: a customer with WordPress in /blog skips the plugin because the readme still says it cannot work for them.
	 */
	public function test_the_readme_drops_the_root_of_the_domain_requirement(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame( array(), $this->hits( '/[^\n]*root of (its|the) domain[^\n]*/i', $readme ) );
		$this->assertMatchesRegularExpression( '/Connect needs[^\n]*permalinks[^\n]*https/i', $readme, 'the requirements line keeps permalinks and https' );
	}

	/**
	 * What it checks: The customer release notes say that subfolder installs and networks now work.
	 * Passes when: changelog.txt's top section is still "= 0.1.0 — unreleased =" and has a line naming a subfolder and a line (the same or
	 *   another) naming a multisite or network.
	 * Fails when: the fix ships without a word in the release notes, so customers who were refused never learn they can connect now.
	 */
	public function test_the_changelog_has_a_line_for_subfolders_and_networks(): void {
		$changelog = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= (.+?) =\s*$(.*?)(?=^= |\z)/ms', $changelog, $top ), 'a top section' );
		$this->assertSame( '0.1.0 — unreleased', $top[1], 'still unreleased' );
		$lines = array_filter( explode( "\n", $top[2] ), static fn ( $l ) => str_starts_with( ltrim( $l ), '*' ) );
		$this->assertNotSame( array(), array_filter( $lines, static fn ( $l ) => (bool) preg_match( '/sub-?folder/i', $l ) ), 'a line naming subfolders' );
		$this->assertNotSame( array(), array_filter( $lines, static fn ( $l ) => (bool) preg_match( '/multisite|network/i', $l ) ), 'a line naming networks' );
	}

	/**
	 * What it checks: The translation template follows the code: the subfolder refusal is gone from it.
	 * Passes when: languages/omnabase.pot holds no sentence speaking of a subfolder or the root of the domain, and no code under src/ or
	 *   templates/ still says one.
	 * Fails when: translators keep translating a refusal nobody sees, or the code still shows it to customers in some corner.
	 */
	public function test_the_subfolder_refusal_is_gone_from_the_pot_and_the_code(): void {
		foreach ( $this->msgids( $this->read( 'languages/omnabase.pot' ) ) as $id ) {
			$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', $id, 'the .pot' );
		}
		foreach ( array_merge( $this->filesUnder( 'src' ), $this->filesUnder( 'templates' ) ) as $file ) {
			$this->assertSame( array(), $this->hits( '/root of (its|the) domain|installed in a subfolder/i', $this->read( $file ) ), $file );
		}
	}

	/**
	 * What it checks: Nothing in the plugin names the site by its host alone any more.
	 * Passes when: no code line under src/ or templates/ defines or calls origin() (the old host-only address).
	 * Fails when: a forgotten call sends "https://example.com" for a WordPress in /blog, and OmnaBase files the blog as another site.
	 */
	public function test_no_host_only_origin_is_left(): void {
		foreach ( array_merge( $this->filesUnder( 'src' ), $this->filesUnder( 'templates' ) ) as $file ) {
			$this->assertSame( array(), $this->hits( '/(function\s+origin\s*\(|->origin\s*\(|::origin\s*\()/', $this->codeLines( $this->read( $file ) ) ), $file );
		}
	}

	/**
	 * What it checks: Deleting the plugin from a network goes through every site, as WordPress's own tools do it.
	 * Passes when: uninstall.php's code lines ask whether WordPress runs as a network, list the sites, switch to each and switch back.
	 * Fails when: a network keeps every sub-site's settings, table and customer notes after the plugin is deleted.
	 */
	public function test_uninstall_goes_through_every_site_of_a_network(): void {
		$code = $this->codeLines( $this->read( 'uninstall.php' ) );
		foreach ( array( 'is_multisite(', 'get_sites(', 'switch_to_blog(', 'restore_current_blog(' ) as $call ) {
			$this->assertTrue( str_contains( $code, $call ), "uninstall.php calls {$call}…)" );
		}
	}

	/**
	 * What it checks: The outbox's table name is worked out from the current site every time, never remembered.
	 * Passes when: the outbox's table class has no static property (nothing kept between calls), and its name is built from WordPress's
	 *   current table prefix.
	 * Fails when: a request that switches sites (the network's uninstall, a network-wide job) keeps writing to the first site's table.
	 */
	public function test_the_table_name_is_never_remembered(): void {
		$code = $this->codeLines( $this->read( 'src/Outbox/Table.php' ) );
		$this->assertSame( array(), $this->hits( '/\b(private|protected|public)\s+static\s+(\??[A-Za-z_\\\\]+\s+)?\$\w+/', $code ), 'no static property' );
		$this->assertSame( 1, preg_match( '/function\s+name\s*\(\s*\)\s*:\s*string\s*\{(.*?)\n\t\}/s', $code, $body ), 'the name method' );
		$this->assertMatchesRegularExpression( '/\$wpdb->(prefix|get_blog_prefix\s*\()/', $body[1], 'built from the current prefix' );
	}

	/**
	 * What it checks: The five user notes that belong to one site's connection are never stored as plain, network-wide user meta.
	 * Passes when: no code line under src/ hands the "known" mark, the pending connect, the message centre's fingerprint, its link count or a
	 *   question's kept words, by their plain name, to WordPress's user meta functions (read, write, delete, or delete for every user), and
	 *   uninstall.php does not delete them by their plain stored names alone.
	 * Fails when: on a network one shop's notes about a customer are read by another shop (see the multisite tests: the second shop never
	 *   introduces the customer to its own workspace), or the uninstall leaves the per-site notes behind.
	 */
	public function test_per_site_user_notes_are_never_plain_user_meta(): void {
		$names = implode( '|', array_keys( self::PER_SITE_META ) );
		$meta  = '/\b(get|update|add|delete)_user_meta\s*\(\s*[^,()]+,\s*Options::(' . $names . ')\s*[,)]/';
		$all   = '/\bdelete_metadata\s*\(\s*[\'"]user[\'"]\s*,\s*0\s*,\s*Options::(' . $names . ')\s*,/';
		foreach ( $this->filesUnder( 'src' ) as $file ) {
			$code = $this->codeLines( $this->read( $file ) );
			$this->assertSame( array(), $this->hits( $meta, $code ), "{$file}: a per-site note as plain user meta" );
			$this->assertSame( array(), $this->hits( $all, $code ), "{$file}: a per-site note deleted for every user of the network" );
		}
		$stored = implode( '|', array_map( 'preg_quote', self::PER_SITE_META ) );
		$this->assertSame( array(), $this->hits( '/\bdelete_metadata\s*\(\s*[\'"]user[\'"]\s*,\s*0\s*,\s*[\'"](' . $stored . ')[\'"]/', $this->codeLines( $this->read( 'uninstall.php' ) ) ), 'uninstall.php deletes the per-site notes under their per-site names' );
	}

	/* ---------- helpers ---------- */

	/** A readme section's text (`== Name ==` up to the next `== … ==`), or ''. */
	private function section( string $readme, string $name ): string {
		return 1 === preg_match( '/^== ' . preg_quote( $name, '/' ) . ' ==\s*$(.*?)(?=^== |\z)/ms', $readme, $m ) ? $m[1] : '';
	}

	/** A FAQ answer: the text after `= question =` up to the next heading, trimmed. */
	private function answerTo( string $faq, string $question ): string {
		return 1 === preg_match( '/^= ' . preg_quote( $question, '/' ) . ' =\s*$(.*?)(?=^=|\z)/ms', $faq, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * Every match of a pattern in a text (an empty list when there is none), so a failure names the offending code and not the whole file.
	 *
	 * @return string[]
	 */
	private function hits( string $pattern, string $text ): array {
		preg_match_all( $pattern, $text, $m );
		return $m[0];
	}

	/** The msgids of a .pot. */
	private function msgids( string $pot ): array {
		preg_match_all( '/^msgid ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $pot, $m );
		$ids = array();
		foreach ( $m[1] as $raw ) {
			preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );
			$ids[] = stripcslashes( implode( '', $parts[1] ) );
		}
		return array_values( array_filter( $ids, static fn ( $id ) => '' !== $id ) );
	}

	/** A PHP file's lines that are not comments. */
	private function codeLines( string $php ): string {
		return implode( "\n", array_filter( explode( "\n", $php ), static fn ( $l ) => ! preg_match( '#^\s*(//|\#|\*|/\*)#', $l ) ) );
	}

	/**
	 * The PHP files under a folder, relative to the repository.
	 *
	 * @return string[]
	 */
	private function filesUnder( string $dir ): array {
		$out   = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$out[] = substr( $file->getPathname(), strlen( OMNABASE_REPO ) + 1 );
			}
		}
		sort( $out );
		return $out;
	}

	private function read( string $path ): string {
		$full = OMNABASE_REPO . '/' . $path;
		return is_file( $full ) ? (string) file_get_contents( $full ) : '';
	}
}
