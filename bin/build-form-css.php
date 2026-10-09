<?php
/**
 * Builds the shortcode form's stylesheet, assets/form.css: the variables it uses from OmnaBase's kit tokens (packages/ui/src/styles/tokens.css
 * in the OmnaBase repository), scoped under .ob-wp, followed by the form's own rules from styles/form.css.
 *
 *   php bin/build-form-css.php            # the OmnaBase repository beside this one, or OMNABASE_REPO
 *
 * Only the light block (the first `:root { … }`) is read: the dark block is dropped and so is the kit's typeface (the form keeps the theme's
 * font). Only the variables the form's rules use are copied, with the ones they refer to in turn. The result is committed, so a checkout
 * without the OmnaBase repository (CI, the zip) still has it; bin/build-dist.sh runs this first when the tokens are there.
 *
 * @package OmnaBase
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- a command-line build script, not WordPress code.

$root    = dirname( __DIR__ );
$omna    = getenv( 'OMNABASE_REPO' ) ? getenv( 'OMNABASE_REPO' ) : dirname( $root ) . '/omnabase';
$tokens  = $omna . '/packages/ui/src/styles/tokens.css';
$source  = $root . '/styles/form.css';
$target  = $root . '/assets/form.css';

if ( ! is_readable( $tokens ) ) {
	fwrite( STDERR, "build-form-css: OmnaBase's tokens not found at {$tokens} (set OMNABASE_REPO); assets/form.css left as it is.\n" );
	exit( 1 );
}

$rules = (string) file_get_contents( $source );
$light = omnabase_light_block( (string) file_get_contents( $tokens ) );
$used  = omnabase_used_variables( $rules, $light );

$lines = array();
foreach ( $light as $name => $value ) {
	if ( isset( $used[ $name ] ) ) {
		$lines[] = "  {$name}: {$value};";
	}
}
$css = "/* Built by bin/build-form-css.php from OmnaBase's packages/ui/src/styles/tokens.css (the light block, the variables the form uses) and\n"
	. "   styles/form.css. Do not edit: change styles/form.css and build again. */\n\n"
	. ".ob-wp {\n" . implode( "\n", $lines ) . "\n}\n\n" . $rules;

file_put_contents( $target, $css );
echo 'build-form-css: assets/form.css with ' . count( $lines ) . " of the kit's variables\n";

/**
 * The first `:root { … }` block's variables, name => value, without the kit's typeface.
 *
 * @param string $css The tokens file.
 * @return array<string,string>
 */
function omnabase_light_block( string $css ): array {
	$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
	if ( ! preg_match( '/:root\s*\{([^}]*)\}/', $css, $block ) ) {
		return array();
	}
	preg_match_all( '/(--ob-[a-z0-9-]+)\s*:\s*([^;]+);/', $block[1], $m, PREG_SET_ORDER );
	$vars = array();
	foreach ( $m as $declaration ) {
		if ( ! str_starts_with( $declaration[1], '--ob-font' ) ) {
			$vars[ $declaration[1] ] = trim( $declaration[2] );
		}
	}
	return $vars;
}

/**
 * The variables the rules use, with the ones those refer to, transitively.
 *
 * @param string               $rules The form's rules.
 * @param array<string,string> $vars  The kit's variables.
 * @return array<string,true>
 */
function omnabase_used_variables( string $rules, array $vars ): array {
	preg_match_all( '/var\(\s*(--ob-[a-z0-9-]+)/', $rules, $m );
	$todo = array_unique( $m[1] );
	$used = array();
	while ( array() !== $todo ) {
		$name = array_pop( $todo );
		if ( isset( $used[ $name ] ) || ! isset( $vars[ $name ] ) ) {
			continue;
		}
		$used[ $name ] = true;
		preg_match_all( '/var\(\s*(--ob-[a-z0-9-]+)/', $vars[ $name ], $refs );
		$todo = array_merge( $todo, $refs[1] );
	}
	return $used;
}
