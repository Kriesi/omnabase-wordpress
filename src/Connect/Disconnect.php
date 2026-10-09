<?php
/**
 * Disconnect: revoke the site's key at OmnaBase, then forget everything.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Connect;

use OmnaBase\WordPress\Admin\Health;
use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Rest\LastPing;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * `DELETE /me` with the stored key (OmnaBase stops the key and the site's own addresses), then the vault, the kept connection, the last
 * check and the last ping are forgotten, also when OmnaBase could not be told: then the administrator is told to revoke the key under
 * Settings › Integrations › API keys in OmnaBase. A 401 means the key was stopped already.
 */
final class Disconnect {

	/**
	 * Builds the step.
	 *
	 * @param Client         $client The API client.
	 * @param Vault          $vault  The key and secrets.
	 * @param ConnectionInfo $info   What the connection was.
	 * @param Health         $health The last check.
	 * @param LastPing       $ping   The last ping.
	 */
	public function __construct( private Client $client, private Vault $vault, private ConnectionInfo $info, private Health $health, private LastPing $ping ) {}

	/**
	 * Disconnects; what to tell the administrator.
	 *
	 * @return array{0:string,1:string}
	 */
	public function run(): array {
		$revoked = Vault::OK === $this->vault->state() ? $this->client->revoke() : new \WP_Error( 'omnabase_unreadable', '' );
		$this->vault->clear();
		$this->info->clear();
		$this->health->forget();
		$this->ping->clear();
		$stopped = ! is_wp_error( $revoked ) || 401 === (int) ( $revoked->get_error_data()['status'] ?? 0 );
		return $stopped
			? array( 'success', __( 'Disconnected: OmnaBase stopped this site\'s key.', 'omnabase' ) )
			: array( 'warning', __( 'Disconnected here, but OmnaBase could not be told. In OmnaBase, revoke this site\'s key under Settings › Integrations › API keys.', 'omnabase' ) );
	}
}
