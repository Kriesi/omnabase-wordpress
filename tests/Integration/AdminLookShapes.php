<?php
/**
 * M14 round four, the red team's readers for the settings screen's look (inside wp-env's tests WordPress): the shapes the screen must take
 * to wear OmnaBase's settings look (white cards on the gray page, the kit's line tabs, the kit's buttons), read from the rendered HTML.
 *
 * The founder's order (2026-10-10): "Use a styling similar to the OmnaBase settings where you render the settings nicely in white background
 * cards, and use the canonical tab styling we have in OmnaBase, in the WP backend." The coordinator's contract for round four: reuse the
 * kit's class names where the markup is the same; one root class on the screen's wrapper; WordPress's notices stay WordPress's.
 *
 * The words: *kit*, OmnaBase's own component library (`packages/ui` in the OmnaBase repository), whose class names this screen reuses so the
 * same look comes from the same names; *card*, a white box with a thin border and rounded corners holding one section; *row*, one setting:
 * its name and a short help on the left, the control (a box, a list, a switch) on the right; *More*, the kit's closed "show more" line
 * (HTML's `<details>`); *notice*, WordPress's own coloured message box (`notice notice-warning` …).
 *
 * Class names pinned (from the kit's own files, read on 2026-10-10: `packages/ui/src/primitives/Form.tsx` renders
 * `section.ob-form__section` › `div.ob-form__head` › `h2.ob-form__title`, then `div.ob-form__intro`; a row `div.ob-form-row` ›
 * `div.ob-form-row__text` › `.ob-form-row__label` + `.ob-form-row__help`, then `div.ob-form-row__control`; `Misc.tsx`'s `Tabs` renders
 * `.ob-tabs.ob-tabs--line` › `.ob-tabs__item` (`--active`) with `span.ob-tabs__count`; `Help.tsx`'s `More` renders `details.ob-more` ›
 * `summary.ob-more__toggle` + `div.ob-more__body`; `dashboard.css` has `.ob-page__title` and `.ob-page__subtitle`; `primitives.css` has
 * `.ob-btn`, `--primary`, `--outline`, `--sm`; the dialog `.ob-dialog`). NOTE for the coordinator: the round's order spelled the card
 * `ob-form-section` / `ob-form-section__title` / `ob-form-section__intro`; the kit has no such class (its card is `ob-form__section`), so the
 * kit's spelling is pinned. Every name is a constant below: the builder may rename any of them in this one place (and tell the red team).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

trait AdminLookShapes {

	/** The one root class on the screen's wrapper; the stylesheet's rules and variables live under it. */
	protected static string $root = 'ob-wp-settings';

	/** The kit's card, its heading and its intro. */
	protected static string $card      = 'ob-form__section';
	protected static string $cardTitle = 'ob-form__title';
	protected static string $cardIntro = 'ob-form__intro';

	/** The kit's setting row and its three parts. */
	protected static string $row        = 'ob-form-row';
	protected static string $rowLabel   = 'ob-form-row__label';
	protected static string $rowHelp    = 'ob-form-row__help';
	protected static string $rowControl = 'ob-form-row__control';

	/** WordPress's own classes for tabs, settings tables and buttons: none may appear on the screen (their admin styles would leak in). */
	protected static array $wordpressLook = array( 'nav-tab', 'nav-tab-wrapper', 'nav-tab-active', 'form-table', 'button', 'button-primary', 'button-secondary', 'button-small', 'button-large', 'button-link' );

	/** The buttons that wear the accent (the contract's list); Connect joins them while the site is not connected. Every other one is outline. */
	protected static array $primaryWords = array( 'Save', 'Check connection', 'Create the page for me', 'Build a shortcode' );

	/** Each tab's sections that must each be their own card, by the classes the older tests keep (connected site, plain run). */
	protected static array $cardsPerTab = array(
		'connection' => array( 'ob-wp-settings__connection', 'ob-wp-settings__health', 'ob-wp-settings__advanced' ),
		'data'       => array( 'ob-wp-settings__sources', 'ob-wp-settings__user-fields' ),
		'forums'     => array( 'ob-wp-settings__forum' ),
		'pages'      => array( 'ob-wp-settings__portal', 'ob-wp-settings__shortcodes' ),
		'activity'   => array( 'ob-wp-settings__outbox', 'ob-wp-settings__deliveries' ),
	);

	/** The screen's wrapper (the one element with the root class), or a failure. */
	protected function wrapperOf( string $html ): \DOMElement {
		$found = self::query( $html, '//*[' . self::hasClass( self::$root ) . ']' );
		$this->assertSame( 1, $found->length, 'exactly one element carries the root class ' . self::$root );
		$wrapper = $found->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $wrapper );
		return $wrapper;
	}

	/** Whether an element carries a class. */
	protected static function classed( \DOMNode $node, string $class ): bool {
		return $node instanceof \DOMElement && in_array( $class, preg_split( '/\s+/', trim( (string) $node->getAttribute( 'class' ) ) ), true );
	}

	/** The nearest card at or above a node, or null. */
	protected static function cardOf( \DOMNode $node ): ?\DOMElement {
		for ( $n = $node; null !== $n; $n = $n->parentNode ) {
			if ( self::classed( $n, self::$card ) ) {
				return $n;
			}
		}
		return null;
	}

	/** Whether a node sits inside (or is) an element with a class. */
	protected static function inside( \DOMNode $node, string $class ): bool {
		for ( $n = $node; null !== $n; $n = $n->parentNode ) {
			if ( self::classed( $n, $class ) ) {
				return true;
			}
		}
		return false;
	}

	/** Whether a node sits inside (or is) an element with this tag. */
	protected static function insideTag( \DOMNode $node, string $tag ): bool {
		for ( $n = $node; null !== $n; $n = $n->parentNode ) {
			if ( $n instanceof \DOMElement && strtolower( $n->nodeName ) === $tag ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every class token used on the page, each with how often.
	 *
	 * @return array<string,int>
	 */
	protected static function classTokens( string $html ): array {
		$out = array();
		foreach ( self::query( $html, '//*[@class]' ) as $el ) {
			foreach ( preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ) ) as $token ) {
				if ( '' !== $token ) {
					$out[ $token ] = ( $out[ $token ] ?? 0 ) + 1;
				}
			}
		}
		return $out;
	}

	/**
	 * Every button on the page: `<button>`, a submit or button input, or a link dressed as a button; each with its words and classes.
	 *
	 * @return array<int,array{words:string,classes:string[],node:\DOMElement}>
	 */
	protected static function buttonsIn( string $html ): array {
		$out = array();
		$q   = "//button | //input[@type='submit' or @type='button'] | //a[contains(concat(' ', normalize-space(@class), ' '), ' button') or contains(@class, 'ob-btn')]";
		foreach ( self::query( $html, $q ) as $b ) {
			$out[] = array(
				'words'   => 'input' === $b->nodeName ? trim( (string) $b->getAttribute( 'value' ) ) : self::wordsOf( $b ),
				'classes' => preg_split( '/\s+/', trim( (string) $b->getAttribute( 'class' ) ) ),
				'node'    => $b,
			);
		}
		return $out;
	}

	/** A word count the way the 30-word rule counts (shortcode tags left out). */
	protected static function wordCount( string $words ): int {
		return str_word_count( (string) preg_replace( '/\[[^\]]*\]/', '', $words ) );
	}

	/**
	 * Text-bearing blocks of the screen that sit in no card: paragraphs, lists, tables, fieldsets, disclosures, rows and headings with words
	 * of their own (button words not counted), outside the page head, the tab strip, the builder's dialog, WordPress's notices and the version
	 * line. Empty when everything a person reads is inside a card.
	 *
	 * @return string[] The loose blocks' words.
	 */
	protected static function looseBlocks( string $html ): array {
		$doc   = self::htmlDocument( $html );
		$xpath = new \DOMXPath( $doc );
		$loose = array();
		$q     = '//*[' . self::hasClass( self::$root ) . ']//*[self::p or self::ul or self::ol or self::table or self::fieldset or self::details or self::h2 or self::h3 or ' . self::hasClass( self::$row ) . ']';
		foreach ( $xpath->query( $q ) as $block ) {
			if ( null !== self::cardOf( $block ) || self::insideTag( $block, 'nav' ) || self::insideTag( $block, 'dialog' ) || self::insideTag( $block, 'h1' )
				|| self::inside( $block, 'notice' ) || self::inside( $block, 'ob-page__subtitle' ) || self::inside( $block, 'ob-wp-settings__version' ) ) {
				continue;
			}
			$words = self::wordsOf( $block );
			foreach ( $xpath->query( ".//button | .//input[@type='submit' or @type='button']", $block ) as $button ) {
				$words = str_replace( 'input' === $button->nodeName ? trim( (string) $button->getAttribute( 'value' ) ) : self::wordsOf( $button ), '', $words );
			}
			if ( '' !== trim( $words ) ) {
				$loose[] = trim( (string) preg_replace( '/\s+/u', ' ', $words ) );
			}
		}
		return $loose;
	}

	/**
	 * Checks every card of a page: a `<section>` with the card class, not inside another card, with exactly one heading `h2` with the title
	 * class (its own, not a nested card's), the intro (when there is one) inside the card and at most 30 words; and every `h2` outside the
	 * builder's dialog is such a title.
	 *
	 * @param string $html  A rendered tab.
	 * @param string $label Which tab, for the messages.
	 * @return int How many cards.
	 */
	protected function assertCardsAreWellFormed( string $html, string $label ): int {
		$doc   = self::htmlDocument( $html );
		$xpath = new \DOMXPath( $doc );
		$cards = iterator_to_array( $xpath->query( '//*[' . self::hasClass( self::$card ) . ']' ) );
		foreach ( $cards as $card ) {
			$this->assertSame( 'section', strtolower( $card->nodeName ), "{$label}: a card is a <section>" );
			$this->assertNull( null === $card->parentNode ? null : self::cardOf( $card->parentNode ), "{$label}: no card inside another card" );
			$titles = array_filter( iterator_to_array( $xpath->query( './/h2', $card ) ), static fn ( $h ) => self::same( self::cardOf( $h ), $card ) );
			$this->assertCount( 1, $titles, "{$label}: a card has exactly one heading" );
			$title = array_values( $titles )[0];
			$this->assertTrue( self::classed( $title, self::$cardTitle ), "{$label}: the heading carries " . self::$cardTitle . ': ' . self::wordsOf( $title ) );
			$this->assertNotSame( '', self::wordsOf( $title ), "{$label}: the heading has words" );
		}
		foreach ( $xpath->query( '//*[' . self::hasClass( self::$cardIntro ) . ']' ) as $intro ) {
			$this->assertNotNull( self::cardOf( $intro ), "{$label}: an intro sits in a card" );
			$this->assertLessThanOrEqual( 30, self::wordCount( self::wordsOf( $intro ) ), "{$label}: intro at most 30 words: " . self::wordsOf( $intro ) );
		}
		foreach ( $xpath->query( '//h2' ) as $h2 ) {
			if ( ! self::insideTag( $h2, 'dialog' ) ) {
				$this->assertTrue( self::classed( $h2, self::$cardTitle ) && null !== self::cardOf( $h2 ), "{$label}: every heading is a card's title: " . self::wordsOf( $h2 ) );
			}
		}
		return count( $cards );
	}

	/**
	 * Checks every setting row of a page: inside a card; exactly one label (with words), at most one help (at most 30 words), exactly one
	 * control, the label and help outside the control and before it; a `<label for>` points at a field inside the row's control.
	 *
	 * @param string $html  A rendered tab.
	 * @param string $label Which tab, for the messages.
	 * @return array<int,array{label:string,help:string,control:\DOMElement}> The rows read.
	 */
	protected function rowsOf( string $html, string $label ): array {
		$doc   = self::htmlDocument( $html );
		$xpath = new \DOMXPath( $doc );
		$rows  = array();
		foreach ( $xpath->query( '//*[' . self::hasClass( self::$row ) . ']' ) as $row ) {
			$part = static function ( string $class ) use ( $xpath, $row ): array {
				return array_values( array_filter( iterator_to_array( $xpath->query( './/*[' . self::hasClass( $class ) . ']', $row ) ), static fn ( $n ) => self::same( self::rowOf( $n ), $row ) ) );
			};
			$labels   = $part( self::$rowLabel );
			$helps    = $part( self::$rowHelp );
			$controls = $part( self::$rowControl );
			$this->assertNotNull( self::cardOf( $row ), "{$label}: a row sits in a card" );
			$this->assertCount( 1, $labels, "{$label}: a row has one label" );
			$this->assertLessThanOrEqual( 1, count( $helps ), "{$label}: a row has at most one help" );
			$this->assertCount( 1, $controls, "{$label}: a row has one control" );
			$name = self::wordsOf( $labels[0] );
			$this->assertNotSame( '', $name, "{$label}: the row's label has words" );
			foreach ( array_merge( $labels, $helps ) as $text ) {
				$this->assertFalse( self::inside( $text, self::$rowControl ), "{$label}: {$name}: the label and help sit beside the control, not in it" );
				$after = array_filter( iterator_to_array( $xpath->query( 'following::*', $text ) ), static fn ( $n ) => self::same( $n, $controls[0] ) );
				$this->assertNotEmpty( $after, "{$label}: {$name}: label and help come before the control" );
			}
			if ( 'label' === strtolower( $labels[0]->nodeName ) && $labels[0]->hasAttribute( 'for' ) ) {
				$for = (string) $labels[0]->getAttribute( 'for' );
				$this->assertSame( 1, $xpath->query( ".//*[@id='{$for}']", $controls[0] )->length, "{$label}: {$name}: the label names a field in its own control" );
			}
			$help = array() === $helps ? '' : self::wordsOf( $helps[0] );
			$this->assertLessThanOrEqual( 30, self::wordCount( $help ), "{$label}: {$name}: help at most 30 words" );
			$rows[] = array( 'label' => $name, 'help' => $help, 'control' => $controls[0] );
		}
		return $rows;
	}

	/** Whether two nodes are the same node (PHP's DOM may hand out two objects for one node). */
	protected static function same( ?\DOMNode $a, ?\DOMNode $b ): bool {
		return null !== $a && null !== $b && $a->isSameNode( $b );
	}

	/** The nearest row at or above a node, or null. */
	protected static function rowOf( \DOMNode $node ): ?\DOMElement {
		for ( $n = $node; null !== $n; $n = $n->parentNode ) {
			if ( self::classed( $n, self::$row ) ) {
				return $n;
			}
		}
		return null;
	}

	/**
	 * The settings a person changes on a page that sit outside a row's control: every select, text box and tick box (not hidden fields, not
	 * buttons), outside the builder's dialog. Empty when every setting sits in a row.
	 *
	 * @return string[] Each stray field's name.
	 */
	protected static function fieldsOutsideRows( string $html ): array {
		$out = array();
		foreach ( self::query( $html, "//select | //textarea | //input[not(@type='hidden') and not(@type='submit') and not(@type='button')]" ) as $field ) {
			if ( self::insideTag( $field, 'dialog' ) || self::inside( $field, self::$rowControl ) ) {
				continue;
			}
			$out[] = (string) $field->getAttribute( 'name' ) . ( $field->hasAttribute( 'readonly' ) ? ' (read-only)' : '' );
		}
		return $out;
	}

	/**
	 * Checks every button of a page against the contract: the kit's base class and exactly one look, primary for the listed words (and for
	 * Connect while not connected), outline for the rest; the small size on the outbox's rows.
	 *
	 * @param string $html         A rendered tab.
	 * @param string $label        Which tab, for the messages.
	 * @param bool   $connectFirst Whether Connect is the main action here (the site is not connected).
	 * @return array<string,string> words => look, for the buttons seen.
	 */
	protected function assertButtonsWearTheKit( string $html, string $label, bool $connectFirst ): array {
		$seen    = array();
		$primary = array_merge( self::$primaryWords, $connectFirst ? array( 'Connect' ) : array() );
		foreach ( self::buttonsIn( $html ) as $button ) {
			$words = $button['words'];
			$this->assertContains( 'ob-btn', $button['classes'], "{$label}: \"{$words}\" carries the kit's button class" );
			$looks = array_values( array_intersect( array( 'ob-btn--primary', 'ob-btn--outline' ), $button['classes'] ) );
			$this->assertCount( 1, $looks, "{$label}: \"{$words}\" has exactly one look (primary or outline)" );
			$want = in_array( $words, $primary, true ) ? 'ob-btn--primary' : 'ob-btn--outline';
			$this->assertSame( $want, $looks[0], "{$label}: \"{$words}\"" );
			if ( self::inside( $button['node'], 'ob-wp-settings__outbox' ) && self::insideTag( $button['node'], 'table' ) ) {
				$this->assertContains( 'ob-btn--sm', $button['classes'], "{$label}: \"{$words}\" on an outbox row is small" );
			}
			$seen[ $words ] = $looks[0];
		}
		return $seen;
	}
}
