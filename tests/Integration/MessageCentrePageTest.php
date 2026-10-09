<?php
/**
 * M13 round five, the red team's failing tests: the page the administrator picks, kept out of every cache, and the settings screen's choice of
 * it (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 9: "`[omnabase_messages]` on the page the admin picks"; "**No
 * caching**: at `send_headers` (before any output) a request for the picked page gets `nocache_headers()`, `Cache-Control: private,
 * no-store`, `DONOTCACHEPAGE` and LiteSpeed's no-cache action; page builders that keep the shortcode outside the content are covered because
 * the page, not the content, is matched"; choice 2 ("the message-centre page" on the one settings screen); review S6 ("A shortcode runs after
 * headers are sent. Page builders (Elementor) keep shortcodes outside `post_content`"); round five's *Red team*: "the picked page carries
 * `no-store` and `DONOTCACHEPAGE` also when built with a page builder"; the plugin's `CLAUDE.md` (descriptions at most 30 words; a nonce and
 * `manage_options` on every admin action). The coordinator: "a page without the pick → an admin-only notice"; "no-cache headers early on the
 * page the admin picked"; round four's "Left for later rounds": a "Shortcodes" section naming `[omnabase_messages]`.
 *
 * Shapes the red team pins: `RoundFiveTestCase`. WordPress's own `send_headers` runs inside the test's request for the page (before the page's
 * query and content exist), so what the plugin does there is seen without building the page. The headers themselves cannot be read in a
 * command-line PHP (it records none): `DONOTCACHEPAGE` and LiteSpeed's action `litespeed_control_set_nocache` are checked here, the
 * `Cache-Control` header itself in `messages.spec.ts`. (chosen) The shortcode on any other page shows a logged-in customer nothing private and
 * the administrator a notice naming the page setting: a page that is not picked gets no no-cache headers, so it must never hold a list.
 *
 * Why these fail today: nothing acts on the picked page, there is no `[omnabase_messages]` and no settings section for it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class MessageCentrePageTest extends RoundFiveTestCase {

	private const NOCACHE_ACTION = 'litespeed_control_set_nocache';

	/**
	 * What it checks: The picked page is marked "never cache" while WordPress sends its headers, before anything of the page is built; other
	 *   pages are not.
	 * Passes when: requesting another page (even one holding the shortcode) fires no LiteSpeed no-cache action; requesting the picked page, as
	 *   a signed-in customer and as a visitor, fires it each time during the request itself (no content rendered), and `DONOTCACHEPAGE` is then
	 *   defined as true.
	 * Fails when: a caching plugin keeps one customer's message centre and serves it to the next visitor, or the whole site loses its cache.
	 */
	public function test_the_picked_page_is_marked_never_cache_while_headers_are_sent(): void {
		$other  = $this->page( 'About us', 'Plain page. [omnabase_messages]' );
		$before = did_action( self::NOCACHE_ACTION );
		$this->asUserOnTheFront( $this->ada );
		$this->go_to( (string) get_permalink( $other ) );
		$this->assertSame( $before, did_action( self::NOCACHE_ACTION ), 'another page keeps its cache' );

		$this->go_to( (string) get_permalink( $this->picked ) );
		$this->assertGreaterThan( $before, did_action( self::NOCACHE_ACTION ), 'the picked page, for a customer, at the request' );
		$customer = did_action( self::NOCACHE_ACTION );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'DONOTCACHEPAGE for page caches that read it' );
		$this->asUserOnTheFront( 0 );
		$this->go_to( (string) get_permalink( $this->picked ) );
		$this->assertGreaterThan( $customer, did_action( self::NOCACHE_ACTION ), 'and for a visitor (a cached logged-out copy would be served to customers too)' );
		$this->assertSame( array(), $this->portalCalls(), 'nothing was rendered: the marking happens before the page is built' );
	}

	/**
	 * What it checks: A page built with a page builder, whose stored content does not hold the shortcode, is covered too.
	 * Passes when: the administrator picks a page whose content is a page builder's markup without "[omnabase_messages]", and requesting it
	 *   fires the no-cache action.
	 * Fails when: on Elementor-style sites (the shortcode kept outside the content) the message centre is cached and one customer's list is
	 *   served to another.
	 */
	public function test_a_page_builder_page_is_covered_by_the_pick(): void {
		$built = $this->page( 'Help centre', '<div class="elementor" data-elementor-type="wp-page" data-elementor-id="7"></div>' );
		update_option( self::PORTAL_OPTION, array( 'page' => $built ) );
		$before = did_action( self::NOCACHE_ACTION );
		$this->asUserOnTheFront( $this->ada );
		$this->go_to( (string) get_permalink( $built ) );
		$this->assertGreaterThan( $before, did_action( self::NOCACHE_ACTION ) );
	}

	/**
	 * What it checks: The shortcode on a page that is not the picked one never shows a list; only the administrator learns why.
	 * Passes when: on another page holding the shortcode, and on any page while no page is picked, a signed-in customer sees none of her
	 *   tickets and no prompt and OmnaBase is not asked; the administrator sees a notice mentioning the page setting.
	 * Fails when: a list lands on a page the cache was never told about, or the owner never learns why the message centre is empty.
	 */
	public function test_the_shortcode_elsewhere_shows_only_the_administrator_a_notice(): void {
		$this->omnabaseKnows( $this->ada, false );
		$this->ticketFor( $this->ada, 'Ada private question' );
		$other = $this->page( 'Account', '[omnabase_messages]' );
		$cases = array(
			'another page'   => static fn () => null,
			'no page picked' => static fn () => delete_option( 'omnabase_portal' ),
		);
		foreach ( $cases as $case => $setup ) {
			$setup();
			$this->calls = array();
			$customer    = $this->view( $this->ada, array(), $other );
			$this->assertStringNotContainsString( 'Ada private question', $customer, "{$case}: no list" );
			$this->assertFalse( self::hasPrompt( $customer ), "{$case}: no prompt" );
			$this->assertSame( array(), $this->portalCalls(), "{$case}: OmnaBase not asked" );
			$this->assertStringNotContainsString( '[omnabase_messages', $customer, "{$case}: never the raw tag" );
			$admin = $this->view( $this->admin, array(), $other );
			$this->assertMatchesRegularExpression( '/page/i', self::text( $admin ), "{$case}: the administrator is told about the page setting" );
		}
	}

	/**
	 * What it checks: The settings screen lets the administrator pick the message centre's page and names the shortcode.
	 * Passes when: Settings › OmnaBase has a message-centre section with a list of the site's published pages (the current pick selected) and
	 *   the text "[omnabase_messages]"; saving another page with the form's nonce stores it.
	 * Fails when: the owner cannot choose where the message centre lives, so the no-cache protection never knows which page to guard.
	 */
	public function test_the_settings_screen_picks_the_page(): void {
		$next    = $this->page( 'My support', '[omnabase_messages]' );
		$section = $this->section();
		$this->assertNotSame( '', $section, 'the message-centre section' );
		$this->assertStringContainsString( '[omnabase_messages]', $section );
		$options = array_map( static fn ( $o ) => (string) $o->getAttribute( 'value' ), iterator_to_array( self::nodes( $section, "//select[@name='omnabase_portal[page]']/option" ) ) );
		$this->assertContains( (string) $this->picked, $options );
		$this->assertContains( (string) $next, $options );
		$this->assertSame( 1, self::nodes( $section, "//select[@name='omnabase_portal[page]']/option[@value='" . $this->picked . "'][@selected]" )->length, 'the current pick is selected' );
		$this->save( array( 'page' => (string) $next ) );
		$this->assertSame( $next, (int) ( get_option( self::PORTAL_OPTION )['page'] ?? 0 ) );
	}

	/**
	 * What it checks: Only an administrator with the form's nonce can change the pick.
	 * Passes when: the screen has the message-centre section; a save without the nonce, and one by an editor, leave the stored pick as it was.
	 * Fails when: a forged link or a lower account moves the message centre to a page without the no-cache protection.
	 */
	public function test_only_an_administrator_with_the_nonce_changes_the_pick(): void {
		$this->assertNotSame( '', $this->section(), 'the message-centre section' );
		$before = get_option( self::PORTAL_OPTION );
		$other  = $this->page( 'Cached page', 'Hello' );
		$this->asAdminOnTheScreen( $this->admin );
		$this->save( array( 'page' => (string) $other ), false );
		$this->assertSame( $before, get_option( self::PORTAL_OPTION ), 'no nonce' );
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->save( array( 'page' => (string) $other ) );
		$this->assertSame( $before, get_option( self::PORTAL_OPTION ), 'an editor' );
	}

	/**
	 * What it checks: The message-centre section's descriptions stay short (the plugin's settings copy rule).
	 * Passes when: every paragraph or description in the section has at most 30 words.
	 * Fails when: the section grows walls of text the site owner skips.
	 */
	public function test_the_sections_descriptions_are_at_most_30_words(): void {
		$section = $this->section();
		$this->assertNotSame( '', $section );
		foreach ( self::nodes( $section, '//p | //*[contains(concat(" ", normalize-space(@class), " "), " description ")]' ) as $node ) {
			$this->assertLessThanOrEqual( 30, str_word_count( self::text( (string) $node->textContent ) ), self::text( (string) $node->textContent ) );
		}
	}

	/** The settings screen's message-centre section, as the administrator sees it (empty when missing). */
	private function section(): string {
		$this->asAdminOnTheScreen( $this->admin );
		$nodes = self::nodes( $this->renderScreen(), '//*[contains(concat(" ", normalize-space(@class), " "), " ob-wp-settings__portal ")]' );
		return $nodes->length ? (string) $nodes->item( 0 )->ownerDocument->saveHTML( $nodes->item( 0 ) ) : '';
	}

	/**
	 * Posts the message-centre section's form to `admin-post.php` as the current user.
	 *
	 * @param array<string,string> $fields The fields under `omnabase_portal`.
	 * @param bool                 $nonce  Whether the nonce is sent.
	 */
	private function save( array $fields, bool $nonce = true ): void {
		$_POST    = array( 'action' => self::SAVE_PORTAL, 'omnabase_portal' => $fields ) + ( $nonce ? array( '_wpnonce' => wp_create_nonce( self::SAVE_PORTAL ) ) : array() );
		$_REQUEST = $_POST;
		try {
			do_action( 'admin_post_' . self::SAVE_PORTAL );
		} catch ( Redirected $r ) {
			unset( $r );
		} catch ( \WPDieException $e ) {
			unset( $e );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
	}
}
