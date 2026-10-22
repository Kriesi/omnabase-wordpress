<?php
/**
 * The front-end design round (light and dark, the form, the message centre), the red team's failing tests: file-level guards over the
 * stylesheet the form and the message centre load, the font files, the build script, the release notes and the version.
 *
 * Brief (OmnaBase `docs/briefs/plugin-frontend-design.md`, 2026-10-21) rule 1: "`bin/build-css.php` copies from OmnaBase's `tokens.css` every
 * semantic token the plugin's rules use, in both the light block and the dark block, scoped as `.ob-wp` (light), `.ob-wp[data-theme="dark"]`
 * and, for "system", `@media (prefers-color-scheme: dark) .ob-wp:not([data-theme="light"])`; raw palette tokens never appear in
 * `styles/form.css` …; notices and errors use semantic tokens (added to `tokens.css` in `packages/ui` if the kit lacks them, with their dark
 * values …). The built file stays committed; the first line keeps naming the script." Rule 2: the font "the plugin ships the variable woff2
 * and loads it only then"; the accent "as the app does". Rule 7: "Version and changelog per the plugin's own files; DECISIONS in the plugin
 * repo". The red team's report: `docs/reviews/plugin-frontend-design-red-team-report.md` in the OmnaBase repository.
 *
 * The words: *token*, one of OmnaBase's named design values (`--ob-bg-surface: #ffffff` …) in `packages/ui/src/styles/tokens.css`; *semantic
 * token*, one named for its job (`--ob-text`, `--ob-bg-surface`), as against a *raw palette token* named for its colour (`--ob-red-500`), which
 * has no dark version; *dark block*, the set of the same semantic names with their night values; *scope*, the CSS selector the values live
 * under (`.ob-wp`, the wrapper of the plugin's output, so the theme's page is never touched); *contrast*, how far apart two colours are in
 * brightness (WCAG's ratio: 4.5 to 1 for ordinary text, 3 to 1 for a focus ring); *woff2*, the web font file format; *OFL*, the SIL Open Font
 * Licence the typeface comes under, which asks that its text travel with the files.
 *
 * Shapes the red team pins (chosen where the brief names none; the builder may rename and tell the red team; every name sits in a constant):
 * the font folder `assets/fonts/` with the kit's two file names and `OFL.txt`; the font in its own stylesheet (any file under `assets/` other
 * than form.css and admin.css), the only file with `@font-face`; the accent blocks named as the kit names them, with `:root` read as `.ob-wp`.
 * The tests that compare with OmnaBase's live files need the OmnaBase repository beside this one (or OMNABASE_REPO) and skip without it, as
 * M14 round four's do (so CI, which has only this repository, does not run them: see the report's finding on drift).
 *
 * Why these fail today: the built stylesheet has only the light block (and that one is already a week stale: OmnaBase changed
 * `--ob-bg-muted` on 2026-10-16), the rules use `--ob-red-500`, `--ob-green-100`, `--ob-amber-*`, the error colour reads at 4.2 to 1, there is
 * no font file, no font stylesheet, the build script reads only the light block, and the changelog's top section is the dated 0.1.0.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Structure;

use PHPUnit\Framework\TestCase;

final class FrontendDesignStructureTest extends TestCase {

	/** The built stylesheet the form and the message centre load, its source, and the wrapper's class. */
	private const BUILT  = 'assets/form.css';
	private const SOURCE = 'styles/form.css';
	private const SCOPE  = '.ob-wp';

	/** The three scopes the brief names (attribute quotes and spaces are read loosely). */
	private const DARK_SELECTOR   = '.ob-wp[data-theme="dark"]';
	private const SYSTEM_SELECTOR = '.ob-wp:not([data-theme="light"])';

	/** The accent blocks, the kit's with `:root` read as `.ob-wp`. */
	private const ACCENT_SELECTOR        = '.ob-wp[data-accent]';
	private const ACCENT_DARK_SELECTOR   = '.ob-wp[data-accent][data-theme="dark"]';
	private const ACCENT_SYSTEM_SELECTOR = '.ob-wp[data-accent]:not([data-theme="light"])';

	/** The kit's font files (OmnaBase `packages/ui/src/fonts/`), shipped under the plugin's font folder. */
	private const FONT_DIR   = 'assets/fonts';
	private const FONT_FILES = array( 'plus-jakarta-sans-latin-wght-normal.woff2', 'plus-jakarta-sans-latin-ext-wght-normal.woff2', 'OFL.txt' );

	/** The raw palette families of the kit (named for a colour, not a job): never in the plugin's rules. */
	private const PALETTE = '/var\(\s*--ob-(gray|amber|green|blue|orange|red|indigo|night)-[0-9a-z]+/i';

	/** Variables that carry data rather than a theme's colour (the workspace's accent and its text): they need no dark value. */
	private const DATA_VARIABLES = array( '--ob-accent-user', '--ob-on-accent-user' );

	/** The version of the release already out, frozen since it was dated. */
	private const RELEASED = '0.1.0';
	private const RELEASED_HEADING = '= 0.1.0 — 2026-10-13 =';

	/**
	 * What it checks: The stylesheet the form and the message centre load carries a dark set of colours in the three places the brief names:
	 *   the light set on the plugin's wrapper, the dark set when the owner chose Dark, and the dark set inside "the visitor's device is dark"
	 *   for the "Follow the visitor's system" choice.
	 * Passes when: the built stylesheet has a block for the wrapper, one for the wrapper marked dark and, inside a block that applies only when
	 *   the visitor's device is set to dark, one for the wrapper not marked light; both dark blocks hold the same names with the same values,
	 *   say that the page is dark (so the browser draws its own parts of a select box and a scroll bar dark too), and nothing sets OmnaBase's
	 *   colours on the whole page.
	 * Fails when: the dark colours are copied but never applied (a block nobody's markup matches), only one of the two dark ways works, the
	 *   two disagree so "Dark" and "the visitor's system" look different, or the plugin recolours the theme's whole page.
	 */
	public function test_the_built_stylesheet_has_the_dark_block_in_the_three_scopes(): void {
		$blocks = $this->blocks( $this->withoutComments( $this->read( self::BUILT ) ) );
		$light  = $this->declarationsOf( $blocks, self::SCOPE );
		$dark   = $this->declarationsOf( $blocks, self::DARK_SELECTOR );
		$system = $this->declarationsOf( $blocks, self::SYSTEM_SELECTOR, 'prefers-color-scheme: dark' );

		$this->assertNotSame( array(), $light, 'the light block on the wrapper' );
		$this->assertNotSame( array(), $dark, 'a block for the wrapper marked dark (' . self::DARK_SELECTOR . ')' );
		$this->assertNotSame( array(), $system, 'inside @media (prefers-color-scheme: dark), a block for ' . self::SYSTEM_SELECTOR );
		$this->assertEquals( $dark, $system, 'Dark and "the visitor\'s system" in dark set the same names to the same values' );
		$this->assertSame( 'dark', $dark['color-scheme'] ?? null, 'the dark block tells the browser the area is dark' );
		$this->assertDoesNotMatchRegularExpression( '/(^|[},\s]):root\b/', $this->withoutComments( $this->read( self::BUILT ) ), 'nothing set on the whole page' );
		foreach ( array_keys( $dark ) as $name ) {
			if ( str_starts_with( $name, '--ob-' ) && ! str_starts_with( $name, '--ob-night-' ) && ! preg_match( '/^--ob-blue-\d/', $name ) ) {
				$this->assertArrayHasKey( $name, $light, "{$name} is changed for dark but has no light value on the wrapper" );
			}
		}
	}

	/**
	 * What it checks: The light and the dark colours in the shipped stylesheet are OmnaBase's live ones, not a copy that went stale.
	 * Passes when: (with the OmnaBase repository beside this one) every variable of the wrapper's light block has the value of OmnaBase's
	 *   light block; every variable of the dark block has the value of OmnaBase's dark block; every colour OmnaBase changes for dark that the
	 *   wrapper uses is changed in the plugin's dark block too; and every night colour the dark block refers to is defined with OmnaBase's value.
	 * Fails when: the plugin's form shows last month's grey after OmnaBase changed it (today: the muted background, changed on 2026-10-16, is
	 *   still the old one in the plugin), or a colour stays light in the plugin's dark mode because the copy missed it.
	 */
	public function test_the_light_and_dark_values_are_omnabase_live_tokens(): void {
		$kit    = $this->kitBlocks();
		$blocks = $this->blocks( $this->withoutComments( $this->read( self::BUILT ) ) );
		$light  = $this->variables( $this->declarationsOf( $blocks, self::SCOPE ) );
		$dark   = $this->variables( $this->declarationsOf( $blocks, self::DARK_SELECTOR ) );
		$this->assertNotSame( array(), $light, 'the wrapper\'s light block' );
		$this->assertNotSame( array(), $dark, 'the wrapper\'s dark block' );

		foreach ( $light as $name => $value ) {
			$this->assertArrayHasKey( $name, $kit['primitives'], "{$name} is one of OmnaBase's tokens" );
			$this->assertSame( $kit['primitives'][ $name ], $value, "{$name} has OmnaBase's live light value" );
		}
		foreach ( $dark as $name => $value ) {
			$expected = $kit['dark'][ $name ] ?? $kit['primitives'][ $name ] ?? null;
			$this->assertNotNull( $expected, "{$name} in the dark block is one of OmnaBase's tokens" );
			$this->assertSame( $expected, $value, "{$name} has OmnaBase's live dark value" );
		}
		foreach ( array_keys( $light ) as $name ) {
			if ( isset( $kit['dark'][ $name ] ) ) {
				$this->assertArrayHasKey( $name, $dark, "{$name} changes in OmnaBase's dark theme, so the plugin's dark block changes it too" );
			}
		}
		$all = $light + $dark;
		foreach ( $dark as $name => $value ) {
			preg_match_all( '/var\(\s*(--ob-[a-z0-9-]+)/', $value, $refs );
			foreach ( $refs[1] as $ref ) {
				$this->assertArrayHasKey( $ref, $all, "{$name} refers to {$ref}, which the stylesheet must define" );
			}
		}
	}

	/**
	 * What it checks: A site's own accent colour wins in both themes, because its blocks come after the dark blocks they must override.
	 * Passes when: the built stylesheet has the accent block for the wrapper (the accent and the text on it taken from the owner's colour), the
	 *   same for the wrapper marked dark, and, inside the "device is dark" block, for the wrapper not marked light; each dark accent block comes
	 *   later in the file than the dark block of the same strength; and (with the OmnaBase repository beside this one) each holds OmnaBase's own
	 *   accent rules.
	 * Fails when: the owner picks green, and the dark form still shows OmnaBase's blue because the dark block, written later with the same
	 *   strength, quietly wins (the trap OmnaBase's own tokens file warns about).
	 */
	public function test_the_accent_blocks_follow_the_dark_blocks(): void {
		$css    = $this->withoutComments( $this->read( self::BUILT ) );
		$blocks = $this->blocks( $css );
		$accent = $this->declarationsOf( $blocks, self::ACCENT_SELECTOR );
		$this->assertSame( 'var(--ob-accent-user)', $accent['--ob-accent'] ?? null, 'the accent follows the owner\'s colour' );
		$this->assertSame( 'var(--ob-on-accent-user)', $accent['--ob-on-accent'] ?? null, 'the text on it follows the owner\'s colour' );
		$this->assertNotSame( array(), $this->declarationsOf( $blocks, self::ACCENT_DARK_SELECTOR ), 'the accent again for Dark' );
		$this->assertNotSame( array(), $this->declarationsOf( $blocks, self::ACCENT_SYSTEM_SELECTOR, 'prefers-color-scheme: dark' ), 'the accent again for the system\'s dark' );

		$order = $this->selectorOrder( $blocks );
		$this->assertGreaterThan( $order[ self::norm( self::DARK_SELECTOR ) ] ?? PHP_INT_MAX, $order[ self::norm( self::ACCENT_DARK_SELECTOR ) ] ?? -1, 'the dark accent block comes after the dark block' );
		$this->assertGreaterThan( $order[ self::norm( self::SYSTEM_SELECTOR ) ] ?? PHP_INT_MAX, $order[ self::norm( self::ACCENT_SYSTEM_SELECTOR ) ] ?? -1, 'the system accent block comes after the system dark block' );

		$kit   = $this->kitBlocks();
		$light = $this->variables( $this->declarationsOf( $blocks, self::SCOPE ) );
		foreach ( array( self::ACCENT_SELECTOR => 'accent', self::ACCENT_DARK_SELECTOR => 'accentDark', self::ACCENT_SYSTEM_SELECTOR => 'accentSystem' ) as $selector => $key ) {
			$media = self::ACCENT_SYSTEM_SELECTOR === $selector ? 'prefers-color-scheme: dark' : '';
			$built = $this->variables( $this->declarationsOf( $blocks, $selector, $media ) );
			foreach ( $built as $name => $value ) {
				$this->assertSame( $kit[ $key ][ $name ] ?? null, $value, "{$selector}: {$name} is OmnaBase's own accent rule" );
			}
			foreach ( array_keys( $kit[ $key ] ) as $name ) {
				if ( isset( $light[ $name ] ) ) {
					$this->assertArrayHasKey( $name, $built, "{$selector}: the rules use {$name}, so the owner's colour must reach it" );
				}
			}
		}
	}

	/**
	 * What it checks: The front end's rules use only OmnaBase's named-for-their-job colours, never a raw palette colour or a hand-typed one.
	 * Passes when: styles/form.css (the source the builder edits) holds no reference to a raw palette token (grey, amber, green, blue, orange,
	 *   red, indigo or night with a number), no colour written as #hex, rgb(), rgba(), hsl() or hsla(), and no named colour such as white or
	 *   black as a value.
	 * Fails when: an error or a notice is painted with a raw red or amber that has no dark version, so in dark mode it glows on the night
	 *   background or vanishes; or a hand-typed colour drifts from OmnaBase's look.
	 */
	public function test_no_raw_colour_or_palette_token_in_the_rules(): void {
		$rules = $this->withoutComments( $this->read( self::SOURCE ) );
		$this->assertNotSame( '', trim( $rules ), 'the source of the rules' );
		preg_match_all( self::PALETTE, $rules, $palette );
		$this->assertSame( array(), array_values( array_unique( $palette[0] ) ), 'no raw palette token' );
		preg_match_all( '/#[0-9a-f]{3,8}\b|\b(rgba?|hsla?)\(/i', $rules, $raw );
		$this->assertSame( array(), array_values( array_unique( $raw[0] ) ), 'no hand-typed colour' );
		preg_match_all( '/:\s*[^;{}]*\b(white|black)\b/i', $rules, $named );
		$this->assertSame( array(), array_values( array_unique( $named[1] ) ), 'no named colour' );
	}

	/**
	 * What it checks: Every colour the front end's rules use has a night value in OmnaBase's own tokens, so notices and errors added for this
	 *   round were added to OmnaBase's file with their dark values (the brief's rule 1), not only to the plugin.
	 * Passes when: (with the OmnaBase repository beside this one) each variable the rules use, if OmnaBase's light block gives it a colour, is
	 *   also set in OmnaBase's dark block, or refers only to variables that are (the owner's accent and its text excepted: they are data).
	 * Fails when: the error under a field uses a token that is red in both themes (OmnaBase's "alert" today) or a new light-only token, so the
	 *   dark form shows a light-theme red on a night background, or the app and the plugin no longer share the colour.
	 */
	public function test_every_colour_the_rules_use_has_a_dark_value_in_omnabase(): void {
		$kit = $this->kitBlocks();
		preg_match_all( '/var\(\s*(--ob-[a-z0-9-]+)/', $this->withoutComments( $this->read( self::SOURCE ) ), $used );
		$missing = array();
		foreach ( array_unique( $used[1] ) as $name ) {
			if ( in_array( $name, self::DATA_VARIABLES, true ) || ! isset( $kit['primitives'][ $name ] ) || ! self::isColour( $kit['primitives'][ $name ], $kit['primitives'] ) ) {
				continue;
			}
			if ( ! self::followsDark( $name, $kit ) ) {
				$missing[] = $name;
			}
		}
		$this->assertSame( array(), $missing, 'colours the rules use that OmnaBase\'s dark block does not change' );
	}

	/**
	 * What it checks: The colours the front end ends up with are readable in both themes.
	 * Passes when: resolving the built stylesheet's light block and its dark block (the dark one over the light one), the text and the
	 *   secondary text on the surface reach 4.5 to 1, the primary button's text on its colour reaches 4.5 to 1 (with OmnaBase's blue), the focus
	 *   ring reaches 3 to 1 on the surface, and the colour the error under a field is written in (the first variable of its colour rule in
	 *   styles/form.css) reaches 4.5 to 1 on the surface, each in light and in dark.
	 * Fails when: a customer with weaker eyesight cannot read the error that tells them what to fix (today's red reads at 4.2 to 1 on white),
	 *   the help text fades into a night background, or a keyboard user cannot see where they are.
	 */
	public function test_the_built_colours_read_well_in_both_themes(): void {
		$blocks = $this->blocks( $this->withoutComments( $this->read( self::BUILT ) ) );
		$light  = $this->variables( $this->declarationsOf( $blocks, self::SCOPE ) );
		$dark   = $this->variables( $this->declarationsOf( $blocks, self::DARK_SELECTOR ) );
		$this->assertNotSame( array(), $dark, 'the dark block (without it there is no dark theme to check)' );
		$error = $this->errorColour();
		$this->assertNotSame( '', $error, 'the error under a field takes its colour from a variable' );
		$pairs = array(
			array( '--ob-text', '--ob-bg-surface', 4.5 ),
			array( '--ob-text-secondary', '--ob-bg-surface', 4.5 ),
			array( '--ob-btn-primary-text', '--ob-btn-primary-bg', 4.5 ),
			array( '--ob-focus', '--ob-bg-surface', 3.0 ),
			array( $error, '--ob-bg-surface', 4.5 ),
		);
		foreach ( array( 'light' => $light, 'dark' => array_merge( $light, $dark ) ) as $theme => $vars ) {
			foreach ( $pairs as [ $fg, $bg, $min ] ) {
				$a = self::resolve( $fg, $vars );
				$b = self::resolve( $bg, $vars );
				$this->assertNotNull( $a, "{$theme}: {$fg} resolves to a plain colour" );
				$this->assertNotNull( $b, "{$theme}: {$bg} resolves to a plain colour" );
				$ratio = self::contrast( $a, $b );
				$this->assertGreaterThanOrEqual( $min, $ratio, sprintf( '%s: %s (%s) on %s (%s) reads at %.2f to 1, needs %.1f', $theme, $fg, $a, $bg, $b, $ratio, $min ) );
			}
		}
	}

	/**
	 * What it checks: The plugin ships OmnaBase's typeface files with their licence, the same files OmnaBase serves.
	 * Passes when: the plugin's font folder holds both subsets of the variable typeface (Latin, and Latin Extended for names such as "Łukasz"
	 *   or "Dvořák") and the licence text; with the OmnaBase repository beside this one, each is byte for byte OmnaBase's file.
	 * Fails when: a site owner chooses OmnaBase's font and a customer's accented name falls back to another typeface mid-word, the zip ships a
	 *   font without its licence (the licence requires it beside the files), or the plugin keeps last year's typeface after OmnaBase changed it.
	 */
	public function test_the_font_files_ship_with_their_licence(): void {
		foreach ( self::FONT_FILES as $file ) {
			$this->assertFileExists( OMNABASE_REPO . '/' . self::FONT_DIR . '/' . $file, "the plugin ships {$file}" );
		}
		$kit = $this->kitDir();
		foreach ( self::FONT_FILES as $file ) {
			$this->assertSame( sha1_file( $kit . '/packages/ui/src/fonts/' . $file ), sha1_file( OMNABASE_REPO . '/' . self::FONT_DIR . '/' . $file ), "{$file} is OmnaBase's own file" );
		}
	}

	/**
	 * What it checks: The typeface is declared in one stylesheet of its own, never in the one every page with a form loads, and only from the
	 *   plugin's own files.
	 * Passes when: exactly one stylesheet under assets/ declares a font (`@font-face`), and it is neither the form's nor the settings screen's;
	 *   every address it names is a file of the plugin's that exists (no web address, no Google Fonts); it swaps the font in once loaded
	 *   (`font-display: swap`); it sets the family on the plugin's wrapper; neither the form's nor the settings screen's stylesheet names the
	 *   typeface; and (with the OmnaBase repository beside this one) the family it declares is the first of OmnaBase's font token.
	 * Fails when: every visitor downloads the font although the owner kept the theme's (the brief's "loads it only then"), a page asks Google
	 *   for a font (a 2022 German ruling made that a warning-letter risk for the site owner), text stays invisible while the font loads, or the
	 *   plugin declares a typeface OmnaBase no longer uses.
	 */
	public function test_the_font_lives_in_its_own_stylesheet_of_local_files(): void {
		$sheets = array_values( array_filter( $this->filesUnder( 'assets', 'css' ), fn ( $f ) => (bool) preg_match( '/@font-face/i', $this->read( $f ) ) ) );
		$this->assertCount( 1, $sheets, 'one stylesheet declares the font: ' . implode( ', ', $sheets ) );
		$sheet = $sheets[0];
		$this->assertNotContains( $sheet, array( self::BUILT, 'assets/admin.css' ), 'not the stylesheet every form page loads, not the settings screen\'s' );
		$css = $this->withoutComments( $this->read( $sheet ) );
		preg_match_all( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $css, $urls );
		$this->assertNotSame( array(), $urls[1], 'the font sheet names its files' );
		foreach ( $urls[1] as $url ) {
			$this->assertDoesNotMatchRegularExpression( '#^(https?:)?//|googleapis|gstatic#i', $url, "{$url}: a file of the plugin's, not a web address" );
			$this->assertFileExists( dirname( OMNABASE_REPO . '/' . $sheet ) . '/' . $url, "{$url} exists beside the font sheet" );
		}
		$this->assertMatchesRegularExpression( '/font-display\s*:\s*swap/i', $css, 'the text shows at once and the font swaps in' );
		$this->assertMatchesRegularExpression( '/(^|\})\s*\.ob-wp[^{]*\{[^}]*font-family\s*:/s', $css, 'the family is set on the plugin\'s wrapper' );
		foreach ( array( self::BUILT, 'assets/admin.css', self::SOURCE ) as $file ) {
			$this->assertStringNotContainsString( 'Plus Jakarta', $this->read( $file ), "{$file} names no typeface" );
		}
		preg_match( '/font-family\s*:\s*[\'"]([^\'"]+)[\'"]/i', (string) preg_replace( '/^.*?@font-face\s*\{/is', '', $css ), $declared );
		$kit = $this->kitBlocks();
		preg_match( '/^\s*[\'"]?([^\'",]+)/', (string) ( $kit['primitives']['--ob-font-sans'] ?? '' ), $first );
		$this->assertSame( trim( (string) ( $first[1] ?? '' ) ), trim( (string) ( $declared[1] ?? '' ) ), 'the declared family is OmnaBase\'s current typeface' );
	}

	/**
	 * What it checks: The build script makes the dark blocks and the font from OmnaBase's files, so a rebuild keeps them.
	 * Passes when: bin/build-css.php's code (comments left out) names the dark theme's marker (`data-theme`), the device's dark setting
	 *   (`prefers-color-scheme`), the accent's marker (`data-accent`) and the font files (`woff2` or OmnaBase's `fonts` folder).
	 * Fails when: the dark blocks or the font were added to the shipped files by hand, so the next build (bin/build-dist.sh runs it before every
	 *   zip) silently throws them away.
	 */
	public function test_the_build_script_makes_the_dark_blocks_and_the_font(): void {
		$code = implode( "\n", array_filter( explode( "\n", $this->read( 'bin/build-css.php' ) ), static fn ( $l ) => ! preg_match( '#^\s*(//|\#|\*|/\*)#', $l ) ) );
		foreach ( array( 'data-theme', 'prefers-color-scheme', 'data-accent' ) as $needle ) {
			$this->assertTrue( str_contains( $code, $needle ), "the build names {$needle} in code" );
		}
		$this->assertSame( 1, preg_match( '#woff2|/fonts\b|fonts/#', $code ), 'the build copies the font files' );
		$this->assertMatchesRegularExpression( '#bin/build-css\.php#', (string) strtok( $this->read( self::BUILT ), "\n" ), 'the built file\'s first line still names its script' );
	}

	/**
	 * What it checks: The customer release notes get a new, unreleased section for this round, the released 0.1.0 stays as published, and the
	 *   version agrees everywhere.
	 * Passes when: changelog.txt's top section is a higher version than 0.1.0 marked "unreleased" (no date); it holds a line about choosing light
	 *   or dark and a line about the form's or the message centre's new look; the 0.1.0 section keeps its date and says nothing about the
	 *   round; the plugin's header, its version constant, readme.txt's Stable tag and package.json name the new version.
	 * Fails when: the round's lines are added to the release customers already downloaded (its notes then describe code nobody received), the
	 *   new section is dated before it ships, or the zip's build refuses because one file still says 0.1.0.
	 */
	public function test_the_release_notes_get_a_new_unreleased_section(): void {
		$changelog = $this->read( 'changelog.txt' );
		$this->assertSame( 1, preg_match( '/^= (\d+\.\d+\.\d+) — (.+) =$/m', $changelog, $top ), 'a version heading' );
		$this->assertTrue( version_compare( $top[1], self::RELEASED, '>' ), "the top section ({$top[1]}) is newer than the released " . self::RELEASED );
		$this->assertSame( 'unreleased', $top[2], 'the new section is marked unreleased, not dated' );
		$this->assertSame( 1, preg_match( '/^= ' . preg_quote( $top[1], '/' ) . ' — unreleased =$(.*?)(?:^= |\z)/ms', $changelog, $section ) );
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $section[1] ) ) ) );
		$this->assertNotEmpty( preg_grep( '/\bdark\b/i', $lines ), 'a line about light and dark' );
		$this->assertNotEmpty( preg_grep( '/(form|message centre).*(look|design)|(look|design).*(form|message centre)/i', $lines ), 'a line about the new look of the form or the message centre' );
		$this->assertStringContainsString( self::RELEASED_HEADING, $changelog, 'the released section keeps its date' );
		$this->assertSame( 1, preg_match( '/^' . preg_quote( self::RELEASED_HEADING, '/' ) . '$(.*?)(?:^= |\z)/ms', $changelog, $old ) );
		$this->assertDoesNotMatchRegularExpression( '/\bdark\b|Appearance/i', $old[1], 'nothing of this round in the released section' );

		$main = $this->read( 'omnabase.php' );
		$this->assertSame( 1, preg_match( '/^\s*\*?\s*Version:\s*(\S+)/m', $main, $header ), 'the header\'s version' );
		$this->assertSame( $top[1], $header[1], 'the plugin header names the new version' );
		$this->assertSame( 1, preg_match( "/define\(\s*'OMNABASE_VERSION',\s*'([^']+)'/", $main, $constant ) );
		$this->assertSame( $top[1], $constant[1], 'the version constant names the new version' );
		$this->assertSame( 1, preg_match( '/^Stable tag:\s*(\S+)/m', $this->read( 'readme.txt' ), $stable ) );
		$this->assertSame( $top[1], $stable[1], 'readme.txt\'s Stable tag names the new version' );
		$this->assertSame( $top[1], (string) ( json_decode( $this->read( 'package.json' ), true )['version'] ?? '' ), 'package.json names the new version' );
	}

	/**
	 * What it checks: The readme tells site owners where the look is chosen now and names the bundled typeface's licence, and the decisions
	 *   record says why the settings screen has six tabs.
	 * Passes when: readme.txt mentions dark, its answer about the theme's styles names the Appearance tab (not the Forms and messages tab), and it
	 *   names Plus Jakarta Sans with the SIL Open Font License; DECISIONS.md has a dated heading whose entry names the Appearance tab and six
	 *   tabs.
	 * Fails when: the FAQ sends owners to a tab where the switch no longer is, WordPress.org's review finds a bundled font nobody declared, or a
	 *   later round undoes the sixth tab because nothing says it was decided (the brief: "the pinned five-tab tests are adjusted with a
	 *   DECISIONS entry").
	 */
	public function test_the_readme_and_the_decisions_record_the_round(): void {
		$readme = $this->read( 'readme.txt' );
		$this->assertSame( 1, preg_match( '/\bdark\b/i', $readme ), 'the readme says the form and the message centre can be dark' );
		$this->assertSame( 1, preg_match( '/^= [^=]*theme\'?s? styles[^=]*=\s*$(.*?)(?:^= |^== |\z)/msi', $readme, $faq ), 'the FAQ about the theme\'s styles' );
		$this->assertStringContainsString( 'Appearance', $faq[1], 'it names the Appearance tab' );
		$this->assertStringNotContainsString( 'Forms and messages tab', $faq[1], 'not the tab the switch left' );
		$this->assertTrue( str_contains( $readme, 'Plus Jakarta Sans' ), 'the bundled typeface is named' );
		$this->assertSame( 1, preg_match( '/Open Font Licen[cs]e|\bOFL\b/i', $readme ), 'with its licence' );

		$decisions = $this->read( 'DECISIONS.md' );
		$entries   = preg_split( '/^(?=## \d{4}-\d{2}-\d{2} — )/m', $decisions );
		$this->assertNotEmpty( preg_grep( '/Appearance/', $entries ) ? array_filter( preg_grep( '/Appearance/', $entries ), static fn ( $e ) => (bool) preg_match( '/\bsix tabs\b|\bsixth tab\b/i', $e ) ) : array(), 'a dated entry names the Appearance tab and the six tabs' );
	}

	/* ---------- reading CSS ---------- */

	/**
	 * The blocks of a stylesheet: each `selector { … }` at the top, and inside each `@media`, with the at-rule it sits in.
	 *
	 * @return array<int,array{selector:string,media:string,body:string}>
	 */
	private function blocks( string $css, string $media = '' ): array {
		$out = array();
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$open = strpos( $css, '{', $i );
			if ( false === $open ) {
				break;
			}
			$head  = trim( substr( $css, $i, $open - $i ) );
			$depth = 1;
			$j     = $open + 1;
			while ( $j < $len && $depth > 0 ) {
				$depth += ( '{' === $css[ $j ] ) ? 1 : ( ( '}' === $css[ $j ] ) ? -1 : 0 );
				++$j;
			}
			$body = substr( $css, $open + 1, $j - $open - 2 );
			if ( str_starts_with( $head, '@media' ) ) {
				$out = array_merge( $out, $this->blocks( $body, self::norm( substr( $head, 6 ) ) ) );
			} else {
				foreach ( array_map( 'trim', explode( ',', $head ) ) as $selector ) {
					$out[] = array( 'selector' => self::norm( $selector ), 'media' => $media, 'body' => $body );
				}
			}
			$i = $j;
		}
		return $out;
	}

	/**
	 * The declarations of the first block with a selector (inside an `@media` holding the given words, or outside any when ''), name => value.
	 *
	 * @param array<int,array{selector:string,media:string,body:string}> $blocks The blocks.
	 * @return array<string,string>
	 */
	private function declarationsOf( array $blocks, string $selector, string $media = '' ): array {
		$want = self::norm( $selector );
		$out  = array();
		foreach ( $blocks as $b ) {
			if ( $b['selector'] !== $want || ( '' === $media ? '' !== $b['media'] : ! str_contains( $b['media'], self::norm( $media ) ) ) ) {
				continue;
			}
			preg_match_all( '/([a-z-][a-z0-9-]*)\s*:\s*([^;]+);?/i', $b['body'], $m, PREG_SET_ORDER );
			foreach ( $m as $d ) {
				$out[ strtolower( $d[1] ) ] = trim( $d[2] );
			}
		}
		return $out;
	}

	/** Where each selector first appears, by its position among the blocks. */
	private function selectorOrder( array $blocks ): array {
		$order = array();
		foreach ( $blocks as $i => $b ) {
			$order[ $b['selector'] ] ??= $i;
		}
		return $order;
	}

	/** Only the custom properties of some declarations. */
	private function variables( array $declarations ): array {
		return array_filter( $declarations, static fn ( $k ) => str_starts_with( (string) $k, '--ob-' ), ARRAY_FILTER_USE_KEY );
	}

	/** A selector or media text written one way: lower case, spaces collapsed, none around colons and brackets, attribute quotes double. */
	private static function norm( string $s ): string {
		$s = strtolower( (string) preg_replace( '/\s+/', ' ', trim( $s ) ) );
		$s = (string) preg_replace( '/\[\s*([a-z-]+)\s*=\s*[\'"]?([a-z-]+)[\'"]?\s*\]/', '[$1="$2"]', $s );
		return (string) preg_replace( '/\s*([:()])\s*/', '$1', $s );
	}

	/** The variable the error under a field is written in (the first `var()` of the colour rule of `.ob-wp-form__error`), or ''. */
	private function errorColour(): string {
		$blocks = $this->blocks( $this->withoutComments( $this->read( self::SOURCE ) ) );
		foreach ( $blocks as $b ) {
			if ( str_contains( $b['selector'], 'ob-wp-form__error' ) && ! str_contains( $b['selector'], '[hidden]' ) && preg_match( '/(?<![a-z-])color\s*:\s*var\(\s*(--ob-[a-z0-9-]+)/i', $b['body'], $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/* ---------- colours ---------- */

	/** A variable (or a colour) resolved to #rrggbb through the variables, or null when it is not a plain colour (a mix, a gradient). */
	private static function resolve( string $value, array $vars, int $depth = 0 ): ?string {
		$value = trim( $value );
		if ( $depth > 20 ) {
			return null;
		}
		if ( isset( $vars[ $value ] ) ) {
			return self::resolve( $vars[ $value ], $vars, $depth + 1 );
		}
		if ( preg_match( '/^var\(\s*(--ob-[a-z0-9-]+)\s*(?:,[^)]*)?\)$/', $value, $m ) ) {
			return isset( $vars[ $m[1] ] ) ? self::resolve( $vars[ $m[1] ], $vars, $depth + 1 ) : null;
		}
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value, $m ) ) {
			$hex = 3 === strlen( $m[1] ) ? preg_replace( '/(.)/', '$1$1', $m[1] ) : $m[1];
			return '#' . strtolower( (string) $hex );
		}
		return null;
	}

	/** Whether a token's light value is a colour (a hex, or a reference that ends in one, or a colour mix). */
	private static function isColour( string $value, array $vars ): bool {
		return null !== self::resolve( $value, $vars ) || str_starts_with( trim( $value ), 'color-mix(' );
	}

	/** Whether a token follows the dark theme in OmnaBase: set in its dark block, or made only of tokens that are (data excepted). */
	private static function followsDark( string $name, array $kit, int $depth = 0 ): bool {
		if ( isset( $kit['dark'][ $name ] ) || in_array( $name, self::DATA_VARIABLES, true ) ) {
			return true;
		}
		$value = (string) ( $kit['primitives'][ $name ] ?? '' );
		preg_match_all( '/var\(\s*(--ob-[a-z0-9-]+)/', $value, $refs );
		if ( array() === $refs[1] || $depth > 10 ) {
			return false;
		}
		foreach ( $refs[1] as $ref ) {
			if ( ! self::followsDark( $ref, $kit, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	/** WCAG's contrast ratio of two #rrggbb colours. */
	private static function contrast( string $a, string $b ): float {
		$l = array_map(
			static function ( string $hex ): float {
				$c = array_map( static fn ( $i ) => hexdec( substr( $hex, $i, 2 ) ) / 255, array( 1, 3, 5 ) );
				$c = array_map( static fn ( $v ) => $v <= 0.03928 ? $v / 12.92 : ( ( $v + 0.055 ) / 1.055 ) ** 2.4, $c );
				return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
			},
			array( $a, $b )
		);
		rsort( $l );
		return ( $l[0] + 0.05 ) / ( $l[1] + 0.05 );
	}

	/* ---------- OmnaBase's live tokens ---------- */

	/**
	 * OmnaBase's tokens, by block: `primitives` (every plain `:root` block: the light semantic tokens and the night scale), `dark` (the
	 * `:root[data-theme="dark"]` block), and the three accent blocks with `:root` read as the wrapper. Skips when the repository is absent.
	 *
	 * @return array{primitives:array<string,string>,dark:array<string,string>,accent:array<string,string>,accentDark:array<string,string>,accentSystem:array<string,string>}
	 */
	private function kitBlocks(): array {
		$css    = $this->withoutComments( (string) file_get_contents( $this->kitDir() . '/packages/ui/src/styles/tokens.css' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$css    = str_replace( ':root', self::SCOPE, $css );
		$blocks = $this->blocks( $css );
		$plain  = array();
		foreach ( $blocks as $b ) {
			if ( self::norm( self::SCOPE ) === $b['selector'] && '' === $b['media'] ) {
				preg_match_all( '/(--ob-[a-z0-9-]+)\s*:\s*([^;]+);/', $b['body'], $m, PREG_SET_ORDER );
				foreach ( $m as $d ) {
					$plain[ $d[1] ] ??= trim( $d[2] );
				}
			}
		}
		return array(
			'primitives'   => $plain,
			'dark'         => $this->variables( $this->declarationsOf( $blocks, self::DARK_SELECTOR ) ),
			'accent'       => $this->variables( $this->declarationsOf( $blocks, self::ACCENT_SELECTOR ) ),
			'accentDark'   => $this->variables( $this->declarationsOf( $blocks, self::ACCENT_DARK_SELECTOR ) ),
			'accentSystem' => $this->variables( $this->declarationsOf( $blocks, self::ACCENT_SYSTEM_SELECTOR, 'prefers-color-scheme: dark' ) ),
		);
	}

	/** The OmnaBase repository, or the test is skipped (CI has only this repository). */
	private function kitDir(): string {
		$dir = getenv( 'OMNABASE_REPO' ) ? (string) getenv( 'OMNABASE_REPO' ) : dirname( OMNABASE_REPO ) . '/omnabase';
		if ( ! is_readable( $dir . '/packages/ui/src/styles/tokens.css' ) ) {
			$this->markTestSkipped( 'OmnaBase\'s kit is not beside this repository (set OMNABASE_REPO): ' . $dir );
		}
		return $dir;
	}

	/* ---------- files ---------- */

	private function withoutComments( string $css ): string {
		return (string) preg_replace( '#/\*.*?\*/#s', '', $css );
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

	private function read( string $path ): string {
		$file = OMNABASE_REPO . '/' . $path;
		return is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
