<?php
/**
 * A forum post as the forum shows it: the formatted version OmnaBase takes beside the plain text.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * The forum edits and pictures round (2026-11-18, findings 21 to 23): the post's stored content with the forum's own formatting, so the
 * pictures a visitor sees reach the ticket: bbPress's link previews when its auto-embed setting is on (a bare picture link becomes the
 * picture), balanced tags, clickable links and paragraphs. Only WordPress's and bbPress's own formatting steps run, by name. The site's
 * other display filters do not (the check before the first install on kriesi.at, 2026-11-18): a site may hide email addresses or licence
 * codes from logged-out visitors, or turn product names into partner links, and the sending job is a logged-out visitor; the team must read
 * what the customer wrote. bbPress's "show the post" step is not used either: it answers a password box for a protected post and adds its
 * revision log ("This topic was modified … ago by …"). Two more rules:
 * - a post behind a password (its own, its topic's or its forum's) sends no formatted version, only its plain text;
 * - above OmnaBase's 200,000 characters the formatted version is left out (OmnaBase would refuse the whole call).
 * The post is set up as the current post while it renders, so a link preview uses the cache the forum filled beside the post. A formatting
 * step that fails leaves the formatted version out; the plain text still travels.
 */
final class ShownForm {

	/** The longest formatted version OmnaBase takes. */
	public const MAX = 200000;

	/**
	 * The `html` a call carries for a post, or nothing.
	 *
	 * @param \WP_Post $post The topic or reply.
	 * @return array{html?:string}
	 */
	public static function of( \WP_Post $post ): array {
		if ( 0 !== (int) bbp_get_password_required_id( $post->ID ) ) {
			return array();
		}
		$html = trim( self::render( $post ) );
		return '' === $html || mb_strlen( $html ) > self::MAX ? array() : array( 'html' => $html );
	}

	/**
	 * The post's stored content with the forum's own formatting, the post set up as the current one; '' when a step fails.
	 *
	 * @param \WP_Post $post The topic or reply.
	 */
	private static function render( \WP_Post $post ): string {
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the forum's link previews read the current post; put back below.
		setup_postdata( $post );
		try {
			return self::formatted( (string) $post->post_content );
		} catch ( \Throwable $e ) {
			return '';
		} finally {
			$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- put back.
			if ( $previous instanceof \WP_Post ) {
				setup_postdata( $previous );
			}
		}
	}

	/**
	 * WordPress's and bbPress's own formatting steps, in bbPress's order: link previews (when its setting is on), balanced tags, clickable
	 * links, paragraphs. No filter of the site runs.
	 *
	 * @param string $content The post's stored content.
	 */
	private static function formatted( string $content ): string {
		if ( bbp_use_autoembed() && isset( $GLOBALS['wp_embed'] ) && $GLOBALS['wp_embed'] instanceof \WP_Embed ) {
			$content = (string) $GLOBALS['wp_embed']->autoembed( $content );
		}
		return wpautop( bbp_make_clickable( force_balance_tags( $content ) ) );
	}
}
