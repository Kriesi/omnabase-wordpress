<?php
/**
 * The marks the front end's wrapper carries for the chosen look.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Look;

defined( 'ABSPATH' ) || exit;

/**
 * As OmnaBase's app marks its page: `data-theme="light|dark"` for an explicit theme and none for the visitor's system (the stylesheet then
 * follows the device); for an own colour `data-accent="custom"` with `--ob-accent-user` and `--ob-on-accent-user` in the style. The text on
 * the accent is the readable one of white and near-black (the founder's decision of 2026-10-22 for a shop's public pages: whichever reads
 * better against the colour; the app keeps its own rule). Pure: the choices in, attribute names and values out (the templates escape them).
 */
final class WrapperMarks {

	public const WHITE = '#ffffff';
	public const INK   = '#1a1a1a';

	/**
	 * The wrapper's attributes, name => value.
	 *
	 * @param array{theme:string,accent:string,font:string,themeOnly:bool} $choices The stored look.
	 * @return array<string,string>
	 */
	public static function of( array $choices ): array {
		$marks = array();
		if ( Appearance::SYSTEM !== $choices['theme'] ) {
			$marks['data-theme'] = $choices['theme'];
		}
		if ( '' !== $choices['accent'] ) {
			$marks['data-accent'] = 'custom';
			$marks['style']       = '--ob-accent-user: ' . $choices['accent'] . '; --ob-on-accent-user: ' . self::onAccent( $choices['accent'] ) . ';';
		}
		return $marks;
	}

	/**
	 * The text colour that reads better on a colour: white or near-black, by WCAG's contrast.
	 *
	 * @param string $hex A colour, `#rrggbb`.
	 */
	public static function onAccent( string $hex ): string {
		$colour = self::luminance( $hex );
		$white  = 1.05 / ( $colour + 0.05 );
		$ink    = ( $colour + 0.05 ) / ( self::luminance( self::INK ) + 0.05 );
		return $white >= $ink ? self::WHITE : self::INK;
	}

	/**
	 * WCAG's relative luminance of a colour, 0 (black) to 1 (white).
	 *
	 * @param string $hex A colour, `#rrggbb`.
	 */
	private static function luminance( string $hex ): float {
		$channels = array_map(
			static function ( int $at ) use ( $hex ): float {
				$c = hexdec( substr( $hex, $at, 2 ) ) / 255;
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			array( 1, 3, 5 )
		);
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
}
