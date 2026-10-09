<?php
/**
 * The page the administrator picked for the message centre.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Stored as `omnabase_portal` = `[ 'page' => <page id> ]`. The page, not its content, is what counts: the no-cache marking matches the
 * request for it (so a page builder that keeps the shortcode outside the content is covered), and `[omnabase_messages]` shows a customer's
 * tickets only on it (any other page was never told to the caches).
 */
final class PickedPage {

	// WordPress's own settings for a static front page (Settings › Reading), read only.
	private const SHOW_ON_FRONT = 'show_on_front';
	private const PAGE_ON_FRONT = 'page_on_front';

	/** The picked page's id, or 0. */
	public function id(): int {
		$stored = get_option( Options::PORTAL, array() );
		return is_array( $stored ) ? absint( $stored['page'] ?? 0 ) : 0;
	}

	/**
	 * Stores the pick (0: none).
	 *
	 * @param int $page_id A page's id.
	 */
	public function save( int $page_id ): void {
		update_option( Options::PORTAL, array( 'page' => max( 0, $page_id ) ), false );
	}

	/** Whether the page being shown is the picked one (after WordPress ran its query). */
	public function isCurrent(): bool {
		$id = $this->id();
		return $id > 0 && (int) get_queried_object_id() === $id;
	}

	/**
	 * Whether a request asks for the picked page, from WordPress's parsed request alone (at `send_headers` the query has not run yet).
	 *
	 * @param \WP $wp WordPress's request.
	 */
	public function isRequested( \WP $wp ): bool {
		$id   = $this->id();
		$vars = is_array( $wp->query_vars ) ? $wp->query_vars : array();
		if ( $id <= 0 ) {
			return false;
		}
		if ( ! empty( $vars['page_id'] ) || ! empty( $vars['p'] ) ) {
			return absint( ! empty( $vars['page_id'] ) ? $vars['page_id'] : $vars['p'] ) === $id;
		}
		if ( ! empty( $vars['pagename'] ) ) {
			$page = get_page_by_path( (string) $vars['pagename'] );
			return $page instanceof \WP_Post && (int) $page->ID === $id;
		}
		return array() === array_filter( $vars ) && 'page' === get_option( self::SHOW_ON_FRONT ) && (int) get_option( self::PAGE_ON_FRONT ) === $id;
	}

	/**
	 * The picked page's address with some query arguments (each encoded).
	 *
	 * @param array<string,string|int> $args The arguments.
	 */
	public function url( array $args = array() ): string {
		$base = (string) get_permalink( $this->id() );
		return array() === $args ? $base : add_query_arg( array_map( static fn ( $v ) => rawurlencode( (string) $v ), $args ), $base );
	}
}
