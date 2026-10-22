<?php
/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED (the release notes' reading only). What changed and why:
 * 0.1.0 was released and dated on 2026-10-13 (commit "Release 0.1.0 (beta)"), and the founder's changelog rule freezes a dated section: new
 * work goes into a new "unreleased" section above it (this round's `FrontendDesignStructureTest` asks for one). This file's release-notes
 * test read "the top section, 0.1.0, unreleased", which has failed since the release and would read the new round's section instead of the
 * one that carries this round's lines. It now reads the 0.1.0 section by its version, whatever its date, and asks the same lines of it; where
 * it asked that the section stay undated, it now asks that the released section keep its release date (frozen). No line it asks for changed.
 *
 * M13 round five, the red team's failing tests: the structure guard extended for the message centre (file-level checks, no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) "Round five": "`Portal/Shortcode`, `Portal/Controller`, `Portal/Views`,
 * `Portal/NoCache`, `Sync/ProfileWatcher`"; choice 1: "Structure guards, lightly: … no `$_GET`/`$_REQUEST` in the message centre's identity
 * path"; choice 9: "The identity is always the current user's site id in `OmnaBase-Customer`, never from the address, a form field or a
 * cookie of ours"; "at `send_headers` (before any output)"; choice 10: "Uninstall removes every option, usermeta, post meta, transient,
 * scheduled job"; the plugin's `CLAUDE.md` ("`Options::ALL` lists every option name"; "every runtime file starts with `defined( 'ABSPATH' ) ||
 * exit;`"; escape on output). The coordinator: "the structure guard's additions (the template, the JS, the options, the uninstall list)";
 * "the e2e flow (`messages.spec.ts`)".
 *
 * Shapes the red team pins: the brief's class names; (chosen) a front-end template under `templates/` (outside `templates/admin/`) whose name
 * holds "messages" or "portal"; the picked page's option in `Options::ALL` under a name holding "portal" or "messages" (`RoundFiveTestCase`
 * uses `omnabase_portal`); every user meta constant in `Options` named in uninstall.php; a script for the message centre is not required (the
 * brief's posts work without one), but any script whose name holds "messages" or "portal" holds no secret and no OmnaBase address.
 *
 * Why these fail today: no `src/Portal/`, no `Sync/ProfileWatcher.php`, no template, no shortcode, no `send_headers` hook, no option, no
 * changelog line, no flow.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use OmnaBase\WordPress\Options;
use PHPUnit\Framework\TestCase;

final class RoundFiveStructureTest extends TestCase {

	private const ROUND_FIVE_FILES = array(
		'src/Portal/Shortcode.php',
		'src/Portal/Controller.php',
		'src/Portal/Views.php',
		'src/Portal/NoCache.php',
		'src/Sync/ProfileWatcher.php',
		'tests/e2e/messages.spec.ts',
	);

	private const ESCAPERS = array( 'esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'wp_kses_post', 'wp_kses', 'esc_html__', 'esc_attr__', 'esc_html_e', 'esc_attr_e', 'absint', 'intval', 'wp_json_encode', 'wp_nonce_field', 'number_format_i18n' );

	/**
	 * What it checks: Round five's parts exist under the brief's names, each a runtime file with the direct-access stop.
	 * Passes when: the message centre's shortcode, controller (the form posts), views, no-cache part, the profile watcher and the browser flow
	 *   exist, and each PHP file starts with WordPress's direct-access stop.
	 * Fails when: the message centre is one tangled class, so a change to the list risks breaking the no-cache protection or who is asked
	 *   about.
	 */
	public function test_the_round_five_parts_exist(): void {
		$missing = array_values( array_filter( self::ROUND_FIVE_FILES, static fn ( string $f ) => ! file_exists( OMNABASE_REPO . '/' . $f ) ) );
		$this->assertSame( array(), $missing, 'missing: ' . implode( ', ', $missing ) );
		foreach ( array_filter( self::ROUND_FIVE_FILES, static fn ( $f ) => str_ends_with( $f, '.php' ) ) as $file ) {
			$this->assertMatchesRegularExpression( "/defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\|\\s*exit/", $this->read( $file ), "{$file}: the direct-access stop" );
		}
	}

	/**
	 * What it checks: The shortcode is registered under the brief's name and the no-cache work hangs on WordPress's `send_headers` moment.
	 * Passes when: the code under src/ registers the shortcode omnabase_messages and adds an action at send_headers.
	 * Fails when: the documented shortcode does nothing, or the no-cache headers come from the shortcode itself, after the headers were sent
	 *   (the review's S6).
	 */
	public function test_the_shortcode_and_the_send_headers_hook_are_registered(): void {
		$src = $this->codeUnder( 'src' );
		$this->assertMatchesRegularExpression( '/add_shortcode\s*\(\s*[\'"]omnabase_messages[\'"]/', $src );
		$this->assertMatchesRegularExpression( '/add_action\s*\(\s*[\'"]send_headers[\'"]/', $src );
	}

	/**
	 * What it checks: Whom OmnaBase is asked about is never read from the request (the brief's guard on the identity path).
	 * Passes when: at least one file under src/ names the OmnaBase-Customer header, and no such file reads $_GET, $_REQUEST, $_COOKIE or a
	 *   request header ($_SERVER['HTTP_…']); the files under src/Portal/ never read $_COOKIE or $_REQUEST at all.
	 * Fails when: a later change lets an address-bar parameter, a cookie or a header choose whose tickets are shown.
	 */
	public function test_the_identity_path_reads_nothing_from_the_request(): void {
		$naming = array_values( array_filter( $this->filesUnder( 'src', 'php' ), fn ( $f ) => (bool) preg_match( '/omnabase-customer/i', $this->code( $f ) ) ) );
		$this->assertNotEmpty( $naming, 'a file sends the OmnaBase-Customer header' );
		foreach ( $naming as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/\$_(GET|REQUEST|COOKIE)\b|\$_SERVER\s*\[\s*[\'"]HTTP_/', $this->code( $file ), "{$file}: the identity never comes from the request" );
		}
		$portal = $this->filesUnder( 'src/Portal', 'php' );
		$this->assertNotEmpty( $portal, 'src/Portal/' );
		foreach ( $portal as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/\$_(REQUEST|COOKIE)\b/', $this->code( $file ), "{$file}: no request-wide or cookie input" );
		}
	}

	/**
	 * What it checks: The message centre's template prints nothing unescaped.
	 * Passes when: a template under templates/ (not the admin's) whose name holds "messages" or "portal" exists, starts with WordPress's
	 *   direct-access stop, and every echo in it begins with an escaping function.
	 * Fails when: a ticket subject or a message from OmnaBase reaches the page as markup and runs script in a signed-in customer's browser.
	 */
	public function test_the_message_centre_template_escapes_everything(): void {
		$templates = array_values( array_filter( $this->filesUnder( 'templates', 'php' ), static fn ( $f ) => ! str_starts_with( $f, 'templates/admin/' ) && preg_match( '/messages|portal/', basename( $f ) ) ) );
		$this->assertNotEmpty( $templates, 'a front-end message-centre template under templates/' );
		foreach ( $templates as $file ) {
			$code = $this->read( $file );
			$this->assertMatchesRegularExpression( "/defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\|\\s*exit/", $code, "{$file}: the direct-access stop" );
			$this->assertSame( array(), $this->unescapedEchoes( $code ), "{$file}: unescaped output" );
		}
	}

	/**
	 * What it checks: Any script of the message centre holds no secret and talks only to the site.
	 * Passes when: every script under assets/ whose name holds "messages" or "portal" holds no site key prefix (obw_), no webhook secret
	 *   prefix (whsec_), no Authorization or Bearer header and no OmnaBase address; at least one message-centre file exists for the check to
	 *   mean something (the template).
	 * Fails when: the browser is handed the key or calls OmnaBase directly, where a customer could replay the calls for anyone.
	 */
	public function test_message_centre_scripts_hold_no_secret(): void {
		$this->assertNotEmpty( array_filter( $this->filesUnder( 'templates', 'php' ), static fn ( $f ) => (bool) preg_match( '/messages|portal/', basename( $f ) ) ), 'the message centre exists' );
		foreach ( array_filter( $this->filesUnder( 'assets', 'js' ), static fn ( $f ) => (bool) preg_match( '/messages|portal/', basename( $f ) ) ) as $file ) {
			$this->assertDoesNotMatchRegularExpression( '/obw_|whsec_|Authorization|Bearer|omnabase\.com|omnabase-customer/i', $this->read( $file ), "{$file}: no secret, no OmnaBase address" );
		}
	}

	/**
	 * What it checks: What the message centre stores is on the plugin's one list of stored names and removed on uninstall.
	 * Passes when: Options::ALL holds an option whose name holds "portal" or "messages" (the picked page), uninstall.php names it, and every
	 *   user meta constant in Options (names ending in _META) is named in uninstall.php.
	 * Fails when: the picked page or a customer's stored fingerprint stays in the site's database after the plugin is deleted.
	 */
	public function test_the_message_centres_stored_names_are_listed_and_removed(): void {
		$portal = array_values( array_filter( Options::ALL, static fn ( $o ) => (bool) preg_match( '/portal|messages/', $o ) ) );
		$this->assertNotEmpty( $portal, 'the picked page\'s option is on Options::ALL' );
		$uninstall = $this->read( 'uninstall.php' );
		foreach ( $portal as $option ) {
			$this->assertStringContainsString( "'{$option}'", $uninstall );
		}
		foreach ( ( new \ReflectionClass( Options::class ) )->getConstants() as $name => $value ) {
			if ( str_ends_with( $name, '_META' ) ) {
				$this->assertStringContainsString( (string) $value, $uninstall, "user or post meta {$name} is removed on uninstall" );
			}
		}
	}

	/**
	 * What it checks: The customer changelog and the decisions record round five.
	 * Passes when: changelog.txt's 0.1.0 section (released 2026-10-13) has a line about the message centre (or [omnabase_messages]), and DECISIONS.md has a dated
	 *   "M13 round five" heading.
	 * Fails when: site owners are not told their customers can read and answer tickets on the site, or the round's choices are not written
	 *   down.
	 */
	public function test_the_changelog_and_decisions_record_round_five(): void {
		$changelog = $this->read( 'changelog.txt' );
		$top       = (string) preg_replace( '/^.*?^= 0\.1\.0 — [^=]+ =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$this->assertMatchesRegularExpression( '/^\* .*(message centre|\[omnabase_messages\])/mi', $top );
		$this->assertMatchesRegularExpression( '/^## \d{4}-\d{2}-\d{2} — M13 round five/m', $this->read( 'DECISIONS.md' ) );
	}

	/**
	 * The echo statements whose first word is not an escaping function (round four's rule; `_e()` counts as unescaped).
	 *
	 * @param string $code A template's source.
	 * @return string[]
	 */
	private function unescapedEchoes( string $code ): array {
		$tokens = token_get_all( $code );
		$bad    = array();
		foreach ( $tokens as $i => $t ) {
			if ( ! is_array( $t ) ) {
				continue;
			}
			if ( T_STRING === $t[0] && '_e' === $t[1] ) {
				$bad[] = 'line ' . $t[2] . ': _e';
				continue;
			}
			if ( ! in_array( $t[0], array( T_ECHO, T_OPEN_TAG_WITH_ECHO, T_PRINT ), true ) ) {
				continue;
			}
			$next = '';
			for ( $j = $i + 1; $j < count( $tokens ); $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					continue;
				}
				$next = is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ];
				break;
			}
			if ( ! in_array( ltrim( $next, '\\' ), self::ESCAPERS, true ) ) {
				$bad[] = 'line ' . $t[2] . ': ' . $next;
			}
		}
		return $bad;
	}

	/** The code of every PHP file under a folder, without comments, as one text. */
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

	/** A repository file's text. */
	private function read( string $path ): string {
		return is_file( OMNABASE_REPO . '/' . $path ) ? (string) file_get_contents( OMNABASE_REPO . '/' . $path ) : '';
	}
}
