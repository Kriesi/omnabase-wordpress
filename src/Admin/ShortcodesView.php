<?php
/**
 * The settings screen's Shortcodes section: the two shortcodes and the front-end switches.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forms\Display;

defined( 'ABSPATH' ) || exit;

/**
 * The template names both shortcodes and their attributes; this hands it the two switches ("theme styles only", the My Account link) and
 * whether WooCommerce is active (the My Account switch is offered only then).
 */
final class ShortcodesView {

	/**
	 * Builds the section.
	 *
	 * @param Display $display The switches.
	 */
	public function __construct( private Display $display ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{themeOnly:bool,myAccount:bool,shop:bool}
	 */
	public function build(): array {
		return array(
			'themeOnly' => $this->display->themeOnly(),
			'myAccount' => $this->display->myAccount(),
			'shop'      => self::shopActive(),
		);
	}

	/** Whether WooCommerce is active (its My Account page exists). */
	public static function shopActive(): bool {
		return class_exists( 'WooCommerce' );
	}
}
