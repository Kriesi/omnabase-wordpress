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
 * The template names both shortcodes and their attributes; this hands it the My Account switch ("theme styles only" lives on the Appearance
 * tab since 2026-10-22) and whether WooCommerce is active (the My Account switch is offered only then).
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
	 * @return array{myAccount:bool,shop:bool}
	 */
	public function build(): array {
		return array(
			'myAccount' => $this->display->myAccount(),
			'shop'      => self::shopActive(),
		);
	}

	/** Whether WooCommerce is active (its My Account page exists). */
	public static function shopActive(): bool {
		return class_exists( 'WooCommerce' );
	}
}
