<?php
/**
 * The plugin's entry: builds the parts and registers their hooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Admin\Actions;
use OmnaBase\WordPress\Admin\AdminAssets;
use OmnaBase\WordPress\Admin\BuilderActions;
use OmnaBase\WordPress\Admin\BuilderChoices;
use OmnaBase\WordPress\Admin\BuilderView;
use OmnaBase\WordPress\Admin\ConnectionView;
use OmnaBase\WordPress\Admin\DataActions;
use OmnaBase\WordPress\Admin\FactsView;
use OmnaBase\WordPress\Admin\Flash;
use OmnaBase\WordPress\Admin\ForumActions;
use OmnaBase\WordPress\Admin\ForumView;
use OmnaBase\WordPress\Admin\Health;
use OmnaBase\WordPress\Admin\HealthNotes;
use OmnaBase\WordPress\Admin\Notices;
use OmnaBase\WordPress\Admin\OutboxView;
use OmnaBase\WordPress\Admin\PageMaker;
use OmnaBase\WordPress\Admin\PagesActions;
use OmnaBase\WordPress\Admin\PluginLinks;
use OmnaBase\WordPress\Admin\PortalView;
use OmnaBase\WordPress\Admin\SettingsScreen;
use OmnaBase\WordPress\Admin\SetupLine;
use OmnaBase\WordPress\Admin\ShortcodesView;
use OmnaBase\WordPress\Admin\TaggedPages;
use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Api\Loopback;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Connect\ConnectState;
use OmnaBase\WordPress\Connect\Disconnect;
use OmnaBase\WordPress\Connect\Flow;
use OmnaBase\WordPress\Connect\SiteCheck;
use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\Forms\FirstShown;
use OmnaBase\WordPress\Forms\Projects;
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
 * routes (`Forms`). In the admin: Settings › OmnaBase in five tabs, its actions (Connect, Disconnect, Check connection, the way back, the
 * setup line's Dismiss, the forum settings, the Forms and messages tab's save, the customer-data switches, Retry, Discard and Send now), the
 * Plugins list's Settings link and the "reconnect needed" notice. Everywhere too: the
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
		( new FirstShown( new Display() ) )->register();
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
		$setup   = new SetupLine( $vault, $display, $flash );
		$setup->register();
		( new DataActions( $data->switches, $data->sources, $data->table, $data->queue, $flash ) )->register();
		( new ForumActions( $forum->settings, $flash ) )->register();
		( new PagesActions( $portal->page, $display, $flash ) )->register();
		$sections = array(
			'setup'      => array( $setup, 'build' ),
			'builder'    => array( $this->bootBuilder( $vault, $info, $portal, $flash ), 'build' ),
			'connection' => array( new ConnectionView( $vault, $client, $info, $site ), 'build' ),
			'health'     => array( $health, 'view' ),
			'deliveries' => static fn () => array( 'rows' => $log->recent() ),
		) + $this->settingsSections( $data, $forum, $portal, $display );
		( new SettingsScreen( $this->info, $flash, $actions, $sections, array( $data->table, 'attentionCount' ) ) )->register();
		( new PluginLinks( plugin_basename( $this->info->mainFile() ) ) )->register();
		( new Notices( $vault ) )->register();
	}

	/**
	 * The shortcode builder: its "Create the page for me" action and the screen's script; answers the dialog's view.
	 *
	 * @param Vault          $vault  The key (whether the site is connected).
	 * @param ConnectionInfo $info   The key's projects.
	 * @param Portal         $portal The message centre's page.
	 * @param Flash          $flash  The message for the next view.
	 */
	private function bootBuilder( Vault $vault, ConnectionInfo $info, Portal $portal, Flash $flash ): BuilderView {
		$projects = new Projects( $info, $vault );
		( new BuilderActions( new BuilderChoices( $projects ), new PageMaker( $portal->page ), $flash ) )->register();
		( new AdminAssets( $this->info ) )->register();
		return new BuilderView( $projects, $info, $portal->page, new TaggedPages() );
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
