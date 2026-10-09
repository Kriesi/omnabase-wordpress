<?php
/**
 * Settings › OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Api\ApiUrl;
use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's one admin screen (`options-general.php?page=omnabase`), for administrators only. Round zero shows the connection:
 * "Connected to <workspace>" when a stored key answers `GET /me`, else why not. Connect, the forums, the facts switches, the outbox
 * and health join it in later rounds.
 */
final class SettingsScreen {

	public const SLUG       = 'omnabase';
	public const CAPABILITY = 'manage_options';

	/**
	 * Builds the screen.
	 *
	 * @param PluginInfo $info   The header fields (the version shown in the footer line).
	 * @param Client     $client The API client.
	 */
	public function __construct( private PluginInfo $info, private Client $client ) {}

	/** Registers the menu entry. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addMenu' ) );
	}

	/** Adds Settings › OmnaBase. */
	public function addMenu(): void {
		add_options_page(
			__( 'OmnaBase', 'omnabase' ),
			__( 'OmnaBase', 'omnabase' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** Renders the screen. */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage OmnaBase.', 'omnabase' ) );
		}
		$view = array(
			'version'    => $this->info->version(),
			'apiUrl'     => ApiUrl::override(),
			'connection' => $this->connectionView(),
		);
		include $this->info->dir() . 'templates/admin/settings.php';
	}

	/**
	 * What the connection section shows: a state (`connected`, `not_connected`, `error`) and its words.
	 *
	 * @return array{state:string,workspace:string,site:string,message:string}
	 */
	private function connectionView(): array {
		$me = $this->client->me();
		if ( is_wp_error( $me ) ) {
			$state = 'omnabase_not_connected' === $me->get_error_code() ? 'not_connected' : 'error';
			return array(
				'state'     => $state,
				'workspace' => '',
				'site'      => '',
				'message'   => $me->get_error_message(),
			);
		}
		return array(
			'state'     => 'connected',
			'workspace' => isset( $me['workspace']['name'] ) ? (string) $me['workspace']['name'] : '',
			'site'      => isset( $me['site']['name'] ) ? (string) $me['site']['name'] : '',
			'message'   => '',
		);
	}
}
