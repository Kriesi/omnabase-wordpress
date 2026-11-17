<?php
/**
 * The pictures of OmnaBase's reply in a forum post: fetched while posting, within a budget, and finished later by a background job.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forum;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The forum reply pictures round (OmnaBase, 2026-11-17; OmnaBase's brief "Part B, pinned", its red team's findings 12, 17, 18, 20 and 21).
 * - While posting (`whilePosting()`, before the reply exists): each listed picture is fetched and saved in turn, until three seconds have
 *   passed since the request began (OmnaBase waits five for the answer); each download may take only what is left. Saved pictures have no
 *   parent yet. A picture refused for its type, size, a 404 or an upload folder that cannot be written is never tried again; one that did
 *   not arrive waits.
 * - After posting (`afterPosting()`): the saved pictures get the reply as their parent, refusals go to the Activity tab, and when anything
 *   is left (a picture waiting, thumbnails to make) the reply keeps what the later job needs and the job is left for WP-Cron (WordPress's
 *   timer, which runs jobs shortly after a page view).
 * - The later job (`finish()`): it fetches what waits, makes the thumbnails (WordPress's smaller copies), and puts new pictures into the
 *   posted reply without a revision (a stored earlier version, which bbPress would show as "This reply was modified"), unless someone
 *   edited the reply meanwhile. A picture that still waits is tried again after 5, 15 and 40 minutes, then left out.
 */
final class ReplyPictures {

	public const HOOK = 'omnabase_forum_pictures';

	/** Seconds from the request's start that the pictures may use while the reply is posted. */
	private const BUDGET_SECONDS = 3.0;
	/** A download is only started with at least this much of the budget left. */
	private const LEAST_SECONDS = 0.75;
	/** Seconds one download may take in the later job. */
	private const LATER_SECONDS = 10.0;
	/** When the job runs again after each try, in seconds: about an hour in all. */
	private const RETRY_AFTER = array( 300, 900, 2400 );

	/**
	 * Builds the pictures' part.
	 *
	 * @param PictureSaver $saver Fetches and saves one picture.
	 * @param PictureLog   $log   The Activity tab's lines.
	 */
	public function __construct( private PictureSaver $saver, private PictureLog $log ) {}

