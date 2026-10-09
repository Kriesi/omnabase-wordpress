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
 * table on activation and clears its timers on deactivation; declares WooCommerce's newer order storage supported.
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
		register_activation_hook( $main_file, static fn () => ( new Outbox\Table() )->install() );
		register_deactivation_hook( $main_file, array( self::class, 'deactivate' ) );
	}

	/** Deactivation: the outbox's timer and the forum's waiting mail jobs are cleared (the rows and settings stay). */
	public static function deactivate(): void {
		Outbox\Schedule::clear();
		Forum\SubscriberMails::clear();
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
