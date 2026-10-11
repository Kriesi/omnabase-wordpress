<?php
/**
 * The two receivers' door: OmnaBase's signature, checked before any handler runs.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

use OmnaBase\WordPress\Security\Signature;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * The `permission_callback` of `/events` and `/customer`. A call passes only with OmnaBase's signature over the raw body, made with this
 * receiver's own secret (the webhook secret for `/events`, the facts secret for `/customer`) within five minutes. Refusals:
 * - 503 while the vault is empty or unreadable (not connected yet, or the key that locked it lost): OmnaBase keeps retrying, so a fixable gap loses
 *   nothing. Never 410, which would switch the address off for good.
 * - 401 for a missing, stale or wrong signature.
 */
final class SignedCall {

	/**
	 * Builds the check.
	 *
	 * @param Vault $vault Where the secrets live.
	 */
	public function __construct( private Vault $vault ) {}

	/**
	 * True when the call is OmnaBase's, else the refusal.
	 *
	 * @param \WP_REST_Request $request The call.
	 * @param string           $secret  `webhooks` or `facts`: which of the vault's secrets signs this receiver's calls.
	 * @return true|\WP_Error
	 */
	public function check( \WP_REST_Request $request, string $secret ) {
		$credentials = $this->vault->read();
		if ( null === $credentials ) {
			return new \WP_Error( 'omnabase_unavailable', __( 'This site cannot check OmnaBase\'s signature right now; try again later.', 'omnabase' ), array( 'status' => 503 ) );
		}
		$signed = Signature::verify(
			(string) ( $credentials[ $secret ] ?? '' ),
			(string) $request->get_header( 'x-omnabase-timestamp' ),
			(string) $request->get_body(),
			(string) $request->get_header( 'x-omnabase-signature' )
		);
		return $signed ? true : new \WP_Error( 'omnabase_signature', __( 'This call does not carry a valid OmnaBase signature.', 'omnabase' ), array( 'status' => 401 ) );
	}
}
