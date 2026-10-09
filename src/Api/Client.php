<?php
/**
 * The calls the plugin makes to OmnaBase's public API.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Round zero: `GET /me` only. Every call goes to the pinned host with the key in the Authorization header, certificates checked,
 * no redirect followed, five seconds at most.
 */
final class Client {

	private const TIMEOUT_SECONDS = 5;

	/**
	 * Builds the client.
	 *
	 * @param StoredKey $key Where the key lives.
	 */
	public function __construct( private StoredKey $key ) {}

	/**
	 * Who the key is: `{ site, workspace, scopes, projects, limits }`, or an error in words.
	 *
	 * @return array<string,mixed>|\WP_Error
	 */
	public function me() {
		$key = $this->key->get();
		if ( null === $key ) {
			return new \WP_Error( 'omnabase_not_connected', __( 'This site is not connected to OmnaBase yet.', 'omnabase' ) );
		}
		$response = wp_remote_get(
			ApiUrl::publicApi( '/me' ),
			array(
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'application/json',
				),
			)
		);
		return self::decode( $response );
	}

	/**
	 * A JSON answer as an array, or an error naming what went wrong.
	 *
	 * @param array<string,mixed>|\WP_Error $response What wp_remote_get answered.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			/* translators: %s: the network error. */
			return new \WP_Error( 'omnabase_unreachable', sprintf( __( 'OmnaBase could not be reached: %s', 'omnabase' ), $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $status || ! is_array( $body ) ) {
			$message = is_array( $body ) && isset( $body['error']['message'] ) ? (string) $body['error']['message'] : '';
			/* translators: 1: the HTTP status, 2: OmnaBase's message. */
			return new \WP_Error( 'omnabase_refused', trim( sprintf( __( 'OmnaBase answered %1$d. %2$s', 'omnabase' ), $status, $message ) ) );
		}
		return $body;
	}
}