	/** Registers the later job. */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'finish' ), 10, 1 );
	}

	/**
	 * When the budget's clock started: the web request's start, or now on the command line (WP-CLI, a test run), where no request is waited on.
	 */
	public static function clockStart(): float {
		$request = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0.0;
		return 'cli' === PHP_SAPI || $request <= 0.0 ? microtime( true ) : $request;
	}

	/**
	 * Fetches the listed pictures while the budget lasts, before the reply exists.
	 *
	 * @param array<string,mixed> $message OmnaBase's message.
	 * @param int                 $ticket  The ticket's number.
	 * @param float               $started When the budget's clock started.
	 * @return array{entries:array,saved:array<string,int>,waiting:string[],refused:array<string,string>}
	 */
	public function whilePosting( array $message, int $ticket, float $started ): array {
		$set = array( 'entries' => $ticket > 0 ? PictureMarks::entries( $message ) : array(), 'saved' => array(), 'waiting' => array(), 'refused' => array() );
		foreach ( $set['entries'] as $entry ) {
			$left = self::BUDGET_SECONDS - ( microtime( true ) - $started );
			if ( $left < self::LEAST_SECONDS ) {
				$set['waiting'][] = $entry['id'];
				continue;
			}
			$set = self::with( $set, $entry['id'], $this->saver->save( $entry, $ticket, (string) $message['id'], min( self::BUDGET_SECONDS, $left ), 0 ) );
		}
		return $set;
	}

	/**
	 * After the reply exists: the saved pictures get it as their parent, refusals are logged, and the later job is left when anything is left.
	 *
	 * @param int                 $reply   The posted reply.
	 * @param array               $set     What `whilePosting()` answered.
	 * @param array<string,mixed> $message OmnaBase's message.
	 * @param string              $closing The reply's closing paragraph, as posted.
	 * @param int                 $ticket  The ticket's number.
	 */
	public function afterPosting( int $reply, array $set, array $message, string $closing, int $ticket ): void {
		if ( array() === $set['entries'] ) {
			return;
		}
		self::adopt( $reply, $set['saved'] );
		$this->log->add( $ticket, PictureLog::LEFT_OUT, PictureLog::counted( $set['refused'] ) );
		$state = $set + array(
			'html'    => is_string( $message['bodyHtml'] ?? null ) ? $message['bodyHtml'] : null,
			'text'    => (string) ( $message['bodyText'] ?? '' ),
			'closing' => $closing,
			'ticket'  => $ticket,
			'message' => (string) $message['id'],
			'tries'   => 0,
			'posted'  => md5( (string) get_post_field( 'post_content', $reply ) ),
		);
		update_post_meta( $reply, Options::PICTURES_META, $state );
		wp_schedule_single_event( time(), self::HOOK, array( $reply ) );
	}

	/**
	 * The later job: fetches what waits, makes the thumbnails, updates the posted reply, and tries again later or gives up.
	 *
	 * @param int|mixed $reply_id The reply.
	 */
	public function finish( $reply_id ): void {
		$reply = (int) $reply_id;
		$state = get_post_meta( $reply, Options::PICTURES_META, true );
		if ( ! is_array( $state ) || ! Posts::isReply( get_post( $reply ) ) ) {
			return;
		}
		$before = $state['saved'];
		$state  = $this->fetchWaiting( $reply, $state );
		array_map( array( PictureSaver::class, 'thumbnails' ), array_values( $state['saved'] ) );
		$state = $this->showNew( $reply, $state, array_diff_key( $state['saved'], $before ) );
		$this->nextTry( $reply, $state );
	}

	/** Clears every waiting job (deactivation). */
	public static function clear(): void {
		wp_unschedule_hook( self::HOOK );
	}

	/**
	 * The later job's fetch of every waiting picture, each given the reply as its parent.
	 *
	 * @param int   $reply The reply.
	 * @param array $state The kept state.
	 * @return array The state after the fetch.
	 */
	private function fetchWaiting( int $reply, array $state ): array {
		$named            = array_column( $state['entries'], null, 'id' );
		$waiting          = $state['waiting'];
		$state['waiting'] = array();
		foreach ( $waiting as $id ) {
			$state = self::with( $state, $id, $this->saver->save( $named[ $id ], (int) $state['ticket'], (string) $state['message'], self::LATER_SECONDS, $reply ) );
		}
		self::adopt( $reply, $state['saved'] );
		$this->log->add( (int) $state['ticket'], PictureLog::LEFT_OUT, PictureLog::counted( array_intersect_key( $state['refused'], array_flip( $waiting ) ) ) );
		return $state;
	}

	/**
	 * Puts the newly saved pictures into the posted reply (no revision), unless the reply was edited since it was posted.
	 *
	 * @param int             $reply The reply.
	 * @param array           $state The kept state.
	 * @param array<string,int> $new The pictures saved by this run.
	 * @return array The state with the content's new fingerprint.
	 */
	private function showNew( int $reply, array $state, array $new ): array {
		if ( array() === $new ) {
			return $state;
		}
		if ( md5( (string) get_post_field( 'post_content', $reply ) ) !== $state['posted'] ) {
			$state['waiting'] = array(); // someone edited the reply: the plugin no longer writes into it
			$this->log->add( (int) $state['ticket'], PictureLog::LEFT_OUT, array( PictureLog::EDITED => count( $new ) ) );
			return $state;
		}
		$content = ReplyPoster::body( $state['html'], $state['text'], $state['entries'], $state['saved'] ) . $state['closing'];
		self::updateWithoutRevision( $reply, $content );
		$state['posted'] = md5( (string) get_post_field( 'post_content', $reply ) );
		$this->log->add( (int) $state['ticket'], PictureLog::SAVED_LATER, array( '' => count( $new ) ) );
		return $state;
	}

	/**
	 * After a run: the job again later while pictures wait and tries are left, else the waiting ones are left out and the state goes.
	 *
	 * @param int   $reply The reply.
	 * @param array $state The kept state.
	 */
	private function nextTry( int $reply, array $state ): void {
		$tries = (int) $state['tries'];
		if ( array() !== $state['waiting'] && $tries < count( self::RETRY_AFTER ) ) {
			$state['tries'] = $tries + 1;
			update_post_meta( $reply, Options::PICTURES_META, $state );
			wp_schedule_single_event( time() + self::RETRY_AFTER[ $tries ], self::HOOK, array( $reply ) );
			return;
		}
		$this->log->add( (int) $state['ticket'], PictureLog::LEFT_OUT, array() !== $state['waiting'] ? array( PictureSaver::NO_ANSWER => count( $state['waiting'] ) ) : array() );
		delete_post_meta( $reply, Options::PICTURES_META );
	}

	/**
	 * The set with one try's outcome added.
	 *
	 * @param array  $set     The set.
	 * @param string $id      OmnaBase's file id.
	 * @param array  $outcome What the saver answered.
	 * @return array
	 */
	private static function with( array $set, string $id, array $outcome ): array {
		if ( 'saved' === $outcome['state'] ) {
			$set['saved'][ $id ] = (int) $outcome['attachment'];
		} elseif ( 'later' === $outcome['state'] ) {
			$set['waiting'][] = $id;
		} else {
			$set['refused'][ $id ] = (string) $outcome['reason'];
		}
		return $set;
	}

	/**
	 * Gives the saved pictures the reply as their parent (a picture saved before the reply existed has none).
	 *
	 * @param int               $reply The reply.
	 * @param array<string,int> $saved The saved pictures by OmnaBase's file id.
	 */
	private static function adopt( int $reply, array $saved ): void {
		foreach ( $saved as $attachment ) {
			if ( (int) get_post_field( 'post_parent', $attachment ) !== $reply ) {
				wp_update_post( array( 'ID' => $attachment, 'post_parent' => $reply ) );
			}
		}
	}

	/**
	 * Updates the reply's content without a stored earlier version.
	 *
	 * @param int    $reply   The reply.
	 * @param string $content The new content.
	 */
	private static function updateWithoutRevision( int $reply, string $content ): void {
		$none = static fn ( $keep, $post ) => $post instanceof \WP_Post && $post->ID === $reply ? 0 : $keep;
		add_filter( 'wp_revisions_to_keep', $none, 999, 2 );
		try {
			wp_update_post( array( 'ID' => $reply, 'post_content' => wp_slash( $content ) ) );
		} finally {
			remove_filter( 'wp_revisions_to_keep', $none, 999 );
		}
	}
}
