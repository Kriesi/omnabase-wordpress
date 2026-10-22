<?php
/**
 * Builds the plugin's stylesheets from OmnaBase's kit (packages/ui in the OmnaBase repository): each one is the kit's variables its rules use,
 * scoped under its root class, followed by its own rules; and the font's own stylesheet with the kit's two font files and their licence.
 *
 *   php bin/build-css.php           # all: the front end's (styles/form.css → assets/form.css, under .ob-wp, light and dark),
 *                                   #      the settings screen's (styles/admin.css → assets/admin.css, under .ob-wp-settings, light only)
 *                                   #      and the font (assets/fonts.css, assets/fonts/)
 *   php bin/build-css.php admin     # one of them: form, admin or font
 *
 * The OmnaBase repository beside this one, or OMNABASE_REPO. The front end gets the kit's light block on `.ob-wp`, its accent block on
 * `.ob-wp[data-accent]`, and the dark block twice: on `.ob-wp[data-theme="dark"]` (the owner chose Dark) and, inside the device's dark
 * setting (prefers-color-scheme), on `.ob-wp:not([data-theme="light"])` (the owner chose the visitor's system); each dark accent block follows
 * its dark block, in the kit's own order, so a site's own colour wins in both themes. The settings screen stays light (WordPress's admin
 * has no dark mode). Only the variables the rules use are copied, with the ones they refer to in turn; the typeface's token is never copied
 * (the theme's font stays unless the owner loads OmnaBase's: assets/fonts.css, queued only then). The results are committed, so a checkout
 * without the OmnaBase repository (CI, the zip) still has them; bin/build-dist.sh runs this first when the kit is there.
 *
 * @package OmnaBase
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- a command-line build script, not WordPress code.

require __DIR__ . '/lib/css-tokens.php';

$root   = dirname( __DIR__ );
$omna   = getenv( 'OMNABASE_REPO' ) ? getenv( 'OMNABASE_REPO' ) : dirname( $root ) . '/omnabase';
$tokens = $omna . '/packages/ui/src/styles/tokens.css';
$builds = array(
	'form'  => array( 'source' => 'styles/form.css', 'target' => 'assets/form.css', 'scope' => '.ob-wp', 'what' => 'the form and the message centre', 'dark' => true ),
	'admin' => array( 'source' => 'styles/admin.css', 'target' => 'assets/admin.css', 'scope' => '.ob-wp-settings', 'what' => 'the settings screen', 'dark' => false ),
	'font'  => array(),
);

$wanted = array_slice( $argv, 1 );
$wanted = array() === $wanted ? array_keys( $builds ) : $wanted;
foreach ( $wanted as $name ) {
	if ( ! isset( $builds[ $name ] ) ) {
		fwrite( STDERR, "build-css: unknown stylesheet {$name} (form, admin or font).\n" );
		exit( 2 );
	}
}
if ( ! is_readable( $tokens ) ) {
	fwrite( STDERR, "build-css: OmnaBase's tokens not found at {$tokens} (set OMNABASE_REPO); the stylesheets left as they are.\n" );
	exit( 1 );
}

$kit = omnabase_kit_blocks( (string) file_get_contents( $tokens ) );
foreach ( $wanted as $name ) {
	if ( 'font' === $name ) {
		echo 'build-css: ' . omnabase_build_font( $root, $omna . '/packages/ui/src', $kit ) . "\n";
		continue;
	}
	$count = omnabase_build( $root, $builds[ $name ], $kit );
	echo "build-css: {$builds[ $name ]['target']} with {$count} of the kit's variables\n";
}

/**
 * Writes one stylesheet: the header, the scoped variable blocks, the rules.
 *
 * @param string                                                                 $root  The repository.
 * @param array{source:string,target:string,scope:string,what:string,dark:bool} $build The pair, its root class, what it styles, whether it has a dark theme.
 * @param array<string,array<string,string>>                                     $kit   The kit's blocks.
 * @return int How many variables were copied (light and dark).
 */
