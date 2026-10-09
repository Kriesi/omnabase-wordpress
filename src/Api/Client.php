<?php
/**
 * The calls the plugin makes to OmnaBase's public API.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Api;

use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Every call goes to the pinned host with the vault's key in the Authorization header (the exchange alone carries none: the site has no
 * key yet), certificates checked, no redirect followed. An answer is an array, or a `WP_Error` naming what went wrong in words; the key and
 * the secrets never appear in an error, and OmnaBase's raw answer is never echoed (only its `error.message`).
 */
final class Client {

	private const TIMEOUT_SECONDS          = 5;
	private const EXCHANGE_TIMEOUT_SECONDS = 10;
	// `/me/ping` answers once OmnaBase has called the site back (OmnaBase allows the site 5 seconds).
	private const PING_TIMEOUT_SECONDS = 15;

	/**
	 * Builds the client.
	 *
	 * @param Vault $vault Where the key lives.
	 */
	public function __construct( private Vault $vault ) {}

	/**
	 * Who the key is: `{ site, workspace, scopes, projects, limits }`.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function me() {
		return $this->withKey( 'GET', '/me', null, self::TIMEOUT_SECONDS );
	}

	/**
	 * Asks OmnaBase to call the site's events receiver with a signed `ping` and answer how it went.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function ping() {
		return $this->withKey( 'POST', '/me/ping', new \stdClass(), self::PING_TIMEOUT_SECONDS );
	}

	/**
	 * Revokes the site's own key at OmnaBase (Disconnect), with the key itself.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function revoke() {
		return $this->withKey( 'DELETE', '/me', null, self::TIMEOUT_SECONDS );
	}

	/**
	 * Trades a connect's one-time code for the key and both secrets (no key: the site has none yet).
	 *
	 * @param string $code     The code from the way back.
	 * @param string $verifier The PKCE verifier the site kept.
	 * @param string $site     The site's origin, as the link named it.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function exchange( string $code, string $verifier, string $site ) {
		$body = array(
			'code'     => $code,
			'verifier' => $verifier,
			'site'     => $site,
		);
		return $this->send( 'POST', '/connect/token', null, $body, self::EXCHANGE_TIMEOUT_SECONDS );
	}

	/**
	 * A call with the vault's key; an error when there is none or it cannot be read.
	 *
	 * @param string     $method  The HTTP method.
	 * @param string     $path    The path under /api/public/v1.
	 * @param mixed|null $body    A JSON body, or null.
	 * @param int        $timeout Seconds for the whole call.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function withKey( string $method, string $path, $body, int $timeout ) {
		$credentials = $this->vault->read();
		if ( null === $credentials ) {
			return Vault::UNREADABLE === $this->vault->state()
				? new \WP_Error( 'omnabase_unreadable', __( 'The stored connection can no longer be read: connect the site again.', 'omnabase' ) )
				: new \WP_Error( 'omnabase_not_connected', __( 'This site is not connected to OmnaBase yet.', 'omnabase' ) );
		}
		return $this->send( $method, $path, $credentials['key'], $body, $timeout );
	}

	/**
	 * One call to the pinned host.
	 *
	 * @param string      $method  The HTTP method.
	 * @param string      $path    The path under /api/public/v1.
	 * @param string|null $key     The key, or null for the exchange.
	 * @param mixed|null  $body    A JSON body, or null.
	 * @param int         $timeout Seconds for the whole call.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function send( string $method, string $path, ?string $key, $body, int $timeout ) {
		$headers = array( 'Accept' => 'application/json' );
		if ( null !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}
		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}
		return self::decode( wp_remote_request( ApiUrl::publicApi( $path ), $args ) );
	}

	/**
	 * A 2xx JSON answer as an array (an empty answer as an empty array), or an error naming the status and OmnaBase's message.
	 *
	 * @param array<string,mixed>|\WP_Error $response What wp_remote_request answered.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			/* translators: %s: the network error. */
			return new \WP_Error( 'omnabase_unreachable', sprintf( __( 'OmnaBase could not be reached: %s', 'omnabase' ), $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$text   = (string) wp_remote_retrieve_body( $response );
		$body   = '' === $text ? array() : json_decode( $text, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ? $body['error']['message'] : '';
			/* translators: 1: the HTTP status, 2: OmnaBase's message. */
			return new \WP_Error( 'omnabase_refused', trim( sprintf( __( 'OmnaBase answered %1$d. %2$s', 'omnabase' ), $status, $message ) ), array( 'status' => $status ) );
		}
		return $body;
	}
}
