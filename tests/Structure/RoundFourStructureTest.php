<?php
/**
 * THE PLUGIN'S FORMS ROUND (2026-10-25), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the brief (OmnaBase
 * `docs/briefs/plugin-forms-round.md`, "The old routes": "the plugin's time-token and form routes go, with their tests; `Forms.php` keeps only
 * the shortcode and the block"; outcome 1: "'Classic' is gone") retires round four's form: OmnaBase's round three removed the API those routes
 * sent to, and the shortcode now prints OmnaBase's embed. Three tests follow: the parts that must exist are now the shortcode and the browser
 * flow (the time token, the guard and the route class are gone; `FormEmbedStructureTest` refuses them); the registration test still asks for
 * the shortcode and now asks that neither Classic route is registered; the script test still refuses any secret or Authorization header in a
 * form script under assets/, and no longer requires such a script to exist (the embed's script is OmnaBase's, loaded from the app host). What
 * they protected stays protected: the shortcode exists under its name, nothing secret reaches a script, and no route is left without its
 * check (there is no such route left). The template, stylesheet, uninstall and release-notes tests are unchanged.
 *
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the founder's order of 2026-10-21
 * ("option for light and dark themes … should use the styling of our OmnaBase app") and its brief (OmnaBase
 * `docs/briefs/plugin-frontend-design.md`, rules 1 and 2) reverse two of round four's choices for the form's stylesheet: the dark block is now
 * copied (scoped to the plugin's wrapper: `.ob-wp[data-theme="dark"]` and, inside the device's dark setting, `.ob-wp:not([data-theme="light"])`),
 * and the kit's typeface may be loaded when the owner asks for it. So `test_the_stylesheet_comes_from_the_kit_tokens_in_the_plugins_scope` no
 * longer refuses every dark block and every mention of the typeface: it refuses a dark block anywhere but the form's stylesheet, any dark rule
 * not under the plugin's wrapper, and the typeface anywhere but the one stylesheet that declares the font (`@font-face`, loaded only on the
 * option; `FrontendDesignStructureTest` pins that sheet). What it protected stays protected: the theme's page is never recoloured (nothing on
 * `:root`, every dark rule under `.ob-wp`), the settings screen stays light (M14 round four's own guard), and the theme's font stays unless the
 * owner chooses otherwise (the integration and browser layers check the option). Every other test in this file is unchanged.
 *
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED (the release notes' reading only). What changed and why:
 * 0.1.0 was released and dated on 2026-10-13 (commit "Release 0.1.0 (beta)"), and the founder's changelog rule freezes a dated section: new
 * work goes into a new "unreleased" section above it (this round's `FrontendDesignStructureTest` asks for one). This file's release-notes
 * test read "the top section, 0.1.0, unreleased", which has failed since the release and would read the new round's section instead of the
 * one that carries this round's lines. It now reads the 0.1.0 section by its version, whatever its date, and asks the same lines of it; where
 * it asked that the section stay undated, it now asks that the released section keep its release date (frozen). No line it asks for changed.
 *
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
		'tests/e2e/form.spec.ts',
	);

	private const ESCAPERS = array( 'esc_html', 'esc_attr', 'esc_url', 'esc_textarea', 'wp_kses_post', 'wp_kses', 'esc_html__', 'esc_attr__', 'absint', 'intval', 'wp_json_encode' );

	/**
	 * What it checks: The form's parts that outlive the Classic form exist under their names.
	 * Passes when: the shortcode's class and the browser flow exist (the forms round retired the token, the guard and the route class).
	 * Fails when: the shortcode documented on the settings screen has no home of its own, or the form has no browser flow proving it works.
	 */
	public function test_the_round_four_parts_exist(): void {
		$missing = array_values( array_filter( self::ROUND_FOUR_FILES, static fn ( string $f ) => ! file_exists( OMNABASE_REPO . '/' . $f ) ) );
		$this->assertSame( array(), $missing, 'missing: ' . implode( ', ', $missing ) );
	}

	/**
	 * What it checks: The shortcode is registered under its documented name, and the Classic form's two addresses are not.
	 * Passes when: the code under src/ registers the shortcode omnabase_form and registers no REST route /form or /form-token.
	 * Fails when: the shortcode documented on the settings screen does nothing, or a retired address keeps taking messages that go nowhere.
	 */
	public function test_the_shortcode_and_the_two_routes_are_registered(): void {
		$src = $this->codeUnder( 'src' );
		$this->assertMatchesRegularExpression( '/add_shortcode\s*\(\s*[\'"]omnabase_form[\'"]/', $src );
		$this->assertDoesNotMatchRegularExpression( '#register_rest_route\s*\([^;]*[\'"]/form[\'"]#s', $src, 'the Classic form route is gone' );
		$this->assertDoesNotMatchRegularExpression( '#register_rest_route\s*\([^;]*[\'"]/form-token[\'"]#s', $src, 'the Classic time-token route is gone' );
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
	 * What it checks: No form script the plugin ships holds a secret or calls OmnaBase with the site's credentials.
	 * Passes when: every script under assets/ whose name holds "form" (there may be none: the embed's script is OmnaBase's) holds no site key
	 *   prefix (obw_), no webhook secret prefix (whsec_), no Authorization or Bearer header and no address of OmnaBase.
	 * Fails when: a key is written into a script every visitor downloads, or the browser calls OmnaBase with the site's credentials.
	 */
	public function test_the_form_script_holds_no_secret_and_calls_only_the_site(): void {
		$scripts = array_values( array_filter( $this->filesUnder( 'assets', 'js' ), static fn ( $f ) => str_contains( basename( $f ), 'form' ) ) );
		foreach ( $scripts as $file ) {
			$js = $this->read( $file );
			$this->assertDoesNotMatchRegularExpression( '/obw_|whsec_|Authorization|Bearer|omnabase\.com/i', $js, "{$file}: no secret, no OmnaBase address" );
		}
		$this->addToAssertionCount( 1 ); // no form script at all is fine: nothing secret can be in it
	}

	/**
	 * What it checks: The form's stylesheet comes from OmnaBase's kit tokens, kept inside the plugin's own scope; its dark colours apply only
	 *   inside the plugin's own output, and the kit's typeface appears only in the font's own stylesheet; the build knows where the tokens come
	 *   from.
	 * Passes when: a stylesheet under assets/ uses the kit's --ob- variables under a .ob-wp selector and never sets them on :root; a dark block
	 *   (prefers-color-scheme dark, or a data-theme="dark" mark) appears in no stylesheet but the form's, and every selector inside it starts at
	 *   the plugin's wrapper (.ob-wp); "Plus Jakarta Sans" appears in no stylesheet except the one that declares the font (@font-face); a script
	 *   under bin/ reads tokens.css (a line of code, not a comment).
	 * Fails when: the plugin restyles the whole theme, turns the theme's own page dark, swaps the theme's font for every visitor, or the colours
	 *   drift from the product's because nobody knows how they were made.
	 */
	public function test_the_stylesheet_comes_from_the_kit_tokens_in_the_plugins_scope(): void {
		$files = $this->filesUnder( 'assets', 'css' );
		$css   = implode( "\n", array_map( fn ( $f ) => $this->read( $f ), $files ) );
		$this->assertMatchesRegularExpression( '/\.ob-wp[^{]*\{[^}]*--ob-[a-z0-9-]+\s*:/s', $css, 'the kit\'s variables under .ob-wp' );
		$this->assertDoesNotMatchRegularExpression( '/:root\s*\{[^}]*--ob-/s', $css, 'never on :root' );
		foreach ( $files as $file ) {
			$one  = (string) preg_replace( '#/\*.*?\*/#s', '', $this->read( $file ) );
			$dark = (bool) preg_match( '/prefers-color-scheme\s*:\s*dark|data-theme\s*=\s*[\'"]?dark/i', $one );
			if ( 'assets/form.css' !== $file ) {
				$this->assertFalse( $dark, "{$file}: no dark block outside the form's stylesheet" );
			}
			if ( $dark ) {
				preg_match_all( '/([^{}]+)\{/', (string) preg_replace( '/@media[^{]*\{/', '', $one ), $heads );
				foreach ( $heads[1] as $head ) {
					foreach ( array_map( 'trim', explode( ',', $head ) ) as $selector ) {
						if ( '' !== $selector && ! str_starts_with( $selector, '@' ) ) {
							$this->assertStringStartsWith( '.ob-wp', $selector, "{$file}: every rule lives under the plugin's wrapper" );
						}
					}
				}
			}
			if ( ! preg_match( '/@font-face/i', $one ) ) {
				$this->assertStringNotContainsString( 'Plus Jakarta', $one, "{$file}: the theme's font, unless this is the font's own stylesheet" );
			}
		}
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
	 * Passes when: changelog.txt's 0.1.0 section (released 2026-10-13) has a line about the contact form shortcode, and DECISIONS.md has a dated "M13 round four"
	 *   heading.
	 * Fails when: site owners are not told the form exists, or the round's choices are not written down.
	 */
	public function test_the_changelog_and_decisions_record_round_four(): void {
		$changelog = $this->read( 'changelog.txt' );
		$top       = (string) preg_replace( '/^.*?^= 0\.1\.0 — [^=]+ =$(.*?)(?:^= |\z).*$/ms', '$1', $changelog );
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
