<?php
/**
 * The plugin's start: called once from omnabase.php after the autoloader.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress at `plugins_loaded`, so every other plugin (WooCommerce, EDD, bbPress) is loaded first; makes the outbox's
 * table on activation and clears its timers on deactivation (each on every site when WordPress does it for a whole network); declares
 * WooCommerce's newer order storage supported.
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
		add_action( 'before_woocommerce_init', static fn () => self::declareHpos( $main_file ) );
		register_activation_hook( $main_file, array( self::class, 'activate' ) );
		register_deactivation_hook( $main_file, array( self::class, 'deactivate' ) );
	}

	/**
	 * Activation: the outbox's table, on this site or, turned on for a whole network, on every site of it (a site added later gets its table
	 * on its first load, where the table is made when missing).
	 *
	 * @param bool $network_wide Whether WordPress turns the plugin on for every site of a network.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::onSites( $network_wide, static fn () => ( new Outbox\Table() )->install() );
	}

	/**
	 * Deactivation: the outbox's timer and the forum's waiting mail jobs are cleared (the rows and settings stay), on this site or, turned
	 * off for a whole network, on every site of it.
	 *
	 * @param bool $network_wide Whether WordPress turns the plugin off for every site of a network.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		self::onSites(
			$network_wide,
			static function () {
				Outbox\Schedule::clear();
				Forum\SubscriberMails::clear();
			}
		);
	}

	/**
	 * Runs a step on the current site, or on every site of the network (all of them, not WordPress's default first 100), coming back to the
	 * current site afterwards.
	 *
	 * @param bool     $network_wide Whether the step is for every site.
	 * @param callable $step         The step.
	 */
	private static function onSites( bool $network_wide, callable $step ): void {
		if ( ! $network_wide || ! is_multisite() ) {
			$step();
			return;
		}
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			switch_to_blog( (int) $site_id );
			$step();
			restore_current_blog();
		}
	}

	/**
	 * Tells WooCommerce the plugin works with its newer order storage (HPOS): orders are read through `wc_get_orders` alone.
	 *
	 * @param string $main_file The absolute path of omnabase.php.
	 */
	private static function declareHpos( string $main_file ): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', $main_file, true );
		}
	}
}
