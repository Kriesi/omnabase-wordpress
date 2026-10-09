<?php
/**
 * The settings screen's message-centre section: the page pick and the shortcodes.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Portal\PickedPage;

defined( 'ABSPATH' ) || exit;

/**
 * The site's published pages (at most 200, by title, the current pick always among them) for the message centre's page; the template adds
 * the shortcodes' help.
 */
final class PortalView {

	private const MAX_PAGES = 200;

	/**
	 * Builds the section.
	 *
	 * @param PickedPage $page The picked page.
	 */
	public function __construct( private PickedPage $page ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{picked:int,pages:array<int,array{id:int,title:string}>}
	 */
	public function build(): array {
		$picked = $this->page->id();
		$pages  = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title', 'number' => self::MAX_PAGES ) );
		$ids    = array_map( static fn ( \WP_Post $p ) => (int) $p->ID, is_array( $pages ) ? $pages : array() );
		if ( $picked > 0 && ! in_array( $picked, $ids, true ) && get_post( $picked ) ) {
			$ids[] = $picked;
		}
		return array(
			'picked' => $picked,
			'pages'  => array_map( static fn ( int $id ) => array( 'id' => $id, 'title' => '' !== get_the_title( $id ) ? get_the_title( $id ) : '#' . $id ), $ids ),
		);
	}
}
