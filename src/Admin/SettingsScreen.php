<?php
/**
 * Settings › OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Api\ApiUrl;
use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's one admin screen (`options-general.php?page=omnabase`), for administrators only: the connection (Connect, Disconnect,
 * Check connection), its health and the last webhook deliveries. The way back from OmnaBase's connect page arrives here too, handled at the
 * page's load hook before anything is printed. The forums, the facts switches and the outbox join it in later rounds.
 */
final class SettingsScreen {

	public const SLUG       = 'omnabase';
	public const CAPABILITY = 'manage_options';

	/**
	 * Builds the screen.
	 *
	 * @param PluginInfo     $info       The header fields (the version shown in the footer line).
	 * @param ConnectionView $connection The connection section.
	 * @param Health         $health     The health section.
	 * @param Flash          $flash      The message from the last action.
	 * @param Actions        $actions    The way back's handler.
	 */
	public function __construct( private PluginInfo $info, private ConnectionView $connection, private Health $health, private Flash $flash, private Actions $actions ) {}

	/** The screen's address: https on an https site (OmnaBase's way back must be https), whatever the current request's scheme. */
	public static function url(): string {
		return admin_url( 'options-general.php?page=' . self::SLUG, 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ) ? 'https' : 'admin' );
	}

	/** Registers the menu entry. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
	}

	/** Adds Settings › OmnaBase and the way back at its load hook. */
	public function addMenu(): void {
		$hook = add_options_page(
			__( 'OmnaBase', 'omnabase' ),
			__( 'OmnaBase', 'omnabase' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this->actions, 'wayBack' ) );
		}
	}

	/** Renders the screen. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OmnaBase.', 'omnabase' ) );
		}
		$view = array(
			'version'    => $this->info->version(),
			'apiUrl'     => ApiUrl::override(),
			'appUrl'     => ApiUrl::appOverride(),
			'notice'     => $this->flash->take( get_current_user_id() ),
			'connection' => $this->connection->build(),
			'health'     => $this->health->view(),
		);
		include $this->info->dir() . 'templates/admin/settings.php';
	}
}
