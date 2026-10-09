<?php
/**
 * The plugin's entry: builds the parts and registers their hooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Admin\Actions;
use OmnaBase\WordPress\Admin\ConnectionView;
use OmnaBase\WordPress\Admin\Flash;
use OmnaBase\WordPress\Admin\Health;
use OmnaBase\WordPress\Admin\Notices;
use OmnaBase\WordPress\Admin\SettingsScreen;
use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Api\Loopback;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Connect\ConnectState;
use OmnaBase\WordPress\Connect\Disconnect;
use OmnaBase\WordPress\Connect\Flow;
use OmnaBase\WordPress\Connect\SiteCheck;
use OmnaBase\WordPress\Rest\Customer;
use OmnaBase\WordPress\Rest\DeliveryLog;
use OmnaBase\WordPress\Rest\Events;
use OmnaBase\WordPress\Rest\LastPing;
use OmnaBase\WordPress\Rest\ReplayGuard;
use OmnaBase\WordPress\Rest\SignedCall;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * The hand-wired container: each part is made here and told to register its own hooks. Everywhere: the two signed receivers. In the admin:
 * Settings › OmnaBase, its actions (Connect, Disconnect, Check connection, the way back) and the "reconnect needed" notice.
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
		$vault = new Vault();
		$log   = new DeliveryLog();
		$ping  = new LastPing();
		$door  = new SignedCall( $vault );
		( new Events( $door, new ReplayGuard(), $log, $ping ) )->register();
		( new Customer( $door ) )->register();
		if ( is_admin() ) {
			$this->bootAdmin( $vault, $log, $ping );
		}
	}

	/** The header fields. */
	public function info(): PluginInfo {
		return $this->info;
	}

	/**
	 * The admin parts.
	 *
	 * @param Vault       $vault The vault.
	 * @param DeliveryLog $log   The last deliveries.
	 * @param LastPing    $ping  The last ping.
	 */
	private function bootAdmin( Vault $vault, DeliveryLog $log, LastPing $ping ): void {
		$client  = new Client( $vault );
		$info    = new ConnectionInfo();
		$site    = new SiteCheck();
		$flash   = new Flash();
		$health  = new Health( $client, new Loopback(), $ping, $log, $vault );
		$flow    = new Flow( $client, $vault, new ConnectState( $vault ), $info, $site, $health );
		$actions = new Actions( $flow, new Disconnect( $client, $vault, $info, $health, $ping ), $health, $flash );
		$actions->register();
		( new SettingsScreen( $this->info, new ConnectionView( $vault, $client, $info, $site ), $health, $flash, $actions ) )->register();
		( new Notices( $vault ) )->register();
	}
}
