<?php
/**
 * M13 round five, the red team's integration helpers (inside wp-env's tests WordPress, the plain run): a connected site with one project, the
 * message centre's page picked by the administrator, signed-in customers (Ada and Ben) and visitors looking at it, the page's form posts
 * (New ticket, Reply, "Show my email conversations") sent the way a browser sends them, and OmnaBase's side faked at the HTTP layer (round
 * one's `pre_http_request` fake): the site's users (`PUT /customers/by-external/{id}`) and the five portal routes, each visitor's tickets kept
 * by the `OmnaBase-Customer` header exactly as OmnaBase keeps them, strict about what a request may carry (OmnaBase refuses unknown fields
 * with 400), and answering Q5's rule (a different address answers `confirmed: false`).
 *
 * The words: *message centre*, the page where a signed-in customer reads and answers their own tickets; *shortcode*, a tag like
 * `[omnabase_messages]` that WordPress replaces with the plugin's output; *site id*, the plugin's id for a WordPress user as OmnaBase knows
 * them (`<user id>-<8 random characters>`, round two); *confirm prompt*, the box asking the visitor to prove their address through a link
 * OmnaBase mails; *nonce*, a short-lived form token against forged requests; *hook*, a named moment where a plugin's code runs
 * (`send_headers`: just before WordPress sends the page's headers; `template_redirect`: before the page is built, where form posts are handled);
 * *usermeta*, extra fields stored per user; *outbox*, the plugin's lasting list of calls still to make.
 *
 * Shapes the red team pins here (chosen where the brief names none; the builder may rename and tell the red team; every name sits here):
 * - The shortcode `[omnabase_messages]` (the brief's), with `guest="form"` for the logged-out form (the brief's). A thread is
 *   `?omnabase_ticket=<number>` on the picked page (the brief's). Paging follows the link the page prints (its parameter is not pinned).
 * - The picked page is the option `omnabase_portal` = `[ 'page' => <page id> ]` (chosen), on the settings screen a section
 *   `.ob-wp-settings__portal` with a select `omnabase_portal[page]`, saved through `admin-post.php` with the action `omnabase_save_portal` and
 *   its nonce of the same name (round three's pattern).
 * - The page's forms post back to the picked page itself (the brief's "handled at `template_redirect` and redirected back"): the helper reads
 *   the rendered form and sends every field it holds, as a browser does, so hidden fields and the nonce need no names here. Named: the New
 *   ticket form's `subject` input and its message textarea, the honeypot `omnabase_hp` (round four's name), the reply form's one textarea, the
 *   confirm form by its button "Show my email conversations" (the brief's words; "Send me the link" is read too).
 * - Calls to OmnaBase: `PUT /customers/by-external/<site id> { email, name }`, then the portal's routes with the header `OmnaBase-Customer:
 *   <site id>` (`docs/public-api.md`, "The message centre"); New ticket `POST /portal/tickets { subject, message, project? }` with an
 *   `Idempotency-Key`; Reply `POST /portal/tickets/{n}/messages { body }`; `POST /portal/confirm {}`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

abstract class RoundFiveTestCase extends RoundFourTestCase {

	protected const PORTAL_OPTION = 'omnabase_portal';
	protected const SAVE_PORTAL   = 'omnabase_save_portal';
	protected const TICKET_QUERY  = 'omnabase_ticket';
	protected const PORTAL_HEADER = 'OmnaBase-Customer';
	protected const CONFIRM_BUTTON = "contains(translate(normalize-space(.),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'show my email conversations') or contains(translate(normalize-space(.),'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'send me the link')";
	protected const ADA_EMAIL = 'ada@example.com';
	protected const BEN_EMAIL = 'ben@example.com';

	protected int $picked = 0;
	protected int $ada    = 0;
	protected int $ben    = 0;
	/** @var array<string,array{known:bool,email:string,name:?string,confirmed:bool}> OmnaBase's site users, by site id */
	protected array $visitors = array();
	/** @var array<string,array<int,array<string,mixed>>> each visitor's tickets, by site id and number */
	protected array $tickets = array();
	/** @var array<string,array> a refusal OmnaBase answers instead: `sync`, `list`, `read`, `create`, `reply`, `confirm` */
	protected array $refusals = array();
	/** the most rows OmnaBase answers on one page of the list (its own limit is 100) */
	protected int $pageSize = 50;
	/** whether OmnaBase holds conversations an unconfirmed visitor's address would add (its list's `hiddenConversations`, 2026-10-25) */
	protected bool $hiddenHistory = true;
	protected int $nextNumber = 3001;
	/** @var array<string,int> new tickets by Idempotency-Key (OmnaBase's replay) */
	protected array $portalReplays = array();

	public function set_up(): void {
		parent::set_up();
		remove_action( 'template_redirect', 'redirect_canonical' ); // the test site's https address is never the request's own
		add_filter( 'show_admin_bar', '__return_false' ); // the toolbar is WordPress's, not the page's (and trips over the reused-number user)
		$this->visitors      = array();
		$this->tickets       = array();
		$this->refusals      = array();
		$this->pageSize      = 50;
		$this->hiddenHistory = true;
		$this->nextNumber    = 3001;
		$this->portalReplays = array();
		$this->picked        = $this->page( 'Support messages', '[omnabase_messages]' );
		update_option( self::PORTAL_OPTION, array( 'page' => $this->picked ) );
		$this->ada = $this->person( self::ADA_EMAIL, 'Ada Lovelace' );
		$this->ben = $this->person( self::BEN_EMAIL, 'Ben Weber' );
		foreach ( array( 'GET', 'POST', 'PUT' ) as $method ) {
			$this->answers[ $method . ' ' ] = fn ( $args, $url ) => $this->omnabase( $method, (array) $args, (string) $url );
		}
		$this->calls = array();
	}

	public function tear_down(): void {
		$_COOKIE = array();
		unset( $_SERVER['HTTP_OMNABASE_CUSTOMER'] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		parent::tear_down();
	}

	/* ---------- the site ---------- */

	/** A published page. */
	protected function page( string $title, string $content ): int {
		return (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title . ' ' . wp_generate_password( 6, false ), 'post_content' => $content ) );
	}

	/** A customer of the site (a subscriber: the plain run has no shop roles). */
	protected function person( string $email, string $name ): int {
		return (int) self::factory()->user->create( array( 'user_email' => $email, 'display_name' => $name, 'first_name' => strtok( $name, ' ' ), 'role' => 'subscriber' ) );
	}

	/* ---------- OmnaBase's side ---------- */

	/**
	 * OmnaBase knows the user (an earlier view told it), confirmed or not, with the address it was told.
	 *
	 * @param int         $user      The user.
	 * @param bool        $confirmed Whether the visitor opened the mailed link.
	 * @param string|null $email     The address OmnaBase holds (the user's own by default).
	 */
	protected function omnabaseKnows( int $user, bool $confirmed, ?string $email = null ): void {
		$this->visitors[ self::siteIdOf( $user ) ] = array( 'known' => true, 'email' => strtolower( $email ?? (string) get_userdata( $user )->user_email ), 'name' => null, 'confirmed' => $confirmed );
	}

	/** The visitor opened the link OmnaBase mailed (OmnaBase's side changes; the site is not told). */
	protected function linkOpened( int $user ): void {
		$id = self::siteIdOf( $user );
		$this->visitors[ $id ]['confirmed'] = true;
	}

	/**
	 * A ticket the visitor sees in OmnaBase's message centre.
	 *
	 * @param int                $user     The visitor.
	 * @param string             $subject  Its subject.
	 * @param array<int,array>   $messages Its messages (`kind`, `name`, `bodyText`, `bodyHtml`).
	 * @param array<string,mixed> $more    Other fields (status, updatedAt, channel).
	 */
	protected function ticketFor( int $user, string $subject, array $messages = array(), array $more = array() ): int {
		$n  = $this->nextNumber++;
		$at = gmdate( 'Y-m-d\TH:i:s\Z', time() - ( 4000 - $n ) * 60 );
		$this->tickets[ self::siteIdOf( $user ) ][ $n ] = array_merge(
			array( 'number' => $n, 'subject' => $subject, 'status' => 'open', 'channel' => 'api', 'createdAt' => $at, 'updatedAt' => $at ),
			$more,
			array(
				'messages' => array_map(
					static fn ( $m, $i ) => array_merge( array( 'id' => 'm-' . $n . '-' . $i, 'kind' => 'you', 'name' => 'You', 'bodyText' => '', 'bodyHtml' => null, 'createdAt' => $at ), $m ),
					$messages,
					array_keys( $messages )
				),
			)
		);
		return $n;
	}

	/**
	 * Answers every call to OmnaBase the message centre makes; anything else is not faked.
	 *
	 * @param string $method The method.
	 * @param array  $args   The request.
	 * @param string $url    The address.
	 * @return array|\WP_Error
	 */
	protected function omnabase( string $method, array $args, string $url ) {
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$at    = strpos( $path, '/api/public/v1' );
		$route = false === $at ? $path : substr( $path, $at + strlen( '/api/public/v1' ) );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
		$body    = self::bodyOf( array( 'args' => $args ) );
		$visitor = self::headerOf( $args, self::PORTAL_HEADER );
		if ( 'PUT' === $method && preg_match( '#^/customers/by-external/([^/]+)$#', $route, $m ) ) {
			return $this->refusals['sync'] ?? $this->sync( rawurldecode( $m[1] ), $body );
		}
		if ( 'POST' === $method && preg_match( '#^/customers/by-external/[^/]+/refresh$#', $route ) ) {
			return self::answer( 200, array( 'ok' => true, 'licences' => 0 ) );
		}
		if ( ! str_starts_with( $route, '/portal/' ) ) {
			return new \WP_Error( 'http_request_failed', 'OmnaBase is not faked for ' . $method . ' ' . $route );
		}
		if ( '' === $visitor ) {
			return self::refusal( 400, 'bad_request', 'Name the visitor with the OmnaBase-Customer header (the site\'s own user id)' );
		}
		if ( empty( $this->visitors[ $visitor ]['known'] ) ) {
			return self::refusal( 404, 'not_found', 'No such visitor: tell us about the site\'s user first (PUT /customers/by-external/{id})' );
		}
		return $this->portal( $method, $route, $visitor, $query, $body, $args );
	}

	/** `PUT /customers/by-external/{id}`: strict body; a new user 201, a known one 200; another address answers `confirmed: false` (Q5). */
	private function sync( string $id, array $body ): array {
		$extra = array_diff( array_keys( $body ), array( 'email', 'name', 'company', 'country', 'language' ) );
		if ( $extra || ! is_email( (string) ( $body['email'] ?? '' ) ) ) {
			return self::refusal( 400, 'bad_request', 'The request did not match its schema' );
		}
		$known     = $this->visitors[ $id ] ?? null;
		$email     = strtolower( trim( (string) $body['email'] ) );
		$confirmed = (bool) ( $known['confirmed'] ?? false );
		if ( $known && $known['email'] !== $email ) {
			$confirmed = false;
		}
		$this->visitors[ $id ] = array( 'known' => true, 'email' => $email, 'name' => $body['name'] ?? null, 'confirmed' => $confirmed );
		return self::answer( $known ? 200 : 201, array( 'externalId' => $id, 'confirmed' => $confirmed, 'customer' => null ) );
	}

	/** The five portal routes for a known visitor. */
	private function portal( string $method, string $route, string $visitor, array $query, array $body, array $args ): array {
		$mine = $this->tickets[ $visitor ] ?? array();
		if ( 'GET' === $method && '/portal/tickets' === $route ) {
			return $this->refusals['list'] ?? $this->listFor( $mine, $query, $this->hiddenHistory && ! ( $this->visitors[ $visitor ]['confirmed'] ?? false ) );
		}
		if ( 'GET' === $method && preg_match( '#^/portal/tickets/(\d+)$#', $route, $m ) ) {
			return $this->refusals['read'] ?? ( isset( $mine[ (int) $m[1] ] ) ? self::answer( 200, $mine[ (int) $m[1] ] ) : self::refusal( 404, 'not_found', 'No such ticket' ) );
		}
		if ( 'POST' === $method && '/portal/tickets' === $route ) {
			return $this->refusals['create'] ?? $this->create( $visitor, $body, self::headerOf( $args, 'Idempotency-Key' ) );
		}
		if ( 'POST' === $method && preg_match( '#^/portal/tickets/(\d+)/messages$#', $route, $m ) ) {
			return $this->refusals['reply'] ?? $this->reply( $visitor, (int) $m[1], $body );
		}
		if ( 'POST' === $method && '/portal/confirm' === $route ) {
			return $this->refusals['confirm'] ?? self::answer( 200, array( 'sent' => true, 'expiresInHours' => 24 ) );
		}
		return self::refusal( 404, 'not_found', 'No such route' );
	}

	/** `GET /portal/tickets?after=&limit=`: strict query, newest change first, a page at a time, and whether confirming would show more. */
	private function listFor( array $mine, array $query, bool $hidden ): array {
		$limit = (int) ( $query['limit'] ?? 50 );
		if ( array_diff( array_keys( $query ), array( 'after', 'limit' ) ) || $limit < 1 || $limit > 100 ) {
			return self::refusal( 400, 'bad_request', 'The request did not match its schema' );
		}
		$rows = array_values( array_map( static fn ( $t ) => array_diff_key( $t, array( 'messages' => 1 ) ), $mine ) );
		usort( $rows, static fn ( $a, $b ) => strcmp( $b['updatedAt'], $a['updatedAt'] ) ?: $b['number'] <=> $a['number'] );
		$from = isset( $query['after'] ) ? (int) base64_decode( (string) $query['after'] ) : 0; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		$size = min( $limit, $this->pageSize );
		$page = array_slice( $rows, $from, $size );
		$next = count( $rows ) > $from + $size ? base64_encode( (string) ( $from + $size ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return self::answer( 200, array( 'data' => $page, 'next' => $next, 'hiddenConversations' => $hidden ) );
	}

	/** `POST /portal/tickets`: strict body; 201 with the new ticket; the same Idempotency-Key answers the same ticket. */
	private function create( string $visitor, array $body, string $key ): array {
		if ( array_diff( array_keys( $body ), array( 'project', 'subject', 'message' ) ) || '' === trim( (string) ( $body['subject'] ?? '' ) ) || '' === trim( (string) ( $body['message'] ?? '' ) ) ) {
			return self::refusal( 400, 'bad_request', 'The request did not match its schema' );
		}
		if ( '' !== $key && isset( $this->portalReplays[ $key ] ) ) {
			return self::answer( 201, $this->tickets[ $visitor ][ $this->portalReplays[ $key ] ] );
		}
		$n = $this->nextNumber++;
		$this->tickets[ $visitor ][ $n ] = array(
			'number'    => $n,
			'subject'   => (string) $body['subject'],
			'status'    => 'open',
			'channel'   => 'api',
			'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'updatedAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'messages'  => array( array( 'id' => 'm-' . $n, 'kind' => 'you', 'name' => 'You', 'bodyText' => (string) $body['message'], 'bodyHtml' => null, 'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) ),
		);
		if ( '' !== $key ) {
			$this->portalReplays[ $key ] = $n;
		}
		return self::answer( 201, $this->tickets[ $visitor ][ $n ] );
	}

	/** `POST /portal/tickets/{n}/messages`: strict body; the visitor's own ticket only (another's reads like a missing one); it reopens. */
	private function reply( string $visitor, int $number, array $body ): array {
		if ( ! isset( $this->tickets[ $visitor ][ $number ] ) ) {
			return self::refusal( 404, 'not_found', 'No such ticket' );
		}
		if ( array_diff( array_keys( $body ), array( 'body' ) ) || '' === trim( (string) ( $body['body'] ?? '' ) ) ) {
			return self::refusal( 400, 'bad_request', 'The request did not match its schema' );
		}
		$message = array( 'id' => 'm-' . $number . '-' . wp_generate_password( 6, false ), 'kind' => 'you', 'name' => 'You', 'bodyText' => (string) $body['body'], 'bodyHtml' => null, 'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ) );
		$this->tickets[ $visitor ][ $number ]['messages'][] = $message;
		$this->tickets[ $visitor ][ $number ]['status']     = 'open';
		$this->tickets[ $visitor ][ $number ]['updatedAt']  = gmdate( 'Y-m-d\TH:i:s\Z' );
		return self::answer( 201, $message );
	}

	/** OmnaBase's `409 conflict` with `details.reason` (`address_taken`, `forgotten`), as its error body carries it. */
	protected static function conflict( string $reason, string $message ): array {
		return self::answer( 409, array( 'error' => array( 'code' => 'conflict', 'message' => $message, 'details' => array( 'reason' => $reason ) ) ) );
	}

	/* ---------- what reached OmnaBase ---------- */

	/** The portal calls, optionally only those to one route ending (e.g. `/portal/confirm`). */
	protected function portalCalls( string $method = '', string $suffix = '' ): array {
		return array_values(
			array_filter(
				$this->calls,
				static function ( $c ) use ( $method, $suffix ) {
					$path = (string) wp_parse_url( $c['url'], PHP_URL_PATH );
					return str_contains( $path, '/api/public/v1/portal/' ) && ( '' === $method || $method === $c['method'] ) && ( '' === $suffix || str_ends_with( $path, $suffix ) );
				}
			)
		);
	}

	/** The `PUT /customers/by-external/…` calls for a user (by their site id). */
	protected function syncCalls( int $user ): array {
		return $this->callsFor( $user, 'PUT', false );
	}

	/** The visitor every portal call named (the `OmnaBase-Customer` headers, distinct). */
	protected function namedVisitors(): array {
		return array_values( array_unique( array_map( static fn ( $c ) => self::headerOf( (array) $c['args'], self::PORTAL_HEADER ), $this->portalCalls() ) ) );
	}

	/* ---------- the page ---------- */

	/**
	 * The page as someone sees it: the request for the page (which runs WordPress's `send_headers`), `template_redirect`, then the content.
	 *
	 * @param int                 $user  The user (0: nobody signed in).
	 * @param array<string,mixed> $query The address's query.
	 * @param int                 $page  The page (the picked one by default).
	 */
	protected function view( int $user, array $query = array(), int $page = 0 ): string {
		$page = $page ?: $this->picked;
		$this->asUserOnTheFront( $user );
		$this->go_to( add_query_arg( array_map( 'rawurlencode', $query ), get_permalink( $page ) ) );
		try {
			do_action( 'template_redirect' );
		} catch ( Redirected $r ) {
			$this->fail( 'a plain view redirected to ' . $r->location );
		}
		return (string) apply_filters( 'the_content', get_post( $page )->post_content );
	}

	/**
	 * A POST to the page, handled at `template_redirect`; where it redirected, or null.
	 *
	 * @param int                 $user   The user.
	 * @param array<string,mixed> $fields The posted fields.
	 * @param string              $url    The address posted to (the picked page by default).
	 */
	protected function postTo( int $user, array $fields, string $url = '' ): ?string {
		$this->asUserOnTheFront( $user );
		$this->go_to( '' !== $url ? $url : get_permalink( $this->picked ) );
		$_POST                     = $fields;
		$_REQUEST                  = array_merge( $_GET, $fields ); // phpcs:ignore WordPress.Security.NonceVerification
		$_SERVER['REQUEST_METHOD'] = 'POST';
		try {
			do_action( 'template_redirect' );
		} catch ( Redirected $r ) {
			return $r->location;
		} catch ( \WPDieException $e ) {
			return null;
		} finally {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_POST                     = array();
			$_REQUEST                  = array();
		}
		return null;
	}

	/**
	 * The first form in some HTML matching an XPath condition: where it posts and every field it would send.
	 *
	 * @param string $html      The page.
	 * @param string $condition An XPath condition on the form, e.g. `.//input[@name='subject']`.
	 * @return array{action:string,fields:array<string,string>}|null
	 */
	protected static function formIn( string $html, string $condition ): ?array {
		$forms = self::nodes( $html, "//form[{$condition}]" );
		if ( 0 === $forms->length ) {
			return null;
		}
		$form   = $forms->item( 0 );
		$fields = array();
		foreach ( ( new \DOMXPath( $form->ownerDocument ) )->query( './/input | .//textarea | .//select | .//button[@name]', $form ) as $el ) {
			$name = (string) $el->getAttribute( 'name' );
			$type = strtolower( (string) $el->getAttribute( 'type' ) );
			if ( '' === $name || ( in_array( $type, array( 'checkbox', 'radio' ), true ) && ! $el->hasAttribute( 'checked' ) ) ) {
				continue;
			}
			$fields[ $name ] = 'textarea' === $el->nodeName ? (string) $el->textContent : (string) $el->getAttribute( 'value' );
		}
		return array( 'action' => (string) $form->getAttribute( 'action' ), 'fields' => $fields );
	}

	/**
	 * Sends a form found on the page as a browser does (its fields, some filled in), then follows the redirect back.
	 *
	 * @param int                  $user      The user.
	 * @param string               $html      The page holding the form.
	 * @param string               $condition Which form (an XPath condition).
	 * @param array<string,string> $fill      Fields to fill by name; `textarea` fills the form's only textarea.
	 * @return array{location:?string,html:string}
	 */
	protected function send( int $user, string $html, string $condition, array $fill = array() ): array {
		$form = self::formIn( $html, $condition );
		$this->assertNotNull( $form, 'the form [' . $condition . '] is on the page' );
		$fields = $form['fields'];
		if ( isset( $fill['textarea'] ) ) {
			$areas = self::nodes( $html, "//form[{$condition}]//textarea" );
			$this->assertSame( 1, $areas->length, 'the form has one text box' );
			$fields[ (string) $areas->item( 0 )->getAttribute( 'name' ) ] = $fill['textarea'];
			unset( $fill['textarea'] );
		}
		$action = '' === $form['action'] ? (string) get_permalink( $this->picked ) : html_entity_decode( $form['action'] );
		$this->assertSame( (string) wp_parse_url( (string) get_permalink( $this->picked ), PHP_URL_PATH ), (string) wp_parse_url( $action, PHP_URL_PATH ), 'the form posts back to the message centre\'s page (handled before the page is built)' );
		$location = $this->postTo( $user, array_merge( $fields, $fill ), $action );
		return array( 'location' => $location, 'html' => null === $location ? '' : $this->follow( $user, $location ) );
	}

	/** The page a redirect leads to, which must be the message centre's page on this site. */
	protected function follow( int $user, string $location ): string {
		$this->assertSame( (string) wp_parse_url( self::SITE, PHP_URL_HOST ), (string) wp_parse_url( $location, PHP_URL_HOST ), 'redirected on this site' );
		$this->assertSame( (string) wp_parse_url( (string) get_permalink( $this->picked ), PHP_URL_PATH ), (string) wp_parse_url( $location, PHP_URL_PATH ), 'redirected back to the message centre' );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		return $this->view( $user, $query );
	}

	/** The page's text, without tags. */
	protected static function text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( '<', ' <', $html ) ) ) );
	}

	/** Whether the confirm prompt is on the page. */
	protected static function hasPrompt( string $html ): bool {
		return null !== self::formIn( $html, './/button[' . self::CONFIRM_BUTTON . "] or .//input[@type='submit'][" . str_replace( 'normalize-space(.)', 'normalize-space(@value)', self::CONFIRM_BUTTON ) . ']' );
	}

	/** The confirm prompt's form condition (for `send`). */
	protected static function promptForm(): string {
		return './/button[' . self::CONFIRM_BUTTON . "] or .//input[@type='submit'][" . str_replace( 'normalize-space(.)', 'normalize-space(@value)', self::CONFIRM_BUTTON ) . ']';
	}

	/** The New ticket form's condition. */
	protected static function newTicketForm(): string {
		return ".//input[@name='subject'] and .//textarea";
	}

	/** The reply form's condition (a text box, no subject). */
	protected static function replyForm(): string {
		return ".//textarea and not(.//input[@name='subject'])";
	}
}
