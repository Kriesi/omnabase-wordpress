<?php
/**
 * The message centre's form posts: New ticket, Reply, and "Show my email conversations".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;

use OmnaBase\WordPress\Forms\Guard;
use OmnaBase\WordPress\Forms\Projects;

defined( 'ABSPATH' ) || exit;

/**
 * The forms post back to the picked page and are handled at `template_redirect` (before the page is built), then the browser is sent back
 * with a notice code: a reload never sends twice. Each post needs a signed-in user and the form's nonce (a short-lived form token against
 * forged requests); the New ticket form also carries round four's trap field (`omnabase_hp`: filled, nothing goes). The identity is always
 * the current user's site id (`Visitor`), never a posted field: a changed ticket number only ever reaches the visitor's own tickets.
 */
final class Controller {

	public const ACTION      = 'omnabase_do';
	public const NONCE       = 'omnabase_portal';
	public const NONCE_FIELD = 'omnabase_nonce';
	public const TOKEN       = 'omnabase_token';
	public const TICKET      = 'omnabase_ticket';
	public const DONE        = 'omnabase_done';
	public const NEW_TICKET  = 'new_ticket';
	public const REPLY       = 'reply';
	public const CONFIRM     = 'confirm';

	/**
	 * Builds the controller.
	 *
	 * @param PickedPage $page     The picked page.
	 * @param Visitor    $visitor  Who the user is to OmnaBase.
	 * @param Requests   $requests The writes.
	 * @param Projects   $projects The key's projects.
	 * @param Guard      $guard    Round four's trap field.
	 * @param Draft      $draft    The kept words of a refused New ticket.
	 */
	public function __construct( private PickedPage $page, private Visitor $visitor, private Requests $requests, private Projects $projects, private Guard $guard, private Draft $draft ) {}

	/** Registers the hook. */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle' ), 5 );
	}

	/** Handles a post of one of the forms on the picked page, then redirects back. */
	public function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		$action = sanitize_key( self::posted( self::ACTION ) );
		if ( 'POST' !== $method || '' === $action || ! $this->page->isCurrent() ) {
			return;
		}
		$result = $this->outcome( $action );
		if ( self::NEW_TICKET === $action ) {
			$this->remember( $result['notice'] );
		}
		$this->back( $result );
	}

	/**
	 * A New ticket's words: forgotten once it went, kept for the same user when it was refused (nothing for a trap or a refused user).
	 *
	 * @param string $notice What the post led to.
	 */
	private function remember( string $notice ): void {
		$user_id = get_current_user_id();
		if ( Notices::CREATED === $notice ) {
			$this->draft->forget( $user_id );
		} elseif ( '' !== $notice ) {
			$this->draft->keep( $user_id, self::posted( 'subject' ), self::posted( 'message' ) );
		}
	}

	/**
	 * What the post leads to.
	 *
	 * @param string $action Which form.
	 * @return array{notice:string,ticket:?int}
	 */
	private function outcome( string $action ): array {
		$user   = wp_get_current_user();
		$ticket = self::number( self::posted( self::TICKET ) );
		if ( ! $user->exists() ) {
			return array( 'notice' => '', 'ticket' => null );
		}
		if ( ! wp_verify_nonce( self::posted( self::NONCE_FIELD ), self::NONCE ) ) {
			return array( 'notice' => Notices::EXPIRED, 'ticket' => self::REPLY === $action ? $ticket : null );
		}
		if ( self::NEW_TICKET === $action && $this->guard->trapped( array( Guard::HONEYPOT => self::posted( Guard::HONEYPOT ) ) ) ) {
			return array( 'notice' => '', 'ticket' => null );
		}
		$state = $this->visitor->ensure( $user );
		if ( '' !== $state['problem'] ) {
			return array( 'notice' => '', 'ticket' => null ); // the page says why
		}
		return $this->perform( $action, (int) $user->ID, $state['siteId'], $ticket );
	}

	/**
	 * The form's request, as the visitor.
	 *
	 * @param string   $action  Which form.
	 * @param int      $user_id The user.
	 * @param string   $site_id Their site id.
	 * @param int|null $ticket  The posted ticket number (Reply).
	 * @return array{notice:string,ticket:?int}
	 */
	private function perform( string $action, int $user_id, string $site_id, ?int $ticket ): array {
		return match ( $action ) {
			self::NEW_TICKET => $this->requests->create( $site_id, self::posted( 'subject' ), self::posted( 'message' ), self::posted( self::TOKEN ), $this->project() ),
			self::REPLY      => null === $ticket ? array( 'notice' => Notices::NOT_FOUND, 'ticket' => null ) : $this->requests->reply( $site_id, $ticket, self::posted( 'message' ) ),
			self::CONFIRM    => $this->requests->confirm( $user_id, $site_id ),
			default          => array( 'notice' => '', 'ticket' => null ),
		};
	}

	/** The project a new ticket goes to: the form's (one of the key's), else the key's single one, else OmnaBase's choice (''). */
	private function project(): string {
		$resolved = $this->projects->resolve( sanitize_text_field( self::posted( 'project' ) ) );
		return '' === $resolved['slug'] ? '' : $this->projects->idOf( $resolved['slug'] );
	}

	/**
	 * Back to the message centre, with the notice and the ticket the post was about.
	 *
	 * @param array{notice:string,ticket:?int} $result What happened.
	 */
	private function back( array $result ): void {
		$args = array_filter( array( self::TICKET => (string) ( $result['ticket'] ?? '' ), self::DONE => $result['notice'] ), static fn ( string $v ) => '' !== $v );
		wp_safe_redirect( $this->page->url( $args ) );
		exit;
	}

	/**
	 * A posted field as typed (unslashed, invalid UTF-8 removed; '' when missing). Messages keep their characters: OmnaBase stores them as
	 * text and every page prints them escaped.
	 *
	 * @param string $name The field.
	 */
	private static function posted( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is checked in outcome() before anything is sent; text is kept as typed and escaped on output.
		$value = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : '';
		return wp_check_invalid_utf8( $value );
	}

	/**
	 * A ticket number, only digits (anything else is no number).
	 *
	 * @param string $raw The value.
	 */
	public static function number( string $raw ): ?int {
		return 1 === preg_match( '/^[1-9][0-9]{0,9}$/', $raw ) ? (int) $raw : null;
	}
}
