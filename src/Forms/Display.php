<?php
/**
 * The front-end switches: "theme styles only" and WooCommerce's My Account "Support" link.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Both switches live in one option and start off: the contact form and the message centre bring the plugin's small stylesheet until the
 * administrator asks for the theme's styles alone, and My Account stays as WooCommerce made it until the link is turned on. The same option
 * keeps the first-display marks (`first_shown`), which saving the switches leaves alone.
 */
final class Display {

	private const THEME_ONLY = 'theme_only';
	private const MY_ACCOUNT = 'my_account';
	private const FIRST_SHOWN = 'first_shown';

	/** Whether the administrator asked for the theme's styles alone. */
	public function themeOnly(): bool {
		return $this->flag( self::THEME_ONLY );
	}

	/** Whether My Account gets the "Support" link. */
	public function myAccount(): bool {
		return $this->flag( self::MY_ACCOUNT );
	}

	/**
	 * Stores both switches.
	 *
	 * @param bool $theme_only The theme's styles alone.
	 * @param bool $my_account The My Account link.
	 */
	public function save( bool $theme_only, bool $my_account ): void {
		$stored = get_option( Options::DISPLAY, array() );
		$kept   = is_array( $stored ) ? $stored : array();
		update_option( Options::DISPLAY, array( self::THEME_ONLY => $theme_only, self::MY_ACCOUNT => $my_account ) + $kept, false );
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
