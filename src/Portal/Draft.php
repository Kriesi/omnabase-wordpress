<?php
/**
 * The kept words of a refused New ticket.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\UserNotes;

defined( 'ABSPATH' ) || exit;

/**
 * When OmnaBase refuses a customer's new question (or it cannot be sent), the subject and message are kept in that user's own note for this site, never in
 * the address bar, and shown again in their New ticket form for an hour; a successful New ticket forgets them. Only the same signed-in user
 * ever reads them back, and the template prints them escaped (as text).
 */
final class Draft {

	private const KEEP_SECONDS = HOUR_IN_SECONDS;

	/**
	 * Keeps the words for the user.
	 *
	 * @param int    $user_id The user.
	 * @param string $subject The subject as typed.
	 * @param string $message The message as typed.
	 */
	public function keep( int $user_id, string $subject, string $message ): void {
		if ( $user_id <= 0 || ( '' === trim( $subject ) && '' === trim( $message ) ) ) {
			return;
		}
		UserNotes::set( $user_id, Options::PORTAL_DRAFT_META, array( 'subject' => $subject, 'message' => $message, 'at' => time() ) );
	}

	/**
	 * The user's kept words (empty strings when none, or older than an hour).
	 *
	 * @param int $user_id The user.
	 * @return array{subject:string,message:string}
	 */
	public function of( int $user_id ): array {
		$kept = UserNotes::get( $user_id, Options::PORTAL_DRAFT_META );
		if ( ! is_array( $kept ) || (int) ( $kept['at'] ?? 0 ) < time() - self::KEEP_SECONDS ) {
			return array( 'subject' => '', 'message' => '' );
		}
		return array( 'subject' => (string) ( $kept['subject'] ?? '' ), 'message' => (string) ( $kept['message'] ?? '' ) );
	}

	/**
	 * Forgets the user's kept words.
	 *
	 * @param int $user_id The user.
	 */
	public function forget( int $user_id ): void {
		UserNotes::delete( $user_id, Options::PORTAL_DRAFT_META );
	}
}
