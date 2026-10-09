<?php
/**
 * M13 round four, the red team's failing tests: the structure guard extended for the shortcode form (file-level checks, no WordPress needed).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) "Round four": "`Forms/Shortcode`, `Forms/Token`, `Forms/Guard`, `Rest/Form`,
 * the stylesheet build"; choice 2: "Front-end pieces are PHP templates with a small stylesheet built from OmnaBase's kit tokens (copied at build
 * from `packages/ui/src/tokens.css` …; scoped under `.ob-wp`, the dark block dropped, the theme's font) and vanilla JS"; choice 1: "every
 * `register_rest_route` with a real `permission_callback` (named exceptions: the two signed receivers, the form and time-token routes)";
 * choice 10: "The key and secrets never reach HTML, JS or a log"; "Uninstall removes every option, usermeta, post meta, transient, scheduled
 * job"; "Files": "`templates/`, `assets/`". The coordinator: "the structure guard's additions (the template, the JS file, no inline secrets)".
 *
 * Shapes the red team pins: the brief's class names; the routes `omnabase/v1/form` and `omnabase/v1/form-token`; (chosen) a front-end
 * template under `templates/` (outside `templates/admin/`) whose name holds "form", a script `assets/**\/*form*.js`, a stylesheet under
 * `assets/` scoped to `.ob-wp`. Round one's guard already refuses a `permission_callback` of `__return_true` on every route, these two
 * included: their own check (the token, the limits) is the callback.
 *
 * Why these fail today: no `src/Forms/`, no `Rest/Form.php`, no template, script or stylesheet, no form routes, no changelog line.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class RoundFourStructureTest extends TestCase {

	private const ROUND_FOUR_FILES = array(
		'src/Forms/Shortcode.php',
		'src/Forms/Token.php',
		'src/Forms/Guard.php',
		'src/Rest/Form.php',
		'tests/e2e/form.spec.ts',
	);

	private const ESCAPERS = array( 'esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'wp_kses_post', 'wp_kses', 'esc_html__', 'esc_attr__', 'absint', 'intval', 'wp_json_encode' );

	/**
	 * What it checks: Round four's parts exist under the brief's names.
	 * Passes when: the shortcode, the token, the guard (honeypot and limits), the form's REST route class and the browser flow exist.
	 * Fails when: the form's work is tangled into one class, so a change to the limits risks breaking what the form shows.
	 */
	public function test_the_round_four_parts_exist(): void {
		$missing = array_values( array_filter( self::ROUND_FOUR_FILES, static fn ( string $f ) => ! file_exists( OMNABASE_REPO . '/' . $f ) ) );
		$this->assertSame( array(), $missing, 'missing: ' . implode( ', ', $missing ) );
	}

	/**
	 * What it checks: The shortcode and the two addresses the form's script calls are registered under the brief's names.
	 * Passes when: the code under src/ registers the shortcode omnabase_form and REST routes /form and /form-token in the omnabase/v1
	 *   namespace.
	 * Fails when: the shortcode documented on the settings screen does nothing, or the script calls an address that does not exist.
	 */
	public function test_the_shortcode_and_the_two_routes_are_registered(): void {
		$src = $this->codeUnder( 'src' );
		$this->assertMatchesRegularExpression( '/add_shortcode\s*\(\s*[\'"]omnabase_form[\'"]/', $src );
		$this->assertMatchesRegularExpression( '#register_rest_route\s*\([^;]*[\'"]/form[\'"]#s', $src, 'the form route' );
		$this->assertMatchesRegularExpression( '#register_rest_route\s*\([^;]*[\'"]/form-token[\'"]#s', $src, 'the time-token route' );
	}

	/**
	 * What it checks: The form's template prints nothing unescaped.
	 * Passes when: a template under templates/ (not the admin's) whose name holds "form" exists, starts with WordPress's direct-access stop, and
	 *   every echo in it begins with an escaping function (esc_html, esc_attr, esc_url, esc_textarea, wp_kses_post and their kin).
	 * Fails when: a product name, a subject attribute or a translated sentence reaches the page as markup, and someone can run script in
	 *   visitors' browsers.
	 */
	public function test_the_form_template_escapes_everything_it_prints(): void {
		$templates = array_values( array_filter( $this->filesUnder( 'templates', 'php' ), static fn ( $f ) => ! str_starts_with( $f, 'templates/admin/' ) && str_contains( basename( $f ), 'form' ) ) );
		$this->assertNotEmpty( $templates, 'a front-end form template under templates/' );
		foreach ( $templates as $file ) {
			$code = $this->read( $file );
			$this->assertMatchesRegularExpression( "/defined\\(\\s*'ABSPATH'\\s*\\)\\s*\\|\\|\\s*exit/", $code, "{$file}: the direct-access stop" );
			$this->assertSame( array(), $this->unescapedEchoes( $code ), "{$file}: unescaped output" );
		}
	}

	/**
	 * What it checks: The form's script holds no secret and talks only to the site's own two addresses.
	 * Passes when: a script under assets/ whose name holds "form" exists, mentions the token, and holds no site key prefix (obw_), no webhook
	 *   secret prefix (whsec_), no Authorization or Bearer header and no address of OmnaBase.
	 * Fails when: a key is written into the script every visitor downloads, or the browser calls OmnaBase directly with the site's
	 *   credentials.
	 */
	public function test_the_form_script_holds_no_secret_and_calls_only_the_site(): void {
		$scripts = array_values( array_filter( $this->filesUnder( 'assets', 'js' ), static fn ( $f ) => str_contains( basename( $f ), 'form' ) ) );
		$this->assertNotEmpty( $scripts, 'the form\'s script under assets/' );
		foreach ( $scripts as $file ) {
			$js = $this->read( $file );
			$this->assertMatchesRegularExpression( '/token/i', $js, "{$file}: fetches the time token" );
			$this->assertDoesNotMatchRegularExpression( '/obw_|whsec_|Authorization|Bearer|omnabase\.com/i', $js, "{$file}: no secret, no OmnaBase address" );
		}
	}

	/**
	 * What it checks: The form's stylesheet comes from OmnaBase's kit tokens, kept inside the plugin's own scope, without the dark block or the
	 *   kit's typeface, and the build knows where the tokens come from.
	 * Passes when: a stylesheet under assets/ uses the kit's --ob- variables under a .ob-wp selector, never sets them on :root, has no
	 *   prefers-color-scheme dark block and no "Plus Jakarta Sans"; a script under bin/ reads tokens.css (a line of code, not a comment).
	 * Fails when: the plugin restyles the whole theme, turns dark on a light site, swaps the theme's font, or the colours drift from the
	 *   product's because nobody knows how they were made.
	 */
	public function test_the_stylesheet_comes_from_the_kit_tokens_in_the_plugins_scope(): void {
		$css = implode( "\n", array_map( fn ( $f ) => $this->read( $f ), $this->filesUnder( 'assets', 'css' ) ) );
		$this->assertMatchesRegularExpression( '/\.ob-wp[^{]*\{[^}]*--ob-[a-z0-9-]+\s*:/s', $css, 'the kit\'s variables under .ob-wp' );
		$this->assertDoesNotMatchRegularExpression( '/:root\s*\{[^}]*--ob-/s', $css, 'never on :root' );
		$this->assertDoesNotMatchRegularExpression( '/prefers-color-scheme\s*:\s*dark/i', $css, 'the dark block dropped' );
		$this->assertStringNotContainsString( 'Plus Jakarta', $css, 'the theme\'s font' );
		$bin   = implode( "\n", array_map( fn ( $f ) => $this->read( $f ), $this->filesUnder( 'bin', '' ) ) );
		$lines = array_filter( explode( "\n", $bin ), static fn ( $l ) => str_contains( $l, 'tokens.css' ) && ! preg_match( '#^\s*(\#|//|\*)#', $l ) );
		$this->assertNotEmpty( $lines, 'a build step (not a comment) reads the kit\'s tokens.css' );
	}

	/**
	 * What it checks: Deleting the plugin removes what the form keeps (tokens, counters, the cached settings, waiting messages).
	 * Passes when: uninstall.php deletes names holding "omnabase_form", or sweeps every temporary value starting "omnabase_".
	 * Fails when: visitors' tokens and counted addresses stay in the site's database after the plugin is gone.
	 */
	public function test_uninstall_removes_what_the_form_keeps(): void {
		$this->assertMatchesRegularExpression( '/omnabase_form|esc_like\(\s*[\'"]omnabase_[\'"]\s*\)/', $this->code( 'uninstall.php' ) );
	}

	/**
	 * What it checks: The customer changelog and the decisions record round four.
	 * Passes when: changelog.txt's unreleased section has a line about the contact form shortcode, and DECISIONS.md has a dated "M13 round four"
	 *   heading.
	 * Fails when: site owners are not told the form exists, or the round's choices are not written down.
	 */
	public function test_the_changelog_and_decisions_record_round_four(): void {
		$changelog = $this->read( 'changelog.txt' );
		$top       = (string) preg_replace( '/^.*?^= \d+\.\d+\.\d+ — unreleased =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
		$this->assertMatchesRegularExpression( '/^\* .*(\[omnabase_form\]|shortcode).*\bform\b|^\* .*\bform\b.*(\[omnabase_form\]|shortcode)/mi', $top );
		$this->assertMatchesRegularExpression( '/^## \d{4}-\d{2}-\d{2} — M13 round four/m', $this->read( 'DECISIONS.md' ) );
	}

	/**
	 * The echo statements whose first word is not an escaping function.
	 *
	 * @param string $code A template's source.
	 * @return string[]
	 */
	private function unescapedEchoes( string $code ): array {
		$tokens = token_get_all( $code );
		$bad    = array();
		foreach ( $tokens as $i => $t ) {
			if ( ! is_array( $t ) || ! in_array( $t[0], array( T_ECHO, T_OPEN_TAG_WITH_ECHO, T_PRINT ), true ) ) {
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

	/** Files under a folder with an extension ('' for any), relative, sorted. */
	private function filesUnder( string $dir, string $extension ): array {
		$out = array();
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && ( '' === $extension || $extension === $f->getExtension() ) ) {
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
