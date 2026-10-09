<?php
/**
 * The plugin's start: called once from omnabase.php after the autoloader.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress at `plugins_loaded`, so every other plugin (WooCommerce, EDD, bbPress) is loaded first.
 */
final class Bootstrap {

	/**
	 * The one running plugin, kept so a second call does not boot twice.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $plugin = null;

	/**
	 * Starts the plugin.
	 *
	 * @param string $main_file The absolute path of omnabase.php.
	 */
	public static function start( string $main_file ): void {
		if ( null !== self::$plugin ) {
			return;
		}
		self::$plugin = new Plugin( PluginInfo::fromFile( $main_file ) );
		add_action( 'plugins_loaded', array( self::$plugin, 'boot' ) );
	}
}
