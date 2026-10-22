<?php
/**
 * Reading OmnaBase's tokens.css for bin/build-css.php: the file's blocks by selector, and which variables a stylesheet's rules need in
 * each block. Pure functions, no output (the build script writes the files).
 *
 * @package OmnaBase
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- a command-line build script, not WordPress code.

/**
 * The kit's blocks: `light` (the first `:root`), `primitives` (every plain `:root`: the light block and the night scale), `dark`
 * (`:root[data-theme="dark"]`), `accent`, `accentDark` and `accentSystem` (the accent blocks, the last inside the device's dark setting).
 * The typeface's tokens are left out of every block but `primitives`.
 *
 * @param string $css The tokens file.
 * @return array<string,array<string,string>>
 */
function omnabase_kit_blocks( string $css ): array {
	$blocks = omnabase_css_blocks( (string) preg_replace( '#/\*.*?\*/#s', '', $css ) );
	$pick   = static function ( string $selector, bool $in_media ) use ( $blocks ): array {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( $b['selector'] === $selector && ( '' !== $b['media'] ) === $in_media ) {
				$out += omnabase_variables( $b['body'] );
			}
		}
		return $out;
	};
	$first = array();
	foreach ( $blocks as $b ) {
		if ( ':root' === $b['selector'] && '' === $b['media'] ) {
			$first = omnabase_variables( $b['body'] );
			break;
		}
	}
	$no_font = static fn ( array $vars ): array => array_filter( $vars, static fn ( $k ) => ! str_starts_with( (string) $k, '--ob-font' ), ARRAY_FILTER_USE_KEY );
	return array(
		'light'        => $no_font( $first ),
		'primitives'   => $pick( ':root', false ),
		'dark'         => $no_font( $pick( ':root[data-theme="dark"]', false ) ),
		'accent'       => $pick( ':root[data-accent]', false ),
		'accentDark'   => $pick( ':root[data-accent][data-theme="dark"]', false ),
		'accentSystem' => $pick( ':root[data-accent]:not([data-theme="light"])', true ),
	);
}

/**
 * What a stylesheet's rules need from the kit, block by block: `light` (every variable the rules use and those they refer to, with the
 * primitives the dark values refer to), `dark` (the dark values of the light ones the dark theme changes) and the three accent blocks (the
 * accent rules for the variables the stylesheet has). Without a dark theme only `light` is filled.
 *
 * @param string                             $rules The stylesheet's rules.
 * @param array<string,array<string,string>> $kit   The kit's blocks.
 * @param bool                               $dark  Whether the stylesheet has a dark theme.
 * @return array{light:array<string,string>,dark:array<string,string>,accent:array<string,string>,accentDark:array<string,string>,accentSystem:array<string,string>}
 */
function omnabase_token_sets( string $rules, array $kit, bool $dark ): array {
	$known = $kit['light'] + array_filter( $kit['primitives'], static fn ( $k ) => ! str_starts_with( (string) $k, '--ob-font' ), ARRAY_FILTER_USE_KEY );
	$need  = omnabase_references( $rules );
	do {
		$used     = omnabase_closure( $need, $known );
		$dark_set = $dark ? array_intersect_key( $kit['dark'], $used ) : array();
		$more     = array_diff( omnabase_references( implode( ';', $dark_set ) ), array_keys( $used ) );
		$need     = array_merge( $need, $more );
	} while ( array() !== $more );
	$light = array();
	foreach ( $known as $name => $value ) {
		if ( isset( $used[ $name ] ) ) {
			$light[ $name ] = $value;
		}
	}
	$accent = static fn ( string $key ): array => $dark ? array_intersect_key( $kit[ $key ], $light ) : array();
	return array( 'light' => $light, 'dark' => $dark_set, 'accent' => $accent( 'accent' ), 'accentDark' => $accent( 'accentDark' ), 'accentSystem' => $accent( 'accentSystem' ) );
}

/**
 * The variables some CSS refers to (`var(--ob-…)`), each once.
 *
 * @param string $css The CSS.
 * @return string[]
 */
function omnabase_references( string $css ): array {
	preg_match_all( '/var\(\s*(--ob-[a-z0-9-]+)/', $css, $m );
	return array_values( array_unique( $m[1] ) );
}

/**
 * The named variables with the ones they refer to, transitively (unknown names are dropped: the owner's accent is data, set on the page).
 *
 * @param string[]             $names The variables wanted.
 * @param array<string,string> $vars  Every variable the kit defines.
 * @return array<string,true>
 */
function omnabase_closure( array $names, array $vars ): array {
	$todo = $names;
	$used = array();
	while ( array() !== $todo ) {
		$name = array_pop( $todo );
		if ( isset( $used[ $name ] ) || ! isset( $vars[ $name ] ) ) {
			continue;
		}
		$used[ $name ] = true;
		$todo          = array_merge( $todo, omnabase_references( $vars[ $name ] ) );
	}
	return $used;
}

/**
 * A block's custom properties, name => value, the first one kept.
 *
 * @param string $body The block's body.
 * @return array<string,string>
 */
function omnabase_variables( string $body ): array {
	preg_match_all( '/(--ob-[a-z0-9-]+)\s*:\s*([^;]+);/', $body, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $d ) {
		$out[ $d[1] ] ??= trim( $d[2] );
	}
	return $out;
}

/**
 * The blocks of a stylesheet: each `selector { … }` at the top and inside each `@media`, with the media text it sits in ('' outside).
 *
 * @param string $css   The CSS, comments removed.
 * @param string $media The at-rule the CSS sits in.
 * @return array<int,array{selector:string,media:string,body:string}>
 */
function omnabase_css_blocks( string $css, string $media = '' ): array {
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
			$out = array_merge( $out, omnabase_css_blocks( $body, trim( substr( $head, 6 ) ) ) );
		} else {
			foreach ( array_map( 'trim', explode( ',', $head ) ) as $selector ) {
				$out[] = array( 'selector' => (string) preg_replace( '/\s+/', ' ', $selector ), 'media' => $media, 'body' => $body );
			}
		}
		$i = $j;
	}
	return $out;
}
