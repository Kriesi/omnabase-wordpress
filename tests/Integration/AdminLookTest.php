<?php
/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the brief (OmnaBase
 * `docs/briefs/plugin-frontend-design.md`, rule 2) adds a sixth tab, "Appearance", after "Forms and messages", with settings of its own. Here: the tab strip holds six links (counted from the list of tabs, `TABS`, no longer the number 5), the data provider of the tabs with
 * settings gains `appearance` (so its rows must be the kit's rows like every other tab's), and the card test (through `AdminLookShapes`)
 * expects the Appearance card on its tab. Every assertion is as strict as before.
 *
 * FIXING ROUND "THE VAULT'S KEY FROM A FILE" (2026-10-11), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the founder removed
 * the "Stored connection" card and its Advanced block, so on the Connection tab of a site in full use one closed block is left (the status
 * card's Details), not two: `test_every_disclosure_is_the_kits_more` now expects at least one there (every closed block on the tab is still
 * checked to be the kit's More), and `test_every_section_is_a_card` reads the tab's sections from `AdminLookShapes` without the removed
 * card. The PM lines name the sections as they are now. No assertion was loosened beyond the removed card.
 *
 * M14 round four, the red team's failing tests: Settings › OmnaBase wears OmnaBase's settings look (the plain run, without bbPress or the
 * shops): a page head, the kit's line tabs, every section a white card, every setting a row, the kit's buttons, WordPress's notices kept,
 * and the screen's stylesheet loaded on that screen alone.
 *
 * The founder's order (2026-10-10, after seeing the five tabs in WordPress's default admin look): "still horrible design. Use a styling
 * similar to the OmnaBase settings where you render the settings nicely in white background cards, and use the canonical tab styling we have
 * in OmnaBase, in the WP backend." The coordinator's contract for round four (the page head; the tab strip a `nav` with the kit's line
 * tabs and no WordPress tab classes; every section a card with a heading and an optional intro; every setting a row; the button looks; the
 * setup line a card with its steps as a list; Details and Advanced like the kit's More; the builder's dialog as a kit dialog; the notices
 * and the flash keep WordPress's markup; the stylesheet only on the plugin's screen).
 *
 * The words: *tab strip*, the row of five tab links under the title; *card*, a white box with a thin border and rounded corners holding one
 * section; *row*, one setting with its name and help on the left and the control on the right; *primary button*, the one blue main action;
 * *outline button*, a white button with a gray border; *flash*, the one-time message after an action ("Saved."); *enqueue*, WordPress's list
 * of stylesheets and scripts a page loads; *hook*, a named moment where a plugin's code runs (`admin_enqueue_scripts`: the moment admin
 * pages collect their stylesheets).
 *
 * Shapes pinned: `AdminLookShapes`' class names (the kit's own, renamable there in one place); the tab links keep `page=omnabase&tab=<slug>`;
 * the older tests' section classes (`ob-wp-settings__connection` …) stay and each sits in its own card. Chosen here (the builder may argue in
 * DECISIONS): every `<details>` on the screen is a kit More, not only Details and Advanced; "Use this page" in the builder is outline (the
 * contract's list of primary buttons does not name it); WordPress's button classes (`button`, `button-primary` …) are gone with the tab and
 * table classes, since their admin styles would fight the kit's.
 *
 * Why these fail today: the title is a bare `h1`, the tab strip is WordPress's `nav-tab-wrapper`, the sections are plain `div`s with loose
 * `h2`s, the forum and page settings sit in `form-table`s, the buttons are WordPress's, the setup line is a notice and the disclosures are
 * bare `<details>`. Two tests pass today on purpose, as guards for the round: the notices keep WordPress's markup (they do today, and must
 * survive the restyle) and the stylesheet only on the screen (round two's loader already does it).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Admin\AdminAssets;

final class AdminLookTest extends TabsTestCase {

	use AdminLookShapes;

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/** The six tabs' slugs, for the data provider. */
	public static function tabs(): array {
		return array_map( static fn ( $t ) => array( $t ), array_combine( array_keys( self::TABS ), array_keys( self::TABS ) ) );
	}

	/** The tabs with settings in the plain run (Forums has its settings only with bbPress: `AdminLookForumTest`). */
	public static function settingsTabs(): array {
		return array( 'data' => array( 'data' ), 'pages' => array( 'pages' ), 'appearance' => array( 'appearance' ) );
	}

	/** The tabs with closed blocks on a site in full use. */
	public static function disclosureTabs(): array {
		return array( 'connection' => array( 'connection' ), 'data' => array( 'data' ), 'activity' => array( 'activity' ) );
	}

	/**
	 * What it checks: Every tab opens with the same page head: the title "OmnaBase" in the kit's title style and one short line under it.
	 * Passes when: on each tab the screen's wrapper carries the root class once, holds exactly one main heading reading "OmnaBase" with the
	 *   kit's page-title class, and right after it a subtitle with the kit's subtitle class, with words, at most 30 of them, before the tab
	 *   strip.
	 * Fails when: the screen keeps WordPress's plain grey title, has no line saying what the screen is for, or the line grows into a paragraph.
	 *
	 * @dataProvider tabs
	 */
	public function test_every_tab_has_the_page_head( string $tab ): void {
		$html    = $this->openTab( $tab );
		$wrapper = $this->wrapperOf( $html );
		$xpath   = new \DOMXPath( $wrapper->ownerDocument );

		$h1 = $xpath->query( './/h1', $wrapper );
		$this->assertSame( 1, $h1->length, "{$tab}: one main heading" );
		$this->assertSame( 'OmnaBase', self::wordsOf( $h1->item( 0 ) ), "{$tab}: the heading reads OmnaBase" );
		$this->assertTrue( self::classed( $h1->item( 0 ), 'ob-page__title' ), "{$tab}: the heading wears the kit's page title" );

		$subtitle = $xpath->query( './/*[' . self::hasClass( 'ob-page__subtitle' ) . ']', $wrapper );
		$this->assertSame( 1, $subtitle->length, "{$tab}: one subtitle" );
		$words = self::wordsOf( $subtitle->item( 0 ) );
		$this->assertNotSame( '', $words, "{$tab}: the subtitle has words" );
		$this->assertLessThanOrEqual( 30, self::wordCount( $words ), "{$tab}: subtitle at most 30 words: {$words}" );
		$this->assertSame( 1, $xpath->query( 'following::*[' . self::hasClass( 'ob-page__subtitle' ) . ']', $h1->item( 0 ) )->length, "{$tab}: the subtitle comes after the title" );
		$this->assertSame( 1, $xpath->query( 'following::nav', $subtitle->item( 0 ) )->length, "{$tab}: the tab strip comes after the subtitle" );
	}

	/**
	 * What it checks: The tab strip is OmnaBase's own line tabs, with the open tab marked, and nothing of WordPress's tab look is left.
	 * Passes when: on each tab the wrapper holds one `nav` with a label and the kit's classes for line tabs; it holds exactly the six tab
	 *   links (each with the kit's tab-item class, leading to `page=omnabase&tab=<slug>`, in order) and nothing else to click; exactly the
	 *   open tab carries the kit's active class and says it is the current page; no element anywhere on the screen carries `nav-tab`,
	 *   `nav-tab-wrapper` or `nav-tab-active`.
	 * Fails when: the screen still shows WordPress's grey folder tabs, two tabs look open, the open tab is not announced to screen readers, or
	 *   WordPress's tab styles leak back in through a leftover class.
	 *
	 * @dataProvider tabs
	 */
	public function test_the_tab_strip_is_the_kits_line_tabs( string $tab ): void {
		$html    = $this->openTab( $tab );
		$wrapper = $this->wrapperOf( $html );
		$xpath   = new \DOMXPath( $wrapper->ownerDocument );

		$navs = $xpath->query( './/nav', $wrapper );
		$this->assertSame( 1, $navs->length, "{$tab}: one tab strip" );
		$nav = $navs->item( 0 );
		$this->assertTrue( self::classed( $nav, 'ob-tabs' ) && self::classed( $nav, 'ob-tabs--line' ), "{$tab}: the kit's line tabs" );
		$this->assertNotSame( '', trim( (string) $nav->getAttribute( 'aria-label' ) ), "{$tab}: the strip is named for screen readers" );

		$links = iterator_to_array( $xpath->query( './/a', $nav ) );
		$this->assertCount( count( self::TABS ), $links, "{$tab}: the six tabs and nothing else" );
		$this->assertSame( 0, $xpath->query( './/button | .//input', $nav )->length, "{$tab}: no other control in the strip" );
		$slugs  = array();
		$active = array();
		foreach ( $links as $a ) {
			$this->assertTrue( self::classed( $a, 'ob-tabs__item' ), "{$tab}: each tab is a kit tab item" );
			parse_str( (string) wp_parse_url( html_entity_decode( (string) $a->getAttribute( 'href' ) ), PHP_URL_QUERY ), $q );
			$this->assertSame( 'omnabase', $q['page'] ?? null, "{$tab}: a tab leads to the screen" );
			$slugs[] = (string) ( $q['tab'] ?? '' );
			if ( self::classed( $a, 'ob-tabs__item--active' ) ) {
				$active[] = (string) ( $q['tab'] ?? '' );
				$this->assertSame( 'page', (string) $a->getAttribute( 'aria-current' ), "{$tab}: the open tab says it is the current page" );
			} else {
				$this->assertFalse( $a->hasAttribute( 'aria-current' ), "{$tab}: a closed tab is not announced as current" );
			}
		}
		$this->assertSame( array_keys( self::TABS ), $slugs, "{$tab}: the six tabs in order" );
		$this->assertSame( array( $tab ), $active, "{$tab}: exactly the open tab is marked" );

		$tokens = self::classTokens( $html );
		foreach ( array( 'nav-tab', 'nav-tab-wrapper', 'nav-tab-active' ) as $banned ) {
			$this->assertArrayNotHasKey( $banned, $tokens, "{$tab}: no {$banned}" );
		}
	}

	/**
	 * What it checks: The Activity tab shows how many calls need attention as the kit's small count beside its name, and nothing when none do.
	 * Passes when: with two calls needing attention and one waiting, the Activity tab link holds one count element with the kit's count
	 *   class reading 2; with only a waiting call it holds no count element; no other tab ever holds one.
	 * Fails when: the count is glued into the tab's words (unstyled, "Activity · 2"), shows 0, or counts calls that are merely waiting.
	 */
	public function test_the_activity_count_is_the_kits_tab_count(): void {
		$this->plantOutboxRow( false );
		$this->assertSame( 0, self::countClass( $this->openTab( 'connection' ), 'ob-tabs__count' ), 'a waiting call shows no count' );

		$this->plantOutboxRow( true );
		$this->plantOutboxRow( true );
		$html   = $this->openTab( 'connection' );
		$counts = self::query( $html, '//nav//a[' . self::hasClass( 'ob-tabs__item' ) . ']//span[' . self::hasClass( 'ob-tabs__count' ) . ']' );
		$this->assertSame( 1, $counts->length, 'one count, inside a tab link' );
		$this->assertSame( '2', self::wordsOf( $counts->item( 0 ) ), 'it reads 2' );
		$link = $counts->item( 0 )->parentNode;
		while ( null !== $link && 'a' !== $link->nodeName ) {
			$link = $link->parentNode;
		}
		parse_str( (string) wp_parse_url( html_entity_decode( (string) $link->getAttribute( 'href' ) ), PHP_URL_QUERY ), $q );
		$this->assertSame( 'activity', $q['tab'] ?? null, 'the count sits on the Activity tab' );
	}

	/**
	 * What it checks: Every section of every tab is a white card of its own, with a heading, and nothing a person reads floats outside a card.
	 * Passes when: on a site in full use, each tab's sections (Connection: the connection box, the status block with Details; Customer
	 *   data: the sources, the user fields; Forums: the forum section; Forms and messages: the page picker, the Shortcodes block; Appearance:
	 *   its card; Activity: the
	 *   outbox, the list of what OmnaBase sent) each sit in a card, no two in the same card; every card is a section element with exactly one
	 *   heading in the kit's title style, never inside another card, its intro (if any) at most 30 words; every heading outside the builder's
	 *   dialog is a card's title; no paragraph, list, table or setting outside the page head, the tab strip, the dialog, WordPress's notices
	 *   and the version line sits outside a card.
	 * Fails when: the screen is still loose text on the gray admin page, two sections run together in one box, a heading floats between
	 *   cards, or a help line or a table hangs outside the white boxes.
	 *
	 * @dataProvider tabs
	 */
	public function test_every_section_is_a_card( string $tab ): void {
		$this->busySite();
		$html  = $this->openTab( $tab );
		$doc   = self::htmlDocument( $html );
		$xpath = new \DOMXPath( $doc );

		$count = $this->assertCardsAreWellFormed( $html, $tab );
		$this->assertGreaterThanOrEqual( count( self::$cardsPerTab[ $tab ] ), $count, "{$tab}: at least one card per section" );
		$homes = array();
		foreach ( self::$cardsPerTab[ $tab ] as $section ) {
			$found = $xpath->query( '//*[' . self::hasClass( $section ) . ']' );
			$this->assertSame( 1, $found->length, "{$tab}: the section {$section}" );
			$card = self::cardOf( $found->item( 0 ) );
			$this->assertNotNull( $card, "{$tab}: {$section} is (or sits in) a card" );
			foreach ( $homes as $other => $home ) {
				$this->assertFalse( self::same( $home, $card ), "{$tab}: {$section} and {$other} are separate cards" );
			}
			$homes[ $section ] = $card;
		}
		$this->assertSame( array(), self::looseBlocks( $html ), "{$tab}: nothing to read outside a card" );
	}

	/**
	 * What it checks: Every setting is a row in the kit's layout, and WordPress's settings tables are gone.
	 * Passes when: on a site in full use, Customer data, Forms and messages and Appearance (the plain run's tabs with settings; Forums is pinned in the
	 *   forum run) hold no element with WordPress's `form-table` class and no table row heading; every select,
	 *   text box and tick box outside the builder's dialog sits inside a row's control; every row sits in a card and has one label with words,
	 *   at most one help of at most 30 words and one control, label and help before the control and outside it (a `<label for>` naming a field
	 *   inside the row's control); the Forms and messages tab has a row labelled "Message centre page" whose control holds the page picker and
	 *   whose help names [omnabase_messages].
	 * Fails when: the page settings keep WordPress's two-column table, a tick box floats outside the layout, a row has no name, or a label
	 *   points at the wrong field (clicking it does nothing).
	 *
	 * @dataProvider settingsTabs
	 */
	public function test_every_setting_is_a_kit_row( string $tab ): void {
		$this->busySite();
		$html = $this->openTab( $tab );

		$this->assertSame( 0, self::countClass( $html, 'form-table' ), "{$tab}: no WordPress settings table" );
		$this->assertSame( 0, self::query( $html, "//th[@scope='row']" )->length, "{$tab}: no table row headings for settings" );
		$this->assertSame( array(), self::fieldsOutsideRows( $html ), "{$tab}: every setting sits in a row's control" );
		$rows = $this->rowsOf( $html, $tab );
		$this->assertNotEmpty( $rows, "{$tab}: the settings are rows" );
		if ( 'pages' === $tab ) {
			$picker = array_values( array_filter( $rows, static fn ( $r ) => 'Message centre page' === $r['label'] ) );
			$this->assertCount( 1, $picker, 'a row "Message centre page"' );
			$xpath = new \DOMXPath( $picker[0]['control']->ownerDocument );
			$this->assertSame( 1, $xpath->query( ".//select[@name='omnabase_portal[page]']", $picker[0]['control'] )->length, 'its control holds the page picker' );
			$this->assertStringContainsString( '[omnabase_messages]', $picker[0]['help'], 'its help names the shortcode' );
		}
	}

	/**
	 * What it checks: Every button on a connected site's tabs wears the kit's look: blue for the main actions, white with a border for the
	 *   rest, small on the outbox's rows, and none of WordPress's button classes.
	 * Passes when: on a site in full use every button, submit and button-like link carries the kit's button class and exactly one look;
	 *   "Save", "Check connection", "Build a shortcode" and "Create the page for me" are primary; Connect beside a connection, Disconnect,
	 *   Dismiss, Copy, Close, Retry, Discard and Send now are outline; the outbox's row buttons are small; no element carries `button`,
	 *   `button-primary`, `button-secondary`, `button-small`, `button-large` or `button-link`; and the buttons named above were all seen.
	 * Fails when: the screen mixes WordPress's blue buttons with the kit's, two different blues compete, a secondary action looks like the main
	 *   one, or WordPress's button styles leak back in.
	 */
	public function test_every_button_wears_the_kits_look_on_a_connected_site(): void {
		$this->busySite();
		$seen = array();
		foreach ( array_keys( self::TABS ) as $tab ) {
			$html  = $this->openTab( $tab );
			$seen += $this->assertButtonsWearTheKit( $html, $tab, false );
			$tokens = self::classTokens( $html );
			foreach ( self::$wordpressLook as $banned ) {
				$this->assertArrayNotHasKey( $banned, $tokens, "{$tab}: no WordPress class {$banned}" );
			}
		}
		foreach ( array( 'Save', 'Check connection', 'Build a shortcode', 'Create the page for me' ) as $words ) {
			$this->assertSame( 'ob-btn--primary', $seen[ $words ] ?? null, "\"{$words}\" was seen, primary" );
		}
		foreach ( array( 'Connect', 'Disconnect', 'Copy', 'Close', 'Retry', 'Discard', 'Send now' ) as $words ) {
			$this->assertSame( 'ob-btn--outline', $seen[ $words ] ?? null, "\"{$words}\" was seen, outline" );
		}
	}

	/**
	 * What it checks: Connect is the blue main button while the site is not connected, and "Forget this connection" stays a secondary one.
	 * Passes when: on a fresh site the Connection tab's buttons wear the kit's look with Connect primary; on a site OmnaBase disconnected,
	 *   Connect is primary and "Forget this connection" outline.
	 * Fails when: a first-time owner meets a grey Connect (the one thing to do looks optional), or the dead connection's clean-up looks like the
	 *   main action.
	 */
	public function test_connect_is_primary_while_not_connected(): void {
		$seen = $this->assertButtonsWearTheKit( $this->openTab( 'connection' ), 'not connected', true );
		$this->assertSame( 'ob-btn--primary', $seen['Connect'] ?? null, 'Connect, primary' );

		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array( 'GET /api/public/v1/me' => self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) ) );
		$this->openTab( 'connection' );
		$html = $this->openTab( 'connection' );
		$this->assertSame( 'revoked', self::attr( $html, '//*[' . self::hasClass( 'ob-wp-settings__connection' ) . ']', 'data-state' ), 'the site is in the "Disconnected in OmnaBase" state' );
		$seen = $this->assertButtonsWearTheKit( $html, 'revoked', true );
		$this->assertSame( 'ob-btn--primary', $seen['Connect'] ?? null, 'Connect, primary' );
		$this->assertSame( 'ob-btn--outline', $seen['Forget this connection'] ?? null, 'Forget this connection, outline' );
	}

	/**
	 * What it checks: The setup line is a card whose steps are a list, not a blue WordPress notice.
	 * Passes when: with steps open, the Connection tab's setup line is (or sits in) a card, carries no `notice` class and sits in no notice;
	 *   each step (an element marked with its step name) is a list item or inside one, all in one list; the line's Dismiss button stays.
	 * Fails when: the setup line still looks like a WordPress alert above the cards, or its steps run together as one sentence with dots.
	 */
	public function test_the_setup_line_is_a_card_with_a_list_of_steps(): void {
		delete_option( 'omnabase_facts' );
		delete_option( 'omnabase_display' );
		$html = $this->openTab( 'connection' );
		$line = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__setup' ) . ']' );
		$this->assertSame( 1, $line->length, 'the setup line is there (steps are open)' );
		$line = $line->item( 0 );
		$this->assertNotNull( self::cardOf( $line ), 'the line is a card' );
		$this->assertFalse( self::inside( $line, 'notice' ), 'not a WordPress notice' );
		$xpath = new \DOMXPath( $line->ownerDocument );
		$this->assertSame( 0, $xpath->query( './/*[' . self::hasClass( 'notice' ) . ']', $line )->length, 'no notice inside either' );

		$steps = iterator_to_array( $xpath->query( './/*[@data-step]', $line ) );
		$this->assertGreaterThanOrEqual( 3, count( $steps ), 'the steps' );
		$lists = array();
		foreach ( $steps as $step ) {
			$item = $xpath->query( 'ancestor-or-self::li[1]', $step )->item( 0 );
			$this->assertNotNull( $item, 'step ' . $step->getAttribute( 'data-step' ) . ' is a list item' );
			$list = $item->parentNode;
			$this->assertContains( strtolower( (string) $list->nodeName ), array( 'ul', 'ol' ), 'inside a list' );
			$lists[] = $list;
		}
		foreach ( $lists as $list ) {
			$this->assertTrue( self::same( $lists[0], $list ), 'all steps in one list' );
		}
		$this->assertContains( 'Dismiss', array_column( self::buttonsIn( self::outer( $line ) ), 'words' ), 'Dismiss stays' );
	}

	/**
	 * What it checks: Every closed block on the screen (Details, "Choose user fields", an outbox row's details) is the kit's More.
	 * Passes when: on a site in full use, every `<details>` on the tabs that have them (Connection, Customer data, Activity) carries the kit's More class, its first child is a summary with the
	 *   kit's toggle class, and everything else sits in one body element with the kit's body class.
	 * Fails when: Details and the other closed blocks stay as the browser's bare grey triangles, or their content spills out unindented under
	 *   the summary.
	 *
	 * @dataProvider disclosureTabs
	 */
	public function test_every_disclosure_is_the_kits_more( string $tab ): void {
		$this->busySite();
		$html     = $this->openTab( $tab );
		$doc      = self::htmlDocument( $html );
		$xpath    = new \DOMXPath( $doc );
		$details  = iterator_to_array( $xpath->query( '//details' ) );
		$expected = array( 'connection' => 1, 'data' => 1, 'activity' => 1 );
		$this->assertGreaterThanOrEqual( $expected[ $tab ], count( $details ), "{$tab}: the closed blocks are there" );
		foreach ( $details as $block ) {
			$this->assertTrue( self::classed( $block, 'ob-more' ), "{$tab}: a <details> is a kit More: " . self::wordsOf( $block ) );
			$children = array_values( array_filter( iterator_to_array( $block->childNodes ), static fn ( $n ) => $n instanceof \DOMElement ) );
			$this->assertCount( 2, $children, "{$tab}: a summary and one body" );
			$this->assertSame( 'summary', strtolower( $children[0]->nodeName ), "{$tab}: the summary first" );
			$this->assertTrue( self::classed( $children[0], 'ob-more__toggle' ), "{$tab}: the summary is the kit's toggle" );
			$this->assertTrue( self::classed( $children[1], 'ob-more__body' ), "{$tab}: the rest in the kit's body" );
			$this->assertSame( '', trim( implode( '', array_map( static fn ( $n ) => $n instanceof \DOMText ? $n->nodeValue : '', iterator_to_array( $block->childNodes ) ) ) ), "{$tab}: no loose words beside the body" );
		}
	}

	/**
	 * What it checks: The shortcode builder's dialog is the kit's dialog.
	 * Passes when: the Forms and messages tab's builder dialog keeps its own class and id and also carries the kit's dialog class; its heading
	 *   stays inside it.
	 * Fails when: the builder opens as a bare browser box with WordPress's grey border instead of OmnaBase's white dialog.
	 */
	public function test_the_builder_dialog_is_the_kits_dialog(): void {
		$this->busySite();
		$dialog = self::query( $this->openTab( 'pages' ), "//dialog[@id='ob-wp-builder']" );
		$this->assertSame( 1, $dialog->length, 'the builder\'s dialog' );
		$this->assertTrue( self::classed( $dialog->item( 0 ), 'ob-wp-builder' ), 'its own class stays' );
		$this->assertTrue( self::classed( $dialog->item( 0 ), 'ob-dialog' ), 'the kit\'s dialog class' );
		$this->assertSame( 'Build a shortcode', self::wordsOf( ( new \DOMXPath( $dialog->item( 0 )->ownerDocument ) )->query( './/h2', $dialog->item( 0 ) )->item( 0 ) ) );
	}

	/**
	 * What it checks: The development notices and the message after an action keep WordPress's own notice look, outside the cards.
	 * Passes when: after saving the Customer data tab, the next view shows the message with WordPress's `notice` and a `notice-…` class, in
	 *   no card; any development notice on the screen also carries `notice` and `notice-warning` and sits in no card.
	 * Fails when: WordPress's familiar "Saved." box turns into something an owner does not recognise, or a message hides inside a card far
	 *   from the top of the page.
	 */
	public function test_the_notices_keep_wordpress_markup(): void {
		$this->busySite();
		$save = array_values( array_filter( self::formsIn( $this->openTab( 'data' ) ), static fn ( $f ) => 'omnabase_save_facts' === $f['action'] ) );
		$this->assertCount( 1, $save, 'the Customer data tab\'s save form' );
		$this->assertSame( 'data', $this->tabIn( $this->postFields( $save[0]['fields'] ) ) );
		$html = $this->openTab( 'data' );

		$flash = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__notice' ) . ']' );
		$this->assertSame( 1, $flash->length, 'the message after saving' );
		$this->assertTrue( self::classed( $flash->item( 0 ), 'notice' ), 'WordPress\'s notice class' );
		$this->assertMatchesRegularExpression( '/(^|\s)notice-[a-z]+(\s|$)/', (string) $flash->item( 0 )->getAttribute( 'class' ), 'a notice-… kind' );
		$this->assertNull( self::cardOf( $flash->item( 0 ) ), 'not inside a card' );
		foreach ( self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__dev' ) . ']' ) as $dev ) {
			$this->assertTrue( self::classed( $dev, 'notice' ) && self::classed( $dev, 'notice-warning' ), 'a development notice stays a WordPress warning' );
			$this->assertNull( self::cardOf( $dev ), 'not inside a card' );
		}
	}

	/**
	 * What it checks: The screen's stylesheet loads on Settings › OmnaBase and on no other admin page.
	 * Passes when: at WordPress's moment for admin stylesheets, the plugin's screen queues the stylesheet `assets/admin.css` under the plugin's
	 *   admin handle with the plugin's version; the Dashboard, the Plugins list, Settings › General, the Posts list and another plugin's
	 *   settings page queue no stylesheet of the plugin's at all.
	 * Fails when: the kit's look leaks into the rest of the admin (other screens change colour or spacing), or the screen loads without it and
	 *   falls back to the bare look.
	 */
	public function test_the_stylesheet_is_queued_only_on_the_screen(): void {
		$version = \OmnaBase\WordPress\PluginInfo::fromFile( dirname( __DIR__, 2 ) . '/omnabase.php' )->version();
		$this->assertSame( array( AdminAssets::HANDLE ), $this->pluginStylesOn( self::pageHook() ), 'the screen queues the admin stylesheet alone' );
		$style = wp_styles()->registered[ AdminAssets::HANDLE ] ?? null;
		$this->assertNotNull( $style );
		$this->assertStringEndsWith( 'assets/admin.css', (string) wp_parse_url( (string) $style->src, PHP_URL_PATH ), 'the built stylesheet' );
		$this->assertSame( $version, $style->ver, 'with the plugin\'s version (a new release is not served from a stale cache)' );

		foreach ( array( 'index.php', 'plugins.php', 'options-general.php', 'edit.php', 'settings_page_someone-else' ) as $hook ) {
			$this->assertSame( array(), $this->pluginStylesOn( $hook ), "no stylesheet of the plugin's on {$hook}" );
		}
	}

	/**
	 * The plugin's queued stylesheets after WordPress's admin stylesheet moment on a page (a fresh list each time).
	 *
	 * @param string $hook The admin page's hook name.
	 * @return string[] The handles whose file lives in the plugin.
	 */
	private function pluginStylesOn( string $hook ): array {
		$GLOBALS['wp_styles'] = null;
		wp_styles();
		$this->bootAdminOnce();
		do_action( 'admin_enqueue_scripts', $hook );
		$mine = array();
		foreach ( wp_styles()->queue as $handle ) {
			$src = (string) ( wp_styles()->registered[ $handle ]->src ?? '' );
			if ( str_contains( $src, '/omnabase/' ) || str_starts_with( $handle, 'omnabase' ) ) {
				$mine[] = $handle;
			}
		}
		sort( $mine );
		return $mine;
	}
}
