<?php
/**
 * The plugin's entry: builds the parts and registers their hooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Admin\SettingsScreen;
use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Api\StoredKey;

defined( 'ABSPATH' ) || exit;

/**
 * The hand-wired container: each part is made here and told to register its own hooks. Round zero has one part, the settings
 * screen under Settings › OmnaBase.
 */
final class Plugin {

	/**
	 * Builds the plugin.
	 *
	 * @param PluginInfo $info The header fields of omnabase.php.
	 */
	public function __construct( private PluginInfo $info ) {}

	/** Runs at `plugins_loaded`. */
	public function boot(): void {
		if ( is_admin() ) {
			( new SettingsScreen( $this->info, new Client( new StoredKey() ) ) )->register();
		}
	}

	/** The header fields. */
	public function info(): PluginInfo {
		return $this->info;
	}
}
