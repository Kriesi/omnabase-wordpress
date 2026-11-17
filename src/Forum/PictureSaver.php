<?php
/**
 * One picture of a team reply's text: fetched from OmnaBase, checked and saved into the media library.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The forum reply pictures round (OmnaBase, 2026-11-17; OmnaBase's brief "Part B, pinned", its red team's findings 13 to 16, 18 to 20).
 * The media library is WordPress's store of uploaded files; each saved picture is an attachment (a post of its own) marked with OmnaBase's
 * file id (`_omnabase_file_id`), so a picture already saved (a retry, a redelivery) is found and reused, never saved twice.
 * - The plugin's own call fetches it (the key, the pinned host, no redirect) into a temporary file, stopping at 10 MB and one byte.
 * - An entry announced over 10 MB is never fetched; a file that reached the cap is refused.
 * - The first bytes decide the type (`PictureType`); the saved name's ending comes from that type, never from OmnaBase's name.
 * - WordPress's own upload check runs as well (`wp_handle_sideload`); then only the file and its attachment row are written (no smaller
 *   copies, the thumbnails: those wait for the later job, `thumbnails()`).
 * Each try answers `saved` (with the attachment), `later` (no answer, too many calls, a server error: try again) or `final` (not a picture,
 * too large, gone at OmnaBase, the upload folder refused it: never again), with the reason.
 */
final class PictureSaver {

	/** The largest picture saved, in bytes. */
	public const MOST_BYTES = 10485760;

	/** Why a picture was left out, as the log names it. */
	public const NOT_A_PICTURE = 'type';
	public const TOO_LARGE     = 'size';
	public const GONE          = 'gone';
	public const NOT_WRITABLE  = 'upload';
	public const NO_ANSWER     = 'time';

	/**
	 * Builds the saver.
	 *
	 * @param Client $client The plugin's calls to OmnaBase.
	 */
	public function __construct( private Client $client ) {}

