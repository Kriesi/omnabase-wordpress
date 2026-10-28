<?php
/**
 * The forum settings: which forums send their topics, the support user, the closing line, guests, closing on resolve.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One option (`omnabase_forum`). Missing keys mean: no forum marked, no support user, the closing line "— {agent}, {workspace}", guests'
 * topics forwarded (the brief's default), closing a topic when its ticket is resolved off, no post fields (the site-fields round: `fields`,
 * key ⇒ name, cleaned by `PostFields::cleanRows`). The support user must have bbPress's moderator
 * rights (`moderate`: they can reply on closed topics); anyone else is never stored and never used.
 */
final class Settings {

	public const DEFAULT_CLOSING_LINE = '— {agent}, {workspace}';

	/** The longest closing line kept. */
	private const CLOSING_LINE_MAX = 200;

	/** The forums whose topics become tickets. @return int[] */
	public function forums(): array {
		return array_values( array_filter( array_map( 'intval', (array) ( $this->all()['forums'] ?? array() ) ) ) );
	}

	/**
	 * Whether a forum's topics become tickets.
	 *
	 * @param int $forum_id The forum.
	 */
	public function isMarked( int $forum_id ): bool {
		return $forum_id > 0 && in_array( $forum_id, $this->forums(), true );
	}

	/** The support user's id when it is still a user with moderator rights, else 0. */
	public function supportUser(): int {
		$id = (int) ( $this->all()['support_user'] ?? 0 );
		return self::canModerate( $id ) ? $id : 0;
	}

	/** The closing line with its `{agent}` and `{workspace}` placeholders. */
	public function closingLine(): string {
		$line = trim( (string) ( $this->all()['closing_line'] ?? '' ) );
		return '' !== $line ? $line : self::DEFAULT_CLOSING_LINE;
	}

	/** Whether guests' topics are forwarded. */
	public function guests(): bool {
		return (bool) ( $this->all()['guests'] ?? true );
	}

	/** The declared post fields as stored, key ⇒ name. @return array<string,string> */
	public function fields(): array {
		$saved = $this->all()['fields'] ?? array();
		return is_array( $saved ) ? array_map( 'strval', $saved ) : array();
	}

	/**
	 * Stores the post fields (already cleaned), keeping the rest of the forum settings.
	 *
	 * @param array<string,string> $fields Key ⇒ name.
	 */
	public function saveFields( array $fields ): void {
		update_option( Options::FORUM, array( 'fields' => $fields ) + $this->all(), false );
	}

	/** Whether a resolved ticket closes its topic. */
	public function closeResolved(): bool {
		return (bool) ( $this->all()['close_resolved'] ?? false );
	}

	/**
	 * Stores the administrator's choices: only existing forums, a support user only when they have moderator rights (else the earlier one
	 * stays), the closing line as plain text.
	 *
	 * @param int[]  $forums         The ticked forums.
	 * @param int    $support_user   The chosen support user.
	 * @param string $closing_line   The closing line.
	 * @param bool   $guests         Whether guests' topics are forwarded.
	 * @param bool   $close_resolved Whether a resolved ticket closes its topic.
	 */
	public function save( array $forums, int $support_user, string $closing_line, bool $guests, bool $close_resolved ): void {
		$forums = array_values( array_unique( array_filter( array_map( 'intval', $forums ), array( self::class, 'isForum' ) ) ) );
		$line   = mb_substr( trim( sanitize_text_field( $closing_line ) ), 0, self::CLOSING_LINE_MAX );
		update_option(
			Options::FORUM,
			array(
				'forums'         => $forums,
				'support_user'   => self::canModerate( $support_user ) ? $support_user : (int) ( $this->all()['support_user'] ?? 0 ),
				'closing_line'   => $line,
				'guests'         => $guests,
				'close_resolved' => $close_resolved,
				'fields'         => $this->fields(),
			),
			false
		);
	}

	/**
	 * Whether a user has bbPress's moderator rights.
	 *
	 * @param int $user_id The user.
	 */
	public static function canModerate( int $user_id ): bool {
		return $user_id > 0 && false !== get_userdata( $user_id ) && user_can( $user_id, 'moderate' );
	}

	/**
	 * Whether a post id is a forum.
	 *
	 * @param int $id The post.
	 */
	private static function isForum( int $id ): bool {
		return $id > 0 && function_exists( 'bbp_get_forum_post_type' ) && bbp_get_forum_post_type() === get_post_type( $id );
	}

	/** The stored settings. @return array<string,mixed> */
	private function all(): array {
		$saved = get_option( Options::FORUM, array() );
		return is_array( $saved ) ? $saved : array();
	}
}
