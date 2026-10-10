<?php
/**
 * M14 round one, the red team's failing tests: the fourth connection state, "Disconnected in OmnaBase", when OmnaBase answers 401 to the
 * site's own calls because someone revoked the site's key there (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1, Connection: "A fourth state joins the three: **'Disconnected in
 * OmnaBase'** (the key was revoked there: OmnaBase answers 401 to the site's calls), with 'Someone disconnected this site in OmnaBase. Click
 * Connect to connect it again.' and the Connect button; the daily and on-demand calls stop until then"; choice 9 (the app's Revoke dialog
 * promises "The site's settings page says 'Disconnected in OmnaBase' until someone clicks Connect there"); the review log, B4 ("'reconnect
 * needed' was false → the plugin's fourth state"); "Order of work › Round one": "a 401 from OmnaBase shows the fourth state".
 *
 * The words: *401*, OmnaBase's answer "this key is not (or no longer) valid"; *revoke*, stopping a key in OmnaBase (Settings › Integrations
 * there), after which every call the site makes with it is refused; *on-demand call*, a call the site makes because someone opened or clicked
 * something (the screen asking OmnaBase who the key is, "Check connection"). Round one has no daily call yet (the daily site report is round
 * two's question), so the "daily" half is not tested here.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team): the connection box's `data-state` reads `revoked` in this
 * state (the other three keep `connected`, `not_connected`, `reconnect`); the words are the brief's, exactly; the Connect button is the same
 * admin-post form (`omnabase_connect`).
 *
 * Why these fail today: a 401 shows as a failed check row or a red error under "Connected to Team Kriesi", the screen keeps asking OmnaBase
 * on every view, and "Check connection" stays on offer.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class DisconnectedStateTest extends TabsTestCase {

	private const STATE_WORDS = 'Disconnected in OmnaBase';
	private const SENTENCE    = 'Someone disconnected this site in OmnaBase. Click Connect to connect it again.';

	/** OmnaBase's refusal of a revoked key, as its public API answers it. */
	private static function revoked(): array {
		return self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) );
	}

	/** The connection box's state on the Connection tab. */
	private function stateOn( string $html ): ?string {
		return self::attr( $html, '//*[' . self::hasClass( 'ob-wp-settings__connection' ) . ']', 'data-state' );
	}

	/** Every call the plugin made to OmnaBase's API since the list was emptied. */
	private function omnabaseCalls(): array {
		return array_values( array_filter( $this->calls, static fn ( $c ) => str_contains( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), '/api/public/v1/' ) ) );
	}

	/**
	 * What it checks: When OmnaBase refuses the key while the screen asks who the key is, the screen says "Disconnected in OmnaBase", offers
	 *   Connect, and stops asking.
	 * Passes when: with OmnaBase answering 401 to the screen's question, the Connection tab (at the latest on the next view) has the state
	 *   `revoked`, the words "Disconnected in OmnaBase" and "Someone disconnected this site in OmnaBase. Click Connect to connect it again.",
	 *   a Connect button and no Check connection button; across both views OmnaBase was asked once.
	 * Fails when: an owner whose site was disconnected in OmnaBase sees "Connected to Team Kriesi" with a cryptic error, or every view of the
	 *   screen fires another refused call at OmnaBase.
	 */
	public function test_a_refused_key_on_the_screens_own_call_shows_the_fourth_state(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'GET /api/public/v1/me' => self::revoked() );
		$this->calls   = array();

		$this->openTab( 'connection' );
		$html = $this->openTab( 'connection' );

		$this->assertSame( 'revoked', $this->stateOn( $html ) );
		$seen = self::visibleText( $html );
		$this->assertStringContainsString( self::STATE_WORDS, $seen );
		$this->assertStringContainsString( self::SENTENCE, $seen );
		$this->assertStringNotContainsString( 'Connected to Team Kriesi', $seen );
		$actions = array_column( self::formsIn( $html ), 'action' );
		$this->assertContains( 'omnabase_connect', $actions, 'the Connect button' );
		$this->assertNotContains( 'omnabase_check', $actions, 'no Check connection while the key is refused' );
		$this->assertCount( 1, $this->callsTo( 'GET', '/api/public/v1/me' ), 'asked once; the second view did not ask again' );
	}

	/**
	 * What it checks: A "Check connection" that meets OmnaBase's 401 leads to the same state, and afterwards the site stops calling.
	 * Passes when: after a check whose calls OmnaBase answers 401, the Connection tab is in the `revoked` state with its words; then another
	 *   view, another check posted anyway and a view of every tab make no call to OmnaBase at all.
	 * Fails when: the site keeps knocking with a dead key (OmnaBase's logs fill with refusals and its rate limit counts them), or the owner is
	 *   told only that a check "failed".
	 */
	public function test_a_refused_check_leads_to_the_fourth_state_and_the_calls_stop(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array(
			'GET /api/public/v1/me'       => self::revoked(),
			'POST /api/public/v1/me/ping' => self::revoked(),
		);
		$location = $this->adminPost( 'omnabase_check' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->asAdminOnTheScreen( $this->admin );
		$html = $this->openTab( 'connection' );
		$this->assertSame( 'revoked', $this->stateOn( $html ) );
		$this->assertStringContainsString( self::STATE_WORDS, self::visibleText( $html ) );

		$this->calls = array();
		$this->adminPost( 'omnabase_check' );
		foreach ( array_keys( self::TABS ) as $tab ) {
			$this->openTab( $tab );
		}
		$this->assertSame( array(), $this->omnabaseCalls(), 'no call to OmnaBase until Connect' );
	}

	/**
	 * What it checks: Connecting again ends the fourth state.
	 * Passes when: after the `revoked` state, a full Connect (OmnaBase hands a new key) and OmnaBase answering the screen's question again
	 *   bring back "Connected to Team Kriesi" with the state `connected` and without "Disconnected in OmnaBase".
	 * Fails when: the site stays "Disconnected in OmnaBase" for ever after the owner did exactly what the screen asked.
	 */
	public function test_connecting_again_ends_the_fourth_state(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'GET /api/public/v1/me' => self::revoked() );
		$this->openTab( 'connection' );
		$this->openTab( 'connection' );

		$this->connectFully( self::exchangeAnswer( 'obw_n3wk3yn3wk3yn3wk3yn3wk3yn3wk3yn3wk3yn3wk3y_cdZ9Y8' ) );
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
		$html = $this->openTab( 'connection' );

		$this->assertSame( 'connected', $this->stateOn( $html ) );
		$this->assertStringContainsString( 'Connected to Team Kriesi', $html );
		$this->assertStringNotContainsString( self::STATE_WORDS, self::visibleText( $html ) );
	}

	/**
	 * What it checks: Only OmnaBase's 401 counts: an outage, a server error, or a refusal the site itself gave OmnaBase is not a disconnect.
	 * Passes when: with OmnaBase unreachable, with OmnaBase answering 500, and after a check where OmnaBase reports that the site's own
	 *   receiver answered 401, the Connection tab stays `connected` and never says "Disconnected in OmnaBase".
	 * Fails when: a short outage or a security plugin on the site tells the owner that someone disconnected the site, and they reconnect for
	 *   nothing (rotating the key).
	 */
	public function test_an_outage_or_the_sites_own_refusal_is_not_a_disconnect(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$cases = array(
			'unreachable'           => new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ),
			'a server error'        => self::answer( 500, array( 'error' => array( 'code' => 'internal', 'message' => 'Something broke' ) ) ),
		);
		foreach ( $cases as $case => $answer ) {
			$this->answers = array( 'GET /api/public/v1/me' => $answer );
			$this->openTab( 'connection' );
			$html = $this->openTab( 'connection' );
			$this->assertSame( 'connected', $this->stateOn( $html ), $case );
			$this->assertStringNotContainsString( self::STATE_WORDS, self::visibleText( $html ), $case );
		}

		$this->answers = array(
			'GET /api/public/v1/me'       => self::answer( 200, self::meAnswer() ),
			'POST /api/public/v1/me/ping' => self::answer( 200, array( 'ok' => false, 'status' => 401 ) ),
		);
		$location = $this->adminPost( 'omnabase_check' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->asAdminOnTheScreen( $this->admin );
		$html = $this->openTab( 'connection' );
		$this->assertSame( 'connected', $this->stateOn( $html ), 'the site\'s own receiver refused OmnaBase' );
		$this->assertStringNotContainsString( self::STATE_WORDS, self::visibleText( $html ) );
	}
}