	/**
	 * The attachment already saved for an OmnaBase file id, or 0.
	 *
	 * @param string $file_id OmnaBase's file id.
	 */
	public static function savedFor( string $file_id ): int {
		$found = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => Options::FILE_META, // phpcs:ignore WordPress.DB.SlowDBQuery -- one indexed meta key, read when a picture arrives.
				'meta_value'       => $file_id, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true,
			)
		);
		return (int) ( $found[0] ?? 0 );
	}

	/**
	 * Fetches and saves one listed picture, unless it is saved already.
	 *
	 * @param array{id:string,name:string,size:int,width:?int} $entry   The listed picture.
	 * @param int                                              $ticket  The ticket's number.
	 * @param string                                           $message OmnaBase's message id.
	 * @param float                                            $timeout Seconds the download may take.
	 * @param int                                              $parent  The forum reply, or 0 before it exists.
	 * @return array{state:string,attachment:int,reason:string}
	 */
	public function save( array $entry, int $ticket, string $message, float $timeout, int $parent ): array {
		$existing = self::savedFor( $entry['id'] );
		if ( $existing > 0 ) {
			return self::outcome( 'saved', '', $existing );
		}
		if ( $entry['size'] > self::MOST_BYTES ) {
			return self::outcome( 'final', self::TOO_LARGE );
		}
		self::needAdminFiles();
		if ( ! empty( wp_upload_dir( null, false )['error'] ) ) { // as the site reports it, before WordPress's own folder test
			return self::outcome( 'final', self::NOT_WRITABLE );
		}
		$tmp = wp_tempnam( 'omnabase-picture' );
		try {
			$fetched = $this->fetch( $entry, $ticket, $message, $tmp, $timeout );
			return null !== $fetched ? $fetched : self::store( $tmp, $entry, $parent );
		} finally {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Makes the smaller copies (thumbnails) of a saved picture that has none yet (the later job, never while a reply is being posted).
	 *
	 * @param int $attachment The saved picture.
	 */
	public static function thumbnails( int $attachment ): void {
		if ( $attachment < 1 || ! empty( wp_get_attachment_metadata( $attachment ) ) ) {
			return;
		}
		$file = (string) get_attached_file( $attachment );
		if ( '' === $file || ! is_file( $file ) ) {
			return;
		}
		self::needAdminFiles();
		wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $file ) );
	}

	/**
	 * Downloads the picture into the temporary file: null when it arrived and fits, else the outcome.
	 *
	 * @param array{id:string,name:string,size:int,width:?int} $entry   The listed picture.
	 * @param int                                              $ticket  The ticket's number.
	 * @param string                                           $message OmnaBase's message id.
	 * @param string                                           $tmp     The temporary file.
	 * @param float                                            $timeout Seconds the download may take.
	 * @return array{state:string,attachment:int,reason:string}|null
	 */
	private function fetch( array $entry, int $ticket, string $message, string $tmp, float $timeout ): ?array {
		$answer = $this->client->picture( $ticket, $message, $entry['id'], $tmp, $timeout );
		$status = $answer['status'];
		if ( 404 === $status ) {
			return self::outcome( 'final', self::GONE );
		}
		if ( null === $status || $status < 200 || $status >= 300 ) {
			return self::outcome( 'later', self::NO_ANSWER ); // no answer, too many calls (429), a server error, a redirect not followed
		}
		clearstatcache( true, $tmp );
		return is_file( $tmp ) && filesize( $tmp ) <= self::MOST_BYTES ? null : self::outcome( 'final', self::TOO_LARGE );
	}

	/**
	 * Checks the downloaded bytes and saves them as an attachment marked with OmnaBase's file id.
	 *
	 * @param string                                           $tmp    The downloaded file.
	 * @param array{id:string,name:string,size:int,width:?int} $entry  The listed picture.
	 * @param int                                              $parent The forum reply, or 0.
	 * @return array{state:string,attachment:int,reason:string}
	 */
	private static function store( string $tmp, array $entry, int $parent ): array {
		$type = PictureType::of( (string) file_get_contents( $tmp, false, null, 0, 16 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- the first bytes of a local temporary file.
		if ( null === $type ) {
			return self::outcome( 'final', self::NOT_A_PICTURE );
		}
		$upload = array( 'name' => self::fileName( $entry['name'], $type['ext'] ), 'type' => $type['mime'], 'tmp_name' => $tmp, 'error' => 0, 'size' => (int) filesize( $tmp ) );
		$moved  = wp_handle_sideload( $upload, array( 'test_form' => false ) );
		if ( ! empty( $moved['error'] ) || empty( $moved['file'] ) ) {
			return self::outcome( 'final', self::NOT_WRITABLE );
		}
		$attachment = wp_insert_attachment( array( 'post_mime_type' => $type['mime'], 'post_title' => sanitize_text_field( $entry['name'] ), 'post_content' => '', 'post_status' => 'inherit', 'guid' => (string) $moved['url'] ), (string) $moved['file'], $parent, true );
		if ( is_wp_error( $attachment ) || $attachment < 1 ) {
			wp_delete_file( (string) $moved['file'] );
			return self::outcome( 'final', self::NOT_WRITABLE );
		}
		update_post_meta( $attachment, Options::FILE_META, $entry['id'] );
		return self::outcome( 'saved', '', (int) $attachment );
	}

	/**
	 * The saved file's name: OmnaBase's name without any ending or dot, made safe, with the ending of the type the bytes proved.
	 *
	 * @param string $name OmnaBase's name.
	 * @param string $ext  The proved type's ending.
	 */
	private static function fileName( string $name, string $ext ): string {
		$stem = sanitize_title( str_replace( '.', '-', (string) pathinfo( $name, PATHINFO_FILENAME ) ) );
		return ( '' !== $stem ? $stem : 'picture' ) . '.' . $ext;
	}

	/**
	 * One try's outcome.
	 *
	 * @param string $state      `saved`, `later` or `final`.
	 * @param string $reason     Why, for `later` and `final`.
	 * @param int    $attachment The saved picture, for `saved`.
	 * @return array{state:string,attachment:int,reason:string}
	 */
	private static function outcome( string $state, string $reason, int $attachment = 0 ): array {
		return array( 'state' => $state, 'attachment' => $attachment, 'reason' => $reason );
	}

	/** WordPress's file, picture and media helpers, which a REST request or a background job does not load. */
	private static function needAdminFiles(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}
}
