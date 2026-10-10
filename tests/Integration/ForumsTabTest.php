<?php
/**
 * M14 round one, the red team's failing tests: the Forums tab where bbPress is active: one save form, labels that say what each setting does
 * for the owner, plain words, a save that returns to the tab, and the setup line's Forums step (the forum run, bbPress loaded).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1 ("A tab with settings has one save form and one primary 'Save'
 * button at its end"; "Forums: as today"); choice 3 ("Labels name what a thing does for the owner ('Who answers on the forum' for the support
 * user, 'Line under each answer' for the closing line, 'Who may send topics' with 'Members only / Guests too' for the guest switch). Lists stay
 * lists"; the banned list); choice 4 (the setup line, "Forums" done when its setting is saved).
 *
 * The words: *bbPress*, the forum plugin; *support user*, the forum account under whose name the team's answers appear; *closing line*, the
 * line under each answer; *guest*, a visitor who posts without an account; *radio buttons*, a set of round choices of which exactly one is
 * picked.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team): the three labels are the `<label>` (or row heading) words
 * of the support-user select, the closing-line field and the guest choice; the guest choice is two radio buttons named
 * `omnabase_forum[guests]`, labelled "Members only" (stores guests off) and "Guests too" (stores guests on), the stored one checked; the setup
 * line's shapes are `SetupLineTest`'s.
 *
 * Why these fail today: no tabs; the labels read "Support user", "Closing line", "Guests" with a checkbox; the button reads "Save forum
 * settings"; the save returns to the bare address; no setup line.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumsTabTest extends RoundThreeTestCase {

	/** The label words for a form control (its `<label for>`, a wrapping `<label>`, or its table row's heading). */
	private static function labelOf( \DOMElement $el ): string {
		$xpath = new \DOMXPath( $el->ownerDocument );
		$words = array();
		$id    = (string) $el->getAttribute( 'id' );
		if ( '' !== $id ) {
			foreach ( $xpath->query( "//label[@for='{$id}']" ) as $l ) {
				$words[] = self::wordsOf( $l );
			}
		}
		foreach ( $xpath->query( 'ancestor::label | ancestor::tr/th | ancestor::fieldset/legend', $el ) as $l ) {
			$words[] = self::wordsOf( $l );
		}
		return implode( ' | ', $words );
	}

	/**
	 * What it checks: The Forums tab has one save form ending in one blue "Save", and saving returns to the Forums tab.
	 * Passes when: the tab holds exactly one form saving settings (`omnabase_save_forum`) with one primary submit reading "Save"; sending it as
	 *   it stands redirects to the forums tab.
	 * Fails when: the button reads "Save forum settings" beside other Save buttons, or the owner lands on Connection after saving.
	 */
	public function test_one_save_form_and_the_way_back_to_forums(): void {
		$html  = $this->openTab( 'forums' );
		$saves = array_values( array_filter( self::formsIn( $html ), static fn ( $f ) => str_starts_with( $f['action'], 'omnabase_save_' ) ) );
		$this->assertCount( 1, $saves );
		$this->assertSame( 'omnabase_save_forum', $saves[0]['action'] );
		$this->assertSame( array( array( 'words' => 'Save', 'primary' => true ) ), self::submitsOf( $saves[0]['node'] ) );
		$this->assertSame( 'forums', $this->tabIn( $this->postFields( $saves[0]['fields'] ) ) );
	}

	/**
	 * What it checks: Each forum setting is named by what it does for the owner.
	 * Passes when: the support-user picker is labelled "Who answers on the forum", the closing-line field "Line under each answer", and the
	 *   guest setting "Who may send topics" with two choices "Members only" and "Guests too", the stored one picked; saving "Guests too"
	 *   stores guests on and "Members only" stores them off.
	 * Fails when: an owner reads "Support user" or "Closing line" and has to guess what happens on the forum.
	 */
	public function test_labels_say_what_each_setting_does(): void {
		// Builder's adjustment (M14 round one, DECISIONS "2026-10-10 — M14 round one"): the forum run's fixture leaves `guests` unset, and an
		// unset guest switch means "guests too" (M13's decided default), so "Members only" could never be the picked one as the line below
		// assumed. The test now stores guests off first, the state its assertion describes; every assertion is unchanged.
		update_option( self::FORUM_OPTION, array( 'guests' => false ) + (array) get_option( self::FORUM_OPTION, array() ) );
		$html   = $this->openTab( 'forums' );
		$select = self::query( $html, "//select[@name='omnabase_forum[support_user]']" )->item( 0 );
		$line   = self::query( $html, "//input[@name='omnabase_forum[closing_line]']" )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $select );
		$this->assertInstanceOf( \DOMElement::class, $line );
		$this->assertStringContainsString( 'Who answers on the forum', self::labelOf( $select ) );
		$this->assertStringContainsString( 'Line under each answer', self::labelOf( $line ) );

		$radios = iterator_to_array( self::query( $html, "//input[@type='radio'][@name='omnabase_forum[guests]']" ) );
		$this->assertCount( 2, $radios, 'two choices for guests' );
		$this->assertStringContainsString( 'Who may send topics', self::wordsOf( self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__forum' ) . ']' )->item( 0 ) ), 'the choice is headed "Who may send topics"' );
		$by_label = array();
		foreach ( $radios as $radio ) {
			$label = self::labelOf( $radio );
			if ( str_contains( $label, 'Guests too' ) ) {
				$by_label['guests'] = $radio;
			} elseif ( str_contains( $label, 'Members only' ) ) {
				$by_label['members'] = $radio;
			}
		}
		$this->assertArrayHasKey( 'guests', $by_label, 'a choice labelled "Guests too"' );
		$this->assertArrayHasKey( 'members', $by_label, 'a choice labelled "Members only"' );
		$this->assertTrue( $by_label['members']->hasAttribute( 'checked' ), 'guests are off here, so "Members only" is picked' );

		$form = array_values( array_filter( self::formsIn( $html ), static fn ( $f ) => 'omnabase_save_forum' === $f['action'] ) )[0]['fields'];
		$form['omnabase_forum']['guests'] = (string) $by_label['guests']->getAttribute( 'value' );
		$this->postFields( $form );
		$this->assertTrue( (bool) ( get_option( self::FORUM_OPTION )['guests'] ?? false ), '"Guests too" stores guests on' );
		$form['omnabase_forum']['guests'] = (string) $by_label['members']->getAttribute( 'value' );
		$this->postFields( $form );
		$this->assertFalse( (bool) ( get_option( self::FORUM_OPTION )['guests'] ?? true ), '"Members only" stores guests off' );
	}

	/**
	 * What it checks: The Forums tab with bbPress speaks plainly and keeps its descriptions short.
	 * Passes when: the tab's visible words hold no banned word, and every paragraph, description and list item has at most 30 words.
	 * Fails when: the forum settings bring back developer words or long paragraphs.
	 */
	public function test_the_forums_tab_speaks_plainly(): void {
		$html = $this->openTab( 'forums' );
		$this->assertSame( array(), self::bannedIn( self::visibleText( $html ) ) );
		foreach ( self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__forum' ) . ']//*[self::p or self::li or ' . self::hasClass( 'description' ) . ']' ) as $node ) {
			$this->assertLessThanOrEqual( 30, str_word_count( self::wordsOf( $node ) ), self::wordsOf( $node ) );
		}
	}

	/**
	 * What it checks: Where bbPress is active, the setup line shows all four steps, Forums ticking once the forum settings are saved.
	 * Passes when: the Connection tab's setup line holds connect, data, forums, form in that order, Forums linking to the forums tab; with
	 *   the forum settings deleted Forums is not done; after saving the Forums tab's form it is done.
	 * Fails when: an owner with a forum never sees the forum step, or it stays open after they saved the forum settings.
	 */
	public function test_the_setup_line_has_the_forums_step(): void {
		delete_option( self::FORUM_OPTION );
		$html = $this->openTab( 'connection' );
		$line = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__setup' ) . ']' );
		$this->assertSame( 1, $line->length, 'the setup line' );
		$steps = array();
		foreach ( ( new \DOMXPath( $line->item( 0 )->ownerDocument ) )->query( './/*[@data-step]', $line->item( 0 ) ) as $step ) {
			$steps[ (string) $step->getAttribute( 'data-step' ) ] = $step;
		}
		$this->assertSame( array( 'connect', 'data', 'forums', 'form' ), array_keys( $steps ) );
		$link = ( new \DOMXPath( $steps['forums']->ownerDocument ) )->query( './/a[@href]', $steps['forums'] )->item( 0 );
		$this->assertStringContainsString( 'tab=forums', html_entity_decode( $link instanceof \DOMElement ? (string) $link->getAttribute( 'href' ) : '' ) );
		$this->assertSame( '0', $steps['forums']->getAttribute( 'data-done' ) );

		$form = array_values( array_filter( self::formsIn( $this->openTab( 'forums' ) ), static fn ( $f ) => 'omnabase_save_forum' === $f['action'] ) );
		$this->assertNotEmpty( $form );
		$this->postFields( $form[0]['fields'] );
		$after = self::query( $this->openTab( 'connection' ), "//*[@data-step='forums']" )->item( 0 );
		$this->assertTrue( null === $after || '1' === $after->getAttribute( 'data-done' ), 'Forums is done once saved (or the line is gone)' );
	}
}
