<?php
/**
 * M13 round one, the red team's integration helpers (inside wp-env's tests WordPress): a site on https with pretty permalinks, an
 * administrator on Settings › OmnaBase, OmnaBase faked at the HTTP layer (WordPress's `pre_http_request` filter answers every outgoing
 * call and records it), redirects caught (the `wp_redirect` filter throws, so a handler's `exit` never runs), signed calls to the two
 * receivers, and a time shift for the pending connect.
 *
 * Shapes the red team pins here (chosen; the builder may rename and tell the red team; every name sits in this file):
 * - Admin actions through `admin-post.php`: `omnabase_connect`, `omnabase_disconnect`, `omnabase_check`, each with a nonce of the same
 *   name in `_wpnonce` and `manage_options`.
 * - The callback is the settings page itself, handled at its load hook `load-settings_page_omnabase` (before any output) from `$_GET`
 *   `code` + `state` (or `error` + `state`); the screen renders at `settings_page_omnabase`.
 * - The REST routes `omnabase/v1/events` and `omnabase/v1/customer` (POST), reading the raw body from the request (WordPress's REST request
 *   keeps the bytes as received), the headers `X-OmnaBase-Timestamp`, `X-OmnaBase-Signature`, `X-OmnaBase-Event`, `X-OmnaBase-Delivery`.
 * - `OmnaBase\WordPress\Security\Vault` as in the unit test; its salts through `wp_salt()`, so a rotation here is WordPress's `salt`
 *   filter (wp-env's config must not define OMNABASE_ENCRYPTION_KEY, or no rotation could be simulated).
 * - Everything the plugin keeps for a pending connect is stored under a name holding "omnabase" (user meta or a transient): the time
 *   shift finds it there.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Plugin;
use OmnaBase\WordPress\PluginInfo;
use OmnaBase\WordPress\Security\Vault;

abstract class RoundOneTestCase extends \WP_UnitTestCase {

	protected const SITE   = 'https://shop.example.org';
	protected const SCOPES = array( 'tickets:read', 'tickets:write', 'customers:read', 'customers:write', 'forms:write', 'portal' );
	/**
	 * Builder's adjustment (M13 round three, the coordinator's ruling; DECISIONS "M13 round three"): Connect also asks for `ticket.resolved`,
	 * the shape OmnaBase's fan-out sends a resolve in, so the forum's "close the topic when its ticket is resolved" can fire (the red team's
	 * contradiction 5). Round one's link test compares the link's events with this list.
	 */
	protected const EVENTS = array( 'message.sent', 'ticket.updated', 'ticket.resolved' );
	/** the stored values every leak check searches for (the facts secret has no prefix: review S7) */
	protected const KEY      = 'obw_k3j9x2qak3j9x2qak3j9x2qak3j9x2qak3j9x2qak3j_ab7Q2X';
	protected const WEBHOOKS = 'whsec_integrationWebhookSecret000000001';
	protected const FACTS    = 'aW50ZWdyYXRpb24tZmFjdHMtc2VjcmV0LTAwMDAwMDE';
	protected const CREDENTIALS = array( 'key' => self::KEY, 'webhooks' => self::WEBHOOKS, 'facts' => self::FACTS );

	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> the calls the plugin made to OmnaBase */
	protected array $calls = array();
	/** @var array<string,array|\WP_Error|callable> fake answers by "METHOD /path-suffix" */
	protected array $answers = array();
	protected int $admin = 0;
	/** whether this test booted the plugin's admin parts already (once per test: a second boot would register every hook twice) */
	private bool $booted = false;

	public function set_up(): void {
		parent::set_up();
		$this->booted = false;
		update_option( 'home', self::SITE );
		update_option( 'siteurl', self::SITE );
		update_option( 'blogname', 'Kriesi Shop' );
		$this->set_permalink_structure( '/%postname%/' );
		$this->calls   = array();
		$this->answers = array();
		add_filter( 'pre_http_request', array( $this, 'fakeOmnaBase' ), 10, 3 );
		add_filter( 'wp_redirect', array( $this, 'catchRedirect' ), 1, 1 );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator', 'user_email' => 'owner@shop.example.org' ) );
		$this->asAdminOnTheScreen( $this->admin );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		if ( class_exists( Vault::class ) ) {
			( new Vault() )->clear();
		}
		parent::tear_down();
	}

	/** The user, on Settings › OmnaBase, with the plugin's admin parts booted once per test (as SettingsMenuTest does). */
	protected function asAdminOnTheScreen( int $user ): void {
		wp_set_current_user( $user );
		set_current_screen( 'settings_page_omnabase' );
		$this->bootAdminOnce();
	}

	/** Boots the plugin's admin parts and builds the admin menu, once per test. */
	protected function bootAdminOnce(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		global $submenu, $_wp_submenu_nopriv, $admin_page_hooks;
		$submenu            = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$_wp_submenu_nopriv = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		$admin_page_hooks['options-general.php'] = 'settings'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- wp-admin/menu.php's entry, so the page's hooks get their real names (settings_page_omnabase)
		( new Plugin( PluginInfo::fromFile( dirname( __DIR__, 2 ) . '/omnabase.php' ) ) )->boot();
		do_action( 'admin_menu' );
	}

	/* ---------- OmnaBase, faked at the HTTP layer ---------- */

	/**
	 * Answers every outgoing call; records it.
	 *
	 * @param mixed  $pre  The short-circuit value.
	 * @param array  $args The request.
	 * @param string $url  The address.
	 * @return array|\WP_Error
	 */
	public function fakeOmnaBase( $pre, $args, $url ) {
		$method        = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$this->calls[] = array( 'method' => $method, 'url' => (string) $url, 'args' => (array) $args );
		$path          = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		foreach ( $this->answers as $route => $answer ) {
			[ $m, $suffix ] = explode( ' ', $route, 2 );
			if ( $m === $method && str_ends_with( $path, $suffix ) ) {
				return is_callable( $answer ) ? $answer( $args, $url ) : $answer;
			}
		}
		return new \WP_Error( 'http_request_failed', 'OmnaBase is not faked for ' . $method . ' ' . $path );
	}

	/**
	 * A fake HTTP answer.
	 *
	 * @param int   $status The status.
	 * @param mixed $body   Encoded as JSON unless a string.
	 */
	protected static function answer( int $status, $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => is_string( $body ) ? $body : (string) wp_json_encode( $body ),
			'response' => array( 'code' => $status, 'message' => get_status_header_desc( $status ) ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/** The calls to a path suffix. */
	protected function callsTo( string $method, string $suffix ): array {
		return array_values( array_filter( $this->calls, static fn ( $c ) => $c['method'] === $method && str_ends_with( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), $suffix ) ) );
	}

	/** The exchange's one answer, as OmnaBase gives it (`docs/public-api.md`, "Connect your site"). */
	protected static function exchangeAnswer( string $key = self::KEY, string $webhooks = self::WEBHOOKS, ?string $facts = self::FACTS ): array {
		return array(
			'key'       => $key,
			'site'      => array( 'id' => 'site-1', 'name' => 'Kriesi Shop', 'url' => self::SITE ),
			'workspace' => array( 'slug' => 'kriesi', 'name' => 'Team Kriesi' ),
			'scopes'    => self::SCOPES,
			'projects'  => array( 'project-enfold' ),
			'webhook'   => array( 'url' => self::SITE . '/wp-json/omnabase/v1/events', 'events' => self::EVENTS ),
			'facts'     => null === $facts ? null : array( 'url' => self::SITE . '/wp-json/omnabase/v1/customer' ),
			'secrets'   => array( 'webhooks' => $webhooks, 'facts' => $facts ),
		);
	}

	/** `GET /me` as OmnaBase answers it after round one (projects with their slugs). */
	protected static function meAnswer(): array {
		return array(
			'site'      => array( 'id' => 'site-1', 'name' => 'Kriesi Shop', 'url' => self::SITE ),
			'workspace' => array( 'slug' => 'kriesi', 'name' => 'Team Kriesi' ),
			'scopes'    => self::SCOPES,
			'projects'  => array( array( 'id' => 'project-enfold', 'slug' => 'enfold', 'name' => 'Enfold' ) ),
			'limits'    => array( 'perMinute' => 300, 'perDay' => 50000 ),
		);
	}

	/* ---------- the admin actions and the callback ---------- */

	/**
	 * Runs an admin-post action with its nonce; answers where it redirected (null when it did not).
	 *
	 * @param string $action      `omnabase_connect`, `omnabase_disconnect`, `omnabase_check`.
	 * @param bool   $with_nonce  Whether the nonce is sent.
	 */
	protected function adminPost( string $action, bool $with_nonce = true ): ?string {
		$_POST    = array( 'action' => $action ) + ( $with_nonce ? array( '_wpnonce' => wp_create_nonce( $action ) ) : array() );
		$_REQUEST = $_POST;
		try {
			do_action( 'admin_post_' . $action );
		} catch ( Redirected $r ) {
			return $r->location;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return null;
	}

	/** Connect as the current admin: the address of OmnaBase's connect page. */
	protected function connectLink(): string {
		$location = $this->adminPost( 'omnabase_connect' );
		$this->assertNotNull( $location, 'Connect sends the browser somewhere' );
		$this->assertStringContainsString( '/connect#', (string) $location, 'Connect sends the browser to OmnaBase\'s connect page' );
		return (string) $location;
	}

	/** The parameters after `#`. */
	protected static function fragment( string $link ): array {
		parse_str( (string) substr( $link, (int) strpos( $link, '#' ) + 1 ), $p );
		return $p;
	}

	/**
	 * The browser's way back onto the settings page; answers where the plugin redirected (null when it did not).
	 *
	 * @param array<string,string> $query `code` + `state`, or `error` + `state`.
	 */
	protected function arriveBack( array $query ): ?string {
		$_GET     = array( 'page' => 'omnabase' ) + $query;
		$_REQUEST = $_GET;
		try {
			do_action( 'load-' . self::pageHook() );
		} catch ( Redirected $r ) {
			$this->followQuery( $r->location );
			return $r->location;
		}
		return null;
	}

	/** After a redirect back to the screen, the next request's query is the redirect's. */
	protected function followQuery( string $location ): void {
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $q );
		$_GET     = $q;
		$_REQUEST = $q;
	}

	/** A full connect as the current admin, OmnaBase answering the exchange with the given answer. */
	protected function connectFully( ?array $exchange = null ): void {
		$p                                              = self::fragment( $this->connectLink() );
		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 200, $exchange ?? self::exchangeAnswer() );
		$this->answers['GET /api/public/v1/me']             = self::answer( 200, self::meAnswer() );
		$this->arriveBack( array( 'code' => 'code-' . wp_generate_password( 32, false ), 'state' => $p['state'] ) );
		$this->assertSame( 'ok', ( new Vault() )->state(), 'the connect stored the credentials' );
	}

	/** The screen's hook name as WordPress builds it (`settings_page_omnabase` in a real admin request). */
	protected static function pageHook(): string {
		return (string) get_plugin_page_hookname( 'omnabase', 'options-general.php' );
	}

	/** The settings screen's HTML. */
	protected function renderScreen(): string {
		ob_start();
		try {
			do_action( self::pageHook() );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/** The admin notices of an ordinary admin page (the dashboard) for a user. */
	protected function noticesOnTheDashboardFor( int $user ): string {
		wp_set_current_user( $user );
		set_current_screen( 'dashboard' );
		$this->bootAdminOnce();
		ob_start();
		do_action( 'admin_notices' );
		do_action( 'all_admin_notices' );
		return (string) ob_get_clean();
	}

	/** The value of an attribute on the first element matching an XPath query in some HTML, or null. */
	protected static function attr( string $html, string $xpath, string $attribute ): ?string {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$node = ( new \DOMXPath( $doc ) )->query( $xpath )->item( 0 );
		return $node instanceof \DOMElement && $node->hasAttribute( $attribute ) ? $node->getAttribute( $attribute ) : null;
	}

	/* ---------- the receivers ---------- */

	/** A fresh REST server with the routes registered. */
	protected function rest(): \WP_REST_Server {
		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		do_action( 'rest_api_init', $wp_rest_server );
		return $wp_rest_server;
	}

	/**
	 * A POST to one of the receivers as OmnaBase sends it (logged out, signed unless told otherwise).
	 *
	 * @param string               $route   `/omnabase/v1/events` or `/omnabase/v1/customer`.
	 * @param string               $body    The raw body.
	 * @param string|null          $secret  The secret to sign with; null sends no signature.
	 * @param array<string,string> $headers Extra or replacing headers.
	 * @param int|null             $ts      The timestamp (now by default).
	 */
	protected function deliver( string $route, string $body, ?string $secret, array $headers = array(), ?int $ts = null ): \WP_REST_Response {
		wp_set_current_user( 0 );
		$ts      = $ts ?? time();
		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'user-agent', 'OmnaBase' );
		if ( null !== $secret ) {
			$request->set_header( 'x-omnabase-timestamp', (string) $ts );
			$request->set_header( 'x-omnabase-signature', 'v1=' . hash_hmac( 'sha256', $ts . '.' . $body, $secret ) );
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		return rest_ensure_response( $this->rest()->dispatch( $request ) );
	}

	/** A webhook's body as OmnaBase sends it (`docs/public-api.md`, "The events"). */
	protected static function event( string $type, string $id, array $data = array() ): string {
		return (string) wp_json_encode(
			array(
				'id'        => $id,
				'seq'       => 'ping' === $type ? null : 1234,
				'type'      => $type,
				'createdAt' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'workspace' => array( 'slug' => 'kriesi', 'name' => 'Team Kriesi' ),
				'origin'    => null,
				'data'      => (object) $data,
			)
		);
	}

	/** A signed webhook to the events receiver. */
	protected function deliverEvent( string $type, string $delivery, array $data = array(), string $secret = self::WEBHOOKS ): \WP_REST_Response {
		return $this->deliver( '/omnabase/v1/events', self::event( $type, 'evt_' . $delivery, $data ), $secret, array( 'x-omnabase-event' => $type, 'x-omnabase-delivery' => $delivery ) );
	}

	/** The salts change (a security plugin's "log everyone out"), through WordPress's own `salt` filter. */
	protected function rotateSalts(): void {
		add_filter( 'salt', static fn ( $salt, $scheme ) => 'rotated-by-the-red-team|' . $scheme, 99, 2 );
	}

	/* ---------- the time shift for the pending connect ---------- */

	/**
	 * Moves everything the plugin keeps for a user's pending connect back in time: every timestamp-looking number in the user's meta and in
	 * the plugin's transients (names holding "omnabase"), and the transients' own expiry.
	 *
	 * @param int $user    The admin.
	 * @param int $seconds How far back.
	 */
	protected function agePendingConnect( int $user, int $seconds ): void {
		global $wpdb;
		$shifted = 0;
		foreach ( get_user_meta( $user ) as $name => $values ) {
			if ( ! str_contains( strtolower( (string) $name ), 'omnabase' ) ) {
				continue;
			}
			$value = maybe_unserialize( $values[0] );
			$new   = self::shift( $value, $seconds, $shifted );
			update_user_meta( $user, $name, $new );
		}
		$rows = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%transient%omnabase%'" ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $name ) {
			$value = get_option( $name );
			if ( str_contains( $name, '_transient_timeout_' ) ) {
				update_option( $name, (int) $value - $seconds, false );
				++$shifted;
				continue;
			}
			update_option( $name, self::shift( $value, $seconds, $shifted ), false );
		}
		wp_cache_flush();
		$this->assertGreaterThan( 0, $shifted, 'the pending connect is kept under a name holding "omnabase" with its time (user meta or a transient)' );
	}

	/**
	 * Every number within a day of now, moved back.
	 *
	 * @param mixed $value   A stored value.
	 * @param int   $seconds How far back.
	 * @param int   $count   How many were moved.
	 * @return mixed
	 */
	private static function shift( $value, int $seconds, int &$count ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::shift( $v, $seconds, $count );
			}
			return $value;
		}
		if ( ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) && abs( (int) $value - time() ) < DAY_IN_SECONDS ) {
			++$count;
			return is_int( $value ) ? $value - $seconds : (string) ( (int) $value - $seconds );
		}
		return $value;
	}

	/* ---------- redirects ---------- */

	/**
	 * Stops a redirect before its `exit`.
	 *
	 * @param string $location Where the plugin redirects.
	 * @throws Redirected Always.
	 */
	public function catchRedirect( $location ) {
		throw new Redirected( (string) $location );
	}
}

/** A redirect the plugin asked for. */
final class Redirected extends \Exception {
	public function __construct( public string $location ) {
		parent::__construct( 'redirected to ' . $location );
	}
}
