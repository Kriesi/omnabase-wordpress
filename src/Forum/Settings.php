<?php
/**
 * The forum settings: which forums send their topics, the support user, the closing line, guests, keeping topics in step with tickets.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One option (`omnabase_forum`). Missing keys mean: no forum marked, no support user, the closing line "Best regards" / "{agent}, {workspace}"
 * (the polish round, 2026-10-31: a few lines; an empty line or the old default "— {agent}, {workspace}" counts as not set), guests'
 * topics forwarded (the brief's default), topics kept in step with their tickets (`in_step`, the forum sync round of 2026-11-02: on when
 * missing; the earlier `close_resolved` is ignored and dropped at the next save), no post fields (the site-fields round: `fields`,
 * key ⇒ name, cleaned by `PostFields::cleanRows`). The support user must have bbPress's moderator
 * rights (`moderate`: they can reply on closed topics); anyone else is never stored and never used.
 */
final class Settings {

	public const DEFAULT_CLOSING_LINE = "Best regards\n{agent}, {workspace}";

	/** The default before the polish round: a site still holding it gets today's default. */
	private const OLD_DEFAULT_CLOSING_LINE = '— {agent}, {workspace}';

	/** The longest closing line kept, in characters. */
	private const CLOSING_LINE_MAX = 300;

	/** The most lines a closing line keeps. */
	private const CLOSING_LINE_LINES = 5;

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

	/** The closing line with its `{agent}` and `{workspace}` placeholders, its lines split by "\n". */
	public function closingLine(): string {
		$line = trim( (string) ( $this->all()['closing_line'] ?? '' ) );
		return '' === $line || self::OLD_DEFAULT_CLOSING_LINE === $line ? self::DEFAULT_CLOSING_LINE : $line;
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

	/** Whether topics follow their tickets: closed when resolved, reopened when open or pending (on when never stored). */
	public function inStep(): bool {
		return (bool) ( $this->all()['in_step'] ?? true );
	}

	/**
	 * Stores the administrator's choices: only existing forums, a support user only when they have moderator rights (else the earlier one
	 * stays), the closing line as plain lines (nothing stored when it is the default, so a later default reaches the site).
	 *
	 * @param int[]  $forums         The ticked forums.
	 * @param int    $support_user   The chosen support user.
	 * @param string $closing_line   The closing line.
	 * @param bool   $guests         Whether guests' topics are forwarded.
	 * @param bool   $in_step        Whether topics follow their tickets.
	 */
	public function save( array $forums, int $support_user, string $closing_line, bool $guests, bool $in_step ): void {
		$forums = array_values( array_unique( array_filter( array_map( 'intval', $forums ), array( self::class, 'isForum' ) ) ) );
		$line   = self::cleanClosingLine( $closing_line );
		update_option(
			Options::FORUM,
			array(
				'forums'       => $forums,
				'support_user' => self::canModerate( $support_user ) ? $support_user : (int) ( $this->all()['support_user'] ?? 0 ),
				'closing_line' => self::DEFAULT_CLOSING_LINE === $line ? '' : $line,
				'guests'       => $guests,
				'in_step'      => $in_step,
				'fields'       => $this->fields(),
			),
			false
		);
	}

	/**
	 * The closing line as stored: tags stripped (`sanitize_textarea_field` keeps line breaks), plain line ends, each line trimmed, empty lines
	 * dropped, at most five lines, cut at the last whole line within 300 characters (one long line is cut at 300).
	 *
	 * @param string $closing_line The line as typed.
	 */
	public static function cleanClosingLine( string $closing_line ): string {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( $closing_line ) );
		$lines = array_slice( array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) ), 0, self::CLOSING_LINE_LINES );
		$kept  = array();
		foreach ( $lines as $line ) {
			if ( mb_strlen( implode( "\n", array_merge( $kept, array( $line ) ) ) ) > self::CLOSING_LINE_MAX ) {
				break;
			}
			$kept[] = $line;
		}
		if ( array() === $kept && array() !== $lines ) {
			$kept[] = trim( mb_substr( $lines[0], 0, self::CLOSING_LINE_MAX ) );
		}
		return implode( "\n", $kept );
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
