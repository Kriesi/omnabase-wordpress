<?php
/**
 * The privacy parts (round six), wired once.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Privacy\Eraser;
use OmnaBase\WordPress\Privacy\Exporter;
use OmnaBase\WordPress\Privacy\Holdings;
use OmnaBase\WordPress\Privacy\PolicyText;

defined( 'ABSPATH' ) || exit;

/**
 * Part of the hand-wired container (`Plugin`): the suggested privacy-policy text, and the plugin's exporter and eraser for WordPress's
 * personal-data tools (Tools › Export Personal Data, Tools › Erase Personal Data). `register()` hooks them once per request.
 */
final class Privacy {

	private static bool $registered = false;

	/**
	 * Builds the parts.
	 *
	 * @param CustomerData $data The outbox's table.
	 */
	public function __construct( private CustomerData $data ) {}

	/** The text at `admin_init`, the exporter and the eraser: once per request. */
	public function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		$holdings         = new Holdings( $this->data->table );
		( new PolicyText() )->register();
		add_filter( 'wp_privacy_personal_data_exporters', array( new Exporter( $holdings ), 'add' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( new Eraser( $holdings, $this->data->table ), 'add' ) );
	}
}
