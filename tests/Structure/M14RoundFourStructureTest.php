<?php
/**
 * M14 round four, the red team's failing tests: the settings screen's stylesheet and templates (file-level checks, no WordPress needed): the
 * screen's look comes from OmnaBase's kit tokens through a build step, like the shortcode form's, and WordPress's own tab, table and button
 * classes leave the templates.
 *
 * The founder's order (2026-10-10): "Use a styling similar to the OmnaBase settings where you render the settings nicely in white background
 * cards, and use the canonical tab styling we have in OmnaBase, in the WP backend." The coordinator's contract for round four: a source
 * `styles/admin.css` written against the kit's variables, built by `bin/build-form-css.php` generalised (or a sibling) into the shipped
 * `assets/admin.css`, scoped under the screen's one root class; `bin/build-dist.sh` runs the build; no typeface shipped (the coordinator's
 * ruling: the kit's typeface is an undecided placeholder, the admin keeps the system font); no dark mode this round; round two's dialog styles
 * merge into the built file; the built file starts with a header naming the build script and the tokens' source, holds the scoped variables
 * and no `url(`; the source holds no raw colour; no `nav-tab` and no `form-table` left in the templates.
 *
 * The words: *kit*, OmnaBase's own component library (`packages/ui` in the OmnaBase repository); *tokens*, the kit's named design values
 * (`--ob-bg-surface: #ffffff` …) in its `tokens.css`; *scoped*, every rule and variable sits under one class on the screen's wrapper, so the
 * rest of the admin is untouched and WordPress's own styles cannot reach in; *build step*, a script that writes the shipped file from the
 * source and the tokens (the result is committed); *raw colour*, a colour written as a value (`#2271b1`, `rgb(…)`, `white`) instead of a
 * kit variable; *`url(`*, a stylesheet loading a file (an image, a font) from somewhere.
 *
 * Shapes pinned: the paths `styles/admin.css` and `assets/admin.css`; the root class `.ob-wp-settings` (renamable: the constant below); the
 * header names `bin/<script>.php` and `packages/ui/src/styles/tokens.css` on the built file's first line and `styles/admin.css` in its first
 * comment. Chosen here (the builder may argue in DECISIONS): every `ob-` class the source styles is either the plugin's own (`ob-wp-…`) or
 * exists in the kit's stylesheets (so "reuse the kit's names" can be checked); WordPress's button classes and `submit_button()` leave the
 * admin templates with the tab and table classes; a changelog line for the new look.
 *
 * Why these fail today: there is no `styles/admin.css`; `assets/admin.css` is round two's hand-written dialog styles with raw colours and no
 * header or variables; the templates use `nav-tab-wrapper`, `nav-tab`, `form-table` and `submit_button()`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class M14RoundFourStructureTest extends TestCase {

	/** The screen's root class: the built variables and every rule live under it. */
	private const ROOT = '.ob-wp-settings';

	/** The kit's variables the screen's look cannot do without: the card, the border, the corners, the active tab, the primary button. */
	private const NEEDED = array( '--ob-bg-surface', '--ob-border', '--ob-radius-md', '--ob-accent', '--ob-btn-primary-bg' );

	/**
	 * What it checks: The screen's look has a source stylesheet and a shipped, built one.
	 * Passes when: styles/admin.css and assets/admin.css both exist and are not empty.
	 * Fails when: the look is hand-edited in the shipped file (the next build throws it away), or the shipped file is missing from the zip.
	 */
	public function test_the_admin_stylesheet_has_a_source_and_a_built_file(): void {
		$this->assertNotSame( '', trim( $this->read( 'styles/admin.css' ) ), 'styles/admin.css, the source of the rules' );
		$this->assertNotSame( '', trim( $this->read( 'assets/admin.css' ) ), 'assets/admin.css, the shipped file' );
	}

	/**
	 * What it checks: The shipped stylesheet says, in its first line, which script built it and from which kit tokens.
	 * Passes when: assets/admin.css's first line opens a comment naming a script under bin/ (bin/<name>.php) and OmnaBase's
	 *   packages/ui/src/styles/tokens.css; that first comment also names styles/admin.css; the named script exists.
	 * Fails when: someone edits the shipped file by hand because nothing tells them it is generated, or nobody can rebuild it.
	 */
	public function test_the_built_file_starts_with_a_header_naming_the_build_and_the_tokens(): void {
		$built = $this->read( 'assets/admin.css' );
		$first = strtok( $built, "\n" );
		$this->assertIsString( $first );
		$this->assertStringStartsWith( '/*', $first, 'the first line opens a comment' );
		$this->assertMatchesRegularExpression( '#bin/[a-z0-9-]+\.php#', $first, 'the build script on the first line' );
		$this->assertStringContainsString( 'packages/ui/src/styles/tokens.css', $first, 'the tokens\' source on the first line' );
		$this->assertSame( 1, preg_match( '#^/\*.*?\*/#s', $built, $comment ), 'one header comment' );
		$this->assertStringContainsString( 'styles/admin.css', $comment[0], 'the header names the source of the rules' );
		$this->assertFileExists( OMNABASE_REPO . '/' . $this->buildScript(), 'the named build script exists' );
	}

	/**
	 * What it checks: The shipped stylesheet carries the kit's variables under the screen's own class, and every variable its rules use is
	 *   defined there.
	 * Passes when: assets/admin.css has one block for .ob-wp-settings that sets --ob- variables; no other block (and never :root) sets one;
	 *   every var(--ob-…) the file uses is set in that block; the block sets the card's, border's, corner's, accent's and primary button's
	 *   variables.
	 * Fails when: the kit's colours leak onto every admin page through :root, a rule names a variable nobody set (the card turns transparent,
	 *   the button loses its colour), or the look does not use the kit's card and button values at all.
	 */
	public function test_the_built_file_holds_the_kits_variables_under_the_root_class(): void {
		$built = $this->withoutComments( $this->read( 'assets/admin.css' ) );
		$vars  = $this->rootVariables( $built );
		$this->assertNotSame( array(), $vars, 'a ' . self::ROOT . ' block setting --ob- variables' );
		$this->assertDoesNotMatchRegularExpression( '/:root\b/', $built, 'never :root' );
		preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $built, $blocks, PREG_SET_ORDER );
		$setting = array_filter( $blocks, static fn ( $b ) => (bool) preg_match( '/--ob-[a-z0-9-]+\s*:/', $b[2] ) );
		$this->assertCount( 1, $setting, 'one block sets the variables' );
		$this->assertSame( self::ROOT, trim( array_values( $setting )[0][1] ), 'and it is the root class\'s' );
		preg_match_all( '/var\(\s*(--ob-[a-z0-9-]+)/', $built, $used );
		$missing = array_values( array_diff( array_unique( $used[1] ), array_keys( $vars ) ) );
		$this->assertSame( array(), $missing, 'every variable used is set' );
		foreach ( self::NEEDED as $name ) {
			$this->assertArrayHasKey( $name, $vars, "the look uses {$name}" );
		}
	}

	/**
	 * What it checks: The variables in the shipped stylesheet are the kit's light tokens, unchanged.
	 * Passes when: (with the OmnaBase repository beside this one, or OMNABASE_REPO) every variable in assets/admin.css's .ob-wp-settings block
	 *   has exactly the value of the first block of OmnaBase's tokens.css, and no --ob-font variable is copied.
	 * Fails when: the screen's colours drift from the product's (a hand-tuned blue, a stale build after the tokens changed), or the kit's
	 *   placeholder typeface slips into the admin.
	 */
	public function test_the_variables_are_the_kits_light_tokens(): void {
		$tokens = $this->kitFile( 'packages/ui/src/styles/tokens.css' );
		$light  = $this->lightTokens( $tokens );
		$this->assertNotSame( array(), $light, 'the kit\'s light block' );
		$vars = $this->rootVariables( $this->withoutComments( $this->read( 'assets/admin.css' ) ) );
		$this->assertNotSame( array(), $vars, 'the shipped file\'s variables block' );
		foreach ( $vars as $name => $value ) {
			$this->assertStringStartsNotWith( '--ob-font', $name, 'no typeface variable' );
			$this->assertArrayHasKey( $name, $light, "{$name} is a kit token" );
			$this->assertSame( $light[ $name ], $value, "{$name} has the kit's light value" );
		}
	}

	/**
	 * What it checks: The shipped stylesheet is the build's output: the source's rules follow the variables unchanged, and round two's
	 *   dialog styles are part of them.
	 * Passes when: assets/admin.css contains styles/admin.css's whole text, after the variables block; the source styles the builder's
	 *   dialog (.ob-wp-builder) and its overlay (::backdrop).
	 * Fails when: the shipped file and the source drift apart (a fix lands in one and the next build undoes it), or the dialog's styles are
	 *   lost when the old hand-written file is replaced.
	 */
	public function test_the_built_file_is_the_source_after_the_variables(): void {
		$source = trim( $this->read( 'styles/admin.css' ) );
		$built  = $this->read( 'assets/admin.css' );
		$this->assertNotSame( '', $source );
		$at = strpos( $built, $source );
		$this->assertNotFalse( $at, 'the source\'s rules, unchanged, in the shipped file' );
		$this->assertSame( 1, preg_match( '/' . preg_quote( self::ROOT, '/' ) . '\s*\{[^}]*--ob-/', $built, $m, PREG_OFFSET_CAPTURE ), 'the variables block' );
		$this->assertGreaterThan( $m[0][1], $at, 'the rules after the variables' );
		$this->assertStringContainsString( '.ob-wp-builder', $source, 'the builder\'s dialog' );
		$this->assertStringContainsString( '::backdrop', $source, 'its overlay' );
	}

	/**
	 * What it checks: The source names every colour through a kit variable.
	 * Passes when: styles/admin.css, comments left out, holds no hex colour (#fff, #2271b1 …), no rgb(), rgba(), hsl() or hsla(), and no
	 *   named colour such as white, black, gray or red.
	 * Fails when: a hand-picked colour drifts from the product's palette and stays when the founder changes the tokens.
	 */
	public function test_the_source_names_no_raw_colour(): void {
		$css = $this->withoutComments( $this->read( 'styles/admin.css' ) );
		$this->assertNotSame( '', trim( $css ) );
		$this->assertDoesNotMatchRegularExpression( '/#[0-9a-f]{3,8}\b/i', $css, 'no hex colour' );
		$this->assertDoesNotMatchRegularExpression( '/\b(rgba?|hsla?)\s*\(/i', $css, 'no rgb() or hsl()' );
		$this->assertDoesNotMatchRegularExpression( '/:\s*[^;{}]*(?<![\w-])(white|black|gr[ae]y|silver|red|blue|green|yellow|orange)(?![\w-])[^;{}]*;/i', $css, 'no named colour' );
	}

	/**
	 * What it checks: Every rule of the source applies only inside the screen.
	 * Passes when: each selector of styles/admin.css (inside media queries too) starts with .ob-wp-settings; none names html, body, :root or
	 *   WordPress's own page parts (#wpcontent, #wpbody, .wp-core-ui).
	 * Fails when: the plugin's look changes other admin pages, or WordPress's admin chrome (menus, the toolbar) shifts when the screen loads.
	 */
	public function test_every_rule_of_the_source_is_scoped_under_the_root_class(): void {
		$selectors = $this->selectors( $this->withoutComments( $this->read( 'styles/admin.css' ) ) );
		$this->assertNotSame( array(), $selectors, 'the source has rules' );
		$stray = array_values( array_filter( $selectors, static fn ( $s ) => ! preg_match( '/^' . preg_quote( self::ROOT, '/' ) . '(?![\w-])/', $s ) ) );
		$this->assertSame( array(), $stray, 'every selector starts with ' . self::ROOT );
		foreach ( $selectors as $s ) {
			$this->assertDoesNotMatchRegularExpression( '/(^|[\s>+~,])(html|body)\b|:root|#wpcontent|#wpbody|\.wp-core-ui/', $s, $s );
		}
	}

	/**
	 * What it checks: The screen loads nothing from elsewhere and ships no typeface or dark mode.
	 * Passes when: neither styles/admin.css nor assets/admin.css holds url(, @import or @font-face, a font-family naming "Plus Jakarta", a
	 *   --ob-font variable, or a prefers-color-scheme block.
	 * Fails when: the admin screen fetches a font or an image from another server (slower, a privacy question for the site owner), swaps
	 *   WordPress's system font for the kit's undecided placeholder, or turns dark on its own inside WordPress's light admin.
	 */
	public function test_no_url_no_typeface_and_no_dark_block(): void {
		foreach ( array( 'styles/admin.css', 'assets/admin.css' ) as $file ) {
			$css = $this->withoutComments( $this->read( $file ) );
			$this->assertNotSame( '', trim( $css ), $file );
			$this->assertStringNotContainsString( 'url(', $css, "{$file}: no url(" );
			$this->assertDoesNotMatchRegularExpression( '/@import|@font-face/i', $css, "{$file}: nothing imported" );
			$this->assertStringNotContainsString( 'Plus Jakarta', $css, "{$file}: the system font stays" );
			$this->assertStringNotContainsString( '--ob-font', $css, "{$file}: no typeface variable" );
			$this->assertDoesNotMatchRegularExpression( '/prefers-color-scheme/i', $css, "{$file}: no dark block" );
		}
	}

	/**
	 * What it checks: The build script named in the header really builds the screen's stylesheet from the tokens.
	 * Passes when: the script's code (comments left out) names OmnaBase's tokens.css, styles/admin.css, assets/admin.css and the root class
	 *   .ob-wp-settings; and the form's assets/form.css still names a build script that exists (the form's build keeps working).
	 * Fails when: the header points at a script that does not touch the admin stylesheet, so a rebuild silently leaves it stale, or
	 *   generalising the script breaks the shortcode form's stylesheet.
	 */
	public function test_the_build_script_builds_the_admin_stylesheet(): void {
		$code = $this->codeLines( $this->read( $this->buildScript() ) );
		foreach ( array( 'tokens.css', 'styles/admin.css', 'assets/admin.css', self::ROOT ) as $needle ) {
			$this->assertStringContainsString( $needle, $code, $this->buildScript() . " names {$needle} in code" );
		}
		$this->assertSame( 1, preg_match( '#bin/[a-z0-9-]+\.php#', (string) strtok( $this->read( 'assets/form.css' ), "\n" ), $form ), 'the form\'s header names its script' );
		$this->assertFileExists( OMNABASE_REPO . '/' . $form[0], 'the form\'s build script exists' );
	}

	/**
	 * What it checks: Building the customer zip rebuilds the screen's stylesheet first.
	 * Passes when: bin/build-dist.sh has a line of code (not a comment) that runs the script named in assets/admin.css's header, before the
	 *   files are staged for the zip.
	 * Fails when: a release ships last month's look because nobody remembered to rebuild the stylesheet after the tokens changed.
	 */
	public function test_build_dist_runs_the_admin_build(): void {
		$script = $this->buildScript();
		$dist   = $this->read( 'bin/build-dist.sh' );
		$lines  = array_values( array_filter( explode( "\n", $dist ), static fn ( $l ) => str_contains( $l, $script ) && ! preg_match( '/^\s*#/', $l ) ) );
		$this->assertNotSame( array(), $lines, "bin/build-dist.sh runs {$script}" );
		$this->assertLessThan( (int) strpos( $dist, 'RUNTIME=(' ), (int) strpos( $dist, $lines[0] ), 'before the files are staged' );
	}

	/**
	 * What it checks: WordPress's tab, settings-table and button classes are gone from the admin templates.
	 * Passes when: no file under templates/ names nav-tab (nav-tab-wrapper, nav-tab-active); no file under templates/admin names form-table,
	 *   button-primary, button-secondary, button-small, button-link, gives an element the bare class "button", or calls submit_button() (which
	 *   always prints WordPress's button class).
	 * Fails when: a tab or a button keeps WordPress's look beside the kit's, so the screen looks patched together.
	 */
	public function test_wordpress_tab_table_and_button_classes_leave_the_templates(): void {
		foreach ( $this->filesUnder( 'templates', 'php' ) as $file ) {
			$this->assertStringNotContainsString( 'nav-tab', $this->read( $file ), "{$file}: no nav-tab" );
		}
		$admin = $this->filesUnder( 'templates/admin', 'php' );
		$this->assertNotSame( array(), $admin );
		foreach ( $admin as $file ) {
			$php = $this->read( $file );
			$this->assertStringNotContainsString( 'form-table', $php, "{$file}: no form-table" );
			$this->assertDoesNotMatchRegularExpression( '/\bbutton-(primary|secondary|small|large|link)\b/', $php, "{$file}: no WordPress button look" );
			$this->assertDoesNotMatchRegularExpression( '/class="(?:[^"]*\s)?button(?:\s[^"]*)?"/', $php, "{$file}: no bare button class" );
			$this->assertDoesNotMatchRegularExpression( '/\bsubmit_button\s*\(/', $php, "{$file}: no submit_button()" );
		}
	}

	/**
	 * What it checks: The source reuses the kit's own class names and gives every new one the plugin's prefix.
	 * Passes when: (with the OmnaBase repository beside this one, or OMNABASE_REPO) every class the source styles that starts with "ob-" either
	 *   starts with "ob-wp-" (the plugin's own) or is a class the kit's stylesheets (packages/ui/src/styles) also style; the source styles the
	 *   kit's line tabs, tab item, card, row, page title and button classes.
	 * Fails when: the screen invents look-alike names (ob-form-section beside the kit's ob-form__section), so the two drift apart and the
	 *   founder's "the canonical tab styling" is a copy, not the same thing.
	 */
	public function test_the_source_reuses_the_kits_class_names(): void {
		$kit = '';
		foreach ( glob( $this->kitDir() . '/packages/ui/src/styles/*.css' ) ?: array() as $file ) {
			$kit .= (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$this->assertNotSame( '', $kit, 'the kit\'s stylesheets' );
		preg_match_all( '/\.(ob-[a-z0-9_-]+)/', $kit, $k );
		$known = array_flip( $k[1] );
		preg_match_all( '/\.(ob-[a-z0-9_-]+)/', $this->withoutComments( $this->read( 'styles/admin.css' ) ), $s );
		$used     = array_unique( $s[1] );
		$invented = array_values( array_filter( $used, static fn ( $c ) => ! str_starts_with( $c, 'ob-wp-' ) && ! isset( $known[ $c ] ) ) );
		$this->assertSame( array(), $invented, 'classes that are neither the plugin\'s (ob-wp-…) nor the kit\'s' );
		foreach ( array( 'ob-tabs--line', 'ob-tabs__item', 'ob-tabs__item--active', 'ob-form__section', 'ob-form-row', 'ob-page__title', 'ob-btn--primary', 'ob-btn--outline', 'ob-more' ) as $class ) {
			$this->assertContains( $class, $used, "the source styles the kit's {$class}" );
		}
	}

	/**
	 * What it checks: The unreleased changelog tells customers the settings screen has a new look.
	 * Passes when: changelog.txt's top section is still "0.1.0 — unreleased" and has a line naming the settings screen together with its look,
	 *   design, cards or style.
	 * Fails when: customers updating the plugin find a different-looking screen with no word about it, or a dated (released) section was
	 *   edited.
	 */
	public function test_the_changelog_names_the_new_look(): void {
		$changelog = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= 0\.1\.0 — unreleased =$(.*?)(?:^= |\z)/ms', $changelog, $m ), 'the top section is 0.1.0, unreleased' );
		$this->assertMatchesRegularExpression( '/^\* .*settings.*\b(look|design|cards?|styles?|styling)\b/mi', $m[1], 'a line about the settings screen\'s look' );
	}

	/* ---------- helpers ---------- */

	/** The build script named on assets/admin.css's first line (relative to the repository), or a failure. */
	private function buildScript(): string {
		$this->assertSame( 1, preg_match( '#bin/[a-z0-9-]+\.php#', (string) strtok( $this->read( 'assets/admin.css' ), "\n" ), $m ), 'assets/admin.css\'s first line names its build script' );
		return $m[0];
	}

	/** The OmnaBase repository (OMNABASE_REPO in the environment, or beside this one); the test is skipped without it. */
	private function kitDir(): string {
		$dir = getenv( 'OMNABASE_REPO' ) ? (string) getenv( 'OMNABASE_REPO' ) : dirname( OMNABASE_REPO ) . '/omnabase';
		if ( ! is_readable( $dir . '/packages/ui/src/styles/tokens.css' ) ) {
			$this->markTestSkipped( 'OmnaBase\'s kit is not beside this repository (set OMNABASE_REPO): ' . $dir );
		}
		return $dir;
	}

	/** A file of the OmnaBase repository. */
	private function kitFile( string $path ): string {
		return (string) file_get_contents( $this->kitDir() . '/' . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * The kit's light tokens (the first `:root { … }` block), name => value, comments left out.
	 *
	 * @return array<string,string>
	 */
	private function lightTokens( string $css ): array {
		if ( ! preg_match( '/:root\s*\{([^}]*)\}/', $this->withoutComments( $css ), $block ) ) {
			return array();
		}
		preg_match_all( '/(--ob-[a-z0-9-]+)\s*:\s*([^;]+);/', $block[1], $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $d ) {
			$out[ $d[1] ] = trim( $d[2] );
		}
		return $out;
	}

	/**
	 * The variables set in the root class's block, name => value.
	 *
	 * @return array<string,string>
	 */
	private function rootVariables( string $css ): array {
		if ( ! preg_match( '/(?:^|\})\s*' . preg_quote( self::ROOT, '/' ) . '\s*\{([^}]*)\}/', $css, $block ) ) {
			return array();
		}
		preg_match_all( '/(--ob-[a-z0-9-]+)\s*:\s*([^;]+);/', $block[1], $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $d ) {
			$out[ $d[1] ] = trim( $d[2] );
		}
		return $out;
	}

	/**
	 * Every selector of a stylesheet (comma lists split; at-rule headers and keyframe steps left out; rules inside media queries included).
	 *
	 * @return string[]
	 */
	private function selectors( string $css ): array {
		$out = array();
		preg_match_all( '/([^{};]+)\{/', $css, $m );
		foreach ( $m[1] as $head ) {
			$head = trim( $head );
			if ( '' === $head || str_starts_with( $head, '@' ) ) {
				continue;
			}
			foreach ( explode( ',', $head ) as $selector ) {
				$selector = trim( (string) preg_replace( '/\s+/', ' ', $selector ) );
				if ( '' !== $selector && ! preg_match( '/^(from|to|\d+(\.\d+)?%)$/', $selector ) ) {
					$out[] = $selector;
				}
			}
		}
		return $out;
	}

	/** CSS without its comments. */
	private function withoutComments( string $css ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/** A script's lines that are code, not comments (PHP's `//`, `#`, docblock lines). */
	private function codeLines( string $php ): string {
		return implode( "\n", array_filter( explode( "\n", $php ), static fn ( $l ) => ! preg_match( '#^\s*(//|\#|\*|/\*)#', $l ) ) );
	}

	/** The files under a folder with an extension, relative to the repository. */
	private function filesUnder( string $dir, string $ext ): array {
		if ( ! is_dir( OMNABASE_REPO . '/' . $dir ) ) {
			return array();
		}
		$out = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( OMNABASE_REPO . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( $ext === $f->getExtension() ) {
				$out[] = str_replace( OMNABASE_REPO . '/', '', $f->getPathname() );
			}
		}
		sort( $out );
		return $out;
	}

	/** A repository file's contents ('' when missing). */
	private function read( string $path ): string {
		$file = OMNABASE_REPO . '/' . $path;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
