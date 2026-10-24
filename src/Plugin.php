<?php
/**
 * The plugin's entry: builds the parts and registers their hooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\Forms\FirstShown;
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
 * and the licence events (`CustomerData`), bbPress's moments and OmnaBase's replies posted back (`Forums`), the shortcode form, drawn by
 * OmnaBase's embed (`Forms`). In the admin: the settings screen and its actions (`AdminParts`). Everywhere too: the
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
		$forms = new Forms( $this->info, $vault );
		$forms->register();
		( new FirstShown( new Display() ) )->register();
		$portal = new Portal( $this->info, $vault, $data );
		$portal->register();
		( new Privacy( $data ) )->register();
		( new Events( $door, new ReplayGuard(), $log, $ping ) )->register();
		( new Customer( $door, $data->responder ) )->register();
		if ( is_admin() ) {
			( new AdminParts( $this->info, $forms->list ) )->register( $vault, $log, $ping, $data, $forum, $portal );
		}
	}

	/** The header fields. */
	public function info(): PluginInfo {
		return $this->info;
	}
}
