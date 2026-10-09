<?php
/**
 * M13 round four, the red team's integration helpers (inside wp-env's tests WordPress, the plain run): a connected site, the shortcode form
 * rendered on the front end as a visitor, a signed-in customer or an administrator sees it, the time-token route and the form route called the
 * way the form's script calls them, OmnaBase faked at the HTTP layer (round one's `pre_http_request` fake) answering the project's form
 * settings and the submissions with ticket numbers (a resend with the same Idempotency-Key answers the same number, as OmnaBase's replay
 * does), and the outbox (round two's helpers).
 *
 * The words: *shortcode*, a tag like `[omnabase_form]` that WordPress replaces with the plugin's output; *time token*, a one-time ticket the
 * form's script fetches when the page loads, without which a submission is refused; *honeypot*, a field people never see and bots fill in;
 * *idempotency key*, a one-time id that makes a resend count once; *REST route*, an address under `/wp-json/…` a program calls; *nonce*, a
 * short-lived form token against forged requests; *outbox*, the plugin's lasting list of calls still to make.
 *
 * Shapes the red team pins here (chosen where the brief names none; the builder may rename and tell the red team; every name sits here):
 * - The shortcode `[omnabase_form]` with the attributes `project` (a project's slug) and `subject` (the brief's names). The form's fields by
 *   name: `name`, `email`, `subject`, `message` (a textarea), `product` (a select), `licence`; the honeypot `omnabase_hp`; the project in a
 *   hidden field `project` or the form's `data-project`.
 * - The time-token route `omnabase/v1/form-token` (the coordinator's name; GET or POST, whichever the plugin registers) takes `project` and
 *   answers `{ "token": "…" }`. The form route `omnabase/v1/form` (POST, the brief's name) takes the fields above plus `project` and `token`
 *   and answers JSON whose `message` is the sentence the visitor reads; a field's own error sits under the field's name somewhere in the
 *   answer.
 * - The time a token was handed out is kept on the site (a transient or an option under a name holding "omnabase"), so the helper can age
 *   it: the brief's "older than 3 seconds, younger than 2 hours" is tested by moving that time back, never by sleeping.
 * - The filter for a site behind a proxy is `omnabase_visitor_address` (the visitor's address in, the address to count out).
 * - The project's form settings come from `GET /api/public/v1/forms/{slug}` in the brief's shape `{ products, askLicense, privacyUrl }`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Options;

abstract class RoundFourTestCase extends RoundTwoTestCase {

	protected const TOKEN_ROUTE = '/omnabase/v1/form-token';
	protected const FORM_ROUTE  = '/omnabase/v1/form';
	protected const HONEYPOT    = 'omnabase_hp';
	protected const VISITOR_IP  = '203.0.113.10';
	protected const PRIVACY     = 'https://kriesi.example/privacy';
	protected const SETTINGS    = array(
		'products'   => array( 'Enfold', 'Enfold Pro' ),
		'askLicense' => true,
		'privacyUrl' => self::PRIVACY,
	);
	protected const FIRST_REFERENCE = 2001;

	/** the next ticket number the fake hands out */
	protected int $nextReference = self::FIRST_REFERENCE;
	/** @var array<string,int> the numbers handed out by Idempotency-Key (OmnaBase's replay) */
	protected array $replays = array();

	public function set_up(): void {
		parent::set_up();
		$this->connectedWith( array( array( 'id' => self::PROJECT, 'slug' => 'enfold', 'name' => 'Enfold' ) ) );
		$this->nextReference = self::FIRST_REFERENCE;
		$this->replays       = array();
		$this->formSettings( self::SETTINGS );
		$this->omnabaseTakesSubmissions();
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- a fresh script queue per test
		$GLOBALS['wp_styles']  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$_SERVER['REMOTE_ADDR'] = self::VISITOR_IP;
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$this->asVisitor();
		$this->calls = array();
	}

	public function tear_down(): void {
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		parent::tear_down();
	}

	/* ---------- the connection and OmnaBase's answers ---------- */

	/**
	 * The connection's record with these projects (round one's `omnabase_site`).
	 *
	 * @param array<int,array{id:string,slug:string,name:string}> $projects The key's projects.
	 */
	protected function connectedWith( array $projects ): void {
		update_option(
			'omnabase_site',
			array(
				'workspace'   => array( 'name' => 'Team Kriesi', 'slug' => 'kriesi' ),
				'site'        => array( 'name' => 'Kriesi Shop', 'url' => self::SITE ),
				'scopes'      => self::SCOPES,
				'projects'    => $projects,
				'events'      => self::EVENTS,
				'connectedAt' => time(),
			),
			false
		);
	}

	/**
	 * OmnaBase answers `GET /forms/{slug}` with these settings (or the given error).
	 *
	 * @param array<string,mixed>|\WP_Error $settings The answer.
	 * @param string                       $slug     The project.
	 */
	protected function formSettings( $settings, string $slug = 'enfold' ): void {
		$this->answers[ 'GET /api/public/v1/forms/' . $slug ] = is_wp_error( $settings ) ? $settings : self::answer( 200, $settings );
	}

	/** OmnaBase files submissions to any project: 201 with the next number, the same number again for a resend with the same key. */
	protected function omnabaseTakesSubmissions(): void {
		$this->answers['POST /submissions'] = function ( $args ) {
			$key = self::headerOf( (array) $args, 'Idempotency-Key' );
			if ( '' !== $key && isset( $this->replays[ $key ] ) ) {
				return self::answer( 201, array( 'ok' => true, 'reference' => $this->replays[ $key ] ) );
			}
			$n = $this->nextReference++;
			if ( '' !== $key ) {
				$this->replays[ $key ] = $n;
			}
			return self::answer( 201, array( 'ok' => true, 'reference' => $n ) );
		};
	}

	/**
	 * OmnaBase answers every submission with this (a status and body, or a network error).
	 *
	 * @param array|\WP_Error $answer The fake answer.
	 */
	protected function omnabaseAnswersSubmissions( $answer ): void {
		$this->answers['POST /submissions'] = $answer;
	}

	/** OmnaBase's refusal body. */
	protected static function refusal( int $status, string $code, string $message, array $headers = array() ): array {
		$a            = self::answer( $status, array( 'error' => array( 'code' => $code, 'message' => $message ) ) );
		$a['headers'] = $headers + $a['headers'];
		return $a;
	}

	/** The submissions the plugin sent to OmnaBase. */
	protected function submissionCalls( string $slug = 'enfold' ): array {
		return $this->callsTo( 'POST', '/api/public/v1/forms/' . $slug . '/submissions' );
	}

	/** The settings questions the plugin sent to OmnaBase. */
	protected function settingsCalls( string $slug = 'enfold' ): array {
		return $this->callsTo( 'GET', '/api/public/v1/forms/' . $slug );
	}

	/** A request header's value, whatever its case. */
	protected static function headerOf( array $args, string $name ): string {
		foreach ( (array) ( $args['headers'] ?? array() ) as $k => $v ) {
			if ( strtolower( (string) $k ) === strtolower( $name ) ) {
				return (string) $v;
			}
		}
		return '';
	}

	/** A recorded call's JSON body. */
	protected static function bodyOf( array $call ): array {
		$body = json_decode( (string) ( $call['args']['body'] ?? '' ), true );
		return is_array( $body ) ? $body : array();
	}

	/* ---------- who looks at the page ---------- */

	/** Nobody signed in, on the front end. */
	protected function asVisitor(): void {
		wp_set_current_user( 0 );
		set_current_screen( 'front' );
	}

	/** A signed-in user, on the front end. */
	protected function asUserOnTheFront( int $user ): void {
		wp_set_current_user( $user );
		set_current_screen( 'front' );
	}

	/* ---------- the shortcode ---------- */

	/**
	 * The shortcode's output.
	 *
	 * @param string $atts The attributes as written in the post, e.g. `project="enfold"`.
	 */
	protected function render( string $atts = '' ): string {
		return (string) do_shortcode( '[omnabase_form' . ( '' === $atts ? '' : ' ' . $atts ) . ']' );
	}

	/** Every script and stylesheet queued so far, as the page prints them (with their inline data). */
	protected function pageAssets(): string {
		return get_echo( array( wp_scripts(), 'do_items' ) ) . get_echo( array( wp_styles(), 'do_items' ) );
	}

	/** Elements matching an XPath query in some HTML. */
	protected static function nodes( string $html, string $xpath ): \DOMNodeList {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		return ( new \DOMXPath( $doc ) )->query( $xpath );
	}

	/** The texts of the matching elements. */
	protected static function texts( string $html, string $xpath ): array {
		$out = array();
		foreach ( self::nodes( $html, $xpath ) as $n ) {
			$out[] = trim( (string) $n->textContent );
		}
		return $out;
	}

	/* ---------- the time token ---------- */

	/**
	 * The time-token route's answer, called as the form's script calls it.
	 *
	 * @param string $project The project's slug.
	 */
	protected function tokenResponse( string $project = 'enfold' ): \WP_REST_Response {
		$server = $this->rest();
		$routes = $server->get_routes();
		$this->assertArrayHasKey( self::TOKEN_ROUTE, $routes, 'the time-token route omnabase/v1/form-token is registered' );
		$methods = array();
		foreach ( $routes[ self::TOKEN_ROUTE ] as $handler ) {
			$methods = array_merge( $methods, array_keys( array_filter( (array) ( $handler['methods'] ?? array() ) ) ) );
		}
		$method  = in_array( 'POST', $methods, true ) ? 'POST' : 'GET';
		$request = new \WP_REST_Request( $method, self::TOKEN_ROUTE );
		$request->set_param( 'project', $project );
		return rest_ensure_response( $server->dispatch( $request ) );
	}

	/**
	 * A fresh token for the project; `$aged` moves its time back past the brief's 3 seconds, as a person filling the form would.
	 *
	 * @param string $project The project's slug.
	 * @param bool   $aged    Whether it is old enough to send with.
	 */
	protected function token( string $project = 'enfold', bool $aged = true ): string {
		$r = $this->tokenResponse( $project );
		$this->assertSame( 200, $r->get_status(), 'the time token: ' . wp_json_encode( $r->get_data() ) );
		$token = (string) ( ( (array) $r->get_data() )['token'] ?? '' );
		$this->assertNotSame( '', $token, 'the answer carries the token' );
		if ( $aged ) {
			$this->ageFormState( 5 );
		}
		return $token;
	}

	/**
	 * Moves back every time the plugin keeps for its form (names holding "omnabase", outside round one to three's options): the tokens'
	 * handed-out times, and the transients' own expiry.
	 *
	 * @param int $seconds How far back.
	 */
	protected function ageFormState( int $seconds ): void {
		global $wpdb;
		$shifted = 0;
		$names   = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%omnabase%'" ); // phpcs:ignore WordPress.DB
		foreach ( $names as $name ) {
			if ( in_array( $name, Options::ALL, true ) || str_contains( $name, 'omnabase_notice_' ) ) {
				continue;
			}
			$value = get_option( $name );
			if ( str_contains( $name, '_transient_timeout_' ) ) {
				update_option( $name, (int) $value - $seconds, false );
				continue;
			}
			$new = self::backInTime( $value, $seconds, $shifted );
			if ( $new !== $value ) {
				update_option( $name, $new, false );
			}
		}
		wp_cache_flush();
		$this->assertGreaterThan( 0, $shifted, 'the time a token was handed out is kept on the site, under a name holding "omnabase" (a transient or an option)' );
	}

	/**
	 * Every number within a day of now, moved back.
	 *
	 * @param mixed $value   A stored value.
	 * @param int   $seconds How far back.
	 * @param int   $count   How many were moved.
	 * @return mixed
	 */
	private static function backInTime( $value, int $seconds, int &$count ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::backInTime( $v, $seconds, $count );
			}
			return $value;
		}
		$numeric = is_int( $value ) || is_float( $value ) || ( is_string( $value ) && preg_match( '/^\d{10}(\.\d+)?$/', $value ) );
		if ( $numeric && abs( (float) $value - time() ) < DAY_IN_SECONDS ) {
			++$count;
			if ( is_string( $value ) ) {
				return (string) ( (float) $value - $seconds );
			}
			return $value - $seconds;
		}
		return $value;
	}

	/* ---------- the submission ---------- */

	/**
	 * The fields a visitor sends (the honeypot empty, as a real form sends it).
	 *
	 * @param string              $token The time token.
	 * @param array<string,mixed> $more  Fields to add or replace.
	 */
	protected static function fields( string $token, array $more = array() ): array {
		return array_merge(
			array(
				'project'      => 'enfold',
				'token'        => $token,
				'name'         => 'Vera Visitor',
				'email'        => 'vera@example.org',
				'subject'      => 'The slider stopped',
				'message'      => 'Since the update the slider on my front page is gone.',
				self::HONEYPOT => '',
			),
			$more
		);
	}

	/**
	 * A POST to the form route as the form's script sends it (JSON), from a network address.
	 *
	 * @param array<string,mixed>  $fields  The fields.
	 * @param string               $ip      The visitor's address (REMOTE_ADDR).
	 * @param array<string,string> $headers Extra headers.
	 */
	protected function submit( array $fields, string $ip = self::VISITOR_IP, array $headers = array() ): \WP_REST_Response {
		$_SERVER['REMOTE_ADDR'] = $ip;
		$request                = new \WP_REST_Request( 'POST', self::FORM_ROUTE );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( (string) wp_json_encode( $fields ) );
		return rest_ensure_response( $this->rest()->dispatch( $request ) );
	}

	/** A token fetched from an address, aged, and a submission from the same address with it. */
	protected function sendFrom( string $ip, array $more = array() ): \WP_REST_Response {
		$_SERVER['REMOTE_ADDR'] = $ip;
		return $this->submit( self::fields( $this->token(), $more ), $ip );
	}

	/** The sentence the visitor reads. */
	protected static function said( \WP_REST_Response $r ): string {
		$data = (array) $r->get_data();
		return (string) ( $data['message'] ?? '' );
	}

	/** Whether the answer is a success (2xx). */
	protected static function ok( \WP_REST_Response $r ): bool {
		return $r->get_status() >= 200 && $r->get_status() < 300;
	}

	/**
	 * A field's own error, wherever the answer puts it (a key named like the field holding a sentence), or ''.
	 *
	 * @param mixed  $data  The answer's data.
	 * @param string $field The field's name.
	 */
	protected static function fieldError( $data, string $field ): string {
		foreach ( (array) $data as $k => $v ) {
			if ( $field === $k && is_string( $v ) && '' !== trim( $v ) ) {
				return $v;
			}
			if ( is_array( $v ) || is_object( $v ) ) {
				$hit = self::fieldError( (array) $v, $field );
				if ( '' !== $hit ) {
					return $hit;
				}
			}
		}
		return '';
	}

	/** The outbox rows about form submissions. */
	protected function formRows(): array {
		return array_values( array_filter( $this->outboxRows(), static fn ( $r ) => str_contains( (string) $r['kind'], 'form' ) ) );
	}
}
