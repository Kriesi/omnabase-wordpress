<?php
/**
 * The message centre's output: the template with the model in words.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\PluginInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Turns `Centre`'s plain values into what a customer reads (a status word, a date with its year, the notice's sentence, the refusal's)
 * and renders `templates/messages.php`, which escapes everything it prints. Also the two small outputs around it: the login link for a
 * visitor who is not signed in, and the administrator's note when the shortcode cannot work where it is.
 */
final class Views {

	/**
	 * Builds the views.
	 *
	 * @param PluginInfo $info The plugin's folder.
	 */
	public function __construct( private PluginInfo $info ) {}

	/**
	 * A signed-in customer's message centre.
	 *
	 * @param array<string,mixed> $model What `Centre` made.
	 */
	public function centre( array $model ): string {
		$model['notice']  = Notices::of( (string) $model['notice'], (string) $model['masked'] );
		$model['refusal'] = Notices::refusal( (string) ( $model['problem'] ?? '' ) ); // shown by the "refused" and "down" views
		$model['rows']    = array_map( array( self::class, 'worded' ), (array) ( $model['rows'] ?? array() ) );
		if ( isset( $model['ticket'] ) ) {
			$model['ticket']   = self::worded( $model['ticket'] );
			$model['messages'] = array_map( static fn ( array $m ) => $m + array( 'when' => self::date( $m['createdAt'] ) ), $model['messages'] );
		}
		return $this->render( $model + array( 'loginUrl' => '' ) );
	}

	/**
	 * A visitor who is not signed in: a login link that brings them back.
	 *
	 * @param string $back Where to come back to.
	 */
	public function loggedOut( string $back ): string {
		return $this->render( array( 'view' => 'login', 'loginUrl' => wp_login_url( $back ), 'notice' => null ) );
	}

	/**
	 * The administrator's note when the shortcode cannot work here; nothing for anyone else.
	 *
	 * @param string $words What is wrong.
	 */
	public static function adminNote( string $words ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}
		return '<p class="ob-wp ob-wp-messages-notice">' . esc_html( $words . ' ' . __( 'Only administrators see this note.', 'omnabase' ) ) . '</p>';
	}

	/**
	 * The template's output.
	 *
	 * @param array<string,mixed> $omnabase_messages What it shows.
	 */
	private function render( array $omnabase_messages ): string {
		ob_start();
		include $this->info->dir() . 'templates/messages.php';
		return (string) ob_get_clean();
	}

	/**
	 * A ticket with its status in words and its last change as a date.
	 *
	 * @param array<string,mixed> $ticket The ticket.
	 * @return array<string,mixed>
	 */
	private static function worded( array $ticket ): array {
		return $ticket + array( 'statusWord' => self::status( (string) $ticket['status'] ), 'when' => self::date( (string) $ticket['updatedAt'] ) );
	}

	/**
	 * A status as the customer reads it.
	 *
	 * @param string $status OmnaBase's status.
	 */
	private static function status( string $status ): string {
		return match ( $status ) {
			'pending'  => __( 'Answered', 'omnabase' ),
			'resolved' => __( 'Resolved', 'omnabase' ),
			'archived' => __( 'Closed', 'omnabase' ),
			default    => __( 'Open', 'omnabase' ),
		};
	}

	/**
	 * A time from OmnaBase as a date with its year, in the site's time zone ('' when unreadable).
	 *
	 * @param string $iso The time (ISO 8601).
	 */
	private static function date( string $iso ): string {
		$time = strtotime( $iso );
		/* translators: the date format of a ticket's last change in the message centre (PHP date format; keep the year). */
		return false === $time ? '' : (string) wp_date( __( 'j M Y', 'omnabase' ), $time );
	}
}
