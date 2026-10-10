<?php
/**
 * Published pages that already carry one of the plugin's tags.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Before the builder makes a page, it offers one that already shows the tag: published (a draft is never offered), carrying the shortcode in
 * its content, and one the current person may edit (so the offer never shows an edit address they cannot open). A preferred page (the picked
 * message centre page) comes first when it qualifies.
 */
final class TaggedPages {

	private const LOOK_AT = 20;

	/**
	 * The first page that qualifies, or null.
	 *
	 * @param string $shortcode The shortcode's name (`omnabase_form`, `omnabase_messages`).
	 * @param int    $preferred A page to offer first when it qualifies (0: none).
	 * @return array{id:int,title:string,url:string}|null
	 */
	public function first( string $shortcode, int $preferred = 0 ): ?array {
		$ids = $preferred > 0 ? array( $preferred ) : array();
		$ids = array_merge( $ids, self::candidates( $shortcode ) );
		foreach ( array_unique( $ids ) as $id ) {
			if ( self::qualifies( (int) $id, $shortcode ) ) {
				return array( 'id' => (int) $id, 'title' => self::title( (int) $id ), 'url' => (string) get_edit_post_link( (int) $id, 'raw' ) );
			}
		}
		return null;
	}

	/**
	 * The newest published pages whose content mentions the tag.
	 *
	 * @param string $shortcode The shortcode's name.
	 * @return int[]
	 */
	private static function candidates( string $shortcode ): array {
		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'numberposts'    => self::LOOK_AT,
				'fields'         => 'ids',
				's'              => '[' . $shortcode,
				'search_columns' => array( 'post_content' ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Whether a page is published, carries the shortcode and may be edited by the current person.
	 *
	 * @param int    $id        The page.
	 * @param string $shortcode The shortcode's name.
	 */
	private static function qualifies( int $id, string $shortcode ): bool {
		$post = get_post( $id );
		return $post instanceof \WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status
			&& has_shortcode( $post->post_content, $shortcode ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * A page's title, or its number when it has none.
	 *
	 * @param int $id The page.
	 */
	private static function title( int $id ): string {
		$title = get_the_title( $id );
		return '' !== $title ? $title : '#' . $id;
	}
}
