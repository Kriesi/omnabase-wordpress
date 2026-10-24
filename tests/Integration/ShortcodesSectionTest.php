<?php
/**
 * THE PLUGIN'S FORMS ROUND (2026-10-25), THE BUILDER (beside the red team's adjustment below): ADJUSTED, NOT WEAKENED. What changed and
 * why: the shortcode no longer prints a `<form>` of its own; it prints the container OmnaBase's embed draws the form in (`data-omnabase-form`,
 * OmnaBase `docs/briefs/plugin-forms-round.md`). The check "the form still renders" now looks for that container; the rest is unchanged.
 *
 * THE PLUGIN'S FORMS ROUND (2026-10-25), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the brief (OmnaBase
 * `docs/briefs/plugin-forms-round.md`, outcome 1) gives the form's shortcode an `id` attribute, the form built in OmnaBase to show
 * (`[omnabase_form id="<form slug>"]`). The Shortcodes section's test now also asks that `id` is explained beside guest, project and subject;
 * nothing it asked before was removed (`subject` stays: report question 7's default keeps it as the subject field's pre-wording).
 *
 * THE FRONT-END DESIGN ROUND (2026-10-22), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the brief (OmnaBase
 * `docs/briefs/plugin-frontend-design.md`, rule 2) moves "Theme styles only" to the new Appearance tab ("the moved switch … the plugin's
 * stylesheet off entirely"). The three switch tests now read and flip it on the Appearance tab (`screen( 'appearance' )`, `flip( …, 'appearance' )`)
 * and look for it inside the Appearance card (`ob-wp-settings__appearance`) instead of the Shortcodes section; what they ask of it (off by
 * default, both pieces lose and regain the stylesheet, the nonce) is unchanged. The Shortcodes section's own test is unchanged.
 *
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the Shortcodes block moves to the "Forms and messages" tab, keeping
 * its class `ob-wp-settings__shortcodes`, its `h2` "Shortcodes" and the theme switch inside it (brief `docs/briefs/m14-settings-cleanup.md`,
 * choice 1; review S1: "the `ShortcodesSectionTest` moves with it"). Every read of the screen now opens that tab (`screen( 'pages' )`), and
 * `flip()` reads its switch there too. The assertions are unchanged.
 *
 * M13 round six, the red team's failing tests: the settings screen's own "Shortcodes" section and its "theme styles only" switch.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 2: "the shortcodes; the message-centre page"; Q4 (the founder's
 * default): "a small stylesheet from the kit's tokens, the theme's font, a switch for 'theme styles only'"; the plugin's DECISIONS "M13 round
 * four" ("the settings screen's switch 'theme styles only' (Q4) waits for the shortcodes section") and "M13 round five" ("Left for round six
 * … the 'theme styles only' switch"); the plugin's `CLAUDE.md` ("Every description on the settings screen … is at most 30 words"). The
 * coordinator: "the theme-styles switch (on → `assets/form.css` not enqueued), the Shortcodes section (the two shortcodes with their
 * attributes in words ≤ 30 each)".
 *
 * Shapes pinned (`RoundSixTestCase`): the section `.ob-wp-settings__shortcodes` with an h2 "Shortcodes"; the switch a checkbox labelled
 * "theme styles only" inside it, saved through its own form (whatever its action), with the form's nonce. The stylesheet's handle stays
 * round four's `omnabase-form` (`assets/form.css`).
 *
 * Why these fail today: the shortcodes are a small list under the message centre (an h3, no section of their own) and there is no switch.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class ShortcodesSectionTest extends RoundSixTestCase {

	private const THEME_ONLY = '/theme(\'s)? styles only/i';

	/**
	 * What it checks: Settings › OmnaBase has a "Shortcodes" section that names both shortcodes and every attribute in plain words.
	 * Passes when: a section with the heading "Shortcodes" names [omnabase_form] and [omnabase_messages] and the attributes id (the form built
	 *   in OmnaBase), guest, project and subject, and every line in it has 30 words or fewer.
	 * Fails when: a site owner has to guess the shortcodes or what an attribute does, or the help grows into paragraphs nobody reads.
	 */
	public function test_the_shortcodes_section_names_both_shortcodes_and_their_attributes(): void {
		$section = self::section( $this->screen( 'pages' ), 'ob-wp-settings__shortcodes' );
		$this->assertNotSame( '', $section, 'the Shortcodes section' );
		$this->assertCount( 1, iterator_to_array( self::nodes( $section, "//h2[normalize-space(.)='Shortcodes']" ) ), 'its own heading "Shortcodes" (a section of the screen, not a note under another one)' );
		$text = self::text( $section );
		foreach ( array( '[omnabase_form', '[omnabase_messages' ) as $tag ) {
			$this->assertStringContainsString( $tag, $text );
		}
		foreach ( array( 'id', 'guest', 'project', 'subject' ) as $attribute ) {
			$this->assertMatchesRegularExpression( '/\b' . $attribute . '\b/', $text, "the attribute {$attribute} is explained" );
		}
		foreach ( self::lines( $section ) as $line ) {
			$this->assertLessThanOrEqual( 30, str_word_count( (string) preg_replace( '/\[[^\]]*\]/', '', $line ) ), $line );
		}
	}

	/**
	 * What it checks: The "theme styles only" switch sits on the Appearance tab and is off until the site owner turns it on.
	 * Passes when: the Appearance card holds a checkbox labelled "theme styles only", not ticked on a new site, and with it off the contact
	 *   form and the message centre still load the plugin's small stylesheet.
	 * Fails when: the switch is missing, or starts on so a new site's form looks unstyled.
	 */
	public function test_the_theme_styles_switch_is_offered_and_off_by_default(): void {
		// Builder's adjustment (M14 round one, DECISIONS "2026-10-10 — M14 round one"): the tab's one save form wraps the whole Shortcodes
		// block, so the block's own HTML holds no form and the form-reading helper found nothing in it. The switch is read with its form from
		// the whole tab, and a second assertion still pins it inside the Shortcodes block.
		$html = $this->screen( 'appearance' );
		$box  = self::checkbox( $html, self::THEME_ONLY );
		$this->assertNotNull( $box, 'the switch, inside the tab\'s save form' );
		$this->assertSame( 1, self::nodes( self::section( $html, 'ob-wp-settings__appearance' ), "//input[@type='checkbox'][@name='" . $box['name'] . "']" )->length, 'the switch inside the Appearance card' );
		$this->assertFalse( $box['checked'] );

		self::freshQueue();
		$this->asVisitor();
		$this->render();
		$this->assertTrue( $this->formCssQueued(), 'the form loads the stylesheet while the switch is off' );
		self::freshQueue();
		$this->omnabaseKnows( $this->ada, true );
		$this->view( $this->ada );
		$this->assertTrue( $this->formCssQueued(), 'the message centre loads it too' );
	}

	/**
	 * What it checks: With "theme styles only" on, neither the contact form nor the message centre loads the plugin's stylesheet; off again,
	 *   both load it.
	 * Passes when: after ticking the switch through its form, rendering [omnabase_form] and the message centre queues no assets/form.css; after
	 *   unticking it, both queue it again.
	 * Fails when: a theme's own design is overridden by the plugin's look although the owner asked for the theme alone, or the switch cannot
	 *   be undone.
	 */
	public function test_theme_styles_only_keeps_the_stylesheet_away_from_both_pieces(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->flip( self::THEME_ONLY, true, true, 'appearance' );

		self::freshQueue();
		$this->asVisitor();
		$this->assertStringContainsString( 'data-omnabase-form', $this->render(), 'the form still renders' );
		$this->assertFalse( $this->formCssQueued(), 'no stylesheet for the form' );
		self::freshQueue();
		$this->view( $this->ada );
		$this->assertFalse( $this->formCssQueued(), 'no stylesheet for the message centre' );

		$this->flip( self::THEME_ONLY, false, true, 'appearance' );
		self::freshQueue();
		$this->asVisitor();
		$this->render();
		$this->assertTrue( $this->formCssQueued(), 'turned off again, the stylesheet is back' );
	}

	/**
	 * What it checks: The switch changes only with the form's token, from an administrator.
	 * Passes when: posting the switch's form ticked but without its nonce leaves it off, and the stylesheet still loads.
	 * Fails when: another site can make an administrator's browser change how the shop's forms look.
	 */
	public function test_the_switch_needs_the_forms_token(): void {
		$this->flip( self::THEME_ONLY, true, false, 'appearance' );
		$box = self::checkbox( $this->screen( 'appearance' ), self::THEME_ONLY );
		$this->assertNotNull( $box );
		$this->assertFalse( $box['checked'], 'still off' );
		self::freshQueue();
		$this->asVisitor();
		$this->render();
		$this->assertTrue( $this->formCssQueued() );
	}
}
