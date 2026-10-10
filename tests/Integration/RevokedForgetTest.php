<?php
/**
 * M14 round two, the red team's failing tests: "Forget this connection" in the fourth state, "Disconnected in OmnaBase" (inside wp-env's tests
 * WordPress, the plain run).
 *
 * The coordinator's ruling after round one (the builder's report asked: "No Disconnect button in the revoked state … Say if the button should
 * show there too"): the revoked state also shows a secondary "Forget this connection" beside Connect; it is Disconnect's handler (it clears the
 * vault). Round one's ruling (DECISIONS "2026-10-10 — M14 round one"): once OmnaBase refused the key, the site makes no call with it at all
 * until Connect saves a new key or Disconnect clears the vault.
 *
 * The words: *revoked*, stopped in OmnaBase (OmnaBase then answers 401, "this key is not valid"); *vault*, the plugin's encrypted store of the
 * site's key and secrets; *secondary button*, WordPress's grey button style beside the blue primary one; *nonce*, a short-lived form token
 * against forged requests; *flash*, the one-time notice the next view of the screen shows.
 *
 * Shapes pinned (the builder may rename and tell the red team): the button is a submit reading "Forget this connection", not primary, in a
 * form posting round one's `omnabase_disconnect` with its nonce; in this state no button reads "Disconnect"; the connection box's `data-state`
 * values are round one's (`revoked`, `not_connected`, `connected`).
 *
 * Why these fail today: the revoked state offers Connect alone.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class RevokedForgetTest extends TabsTestCase {

	private const FORGET = 'Forget this connection';

	/** OmnaBase's refusal of a revoked key. */
	private static function revoked(): array {
		return self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) );
	}

	/** The connection box's state on some HTML. */
	private static function stateOn( string $html ): ?string {
		return self::attr( $html, '//*[' . self::hasClass( 'ob-wp-settings__connection' ) . ']', 'data-state' );
	}

	/** A connected site whose key OmnaBase then refused; answers the Connection tab in that state. */
	private function revokedSite(): string {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'GET /api/public/v1/me' => self::revoked() );
		$this->openTab( 'connection' );
		$html = $this->openTab( 'connection' );
		$this->assertSame( 'revoked', self::stateOn( $html ), 'the site is in the fourth state' );
		return $html;
	}

	/** The form whose submit button reads the words, or null. */
	private static function formWithSubmit( string $html, string $words ): ?array {
		foreach ( self::formsIn( $html ) as $form ) {
			foreach ( self::submitsOf( $form['node'] ) as $submit ) {
				if ( $words === $submit['words'] ) {
					return $form + array( 'primary' => $submit['primary'] );
				}
			}
		}
		return null;
	}

	/** Every submit button's words on some HTML. */
	private static function submitWords( string $html ): array {
		$out = array();
		foreach ( self::formsIn( $html ) as $form ) {
			foreach ( self::submitsOf( $form['node'] ) as $submit ) {
				$out[] = $submit['words'];
			}
		}
		return $out;
	}

	/**
	 * What it checks: When OmnaBase disconnected the site, the Connection tab offers "Forget this connection" beside Connect.
	 * Passes when: in the "Disconnected in OmnaBase" state the tab still has the Connect button, and also a grey (not primary) "Forget this
	 *   connection" button whose form is Disconnect's, with its token; no button there reads "Disconnect".
	 * Fails when: an owner who does not want to connect again has no way to clear the dead connection from the site, or meets a "Disconnect"
	 *   button for a connection OmnaBase already ended.
	 */
	public function test_the_revoked_state_offers_forget_beside_connect(): void {
		$html    = $this->revokedSite();
		$actions = array_column( self::formsIn( $html ), 'action' );
		$this->assertContains( 'omnabase_connect', $actions, 'Connect stays' );
		$forget = self::formWithSubmit( $html, self::FORGET );
		$this->assertNotNull( $forget, 'a "Forget this connection" button' );
		$this->assertSame( 'omnabase_disconnect', $forget['action'], 'Disconnect\'s handler' );
		$this->assertFalse( $forget['primary'], 'a secondary button' );
		$this->assertNotFalse( wp_verify_nonce( (string) ( $forget['fields']['_wpnonce'] ?? '' ), 'omnabase_disconnect' ), 'with its token' );
		$this->assertNotContains( 'Disconnect', self::submitWords( $html ), 'no "Disconnect" in this state' );
	}

	/**
	 * What it checks: "Forget this connection" clears the site's side without calling OmnaBase with the dead key.
	 * Passes when: posting the button's form makes no call to OmnaBase, empties the vault, forgets the kept connection record, returns to the
	 *   Connection tab, and the next view shows the "not connected" state with Connect; its notice does not tell the owner to revoke the key in
	 *   OmnaBase.
	 * Fails when: the site keeps the dead key, knocks at OmnaBase once more with it, or sends the owner to OmnaBase to revoke a key OmnaBase
	 *   already stopped.
	 */
	public function test_forget_clears_the_site_without_calling_omnabase(): void {
		$forget = self::formWithSubmit( $this->revokedSite(), self::FORGET );
		$this->assertNotNull( $forget );
		$this->calls = array();
		$this->asAdminOnTheScreen( $this->admin );
		$location = $this->postFields( $forget['fields'] );

		$this->assertSame( array(), array_values( array_filter( $this->calls, static fn ( $c ) => str_contains( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), '/api/public/v1/' ) ) ), 'no call to OmnaBase' );
		$this->assertSame( 'empty', ( new \OmnaBase\WordPress\Security\Vault() )->state(), 'the vault is empty' );
		$this->assertFalse( get_option( 'omnabase_site' ), 'the kept connection record is gone' );
		$this->assertSame( 'connection', $this->tabIn( $location ) );

		$this->followQuery( (string) $location );
		$this->asAdminOnTheScreen( $this->admin );
		$html = $this->openTab( 'connection' );
		$this->assertSame( 'not_connected', self::stateOn( $html ) );
		$this->assertContains( 'omnabase_connect', array_column( self::formsIn( $html ), 'action' ) );
		$this->assertDoesNotMatchRegularExpression( '/could not be told|revoke this site/i', self::visibleText( $html ), 'no advice to revoke a key OmnaBase already stopped' );
	}

	/**
	 * What it checks: "Forget this connection" needs the form's token, like every action on the screen.
	 * Passes when: posting its form without the token leaves the vault holding the key and the state "Disconnected in OmnaBase".
	 * Fails when: another site can make an administrator's browser wipe this site's connection.
	 */
	public function test_forget_needs_the_forms_token(): void {
		$forget = self::formWithSubmit( $this->revokedSite(), self::FORGET );
		$this->assertNotNull( $forget );
		$this->asAdminOnTheScreen( $this->admin );
		$this->postFields( $forget['fields'], false );
		$this->assertNotSame( 'empty', ( new \OmnaBase\WordPress\Security\Vault() )->state(), 'the key is still stored' );
		$this->asAdminOnTheScreen( $this->admin );
		$this->assertSame( 'revoked', self::stateOn( $this->openTab( 'connection' ) ) );
	}

	/**
	 * What it checks: A working connection keeps its Disconnect button and shows no "Forget this connection".
	 * Passes when: on a connected site the Connection tab has "Disconnect" and no "Forget this connection".
	 * Fails when: an owner with a working connection is offered to "forget" it, which would leave the key alive in OmnaBase.
	 */
	public function test_the_connected_state_keeps_disconnect_and_shows_no_forget(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'GET /api/public/v1/me' => self::answer( 200, self::meAnswer() ) );
		$html          = $this->openTab( 'connection' );
		$this->assertSame( 'connected', self::stateOn( $html ) );
		$words = self::submitWords( $html );
		$this->assertContains( 'Disconnect', $words );
		$this->assertNotContains( self::FORGET, $words );
	}
}
