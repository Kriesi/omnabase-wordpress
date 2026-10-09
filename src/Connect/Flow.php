<?php
/**
 * Connect: the link to OmnaBase's connect page, and the way back.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Connect;

use OmnaBase\WordPress\Admin\Health;
use OmnaBase\WordPress\Admin\SettingsScreen;
use OmnaBase\WordPress\Api\ApiUrl;
use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * The start builds the link to `<app>/connect#…` with exactly the fields OmnaBase's page reads (the site's origin, its title, the clicking
 * administrator's address, the six permissions, the two events, the way back, the state, the challenge); everything is after `#`, so it
 * never reaches a server or a log. The way back (the settings page with `code` and `state`, or `error` and `state`) checks the state for
 * this administrator, trades the code once with the verifier, stores the key and secrets in the vault and what the connection is beside it.
 * Each step answers what to tell the administrator: `[ type, message ]`.
 */
final class Flow {

	public const SCOPES = array( 'tickets:read', 'tickets:write', 'customers:read', 'customers:write', 'forms:write', 'portal' );
	public const EVENTS = array( 'message.sent', 'ticket.updated' );

	/**
	 * Builds the flow.
	 *
	 * @param Client         $client The API client.
	 * @param Vault          $vault  Where the key and secrets go.
	 * @param ConnectState   $state  The administrators' pending connects.
	 * @param ConnectionInfo $info   What the connection is.
	 * @param SiteCheck      $site   Whether the site can be called back.
	 * @param Health         $health The last check (forgotten on a new connection).
	 */
	public function __construct( private Client $client, private Vault $vault, private ConnectState $state, private ConnectionInfo $info, private SiteCheck $site, private Health $health ) {}

	/**
	 * The link for this administrator, or why the site cannot connect.
	 *
	 * @param \WP_User $user The administrator who clicked Connect.
	 * @return array{link:?string,problem:?string}
	 */
	public function start( \WP_User $user ): array {
		$problem = $this->site->problem();
		if ( null !== $problem ) {
			return array( 'link' => null, 'problem' => $problem );
		}
		$pending = $this->state->begin( (int) $user->ID );
		$fields  = array(
			'site'      => $this->site->origin(),
			'name'      => $this->siteName(),
			'email'     => (string) $user->user_email,
			'scopes'    => implode( ',', self::SCOPES ),
			'events'    => implode( ',', self::EVENTS ),
			'return'    => SettingsScreen::url(),
			'state'     => $pending['state'],
			'challenge' => $pending['challenge'],
		);
		return array( 'link' => ApiUrl::app() . '/connect#' . http_build_query( $fields, '', '&', PHP_QUERY_RFC3986 ), 'problem' => null );
	}

	/**
	 * The way back: what to tell the administrator.
	 *
	 * @param int         $user  The administrator arriving.
	 * @param string      $state The state the way back brought.
	 * @param string|null $code  The one-time code (Approve), or null.
	 * @param string|null $error The error (Cancel: `access_denied`), or null.
	 * @return array{0:string,1:string}
	 */
	public function arrive( int $user, string $state, ?string $code, ?string $error ): array {
		$taken = $this->state->take( $user, $state );
		if ( ConnectState::MISSING === $taken['problem'] ) {
			return array( 'error', __( 'This way back from OmnaBase was not started by your Connect, or was used already. Nothing was stored: click Connect to try again.', 'omnabase' ) );
		}
		if ( ConnectState::EXPIRED === $taken['problem'] ) {
			return array( 'error', __( 'This connect expired after 10 minutes. Nothing was stored: click Connect to start again.', 'omnabase' ) );
		}
		if ( null !== $error || null === $code || '' === $code ) {
			return array( 'info', __( 'You cancelled the connection on OmnaBase. Nothing was stored.', 'omnabase' ) );
		}
		return $this->exchange( $code, (string) $taken['verifier'] );
	}

	/**
	 * Trades the code and keeps what comes back.
	 *
	 * @param string $code     The one-time code.
	 * @param string $verifier The verifier kept for it.
	 * @return array{0:string,1:string}
	 */
	private function exchange( string $code, string $verifier ): array {
		$answer = $this->client->exchange( $code, $verifier, $this->site->origin() );
		if ( is_wp_error( $answer ) ) {
			/* translators: %s: why OmnaBase refused or could not be reached. */
			return array( 'error', sprintf( __( 'The connection could not be completed. %s Click Connect to try again.', 'omnabase' ), $answer->get_error_message() ) );
		}
		$key = is_string( $answer['key'] ?? null ) ? $answer['key'] : '';
		if ( ! str_starts_with( $key, 'obw_' ) ) {
			return array( 'error', __( 'The connection could not be completed: OmnaBase\'s answer held no key. Click Connect to try again.', 'omnabase' ) );
		}
		$secrets = is_array( $answer['secrets'] ?? null ) ? $answer['secrets'] : array();
		$this->vault->save(
			array(
				'key'      => $key,
				'webhooks' => is_string( $secrets['webhooks'] ?? null ) ? $secrets['webhooks'] : null,
				'facts'    => is_string( $secrets['facts'] ?? null ) ? $secrets['facts'] : null,
			)
		);
		$me = $this->client->me();
		$this->info->save( $answer, is_array( $me ) && is_array( $me['projects'] ?? null ) && array() !== $me['projects'] ? $me['projects'] : null );
		$this->health->forget();
		/** A new connection: OmnaBase on the other side may be another workspace, so what the site knew about the old one starts afresh. */
		do_action( 'omnabase_connected' );
		return array( 'success', __( 'Done: this site is now connected to OmnaBase.', 'omnabase' ) );
	}

	/** The site's title as plain text (the host when it has none). */
	private function siteName(): string {
		$name = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		return '' !== $name ? $name : (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}
}
