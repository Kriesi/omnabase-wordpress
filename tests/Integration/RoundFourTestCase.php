<?php
/**
 * THE PLUGIN'S FORMS ROUND (2026-10-25), THE BUILDER: the Classic form's helpers dropped, as the red team's report asks once the Classic tests
 * are gone (the time token, the form route, the submissions, the project's form settings, their fake answers); no assertion lived here. The
 * trap field's name stays for the message centre's test, the visitor's address for the requests.
 *
 * THE PLUGIN'S FORMS ROUND (2026-10-25), THE RED TEAM: ADDED TO, NOTHING WEAKENED. What changed and why: the shortcode now shows a form built in
 * OmnaBase through the embed (OmnaBase `docs/briefs/plugin-forms-round.md`; report `docs/reviews/plugin-forms-round-red-team-report.md`), so a
 * render asks OmnaBase for the workspace's forms (`GET /forms`) and, for a signed-in visitor, for a prefill token (`POST /forms/prefill`). The
 * fake answers both from `set_up()` on (Enfold's default form "support"), before round five's catch-all answers, so every test that renders
 * `[omnabase_form]` (the first-display mark, the look's marks, the message-centre tests' neighbours) keeps rendering. The Classic answers below
 * stay until the builder deletes the Classic tests; no assertion of this file changed.
 *
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

abstract class RoundFourTestCase extends RoundTwoTestCase {

	protected const HONEYPOT    = 'omnabase_hp';
	protected const VISITOR_IP  = '203.0.113.10';
	/** The plugin's forms round: the workspace's one live form in the fake list, Enfold's default form (`defaultFor`, finding 6). */
	protected const SUPPORT_FORM = array( 'slug' => 'support', 'name' => 'Support', 'defaultFor' => array( 'enfold' ) );

	/** @var array<int,array{slug:string,name:string,defaultFor:string[]}> the forms `GET /forms` answers (the plugin's forms round) */
	protected array $liveForms = array();


	public function set_up(): void {
		parent::set_up();
		$this->connectedWith( array( array( 'id' => self::PROJECT, 'slug' => 'enfold', 'name' => 'Enfold' ) ) );
		$this->omnabaseListsForms( array( self::SUPPORT_FORM ) );
		$this->omnabaseHandsPrefillTokens();
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
	 * The plugin's forms round (the red team, 2026-10-25; an addition, no existing assertion changed): OmnaBase answers the plugin's list of
	 * forms, `GET /forms` with the site key, with these forms (`{ data: [ { slug, name, defaultFor } ] }`; `defaultFor` names the key's projects
	 * whose default form it is). Set early in `set_up()`, so it comes before round five's catch-all answers; changing it later keeps its place.
	 *
	 * @param array<int,array{slug:string,name:string,defaultFor:string[]}>|\WP_Error|array{status:int} $forms The forms, an error (OmnaBase
	 *                                                                                                   unreachable) or a fake answer.
	 */
	protected function omnabaseListsForms( $forms ): void {
		if ( is_array( $forms ) && ! isset( $forms['response'] ) ) {
			$this->liveForms                            = array_values( $forms );
			$this->answers['GET /api/public/v1/forms'] = fn () => self::answer( 200, array( 'data' => $this->liveForms ) );
			return;
		}
		$this->answers['GET /api/public/v1/forms'] = $forms;
	}

	/**
	 * The plugin's forms round: OmnaBase hands a prefill token for every `POST /forms/prefill` (201 `{ token, expiresAt }`), one token per
	 * address it vouches for (`tok-<hash of the address>`), so a test can tell whose token reached a page; or this answer instead.
	 *
	 * @param array|\WP_Error|null $answer A fake answer or an error; null for the token per address.
	 */
	protected function omnabaseHandsPrefillTokens( $answer = null ): void {
		$this->answers['POST /api/public/v1/forms/prefill'] = $answer ?? fn ( $args ) => self::answer( 201, array( 'token' => self::prefillTokenFor( (string) ( self::bodyOf( array( 'args' => (array) $args ) )['values']['email'] ?? '' ) ), 'expiresAt' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 ) ) );
	}

	/** The token the fake hands for an address. */
	protected static function prefillTokenFor( string $email ): string {
		return 'tok-' . substr( md5( strtolower( $email ) ), 0, 16 ) . '.sig';
	}

	/** The plugin's calls for its list of forms. */
	protected function formListCalls(): array {
		return $this->callsTo( 'GET', '/api/public/v1/forms' );
	}

	/** The plugin's calls for a prefill token. */
	protected function prefillCalls(): array {
		return $this->callsTo( 'POST', '/api/public/v1/forms/prefill' );
	}

	/** OmnaBase's refusal body. */
	protected static function refusal( int $status, string $code, string $message, array $headers = array() ): array {
		$a            = self::answer( $status, array( 'error' => array( 'code' => $code, 'message' => $message ) ) );
		$a['headers'] = $headers + $a['headers'];
		return $a;
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
}
