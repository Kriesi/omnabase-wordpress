<?php
/**
 * M14 round one, the red team's failing tests: the setup line at the top of the Connection tab (the plain run, without bbPress).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 4: "**A setup line at the top of the Connection tab** ('Set up:
 * Connect ✓ · Customer data ✓ · Forums · Add the form to a page') with each item a link to its tab; done when its setting is saved or, for the
 * last, when the first-display mark of choice 2 is set; gone once every item is done or dismissed". The coordinator for round one: "the last
 * item's mark comes in round two, so for now it is done when a published page holds either tag OR when a stored mark
 * `omnabase_display['first_shown']` exists — pin the stored-mark path and leave the scan out". "Order of work › Round one": "the setup line
 * counts right and disappears".
 *
 * The words: *setup line*, a short row of steps for a first-time owner, each ticked once done; *first-display mark*, a note the plugin stores
 * the first time the contact form or the message centre appears on the public site (built in round two; here the test stores it itself);
 * *option*, a stored setting.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team): the line is `.ob-wp-settings__setup`; each step an element
 * with `data-step` (`connect`, `data`, `forums`, `form`) and `data-done` (`1` done, `0` not yet) holding a link to its tab and, when done, a
 * "✓"; the dismissal a form inside the line whose button reads "Dismiss" or "Hide". Without bbPress the Forums step may be left out or count
 * as done (the builder's choice, recorded in DECISIONS); `ForumsTabTest` pins the four steps where bbPress is active.
 *
 * Why these fail today: there is no setup line.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class SetupLineTest extends TabsTestCase {

	/** Each step's tab. */
	private const STEP_TABS = array( 'connect' => 'connection', 'data' => 'data', 'forums' => 'forums', 'form' => 'pages' );

	public function set_up(): void {
		parent::set_up();
		foreach ( array( 'omnabase_facts', 'omnabase_forum', 'omnabase_display' ) as $option ) {
			delete_option( $option );
		}
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/**
	 * The setup line's steps on a tab: step => [done, tab its link opens, words].
	 *
	 * @return array<string,array{done:bool,tab:string,words:string}>|null Null when the tab has no setup line.
	 */
	private function steps( string $tab = 'connection' ): ?array {
		$html = $this->openTab( $tab );
		$line = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__setup' ) . ']' );
		if ( 0 === $line->length ) {
			return null;
		}
		$out   = array();
		$xpath = new \DOMXPath( $line->item( 0 )->ownerDocument );
		foreach ( $xpath->query( './/*[@data-step]', $line->item( 0 ) ) as $step ) {
			$link = $xpath->query( './/a[@href]', $step )->item( 0 );
			parse_str( (string) wp_parse_url( html_entity_decode( $link instanceof \DOMElement ? (string) $link->getAttribute( 'href' ) : '' ), PHP_URL_QUERY ), $q );
			$out[ (string) $step->getAttribute( 'data-step' ) ] = array(
				'done'  => '1' === $step->getAttribute( 'data-done' ),
				'tab'   => 'omnabase' === ( $q['page'] ?? '' ) ? (string) ( $q['tab'] ?? 'connection' ) : '',
				'words' => self::wordsOf( $step ),
			);
		}
		return $out;
	}

	/** The first-display mark round two will store when a tag first shows on the public site (here stored by the test). */
	private static function markFirstShown(): void {
		update_option( 'omnabase_display', array_merge( (array) get_option( 'omnabase_display', array() ), array( 'first_shown' => array( 'form' => time() - 60 ) ) ) );
	}

	/**
	 * What it checks: A new site sees the setup line first on the Connection tab, its steps in order, each linking to its tab, none ticked.
	 * Passes when: on a site not connected and with nothing saved, the Connection tab's setup line comes before the connection box and holds
	 *   Connect, Customer data and "Add the form to a page" in that order (Forums between them where shown), each linking to its own tab
	 *   (connection, data, pages; forums), none marked done and none showing "✓".
	 * Fails when: a first-time owner does not see what to do in which order, a step's link opens the wrong tab, or a step reads done on a site
	 *   where nothing was set up.
	 */
	public function test_a_new_site_sees_the_steps_in_order_none_done(): void {
		( new \OmnaBase\WordPress\Security\Vault() )->clear();
		$html  = $this->openTab( 'connection' );
		$order = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__setup' ) . ']/following::*[' . self::hasClass( 'ob-wp-settings__connection' ) . ']' );
		$this->assertGreaterThan( 0, $order->length, 'the setup line comes before the connection box' );
		$steps = $this->steps();
		$this->assertNotNull( $steps, 'the setup line' );
		$this->assertSame( array( 'connect', 'data', 'form' ), array_values( array_diff( array_keys( $steps ), array( 'forums' ) ) ), 'the steps in order' );
		if ( isset( $steps['forums'] ) ) {
			$this->assertSame( array( 'connect', 'data', 'forums', 'form' ), array_keys( $steps ), 'Forums sits between Customer data and the form' );
		}
		$words = array( 'connect' => '/\bConnect\b/', 'data' => '/Customer data/', 'forums' => '/Forums/', 'form' => '/Add the form to a page/' );
		foreach ( $steps as $step => $info ) {
			$this->assertSame( self::STEP_TABS[ $step ], $info['tab'], "{$step} links to its tab" );
			$this->assertMatchesRegularExpression( $words[ $step ], $info['words'], "{$step}'s words" );
			if ( 'forums' !== $step ) {
				$this->assertFalse( $info['done'], "{$step} is not done yet" );
				$this->assertStringNotContainsString( '✓', $info['words'], "{$step} shows no tick" );
			}
		}
	}

	/**
	 * What it checks: Each step ticks itself once its setting is saved: Connect when connected, Customer data when its form was saved, the
	 *   form step when the first-display mark is stored.
	 * Passes when: after a full Connect only Connect is done (with "✓"); after saving the Customer data tab's form Customer data is done too;
	 *   with the first-display mark stored the form step is done as well.
	 * Fails when: a step stays open after the owner did it (the owner keeps doing it again), or one step's action ticks another.
	 */
	public function test_steps_tick_as_their_settings_are_saved(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$steps = $this->steps();
		$this->assertNotNull( $steps, 'the setup line while steps are open' );
		$this->assertTrue( $steps['connect']['done'], 'Connect is done' );
		$this->assertStringContainsString( '✓', $steps['connect']['words'] );
		$this->assertFalse( $steps['data']['done'] );
		$this->assertFalse( $steps['form']['done'] );

		$form = array_values( array_filter( self::formsIn( $this->openTab( 'data' ) ), static fn ( $f ) => 'omnabase_save_facts' === $f['action'] ) );
		$this->assertNotEmpty( $form, 'the Customer data form' );
		$this->postFields( $form[0]['fields'] );
		$steps = $this->steps();
		$this->assertTrue( $steps['data']['done'], 'Customer data is done once saved' );
		$this->assertFalse( $steps['form']['done'] );

		self::markFirstShown();
		$steps = $this->steps();
		$this->assertTrue( $steps['form']['done'] ?? true, 'the form step is done once the first-display mark is stored' );
	}

	/**
	 * What it checks: The form step counts the stored first-display mark, and nothing else in this round.
	 * Passes when: with everything else done but no mark (and no page holding a tag), the form step is open; with the mark stored, it is done.
	 * Fails when: the step ticks without the form ever being shown, or stays open although the form is live.
	 */
	public function test_the_form_step_counts_the_stored_first_display_mark(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		update_option( 'omnabase_facts', array( 'sources' => array( 'profile' => true ), 'meta' => array() ) );
		update_option( 'omnabase_display', array( 'theme_only' => false, 'my_account' => false ) );
		$steps = $this->steps();
		$this->assertNotNull( $steps );
		$this->assertFalse( $steps['form']['done'], 'no mark: not done' );

		self::markFirstShown();
		$steps = $this->steps();
		$this->assertTrue( null === $steps || $steps['form']['done'], 'the mark: done (or the whole line gone because every step is done)' );
	}

	/**
	 * What it checks: The setup line disappears once every step is done.
	 * Passes when: connected, the customer-data and forum settings saved and the first-display mark stored, the Connection tab has no setup
	 *   line.
	 * Fails when: a finished setup keeps nagging the owner on every visit.
	 */
	public function test_the_line_disappears_once_every_step_is_done(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->assertNotNull( $this->steps(), 'the line while steps are open' );
		update_option( 'omnabase_facts', array( 'sources' => array( 'profile' => true ), 'meta' => array() ) );
		update_option( 'omnabase_forum', array( 'forums' => array(), 'support_user' => 0, 'closing_line' => '', 'guests' => false, 'close_resolved' => false ) );
		self::markFirstShown();

		$this->assertNull( $this->steps(), 'no setup line once everything is done' );
	}

	/**
	 * What it checks: An owner can dismiss the setup line before finishing it.
	 * Passes when: the line holds a form whose button reads "Dismiss" or "Hide"; sending it (with its own fields) returns to the Connection
	 *   tab, and the line is gone although steps are still open.
	 * Fails when: an owner who never wants forums or a form keeps seeing the line for ever.
	 */
	public function test_dismissing_hides_the_line(): void {
		$html = $this->openTab( 'connection' );
		$line = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__setup' ) . ']' );
		$this->assertSame( 1, $line->length, 'the setup line' );
		$dismiss = null;
		foreach ( self::formsIn( self::outer( $line->item( 0 ) ) ) as $form ) {
			foreach ( self::submitsOf( $form['node'] ) as $submit ) {
				if ( preg_match( '/^(Dismiss|Hide)\b/i', $submit['words'] ) ) {
					$dismiss = $form['fields'];
				}
			}
		}
		$this->assertNotNull( $dismiss, 'a Dismiss (or Hide) button inside the line' );

		$this->assertSame( 'connection', $this->tabIn( $this->postFields( $dismiss ) ) );
		$this->assertNull( $this->steps(), 'dismissed: no setup line' );
	}

	/**
	 * What it checks: The setup line sits on the Connection tab only.
	 * Passes when: with steps open, the Connection tab holds the line and the Customer data, Forms and messages and Activity tabs do not.
	 * Fails when: the line crowds every tab, pushing the settings an owner came for down the page.
	 */
	public function test_the_line_is_only_on_the_connection_tab(): void {
		$this->assertNotNull( $this->steps( 'connection' ) );
		foreach ( array( 'data', 'pages', 'activity' ) as $tab ) {
			$this->assertNull( $this->steps( $tab ), $tab );
		}
	}
}
