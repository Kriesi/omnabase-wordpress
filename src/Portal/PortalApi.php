<?php
/**
 * The message centre's calls to OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * `PUT /customers/by-external/<site id>` tells OmnaBase who the signed-in user is; every portal call then names them with the header
 * `OmnaBase-Customer: <site id>`. The site id is handed in by the caller, which takes it from the current WordPress user: this class reads
 * nothing from the request (the structure guard checks it). Each answer is kept whole (status, headers, JSON body, network error), as the
 * outbox reads them; a visitor waits for it, so the timeout is short.
 */
final class PortalApi {

	public const HEADER = 'OmnaBase-Customer';

	private const TIMEOUT_SECONDS = 10;
	private const PAGE_SIZE       = 20;

	/**
	 * Builds the calls.
	 *
	 * @param Client $client The API client.
	 */
	public function __construct( private Client $client ) {}

	/**
	 * Tells OmnaBase the user's current address and name.
	 *
	 * @param string $site_id The user's site id.
	 * @param string $email   Their address.
	 * @param string $name    Their name ('' leaves it out).
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function sync( string $site_id, string $email, string $name ): array {
		$body = '' === $name ? array( 'email' => $email ) : array( 'email' => $email, 'name' => $name );
		return $this->client->call( 'PUT', '/customers/by-external/' . rawurlencode( $site_id ), $body, self::TIMEOUT_SECONDS );
	}

	/**
	 * A page of the visitor's tickets, newest change first.
	 *
	 * @param string $site_id The visitor.
	 * @param string $after   OmnaBase's cursor for the next page ('' for the first).
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function tickets( string $site_id, string $after ): array {
		$query = '?limit=' . self::PAGE_SIZE . ( '' === $after ? '' : '&after=' . rawurlencode( $after ) );
		return $this->portal( 'GET', '/portal/tickets' . $query, $site_id, null );
	}

	/**
	 * One of the visitor's tickets with the messages they may see.
	 *
	 * @param string $site_id The visitor.
	 * @param int    $number  The ticket's number.
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function ticket( string $site_id, int $number ): array {
		return $this->portal( 'GET', '/portal/tickets/' . $number, $site_id, null );
	}

	/**
	 * The visitor's new ticket.
	 *
	 * @param string              $site_id The visitor.
	 * @param array<string,string> $body    `subject`, `message` and perhaps `project`.
	 * @param string              $key     The Idempotency-Key.
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function create( string $site_id, array $body, string $key ): array {
		return $this->portal( 'POST', '/portal/tickets', $site_id, $body, array( 'Idempotency-Key' => $key ) );
	}

	/**
	 * The visitor's answer on one of their tickets.
	 *
	 * @param string $site_id The visitor.
	 * @param int    $number  The ticket's number.
	 * @param string $body    The answer.
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function reply( string $site_id, int $number, string $body ): array {
		return $this->portal( 'POST', '/portal/tickets/' . $number . '/messages', $site_id, array( 'body' => $body ) );
	}

	/**
	 * Asks OmnaBase to mail the visitor the link that confirms their address.
	 *
	 * @param string $site_id The visitor.
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	public function confirm( string $site_id ): array {
		return $this->portal( 'POST', '/portal/confirm', $site_id, new \stdClass() );
	}

	/**
	 * A portal call naming the visitor.
	 *
	 * @param string               $method  The method.
	 * @param string               $path    The path under /api/public/v1.
	 * @param string               $site_id The visitor.
	 * @param mixed                $body    A JSON body, or null.
	 * @param array<string,string> $headers More headers.
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	private function portal( string $method, string $path, string $site_id, $body, array $headers = array() ): array {
		return $this->client->call( $method, $path, $body, self::TIMEOUT_SECONDS, array( self::HEADER => $site_id ) + $headers );
	}
}
