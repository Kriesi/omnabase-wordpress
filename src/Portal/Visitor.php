<?php
/**
 * Who the signed-in user is to OmnaBase, made sure of before the message centre reads anything.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Sync\KnownUsers;
use OmnaBase\WordPress\Sync\SiteId;

defined( 'ABSPATH' ) || exit;

/**
 * The identity is always the current WordPress user's site id (`<user id>-<8 characters>`, round two), never anything from the request.
 * Each view first tells OmnaBase the user's current address and name (`PUT /customers/by-external/<site id>`) unless a stored fingerprint
 * says OmnaBase already holds exactly these and called the address confirmed: while OmnaBase says unconfirmed every view asks again (it
 * never tells the site when the link is opened). OmnaBase's two refusals come back as `address_taken` and `forgotten`, anything else that
 * went wrong as `unavailable`. A user OmnaBase accepted counts as known (their later address changes go through the outbox).
 */
final class Visitor {

	public const ADDRESS_TAKEN = 'address_taken';
	public const FORGOTTEN     = 'forgotten';
	public const UNAVAILABLE   = 'unavailable';

	/**
	 * Builds the visitor's check.
	 *
	 * @param PortalApi  $api      The calls.
	 * @param SiteId     $site_ids The site ids.
	 * @param KnownUsers $known    Who OmnaBase knows.
	 */
	public function __construct( private PortalApi $api, private SiteId $site_ids, private KnownUsers $known ) {}

	/**
	 * Makes sure OmnaBase knows the user as they are now.
	 *
	 * @param \WP_User $user The signed-in user.
	 * @return array{siteId:string,confirmed:bool,problem:string}
	 */
	public function ensure( \WP_User $user ): array {
		$user_id = (int) $user->ID;
		$site_id = $this->site_ids->forUser( $user_id );
		$print   = self::fingerprint( $user );
		if ( '' === $site_id ) {
			return self::state( '', false, self::UNAVAILABLE );
		}
		if ( $this->known->isKnown( $user_id ) && hash_equals( (string) get_user_meta( $user_id, Options::PORTAL_FINGERPRINT_META, true ), $print ) ) {
			return self::state( $site_id, true, '' );
		}
		$answer = $this->api->sync( $site_id, trim( (string) $user->user_email ), trim( (string) $user->display_name ) );
		return $this->read( $user_id, $site_id, $print, $answer );
	}

	/**
	 * Forgets the fingerprint, so the next view tells OmnaBase again (OmnaBase no longer knew the visitor).
	 *
	 * @param int $user_id The user.
	 */
	public function forget( int $user_id ): void {
		delete_user_meta( $user_id, Options::PORTAL_FINGERPRINT_META );
	}

	/**
	 * What OmnaBase's answer to the `PUT` means.
	 *
	 * @param int                                                                       $user_id The user.
	 * @param string                                                                    $site_id Their site id.
	 * @param string                                                                    $print   Their fingerprint now.
	 * @param array{status:?int,headers:array<string,string>,body:?array,error:?string} $answer  OmnaBase's answer.
	 * @return array{siteId:string,confirmed:bool,problem:string}
	 */
	private function read( int $user_id, string $site_id, string $print, array $answer ): array {
		$status = (int) $answer['status'];
		if ( 200 === $status || 201 === $status ) {
			$this->known->mark( $user_id );
			$confirmed = true === ( $answer['body']['confirmed'] ?? null );
			$confirmed ? update_user_meta( $user_id, Options::PORTAL_FINGERPRINT_META, $print ) : $this->forget( $user_id );
			return self::state( $site_id, $confirmed, '' );
		}
		$reason = 409 === $status ? (string) ( $answer['body']['error']['details']['reason'] ?? '' ) : '';
		$this->forget( $user_id );
		return self::state( $site_id, false, in_array( $reason, array( self::ADDRESS_TAKEN, self::FORGOTTEN ), true ) ? $reason : self::UNAVAILABLE );
	}

	/**
	 * The fingerprint of what OmnaBase is told: the address (as OmnaBase compares it) and the name.
	 *
	 * @param \WP_User $user The user.
	 */
	private static function fingerprint( \WP_User $user ): string {
		return hash( 'sha256', strtolower( trim( (string) $user->user_email ) ) . '|' . trim( (string) $user->display_name ) );
	}

	/**
	 * A result.
	 *
	 * @param string $site_id   The site id.
	 * @param bool   $confirmed Whether OmnaBase calls the address confirmed.
	 * @param string $problem   '' or one of the constants.
	 * @return array{siteId:string,confirmed:bool,problem:string}
	 */
	private static function state( string $site_id, bool $confirmed, string $problem ): array {
		return array( 'siteId' => $site_id, 'confirmed' => $confirmed, 'problem' => $problem );
	}
}