function omnabase_build( string $root, array $build, array $kit ): int {
	$rules = (string) file_get_contents( $root . '/' . $build['source'] );
	$sets  = omnabase_token_sets( $rules, $kit, $build['dark'] );
	$scope = $build['scope'];
	$css   = "/* Built by bin/build-css.php from OmnaBase's packages/ui/src/styles/tokens.css (the variables {$build['what']} uses"
		. ( $build['dark'] ? ', light and dark' : ', the light block' ) . ") and\n"
		. "   {$build['source']}. Do not edit: change {$build['source']} and build again. */\n\n"
		. omnabase_block( $scope, array(), $sets['light'] );
	if ( $build['dark'] ) {
		$dark   = array( 'color-scheme' => 'dark' ) + $sets['dark'];
		$system = $scope . ':not([data-theme="light"])';
		$css   .= omnabase_block( $scope . '[data-accent]', array(), $sets['accent'] )
			. "@media (prefers-color-scheme: dark) {\n"
			. omnabase_block( $system, $dark, array(), '  ' )
			. omnabase_block( $scope . '[data-accent]:not([data-theme="light"])', array(), $sets['accentSystem'], '  ' )
			. "}\n\n"
			. omnabase_block( $scope . '[data-theme="dark"]', $dark, array() )
			. omnabase_block( $scope . '[data-accent][data-theme="dark"]', array(), $sets['accentDark'] );
	}
	file_put_contents( $root . '/' . $build['target'], $css . $rules );
	return count( $sets['light'] ) + count( $sets['dark'] );
}

/**
 * One block of declarations (nothing when both lists are empty).
 *
 * @param string               $selector The selector.
 * @param array<string,string> $plain    Declarations written first (`color-scheme`), then the variables.
 * @param array<string,string> $vars     More declarations.
 * @param string               $indent   Inside an at-rule.
 */
function omnabase_block( string $selector, array $plain, array $vars, string $indent = '' ): string {
	$all = $plain + $vars;
	if ( array() === $all ) {
		return '';
	}
	$lines = array();
	foreach ( $all as $name => $value ) {
		$lines[] = "{$indent}  {$name}: {$value};";
	}
	return "{$indent}{$selector} {\n" . implode( "\n", $lines ) . "\n{$indent}}\n\n";
}

/**
 * The font's own stylesheet and files: the kit's two variable subsets and their licence copied into assets/fonts/, the kit's `@font-face`
 * rules pointing at them, and the family (the kit's font token) set on the plugin's wrapper and inherited by everything inside it.
 *
 * @param string                             $root The repository.
 * @param string                             $ui   The kit's folder (packages/ui/src).
 * @param array<string,array<string,string>> $kit  The kit's blocks.
 */
function omnabase_build_font( string $root, string $ui, array $kit ): string {
	$faces = (string) file_get_contents( $ui . '/styles/fonts.css' );
	if ( ! is_dir( $root . '/assets/fonts' ) ) {
		mkdir( $root . '/assets/fonts', 0755, true );
	}
	$files = array();
	foreach ( glob( $ui . '/fonts/*' ) as $file ) {
		if ( preg_match( '/\.(woff2|txt)$/', $file ) ) {
			copy( $file, $root . '/assets/fonts/' . basename( $file ) );
			$files[] = basename( $file );
		}
	}
	preg_match_all( '/@font-face\s*\{[^}]*\}/', (string) preg_replace( '#/\*.*?\*/#s', '', $faces ), $rules );
	$family = (string) ( $kit['primitives']['--ob-font-sans'] ?? 'inherit' );
	$css    = "/* Built by bin/build-css.php from OmnaBase's packages/ui/src/styles/fonts.css and the font token: OmnaBase's typeface, two variable\n"
		. "   subsets with the SIL Open Font License beside them in fonts/ (OFL.txt). Queued only when the owner chose \"Load OmnaBase's font\"\n"
		. "   (Settings › OmnaBase › Appearance); local files only, never a font service. Do not edit: build again. */\n\n"
		. str_replace( '../fonts/', 'fonts/', implode( "\n", $rules[0] ) ) . "\n\n"
		. ".ob-wp {\n  font-family: {$family};\n}\n\n"
		. "/* everything inside the wrapper takes it, also where a theme names its own font for labels, fields or buttons */\n"
		. ".ob-wp * {\n  font-family: inherit;\n}\n";
	file_put_contents( $root . '/assets/fonts.css', $css );
	return 'assets/fonts.css with ' . implode( ', ', $files );
}
