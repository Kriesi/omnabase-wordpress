<?php
/**
 * The settings screen's own script and stylesheet (the shortcode builder).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * `assets/admin.js` (plain JavaScript: the builder's dialog, the live tag, Copy) and `assets/admin.css` are queued at
 * `admin_enqueue_scripts`, WordPress's moment for admin scripts, and only when the page is Settings › OmnaBase: no other admin page and no
 * visitor's page carries them.
 */
final class AdminAssets {

	public const HANDLE = 'omnabase-admin';

	/**
	 * Builds the loader.
	 *
	 * @param PluginInfo $info The plugin's main file and version.
	 */
	public function __construct( private PluginInfo $info ) {}

	/** Registers the loader. */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Queues both files on the plugin's screen.
	 *
	 * @param string $hook_suffix The admin page's hook name (`settings_page_omnabase` on the plugin's screen).
	 */
	public function enqueue( $hook_suffix ): void {
		if ( get_plugin_page_hookname( SettingsScreen::SLUG, 'options-general.php' ) !== $hook_suffix ) {
			return;
		}
		$version = $this->info->version();
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/admin.js', $this->info->mainFile() ), array(), $version, array( 'in_footer' => true ) );
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/admin.css', $this->info->mainFile() ), array(), $version );
	}
}
