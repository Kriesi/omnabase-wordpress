<?php
/**
 * Settings › OmnaBase › Appearance: what the tab shows.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Look\Appearance;

defined( 'ABSPATH' ) || exit;

/**
 * The stored look for the tab's one form: the theme, the accent (OmnaBase's blue, or the owner's colour with the colour field's value),
 * the font and "Theme styles only". The colour field shows OmnaBase's blue while no own colour is stored.
 */
final class AppearanceView {

	/**
	 * Builds the view.
	 *
	 * @param Appearance $appearance The stored look.
	 */
	public function __construct( private Appearance $appearance ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{theme:string,own:bool,colour:string,font:string,themeOnly:bool}
	 */
	public function build(): array {
		$choices = $this->appearance->choices();
		return array(
			'theme'     => $choices['theme'],
			'own'       => '' !== $choices['accent'],
			'colour'    => '' !== $choices['accent'] ? $choices['accent'] : Appearance::BLUE,
			'font'      => $choices['font'],
			'themeOnly' => $choices['themeOnly'],
		);
	}
}
