<?php
/**
 * What the settings screen's connection section shows.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Connect\SiteCheck;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Four states: `connected` (the vault reads), `not_connected` (empty), `reconnect` (the vault can no longer be read), `revoked` (OmnaBase
 * answered 401: someone disconnected the site there; no call is made until Connect). Connected shows the workspace, the site, the projects,
 * the permissions in words and the key by its last four; OmnaBase's live `GET /me` wins, the connection kept from the exchange stands in
 * while OmnaBase cannot be reached. The full key never leaves this class.
 */
final class ConnectionView {

	/**
	 * Builds the view.
	 *
	 * @param Vault          $vault  The key.
	 * @param Client         $client The API client.
	 * @param ConnectionInfo $info   What the connection is, kept.
	 * @param SiteCheck      $site   Whether the site can connect.
	 */
	public function __construct( private Vault $vault, private Client $client, private ConnectionInfo $info, private SiteCheck $site ) {}

	/**
	 * The section's data.
	 *
	 * @return array{state:string,workspace:string,site:string,projects:string[],scopes:string[],keyHint:string,liveError:string,problem:?string} `scopes` in words
	 */
	public function build(): array {
		$view = array(
			'state'     => 'not_connected',
			'workspace' => '',
			'site'      => '',
			'projects'  => array(),
			'scopes'    => array(),
			'keyHint'   => '',
			'liveError' => '',
			'problem'   => $this->site->problem(),
		);
		$credentials = $this->vault->read();
		if ( null === $credentials ) {
			$view['state'] = Vault::UNREADABLE === $this->vault->state() ? 'reconnect' : 'not_connected';
			return $view;
		}
		$connected = $this->vault->isRevoked() ? array() : $this->connected( $credentials['key'] );
		return $this->vault->isRevoked() ? array( 'state' => 'revoked' ) + $view : array_merge( $view, $connected );
	}

	/**
	 * The connected state's fields: live from `GET /me`, else as kept.
	 *
	 * @param string $key The stored key (only its last four are shown).
	 * @return array<string,mixed>
	 */
	private function connected( string $key ): array {
		$kept = $this->info->read();
		$live = $this->client->me();
		$from = is_wp_error( $live ) ? $kept : $live;
		$projects = ConnectionInfo::projects( is_wp_error( $live ) || array() === ( $live['projects'] ?? array() ) ? ( $kept['projects'] ?? array() ) : $live['projects'] );
		return array(
			'state'     => 'connected',
			'workspace' => (string) ( $from['workspace']['name'] ?? ( $kept['workspace']['name'] ?? '' ) ),
			'site'      => (string) ( $from['site']['name'] ?? '' ),
			'projects'  => array_map( static fn ( array $p ) => $p['name'], $projects ),
			'scopes'    => PermissionWords::of( ConnectionInfo::strings( $from['scopes'] ?? ( $kept['scopes'] ?? array() ) ) ),
			'keyHint'   => 'obw_…' . substr( $key, -4 ),
			'liveError' => is_wp_error( $live ) ? $live->get_error_message() : '',
		);
	}
}
