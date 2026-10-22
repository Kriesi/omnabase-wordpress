<?php
/**
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the brief (OmnaBase
 * `docs/briefs/plugin-frontend-design.md`, rule 2) moves "Theme styles only" from this tab's Shortcodes block to the new Appearance tab. The
 * first test now asks that the switch is no longer here (it must not show on two tabs); the second asks that this tab's save stores the page
 * and leaves the switch alone, with the switch first turned on through the Appearance tab's own form (the old test asked this save to store
 * the switch; the new risk is that it switches it off). The nonce and editor test and the merged-handler test are unchanged.
 *
 * M14 round one, the red team's failing tests: the "Forms and messages" tab, with the message centre's page picker and the Shortcodes block
 * saved by one form through one action (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1, Pages: "(label 'Forms and messages'): the message centre's page
 * picker, the 'theme's styles only' switch, WooCommerce's My Account link, one save action `omnabase_save_pages` (the two handlers of today
 * merge; `MessageCentrePageTest` posts the new one), and the shortcode builder (choice 2, round two). The builder's block keeps the class
 * `ob-wp-settings__shortcodes` and its `h2` 'Shortcodes' (the `ShortcodesSectionTest` moves with it; the theme switch sits inside the block);
 * the page picker keeps `__portal`". Review S1, S3 ("Forms and messages joins two save handlers … Name the one save action").
 *
 * The words: *message centre page*, the page the owner picks where signed-in customers read their tickets (it is never cached); *theme's
 * styles only*, the switch that keeps the plugin's own small stylesheet off the form and the message centre; *admin-post*, WordPress's address
 * that hands a posted admin form to the plugin's handler; *nonce*, a short-lived form token against forged requests.
 *
 * Shapes pinned: the brief's (`omnabase_save_pages`, `.ob-wp-settings__portal` with `omnabase_portal[page]`, `.ob-wp-settings__shortcodes` with
 * its `h2` "Shortcodes" and the theme switch); the theme switch is the checkbox `omnabase_theme_only` (today's name, kept).
 *
 * Why these fail today: no tabs, two forms (`omnabase_save_portal`, `omnabase_save_display`) with their own buttons, no `omnabase_save_pages`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class PagesTabTest extends TabsTestCase {

	private const SAVE = 'omnabase_save_pages';

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/** A published page. */
	private function page( string $title ): int {
		return (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title ) );
	}

	/** The tab's save form: its node and fields, or null. */
	private function saveForm(): ?array {
		foreach ( self::formsIn( $this->openTab( 'pages' ) ) as $form ) {
			if ( self::SAVE === $form['action'] ) {
				return $form;
			}
		}
		return null;
	}

	/**
	 * What it checks: One form on the Forms and messages tab holds the page picker and the Shortcodes block; the theme switch has left for the
	 *   Appearance tab.
	 * Passes when: the tab's one save form (action `omnabase_save_pages`) contains the page picker inside `.ob-wp-settings__portal` and the
	 *   Shortcodes block `.ob-wp-settings__shortcodes`, whose heading is "Shortcodes"; the tab holds no "theme's styles only" checkbox (it is on
	 *   the Appearance tab now); the tab's name reads "Forms and messages".
	 * Fails when: the picker and the Shortcodes block need two saves, the Shortcodes block loses its heading, or the switch shows on two tabs
	 *   (an owner ticks it on one and the other's save unticks it).
	 */
	public function test_one_form_holds_the_picker_and_the_shortcodes_block(): void {
		$html = $this->openTab( 'pages' );
		$this->assertSame( 'Forms and messages', self::navTabs( $html )['pages'] ?? null );
		$form = $this->saveForm();
		$this->assertNotNull( $form, 'the tab\'s save form' );
		$xpath = new \DOMXPath( $form['node']->ownerDocument );
		$this->assertSame( 1, $xpath->query( './/*[' . self::hasClass( 'ob-wp-settings__portal' ) . "]//select[@name='omnabase_portal[page]']", $form['node'] )->length, 'the page picker inside the form' );
		$block = $xpath->query( './/*[' . self::hasClass( 'ob-wp-settings__shortcodes' ) . ']', $form['node'] )->item( 0 );
		$this->assertNotNull( $block, 'the Shortcodes block inside the form' );
		$this->assertSame( 1, $xpath->query( ".//h2[normalize-space(.)='Shortcodes']", $block )->length, 'its heading' );
		$this->assertSame( 0, self::query( $html, "//input[@type='checkbox'][@name='omnabase_theme_only']" )->length, 'the theme switch is no longer on this tab' );
		$labels = array_map( static fn ( $l ) => self::wordsOf( $l ), iterator_to_array( self::query( $html, '//label' ) ) );
		$this->assertSame( array(), array_values( preg_grep( '/theme(\'s)? styles only/i', $labels ) ), 'no label for it either' );
	}

	/**
	 * What it checks: One save stores the picked page and returns to the tab, and never touches the theme switch that moved to Appearance.
	 * Passes when: with "theme styles only" switched on (as the Appearance tab stores it), sending this tab's form with a new page picked stores
	 *   the page, redirects to the pages tab and leaves the switch on; sending it again with no page clears the page and still leaves the switch
	 *   on.
	 * Fails when: the page is silently not saved, the owner lands on another tab after saving, or saving this tab switches "theme styles only"
	 *   off because the switch is no longer in its form.
	 */
	public function test_one_save_stores_the_page_and_the_switch(): void {
		$look = array_values( array_filter( self::formsIn( $this->openTab( 'appearance' ) ), static fn ( $f ) => 'omnabase_save_appearance' === $f['action'] ) );
		$this->assertNotEmpty( $look, 'the Appearance tab\'s form, where the switch lives now' );
		// Builder's fix (2026-10-22): the second label spelling was written 'theme\'s styles only', which XPath 1.0 cannot read (it has no
		// backslash escapes), so the whole query failed; the spelling is now quoted with double quotes. Both spellings are still looked for.
		$box = self::query( $this->openTab( 'appearance' ), "//form//input[@type='checkbox'][@id=//label[contains(translate(normalize-space(.),'TS','ts'),'theme styles only') or contains(translate(normalize-space(.),'TS','ts'),\"theme's styles only\")]/@for] | //form//label[contains(translate(normalize-space(.),'TS','ts'),'theme styles only')]//input[@type='checkbox']" )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $box, 'the switch on the Appearance tab' );
		$on = $look[0]['fields'];
		self::putField( $on, (string) $box->getAttribute( 'name' ), $box->hasAttribute( 'value' ) ? (string) $box->getAttribute( 'value' ) : 'on' );
		$this->postFields( $on );
		$switch_on = fn (): bool => 1 === self::query( $this->openTab( 'appearance' ), "//input[@type='checkbox'][@name='" . $box->getAttribute( 'name' ) . "'][@checked]" )->length;
		$this->assertTrue( $switch_on(), 'the switch is on before this tab is saved' );

		$next = $this->page( 'Help centre' );
		$form = $this->saveForm();
		$this->assertNotNull( $form );
		$fields                            = $form['fields'];
		$fields['omnabase_portal']['page'] = (string) $next;

		$this->assertSame( 'pages', $this->tabIn( $this->postFields( $fields ) ) );
		$this->assertSame( $next, (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 ) );
		$this->assertTrue( $switch_on(), 'saving this tab leaves the switch on' );

		$fields                            = $this->saveForm()['fields'];
		$fields['omnabase_portal']['page'] = '0';
		$this->postFields( $fields );
		$this->assertSame( 0, (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 ) );
		$this->assertTrue( $switch_on(), 'and again' );
	}

	/**
	 * What it checks: The merged save needs the form's token and an administrator.
	 * Passes when: the form sent without its nonce, and the form sent by an editor, change neither the picked page nor the switch; the same
	 *   form with its nonce, by the administrator, does.
	 * Fails when: a forged link moves the message centre to an unprotected page or changes the site's look.
	 */
	public function test_the_merged_save_needs_the_token_and_an_administrator(): void {
		$next   = $this->page( 'Elsewhere' );
		$fields = $this->saveForm()['fields'] ?? array( 'action' => self::SAVE );
		$fields['omnabase_portal']['page'] = (string) $next;
		$fields['omnabase_theme_only']     = '1';
		$before = array( get_option( 'omnabase_portal' ), get_option( 'omnabase_display' ) );

		$this->postFields( $fields, false );
		$this->assertSame( $before, array( get_option( 'omnabase_portal' ), get_option( 'omnabase_display' ) ), 'no nonce' );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->postFields( $fields );
		$this->assertSame( $before, array( get_option( 'omnabase_portal' ), get_option( 'omnabase_display' ) ), 'an editor' );
		wp_set_current_user( $this->admin );
		$this->postFields( $fields );
		$this->assertSame( $next, (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 ), 'the same form with its token, by the administrator, saves (the refusals above are real refusals)' );
	}

	/**
	 * What it checks: The two separate save handlers of the old screen are gone, merged into the one.
	 * Passes when: WordPress knows a handler for `omnabase_save_pages` and none for `omnabase_save_portal` or `omnabase_save_display`.
	 * Fails when: stray old forms or bookmarks keep saving half of the tab through a handler nobody tests any more.
	 */
	public function test_the_two_old_save_handlers_are_merged_into_one(): void {
		$this->assertNotFalse( has_action( 'admin_post_' . self::SAVE ) );
		$this->assertFalse( has_action( 'admin_post_omnabase_save_portal' ) );
		$this->assertFalse( has_action( 'admin_post_omnabase_save_display' ) );
	}
}
