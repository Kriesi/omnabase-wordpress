<?php
/**
 * What a signed-in customer's message centre shows: their list, one of their threads, the prompt, the forms.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Portal;


defined( 'ABSPATH' ) || exit;

/**
 * The address may carry only what to show (`omnabase_ticket=<number>`, `omnabase_after=<OmnaBase's cursor>`, `omnabase_done=<notice>`),
 * never whom: the visitor is the current user (`Visitor`). A ticket "number" that is not only digits is not sent to OmnaBase at all; another
 * visitor's number is OmnaBase's 404, shown as "not found". OmnaBase's answers are shaped here into plain values (`Views` turns them into
 * words); a message whose kind is not the portal's three (`you`, `team`, `other`) is never shown, whatever OmnaBase sends.
 */
final class Centre {

	private const KINDS = array( 'you', 'team', 'other' );

	/**
	 * Builds the model maker.
	 *
	 * @param PickedPage $page    The picked page.
	 * @param Visitor    $visitor Who the user is to OmnaBase.
	 * @param PortalApi  $api     The calls.
	 * @param Draft      $draft   The kept words of a refused New ticket.
	 */
	public function __construct( private PickedPage $page, private Visitor $visitor, private PortalApi $api, private Draft $draft ) {}

	/**
	 * The model for the signed-in user's view.
	 *
	 * @param \WP_User $user    The user.
	 * @param string   $project The shortcode's project ('' for the key's one).
	 * @return array<string,mixed>
	 */
	public function model( \WP_User $user, string $project ): array {
		$state = $this->visitor->ensure( $user );
		$base  = array(
			'notice'  => self::query( Controller::DONE ),
			'masked'  => Notices::mask( (string) $user->user_email ),
			'action'  => $this->page->url(),
			'nonce'   => wp_create_nonce( Controller::NONCE ),
			'project' => $project,
		);
		if ( '' !== $state['problem'] ) {
			return $base + array( 'view' => 'refused', 'problem' => $state['problem'] );
		}
		$asked = self::query( Controller::TICKET );
		if ( '' !== $asked ) {
			return $base + $this->thread( $state['siteId'], Controller::number( $asked ) );
		}
		return $base + $this->listing( (int) $user->ID, $state['siteId'], ! $state['confirmed'] );
	}

	/**
	 * The list, a page at a time, with the prompt and the New ticket form.
	 *
	 * @param int    $user_id The user.
	 * @param string $site_id Their site id.
	 * @param bool   $prompt  Whether the address still needs confirming (the prompt shows only when OmnaBase also says confirming shows more).
	 * @return array<string,mixed>
	 */
	private function listing( int $user_id, string $site_id, bool $prompt ): array {
		$after  = self::cursor( self::query( 'omnabase_after' ) );
		$answer = $this->api->tickets( $site_id, $after );
		if ( 200 !== $answer['status'] ) {
			if ( 404 === $answer['status'] ) {
				$this->visitor->forget( $user_id ); // OmnaBase no longer knew the visitor: the next view tells it again
			}
			return array( 'view' => 'down' );
		}
		$next = self::cursor( (string) ( $answer['body']['next'] ?? '' ) );
		return array(
			'view'     => 'list',
			'prompt'   => $prompt && true === ( $answer['body']['hiddenConversations'] ?? null ), // only when confirming would show more
			'rows'     => array_map( fn ( $row ) => $this->row( (array) $row ), array_values( array_filter( (array) ( $answer['body']['data'] ?? array() ), 'is_array' ) ) ),
			'next'     => '' === $next ? '' : $this->page->url( array( 'omnabase_after' => $next ) ),
			'first'    => '' === $after ? '' : $this->page->url(),
			'token'    => Requests::token(),
			'honeypot' => Trap::FIELD,
			'draft'    => $this->draft->of( $user_id ),
		);
	}

	/**
	 * One thread, or "not found".
	 *
	 * @param string   $site_id The visitor.
	 * @param int|null $number  The ticket's number (null: not a number).
	 * @return array<string,mixed>
	 */
	private function thread( string $site_id, ?int $number ): array {
		$answer = null === $number ? array( 'status' => 404, 'body' => null ) : $this->api->ticket( $site_id, $number );
		$back   = array( 'back' => $this->page->url() );
		if ( 404 === $answer['status'] ) {
			return $back + array( 'view' => 'missing' );
		}
		if ( 200 !== $answer['status'] || ! is_array( $answer['body'] ) ) {
			return $back + array( 'view' => 'down' );
		}
		$ticket = $this->row( $answer['body'] );
		$shown  = array_filter( (array) ( $answer['body']['messages'] ?? array() ), static fn ( $m ) => is_array( $m ) && in_array( $m['kind'] ?? '', self::KINDS, true ) );
		return $back + array(
			'view'     => 'thread',
			'ticket'   => $ticket,
			'messages' => array_values( array_map( array( self::class, 'message' ), $shown ) ),
		);
	}

	/**
	 * A ticket as the page shows it.
	 *
	 * @param array<string,mixed> $row OmnaBase's ticket.
	 * @return array{number:int,subject:string,status:string,updatedAt:string,url:string}
	 */
	private function row( array $row ): array {
		$number = absint( $row['number'] ?? 0 );
		return array(
			'number'    => $number,
			'subject'   => (string) ( $row['subject'] ?? '' ),
			'status'    => (string) ( $row['status'] ?? '' ),
			'updatedAt' => (string) ( $row['updatedAt'] ?? '' ),
			'url'       => $this->page->url( array( Controller::TICKET => $number ) ),
		);
	}

	/**
	 * A message as the page shows it: its words as text (an HTML-only message loses its markup).
	 *
	 * @param array<string,mixed> $m OmnaBase's message.
	 * @return array{kind:string,name:string,text:string,createdAt:string}
	 */
	private static function message( array $m ): array {
		$text = (string) ( $m['bodyText'] ?? '' );
		if ( '' === trim( $text ) && is_string( $m['bodyHtml'] ?? null ) ) {
			$text = wp_strip_all_tags( $m['bodyHtml'] );
		}
		return array( 'kind' => (string) $m['kind'], 'name' => (string) ( $m['name'] ?? '' ), 'text' => $text, 'createdAt' => (string) ( $m['createdAt'] ?? '' ) );
	}

	/**
	 * A value from the address's query, as typed ('' when missing).
	 *
	 * @param string $name The parameter.
	 */
	private static function query( string $name ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only view: what to show, never whom; each value is checked where it is used.
		return isset( $_GET[ $name ] ) && is_string( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
	}

	/**
	 * OmnaBase's cursor if it looks like one ('' otherwise).
	 *
	 * @param string $raw The value.
	 */
	private static function cursor( string $raw ): string {
		return 1 === preg_match( '#^[A-Za-z0-9_\-+/=]{1,500}$#', $raw ) ? $raw : '';
	}
}
