<?php
/**
 * M14 round one, the red team's failing tests: the Activity tab in words, the count of outbox rows needing attention on the tab's label, and
 * the same count as a bubble after the Settings menu's "OmnaBase" (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1, Activity: "the outbox (in words: 'On its way to OmnaBase') and
 * the webhook deliveries ('What OmnaBase sent here', events in words: 'A reply was sent', 'A ticket changed', 'A ticket was resolved',
 * 'Test'); the tab's label carries the count of outbox rows that need attention ('Activity · 2'; waiting rows do not count; one small count of
 * the outbox table per admin page view). The same count as a bubble on the Settings menu item is question 5 [now question 3, default yes];
 * if yes, the bubble is a separate element after the title, so the registered title stays exactly 'OmnaBase' (`SettingsMenuTest`)". Review
 * S2 ("The webhook log shows raw event codes such as `message.sent` … Show events in words"), S16 ("say what the Activity count counts …
 * Waiting rows should not count … The bubble must stay out of the menu entry's title"). The coordinator puts the count and the bubble in round
 * one.
 *
 * The words: *outbox*, the plugin's lasting list of calls to OmnaBase still to make; *needs attention*, a row the outbox gave up on until the
 * administrator retries or discards it; *delivery*, one call OmnaBase made to the site; *bubble*, WordPress's small round count beside a menu
 * entry (as on "Plugins" when updates wait), markup `<span class="awaiting-mod">` or `<span class="update-plugins">`; *menu title*, the words
 * of the entry under Settings.
 *
 * Shapes the red team pins (chosen where the brief names none; the builder may rename and tell the red team): the outbox section's heading
 * (`h2`) reads exactly "On its way to OmnaBase"; the deliveries' heading (`h2`) "What OmnaBase sent here"; the bubble carries WordPress's
 * class `awaiting-mod` or `update-plugins` and the number; the Settings submenu entry for `page=omnabase` keeps "OmnaBase" as its page
 * title and as the words before the bubble.
 *
 * Why these fail today: the sections read "Outbox" and "Webhook deliveries" with raw event codes, there are no tabs to carry a count, and
 * the menu entry has no bubble.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class ActivityTabTest extends TabsTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/**
	 * The Settings submenu entry for the plugin as the current administrator's menu is built now: [menu title HTML, page title], or null.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private function menuEntry(): ?array {
		global $submenu, $_wp_submenu_nopriv;
		$submenu            = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- a fresh admin menu, built again
		$_wp_submenu_nopriv = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		do_action( 'admin_menu' );
		foreach ( $submenu['options-general.php'] ?? array() as $entry ) {
			if ( 'omnabase' === $entry[2] ) {
				return array( (string) $entry[0], (string) ( $entry[3] ?? '' ) );
			}
		}
		return null;
	}

	/** The numbers written in some words (a count may be repeated for screen readers: "2", "2 need attention"). */
	private static function numbersIn( string $words ): array {
		preg_match_all( '/\d+/', $words, $m );
		return $m[0];
	}

	/**
	 * What it checks: The outbox reads "On its way to OmnaBase", with Retry, Discard and Send now on its rows.
	 * Passes when: the Activity tab's outbox section is headed "On its way to OmnaBase" and offers Retry and Discard for the rows needing
	 *   attention and Send now for the waiting one; no line on the open page reads "Outbox".
	 * Fails when: an owner meets the word "outbox" and has to guess it means messages still on their way.
	 */
	public function test_the_outbox_reads_on_its_way_to_omnabase(): void {
		$this->busySite();
		$html = $this->openTab( 'activity' );
		$this->assertSame( array( 'On its way to OmnaBase' ), array_map( static fn ( $h ) => self::wordsOf( $h ), iterator_to_array( self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__outbox' ) . ']//h2' ) ) ) );
		$actions = array_column( self::formsIn( $html ), 'action' );
		foreach ( array( 'omnabase_outbox_retry', 'omnabase_outbox_discard', 'omnabase_outbox_send_now' ) as $action ) {
			$this->assertContains( $action, $actions, $action );
		}
		$this->assertDoesNotMatchRegularExpression( '/\boutbox\b/i', self::visibleText( $html ) );
	}

	/**
	 * What it checks: The list of OmnaBase's calls reads "What OmnaBase sent here", each call's event in words.
	 * Passes when: after a reply, a ticket change, a resolve and a test ping arrived, the Activity tab has the heading "What OmnaBase sent
	 *   here" and its open page reads "A reply was sent", "A ticket changed", "A ticket was resolved" and "Test", and shows none of the codes
	 *   message.sent, ticket.updated, ticket.resolved.
	 * Fails when: the owner reads "message.sent" and "ticket.updated" and cannot tell what happened on their site.
	 */
	public function test_deliveries_read_what_omnabase_sent_here_with_events_in_words(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->receiveDeliveries( true );
		$html = $this->openTab( 'activity' );

		$this->assertSame( 1, self::query( $html, "//h2[normalize-space(.)='What OmnaBase sent here']" )->length, 'the heading' );
		$seen = self::visibleText( $html );
		foreach ( array( 'A reply was sent', 'A ticket changed', 'A ticket was resolved', 'Test' ) as $words ) {
			$this->assertMatchesRegularExpression( '/\b' . preg_quote( $words, '/' ) . '\b/', $seen, $words );
		}
		foreach ( array( 'message.sent', 'ticket.updated', 'ticket.resolved' ) as $code ) {
			$this->assertStringNotContainsString( $code, $seen, $code );
		}
	}

	/**
	 * What it checks: The Activity tab's name carries the number of outbox rows needing attention, and only those.
	 * Passes when: with two rows needing attention and one waiting, the tab reads "Activity" with the number 2 (and no other number); with only
	 *   a waiting row, it reads just "Activity".
	 * Fails when: a stuck call hides behind an unmarked tab (customer data stops reaching OmnaBase and nobody looks), or calls that are merely
	 *   waiting raise a false alarm.
	 */
	public function test_the_tab_name_counts_only_rows_needing_attention(): void {
		$this->plantOutboxRow( false );
		$this->assertSame( 'Activity', self::navTabs( $this->openTab( 'connection' ) )['activity'] ?? null, 'a waiting row does not count' );

		$this->plantOutboxRow( true );
		$this->plantOutboxRow( true );
		$label = self::navTabs( $this->openTab( 'connection' ) )['activity'] ?? '';
		$this->assertStringStartsWith( 'Activity', $label );
		$this->assertSame( array( '2' ), array_values( array_unique( self::numbersIn( $label ) ) ), 'Activity with the count 2 (and no other number): ' . $label );
	}

	/**
	 * What it checks: The Settings menu shows the same count as a bubble after an unchanged "OmnaBase".
	 * Passes when: with two rows needing attention, the menu entry's words before its first tag are exactly "OmnaBase", a bubble element
	 *   (WordPress's `awaiting-mod` or `update-plugins`) follows holding 2, and the page title stays "OmnaBase"; with none needing attention
	 *   the entry is exactly "OmnaBase".
	 * Fails when: an owner never notices that calls are stuck unless they open the screen, or the count is glued into the title so the page's
	 *   name reads "OmnaBase2" and the menu test breaks.
	 */
	public function test_the_menu_shows_the_count_as_a_bubble_after_an_unchanged_title(): void {
		$this->assertSame( array( 'OmnaBase', 'OmnaBase' ), $this->menuEntry(), 'nothing needs attention: no bubble' );

		$this->plantOutboxRow( true );
		$this->plantOutboxRow( true );
		$this->plantOutboxRow( false );
		$entry = $this->menuEntry();

		$this->assertNotNull( $entry );
		[ $title, $page ] = $entry;
		$this->assertSame( 'OmnaBase', $page, 'the page title is unchanged' );
		$this->assertSame( 'OmnaBase', trim( (string) strstr( $title . '<', '<', true ) ), 'the words before the bubble are exactly "OmnaBase"' );
		$bubbles = self::query( $title, '//*[' . self::hasClass( 'awaiting-mod' ) . ' or ' . self::hasClass( 'update-plugins' ) . ']' );
		$this->assertSame( 1, $bubbles->length, 'one bubble element' );
		$this->assertSame( array( '2' ), array_values( array_unique( self::numbersIn( self::wordsOf( $bubbles->item( 0 ) ) ) ) ), 'the bubble says 2' );
	}

	/**
	 * What it checks: The count costs one small question to the outbox table per admin page, not one per row or per menu item.
	 * Passes when: building the admin menu with three rows in the outbox asks the database about the outbox table at most once.
	 * Fails when: every admin page of a busy shop runs several queries over the outbox just to draw a number.
	 */
	public function test_the_count_is_one_small_question_per_page(): void {
		$this->plantOutboxRow( true );
		$this->plantOutboxRow( true );
		$this->plantOutboxRow( false );
		$table   = \OmnaBase\WordPress\Outbox\Table::name();
		$queries = 0;
		$count   = static function ( $sql ) use ( $table, &$queries ) {
			if ( str_contains( (string) $sql, $table ) ) {
				++$queries;
			}
			return $sql;
		};
		add_filter( 'query', $count );
		try {
			$this->menuEntry();
		} finally {
			remove_filter( 'query', $count );
		}
		$this->assertLessThanOrEqual( 1, $queries, 'at most one count of the outbox table while the menu is built' );
	}
}
