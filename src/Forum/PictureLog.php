<?php
/**
 * The Activity tab's lines about pictures in forum replies.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The forum reply pictures round (OmnaBase, 2026-11-17; OmnaBase's red team's finding 21): one short line per forum reply and outcome when a
 * picture of OmnaBase's answer was saved later or left out: when, the ticket number, `later` or `left_out`, the reason (a `PictureSaver`
 * reason code) and how many pictures. Never the picture, its name or the reply's text. The last 20, newest first (one option).
 */
final class PictureLog {

	public const KEEP = 20;

	public const SAVED_LATER = 'later';
	public const LEFT_OUT    = 'left_out';

	/** The reason when the reply was edited on the site before a late picture arrived (the plugin then leaves the reply as it is). */
	public const EDITED = 'edited';

	/**
	 * Adds one line per outcome and reason for a reply.
	 *
	 * @param int                   $ticket  The ticket's number.
	 * @param string                $outcome `later` or `left_out`.
	 * @param array<string,int>     $reasons How many pictures per reason (`''` for a picture saved later).
	 */
	public function add( int $ticket, string $outcome, array $reasons ): void {
		$rows = array();
		foreach ( $reasons as $reason => $count ) {
			$rows[] = array( 'at' => time(), 'ticket' => $ticket, 'outcome' => $outcome, 'reason' => (string) $reason, 'count' => (int) $count );
		}
		if ( array() !== $rows ) {
			update_option( Options::PICTURE_LOG, array_slice( array_merge( $rows, $this->recent() ), 0, self::KEEP ), false );
		}
	}

	/**
	 * The lines, newest first, at most 20.
	 *
	 * @return array<int,array{at:int,ticket:int,outcome:string,reason:string,count:int}>
	 */
	public function recent(): array {
		$rows = get_option( Options::PICTURE_LOG, array() );
		return is_array( $rows ) ? array_slice( array_values( array_filter( $rows, 'is_array' ) ), 0, self::KEEP ) : array();
	}

	/**
	 * How many pictures of a list of outcomes went for each reason.
	 *
	 * @param array<string,string> $reasons The reason per picture id.
	 * @return array<string,int>
	 */
	public static function counted( array $reasons ): array {
		return array_count_values( array_values( $reasons ) );
	}
}
