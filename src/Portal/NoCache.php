<?php
/**
 * Keeps the message centre's page out of every cache.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

defined( 'ABSPATH' ) || exit;

/**
 * At `send_headers` (the moment just before WordPress sends a page's headers, before any output) a request for the picked page gets
 * WordPress's no-cache headers, `Cache-Control: private, no-store`, `DONOTCACHEPAGE` (read by WP Super Cache, W3 Total Cache, WP Rocket and
 * others) and LiteSpeed's no-cache action, for visitors and customers alike: a cached logged-out copy would be served to customers too. The
 * page is matched, not its content, so a page builder keeping the shortcode elsewhere is covered.
 */
final class NoCache {

	private const CACHE_CONTROL = 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0';

	/**
	 * Builds the marker.
	 *
	 * @param PickedPage $page The picked page.
	 */
	public function __construct( private PickedPage $page ) {}

	/** Registers the hook. */
	public function register(): void {
		add_action( 'send_headers', array( $this, 'mark' ) );
	}

	/**
	 * Marks the picked page's request.
	 *
	 * @param \WP $wp WordPress's request.
	 */
	public function mark( $wp ): void {
		if ( ! $wp instanceof \WP || ! $this->page->isRequested( $wp ) ) {
			return;
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( self::CACHE_CONTROL );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page caches' shared constant.
		}
		do_action( 'litespeed_control_set_nocache', 'OmnaBase message centre' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own action.
	}
}
