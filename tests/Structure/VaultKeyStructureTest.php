<?php
/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED (the release notes' reading only). What changed and why:
 * 0.1.0 was released and dated on 2026-10-13 (commit "Release 0.1.0 (beta)"), and the founder's changelog rule freezes a dated section: new
 * work goes into a new "unreleased" section above it (this round's `FrontendDesignStructureTest` asks for one). This file's release-notes
 * test read "the top section, 0.1.0, unreleased", which has failed since the release and would read the new round's section instead of the
 * one that carries this round's lines. It now reads the 0.1.0 section by its version, whatever its date, and asks the same lines of it; where
 * it asked that the section stay undated, it now asks that the released section keep its release date (frozen). No line it asks for changed.
 *
 * Fixing round "the vault's key from a file" (2026-10-11), the red team's failing tests: file-level checks (no WordPress needed) that the
 * "Stored connection" card is gone from the code, the translation template and the release notes, that no visible sentence names
 * wp-config.php or OMNABASE_ENCRYPTION_KEY any more, that deleting the plugin removes the key file once for the network, and that the key
 * file is written in one step with a private mode.
 *
 * The founder (2026-10-11): "go with the file-based key, remove the block." The coordinator: "the 'Stored connection' card and its
 * 'Advanced' block go; no sentence about how the connection is locked anywhere … `uninstall.php` removes the key file and its directory (one
 * network); Disconnect keeps the file … the readme's FAQ 'What happens when I delete the plugin?' and the privacy/External services text stay
 * true; a changelog line ('The stored connection is locked with a key the plugin keeps in a protected file; the Advanced block is gone'); the
 * `.pot` regenerated."
 *
 * The words: *template*, a PHP file under `templates/` that prints part of the settings screen; *`.pot`*, the translation template, the list
 * of every sentence translators translate; *visible string*, a sentence a person can read (anything passed to WordPress's translation
 * functions, and any text a template prints); *uninstall*, what runs when an administrator deletes the plugin under Plugins; *network*
 * (multisite), several sites sharing one WordPress and one file system; *code lines*, a file's lines that are not comments; *mode 0600*,
 * file permissions under which only the file's owner may read or write it; *in one step* (atomic), so two requests at the same moment can
 * never each write a different key.
 *
 * Shapes pinned (the builder may rename and tell the red team): the card's file `templates/admin/partials/advanced.php` and its classes
 * `ob-wp-settings__advanced`, `ob-wp-settings__security`, `ob-wp-settings__keysource`, the mark `data-key-source`, the screen data's
 * `keySource` (all to disappear); uninstall.php's per-site part is the closure assigned to `$omnabase_uninstall_site` (as today), and its
 * header comment names "the key file"; the changelog's 0.1.0 section (released 2026-10-13, frozen) holds the lines.
 *
 * Why these fail today: the card's template, its sentences in the `.pot`, the key source on the screen's data and the advice naming
 * wp-config.php and OMNABASE_ENCRYPTION_KEY are all there; uninstall.php knows no key file; the changelog has no such line and still says the
 * detail waits behind "Advanced"; no code writes a key file.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class VaultKeyStructureTest extends TestCase {

	/** The card's sentences (as the `.pot` holds them today). */
	private const CARD_SENTENCES = array(
		'Stored connection',
		'How this site locks the connection it keeps for OmnaBase.',
		'Advanced',
	);

	/** The marks of the card on the screen. */
	private const CARD_MARKS = array( 'advanced.php', 'ob-wp-settings__advanced', 'ob-wp-settings__security', 'ob-wp-settings__keysource', 'data-key-source' );

	/**
	 * What it checks: The "Stored connection" card is gone from the settings screen's code, and the screen no longer asks where the key comes
	 *   from.
	 * Passes when: the card's template file no longer exists; no file under templates/ or src/ names it, its classes or its key-source mark;
	 *   no file under templates/ or src/Admin (the settings screen's code) mentions the key source at all.
	 * Fails when: a leftover include brings the card back, or the screen keeps computing and passing the key's source, a door for the advice
	 *   to return.
	 */
	public function test_the_stored_connection_card_is_gone(): void {
		$this->assertFileDoesNotExist( OMNABASE_REPO . '/templates/admin/partials/advanced.php', 'the card\'s template is deleted' );
		foreach ( array_merge( $this->phpUnder( 'templates' ), $this->phpUnder( 'src' ) ) as $file ) {
			$code = self::codeOf( $this->read( $file ) );
			foreach ( self::CARD_MARKS as $mark ) {
				$this->assertFalse( str_contains( $code, $mark ), "{$file} still names {$mark}" );
			}
		}
		foreach ( array_merge( $this->phpUnder( 'templates' ), $this->phpUnder( 'src/Admin' ) ) as $file ) {
			$this->assertSame( 0, preg_match( '/keySource|key_source/', self::codeOf( $this->read( $file ) ) ), "{$file} still carries the key's source" );
		}
	}

	/**
	 * What it checks: The translation template drops the card's sentences, so translators are not asked to translate a screen that no longer
	 *   exists.
	 * Passes when: languages/omnabase.pot names no line of the card's template and holds none of its sentences ("Stored connection", "How
	 *   this site locks …", "Advanced") and no sentence naming wp-config.php or OMNABASE_ENCRYPTION_KEY.
	 * Fails when: the `.pot` was not regenerated, so translators work on dead sentences and the release check (which compares the `.pot` with
	 *   the code) fails at release time.
	 */
	public function test_the_translation_template_drops_the_cards_sentences(): void {
		$pot = $this->read( 'languages/omnabase.pot' );
		$this->assertNotSame( '', $pot, 'the .pot exists' );
		$this->assertFalse( str_contains( $pot, 'advanced.php' ), 'no reference to the card\'s template' );
		$ids = self::msgids( $pot );
		foreach ( self::CARD_SENTENCES as $sentence ) {
			$this->assertNotContains( $sentence, $ids, "the .pot still holds \"{$sentence}\"" );
		}
		foreach ( $ids as $id ) {
			$this->assertDoesNotMatchRegularExpression( '/OMNABASE_ENCRYPTION_KEY|wp-config/i', $id, 'a sentence for translators names the settings file or the constant' );
		}
	}

	/**
	 * What it checks: No sentence a person can read anywhere in the plugin names wp-config.php or OMNABASE_ENCRYPTION_KEY, or says how the
	 *   connection is locked.
	 * Passes when: no sentence handed to WordPress's translation functions under src/ or templates/ names wp-config.php, the constant, or the
	 *   words "encryption" or "locked"; no template prints either name anywhere in its code; the constant's name appears in src/ only as the
	 *   bare name the vault looks up, and only under the security code.
	 * Fails when: the advice comes back in a notice, a Details line or a help text, telling an owner to edit a settings file the founder
	 *   decided they should never have to touch.
	 */
	public function test_no_visible_string_names_the_constant_or_wp_config(): void {
		foreach ( array_merge( $this->phpUnder( 'src' ), $this->phpUnder( 'templates' ) ) as $file ) {
			foreach ( self::translated( $this->read( $file ) ) as $sentence ) {
				$this->assertDoesNotMatchRegularExpression( '/OMNABASE_ENCRYPTION_KEY|wp-config|\bencrypt\w*|\blocked\b/i', $sentence, "{$file}: \"{$sentence}\"" );
			}
		}
		foreach ( $this->phpUnder( 'templates' ) as $file ) {
			$this->assertSame( 0, preg_match( '/OMNABASE_ENCRYPTION_KEY|wp-config/i', self::codeOf( $this->read( $file ) ) ), "{$file} prints the settings file or the constant" );
		}
		foreach ( $this->phpUnder( 'src' ) as $file ) {
			foreach ( self::literals( $this->read( $file ) ) as $literal ) {
				$this->assertDoesNotMatchRegularExpression( '/wp-config/i', $literal, "{$file}: a text names wp-config.php" );
				if ( str_contains( $literal, 'OMNABASE_ENCRYPTION_KEY' ) ) {
					$this->assertSame( 'OMNABASE_ENCRYPTION_KEY', $literal, "{$file}: the constant's name inside a sentence" );
					$this->assertStringStartsWith( 'src/Security/', $file, 'only the security code looks the constant up' );
				}
			}
		}
	}

	/**
	 * What it checks: Deleting the plugin removes the key file and its folder, once for the whole network, and the uninstall's own list says
	 *   so.
	 * Passes when: uninstall.php's header comment names "the key file"; its code outside the per-site part removes a file or folder (or asks
	 *   the plugin's own code to remove the key file); the per-site part (run once per site of a network) removes no file or folder.
	 * Fails when: a deleted plugin leaves its key behind in wp-content (the readme promises everything goes), or a network's uninstall tries
	 *   to delete the shared file once per site.
	 */
	public function test_uninstall_removes_the_key_file_once_for_the_network(): void {
		$uninstall = $this->read( 'uninstall.php' );
		$this->assertSame( 1, preg_match( '/key file/i', $uninstall ), 'the header names the key file among what goes' );
		$code  = self::codeOf( $uninstall );
		$start = strpos( $code, '$omnabase_uninstall_site = static function' );
		$this->assertNotFalse( $start, 'the per-site part is where it was' );
		$end      = strpos( $code, "\n};", (int) $start );
		$per_site = substr( $code, (int) $start, (int) $end - (int) $start );
		$outside  = substr( $code, 0, (int) $start ) . substr( $code, (int) $end );
		$removes  = '/\b(unlink|rmdir|wp_delete_file|wp_delete_file_from_directory)\s*\(|(->|::)\s*(remove|delete|forget|destroy|erase|wipe)\w*\s*\(/i';
		$this->assertSame( 0, preg_match( '/\b(unlink|rmdir|wp_delete_file)\s*\(/', $per_site ), 'the per-site part removes no file' );
		$this->assertSame( 1, preg_match( $removes, $outside ), 'the key file goes once, outside the per-site part' );
	}

	/**
	 * What it checks: The release notes tell customers the connection is now locked with a key in a protected file and that the Advanced block
	 *   is gone, and no other line still promises an Advanced block.
	 * Passes when: changelog.txt's 0.1.0 section (released 2026-10-13, frozen), has a line naming a key kept in a file, has a line saying the
	 *   Advanced block is gone (or removed), every line naming "Advanced" says it is gone, and no line names wp-config.php, the constant,
	 *   "salts" or "encryption key".
	 * Fails when: customers are not told, or the notes still say the detail waits behind "Details" and "Advanced" while the screen has no
	 *   Advanced block.
	 */
	public function test_the_changelog_says_the_key_file_and_the_advanced_blocks_end(): void {
		$changelog = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= 0\.1\.0 — [^=]+ =$(.*?)(?:^= |\z)/ms', $changelog, $m ), 'the 0.1.0 section (released 2026-10-13)' );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $m[1] ) ), static fn ( $l ) => str_starts_with( $l, '* ' ) ) );
		$this->assertNotEmpty( array_filter( $lines, static fn ( $l ) => preg_match( '/\bkey\b/i', $l ) && preg_match( '/\bprotected file\b|\bfile\b/i', $l ) ), 'a line about the key kept in a file' );
		$advanced = array_values( array_filter( $lines, static fn ( $l ) => str_contains( $l, 'Advanced' ) ) );
		$this->assertNotEmpty( $advanced, 'a line about the Advanced block' );
		foreach ( $advanced as $line ) {
			$this->assertMatchesRegularExpression( '/\b(gone|removed|no longer)\b/i', $line, "a line still promises the Advanced block: {$line}" );
		}
		foreach ( $lines as $line ) {
			$this->assertDoesNotMatchRegularExpression( '/wp-config|OMNABASE_ENCRYPTION_KEY|\bsalts\b|\bencryption key\b/i', $line, "a developer word in: {$line}" );
		}
	}

	/**
	 * What it checks: The readme stays true: deleting the plugin still removes everything it stored, the external-services text still says
	 *   nothing is sent before Connect, and nothing mentions the removed card.
	 * Passes when: readme.txt's FAQ still asks "What happens when I delete the plugin?" and answers that it removes everything it stored on
	 *   the site; the External services section still says nothing is sent before you connect; the readme names neither "Stored connection"
	 *   nor an "Advanced" block.
	 * Fails when: the plugin's public page promises a clean removal the code does not deliver, or points owners to a block that no longer
	 *   exists.
	 */
	public function test_the_readme_stays_true(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame( 1, preg_match( '/^= What happens when I delete the plugin\? =\s*\R+[^=]*removes everything it stored on your site/m', $readme ), 'the FAQ\'s promise' );
		$this->assertSame( 1, preg_match( '/== External services ==.*Nothing is sent before you connect/s', $readme ), 'the external-services text' );
		$this->assertStringNotContainsString( 'Stored connection', $readme );
		$this->assertDoesNotMatchRegularExpression( '/"Advanced"|Advanced block/', $readme );
	}

	/**
	 * What it checks: The code that makes the key file makes it in one step and private from the start.
	 * Passes when: the runtime code under src/ creates a file only if it does not exist yet (fopen's "x" mode) or writes it elsewhere first
	 *   and moves it into place (rename or link), and sets the private mode 0600 (or 0400).
	 * Fails when: two administrators clicking Connect at the same moment each write a different key (one of the two connections can never be
	 *   read again), or the file is born readable by everyone and only tightened afterwards.
	 */
	public function test_the_key_file_is_written_in_one_step_with_a_private_mode(): void {
		$code = implode( "\n", array_map( fn ( $f ) => self::codeOf( $this->read( $f ) ), $this->phpUnder( 'src' ) ) );
		$this->assertSame( 1, preg_match( '/\bfopen\s*\([^;]*[\'"]x[bt+]*[\'"]|\brename\s*\(|\blink\s*\(/', $code ), 'made in one step (fopen\'s "x" mode, or rename/link into place)' );
		$this->assertSame( 1, preg_match( '/\b0[64]00\b/', $code ), 'a private mode (0600 or 0400)' );
	}

	/* ---------- helpers ---------- */

	/**
	 * Every PHP file under a repository folder, relative to the repository.
	 *
	 * @return string[]
	 */
	private function phpUnder( string $dir ): array {
		$out = array();
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return $out;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			if ( 'php' === $item->getExtension() ) {
				$out[] = $dir . substr( $item->getPathname(), strlen( OMNABASE_REPO . '/' . $dir ) );
			}
		}
		sort( $out );
		return $out;
	}

	/** A PHP file without its comments (its code lines and any HTML it prints). */
	private static function codeOf( string $php ): string {
		$out = '';
		foreach ( token_get_all( $php ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}

	/**
	 * Every quoted string of a PHP file, unquoted.
	 *
	 * @return string[]
	 */
	private static function literals( string $php ): array {
		$out = array();
		foreach ( token_get_all( $php ) as $token ) {
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
				$out[] = stripcslashes( substr( $token[1], 1, -1 ) );
			}
		}
		return $out;
	}

	/**
	 * The first quoted argument of every call to WordPress's translation functions in a PHP file.
	 *
	 * @return string[]
	 */
	private static function translated( string $php ): array {
		$tokens = token_get_all( $php );
		$names  = array( '__', '_e', '_x', '_ex', '_n', '_nx', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x' );
		$out    = array();
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $names, true ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] || '(' === $tokens[ $j ] ) {
					continue;
				}
				if ( is_array( $tokens[ $j ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $j ][0] ) {
					$out[] = stripcslashes( substr( $tokens[ $j ][1], 1, -1 ) );
				}
				break;
			}
		}
		return $out;
	}

	/**
	 * Every msgid of a `.pot` (multi-line ids joined).
	 *
	 * @return string[]
	 */
	private static function msgids( string $pot ): array {
		preg_match_all( '/^msgid ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $pot, $m );
		$ids = array();
		foreach ( $m[1] as $raw ) {
			preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );
			$ids[] = stripcslashes( implode( '', $parts[1] ) );
		}
		return $ids;
	}

	/** A repository file's contents ('' when missing). */
	private function read( string $path ): string {
		$file = OMNABASE_REPO . '/' . $path;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
