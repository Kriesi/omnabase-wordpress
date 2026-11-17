<?php
/**
 * Who wrote a forum reply, as OmnaBase is told: a moderator's site author or another participant's name.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by the reply job and the topic's history (the old-topics-and-undelivered round, 2026-11-16, moved here from the reply job so both
 * name a writer the same way). Pure reads through WordPress's and bbPress's own functions.
 */
final class ReplyWriter {

	private const NAME_MAX = 120;
	private const ROLE_MAX = 60;

	/**
	 * Another participant's name as the forum shows it (the composer-and-fixes round): a member's display name (else their login), a guest's
	 * name as given (bbPress keeps it on the reply); never an address or a role.
	 *
	 * @param \WP_Post $reply The reply.
	 */
	public static function participantName( \WP_Post $reply ): string {
		$author = (int) $reply->post_author;
		$user   = $author > 0 ? get_userdata( $author ) : false;
		$name   = $user ? ( '' !== trim( (string) $user->display_name ) ? $user->display_name : $user->user_login ) : get_post_meta( $reply->ID, '_bbp_anonymous_name', true );
		$name   = trim( wp_strip_all_tags( (string) $name ) );
		return mb_substr( '' !== $name ? $name : __( 'Forum member', 'omnabase' ), 0, self::NAME_MAX );
	}

	/**
	 * The team member as the site names them: the moderator's own forum display name (since 2026-11-03; until then the support user's name,
	 * which made every moderator read as the support user in OmnaBase), their own address (OmnaBase shows the answer as that member when the
	 * address is a member's) and their forum role.
	 *
	 * @param int $author The moderator.
	 * @return array{name:string,email?:string,role?:string}
	 */
	public static function siteAuthor( int $author ): array {
		$moderator = get_userdata( $author );
		$name      = trim( (string) ( $moderator ? $moderator->display_name : '' ) );
		$out       = array( 'name' => mb_substr( '' !== $name ? $name : __( 'Support', 'omnabase' ), 0, self::NAME_MAX ) );
		if ( $moderator && is_email( $moderator->user_email ) ) {
			$out['email'] = (string) $moderator->user_email;
		}
		$role = function_exists( 'bbp_get_user_display_role' ) ? trim( wp_strip_all_tags( (string) bbp_get_user_display_role( $author ) ) ) : '';
		if ( '' !== $role ) {
			$out['role'] = mb_substr( $role, 0, self::ROLE_MAX );
		}
		return $out;
	}
}
