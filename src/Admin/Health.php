<?php
/**
 * "Check connection" and what the screen shows about the connection's health.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Api\Loopback;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Rest\DeliveryLog;
use OmnaBase\WordPress\Rest\LastPing;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * The check tests both directions: the site asks OmnaBase who it is (`GET /me`), and asks OmnaBase to call the site (`POST /me/ping`,
 * which answers once OmnaBase has sent a signed `ping` to the events receiver). The second counts as working only when a ping really
 * arrived after the check started; until then it is "waiting" for up to 30 seconds. A third result asks the site's own receiver
 * (a security plugin blocking the REST API shows there). The view adds where the vault's key comes from and the last deliveries.
 */
final class Health {

	public const PING_WAIT_SECONDS = 30;

	/**
	 * Builds the check.
	 *
	 * @param Client      $client   The API client.
	 * @param Loopback    $loopback The site's call to itself.
	 * @param LastPing    $ping     When the last ping arrived.
	 * @param DeliveryLog $log      The last deliveries.
	 * @param Vault       $vault    The key's source.
	 */
	public function __construct( private Client $client, private Loopback $loopback, private LastPing $ping, private DeliveryLog $log, private Vault $vault ) {}

	/** Runs the check and keeps its results. */
	public function run(): void {
		if ( $this->vault->isRevoked() ) {
			return; // disconnected in OmnaBase: no call until Connect
		}
		$at   = time();
		$me   = $this->client->me();
		$ping = $this->client->ping();
		update_option(
			Options::HEALTH,
			array(
				'at'       => $at,
				'me'       => is_wp_error( $me ) ? array( 'ok' => false, 'message' => $me->get_error_message() ) : array( 'ok' => true, 'message' => '' ),
				'ping'     => self::pingRequest( $ping ),
				'loopback' => $this->loopback->probe(),
			),
			false
		);
	}

	/** Forgets the last check (a new connection, Disconnect). */
	public function forget(): void {
		delete_option( Options::HEALTH );
	}

	/**
	 * What the screen shows.
	 *
	 * @return array{checked:bool,at:int,results:array<string,array{result:string,message:string}>,waiting:bool,keySource:string,deliveries:array<int,array<string,mixed>>}
	 */
	public function view(): array {
		$last    = get_option( Options::HEALTH, array() );
		$checked = is_array( $last ) && isset( $last['at'] );
		$results = $checked ? array(
			'me'       => array(
				'result'  => ! empty( $last['me']['ok'] ) ? 'ok' : 'failed',
				'message' => (string) ( $last['me']['message'] ?? '' ),
			),
			'ping'     => $this->pingResult( (int) $last['at'], (array) ( $last['ping'] ?? array() ) ),
			'loopback' => array(
				'result'  => (string) ( $last['loopback']['result'] ?? 'unknown' ),
				'message' => (string) ( $last['loopback']['message'] ?? '' ),
			),
		) : array();
		return array(
			'checked'    => $checked,
			'at'         => $checked ? (int) $last['at'] : 0,
			'results'    => $results,
			'waiting'    => 'waiting' === ( $results['ping']['result'] ?? '' ),
			'keySource'  => $this->vault->keySource(),
			'deliveries' => $this->log->recent(),
		);
	}

	/**
	 * The ping's request as kept: whether OmnaBase took it, and its reason when it did not or the site refused.
	 *
	 * @param array<string,mixed>|\WP_Error $answer What `POST /me/ping` answered.
	 * @return array{requested:bool,message:string}
	 */
	private static function pingRequest( $answer ): array {
		if ( is_wp_error( $answer ) ) {
			return array( 'requested' => false, 'message' => $answer->get_error_message() );
		}
		if ( false === ( $answer['ok'] ?? null ) ) {
			$status = isset( $answer['status'] ) ? (int) $answer['status'] : 0;
			/* translators: %d: the HTTP status the site answered OmnaBase's ping with. */
			$message = $status ? sprintf( __( 'OmnaBase called this site and was turned away (the site answered %d).', 'omnabase' ), $status ) : (string) ( $answer['message'] ?? __( 'OmnaBase could not reach the site.', 'omnabase' ) );
			return array( 'requested' => false, 'message' => $message );
		}
		return array( 'requested' => true, 'message' => '' );
	}

	/**
	 * OmnaBase → site: `ok` once a ping arrived after the check began, `failed` when the request failed or nothing came within 30
	 * seconds, else `waiting`.
	 *
	 * @param int                 $at      When the check began.
	 * @param array<string,mixed> $request The ping's request as kept.
	 * @return array{result:string,message:string}
	 */
	private function pingResult( int $at, array $request ): array {
		if ( $this->ping->at() >= $at ) {
			return array( 'result' => 'ok', 'message' => '' );
		}
		if ( empty( $request['requested'] ) ) {
			return array( 'result' => 'failed', 'message' => (string) ( $request['message'] ?? '' ) );
		}
		if ( time() - $at > self::PING_WAIT_SECONDS ) {
			return array( 'result' => 'failed', 'message' => __( 'No test message from OmnaBase arrived within 30 seconds: a security plugin or the server may block it.', 'omnabase' ) );
		}
		return array( 'result' => 'waiting', 'message' => __( 'Waiting for OmnaBase\'s test message…', 'omnabase' ) );
	}
}
