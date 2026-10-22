<?php
/**
 * The front end's look as the site owner chose it on the Appearance tab.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Look;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One option (`Options::APPEARANCE`) holds the four choices: the theme (light, dark, or the visitor's system), the accent (OmnaBase's blue,
 * stored as '', or the owner's own colour as `#rrggbb`), the font (the theme's, or OmnaBase's loaded from the plugin's files) and "Theme
 * styles only" (no stylesheet of the plugin's at all). A new site starts light, blue, in the theme's font, with the stylesheet on. Only the
 * Appearance save writes it, and only a real colour is ever kept: anything else leaves OmnaBase's blue, so nothing typed reaches a page.
 */
final class Appearance {

	public const LIGHT  = 'light';
	public const DARK   = 'dark';
	public const SYSTEM = 'system';

	public const FONT_INHERIT = 'inherit';
	public const FONT_LOAD    = 'load';

	/** OmnaBase's own blue: choosing it as the "own" colour is choosing no colour of one's own (as the app does). */
	public const BLUE = '#2271b1';

	private const THEMES = array( self::LIGHT, self::DARK, self::SYSTEM );
	private const FONTS  = array( self::FONT_INHERIT, self::FONT_LOAD );

	/**
	 * The stored choices, every one valid.
	 *
	 * @return array{theme:string,accent:string,font:string,themeOnly:bool}
	 */
	public function choices(): array {
		$stored = get_option( Options::APPEARANCE, array() );
		return self::clean( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Stores the choices (each one cleaned first).
	 *
	 * @param array<string,mixed> $choices `theme`, `accent`, `font`, `themeOnly`.
	 */
	public function save( array $choices ): void {
		update_option( Options::APPEARANCE, self::clean( $choices ), false );
	}

	/** Whether the owner asked for the theme's styles alone. */
	public function themeOnly(): bool {
		return $this->choices()['themeOnly'];
	}

	/** Whether OmnaBase's font is loaded. */
	public function loadsFont(): bool {
		return self::FONT_LOAD === $this->choices()['font'];
	}

	/**
	 * Valid choices from anything: unknown values fall back to the defaults.
	 *
	 * @param array<string,mixed> $raw What was stored or posted.
	 * @return array{theme:string,accent:string,font:string,themeOnly:bool}
	 */
	public static function clean( array $raw ): array {
		$theme = is_string( $raw['theme'] ?? null ) && in_array( $raw['theme'], self::THEMES, true ) ? $raw['theme'] : self::LIGHT;
		$font  = is_string( $raw['font'] ?? null ) && in_array( $raw['font'], self::FONTS, true ) ? $raw['font'] : self::FONT_INHERIT;
		return array(
			'theme'     => $theme,
			'accent'    => self::colour( $raw['accent'] ?? '' ),
			'font'      => $font,
			'themeOnly' => ! empty( $raw['themeOnly'] ),
		);
	}

	/**
	 * A colour as `#rrggbb` in lower case, or '' (OmnaBase's blue) for anything that is not one.
	 *
	 * @param mixed $value A posted or stored value.
	 */
	public static function colour( $value ): string {
		$hex = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( '/^#[0-9a-f]{6}$/', $hex ) && self::BLUE !== $hex ? $hex : '';
	}
}
