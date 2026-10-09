<?php
/**
 * The plugin's entry: builds the parts and registers their hooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Admin\Actions;
use OmnaBase\WordPress\Admin\ConnectionView;
use OmnaBase\WordPress\Admin\DataActions;
use OmnaBase\WordPress\Admin\FactsView;
use OmnaBase\WordPress\Admin\Flash;
use OmnaBase\WordPress\Admin\ForumActions;
use OmnaBase\WordPress\Admin\ForumView;
use OmnaBase\WordPress\Admin\Health;
use OmnaBase\WordPress\Admin\Notices;
use OmnaBase\WordPress\Admin\OutboxView;
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
 * The hand-wired container: each part is made here and told to register its own hooks. Everywhere: the two signed receivers, the outbox
 * and the licence events (`CustomerData`), bbPress's moments and OmnaBase's replies posted back (`Forums`), the shortcode form and its two
 * routes (`Forms`). In the admin: Settings › OmnaBase, its actions (Connect, Disconnect, Check connection, the way back, the forum
 * settings, the customer-data switches, Retry and Discard) and the "reconnect needed" notice.
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
		$data  = new CustomerData( $vault );
		$forum = new Forums( $data );
		$data->registerBackground();
		$forum->registerBackground();
		( new Forms( $this->info, $vault, $data ) )->register();
		( new Events( $door, new ReplayGuard(), $log, $ping ) )->register();
		( new Customer( $door, $data->responder ) )->register();
		if ( is_admin() ) {
			$this->bootAdmin( $vault, $log, $ping, $data, $forum );
		}
	}

	/** The header fields. */
	public function info(): PluginInfo {
		return $this->info;
	}

	/**
	 * The admin parts.
	 *
	 * @param Vault        $vault The vault.
	 * @param DeliveryLog  $log   The last deliveries.
	 * @param LastPing     $ping  The last ping.
	 * @param CustomerData $data  The customer-data parts.
	 * @param Forums       $forum The forum parts.
	 */
	private function bootAdmin( Vault $vault, DeliveryLog $log, LastPing $ping, CustomerData $data, Forums $forum ): void {
		$client  = new Client( $vault );
		$info    = new ConnectionInfo();
		$site    = new SiteCheck();
		$flash   = new Flash();
		$health  = new Health( $client, new Loopback(), $ping, $log, $vault );
		$flow    = new Flow( $client, $vault, new ConnectState( $vault ), $info, $site, $health );
		$actions = new Actions( $flow, new Disconnect( $client, $vault, $info, $health, $ping ), $health, $flash );
		$actions->register();
		( new DataActions( $data->switches, $data->sources, $data->table, $flash ) )->register();
		( new ForumActions( $forum->settings, $flash ) )->register();
		$connection = new ConnectionView( $vault, $client, $info, $site );
		$facts      = new FactsView( $data->switches, $data->sources, $data->log );
		$outbox     = new OutboxView( $data->table, $data->worker );
		$sections   = array(
			'connection' => array( $connection, 'build' ),
			'health'     => array( $health, 'view' ),
			'forum'      => array( new ForumView( $forum->settings ), 'build' ),
			'facts'      => array( $facts, 'build' ),
			'outbox'     => array( $outbox, 'build' ),
		);
		( new SettingsScreen( $this->info, $flash, $actions, $sections ) )->register();
		( new Notices( $vault ) )->register();
	}
}
