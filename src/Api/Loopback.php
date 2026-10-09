<?php
/**
 * The health check's one call that does not go to OmnaBase: the site asks its own events receiver.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Api;

defined( 'ABSPATH' ) || exit;

/**
 * An unsigned POST to the site's own `/wp-json/omnabase/v1/events` (the address from `rest_url()`, never from input). The receiver should
 * refuse it with its own 401: then the REST API is open to calls from outside and the receiver checks signatures. Anything else (a 403 or a
 * 404 from a security plugin, an HTML page) means OmnaBase's calls would meet the same wall. A network error says nothing either way (some
 * hosts cannot call themselves), so it reads "unknown", not "failed".
 */
final class Loopback {

	private const TIMEOUT_SECONDS = 5;

	/**
	 * `ok`, `failed` or `unknown`, with the status and a reason in words.
	 *
	 * @return array{result:string,status:?int,message:string}
	 */
	public function probe(): array {
		$response = wp_remote_post(
			rest_url( 'omnabase/v1/events' ),
			array(
				'timeout'     => self::TIMEOUT_SECONDS,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress's own filter (Site Health's loopback uses it): a site's call to itself may meet a local certificate.
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => '{"type":"loopback"}',
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'result'  => 'unknown',
				'status'  => null,
				'message' => __( 'The site could not call itself, so this check says nothing either way.', 'omnabase' ),
			);
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$code   = json_decode( (string) wp_remote_retrieve_body( $response ), true )['code'] ?? '';
		$ok     = 401 === $status && 'omnabase_signature' === $code;
		return array(
			'result'  => $ok ? 'ok' : 'failed',
			'status'  => $status,
			/* translators: %d: the HTTP status the site's own receiver answered. */
			'message' => $ok ? __( 'The receiver is reachable and refuses unsigned calls.', 'omnabase' ) : sprintf( __( 'The receiver answered %d instead of its own refusal: a security plugin or the server may block OmnaBase.', 'omnabase' ), $status ),
		);
	}
}
