<?php
/**
 * The front-end switches: whether the stylesheet loads, and WooCommerce's My Account "Support" link.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Look\Appearance;
use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The My Account switch lives in one option and starts off: My Account stays as WooCommerce made it until the link is turned on. The same
 * option keeps the first-display marks (`first_shown`), which saving the switch leaves alone. "Theme styles only" moved to the Appearance tab
 * (2026-10-22) and is read from the look's own option (`Look\Appearance`): the contact form and the message centre bring the plugin's
 * stylesheet until the administrator asks for the theme's styles alone.
 */
final class Display {

	private const OLD_THEME_ONLY = 'theme_only';
	private const MY_ACCOUNT     = 'my_account';
	private const FIRST_SHOWN    = 'first_shown';

	/**
	 * Builds the switches.
	 *
	 * @param Appearance $appearance The stored look ("Theme styles only").
	 */
	public function __construct( private Appearance $appearance = new Appearance() ) {}

	/** Whether the administrator asked for the theme's styles alone (the Appearance tab's switch). */
	public function themeOnly(): bool {
		return $this->appearance->themeOnly();
	}

	/** Whether My Account gets the "Support" link. */
	public function myAccount(): bool {
		return $this->flag( self::MY_ACCOUNT );
	}

	/**
	 * Stores the My Account switch (the first-display marks kept; the switch that moved to the Appearance tab is no longer kept here).
	 *
	 * @param bool $my_account The My Account link.
	 */
	public function save( bool $my_account ): void {
		$stored = get_option( Options::DISPLAY, array() );
		$kept   = is_array( $stored ) ? $stored : array();
		unset( $kept[ self::OLD_THEME_ONLY ] );
		update_option( Options::DISPLAY, array( self::MY_ACCOUNT => $my_account ) + $kept, false );
	}

	/**
	 * When each tag first showed on the public site (`[ 'form' => <time>, 'messages' => <time> ]`; empty until then). Round two records it;
	 * the settings screen's setup line reads it.
	 *
	 * @return array<string,int>
	 */
	public function firstShown(): array {
		$stored = get_option( Options::DISPLAY, array() );
		$marks  = is_array( $stored ) && is_array( $stored[ self::FIRST_SHOWN ] ?? null ) ? $stored[ self::FIRST_SHOWN ] : array();
		return array_map( 'intval', array_filter( $marks ) );
	}

	/** Whether the form and the message centre load the plugin's stylesheet: the switch, then the filter. */
	public function wantsStylesheet(): bool {
		/**
		 * Whether the form and the message centre bring their own small stylesheet (built from OmnaBase's colours, in the theme's font).
		 * False leaves both to the theme's styles alone, as the settings screen's "theme styles only" switch does.
		 *
		 * @param bool $styles True unless the switch is on.
		 */
		return (bool) apply_filters( 'omnabase_form_styles', ! $this->themeOnly() );
	}

	/**
	 * One switch from the stored option (off when missing).
	 *
	 * @param string $name The switch.
	 */
	private function flag( string $name ): bool {
		$stored = get_option( Options::DISPLAY, array() );
		return is_array( $stored ) && ! empty( $stored[ $name ] );
	}
}
