<?php
/**
 * Whether OmnaBase could ever call this site back.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Connect;

defined( 'ABSPATH' ) || exit;

/**
 * OmnaBase calls a site only at `https://<host>/wp-json/omnabase/v1/…` on the standard port, so Connect is refused, with the reason, when
 * the site is not on https, uses plain permalinks (no `/wp-json/` addresses), or WordPress answers in a subfolder.
 */
final class SiteCheck {

	/** Why this site cannot connect, in words, or null when it can. */
	public function problem(): ?string {
		$home = wp_parse_url( home_url() );
		if ( 'https' !== ( $home['scheme'] ?? '' ) || ( isset( $home['port'] ) && 443 !== (int) $home['port'] ) ) {
			return __( 'Connect needs the site on https: OmnaBase calls the site back only over https on the standard port.', 'omnabase' );
		}
		if ( ! $this->prettyPermalinks() ) {
			return __( 'Connect needs page addresses other than "Plain": pick any other choice under Settings › Permalinks, so OmnaBase can reach this site.', 'omnabase' );
		}
		if ( '' !== trim( (string) ( $home['path'] ?? '' ), '/' ) || strtolower( rest_url() ) !== $this->origin() . '/wp-json/' ) {
			return __( 'Connect needs WordPress at the root of its domain: OmnaBase cannot call a site installed in a subfolder.', 'omnabase' );
		}
		return null;
	}

	/** Whether the site uses pretty permalinks (anything but "Plain"), so `/wp-json/` addresses exist. */
	private function prettyPermalinks(): bool {
		global $wp_rewrite;
		return $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->using_permalinks();
	}

	/** The site's origin as the link names it: `https://<host>`, lower-case, no path. */
	public function origin(): string {
		$home = wp_parse_url( home_url() );
		return strtolower( ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) );
	}
}
