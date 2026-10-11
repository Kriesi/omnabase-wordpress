<?php
/**
 * FIXING ROUND "THE VAULT'S KEY FROM A FILE" (2026-10-11), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the vault's usual key
 * is now a file under wp-content, so `rotateSalts()` loses the key either way (the salts change and the key folder is moved aside; see
 * `RoundOneTestCase`). `test_unreadable_credentials_warn_administrators_on_every_admin_page` brought the key back with
 * `remove_all_filters( 'salt' )`, which no longer brings back a moved key folder; it now calls `restoreVaultKey()` (both back). Same three
 * assertions, same order; its PM lines say "the key" where they said "the salts".
 *
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: OmnaBase's 401 now has a state of its own, "Disconnected in
 * OmnaBase" (brief `docs/briefs/m14-settings-cleanup.md`, choice 1, the fourth state; review log B4). `test_a_refused_key_shows_as_a_failed_check`
 * asserted only that the "me" row was not "ok"; in the fourth state the check rows are no longer the place this is said, so the test now asserts
 * the stronger, more specific outcome: the connection box is in the `revoked` state and says "Disconnected in OmnaBase" (and, where a "me"
 * row is still printed, it is not "ok"). Every other test here is unchanged: the check rows keep their `data-check`/`data-result` marks inside
 * the Connection tab's Details (review S1), which the default address opens.
 *
 * M13 round one, the red team's failing tests: Disconnect, reconnect, "Check connection" and the "reconnect needed" warning in a real
 * WordPress.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): choice 3 "**Disconnect** calls `DELETE /me`, then wipes the credentials
 * even if the call fails (and then says to revoke under Settings › Integrations › API keys). **Reconnect** rotates the key and secrets at
 * the exchange. … **Health**: 'Check connection' calls `GET /me` (site → OmnaBase) and `POST /me/ping` (OmnaBase → site; the screen waits up
 * to 30 seconds for the `ping`)"; "A **check value** … detects a salt rotation …: then every admin page shows 'OmnaBase: reconnect needed'
 * to administrators"; round one's *Red team*: "the vault … raises the notice on every admin page"; review S1 ("unreadable credentials show as
 * an admin notice on **every** admin page"), S7 (`/me/ping`). The coordinator: "Disconnect → `DELETE /api/public/v1/me` then the vault
 * cleared (a failed revoke still clears, with a notice), reconnect → the link again (… the plugin stores the new key at the exchange)";
 * "'Check connection' on the screen: the site calling `GET /me` and OmnaBase reaching the site through `POST /me/ping` — the screen shows
 * both results".
 *
 * Shapes the red team pins: those of RoundOneTestCase, plus (chosen) the screen's two results as elements carrying `data-check="me"` and
 * `data-check="ping"`, each with `data-result` `ok` when it worked (anything else otherwise: `failed`, `waiting` …).
 *
 * Why these fail today: no Disconnect or Check action, no vault, no notice.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Security\Vault;

final class DisconnectAndHealthTest extends RoundOneTestCase {

	/**
	 * What it checks: Disconnect revokes the site's key at OmnaBase with that key, then forgets everything.
	 * Passes when: one `DELETE /me` goes to OmnaBase carrying the stored key; the vault is then empty; the screen offers Connect again.
	 * Fails when: the key stays alive at OmnaBase after the admin disconnected (anyone holding a copy keeps access), or the site still
	 *   believes it is connected.
	 */
	public function test_disconnect_revokes_the_key_with_itself_then_forgets_it(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'DELETE /api/public/v1/me' => self::answer( 200, array( 'ok' => true ) ) );

		$this->adminPost( 'omnabase_disconnect' );

		$revokes = $this->callsTo( 'DELETE', '/api/public/v1/me' );
		$this->assertCount( 1, $revokes );
		$headers = array_change_key_case( (array) ( $revokes[0]['args']['headers'] ?? array() ) );
		$this->assertSame( 'Bearer ' . self::KEY, $headers['authorization'] ?? null );
		$this->assertSame( 'empty', ( new Vault() )->state() );
		$this->asAdminOnTheScreen( $this->admin );
		$html = $this->renderScreen();
		$this->assertSame( 'not_connected', self::attr( $html, '//*[contains(@class,"ob-wp-settings__connection")]', 'data-state' ) );
		$this->assertStringNotContainsString( 'Team Kriesi', $html, 'nothing of the old connection is shown' );
	}

	/**
	 * What it checks: When OmnaBase cannot be told (down, or the key already revoked), Disconnect still forgets the key and tells the admin
	 *   to revoke it on OmnaBase's side.
	 * Passes when: with `DELETE /me` failing, the vault is empty afterwards and the screen points to Settings › Integrations › API keys.
	 * Fails when: a failing OmnaBase traps the site in "connected", or the admin is not told that the key may still work.
	 */
	public function test_a_failed_revoke_still_forgets_and_says_where_to_revoke(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'DELETE /api/public/v1/me' => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$location = $this->adminPost( 'omnabase_disconnect' );

		$this->assertSame( 'empty', ( new Vault() )->state() );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->asAdminOnTheScreen( $this->admin );
		$this->assertStringContainsString( 'API keys', wp_strip_all_tags( $this->renderScreen() . $this->noticesOnTheDashboardFor( $this->admin ) ) );
	}

	/**
	 * What it checks: Disconnect needs an administrator and the form's token.
	 * Passes when: a subscriber's Disconnect and an administrator's without the token change nothing (the vault still reads, no call to
	 *   OmnaBase).
	 * Fails when: a forged link disconnects the site.
	 */
	public function test_disconnect_needs_an_administrator_and_the_forms_token(): void {
		$this->connectFully();
		$this->answers = array( 'DELETE /api/public/v1/me' => self::answer( 200, array( 'ok' => true ) ) );
		foreach ( array( 'a subscriber' => array( 'subscriber', true ), 'no form token' => array( 'administrator', false ) ) as $name => [ $role, $nonce ] ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			try {
				$this->adminPost( 'omnabase_disconnect', $nonce );
			} catch ( \WPDieException $e ) {
				unset( $e );
			}
			$this->assertSame( 'ok', ( new Vault() )->state(), $name );
			$this->assertSame( array(), $this->callsTo( 'DELETE', '/api/public/v1/me' ), $name );
		}
	}

	/**
	 * What it checks: Connecting again replaces the key and secrets with the new ones at the exchange.
	 * Passes when: after a second connect whose exchange answers a new key and new secrets, the vault holds the new ones and the screen
	 *   shows the new key's last four.
	 * Fails when: the site keeps the old key (OmnaBase revoked it at the exchange, so every call starts failing) or mixes old and new secrets.
	 */
	public function test_reconnecting_stores_the_new_key_and_secrets(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$new = array( 'key' => 'obw_n3wk3yn3wk3yn3wk3yn3wk3yn3wk3yn3wk3yn3wk3y_cdZ9Y8', 'webhooks' => 'whsec_rotatedWebhookSecret0000000002', 'facts' => 'cm90YXRlZC1mYWN0cy1zZWNyZXQtMDAwMDAwMDAwMDI' );

		$this->connectFully( self::exchangeAnswer( $new['key'], $new['webhooks'], $new['facts'] ) );

		$this->assertSame( $new, array_intersect_key( (array) ( new Vault() )->read(), $new ) );
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
		$html = $this->renderScreen();
		$this->assertStringContainsString( 'obw_…Z9Y8', $html );
		$this->assertStringNotContainsString( 'obw_…7Q2X', $html );
	}

	/**
	 * What it checks: "Check connection" tests both directions: the site asks OmnaBase who it is, and OmnaBase is asked to call the site;
	 *   the screen shows both results.
	 * Passes when: the check sends `GET /me` and `POST /me/ping` with the stored key; the screen then shows the first as working and the
	 *   second not yet; once a signed ping arrives at the site, the second shows as working too.
	 * Fails when: a security plugin blocking OmnaBase's calls goes unnoticed (only the outgoing direction is tested), or the screen claims a
	 *   ping arrived that never did.
	 */
	public function test_check_connection_tests_both_directions_and_shows_both(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array(
			'GET /api/public/v1/me'       => self::answer( 200, self::meAnswer() ),
			'POST /api/public/v1/me/ping' => self::answer( 202, array( 'ok' => true ) ),
		);

		$location = $this->adminPost( 'omnabase_check' );

		foreach ( array( array( 'GET', '/api/public/v1/me' ), array( 'POST', '/api/public/v1/me/ping' ) ) as [ $method, $path ] ) {
			$calls = $this->callsTo( $method, $path );
			$this->assertNotEmpty( $calls, "{$method} {$path}" );
			$this->assertSame( 'Bearer ' . self::KEY, array_change_key_case( (array) ( end( $calls )['args']['headers'] ?? array() ) )['authorization'] ?? null );
		}
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->asAdminOnTheScreen( $this->admin );
		$before = $this->renderScreen();
		$this->assertSame( 'ok', self::attr( $before, '//*[@data-check="me"]', 'data-result' ) );
		$this->assertNotSame( 'ok', self::attr( $before, '//*[@data-check="ping"]', 'data-result' ), 'no ping has arrived yet' );
		$this->assertNotNull( self::attr( $before, '//*[@data-check="ping"]', 'data-result' ), 'the ping\'s result is on the screen' );

		$this->assertSame( 200, $this->deliverEvent( 'ping', 'del-check' )->get_status() );
		$this->asAdminOnTheScreen( $this->admin );
		$this->assertSame( 'ok', self::attr( $this->renderScreen(), '//*[@data-check="ping"]', 'data-result' ) );
	}

	/**
	 * What it checks: When OmnaBase refuses the key, "Check connection" says so, as the fourth state "Disconnected in OmnaBase".
	 * Passes when: with `GET /me` answering 401, the Connection tab's connection box is in the `revoked` state and reads "Disconnected in
	 *   OmnaBase"; a "me" row, if one is still printed, is not "ok".
	 * Fails when: a revoked key still shows as a working connection, or only as a failed row the owner has to interpret.
	 */
	public function test_a_refused_key_shows_as_a_failed_check(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array(
			'GET /api/public/v1/me'       => self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) ),
			'POST /api/public/v1/me/ping' => self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) ),
		);

		$location = $this->adminPost( 'omnabase_check' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->asAdminOnTheScreen( $this->admin );
		$html = $this->openTab( 'connection' );
		$this->assertSame( 'revoked', self::attr( $html, '//*[contains(@class,"ob-wp-settings__connection")]', 'data-state' ) );
		$this->assertStringContainsString( 'Disconnected in OmnaBase', wp_strip_all_tags( $html ) );
		$result = self::attr( $html, '//*[@data-check="me"]', 'data-result' );
		$this->assertNotSame( 'ok', $result );
	}

	/**
	 * What it checks: When the stored credentials can no longer be read (the key that locked them is lost: the key file gone, or the salts
	 *   changed), every admin page warns administrators, and only them.
	 * Passes when: after the key is lost, an administrator's dashboard shows "OmnaBase: reconnect needed"; a subscriber's admin pages do
	 *   not; with the key back, the warning is gone.
	 * Fails when: the site silently stops receiving replies and customer questions for days while the warning sits on a settings page nobody
	 *   opens (review S1), or customers with admin access see internal warnings.
	 */
	public function test_unreadable_credentials_warn_administrators_on_every_admin_page(): void {
		( new Vault() )->save( self::CREDENTIALS );
		$this->assertStringNotContainsString( 'OmnaBase: reconnect needed', $this->noticesOnTheDashboardFor( $this->admin ), 'no warning while readable' );

		$this->rotateSalts();

		$this->assertStringContainsString( 'OmnaBase: reconnect needed', $this->noticesOnTheDashboardFor( $this->admin ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->assertStringNotContainsString( 'OmnaBase: reconnect needed', $this->noticesOnTheDashboardFor( $subscriber ) );

		$this->restoreVaultKey();
		$this->assertStringNotContainsString( 'OmnaBase: reconnect needed', $this->noticesOnTheDashboardFor( $this->admin ), 'the key back: no warning' );
	}
}
