<?php
/**
 * The delivery ids already handled.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Rest;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * OmnaBase sends a delivery again after a timeout and on "Redeliver" from its log, always with the same `X-OmnaBase-Delivery`: an id
 * recorded here was handled and is answered 200 without acting again. An id is recorded only **after** its handling succeeded, so a
 * handling that broke half-way is redone on the retry. Ids are kept 30 days (OmnaBase's log and redelivery window), at most 5 000.
 */
final class ReplayGuard {

	private const KEEP_SECONDS = 30 * DAY_IN_SECONDS;
	private const MAX_IDS      = 5000;

	/**
	 * Whether this delivery was handled already.
	 *
	 * @param string $delivery The delivery id.
	 */
	public function handled( string $delivery ): bool {
		$seen = $this->all();
		return isset( $seen[ $delivery ] ) && $seen[ $delivery ] > time() - self::KEEP_SECONDS;
	}

	/**
	 * Records a delivery as handled.
	 *
	 * @param string $delivery The delivery id.
	 */
	public function remember( string $delivery ): void {
		$cutoff = time() - self::KEEP_SECONDS;
		$seen   = array_filter( $this->all(), static fn ( $at ) => $at > $cutoff );
		$seen[ $delivery ] = time();
		if ( count( $seen ) > self::MAX_IDS ) {
			arsort( $seen );
			$seen = array_slice( $seen, 0, self::MAX_IDS, true );
		}
		update_option( Options::HANDLED, $seen, false );
	}

	/**
	 * The handled ids with their times.
	 *
	 * @return array<string,int>
	 */
	private function all(): array {
		$seen = get_option( Options::HANDLED, array() );
		return is_array( $seen ) ? array_map( 'intval', $seen ) : array();
	}
}
