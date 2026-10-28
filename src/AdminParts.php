<?php
/**
 * The admin parts of the hand-wired container: the settings screen, its actions and the admin notices.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Admin\Actions;
use OmnaBase\WordPress\Admin\AppearanceActions;
use OmnaBase\WordPress\Admin\AppearanceView;
use OmnaBase\WordPress\Admin\AdminAssets;
use OmnaBase\WordPress\Admin\BuilderActions;
use OmnaBase\WordPress\Admin\BuilderChoices;
use OmnaBase\WordPress\Admin\BuilderView;
use OmnaBase\WordPress\Admin\ConnectionView;
use OmnaBase\WordPress\Admin\DataActions;
use OmnaBase\WordPress\Admin\FactsView;
use OmnaBase\WordPress\Admin\FormsView;
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
use OmnaBase\WordPress\Forms\FormList;
use OmnaBase\WordPress\Forms\Projects;
use OmnaBase\WordPress\Look\Appearance;
use OmnaBase\WordPress\Rest\DeliveryLog;
use OmnaBase\WordPress\Rest\LastPing;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Made by `Plugin` in the admin only (split out of it on 2026-10-22, when the sixth tab's parts joined): Settings › OmnaBase in six tabs,
 * its actions (Connect, Disconnect, Check connection, the way back, the setup line's Dismiss, the forum settings, the Forms and messages
 * tab's save, the Appearance tab's save, the customer-data switches, Retry, Discard and Send now), the Plugins list's Settings link and the
 * "reconnect needed" notice.
 */
final class AdminParts {

	/**
	 * Builds the parts' maker.
	 *
	 * @param PluginInfo $info  The header fields of omnabase.php.
	 * @param FormList   $forms The workspace's forms (shared with the shortcode).
	 */
	public function __construct( private PluginInfo $info, private FormList $forms ) {}

	/**
	 * Builds the admin parts and registers their hooks.
	 *
	 * @param Vault        $vault  The vault.
	 * @param DeliveryLog  $log    The last deliveries.
	 * @param LastPing     $ping   The last ping.
	 * @param CustomerData $data   The customer-data parts.
	 * @param Forums       $forum  The forum parts.
	 * @param Portal       $portal The message centre's parts.
	 */
	public function register( Vault $vault, DeliveryLog $log, LastPing $ping, CustomerData $data, Forums $forum, Portal $portal ): void {
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
		( new AppearanceActions( new Appearance(), $flash ) )->register();
		$sections = array(
			'setup'      => array( $setup, 'build' ),
			'forms'      => array( new FormsView( $this->forms, new Projects( $info, $vault ), $info ), 'build' ),
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
		( new BuilderActions( new BuilderChoices( $projects, $this->forms ), new PageMaker( $portal->page ), $flash ) )->register();
		( new AdminAssets( $this->info ) )->register();
		return new BuilderView( $projects, $info, $portal->page, new TaggedPages(), $this->forms );
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
			'forum'       => array( new ForumView( $forum->settings, new Forum\PostFields( $forum->settings ) ), 'build' ),
			'portal'      => array( new PortalView( $portal->page ), 'build' ),
			'shortcodes'  => array( new ShortcodesView( $display ), 'build' ),
			'appearance'  => array( new AppearanceView( new Appearance() ), 'build' ),
			'facts'       => array( new FactsView( $data->switches, $data->sources, $data->log ), 'build' ),
			'outbox'      => array( new OutboxView( $data->table, $data->worker ), 'build' ),
		);
	}
}
