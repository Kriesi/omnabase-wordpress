<?php
/**
 * An administrator's pending connect.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Connect;

use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Security\Vault;
use OmnaBase\WordPress\UserNotes;

defined( 'ABSPATH' ) || exit;

/**
 * Between the click on Connect and the way back, each administrator keeps one pending connect per site (a user option): the state, the
 * verifier (sealed by the vault) and the time. Two administrators connecting at once keep their own; a second click by the same
 * administrator replaces the first. The way back must bring the same state (`hash_equals`) to the same administrator within 10 minutes; it
 * is used once.
 */
final class ConnectState {

	public const EXPIRES_SECONDS = 10 * MINUTE_IN_SECONDS;

	public const MISSING = 'missing';
	public const EXPIRED = 'expired';

	/**
	 * Builds the store.
	 *
	 * @param Vault $vault Seals the verifier.
	 */
	public function __construct( private Vault $vault ) {}

	/**
	 * Starts a pending connect for the user: the state and the challenge to send.
	 *
	 * @param int $user The administrator.
	 * @return array{state:string,challenge:string}
	 */
	public function begin( int $user ): array {
		$state    = Pkce::state();
		$verifier = Pkce::verifier();
		UserNotes::set(
			$user,
			Options::PENDING_CONNECT_META,
			array(
				'state'    => $state,
				'verifier' => $this->vault->seal( $verifier ),
				'created'  => time(),
			)
		);
		return array(
			'state'     => $state,
			'challenge' => Pkce::challenge( $verifier ),
		);
	}

	/**
	 * Takes the user's pending connect for a state: the verifier, or why not (`missing`: none, or another state, which leaves the pending
	 * one as it was; `expired`: too late or unreadable, now forgotten).
	 *
	 * @param int    $user  The administrator arriving.
	 * @param string $state The state the way back brought.
	 * @return array{verifier:?string,problem:?string}
	 */
	public function take( int $user, string $state ): array {
		$pending = UserNotes::get( $user, Options::PENDING_CONNECT_META );
		if ( ! is_array( $pending ) || '' === $state || ! hash_equals( (string) ( $pending['state'] ?? '' ), $state ) ) {
			return array( 'verifier' => null, 'problem' => self::MISSING );
		}
		$this->forget( $user );
		$verifier = time() - (int) ( $pending['created'] ?? 0 ) <= self::EXPIRES_SECONDS ? $this->vault->open( (string) ( $pending['verifier'] ?? '' ) ) : null;
		return null === $verifier ? array( 'verifier' => null, 'problem' => self::EXPIRED ) : array( 'verifier' => $verifier, 'problem' => null );
	}

	/**
	 * Forgets the user's pending connect.
	 *
	 * @param int $user The administrator.
	 */
	public function forget( int $user ): void {
		UserNotes::delete( $user, Options::PENDING_CONNECT_META );
	}
}
