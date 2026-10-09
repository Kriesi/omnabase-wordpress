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
use OmnaBase\WordPress\Admin\DisplayActions;
use OmnaBase\WordPress\Admin\FactsView;
use OmnaBase\WordPress\Admin\Flash;
use OmnaBase\WordPress\Admin\ForumActions;
use OmnaBase\WordPress\Admin\ForumView;
use OmnaBase\WordPress\Admin\Health;
use OmnaBase\WordPress\Admin\HealthNotes;
use OmnaBase\WordPress\Admin\Notices;
use OmnaBase\WordPress\Admin\OutboxView;
use OmnaBase\WordPress\Admin\PortalActions;
use OmnaBase\WordPress\Admin\PortalView;
use OmnaBase\WordPress\Admin\SettingsScreen;
use OmnaBase\WordPress\Admin\ShortcodesView;
use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Api\Loopback;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Connect\ConnectState;
use OmnaBase\WordPress\Connect\Disconnect;
use OmnaBase\WordPress\Connect\Flow;
use OmnaBase\WordPress\Connect\SiteCheck;
use OmnaBase\WordPress\Forms\Display;
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
 * settings, the message centre's page, the customer-data switches, Retry and Discard) and the "reconnect needed" notice. Everywhere too: the
 * message centre (`Portal`: its shortcode, form posts, no-cache marking, the profile watcher, My Account's link), and the privacy text,
 * exporter and eraser (`Privacy`).
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
		$portal = new Portal( $this->info, $vault, $data );
		$portal->register();
		( new Privacy( $data ) )->register();
		( new Events( $door, new ReplayGuard(), $log, $ping ) )->register();
		( new Customer( $door, $data->responder ) )->register();
		if ( is_admin() ) {
			$this->bootAdmin( $vault, $log, $ping, $data, $forum, $portal );
		}
	}

	/** The header fields. */
	public function info(): PluginInfo {
		return $this->info;
	}

	/**
	 * The admin parts.
	 *
	 * @param Vault        $vault  The vault.
	 * @param DeliveryLog  $log    The last deliveries.
	 * @param LastPing     $ping   The last ping.
	 * @param CustomerData $data   The customer-data parts.
	 * @param Forums       $forum  The forum parts.
	 * @param Portal       $portal The message centre's parts.
	 */
	private function bootAdmin( Vault $vault, DeliveryLog $log, LastPing $ping, CustomerData $data, Forums $forum, Portal $portal ): void {
		$client  = new Client( $vault );
		$info    = new ConnectionInfo();
		$site    = new SiteCheck();
		$flash   = new Flash();
		$health  = new Health( $client, new Loopback(), $ping, $log, $vault );
		$flow    = new Flow( $client, $vault, new ConnectState( $vault ), $info, $site, $health );
		$actions = new Actions( $flow, new Disconnect( $client, $vault, $info, $health, $ping ), $health, $flash );
		$actions->register();
		$display = new Display();
		( new DataActions( $data->switches, $data->sources, $data->table, $data->queue, $flash ) )->register();
		( new ForumActions( $forum->settings, $flash ) )->register();
		( new PortalActions( $portal->page, $flash ) )->register();
		( new DisplayActions( $display, $flash ) )->register();
		$sections = array(
			'connection' => array( new ConnectionView( $vault, $client, $info, $site ), 'build' ),
			'health'     => array( $health, 'view' ),
		) + $this->settingsSections( $data, $forum, $portal, $display );
		( new SettingsScreen( $this->info, $flash, $actions, $sections ) )->register();
		( new Notices( $vault ) )->register();
	}

	/**
	 * The screen's sections below the connection and its health, each a view to build at render time.
	 *
	 * @param CustomerData $data    The customer-data parts.
	 * @param Forums       $forum   The forum parts.
	 * @param Portal       $portal  The message centre's parts.
	 * @param Display      $display The front-end switches.
	 * @return array<string,callable():array>
	 */
	private function settingsSections( CustomerData $data, Forums $forum, Portal $portal, Display $display ): array {
		return array(
			'healthNotes' => array( new HealthNotes( $data->log ), 'build' ),
			'forum'       => array( new ForumView( $forum->settings ), 'build' ),
			'portal'      => array( new PortalView( $portal->page ), 'build' ),
			'shortcodes'  => array( new ShortcodesView( $display ), 'build' ),
			'facts'       => array( new FactsView( $data->switches, $data->sources, $data->log ), 'build' ),
			'outbox'      => array( new OutboxView( $data->table, $data->worker ), 'build' ),
		);
	}
}
