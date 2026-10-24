<?php
/**
 * THE PLUGIN'S FORMS ROUND (2026-10-25), THE BUILDER: ADJUSTED, NOT WEAKENED. What changed and why: the shortcode no longer prints a `<form>`
 * of its own; it prints the container OmnaBase's embed draws the form in (`data-omnabase-form`, OmnaBase `docs/briefs/plugin-forms-round.md`).
 * The checks "the form shows" and "no form while not connected" now look for that container instead of `<form`; the rest is
 * unchanged.
 *
 * M14 round two, the red team's failing tests: the first-display marks, the time each of the two tags first showed on the public site (inside
 * wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 2: "The plugin records, as one stored setting (added to `Options::ALL`
 * and `uninstall.php`), when each tag first displayed on the public site; that mark, not a scan of page content (page builders keep content
 * elsewhere), tells the setup line and the site report that a form or the message centre is live"; choice 4: the setup line's last step is
 * "done … when the first-display mark of choice 2 is set"; "Order of work › Round two": "the first-display marks set when a tag renders".
 * Review S6 (a content scan misses page builders; record the first display instead). Round one (DECISIONS "2026-10-10 — M14 round one")
 * already reads the mark from `omnabase_display['first_shown']` and keeps it when the switches are saved; nothing writes it yet.
 *
 * The words: *shortcode*, a tag such as `[omnabase_form]` that WordPress replaces with the plugin's output; *render*, WordPress replacing the
 * tag with that output when someone views the page; *public site*, the pages visitors see, as opposed to wp-admin; *option*, a stored setting
 * (here `omnabase_display`, which also holds the two front-end switches); *administrator's note*, the line only administrators see when a tag
 * cannot work (site not connected, message centre on a page that is not the picked one).
 *
 * Shape pinned (round one's, kept): `get_option( 'omnabase_display' )['first_shown']` = `[ 'form' => <time>, 'messages' => <time> ]`, each
 * written once. Chosen here (the builder may argue and tell the red team): a render that shows only the administrator's note, and a render
 * inside wp-admin, set no mark; a later render makes no write to the option at all (a page view must not write to the database).
 *
 * Why these fail today: nothing writes `first_shown`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class FirstShownTest extends RoundSixTestCase {

	private const OPTION = 'omnabase_display';
	private const LONG_AGO = 1700000000;

	/** how often the option was written since `countWrites()` */
	private int $writes = 0;

	public function set_up(): void {
		parent::set_up();
		delete_option( self::OPTION );
		$this->writes = 0;
	}

	/** The marks as stored. */
	private static function marks(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) && is_array( $stored['first_shown'] ?? null ) ? $stored['first_shown'] : array();
	}

	/** Moves a mark back in time, as if the tag first showed long ago. */
	private static function backdate( string $tag ): void {
		$stored                        = (array) get_option( self::OPTION, array() );
		$stored['first_shown'][ $tag ] = self::LONG_AGO;
		update_option( self::OPTION, $stored, false );
	}

	/** From now on, counts every write of the option. */
	private function countWrites(): void {
		$this->writes = 0;
		$count        = function () {
			++$this->writes;
		};
		add_action( 'update_option_' . self::OPTION, $count );
		add_action( 'add_option_' . self::OPTION, $count );
	}

	/**
	 * What it checks: The first time the contact form shows on the public site, the plugin notes the time, once, and keeps the two switches.
	 * Passes when: with "theme styles only" on and no mark yet, a visitor's view of [omnabase_form] stores a "form" time between the moments
	 *   before and after the view, and the switch is still on; with that mark moved back to long ago, two more views (a visitor's and a
	 *   signed-in customer's) leave it at long ago and write nothing to the stored setting.
	 * Fails when: the setup line never learns that the form is live, the "first shown" time creeps forward with every visit, every page view
	 *   writes to the database, or noting the time switches the owner's styles setting off.
	 */
	public function test_the_first_form_render_stores_the_time_once(): void {
		update_option( self::OPTION, array( 'theme_only' => true ), false );
		$this->asVisitor();
		$before = time();
		$this->assertStringContainsString( 'data-omnabase-form', $this->render(), 'the form shows' );
		$marks = self::marks();
		$this->assertArrayHasKey( 'form', $marks, 'the form\'s mark' );
		$this->assertIsInt( $marks['form'] );
		$this->assertGreaterThanOrEqual( $before, $marks['form'] );
		$this->assertLessThanOrEqual( time(), $marks['form'] );
		$this->assertTrue( (bool) ( get_option( self::OPTION )['theme_only'] ?? false ), 'the theme switch is kept' );
		$this->assertArrayNotHasKey( 'messages', $marks, 'the message centre did not show' );

		self::backdate( 'form' );
		$this->countWrites();
		$this->asVisitor();
		$this->render( 'subject="Billing"' );
		$this->asUserOnTheFront( $this->ada );
		$this->render();
		$this->assertSame( self::LONG_AGO, self::marks()['form'] ?? null, 'the first time stays' );
		$this->assertSame( 0, $this->writes, 'later views write nothing' );
	}

	/**
	 * What it checks: The first time the message centre shows on its page, the plugin notes that time too, once.
	 * Passes when: a visitor's view of the picked message centre page (they see the sign-in link) stores a "messages" time and no "form" time;
	 *   with that mark moved back to long ago, a signed-in customer's view leaves it at long ago and writes nothing to the stored setting.
	 * Fails when: the message centre never counts as live, or its time changes with every visit.
	 */
	public function test_the_first_message_centre_render_stores_its_own_time_once(): void {
		$before = time();
		$html   = $this->view( 0 );
		$this->assertNotSame( '', trim( $html ), 'the visitor sees something (the sign-in link)' );
		$marks = self::marks();
		$this->assertArrayHasKey( 'messages', $marks );
		$this->assertGreaterThanOrEqual( $before, (int) $marks['messages'] );
		$this->assertArrayNotHasKey( 'form', $marks, 'no contact form showed' );

		self::backdate( 'messages' );
		$this->countWrites();
		$this->omnabaseKnows( $this->ada, true );
		$this->view( $this->ada );
		$this->assertSame( self::LONG_AGO, self::marks()['messages'] ?? null );
		$this->assertSame( 0, $this->writes );
	}

	/**
	 * What it checks: A tag that shows only the administrators' note does not count as live.
	 * Passes when: an administrator viewing [omnabase_messages] on a page that is not the picked one, and [omnabase_form] while the site is not
	 *   connected, sees the note and no mark is stored; in between, a visitor's view of the real form does store its mark.
	 * Fails when: the setup line ticks "Add the form to a page" although visitors see nothing on that page.
	 */
	public function test_a_note_only_administrators_see_sets_no_mark(): void {
		$elsewhere = $this->page( 'Elsewhere', '[omnabase_messages]' );
		$this->assertNotSame( '', trim( $this->view( $this->admin, array(), $elsewhere ) ), 'the administrator sees the note' );
		$this->assertSame( array(), self::marks(), 'the message centre on the wrong page' );
		$this->asVisitor();
		$this->render();
		$this->assertArrayHasKey( 'form', self::marks(), 'a visitor\'s real form does count (so the empty marks above were about the note)' );
		delete_option( self::OPTION );

		( new \OmnaBase\WordPress\Security\Vault() )->clear();
		delete_option( 'omnabase_site' );
		$this->asUserOnTheFront( $this->admin );
		$this->assertStringNotContainsString( 'data-omnabase-form', $this->render(), 'no form while not connected' );
		$this->assertSame( array(), self::marks(), 'the form while not connected' );
	}

	/**
	 * What it checks: Showing a tag inside wp-admin (a preview in an editor or a page builder) is not the public site.
	 * Passes when: [omnabase_form] rendered while an administrator is on an admin screen stores no mark; the same tag shown to a visitor on
	 *   the public site then stores it.
	 * Fails when: an owner previewing a draft in the editor is told the form is live on the site.
	 */
	public function test_rendering_inside_the_admin_sets_no_mark(): void {
		$this->asAdminOnTheScreen( $this->admin );
		$this->assertTrue( is_admin(), 'on an admin screen' );
		do_shortcode( '[omnabase_form]' );
		$this->assertSame( array(), self::marks() );
		$this->asVisitor();
		$this->render();
		$this->assertArrayHasKey( 'form', self::marks(), 'the same tag on the public site counts' );
	}

	/**
	 * What it checks: After the form first shows on the site, the setup line's last step reads done.
	 * Passes when: on a connected site with customer data not yet saved (so the setup line still shows), a visitor's view of [omnabase_form]
	 *   turns the step "Add the form to a page" on the Connection tab to done.
	 * Fails when: the owner keeps being told to add the form to a page that already has it.
	 */
	public function test_the_setup_lines_last_step_is_done_after_the_first_render(): void {
		delete_option( 'omnabase_facts' );
		delete_option( 'omnabase_setup' );
		$step = static fn ( string $html ) => self::attr( $html, "//*[@data-step='form']", 'data-done' );
		$this->assertSame( '0', $step( $this->screen( 'connection' ) ), 'not done before' );
		$this->asVisitor();
		$this->render();
		$this->assertSame( '1', $step( $this->screen( 'connection' ) ), 'done after the form showed' );
	}
}
