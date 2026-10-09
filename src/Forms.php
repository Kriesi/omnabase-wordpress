<?php
/**
 * The shortcode form's parts (round four), wired once.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forms\Display;
use OmnaBase\WordPress\Forms\FormJob;
use OmnaBase\WordPress\Forms\Guard;
use OmnaBase\WordPress\Forms\Intake;
use OmnaBase\WordPress\Forms\Projects;
use OmnaBase\WordPress\Forms\Sender;
use OmnaBase\WordPress\Forms\Settings;
use OmnaBase\WordPress\Forms\Shortcode;
use OmnaBase\WordPress\Forms\Token;
use OmnaBase\WordPress\Rest\Form;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * `[omnabase_form]` and its two routes (the time token, the form), on every request: the shortcode renders on the front end, the routes
 * answer visitors' browsers. A message OmnaBase cannot take at once waits in the shared outbox (its `form_submission` rows).
 */
final class Forms {

	private readonly Shortcode $shortcode;
	private readonly Form $routes;

	/**
	 * Builds the parts.
	 *
	 * @param PluginInfo   $info  The plugin's folder and version.
	 * @param Vault        $vault The vault.
	 * @param CustomerData $data  The outbox's table and worker.
	 */
	public function __construct( PluginInfo $info, Vault $vault, CustomerData $data ) {
		$client          = new Client( $vault );
		$projects        = new Projects( new ConnectionInfo(), $vault );
		$settings        = new Settings( $client );
		$guard           = new Guard();
		$token           = new Token();
		$intake          = new Intake( $projects, $guard, $token, new Sender( $client, $data->table, $settings ) );
		$this->shortcode = new Shortcode( $info, $projects, $settings, new Display() );
		$this->routes    = new Form( $projects, $guard, $token, $intake );
		$data->worker->add( new FormJob() );
	}

	/** Registers the shortcode and the routes. */
	public function register(): void {
		$this->shortcode->register();
		$this->routes->register();
	}
}
