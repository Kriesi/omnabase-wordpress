<?php
/**
 * Whether OmnaBase could ever call this site back, and the site's address written one way.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Connect;

use OmnaBase\WordPress\Admin\SettingsScreen;

defined( 'ABSPATH' ) || exit;

/**
 * The site's address is its home address with its folder (`https://example.com/blog`), written one way, the way OmnaBase writes it: the
 * scheme and the host in lower case, the standard port dropped, doubled slashes collapsed, no trailing slash, the folder's own letters kept.
 * OmnaBase calls the site only at `<address>/wp-json/omnabase/v1/…` over https on the standard port, and sends the administrator back only to
 * a page under the address (the way back: the settings page), so Connect is refused, with the reason, when the site is not on https on port
 * 443, uses plain permalinks (no `/wp-json/` addresses), answers programs somewhere other than `<address>/wp-json/`, or keeps its admin
 * outside the address.
 */
final class SiteCheck {

	/** Why this site cannot connect, in words, or null when it can. */
	public function problem(): ?string {
		$home = wp_parse_url( home_url() );
		if ( 'https' !== strtolower( (string) ( $home['scheme'] ?? '' ) ) || ( isset( $home['port'] ) && 443 !== (int) $home['port'] ) ) {
			return __( 'Connect needs the site on https: OmnaBase calls the site back only over https on the standard port.', 'omnabase' );
		}
		if ( ! $this->prettyPermalinks() ) {
			return __( 'Connect needs page addresses other than "Plain": pick any other choice under Settings › Permalinks, so OmnaBase can reach this site.', 'omnabase' );
		}
		$base = $this->base();
		if ( self::normalise( rest_url() ) !== $base . '/wp-json' ) {
			return __( 'OmnaBase cannot reach this site at its address: another plugin or a setting moved the addresses where WordPress answers other programs.', 'omnabase' );
		}
		if ( ! str_starts_with( self::normalise( SettingsScreen::url() ), $base . '/' ) ) {
			return __( 'Connect needs the WordPress admin under this site\'s address: after you approve, OmnaBase sends you back only to pages under that address.', 'omnabase' );
		}
		return null;
	}

	/** The site's address as the link and the exchange name it: the home address written one way (`https://example.com/blog`). */
	public function base(): string {
		return self::normalise( home_url() );
	}

	/** Whether the site uses pretty permalinks (anything but "Plain"), so `/wp-json/` addresses exist. */
	private function prettyPermalinks(): bool {
		global $wp_rewrite;
		return $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks();
	}

	/**
	 * An address written one way: the scheme and the host in lower case, port 443 dropped (another port kept), doubled slashes collapsed, no
	 * trailing slash, no query or fragment; the path's letters and encoding as written.
	 *
	 * @param string $url An address.
	 */
	private static function normalise( string $url ): string {
		$parts = wp_parse_url( trim( $url ) );
		$parts = is_array( $parts ) ? $parts : array();
		$port  = isset( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '';
		$path  = rtrim( (string) preg_replace( '#/+#', '/', (string) ( $parts['path'] ?? '' ) ), '/' );
		return strtolower( ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) ) . $port . $path;
	}
}
