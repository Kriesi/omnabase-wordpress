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
 * the secrets never appear in an error, and OmnaBase's raw answer is never echoed (only its `error.message`). OmnaBase's 401 to a call with
 * the key means someone disconnected the site there: the vault is marked, and no further call with the key is made until Connect.
 */
final class Client {

	private const TIMEOUT_SECONDS          = 5;
	private const EXCHANGE_TIMEOUT_SECONDS = 10;
	// `/me/ping` answers once OmnaBase has called the site back (OmnaBase allows the site 5 seconds).
	private const PING_TIMEOUT_SECONDS = 15;
	// A visitor waits for the form's answer; a slower OmnaBase hands the message to the outbox.
	private const FORM_TIMEOUT_SECONDS = 10;

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
	 * A project's form settings for the shortcode form: `{ products, askLicense, privacyUrl }` (round four).
	 *
	 * @param string $slug The project's slug.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function formSettings( string $slug ) {
		return $this->withKey( 'GET', '/forms/' . rawurlencode( $slug ), null, self::TIMEOUT_SECONDS );
	}

	/**
	 * Sends a visitor's form message (round four), the answer kept whole: the visitor waits for it, so the timeout is short.
	 *
	 * @param string              $slug The project's slug.
	 * @param array<string,mixed> $body The fields OmnaBase takes.
	 * @param string              $key  The Idempotency-Key (from the form's time token).
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function submitForm( string $slug, array $body, string $key ): array {
		return $this->call( 'POST', self::formPath( $slug ), $body, self::FORM_TIMEOUT_SECONDS, array( 'Idempotency-Key' => $key ) );
	}

	/**
	 * Where a project's form messages go.
	 *
	 * @param string $slug The project's slug.
	 */
	public static function formPath( string $slug ): string {
		return '/forms/' . rawurlencode( $slug ) . '/submissions';
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
		if ( $this->vault->isRevoked() ) {
			return self::revokedError();
		}
		$answer = $this->send( $method, $path, $credentials['key'], $body, $timeout );
		if ( is_wp_error( $answer ) && 401 === (int) ( $answer->get_error_data()['status'] ?? 0 ) ) {
			$this->vault->markRevoked();
		}
		return $answer;
	}

	/** The answer while OmnaBase refuses the key: no call is made until Connect (a 401 in its data, as OmnaBase would answer). */
	private static function revokedError(): \WP_Error {
		return new \WP_Error( 'omnabase_revoked', __( 'Someone disconnected this site in OmnaBase. Click Connect to connect it again.', 'omnabase' ), array( 'status' => 401 ) );
	}

	/**
	 * A call with the vault's key whose answer is kept whole, for the outbox: its status (null when no answer came), its headers with
	 * lower-case names, its JSON body, and the network error in words.
	 *
	 * @param string               $method  The HTTP method.
	 * @param string               $path    The path under /api/public/v1.
	 * @param mixed|null           $body    A JSON body, or null.
	 * @param int                  $timeout Seconds for the whole call.
	 * @param array<string,string> $headers Extra headers (the outbox's `Idempotency-Key`).
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function call( string $method, string $path, $body, int $timeout, array $headers = array() ): array {
		$credentials = $this->vault->read();
		if ( null === $credentials || $this->vault->isRevoked() ) {
			$why = null === $credentials ? __( 'This site is not connected to OmnaBase.', 'omnabase' ) : self::revokedError()->get_error_message();
			return array( 'status' => null, 'headers' => array(), 'body' => null, 'error' => $why );
		}
		$args            = self::args( $method, $credentials['key'], $body, $timeout );
		$args['headers'] = $headers + $args['headers'];
		$outcome         = self::outcome( wp_remote_request( ApiUrl::publicApi( $path ), $args ) );
		if ( 401 === $outcome['status'] ) {
			$this->vault->markRevoked();
		}
		return $outcome;
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
		return self::decode( wp_remote_request( ApiUrl::publicApi( $path ), self::args( $method, $key, $body, $timeout ) ) );
	}

	/**
	 * The request's arguments: the key, JSON, the timeout, certificates checked, no redirect followed.
	 *
	 * @param string      $method  The HTTP method.
	 * @param string|null $key     The key, or null.
	 * @param mixed|null  $body    A JSON body, or null.
	 * @param int         $timeout Seconds for the whole call.
	 * @return array<string,mixed>
	 */
	private static function args( string $method, ?string $key, $body, int $timeout ): array {
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
		return $args;
	}

	/**
	 * An answer kept whole (the outbox decides what it means).
	 *
	 * @param array<string,mixed>|\WP_Error $response What wp_remote_request answered.
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	private static function outcome( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array( 'status' => null, 'headers' => array(), 'body' => null, 'error' => $response->get_error_message() );
		}
		$headers = array();
		foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return array( 'status' => (int) wp_remote_retrieve_response_code( $response ), 'headers' => $headers, 'body' => is_array( $body ) ? $body : null, 'error' => null );
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
