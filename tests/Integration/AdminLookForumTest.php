<?php
/**
 * M14 round four, the red team's failing tests: the Forums tab in OmnaBase's settings look where bbPress is active (the forum run, bbPress
 * loaded): the forum settings leave WordPress's two-column table for the kit's cards and rows.
 *
 * The founder's order (2026-10-10): "render the settings nicely in white background cards"; the coordinator's contract: "every setting row
 * is `div.ob-form-row` … today's `<table class="form-table">` markup goes away". Only the forum run renders the four forum settings (without
 * bbPress the tab holds one line), so they are pinned here; `AdminLookTest` pins the other tabs in the plain run.
 *
 * The words: *bbPress*, the forum plugin; *support user*, the forum account whose name the team's answers appear under; *row*, one setting
 * with its name and help on the left and the control on the right; *card*, a white box with a thin border and rounded corners.
 *
 * Shapes pinned: `AdminLookShapes`' class names (the kit's, renamable there); the four labels from round one ("Who answers on the forum",
 * "Line under each answer", "Who may send topics", "Resolved tickets") now name rows; the field names as today.
 *
 * Why this fails today: the four settings sit in a `form-table` with `th` row headings, the forum ticks float outside any row, the section is
 * a plain `div` with a loose `h2`, and Save is WordPress's button.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class AdminLookForumTest extends RoundThreeTestCase {

	use AdminLookShapes;

	/** Each forum setting's row label and the field its control must hold. */
	private const ROWS = array(
		'Who answers on the forum' => "select[@name='omnabase_forum[support_user]']",
		'Line under each answer'   => "input[@name='omnabase_forum[closing_line]']",
		'Who may send topics'      => "input[@type='radio'][@name='omnabase_forum[guests]']",
		'Resolved tickets'         => "input[@type='checkbox'][@name='omnabase_forum[close_resolved]']",
	);

	/**
	 * What it checks: With bbPress active, the Forums tab is a white card whose settings are the kit's rows, without WordPress's settings table.
	 * Passes when: the Forums tab holds no `form-table` and no table row heading; its forum section is (or sits in) a well-formed card; every
	 *   tick, box and choice (the forum ticks too) sits in a row's control; there are rows labelled "Who answers on the forum", "Line under
	 *   each answer", "Who may send topics" and "Resolved tickets", each control holding its own field (both guest choices in one row); every
	 *   help at most 30 words; nothing to read outside a card.
	 * Fails when: the forum settings keep WordPress's grey two-column table, the forum ticks hang outside the layout, or a label no longer sits
	 *   beside the field it names.
	 */
	public function test_the_forum_settings_are_kit_rows_in_a_card(): void {
		$html = $this->openTab( 'forums' );

		$this->assertSame( 0, self::countClass( $html, 'form-table' ), 'no WordPress settings table' );
		$this->assertSame( 0, self::query( $html, "//th[@scope='row']" )->length, 'no table row headings' );
		$this->assertCardsAreWellFormed( $html, 'forums' );
		$section = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__forum' ) . ']' );
		$this->assertSame( 1, $section->length, 'the forum section' );
		$this->assertNotNull( self::cardOf( $section->item( 0 ) ), 'the forum section is a card' );
		$this->assertNotEmpty( self::query( $html, "//input[@name='omnabase_forum[forums][]']" ), 'the forum ticks are there (the fixture has forums)' );
		$this->assertSame( array(), self::fieldsOutsideRows( $html ), 'every setting sits in a row\'s control' );

		$rows = array();
		foreach ( $this->rowsOf( $html, 'forums' ) as $row ) {
			$rows[ $row['label'] ] = $row['control'];
		}
		foreach ( self::ROWS as $label => $field ) {
			$this->assertArrayHasKey( $label, $rows, "a row labelled \"{$label}\"" );
			$found = ( new \DOMXPath( $rows[ $label ]->ownerDocument ) )->query( './/' . $field, $rows[ $label ] );
			$this->assertSame( 'Who may send topics' === $label ? 2 : 1, $found->length, "\"{$label}\" holds its field" );
		}
		$this->assertSame( array(), self::looseBlocks( $html ), 'nothing to read outside a card' );
	}

	/**
	 * What it checks: The Forums tab's buttons wear the kit's look and none of WordPress's button classes.
	 * Passes when: the tab's one "Save" is the kit's primary button, every other button outline, and no element carries a WordPress tab,
	 *   table or button class.
	 * Fails when: the forum tab keeps WordPress's blue Save while the other tabs wear OmnaBase's, so the screen looks patched together.
	 */
	public function test_the_forum_tabs_buttons_wear_the_kits_look(): void {
		$html = $this->openTab( 'forums' );
		$seen = $this->assertButtonsWearTheKit( $html, 'forums', false );
		$this->assertSame( 'ob-btn--primary', $seen['Save'] ?? null, 'Save, primary' );
		$tokens = self::classTokens( $html );
		foreach ( self::$wordpressLook as $banned ) {
			$this->assertArrayNotHasKey( $banned, $tokens, "no WordPress class {$banned}" );
		}
	}
}
