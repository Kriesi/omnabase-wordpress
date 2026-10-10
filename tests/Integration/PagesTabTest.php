<?php
/**
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
	 * What it checks: One form on the Forms and messages tab holds the page picker and the Shortcodes block with its theme switch.
	 * Passes when: the tab's one save form (action `omnabase_save_pages`) contains the page picker inside `.ob-wp-settings__portal` and the
	 *   "theme's styles only" checkbox inside `.ob-wp-settings__shortcodes`, whose heading is "Shortcodes"; the tab's name reads "Forms and
	 *   messages".
	 * Fails when: the picker and the switch need two saves (an owner changes both, saves one and loses the other), or the Shortcodes block loses
	 *   its heading or its switch.
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
		$this->assertSame( 1, $xpath->query( ".//input[@type='checkbox'][@name='omnabase_theme_only']", $block )->length, 'the theme switch inside the block' );
	}

	/**
	 * What it checks: One save stores the picked page and the theme switch together and returns to the tab.
	 * Passes when: sending the form with a new page picked and the switch ticked stores both and redirects to the pages tab; sending it again
	 *   with no page and the switch unticked clears both.
	 * Fails when: one half of the tab is silently not saved, or the owner lands on another tab after saving.
	 */
	public function test_one_save_stores_the_page_and_the_switch(): void {
		$next = $this->page( 'Help centre' );
		$form = $this->saveForm();
		$this->assertNotNull( $form );
		$fields                             = $form['fields'];
		$fields['omnabase_portal']['page']  = (string) $next;
		$fields['omnabase_theme_only']      = '1';

		$this->assertSame( 'pages', $this->tabIn( $this->postFields( $fields ) ) );
		$this->assertSame( $next, (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 ) );
		$this->assertTrue( (bool) ( get_option( 'omnabase_display' )['theme_only'] ?? false ) );

		$fields                            = $this->saveForm()['fields'];
		$fields['omnabase_portal']['page'] = '0';
		unset( $fields['omnabase_theme_only'] );
		$this->postFields( $fields );
		$this->assertSame( 0, (int) ( get_option( 'omnabase_portal' )['page'] ?? 0 ) );
		$this->assertFalse( (bool) ( get_option( 'omnabase_display' )['theme_only'] ?? true ) );
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
