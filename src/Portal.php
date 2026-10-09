<?php
/**
 * The message centre's parts (round five), wired once.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forms\Guard;
use OmnaBase\WordPress\Forms\Projects;
use OmnaBase\WordPress\Portal\Centre;
use OmnaBase\WordPress\Portal\Controller;
use OmnaBase\WordPress\Portal\LinkLimit;
use OmnaBase\WordPress\Portal\NoCache;
use OmnaBase\WordPress\Portal\PickedPage;
use OmnaBase\WordPress\Portal\PortalApi;
use OmnaBase\WordPress\Portal\Requests;
use OmnaBase\WordPress\Portal\Shortcode;
use OmnaBase\WordPress\Portal\Views;
use OmnaBase\WordPress\Portal\Visitor;
use OmnaBase\WordPress\Security\Vault;
use OmnaBase\WordPress\Sync\KnownUsers;
use OmnaBase\WordPress\Sync\ProfileWatcher;
use OmnaBase\WordPress\Sync\SiteId;

defined( 'ABSPATH' ) || exit;

/**
 * Part of the hand-wired container (`Plugin`): `[omnabase_messages]` and its form posts on the picked page, the no-cache marking at
 * `send_headers`, and the watcher that tells OmnaBase a known user's new address. `register()` hooks them once per request, however often
 * the plugin is booted. The settings screen's section lives with the admin parts (`Admin\PortalView`, `Admin\PortalActions`).
 */
final class Portal {

	public readonly PickedPage $page;
	private readonly Shortcode $shortcode;
	private readonly Controller $controller;
	private readonly NoCache $nocache;
	private readonly ProfileWatcher $watcher;

	private static bool $registered = false;

	/**
	 * Builds the parts.
	 *
	 * @param PluginInfo   $info  The plugin's folder and version.
	 * @param Vault        $vault The vault.
	 * @param CustomerData $data  The outbox's queue.
	 */
	public function __construct( PluginInfo $info, Vault $vault, CustomerData $data ) {
		$api              = new PortalApi( new Client( $vault ) );
		$known            = new KnownUsers();
		$visitor          = new Visitor( $api, new SiteId(), $known );
		$projects         = new Projects( new ConnectionInfo(), $vault );
		$this->page       = new PickedPage();
		$this->shortcode  = new Shortcode( $info, $this->page, $projects, new Centre( $this->page, $visitor, $api ), new Views( $info ) );
		$this->controller = new Controller( $this->page, $visitor, new Requests( $api, new LinkLimit() ), $projects, new Guard() );
		$this->nocache    = new NoCache( $this->page );
		$this->watcher    = new ProfileWatcher( $data->queue, $known );
	}

	/** The shortcode, the form posts, the no-cache marking and the profile watcher: once per request. */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		$this->shortcode->register();
		$this->controller->register();
		$this->nocache->register();
		$this->watcher->register();
	}
}
