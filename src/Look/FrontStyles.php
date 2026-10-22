<?php
/**
 * The stylesheets the contact form and the message centre bring.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Look;

use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Both shortcodes queue the same stylesheet (`assets/form.css`: OmnaBase's colours in light and dark) and, only when the owner chose
 * OmnaBase's font, the font's own stylesheet (`assets/fonts.css`, the plugin's local font files). "Theme styles only" and the
 * `omnabase_form_styles` filter keep both away; the wrapper's marks stay either way, so a theme may style by them.
 */
final class FrontStyles {

	/**
	 * Builds the queue's part.
	 *
	 * @param PluginInfo $info       The plugin's folder and version.
	 * @param Display    $display    Whether the stylesheet is wanted.
	 * @param Appearance $appearance Whether the font is.
	 */
	public function __construct( private PluginInfo $info, private Display $display, private Appearance $appearance ) {}

	/** Queues the stylesheet and, when chosen, the font's. */
	public function enqueue(): void {
		if ( ! $this->display->wantsStylesheet() ) {
			return;
		}
		wp_enqueue_style( 'omnabase-form', $this->address( 'assets/form.css' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the version is in the address
		if ( $this->appearance->loadsFont() ) {
			wp_enqueue_style( 'omnabase-font', $this->address( 'assets/fonts.css' ), array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the version is in the address
		}
	}

	/**
	 * A stylesheet's address with the plugin's version in it (so a new release is fetched again), the same as the page prints it.
	 *
	 * @param string $file The file under the plugin's folder.
	 */
	private function address( string $file ): string {
		return add_query_arg( 'ver', rawurlencode( $this->info->version() ), plugins_url( $file, $this->info->mainFile() ) );
	}

	/** The wrapper's marks for the stored look (attribute name => value). */
	public function marks(): array {
		return WrapperMarks::of( $this->appearance->choices() );
	}
}
