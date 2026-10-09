<?php
/**
 * The form's protections besides the token: the honeypot and the limits.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * - The honeypot (`omnabase_hp`): a field people never see and bots fill; filled, the message goes nowhere and the bot reads "sent".
 * - 5 messages an hour per visitor address and 25 an hour for the whole site (under OmnaBase's 30 per key and project), so OmnaBase never
 *   has to refuse the site's key; a refused message counts towards neither.
 * - 30 time tokens per 10 minutes per visitor address, so nobody fills the site's database with tokens.
 * The visitor's address is the connection's own (`REMOTE_ADDR`): a forwarded address is written by anyone, so it counts only when the site
 * owner hands it over through the filter `omnabase_visitor_address` (for a proxy such as Cloudflare). The counts are kept as transients
 * under a hash of the address, never the address itself.
 */
final class Guard {

	public const HONEYPOT = 'omnabase_hp';

	private const PER_ADDRESS        = 5;
	private const PER_SITE           = 25;
	private const WINDOW             = HOUR_IN_SECONDS;
	private const TOKENS_PER_ADDRESS = 30;
	private const TOKEN_WINDOW       = 10 * MINUTE_IN_SECONDS;

	/**
	 * Whether the trap field was filled.
	 *
	 * @param array<string,mixed> $fields What the form sent.
	 */
	public function trapped( array $fields ): bool {
		$trap = $fields[ self::HONEYPOT ] ?? '';
		return ! is_string( $trap ) || '' !== trim( $trap );
	}

	/** Whether this address may have another token now (and counts it). */
	public function allowToken(): bool {
		$name = 'ta_' . $this->addressHash();
		$hits = $this->recent( $name, self::TOKEN_WINDOW );
		if ( count( $hits ) >= self::TOKENS_PER_ADDRESS ) {
			return false;
		}
		$this->count( $name, $hits, self::TOKEN_WINDOW );
		return true;
	}

	/** Whether this address and the site may send another message this hour (and counts it for both). */
	public function allowSubmission(): bool {
		$address = 'a_' . $this->addressHash();
		$mine    = $this->recent( $address, self::WINDOW );
		$site    = $this->recent( 'site', self::WINDOW );
		if ( count( $mine ) >= self::PER_ADDRESS || count( $site ) >= self::PER_SITE ) {
			return false;
		}
		$this->count( $address, $mine, self::WINDOW );
		$this->count( 'site', $site, self::WINDOW );
		return true;
	}

	/** The visitor's address: the connection's, or what the site owner's filter hands over (a valid address, else the connection's). */
	public function address(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$remote = false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
		/**
		 * The address the form's limits count for a visitor. Hand over a forwarded address only when the site sits behind a proxy you trust
		 * (Cloudflare's `CF-Connecting-IP`, a load balancer's `X-Forwarded-For`): anyone can write those headers otherwise.
		 *
		 * @param string $remote The connection's address (`REMOTE_ADDR`).
		 */
		$filtered = apply_filters( 'omnabase_visitor_address', $remote );
		return is_string( $filtered ) && false !== filter_var( trim( $filtered ), FILTER_VALIDATE_IP ) ? trim( $filtered ) : $remote;
	}

	/** The address as a transient name's part. */
	private function addressHash(): string {
		return substr( hash( 'sha256', wp_salt( 'nonce' ) . '|' . $this->address() ), 0, 32 );
	}

	/**
	 * The counted times within the window.
	 *
	 * @param string $name   The counter (after the prefix).
	 * @param int    $window Seconds.
	 * @return int[]
	 */
	private function recent( string $name, int $window ): array {
		$hits  = get_transient( Options::FORM_TRANSIENT . $name );
		$since = time() - $window;
		return array_values( array_filter( is_array( $hits ) ? array_map( 'intval', $hits ) : array(), static fn ( int $t ) => $t > $since ) );
	}

	/**
	 * Counts one more now.
	 *
	 * @param string $name   The counter (after the prefix).
	 * @param int[]  $hits   The counted times within the window.
	 * @param int    $window Seconds.
	 */
	private function count( string $name, array $hits, int $window ): void {
		$hits[] = time();
		set_transient( Options::FORM_TRANSIENT . $name, $hits, $window );
	}
}
