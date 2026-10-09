<?php
/**
 * M13 round six, the red team's failing tests: the structure guard for "ready to hand out" (file-level checks, no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) "Round six": "`readme.txt` complete (description, external services,
 * privacy, FAQ, changelog), the privacy text, personal-data exporter hooks …, i18n-ready strings (a `.pot` through `wp i18n make-pot`) …";
 * its *Red team*: "every user-facing string translatable; … `readme.txt` names app.omnabase.com and the privacy link". The founder's global
 * rules (`~/.claude/CLAUDE.md`, "Customer-Facing Changelog"): an unreleased section is marked unreleased, never dated before it ships; "a
 * version lives in more than one file. Find them all". The plugin's `CLAUDE.md`, "Changelog": "`= 0.1.0 — unreleased =` until the release
 * day". The coordinator: "the readme's sections incl. 'External services' naming app.omnabase.com and what is sent, the privacy content
 * registered, every user-facing string through `__()`/`esc_html__()` with the `omnabase` text domain (a scan of `src/` and `templates/` for
 * bare strings in `echo`/`printf`), the `.pot` present and up to date …, the versions agreeing, the unreleased changelog section and the
 * release script's refusal"; "the version lives in the plugin header, `readme.txt`'s Stable tag and `composer.json`/`package.json`".
 *
 * The words: *text domain*, the name WordPress files a plugin's translations under (`omnabase`); *`.pot`*, the template file translators
 * start from, listing every translatable sentence; *bare string*, words printed to a screen without going through WordPress's translation
 * functions (`__()`, `esc_html__()`, …), so no translation can ever replace them.
 *
 * Shapes pinned: `languages/omnabase.pot`; `package.json` carries `"version"` (composer.json may leave it out, as Composer advises; when it has
 * one, it agrees); `bin/check-version.sh` names package.json; the privacy pieces under `src/Privacy/`; the fresh WordPress for the zip test
 * is a third wp-env from `.wp-env.fresh.json` on port 8890 that maps nothing of the plugin (so deleting the plugin there can never delete
 * this checkout), only the test harness's must-use plugin for its https test host `fresh.omnabase.test`; the records' headings "M13 round
 * six" and "M13: done" in DECISIONS.md.
 *
 * Why these fail today: placeholders in readme.txt, no privacy code, a prose label outside translation in the facts summary, no `.pot`, no
 * version in package.json, no round-six changelog lines, no `.wp-env.fresh.json`, no round-six records. Three guards pass today by design (no
 * bare string in output, every translation call names the domain, the release script refuses an undated section): they keep it so.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class RoundSixStructureTest extends TestCase {

	/** WordPress's translation functions; the domain is their last string argument. */
	private const I18N = array( '__', '_e', '_x', '_ex', '_n', '_nx', 'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x', '_n_noop', '_nx_noop' );

	/**
	 * Prose-looking strings in src/ that are not words for people: SQL, HTTP header values, WordPress's own placeholder salt, a third-party
	 * hook's tag. Each is named, so a new label cannot hide among them.
	 */
	private const TECHNICAL = array( '/^(SELECT|UPDATE|DELETE|INSERT|CREATE|DROP)\b/', '/no-store/', '/^Bearer $/', '/^put your unique phrase here$/', '/^OmnaBase message centre$/' );

	/** Sentences the `.pot` must hold: one per part of the plugin (the screen, the form, the message centre, the outbox, the header). */
	private const POT_SAMPLE = array( 'This site is not connected to OmnaBase yet.', 'Outbox', 'Retry', 'Discard', 'Message centre', 'Shortcodes', 'Connects your site to OmnaBase, the support inbox: forum topics, form messages and your customers\' orders on every ticket.' );

	/**
	 * What it checks: readme.txt is finished: every section WordPress.org shows, no placeholder left.
	 * Passes when: readme.txt holds no "PLACEHOLDER"; it has the sections Description, Installation, Frequently Asked Questions (three questions
	 *   or more), External services, Privacy and Changelog (with a 0.1.0 entry); the short description under the header has at most 150
	 *   characters.
	 * Fails when: the plugin is handed out with "[PLACEHOLDER: …]" on its page, or a site owner finds no install steps, FAQ or privacy note.
	 */
	public function test_the_readme_is_complete(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertStringNotContainsString( 'PLACEHOLDER', $readme );
		foreach ( array( 'Description', 'Installation', 'Frequently Asked Questions', 'External services', 'Privacy', 'Changelog' ) as $name ) {
			$this->assertMatchesRegularExpression( '/^== ' . preg_quote( $name, '/' ) . ' ==$/m', $readme, "the section {$name}" );
		}
		$this->assertGreaterThanOrEqual( 3, preg_match_all( '/^= [^=].*\?\s*=$/m', $this->sectionOf( $readme, 'Frequently Asked Questions' ) ), 'three questions or more' );
		$this->assertMatchesRegularExpression( '/^= 0\.1\.0\b/m', $this->sectionOf( $readme, 'Changelog' ) );
		preg_match( '/^License URI:.*\R\R(.+)$/m', $readme, $short );
		$this->assertNotEmpty( $short[1] ?? '', 'the short description' );
		$this->assertLessThanOrEqual( 150, mb_strlen( trim( $short[1] ) ) );
	}

	/**
	 * What it checks: readme.txt's "External services" says where data goes, what goes, when, and links the terms and privacy policy.
	 * Passes when: the section names app.omnabase.com and Kriesi Media GmbH; names forum topics and replies, form messages, the message
	 *   centre and customer data (orders, subscriptions or licences); says what comes back (replies, status); says nothing is sent before
	 *   connecting; links terms and privacy policy over https on omnabase.com.
	 * Fails when: WordPress.org refuses the plugin for an incomplete disclosure, or a site owner learns only later that customer orders leave
	 *   the site.
	 */
	public function test_external_services_says_what_is_sent_where(): void {
		$section = $this->sectionOf( $this->read( 'readme.txt' ), 'External services' );
		foreach ( array( '/app\.omnabase\.com/', '/Kriesi Media GmbH/', '/forum topics?[^.]*repl(y|ies)/i', '/form messages?/i', '/message centre/i', '/orders|subscriptions|licen[cs]es/i', '/receives?[^.]*(repl(y|ies)|status)/i', '/before (you|an administrator) connects?|until (you|an administrator) connects?/i', '#https://[a-z.]*omnabase\.com/\S*terms#i', '#https://[a-z.]*omnabase\.com/\S*privacy#i' ) as $needle ) {
			$this->assertMatchesRegularExpression( $needle, $section );
		}
	}

	/**
	 * What it checks: The plugin hands WordPress its privacy text and its personal-data exporter, from a part of its own.
	 * Passes when: a file under src/Privacy/ calls wp_add_privacy_policy_content and hooks it at admin_init, and the code adds a filter to
	 *   wp_privacy_personal_data_exporters.
	 * Fails when: the privacy text is never offered to the owner, or is added at a moment WordPress ignores.
	 */
	public function test_the_privacy_pieces_are_registered(): void {
		$privacy = implode( "\n", array_map( fn ( $f ) => $this->code( $f ), $this->filesUnder( 'src/Privacy', 'php' ) ) );
		$this->assertNotSame( '', $privacy, 'src/Privacy/' );
		$this->assertMatchesRegularExpression( '/wp_add_privacy_policy_content\s*\(/', $privacy );
		$this->assertMatchesRegularExpression( '/add_action\s*\(\s*[\'"]admin_init[\'"]/', $privacy );
		$this->assertMatchesRegularExpression( '/add_filter\s*\(\s*[\'"]wp_privacy_personal_data_exporters[\'"]/', $this->codeUnder( 'src' ) );
	}

	/**
	 * What it checks: Nothing is printed to a screen without going through translation (a guard; it passes today and keeps it so).
	 * Passes when: no echo, print, printf or wp_die in src/, templates/ or the main file prints words outside a translation call, and no
	 *   template has words in its plain HTML (shortcode tags inside <code> excepted); the scanner first proves it catches five planted examples.
	 * Fails when: a German shop shows an English sentence that no translation can ever replace.
	 */
	public function test_no_bare_strings_reach_the_screen(): void {
		$planted = "<?php ?>\n<th>Name</th>\n<?php echo esc_html( 'Retry' ); echo '<p>Not connected yet</p>'; printf( 'Hello %s', \$x ); wp_die( 'You may not do that.' ); echo esc_html( \$v['label'] ); esc_html_e( 'Fine here', 'omnabase' ); ?>\n<code>[omnabase_form]</code>";
		$this->assertCount( 5, $this->bareStrings( $planted ), 'the scanner catches its examples: ' . implode( ' | ', $this->bareStrings( $planted ) ) );

		$found = array();
		foreach ( array_merge( $this->filesUnder( 'src', 'php' ), $this->filesUnder( 'templates', 'php' ), array( 'omnabase.php' ) ) as $file ) {
			foreach ( $this->bareStrings( $this->read( $file ) ) as $hit ) {
				$found[] = "{$file}: {$hit}";
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * What it checks: Words meant for people that are built in src/ (labels kept and shown later) go through translation too.
	 * Passes when: every string in src/ that reads as prose (two words or more, or one capitalised word) sits inside a translation call, is an
	 *   array key, or is one of the named technical strings (SQL, header values, WordPress's placeholder salt, LiteSpeed's tag); the plugin
	 *   header's field names (src/PluginInfo.php) are exempt.
	 * Fails when: a label such as the facts summary's "licences (the oldest)" reaches the settings screen in English on every site.
	 */
	public function test_prose_in_src_goes_through_translation(): void {
		$found = array();
		foreach ( $this->filesUnder( 'src', 'php' ) as $file ) {
			if ( 'src/PluginInfo.php' === $file ) {
				continue;
			}
			$tokens = token_get_all( $this->read( $file ) );
			$stack  = array();
			$i18n   = false;
			foreach ( $tokens as $j => $t ) {
				if ( ! is_array( $t ) ) {
					if ( '(' === $t ) {
						$stack[] = $i18n;
						$i18n    = false;
					} elseif ( ')' === $t ) {
						array_pop( $stack );
					}
					continue;
				}
				if ( T_STRING === $t[0] ) {
					$i18n = in_array( ltrim( $t[1], '\\' ), self::I18N, true );
					continue;
				}
				if ( T_WHITESPACE === $t[0] ) {
					continue;
				}
				$i18n = false;
				if ( T_CONSTANT_ENCAPSED_STRING !== $t[0] || in_array( true, $stack, true ) || $this->isArrayKey( $tokens, $j ) ) {
					continue;
				}
				$value = stripslashes( substr( $t[1], 1, -1 ) );
				if ( ! self::reads( $value ) || array() !== array_filter( self::TECHNICAL, static fn ( $p ) => (bool) preg_match( $p, $value ) ) ) {
					continue;
				}
				$found[] = "{$file}:{$t[2]} {$t[1]}";
			}
		}
		$this->assertSame( array(), $found );
	}

	/**
	 * What it checks: Every translation call names the plugin's text domain (a guard; it passes today).
	 * Passes when: in src/, templates/ and the main file, each call to a translation function ends with the string 'omnabase'.
	 * Fails when: a sentence is filed under another plugin's or WordPress's own translations and stays English for every site.
	 */
	public function test_every_translation_call_names_the_text_domain(): void {
		$wrong = array();
		foreach ( array_merge( $this->filesUnder( 'src', 'php' ), $this->filesUnder( 'templates', 'php' ), array( 'omnabase.php' ) ) as $file ) {
			foreach ( $this->translationCalls( $this->read( $file ) ) as [ $line, $strings ] ) {
				if ( 'omnabase' !== end( $strings ) ) {
					$wrong[] = "{$file}:{$line}";
				}
			}
		}
		$this->assertSame( array(), $wrong );
	}

	/**
	 * What it checks: languages/omnabase.pot exists, belongs to this plugin and lists every sentence the code translates.
	 * Passes when: the file exists with a header naming OmnaBase, the version 0.1.0 and the text domain; it holds the sample sentences (the
	 *   settings screen, the outbox, the shortcodes, the plugin's description); and every first string of every translation call in src/,
	 *   templates/ and the main file is one of its msgids.
	 * Fails when: translators start from a missing or old template, so new sentences stay English in every translation.
	 */
	public function test_the_pot_is_present_and_up_to_date(): void {
		$pot = $this->read( 'languages/omnabase.pot' );
		$this->assertNotSame( '', $pot, 'languages/omnabase.pot' );
		$this->assertMatchesRegularExpression( '/^"Project-Id-Version: OmnaBase 0\.1\.0\\\\n"$/m', $pot );
		$this->assertMatchesRegularExpression( '/^"X-Domain: omnabase\\\\n"$/m', $pot );
		$ids = $this->msgids( $pot );
		foreach ( self::POT_SAMPLE as $sentence ) {
			$this->assertContains( $sentence, $ids );
		}
		$missing = array();
		foreach ( array_merge( $this->filesUnder( 'src', 'php' ), $this->filesUnder( 'templates', 'php' ), array( 'omnabase.php' ) ) as $file ) {
			foreach ( $this->translationCalls( $this->read( $file ) ) as [ $line, $strings ] ) {
				if ( ! in_array( $strings[0], $ids, true ) ) {
					$missing[] = "{$file}:{$line} {$strings[0]}";
				}
			}
		}
		$this->assertSame( array(), $missing, 'sentences missing from the .pot (run wp i18n make-pot again)' );
	}

	/**
	 * What it checks: The version agrees in every file that declares it, and the version check reads them all.
	 * Passes when: package.json carries a version equal to the plugin header's; composer.json, if it declares one, agrees; bin/check-version.sh
	 *   reads package.json.
	 * Fails when: the zip says 0.1.1 while the build tooling still says 0.1.0, and nobody notices because the check never looks there.
	 */
	public function test_the_versions_agree_in_package_and_composer_files(): void {
		preg_match( '/^[\s*]*Version:\s*(\S+)/m', $this->read( 'omnabase.php' ), $header );
		$package  = json_decode( $this->read( 'package.json' ), true );
		$composer = json_decode( $this->read( 'composer.json' ), true );
		$this->assertSame( $header[1] ?? '', $package['version'] ?? null, 'package.json "version"' );
		if ( isset( $composer['version'] ) ) {
			$this->assertSame( $header[1] ?? '', $composer['version'], 'composer.json "version"' );
		}
		$this->assertStringContainsString( 'package.json', $this->read( 'bin/check-version.sh' ) );
	}

	/**
	 * What it checks: The first release's notes stay unreleased until the day it ships, and name what round six adds for customers.
	 * Passes when: changelog.txt's top heading is "= 0.1.0 — unreleased =" (no date), and that section has lines about the My Account
	 *   "Support" link, the "theme styles only" switch and the suggested privacy-policy text.
	 * Fails when: the notes are dated before the zip reaches anyone (the founder's rule: the date goes on at release), or site owners are not
	 *   told about the new switches.
	 */
	public function test_the_changelog_stays_unreleased_and_names_round_six(): void {
		$changelog = $this->read( 'changelog.txt' );
		preg_match( '/^= (\d+\.\d+\.\d+) — ([^=]+) =$/m', $changelog, $top );
		$this->assertSame( array( '0.1.0', 'unreleased' ), array( $top[1] ?? '', trim( $top[2] ?? '' ) ), 'the top section is 0.1.0, unreleased' );
		$section = (string) preg_replace( '/^.*?^= 0\.1\.0 — unreleased =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$this->assertMatchesRegularExpression( '/^\* .*My Account/m', $section );
		$this->assertMatchesRegularExpression( '/^\* .*theme(\'s)? styles/mi', $section );
		$this->assertMatchesRegularExpression( '/^\* .*privacy/mi', $section );
	}

	/**
	 * What it checks: The release build refuses a changelog whose top section has no date (a guard; it passes today).
	 * Passes when: bin/build-dist.sh --release, run on a copy of the scripts and the version files, stops with a non-zero exit, says the
	 *   section carries no release date, and makes no zip.
	 * Fails when: a customer zip goes out while its release notes still say "unreleased", or with a date stamped too early.
	 */
	public function test_the_release_build_refuses_an_undated_section(): void {
		$tmp = sys_get_temp_dir() . '/omnabase-release-' . bin2hex( random_bytes( 4 ) );
		mkdir( $tmp . '/bin', 0777, true );
		foreach ( array( 'bin/build-dist.sh', 'bin/check-version.sh', 'bin/build-form-css.php', 'omnabase.php', 'readme.txt', 'composer.json', 'package.json' ) as $file ) {
			if ( is_file( OMNABASE_REPO . '/' . $file ) ) {
				copy( OMNABASE_REPO . '/' . $file, $tmp . '/' . $file );
			}
		}
		chmod( $tmp . '/bin/build-dist.sh', 0755 );
		chmod( $tmp . '/bin/check-version.sh', 0755 );
		preg_match( '/^[\s*]*Version:\s*(\S+)/m', $this->read( 'omnabase.php' ), $v );
		file_put_contents( $tmp . '/changelog.txt', "Notes\n\n= {$v[1]} — unreleased =\n\n* Added: something.\n" );
		exec( 'cd ' . escapeshellarg( $tmp ) . ' && OMNABASE_REPO=/nonexistent bin/build-dist.sh --release 2>&1', $out, $code );
		$zips = glob( $tmp . '/build/dist/*.zip' ) ?: array();
		exec( 'rm -rf ' . escapeshellarg( $tmp ) );
		$this->assertNotSame( 0, $code, implode( "\n", $out ) );
		$this->assertMatchesRegularExpression( '/release date|unreleased/i', implode( "\n", $out ) );
		$this->assertSame( array(), $zips );
	}

	/**
	 * What it checks: The clean WordPress the zip is tested on is a separate one that can never touch this checkout.
	 * Passes when: .wp-env.fresh.json exists, uses port 8890 and PHP 8.1, installs no plugin, maps nothing to wp-content/plugins/omnabase
	 *   or to this folder, and answers as https://fresh.omnabase.test through the test harness (its must-use plugin mapped, the test host
	 *   set), so Connect is offered there as on a real https site.
	 * Fails when: the fresh-install test uploads and deletes the plugin on a WordPress that maps this repository, and deleting the plugin there
	 *   deletes the source code.
	 */
	public function test_the_fresh_site_maps_nothing_of_this_repository(): void {
		$config = json_decode( $this->read( '.wp-env.fresh.json' ), true );
		$this->assertIsArray( $config, '.wp-env.fresh.json' );
		$this->assertSame( 8890, $config['port'] ?? null );
		$this->assertSame( '8.1', (string) ( $config['phpVersion'] ?? '' ) );
		$this->assertSame( array(), array_values( (array) ( $config['plugins'] ?? array() ) ) );
		foreach ( (array) ( $config['mappings'] ?? array() ) as $target => $source ) {
			$this->assertStringNotContainsString( 'plugins/omnabase', (string) $target );
			$this->assertNotContains( rtrim( (string) $source, '/' ), array( '.', './', '..', OMNABASE_REPO ) );
		}
		$this->assertSame( './tests/wp-env/mu-plugins', $config['mappings']['wp-content/mu-plugins'] ?? null, 'the harness, for the https test host' );
		$this->assertSame( 'fresh.omnabase.test', $config['config']['OMNABASE_TEST_HOST'] ?? null );
	}

	/**
	 * What it checks: The plugin's decisions record round six and M13's end.
	 * Passes when: DECISIONS.md has a dated "M13 round six" heading and a dated "M13: done" heading.
	 * Fails when: the round's choices (the switches, the privacy text, the .pot, Plugin Check's warnings) and the milestone's end are not
	 *   written down.
	 */
	public function test_decisions_record_round_six_and_the_end(): void {
		$decisions = $this->read( 'DECISIONS.md' );
		$this->assertSame( 1, preg_match( '/^## \d{4}-\d{2}-\d{2} — M13 round six/m', $decisions ), 'a dated "M13 round six" heading in DECISIONS.md' );
		$this->assertSame( 1, preg_match( '/^## \d{4}-\d{2}-\d{2} — M13: done/m', $decisions ), 'a dated "M13: done" heading in DECISIONS.md' );
	}

	/* ---------- the scanners ---------- */

	/** Whether a string reads as words for people (two words in a row, or one capitalised word), markup and placeholders aside. */
	private static function reads( string $s ): bool {
		$s = (string) preg_replace( '#<code>.*?</code>#s', ' ', $s );
		$s = (string) preg_replace( '#<(script|style)\b.*?</\1>#s', ' ', $s );
		$s = (string) preg_replace( '/\b[a-z][a-z0-9_:-]*=("[^"]*"?|\'[^\']*\'?)/i', ' ', $s );
		$s = (string) preg_replace( '/<[^>]*>/', ' ', $s );
		$s = (string) preg_replace( '/^[^<]*>|<[^>]*$/', ' ', $s );
		$s = (string) preg_replace( '/&[a-z#0-9]+;/i', ' ', $s );
		$s = (string) preg_replace( '/%(\d+\$)?[sdf]/', ' ', $s );
		$s = trim( str_replace( array( '"', "'" ), ' ', $s ) );
		return (bool) preg_match( '/\p{L}{2,}[,.!?]?\s+\p{L}{2,}/u', $s ) || (bool) preg_match( '/^\p{Lu}\p{Ll}+[.!?:]?$/u', $s );
	}

	/**
	 * The words printed outside translation in a PHP file: in plain HTML, or as a string in an echo, print, printf or wp_die.
	 *
	 * @return string[]
	 */
	private function bareStrings( string $code ): array {
		$tokens = token_get_all( $code );
		$n      = count( $tokens );
		$hits   = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && T_INLINE_HTML === $t[0] && self::reads( $t[1] ) ) {
				$hits[] = 'line ' . $t[2] . ': ' . trim( substr( $t[1], 0, 60 ) );
			}
			$out = is_array( $t ) && ( in_array( $t[0], array( T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO ), true ) || ( T_STRING === $t[0] && in_array( $t[1], array( 'printf', 'wp_die' ), true ) ) );
			if ( ! $out ) {
				continue;
			}
			$stack = array();
			$depth = 0;
			$i18n  = false;
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$x = $tokens[ $j ];
				if ( ! is_array( $x ) ) {
					if ( '(' === $x ) {
						++$depth;
						$stack[] = $i18n;
						$i18n    = false;
					} elseif ( ')' === $x ) {
						--$depth;
						array_pop( $stack );
						if ( $depth < 0 ) {
							break;
						}
					} elseif ( ';' === $x && $depth <= 0 ) {
						break;
					}
					continue;
				}
				if ( T_CLOSE_TAG === $x[0] ) {
					break;
				}
				if ( T_STRING === $x[0] ) {
					$i18n = in_array( ltrim( $x[1], '\\' ), self::I18N, true );
					continue;
				}
				if ( T_WHITESPACE === $x[0] ) {
					continue;
				}
				$i18n = false;
				if ( in_array( $x[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && ! in_array( true, $stack, true ) && ! $this->isIndex( $tokens, $j ) && self::reads( stripslashes( trim( $x[1], '\'"' ) ) ) ) {
					$hits[] = 'line ' . $x[2] . ': ' . $x[1];
				}
			}
		}
		return $hits;
	}

	/** Whether the string at $j is an array index (`$a['key']`). */
	private function isIndex( array $tokens, int $j ): bool {
		for ( $k = $j - 1; $k > 0 && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0]; $k-- ) {
		}
		return '[' === $tokens[ $k ];
	}

	/** Whether the string at $j is an array key or index (`'key' =>`, `$a['key']`). */
	private function isArrayKey( array $tokens, int $j ): bool {
		if ( $this->isIndex( $tokens, $j ) ) {
			return true;
		}
		for ( $k = $j + 1; $k < count( $tokens ) && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0]; $k++ ) {
		}
		return isset( $tokens[ $k ] ) && is_array( $tokens[ $k ] ) && T_DOUBLE_ARROW === $tokens[ $k ][0];
	}

	/**
	 * Every translation call in a PHP file: its line and its string arguments in order (the first the sentence, the last the domain).
	 *
	 * @return array<int,array{0:int,1:string[]}>
	 */
	private function translationCalls( string $code ): array {
		$tokens = token_get_all( $code );
		$n      = count( $tokens );
		$calls  = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! in_array( ltrim( $t[1], '\\' ), self::I18N, true ) ) {
				continue;
			}
			for ( $j = $i + 1; $j < $n && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0]; $j++ ) {
			}
			if ( '(' !== ( $tokens[ $j ] ?? '' ) ) {
				continue;
			}
			$prev = $i - 1;
			while ( $prev > 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
				--$prev;
			}
			if ( is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
				continue;
			}
			$depth   = 0;
			$strings = array();
			for ( $k = $j; $k < $n; $k++ ) {
				$x = $tokens[ $k ];
				if ( '(' === $x ) {
					++$depth;
				} elseif ( ')' === $x ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( 1 === $depth && is_array( $x ) && T_CONSTANT_ENCAPSED_STRING === $x[0] ) {
					$strings[] = '"' === $x[1][0] ? stripcslashes( substr( $x[1], 1, -1 ) ) : str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), substr( $x[1], 1, -1 ) );
				}
			}
			if ( array() !== $strings ) {
				$calls[] = array( $t[2], $strings );
			}
		}
		return $calls;
	}

	/** The msgids of a .pot (multi-line ids joined, PO escapes undone). */
	private function msgids( string $pot ): array {
		$ids = array();
		preg_match_all( '/^msgid ((?:"(?:[^"\\\\]|\\\\.)*"\s*)+)/m', $pot, $m );
		foreach ( $m[1] as $raw ) {
			preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );
			$ids[] = stripcslashes( implode( '', $parts[1] ) );
		}
		return array_values( array_filter( $ids, static fn ( $id ) => '' !== $id ) );
	}

	/** A readme section's text, up to the next "== … ==" heading. */
	private function sectionOf( string $readme, string $name ): string {
		return preg_match( '/^== ' . preg_quote( $name, '/' ) . ' ==$(.*?)(?=^== |\z)/ms', $readme, $m ) ? $m[1] : '';
	}

	/** The code of every PHP file under a folder, without comments. */
	private function codeUnder( string $dir ): string {
		return implode( "\n", array_map( fn ( $f ) => $this->code( $f ), $this->filesUnder( $dir, 'php' ) ) );
	}

	/** Files under a folder with an extension, relative, sorted. */
	private function filesUnder( string $dir, string $extension ): array {
		$out = array();
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && $extension === $f->getExtension() ) {
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

	/** A repository file's text ('' when missing). */
	private function read( string $path ): string {
		return is_file( OMNABASE_REPO . '/' . $path ) ? (string) file_get_contents( OMNABASE_REPO . '/' . $path ) : '';
	}
}
