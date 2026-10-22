<?php
/**
 * The front-end design round (2026-10-22), the red team's failing tests: the settings screen's new Appearance tab, its choices, its one Save,
 * and how it lives beside the Forms and messages tab (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/plugin-frontend-design.md`) rule 2 (quoted in `AppearanceTestCase`) and rule 7: "Copy under 30 words per line on
 * the Appearance tab (the plugin's settings copy rules as M14)". The plugin's `CLAUDE.md`, "Settings copy": every description at most 30 words,
 * one sentence; "Security": "a nonce and `current_user_can( 'manage_options' )` on every admin action". The red team's report, findings on the
 * moved switch and the colour field.
 *
 * The words: *tab*, *radio button*, *admin-post*, *nonce* as in `AppearanceTestCase`; *capability*, a permission (`manage_options`: an
 * administrator's); *option*, a stored setting.
 *
 * Why these fail today: the screen has five tabs; `&tab=appearance` opens Connection; there is no Appearance form, no theme, accent or font
 * choice; "Theme styles only" sits on the Forms and messages tab; there is no `Options::APPEARANCE`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Options;

final class AppearanceTabTest extends AppearanceTestCase {

	/**
	 * What it checks: Settings › OmnaBase has an Appearance tab right after Forms and messages, and it opens by its own address.
	 * Passes when: the tab row names "Appearance" with the slug appearance, directly after Forms and messages and before Activity; opening it
	 *   marks it (and only it) as open and shows its card.
	 * Fails when: the owner cannot find where to choose light or dark, or the address of the tab opens Connection instead.
	 */
	public function test_the_appearance_tab_follows_forms_and_messages(): void {
		$html  = $this->appearanceTab();
		$slugs = array_keys( self::navTabs( $html ) );
		$this->assertSame( 'Appearance', self::navTabs( $html )['appearance'] ?? null, 'a tab named Appearance' );
		$this->assertSame( array_search( 'pages', $slugs, true ) + 1, array_search( 'appearance', $slugs, true ), 'right after Forms and messages' );
		$this->assertSame( array_search( 'appearance', $slugs, true ) + 1, array_search( 'activity', $slugs, true ), 'right before Activity' );
		$this->assertSame( array( 'appearance' ), self::openTabs( $html ), 'the Appearance tab is the open one' );
		$this->assertSame( 1, self::countClass( $html, self::SECTION ), 'its card' );
	}

	/**
	 * What it checks: The tab holds the four choices, each a row with a short label and help, inside one form with one blue Save, and a new site
	 *   starts light, in OmnaBase's blue, in the theme's font, with the plugin's stylesheet on.
	 * Passes when: the tab's one save form holds rows labelled Theme, Accent, Font and "Theme styles only"; Theme offers Light, Dark and "Follow
	 *   the visitor's system" with Light picked; Accent offers OmnaBase's blue (picked) and the owner's own colour with a colour field; Font offers
	 *   "Inherit the theme's font" (picked) and "Load OmnaBase's font"; "Theme styles only" is unticked; the form has exactly one submit, a
	 *   primary "Save"; every help line and the card's intro have at most 30 words.
	 * Fails when: a choice is missing or sits outside the form (an owner changes it and it is never saved), a new site starts dark or loads a
	 *   font nobody asked for, or the help grows into paragraphs.
	 */
	public function test_the_tab_holds_four_choices_in_one_form_with_the_defaults(): void {
		$html = $this->appearanceTab();
		$form = $this->appearanceForm( $html );
		$this->assertNotNull( $form, 'one save form (action ' . self::SAVE . ')' );
		$node  = $form['node'];
		$xpath = new \DOMXPath( $node->ownerDocument );
		$labels = array_map( static fn ( $l ) => self::wordsOf( $l ), iterator_to_array( $xpath->query( './/*[' . self::hasClass( 'ob-form-row__label' ) . ']', $node ) ) );
		foreach ( array( '/^Theme$/', '/^Accent$/', '/^Font$/', self::THEME_ONLY ) as $row ) {
			$this->assertNotEmpty( preg_grep( $row, $labels ), "a row labelled {$row}: " . implode( ' | ', $labels ) );
		}
		$this->assertTrue( self::picked( $node, self::THEMES['light'] ), 'Light, picked on a new site' );
		$this->assertFalse( self::picked( $node, self::THEMES['dark'] ), 'Dark, offered' );
		$this->assertFalse( self::picked( $node, self::THEMES['system'] ), 'Follow the visitor\'s system, offered' );
		$this->assertTrue( self::picked( $node, self::ACCENT_BLUE ), 'OmnaBase\'s blue, picked' );
		$this->assertFalse( self::picked( $node, self::ACCENT_OWN ), 'the owner\'s own colour, offered' );
		$this->assertSame( 1, $xpath->query( ".//input[@type='color']", $node )->length, 'a colour field' );
		$this->assertTrue( self::picked( $node, self::FONT_INHERIT ), 'the theme\'s font, picked' );
		$this->assertFalse( self::picked( $node, self::FONT_LOAD ), 'OmnaBase\'s font, offered' );
		$this->assertFalse( self::picked( $node, self::THEME_ONLY, 'checkbox' ), '"Theme styles only" is off' );
		$this->assertSame( array( array( 'words' => 'Save', 'primary' => true ) ), self::submitsOf( $node ), 'one primary Save' );
		foreach ( iterator_to_array( self::query( $html, '//*[' . self::hasClass( 'ob-form-row__help' ) . ' or ' . self::hasClass( 'ob-form__intro' ) . ']' ) ) as $help ) {
			$this->assertLessThanOrEqual( 30, str_word_count( self::wordsOf( $help ) ), 'at most 30 words: ' . self::wordsOf( $help ) );
		}
	}

	/**
	 * What it checks: One Save stores every choice, brings the owner back to the tab, and the tab then shows what was saved.
	 * Passes when: saving Dark, the owner's teal (#0f7c8a), OmnaBase's font and "Theme styles only" returns to the Appearance tab, which then
	 *   shows Dark, the own colour with #0f7c8a in its field, OmnaBase's font and the switch ticked; saving Follow the visitor's system,
	 *   OmnaBase's blue, the theme's font and the switch off shows those again.
	 * Fails when: a choice is silently not saved, the owner lands on another tab, or the tab shows the defaults after a save so the owner
	 *   cannot tell what the site uses.
	 */
	public function test_one_save_stores_every_choice_and_returns_to_the_tab(): void {
		$this->assertSame( self::TAB, $this->tabIn( $this->saveAppearance( array( 'theme' => 'dark', 'accent' => self::TEAL, 'font' => 'load', 'themeOnly' => true ) ) ) );
		$node = $this->appearanceForm()['node'] ?? null;
		$this->assertNotNull( $node );
		$this->assertTrue( self::picked( $node, self::THEMES['dark'] ), 'Dark' );
		$this->assertTrue( self::picked( $node, self::ACCENT_OWN ), 'the own colour' );
		$this->assertSame( self::TEAL, strtolower( (string) ( new \DOMXPath( $node->ownerDocument ) )->query( ".//input[@type='color']", $node )->item( 0 )?->getAttribute( 'value' ) ), 'its value' );
		$this->assertTrue( self::picked( $node, self::FONT_LOAD ), 'OmnaBase\'s font' );
		$this->assertTrue( self::picked( $node, self::THEME_ONLY, 'checkbox' ), 'the switch' );

		$this->saveAppearance( array( 'theme' => 'system', 'accent' => 'blue', 'font' => 'inherit', 'themeOnly' => false ) );
		$node = $this->appearanceForm()['node'] ?? null;
		$this->assertTrue( self::picked( $node, self::THEMES['system'] ), 'the visitor\'s system' );
		$this->assertTrue( self::picked( $node, self::ACCENT_BLUE ), 'OmnaBase\'s blue' );
		$this->assertTrue( self::picked( $node, self::FONT_INHERIT ), 'the theme\'s font' );
		$this->assertFalse( self::picked( $node, self::THEME_ONLY, 'checkbox' ), 'the switch off' );
	}

	/**
	 * What it checks: The Appearance save needs the form's token and an administrator.
	 * Passes when: the form sent with Dark but without its nonce, and the same form sent by an editor, leave Light picked; the same form with
	 *   its nonce, by the administrator, picks Dark.
	 * Fails when: another site can make an administrator's browser turn the shop's forms dark, or an editor can change the site's look.
	 */
	public function test_the_save_needs_the_token_and_an_administrator(): void {
		$this->saveAppearance( array( 'theme' => 'dark' ), false );
		$this->assertTrue( self::picked( $this->appearanceForm()['node'], self::THEMES['light'] ), 'no nonce: still Light' );

		$form   = $this->appearanceForm();
		$fields = $form['fields'];
		$this->choose( $fields, $form['node'], self::THEMES['dark'], 'radio' );
		$editor = (int) self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$_POST    = $fields;
		$_REQUEST = $fields;
		try {
			do_action( 'admin_post_' . self::SAVE );
		} catch ( Redirected | \WPDieException $e ) {
			unset( $e ); // refused or sent away: either way nothing may change
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		$this->assertTrue( self::picked( $this->appearanceForm()['node'], self::THEMES['light'] ), 'an editor: still Light' );

		$this->saveAppearance( array( 'theme' => 'dark' ) );
		$this->assertTrue( self::picked( $this->appearanceForm()['node'], self::THEMES['dark'] ), 'the administrator with the token: Dark (the refusals above were real refusals)' );
	}

	/**
	 * What it checks: Only a real colour is kept as the owner's accent; anything else leaves OmnaBase's blue and never reaches the page.
	 * Passes when: saving the own colour with `red;background:url(https://evil.test/x.png)`, with `#12345g` and with `javascript:alert(1)` each
	 *   leaves the stored accent OmnaBase's blue (the tab shows the blue picked), and the contact form's output afterwards carries no accent mark
	 *   and none of those words.
	 * Fails when: text typed into the colour field (or sent by a forged form) is printed into the page's styles, so a stranger's picture loads
	 *   for every visitor or the page's look breaks.
	 */
	public function test_only_a_real_colour_is_kept(): void {
		foreach ( array( 'red;background:url(https://evil.test/x.png)', '#12345g', 'javascript:alert(1)' ) as $bad ) {
			$this->saveAppearance( array( 'accent' => $bad ) );
			$this->assertTrue( self::picked( $this->appearanceForm()['node'], self::ACCENT_BLUE ), "{$bad}: OmnaBase's blue stays" );
			$html    = $this->formAsVisitor();
			$wrapper = self::wrapperOf( $html );
			$this->assertNotNull( $wrapper, 'the form\'s wrapper' );
			$this->assertFalse( $wrapper->hasAttribute( 'data-accent' ), "{$bad}: no accent mark" );
			foreach ( array( 'evil.test', '12345g', 'javascript' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $html, "{$bad}: nothing of it in the page" );
			}
		}
	}

	/**
	 * What it checks: Saving the Forms and messages tab keeps the Appearance choices, and saving Appearance keeps the Forms and messages ones.
	 * Passes when: with Dark and "Theme styles only" saved on Appearance, saving the Forms and messages tab as its form stands (a new page
	 *   picked) leaves Dark and the switch on; then saving Appearance (Light) keeps the picked page and the "first shown" marks.
	 * Fails when: moving the switch leaves the Forms and messages save still writing it, so every save of that tab silently turns "Theme styles
	 *   only" off again (today's handler stores the switch as "not ticked" whenever it is not in the posted form).
	 */
	public function test_each_tab_keeps_the_others_settings(): void {
		$this->saveAppearance( array( 'theme' => 'dark', 'themeOnly' => true ) );
		$picked = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Help desk' ) );
		$pages  = array_values( array_filter( self::formsIn( $this->screen( 'pages' ) ), static fn ( $f ) => 'omnabase_save_pages' === $f['action'] ) );
		$this->assertNotEmpty( $pages, 'the Forms and messages form' );
		$fields                            = $pages[0]['fields'];
		$fields['omnabase_portal']['page'] = (string) $picked;
		$this->postAdmin( $fields );
		$node = $this->appearanceForm()['node'];
		$this->assertTrue( self::picked( $node, self::THEMES['dark'] ), 'Dark survives a save of Forms and messages' );
		$this->assertTrue( self::picked( $node, self::THEME_ONLY, 'checkbox' ), '"Theme styles only" survives a save of Forms and messages' );

		$display = get_option( Options::DISPLAY, array() );
		$display = ( is_array( $display ) ? $display : array() ) + array( 'first_shown' => array( 'form' => 1700000000 ) );
		update_option( Options::DISPLAY, $display, false );
		$this->saveAppearance( array( 'theme' => 'light' ) );
		$this->assertSame( $picked, (int) ( get_option( Options::PORTAL )['page'] ?? 0 ), 'the picked page survives a save of Appearance' );
		$this->assertSame( 1700000000, (int) ( get_option( Options::DISPLAY )['first_shown']['form'] ?? 0 ), 'the first-shown mark survives' );
	}

	/**
	 * What it checks: The look's choices live in one stored setting of their own that uninstall removes with the others.
	 * Passes when: the plugin's list of stored settings names an Appearance entry, and after a save of the tab that setting exists.
	 * Fails when: the choices are spread over several settings (or hidden in another one), so uninstall leaves one behind or a later change
	 *   to one tab overwrites another's.
	 */
	public function test_the_choices_live_in_one_listed_setting(): void {
		$this->assertTrue( defined( Options::class . '::APPEARANCE' ), 'Options::APPEARANCE' );
		$name = (string) constant( Options::class . '::APPEARANCE' );
		$this->assertContains( $name, Options::ALL, 'on the list uninstall removes' );
		$this->saveAppearance( array( 'theme' => 'dark' ) );
		$this->assertIsArray( get_option( $name ), 'the choices are stored there' );
	}
}
