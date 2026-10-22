<?php
/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the brief (OmnaBase
 * `docs/briefs/plugin-frontend-design.md`, rule 2) adds a sixth tab, "Appearance" (slug `appearance`), after "Forms and messages" and before
 * "Activity", with one save form (`omnabase_save_appearance`, the red team's pinned shape) holding the theme, the accent, the font and the moved
 * "Theme styles only" switch; the brief asks that the five-tab pins be adjusted with a DECISIONS entry (`FrontendDesignStructureTest` asks for
 * that entry). Here: the tab row's test asks for six tabs (renamed `..._six_tabs_...`); the one-save-form test counts Appearance among the tabs with
 * settings; the way-back test also saves Appearance and expects to return to it. Nothing loosened.
 *
 * M14 round one, the red team's failing tests: Settings › OmnaBase becomes five tabs, each section in its tab, one save form per settings
 * tab, and every save coming back to the tab it was made on (the plain run, without bbPress or the shops).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1: "Five tabs on the one screen, WordPress's own tab style, the
 * open tab in the address (`?page=omnabase&tab=connection|data|forums|pages|activity`; `connection` opens by default; the server renders only
 * the open tab; the way back from Connect and every save return to the tab they came from: `Guard::backToScreen( $tab )`, the tab from the
 * fixed list). A tab with settings has one save form and one primary 'Save' button at its end; action buttons … keep their own small forms
 * outside it"; "Customer data: … the user fields behind a 'Choose user fields' disclosure (closed; the help names the rule as a list: never
 * passwords, tokens, keys, private fields)"; "Forums: … Without bbPress the tab stays and holds one line". Review S1 (every test that reads
 * a section opens its tab first), S3 (no form inside another; one save action per tab). "Order of work › Round one": "the screen has five tabs
 * with these labels, … each section sits in its tab (an `openTab()` helper renders a tab by its address), … a save returns to its tab".
 *
 * The words: *admin-post*, WordPress's address that hands a posted admin form to the plugin's handler; *nonce*, a short-lived form token
 * against forged requests; *disclosure*, a line such as "Details" that opens a hidden block when clicked (HTML's `<details>`), closed by
 * default; *primary button*, WordPress's blue main button (class `button-primary`).
 *
 * Shapes the red team pins (chosen where the brief names none; the builder may rename and tell the red team): `TabsTestCase`'s tab links;
 * the labels "Connection", "Customer data", "Forums", "Forms and messages", "Activity" (the brief's); the save actions
 * `omnabase_save_facts`, `omnabase_save_forum` (as today) and `omnabase_save_pages` (the brief's); the Save button's word "Save"; the
 * disclosure a `<details>` whose `<summary>` reads "Choose user fields".
 *
 * Why these fail today: the screen is one long page with no tabs, renders every section whatever the address, has two save forms on what
 * becomes the Forms and messages tab (and buttons named "Save customer data settings" …), and every action returns to the bare address.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class TabsTest extends TabsTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/**
	 * What it checks: The screen shows six tabs, in this order, with names that say what each is for.
	 * Passes when: the screen's tab row holds exactly Connection, Customer data, Forums, Forms and messages, Appearance, Activity, each a link
	 *   to the screen's address with its own tab name in it (connection, data, forums, pages, appearance, activity).
	 * Fails when: a site owner faces one long page again, a tab is missing or misnamed, or a tab's link leads nowhere.
	 */
	public function test_the_screen_has_six_tabs_in_order_with_their_names(): void {
		$tabs = self::navTabs( $this->openTab( '' ) );

		$this->assertSame( array_keys( self::TABS ), array_keys( $tabs ), 'the six tabs, in order' );
		$this->assertSame( array_values( self::TABS ), array_values( $tabs ), 'their names (no outbox row needs attention, so Activity carries no count)' );
	}

	/**
	 * What it checks: The screen opens on Connection when the address names no tab or an unknown one, and marks the open tab.
	 * Passes when: the bare address and the address with an unknown tab both mark Connection (and only it) as open and show the connection
	 *   box; each tab's own address marks that tab (and only it) as open.
	 * Fails when: an owner lands on an empty panel, two tabs look open at once, or a made-up address shows something unintended.
	 */
	public function test_connection_opens_by_default_and_the_open_tab_is_marked(): void {
		foreach ( array( '' => 'no tab', 'nonsense' => 'an unknown tab', '../connection' => 'a path-looking tab' ) as $tab => $case ) {
			$html = $this->openTab( (string) $tab );
			$this->assertSame( array( 'connection' ), self::openTabs( $html ), $case );
			$this->assertSame( 1, self::countClass( $html, 'ob-wp-settings__connection' ), "{$case}: the connection box" );
		}
		foreach ( array_keys( self::TABS ) as $tab ) {
			$this->assertSame( array( $tab ), self::openTabs( $this->openTab( $tab ) ), $tab );
		}
	}

	/**
	 * What it checks: Each tab holds its own sections and only the open tab is on the page.
	 * Passes when: on a site in full use, Connection holds the connection box and the status block, Customer data the customer-data section,
	 *   Forums the forum section, Forms and messages the page picker and the Shortcodes block, Activity the outbox and the list of what OmnaBase
	 *   sent; and no tab holds any other tab's sections.
	 * Fails when: a section lands in the wrong tab (an owner looks for the forum settings and cannot find them), or every section is still
	 *   printed on every tab (one long page behind a tab row).
	 */
	public function test_each_section_sits_in_its_tab_and_only_the_open_tab_is_rendered(): void {
		$this->busySite();
		foreach ( self::SECTIONS as $tab => $mine ) {
			$html = $this->openTab( $tab );
			foreach ( $mine as $class ) {
				$this->assertSame( 1, self::countClass( $html, $class ), "{$tab} holds {$class}" );
			}
			foreach ( self::SECTIONS as $other => $theirs ) {
				if ( $other === $tab ) {
					continue;
				}
				foreach ( $theirs as $class ) {
					$this->assertSame( 0, self::countClass( $html, $class ), "{$tab} does not hold {$other}'s {$class}" );
				}
			}
		}
	}

	/**
	 * What it checks: A tab with settings has one save form ending in one blue "Save" button; Connection and Activity have none.
	 * Passes when: Customer data, Forms and messages and Appearance each hold exactly one form that saves settings (its action the tab's save action), with
	 *   exactly one submit button, a primary one reading "Save"; Connection and Activity hold no form that saves settings and no "Save" button.
	 * Fails when: a tab has two save buttons (an owner saves one half and loses the other), a button reads "Save display settings" beside
	 *   "Save page", or a tab without settings shows a Save button that does nothing.
	 */
	public function test_a_settings_tab_has_one_save_form_with_one_primary_save_button(): void {
		$this->busySite();
		foreach ( array_keys( self::TABS ) as $tab ) {
			$html  = $this->openTab( $tab );
			$saves = array_values( array_filter( self::formsIn( $html ), static fn ( $f ) => str_starts_with( $f['action'], 'omnabase_save_' ) ) );
			if ( in_array( $tab, array( 'data', 'pages', 'appearance' ), true ) ) {
				$this->assertCount( 1, $saves, "{$tab}: one save form" );
				$this->assertSame( self::SAVES[ $tab ], $saves[0]['action'], "{$tab}: its save action" );
				$this->assertSame( array( array( 'words' => 'Save', 'primary' => true ) ), self::submitsOf( $saves[0]['node'] ), "{$tab}: one primary Save button" );
			} elseif ( 'forums' !== $tab ) {
				$this->assertSame( array(), $saves, "{$tab}: no save form" );
			}
			$words = array();
			foreach ( self::query( $html, "//form" ) as $form ) {
				foreach ( self::submitsOf( $form ) as $submit ) {
					$words[] = $submit['words'];
				}
			}
			$this->assertLessThanOrEqual( 1, count( array_keys( $words, 'Save', true ) ), "{$tab}: at most one Save button" );
			if ( in_array( $tab, array( 'connection', 'activity' ), true ) ) {
				$this->assertNotContains( 'Save', $words, "{$tab}: nothing to save" );
			}
		}
	}

	/**
	 * What it checks: No form sits inside another on any tab (HTML does not allow it; a browser silently breaks the inner one).
	 * Passes when: on every tab of a site in full use, the raw page never opens a form while another is still open.
	 * Fails when: Retry, Discard, Send now, Connect or Disconnect sit inside a tab's save form, so the browser drops their form and clicking
	 *   them saves the settings instead, or does nothing.
	 */
	public function test_no_form_sits_inside_another(): void {
		$this->busySite();
		foreach ( array_keys( self::TABS ) as $tab ) {
			$this->assertLessThanOrEqual( 1, self::formDepth( $this->openTab( $tab ) ), $tab );
		}
	}

	/**
	 * What it checks: Every save and every action brings the owner back to the tab it was made on.
	 * Passes when: saving Customer data returns to the data tab, saving Forms and messages to pages, saving Appearance to appearance, saving
	 *   the forum settings to forums,
	 *   Retry, Discard and Send now to activity, Check connection and Disconnect to connection; each on Settings › OmnaBase.
	 * Fails when: after saving the forum settings the owner lands on Connection and has to find the tab again to see whether it worked.
	 */
	public function test_every_save_and_action_returns_to_its_tab(): void {
		$this->busySite();
		$data = array_values( array_filter( self::formsIn( $this->openTab( 'data' ) ), static fn ( $f ) => 'omnabase_save_facts' === $f['action'] ) );
		$this->assertNotEmpty( $data, 'the customer-data form' );
		$this->assertSame( 'data', $this->tabIn( $this->postFields( $data[0]['fields'] ) ), 'Customer data' );

		$pages = array_values( array_filter( self::formsIn( $this->openTab( 'pages' ) ), static fn ( $f ) => 'omnabase_save_pages' === $f['action'] ) );
		$this->assertNotEmpty( $pages, 'the Forms and messages form' );
		$this->assertSame( 'pages', $this->tabIn( $this->postFields( $pages[0]['fields'] ) ), 'Forms and messages' );

		$look = array_values( array_filter( self::formsIn( $this->openTab( 'appearance' ) ), static fn ( $f ) => 'omnabase_save_appearance' === $f['action'] ) );
		$this->assertNotEmpty( $look, 'the Appearance form' );
		$this->assertSame( 'appearance', $this->tabIn( $this->postFields( $look[0]['fields'] ) ), 'Appearance' );

		$forum = array( 'action' => 'omnabase_save_forum', '_wpnonce' => wp_create_nonce( 'omnabase_save_forum' ), 'omnabase_forum' => array( 'closing_line' => 'Cheers, {agent}' ) );
		$this->assertSame( 'forums', $this->tabIn( $this->postFields( $forum ) ), 'the forum settings' );

		$activity = $this->openTab( 'activity' );
		foreach ( array( 'omnabase_outbox_retry', 'omnabase_outbox_discard', 'omnabase_outbox_send_now' ) as $action ) {
			$forms = array_values( array_filter( self::formsIn( $activity ), static fn ( $f ) => $action === $f['action'] ) );
			$this->assertNotEmpty( $forms, "{$action} is offered" );
			$this->assertSame( 'activity', $this->tabIn( $this->postFields( $forms[0]['fields'] ) ), $action );
		}

		$this->answers['POST /api/public/v1/me/ping'] = self::answer( 202, array( 'ok' => true ) );
		$this->assertSame( 'connection', $this->tabIn( $this->adminPost( 'omnabase_check' ) ), 'Check connection' );
		$this->answers['DELETE /api/public/v1/me'] = self::answer( 200, array( 'ok' => true ) );
		$this->assertSame( 'connection', $this->tabIn( $this->adminPost( 'omnabase_disconnect' ) ), 'Disconnect' );
	}

	/**
	 * What it checks: Coming back from OmnaBase's connect page (approved or cancelled) opens the Connection tab.
	 * Passes when: a cancelled connect's way back redirects to Settings › OmnaBase with no tab or the connection tab in the address.
	 * Fails when: the owner returns from OmnaBase to another tab and cannot see whether the site is connected.
	 */
	public function test_the_way_back_from_connect_opens_the_connection_tab(): void {
		$state = self::fragment( $this->connectLink() )['state'];
		$this->assertSame( 'connection', $this->tabIn( $this->arriveBack( array( 'error' => 'access_denied', 'state' => $state ) ) ) );
	}

	/**
	 * What it checks: The user fields sit behind a closed "Choose user fields" line inside the customer-data form, with the rule as a list.
	 * Passes when: the Customer data tab holds a closed disclosure reading "Choose user fields" that contains the user-field ticks (one for
	 *   billing_phone here) and sits inside the tab's save form; next to it a list names passwords, tokens, keys and private fields as never
	 *   offered.
	 * Fails when: the user fields are a long open list of internal names every owner must scroll past, the ticks sit outside the form so
	 *   they are never saved, or the rule is a sentence with commas instead of a list.
	 */
	public function test_user_fields_sit_behind_a_closed_choose_user_fields_line(): void {
		update_user_meta( $this->admin, 'billing_phone', '+43 1 2345' );
		$html     = $this->openTab( 'data' );
		$disclose = self::query( $html, "//details[normalize-space(summary)='Choose user fields']" );
		$this->assertSame( 1, $disclose->length, 'one "Choose user fields" disclosure' );
		$details = $disclose->item( 0 );
		$this->assertFalse( $details->hasAttribute( 'open' ), 'closed by default' );
		$xpath = new \DOMXPath( $details->ownerDocument );
		$this->assertGreaterThan( 0, $xpath->query( ".//input[@name='omnabase_facts[meta][]'][@value='billing_phone']", $details )->length, 'the user-field ticks inside it' );
		$form = $xpath->query( 'ancestor::form', $details )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $form, 'inside a form' );
		$this->assertSame( 'omnabase_save_facts', self::formFields( $form )['action'] ?? null, 'inside the customer-data save form' );
		$items = array_map( static fn ( $li ) => self::wordsOf( $li ), iterator_to_array( $xpath->query( '//*[' . self::hasClass( 'ob-wp-settings__facts' ) . ']//li' ) ) );
		foreach ( array( '/password/i', '/token/i', '/\bkeys?\b/i', '/private/i' ) as $rule ) {
			$this->assertNotEmpty( preg_grep( $rule, $items ), 'the rule is a list item: ' . $rule );
		}
	}

	/**
	 * What it checks: Without bbPress the Forums tab stays, with one line saying what to install.
	 * Passes when: in a WordPress without bbPress the Forums tab is in the tab row and holds "Install and activate bbPress to send forum topics
	 *   to OmnaBase." and no form.
	 * Fails when: the tab vanishes (an owner never learns forums are possible) or shows forum settings that cannot work.
	 */
	public function test_the_forums_tab_without_bbpress_holds_one_line(): void {
		$this->assertFalse( function_exists( 'bbp_insert_topic' ), 'the plain run has no bbPress' );
		$html = $this->openTab( 'forums' );
		$this->assertArrayHasKey( 'forums', self::navTabs( $html ) );
		$this->assertStringContainsString( 'Install and activate bbPress to send forum topics to OmnaBase.', self::visibleText( $html ) );
		$forum_forms = array_filter( self::formsIn( $html ), static fn ( $f ) => 'omnabase_save_forum' === $f['action'] );
		$this->assertSame( array(), $forum_forms );
	}
}
