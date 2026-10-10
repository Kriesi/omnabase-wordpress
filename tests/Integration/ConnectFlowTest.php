<?php
/**
 * FIXING ROUND "SITES IN A SUBFOLDER", THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the founder (2026-10-10) ruled the subfolder
 * refusal a bug ("A user installing his WordPress in a subfolder could not use it"), so a WordPress in a subfolder now connects (the positive
 * tests are SubfolderTest's). `test_connect_is_refused_with_the_reason_on_http_plain_permalinks_and_a_subfolder` lost its subfolder case and
 * became `…_and_a_rest_address_elsewhere`: the third case is now a site whose REST address (the `/wp-json/` address OmnaBase calls) another
 * plugin renamed, which is what the old "rest_url() is not <origin>/wp-json/" half of the subfolder refusal guarded; it is still refused before
 * the link, with a reason that no longer speaks of a subfolder. The refusals for http and plain permalinks are unchanged. The link test's
 * comment now says the site field is the site's address with its folder (here the root, so the expected value `https://shop.example.org` is
 * unchanged). Every other test here is unchanged.
 *
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the permissions are shown in words, not as codes (brief
 * `docs/briefs/m14-settings-cleanup.md`, choice 1: "the Permissions (as words: 'Read and write tickets, read and write customers, forms, the
 * message centre') and the Key's last four behind a 'Details' disclosure"; review S2). `test_the_screen_shows_the_connection_its_permissions_
 * and_the_keys_last_four` looked for the six codes (`tickets:read` …) in the page; it now looks for the same six permissions in words, online
 * and offline (kept from the exchange), and asserts the codes are gone. The key's last four and the workspace checks are unchanged (they read
 * the HTML, where Details' content still is). Every other test here is unchanged.
 *
 * M13 round one, the red team's failing tests: Connect in a real WordPress (the Connect button, the link to OmnaBase's connect page, the
 * way back onto the settings page, the one-time exchange, the refusals).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): choice 3 "Admins only. Refused, with the reason, on an http site, on plain
 * permalinks and when `rest_url()` is not `https://<host>/wp-json/`. The plugin makes the verifier … and the state, keeps both **per
 * admin** … and sends the browser to `/connect#…` with `site` = `home_url()`'s origin, the site title, the clicking admin's address,
 * `scopes` = `tickets:read,tickets:write,customers:read,customers:write,forms:write,portal`, `events` = `message.sent,ticket.updated` … (M13 round three adds `ticket.resolved`: DECISIONS)
 * `return` = the settings page. **The callback is the settings page** …: the same admin, the state (`hash_equals`), the expiry, then the
 * server-side exchange and a redirect to the clean address"; round one's *Red team*: "a callback with another state, an expired one or another
 * admin refused; two admins connecting at once keep their own states"; "Connect refuses http and plain permalinks". The link's fields are
 * OmnaBase's `packages/shared/src/connect.ts` (`parseConnect`: `site`, `name`, `email`, `scopes`, `events`, `return`, `state`,
 * `challenge`); the exchange `POST /api/public/v1/connect/token { code, verifier, site }` (`ConnectTokenSchema`, strict). The coordinator:
 * "a subfolder install refused before the link is built with a plain message"; "the scopes stored"; "pin the expiry and that another
 * admin's callback is refused".
 *
 * Shapes the red team pins: those of RoundOneTestCase, plus (chosen) the expiry: still accepted after 9 minutes, refused after 16. The brief
 * says 15 minutes, the coordinator 10; both pass this pin, the builder records which in DECISIONS.
 *
 * Why these fail today: there is no Connect action, no callback handling, no vault.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Security\Vault;

final class ConnectFlowTest extends RoundOneTestCase {

	/**
	 * What it checks: Connect sends the admin's browser to OmnaBase's connect page with exactly what OmnaBase reads, and asks OmnaBase
	 *   nothing yet.
	 * Passes when: the address is OmnaBase's `/connect#` page; after the `#` come exactly site (the site's https address with its folder, if
	 *   any; this site is at the root, so no path), name (the site
	 *   title), email (the clicking admin's), the six permissions, the three events (round three added "ticket resolved"), return (this
	 *   settings page), a random state and a 43-character challenge; no call to OmnaBase was made.
	 * Fails when: a field is missing, misnamed or different, so OmnaBase's page shows "This link cannot connect a site", grants other
	 *   permissions than the plugin needs, or sends the admin back somewhere else.
	 */
	public function test_connect_sends_the_admin_to_omnabase_with_exactly_the_link_omnabase_reads(): void {
		$link = $this->connectLink();
		$p    = self::fragment( $link );

		$app = defined( 'OMNABASE_APP_URL' ) ? rtrim( (string) constant( 'OMNABASE_APP_URL' ), '/' ) : 'https://app.omnabase.com';
		$this->assertStringStartsWith( $app . '/connect#', $link );
		$keys = array_keys( $p );
		sort( $keys );
		$this->assertSame( array( 'challenge', 'email', 'events', 'name', 'return', 'scopes', 'site', 'state' ), $keys );
		$this->assertSame( self::SITE, $p['site'] );
		$this->assertSame( 'Kriesi Shop', $p['name'] );
		$this->assertSame( 'owner@shop.example.org', $p['email'] );
		$this->assertEqualsCanonicalizing( self::SCOPES, explode( ',', $p['scopes'] ) );
		$this->assertEqualsCanonicalizing( self::EVENTS, explode( ',', $p['events'] ) );
		$this->assertSame( self::SITE . '/wp-admin/options-general.php?page=omnabase', $p['return'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{16,128}$/', $p['state'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $p['challenge'] );
		$this->assertSame( array(), $this->calls, 'nothing is sent to OmnaBase before the admin approves' );
	}

	/**
	 * What it checks: Coming back from OmnaBase with a code, the site trades it once for its key and secrets, proves it holds the secret
	 *   whose fingerprint it sent, stores everything in the vault and lands on the clean settings address.
	 * Passes when: exactly one exchange call goes to OmnaBase with exactly the code, the secret (whose fingerprint is the link's challenge,
	 *   and which never appeared in the link) and the site's address, without any key; the vault then holds the key and both secrets; the
	 *   browser is sent to the settings page without the code or state in its address; arriving again with the same code makes no second
	 *   exchange.
	 * Fails when: the exchange is malformed (OmnaBase refuses it and no site connects), the secret travels through the browser (a stolen
	 *   code would be enough to take the site's key), the code stays in the address bar and browser history, or a reload trades again.
	 */
	public function test_the_way_back_trades_the_code_once_and_stores_everything_in_the_vault(): void {
		$link = $this->connectLink();
		$p    = self::fragment( $link );
		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 200, self::exchangeAnswer() );
		$this->answers['GET /api/public/v1/me']             = self::answer( 200, self::meAnswer() );
		$code = 'code-' . str_repeat( 'Ab3', 12 );

		$location = $this->arriveBack( array( 'code' => $code, 'state' => $p['state'] ) );

		$exchanges = $this->callsTo( 'POST', '/api/public/v1/connect/token' );
		$this->assertCount( 1, $exchanges );
		$body = json_decode( (string) ( $exchanges[0]['args']['body'] ?? '' ), true );
		$this->assertIsArray( $body, 'the exchange sends JSON' );
		$this->assertEqualsCanonicalizing( array( 'code', 'verifier', 'site' ), array_keys( $body ), 'exactly the three fields OmnaBase takes (strict)' );
		$this->assertSame( $code, $body['code'] );
		$this->assertSame( self::SITE, $body['site'] );
		$this->assertSame( $p['challenge'], rtrim( strtr( base64_encode( hash( 'sha256', (string) $body['verifier'], true ) ), '+/', '-_' ), '=' ), 'the verifier is the link\'s challenge\'s secret' );
		$this->assertStringNotContainsString( (string) $body['verifier'], $link, 'the verifier never travels through the browser' );
		$headers = array_change_key_case( (array) ( $exchanges[0]['args']['headers'] ?? array() ) );
		$this->assertArrayNotHasKey( 'authorization', $headers, 'the exchange carries no key' );
		$this->assertStringContainsString( 'application/json', (string) ( $headers['content-type'] ?? '' ) );

		$this->assertSame( self::CREDENTIALS, array_intersect_key( (array) ( new Vault() )->read(), self::CREDENTIALS ) );
		$this->assertNotNull( $location, 'the browser is sent on to the clean address' );
		parse_str( (string) wp_parse_url( (string) $location, PHP_URL_QUERY ), $q );
		$this->assertSame( 'omnabase', $q['page'] ?? null );
		$this->assertArrayNotHasKey( 'code', $q );
		$this->assertArrayNotHasKey( 'state', $q );
		$this->assertStringStartsWith( self::SITE . '/wp-admin/options-general.php', (string) $location );

		$this->asAdminOnTheScreen( $this->admin );
		$this->arriveBack( array( 'code' => $code, 'state' => $p['state'] ) );
		$this->assertCount( 1, $this->callsTo( 'POST', '/api/public/v1/connect/token' ), 'a reload never trades again' );
	}

	/**
	 * What it checks: After connecting, the screen says where the site is connected, what it may do and which key it holds, without the
	 *   key itself; the workspace and the permissions are kept, so they show even while OmnaBase cannot be reached.
	 * Passes when: the screen reads "Connected to Team Kriesi", names the project Enfold, says the six permissions in words (read and write
	 *   tickets, read and write customers, forms, the message centre; no code such as tickets:read) and shows the key as "obw_…7Q2X" (its
	 *   last four), never in full; with OmnaBase unreachable it still names Team Kriesi and the permissions in words.
	 * Fails when: the admin cannot tell which workspace or permissions the site has, the full key is printed into the page, or the
	 *   connection seems to vanish whenever OmnaBase is briefly down.
	 */
	public function test_the_screen_shows_the_connection_its_permissions_and_the_keys_last_four(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'GET /api/public/v1/me' => self::answer( 200, self::meAnswer() ) );

		$html = $this->renderScreen();
		$this->assertStringContainsString( 'Connected to Team Kriesi', $html );
		$this->assertStringContainsString( 'Enfold', $html );
		self::assertPermissionsInWords( $html, 'online' );
		$this->assertStringContainsString( 'obw_…7Q2X', $html );
		$this->assertStringNotContainsString( self::KEY, $html );

		$this->answers = array();
		$offline       = $this->renderScreen();
		$this->assertStringContainsString( 'Team Kriesi', $offline, 'kept from the exchange' );
		self::assertPermissionsInWords( $offline, 'the permissions are kept from the exchange' );
	}

	/**
	 * The six permissions in words (M14: "Read and write tickets, read and write customers, forms, the message centre"), and no permission
	 * code left in the page's text.
	 *
	 * @param string $html  The screen.
	 * @param string $where For the message.
	 */
	private static function assertPermissionsInWords( string $html, string $where ): void {
		$text = (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) );
		foreach ( array( '/read and write tickets/i', '/read and write customers/i', '/\bforms?\b/i', '/message centre/i' ) as $words ) {
			self::assertMatchesRegularExpression( $words, $text, $where );
		}
		foreach ( self::SCOPES as $scope ) {
			self::assertStringNotContainsString( $scope, $text, "{$where}: the code {$scope} is said in words" );
		}
	}

	/**
	 * What it checks: A way back with a state the admin never received is refused before anything is sent to OmnaBase.
	 * Passes when: arriving with a valid-looking code but another state makes no exchange call, stores nothing, and the screen then says
	 *   the connection could not be completed.
	 * Fails when: an attacker's link (a code from the attacker's own approval) connects the victim's site to the attacker's workspace.
	 */
	public function test_a_forged_state_is_refused_without_an_exchange(): void {
		$this->connectLink();
		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 200, self::exchangeAnswer() );

		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'x', 32 ), 'state' => 'forged-' . str_repeat( 'y', 24 ) ) );

		$this->assertSame( array(), $this->callsTo( 'POST', '/api/public/v1/connect/token' ) );
		$this->assertSame( 'empty', ( new Vault() )->state() );
		$this->assertMatchesRegularExpression( '/could not|refused|expired|start again|try again/i', $this->renderScreen() );
	}

	/**
	 * What it checks: Each admin's pending connect is their own: another admin cannot complete it, and trying does not spoil it.
	 * Passes when: admin B arriving with admin A's state makes no exchange and stores nothing; admin A arriving with the same state then
	 *   connects.
	 * Fails when: one admin can finish another's connect (a lower-trust admin hijacks the flow), or a stray attempt cancels the real one.
	 */
	public function test_another_admins_return_is_refused_and_leaves_theirs_intact(): void {
		$state = self::fragment( $this->connectLink() )['state'];
		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 200, self::exchangeAnswer() );
		$this->answers['GET /api/public/v1/me']             = self::answer( 200, self::meAnswer() );

		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->asAdminOnTheScreen( $other );
		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'b', 32 ), 'state' => $state ) );
		$this->assertSame( array(), $this->callsTo( 'POST', '/api/public/v1/connect/token' ), 'another admin\'s return trades nothing' );
		$this->assertSame( 'empty', ( new Vault() )->state() );

		$this->asAdminOnTheScreen( $this->admin );
		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'a', 32 ), 'state' => $state ) );
		$this->assertCount( 1, $this->callsTo( 'POST', '/api/public/v1/connect/token' ) );
		$this->assertSame( 'ok', ( new Vault() )->state() );
	}

	/**
	 * What it checks: Two admins who click Connect at the same time each keep their own secret and state.
	 * Passes when: admin A and admin B both start; A's way back trades with A's secret (matching A's challenge); B's way back afterwards
	 *   still trades, with B's secret (matching B's challenge).
	 * Fails when: the second click overwrites the first admin's secret, so the first exchange is refused by OmnaBase and the admin is told
	 *   to start again for no visible reason.
	 */
	public function test_two_admins_connecting_at_once_keep_their_own_secrets(): void {
		$a     = self::fragment( $this->connectLink() );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->asAdminOnTheScreen( $other );
		$b = self::fragment( $this->connectLink() );
		$this->assertNotSame( $a['state'], $b['state'] );
		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 200, self::exchangeAnswer() );
		$this->answers['GET /api/public/v1/me']             = self::answer( 200, self::meAnswer() );
		$challenge = static fn ( array $call ) => rtrim( strtr( base64_encode( hash( 'sha256', (string) json_decode( (string) $call['args']['body'], true )['verifier'], true ) ), '+/', '-_' ), '=' );

		$this->asAdminOnTheScreen( $this->admin );
		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'a', 32 ), 'state' => $a['state'] ) );
		$this->asAdminOnTheScreen( $other );
		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'b', 32 ), 'state' => $b['state'] ) );

		$calls = $this->callsTo( 'POST', '/api/public/v1/connect/token' );
		$this->assertCount( 2, $calls );
		$this->assertSame( $a['challenge'], $challenge( $calls[0] ), 'A traded with A\'s secret' );
		$this->assertSame( $b['challenge'], $challenge( $calls[1] ), 'B traded with B\'s secret' );
	}

	/**
	 * What it checks: A pending connect expires: a way back long after the click is refused, one within minutes is fine.
	 * Passes when: nine minutes after Connect the way back still trades; sixteen minutes after (another attempt) it is refused with no
	 *   exchange call.
	 * Fails when: a state lives for hours (a leaked return link could be used much later), or the admin gets no time to sign in on OmnaBase.
	 */
	public function test_a_pending_connect_expires(): void {
		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 200, self::exchangeAnswer() );
		$this->answers['GET /api/public/v1/me']             = self::answer( 200, self::meAnswer() );

		$late = self::fragment( $this->connectLink() )['state'];
		$this->agePendingConnect( $this->admin, 16 * MINUTE_IN_SECONDS );
		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'l', 32 ), 'state' => $late ) );
		$this->assertSame( array(), $this->callsTo( 'POST', '/api/public/v1/connect/token' ), 'sixteen minutes later: refused' );
		$this->assertSame( 'empty', ( new Vault() )->state() );

		$this->asAdminOnTheScreen( $this->admin );
		$fine = self::fragment( $this->connectLink() )['state'];
		$this->agePendingConnect( $this->admin, 9 * MINUTE_IN_SECONDS );
		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'f', 32 ), 'state' => $fine ) );
		$this->assertCount( 1, $this->callsTo( 'POST', '/api/public/v1/connect/token' ), 'nine minutes later: still fine' );
	}

	/**
	 * What it checks: Cancel on OmnaBase's page brings the admin back to a screen that says so, with nothing stored.
	 * Passes when: arriving with `error=access_denied` and the state makes no call to OmnaBase, stores nothing, and the screen mentions the
	 *   cancelled connection.
	 * Fails when: a cancel is shown as an error, or leaves a half-made connection behind.
	 */
	public function test_cancel_on_omnabase_stores_nothing_and_says_so(): void {
		$state = self::fragment( $this->connectLink() )['state'];

		$this->arriveBack( array( 'error' => 'access_denied', 'state' => $state ) );

		$this->assertSame( array(), $this->calls );
		$this->assertSame( 'empty', ( new Vault() )->state() );
		$this->assertMatchesRegularExpression( '/cancel/i', $this->renderScreen() );
	}

	/**
	 * What it checks: When OmnaBase refuses the exchange (a used or expired code), nothing is stored and the admin is told in words.
	 * Passes when: with OmnaBase answering 410, the vault stays empty and the screen shows an error notice.
	 * Fails when: a failed connect looks connected, or fails silently so the admin does not know to click Connect again.
	 */
	public function test_a_refused_exchange_stores_nothing_and_says_so(): void {
		$state = self::fragment( $this->connectLink() )['state'];
		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 410, array( 'error' => array( 'code' => 'gone', 'message' => 'This code is used, expired or unknown: connect the site again from its settings' ) ) );

		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'g', 32 ), 'state' => $state ) );

		$this->assertSame( 'empty', ( new Vault() )->state() );
		$this->assertMatchesRegularExpression( '/notice-error|ob-wp-settings__error/', $this->renderScreen() );
	}

	/**
	 * What it checks: A site that OmnaBase could never call back is refused before the link is built, with the reason in plain words: an
	 *   http site, plain permalinks (no /wp-json/ addresses), a site whose /wp-json/ address another plugin moved elsewhere.
	 * Passes when: for each of the three, Connect does not send the browser to OmnaBase, and the screen names the reason (https; permalinks;
	 *   an address OmnaBase cannot reach); none of the reasons speaks of a subfolder (a subfolder site connects since this round).
	 * Fails when: the admin approves on OmnaBase only to end with a site that never receives replies or customer questions, and nobody says
	 *   why.
	 */
	public function test_connect_is_refused_with_the_reason_on_http_plain_permalinks_and_a_rest_address_elsewhere(): void {
		$cases = array(
			'an http site'      => array( static function ( $t ) { update_option( 'home', 'http://shop.example.org' ); update_option( 'siteurl', 'http://shop.example.org' ); }, '/https/i' ),
			'plain permalinks'  => array( static function ( $t ) { $t->set_permalink_structure( '' ); }, '/permalink/i' ),
			'a REST address elsewhere' => array( static function ( $t ) { add_filter( 'rest_url_prefix', static fn () => 'api' ); }, '/address|reach/i' ),
		);
		foreach ( $cases as $name => [ $arrange, $reason ] ) {
			update_option( 'home', self::SITE );
			update_option( 'siteurl', self::SITE );
			$this->set_permalink_structure( '/%postname%/' );
			remove_all_filters( 'rest_url_prefix' );
			$arrange( $this );
			$this->asAdminOnTheScreen( $this->admin );

			try {
				$location = $this->adminPost( 'omnabase_connect' );
			} catch ( \WPDieException $e ) {
				$location = null;
			}
			$this->assertStringNotContainsString( '/connect#', (string) $location, "{$name}: no link to OmnaBase" );
			if ( null !== $location ) {
				$this->followQuery( $location );
			}
			$screen = wp_strip_all_tags( $this->renderScreen() );
			$this->assertMatchesRegularExpression( $reason, $screen, "{$name}: the reason in words" );
			$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', $screen, "{$name}: no word about a folder" );
		}
		remove_all_filters( 'rest_url_prefix' );
	}

	/**
	 * What it checks: Only an administrator, from the screen itself, can start a connect.
	 * Passes when: a subscriber's Connect, and an administrator's Connect without the form's token, never reach OmnaBase's page, while the
	 *   administrator's Connect with the token does.
	 * Fails when: a forged link or a non-admin can start connecting the site to someone's workspace.
	 */
	public function test_only_an_administrator_with_the_forms_token_can_start(): void {
		$this->connectLink(); // the control: the real admin's click works
		foreach ( array( 'a subscriber' => array( 'subscriber', true ), 'no form token' => array( 'administrator', false ) ) as $name => [ $role, $nonce ] ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			try {
				$location = $this->adminPost( 'omnabase_connect', $nonce );
			} catch ( \WPDieException $e ) {
				$location = null;
			}
			$this->assertStringNotContainsString( '/connect#', (string) $location, $name );
		}
	}
}
