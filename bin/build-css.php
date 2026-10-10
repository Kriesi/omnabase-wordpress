<?php
/**
 * Builds the plugin's two stylesheets from OmnaBase's kit tokens (packages/ui/src/styles/tokens.css in the OmnaBase repository): each one
 * is the variables its rules use, scoped under its root class, followed by its own rules.
 *
 *   php bin/build-css.php           # both: the shortcode form's (styles/form.css → assets/form.css, under .ob-wp)
 *                                   #       and the settings screen's (styles/admin.css → assets/admin.css, under .ob-wp-settings)
 *   php bin/build-css.php admin     # one of them: form or admin
 *
 * The OmnaBase repository beside this one, or OMNABASE_REPO. Only the light block (the first `:root { … }`) is read: the dark block is
 * dropped and so is the kit's typeface (the form keeps the theme's font, the screen WordPress's). Only the variables the rules use are
 * copied, with the ones they refer to in turn. The results are committed, so a checkout without the OmnaBase repository (CI, the zip) still
 * has them; bin/build-dist.sh runs this first when the tokens are there.
 *
 * @package OmnaBase
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- a command-line build script, not WordPress code.

$root   = dirname( __DIR__ );
$omna   = getenv( 'OMNABASE_REPO' ) ? getenv( 'OMNABASE_REPO' ) : dirname( $root ) . '/omnabase';
$tokens = $omna . '/packages/ui/src/styles/tokens.css';
$builds = array(
	'form'  => array( 'source' => 'styles/form.css', 'target' => 'assets/form.css', 'scope' => '.ob-wp', 'what' => 'the form' ),
	'admin' => array( 'source' => 'styles/admin.css', 'target' => 'assets/admin.css', 'scope' => '.ob-wp-settings', 'what' => 'the settings screen' ),
);

$wanted = array_slice( $argv, 1 );
$wanted = array() === $wanted ? array_keys( $builds ) : $wanted;
foreach ( $wanted as $name ) {
	if ( ! isset( $builds[ $name ] ) ) {
		fwrite( STDERR, "build-css: unknown stylesheet {$name} (form or admin).\n" );
		exit( 2 );
	}
}
if ( ! is_readable( $tokens ) ) {
	fwrite( STDERR, "build-css: OmnaBase's tokens not found at {$tokens} (set OMNABASE_REPO); the stylesheets left as they are.\n" );
	exit( 1 );
}

$light = omnabase_light_block( (string) file_get_contents( $tokens ) );
foreach ( $wanted as $name ) {
	$count = omnabase_build( $root, $builds[ $name ], $light );
	echo "build-css: {$builds[ $name ]['target']} with {$count} of the kit's variables\n";
}

/**
 * Writes one stylesheet: the header, the scoped variables block, the rules.
 *
 * @param string                                                     $root  The repository.
 * @param array{source:string,target:string,scope:string,what:string} $build The pair, its root class and what it styles.
 * @param array<string,string>                                       $light The kit's light variables.
 * @return int How many variables were copied.
 */
function omnabase_build( string $root, array $build, array $light ): int {
	$rules = (string) file_get_contents( $root . '/' . $build['source'] );
	$used  = omnabase_used_variables( $rules, $light );
	$lines = array();
	foreach ( $light as $name => $value ) {
		if ( isset( $used[ $name ] ) ) {
			$lines[] = "  {$name}: {$value};";
		}
	}
	$css = "/* Built by bin/build-css.php from OmnaBase's packages/ui/src/styles/tokens.css (the light block, the variables {$build['what']} uses) and\n"
		. "   {$build['source']}. Do not edit: change {$build['source']} and build again. */\n\n"
		. "{$build['scope']} {\n" . implode( "\n", $lines ) . "\n}\n\n" . $rules;
	file_put_contents( $root . '/' . $build['target'], $css );
	return count( $lines );
}

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
 * @param string               $rules The stylesheet's rules.
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
