<?php
/**
 * The shortcode form's parts, wired once.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\Forms\FormList;
use OmnaBase\WordPress\Forms\FormPick;
use OmnaBase\WordPress\Forms\PrefillToken;
use OmnaBase\WordPress\Forms\Projects;
use OmnaBase\WordPress\Forms\Shortcode;
use OmnaBase\WordPress\Look\Appearance;
use OmnaBase\WordPress\Look\FrontStyles;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * `[omnabase_form]` on every request: the shortcode renders a form built in OmnaBase through OmnaBase's embed (the plugin's forms round;
 * the Classic form, its two routes and its outbox rows are gone). The workspace's list of forms is shared with the settings screen.
 */
final class Forms {

	public readonly FormList $list;
	private readonly Shortcode $shortcode;

	/**
	 * Builds the parts.
	 *
	 * @param PluginInfo $info  The plugin's folder and version.
	 * @param Vault      $vault The vault.
	 */
	public function __construct( PluginInfo $info, Vault $vault ) {
		$client          = new Client( $vault );
		$connection      = new ConnectionInfo();
		$this->list      = new FormList( $client );
		$styles          = new FrontStyles( $info, new Display(), new Appearance() );
		$this->shortcode = new Shortcode( $info, new Projects( $connection, $vault ), new FormPick( $this->list ), new PrefillToken( $client ), $styles, $connection );
	}

	/** Registers the shortcode and the list's forgetting on a new connection. */
	public function register(): void {
		$this->shortcode->register();
		$this->list->register();
	}
}
