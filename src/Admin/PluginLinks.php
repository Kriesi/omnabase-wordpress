<?php
/**
 * The plugin's "Settings" link on WordPress's Plugins list.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Beside Deactivate on the plugin's row, a "Settings" link to Settings › OmnaBase, through WordPress's `plugin_action_links_<file>` filter
 * (a filter: a named moment where a plugin may change a list before WordPress uses it), registered with the admin parts.
 */
final class PluginLinks {

	/**
	 * Builds the links.
	 *
	 * @param string $basename The plugin's main file as WordPress names it (`omnabase/omnabase.php`).
	 */
	public function __construct( private string $basename ) {}

	/** Registers the filter. */
	public function register(): void {
		add_filter( 'plugin_action_links_' . $this->basename, array( $this, 'add' ) );
	}

	/**
	 * The row's links with "Settings" first.
	 *
	 * @param mixed $links WordPress's links for the row.
	 * @return array<int|string,string>
	 */
	public function add( $links ): array {
		$settings = sprintf( '<a href="%1$s">%2$s</a>', esc_url( SettingsScreen::url() ), esc_html__( 'Settings', 'omnabase' ) );
		return array( 'settings' => $settings ) + ( is_array( $links ) ? $links : array() );
	}
}
