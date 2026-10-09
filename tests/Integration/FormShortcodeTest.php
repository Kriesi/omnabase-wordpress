<?php
/**
 * M13 round four, the red team's failing tests: what the shortcode form shows (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 8: "`[omnabase_form]` (optional `project="<slug>"`, `subject="…"`)
 * renders name, email (prefilled when logged in), subject, message, a product select and a licence field when the project's form settings ask
 * for them, and the project's privacy link (from `GET /forms/{slug}`, an OmnaBase addition, cached an hour)"; "On load the form's JS fetches a
 * single-use time token from the plugin; … a cached page never shares one"; choice 10: "The key and secrets never reach HTML, JS or a log";
 * round four's *Red team*: "the product list and privacy link follow the project". The coordinator: "the project from the attribute, else the
 * key's single project, else a plain notice for the admin only"; "a cached page never carries a usable token: a test that two renders differ
 * or that the token is not in the HTML"; "the privacy link under the form (the project's privacy setting from OmnaBase or the site's privacy
 * page)"; "escaping and a nonce where a logged-in user posts, no key in the page".
 *
 * Shapes the red team pins: `RoundFourTestCase`. (chosen) When OmnaBase cannot be asked for the settings, the form still shows its four
 * fields (a visitor can still write); only an administrator ever sees the "which project?" notice (WordPress's `manage_options` capability,
 * the administrator's permission).
 *
 * Why these fail today: no shortcode is registered, so `[omnabase_form]` stays as typed and nothing asks OmnaBase.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Security\Vault;

final class FormShortcodeTest extends RoundFourTestCase {

	/**
	 * What it checks: A visitor sees the form with the fields the project asks for, the project's products to pick from and the team's privacy
	 *   link, and the form's own script is loaded.
	 * Passes when: `[omnabase_form project="enfold"]` shows inputs for name, email and subject, a message box, a product list holding exactly
	 *   "Enfold" and "Enfold Pro", a licence field and a link to the project's privacy address; OmnaBase was asked for the project's settings;
	 *   a script of the plugin's whose name holds "form" is queued.
	 * Fails when: the form misses fields, offers products the team does not sell, hides the privacy policy, or cannot fetch its token.
	 */
	public function test_the_form_shows_its_fields_the_projects_products_licence_and_privacy_link(): void {
		$html = $this->render( 'project="enfold"' );

		foreach ( array( 'name', 'email', 'subject' ) as $field ) {
			$this->assertSame( 1, self::nodes( $html, "//form//input[@name='{$field}']" )->length, "the {$field} field" );
		}
		$this->assertSame( 1, self::nodes( $html, "//form//textarea[@name='message']" )->length, 'the message box' );
		$this->assertSame( array( 'Enfold', 'Enfold Pro' ), array_values( array_filter( self::texts( $html, "//form//select[@name='product']/option[normalize-space(@value)!='']" ) ) ), 'the project\'s products' );
		$this->assertSame( 1, self::nodes( $html, "//form//input[@name='licence']" )->length, 'the licence field the project asks for' );
		$this->assertGreaterThan( 0, self::nodes( $html, "//a[@href='" . self::PRIVACY . "']" )->length, 'the project\'s privacy link' );
		$this->assertCount( 1, $this->settingsCalls(), 'OmnaBase was asked for the project\'s form settings' );
		$scripts = array_filter( wp_scripts()->queue, static fn ( $h ) => preg_match( '#omnabase.*/[^/]*form[^/]*\.js#', (string) ( wp_scripts()->registered[ $h ]->src ?? '' ) ) );
		$this->assertNotEmpty( $scripts, 'the form\'s own script is queued' );
	}

	/**
	 * What it checks: Without the attribute the form uses the project the site's key is limited to.
	 * Passes when: `[omnabase_form]` on a site whose key has the one project Enfold shows the form for "enfold" (a hidden project field or the
	 *   form's data attribute) and asks OmnaBase for Enfold's settings.
	 * Fails when: a site owner must look up and type a slug although the site has only one project.
	 */
	public function test_without_the_attribute_the_keys_single_project_is_used(): void {
		$html = $this->render();
		$this->assertGreaterThan( 0, self::nodes( $html, "//form[@data-project='enfold'] | //form[.//input[@name='project' and @value='enfold']]" )->length, 'the form is Enfold\'s' );
		$this->assertCount( 1, $this->settingsCalls(), 'Enfold\'s settings asked for' );
	}

	/**
	 * What it checks: When the form cannot tell which project it belongs to, only the administrator is told, in a plain notice; visitors see
	 *   nothing broken.
	 * Passes when: on a site whose key has two projects, `[omnabase_form]` without the attribute, `[omnabase_form project="photoshop"]` (not
	 *   one of the key's) and the form on a site that is not connected each show a visitor and an editor nothing (no form, no text) and an
	 *   administrator a notice mentioning the project.
	 * Fails when: visitors meet an error message about the site's setup, or the owner never learns why the form does not show.
	 */
	public function test_an_unclear_project_shows_the_administrator_a_notice_and_everyone_else_nothing(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$cases  = array(
			'two projects, no attribute' => array( fn () => $this->connectedWith( array( array( 'id' => 'p-1', 'slug' => 'enfold', 'name' => 'Enfold' ), array( 'id' => 'p-2', 'slug' => 'omnalingo', 'name' => 'Omnalingo' ) ) ), '' ),
			'a project the key lacks'    => array( fn () => null, 'project="photoshop"' ),
			'not connected'              => array(
				static function () {
					( new Vault() )->clear();
					delete_option( 'omnabase_site' );
				},
				'',
			),
		);
		foreach ( $cases as $case => [ $setup, $atts ] ) {
			$setup();
			$this->asVisitor();
			$visitor = $this->render( $atts );
			$this->asUserOnTheFront( $editor );
			$edited = $this->render( $atts );
			$this->asUserOnTheFront( $this->admin );
			$admin = $this->render( $atts );
			foreach ( array( 'a visitor' => $visitor, 'an editor' => $edited ) as $who => $out ) {
				$this->assertStringNotContainsString( '<form', $out, "{$case}: {$who} sees no form" );
				$this->assertSame( '', trim( wp_strip_all_tags( $out ) ), "{$case}: {$who} sees no text" );
				$this->assertStringNotContainsString( '[omnabase_form', $out, "{$case}: {$who} never sees the raw shortcode" );
			}
			$this->assertMatchesRegularExpression( '/project/i', wp_strip_all_tags( $admin ), "{$case}: the administrator is told about the project" );
		}
	}

	/**
	 * What it checks: The product list and the licence field appear only when the project asks for them, and the privacy link falls back to
	 *   the site's own privacy page.
	 * Passes when: with no products, no licence and no privacy address in the project's settings, the form has no product list and no licence
	 *   field and links the site's privacy policy page; with no privacy page either, no privacy link at all.
	 * Fails when: visitors pick from an empty list, are asked for a licence nobody needs, or the form links a privacy page that does not exist.
	 */
	public function test_without_products_licence_or_privacy_address_the_form_falls_back(): void {
		$this->formSettings( array( 'products' => array(), 'askLicense' => false, 'privacyUrl' => null ) );
		$page = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy policy' ) );
		update_option( 'wp_page_for_privacy_policy', $page );

		$html = $this->render( 'project="enfold"' );
		$this->assertSame( 0, self::nodes( $html, "//select[@name='product']" )->length, 'no product list' );
		$this->assertSame( 0, self::nodes( $html, "//input[@name='licence']" )->length, 'no licence field' );
		$this->assertGreaterThan( 0, self::nodes( $html, "//a[@href='" . get_privacy_policy_url() . "']" )->length, 'the site\'s privacy page' );

		delete_option( 'wp_page_for_privacy_policy' );
		$html = $this->render( 'project="enfold"' );
		$this->assertSame( 0, self::nodes( $html, "//a[contains(translate(@href,'PRIVACY','privacy'),'privacy')]" )->length, 'no privacy link to nowhere' );
	}

	/**
	 * What it checks: The project's settings are kept for a while, so a busy page does not ask OmnaBase on every view.
	 * Passes when: two renders of the form ask OmnaBase for the settings once.
	 * Fails when: every page view costs an API call, and a popular contact page uses up the site's allowance.
	 */
	public function test_the_settings_are_asked_once_for_two_views(): void {
		$this->render( 'project="enfold"' );
		$this->render( 'project="enfold"' );
		$this->assertCount( 1, $this->settingsCalls() );
	}

	/**
	 * What it checks: While OmnaBase cannot be reached, visitors still find a form to write in.
	 * Passes when: with the settings call failing, the form still shows name, email, subject and message, without a product list or licence
	 *   field.
	 * Fails when: a short outage at OmnaBase makes the site's contact page empty.
	 */
	public function test_the_form_still_shows_when_omnabase_cannot_be_asked(): void {
		$this->formSettings( new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
		$html = $this->render( 'project="enfold"' );
		foreach ( array( 'name', 'email', 'subject' ) as $field ) {
			$this->assertSame( 1, self::nodes( $html, "//form//input[@name='{$field}']" )->length, "the {$field} field" );
		}
		$this->assertSame( 1, self::nodes( $html, "//form//textarea[@name='message']" )->length );
		$this->assertSame( 0, self::nodes( $html, "//select[@name='product'] | //input[@name='licence']" )->length );
	}

	/**
	 * What it checks: A page cache can keep the form's page without breaking it: the page never holds a token.
	 * Passes when: two views of the form are the same HTML; no field named "token" carries a value; a token fetched from the time-token route
	 *   appears neither in the form nor in the page's scripts.
	 * Fails when: a cached page hands every visitor the same token, so the second visitor's message is refused or counted as the first's.
	 */
	public function test_no_token_is_ever_in_the_page(): void {
		$first  = $this->render( 'project="enfold"' );
		$assets = $this->pageAssets();
		$second = $this->render( 'project="enfold"' );
		$this->assertNotSame( '', trim( $first ), 'the form renders' );
		$this->assertSame( $first, $second, 'two views are the same page' );
		foreach ( self::nodes( $first, "//input[@name='token']" ) as $input ) {
			$this->assertSame( '', (string) $input->getAttribute( 'value' ), 'the token field is empty in the page' );
		}
		$token = $this->token( 'enfold', false );
		$this->assertStringNotContainsString( $token, $first . $assets );
	}

	/**
	 * What it checks: The form carries a trap field that people never see and bots fill in.
	 * Passes when: the form has an input named omnabase_hp that is not a hidden input type, skipped by the keyboard (tabindex -1), not
	 *   autofilled (autocomplete off) and inside an element hidden from screen readers (aria-hidden true).
	 * Fails when: there is no trap, or a real visitor (with a keyboard, a screen reader or the browser's autofill) fills it and loses their
	 *   message silently.
	 */
	public function test_the_honeypot_is_a_field_people_never_fill(): void {
		$html = $this->render( 'project="enfold"' );
		$trap = self::nodes( $html, "//form//input[@name='" . self::HONEYPOT . "']" );
		$this->assertSame( 1, $trap->length, 'the trap field' );
		$input = $trap->item( 0 );
		$this->assertNotSame( 'hidden', strtolower( (string) $input->getAttribute( 'type' ) ), 'bots skip hidden inputs' );
		$this->assertSame( '-1', $input->getAttribute( 'tabindex' ) );
		$this->assertSame( 'off', strtolower( (string) $input->getAttribute( 'autocomplete' ) ) );
		$this->assertSame( 1, self::nodes( $html, "//*[@aria-hidden='true'][.//input[@name='" . self::HONEYPOT . "']]" )->length, 'hidden from screen readers' );
	}

	/**
	 * What it checks: Words from the shortcode and from OmnaBase are shown as text, never run as code.
	 * Passes when: a subject attribute holding a script tag and a product named like an image tag with an error handler appear only as text (in
	 *   the field's value and the list's option), never as markup.
	 * Fails when: anyone who can edit a page or name a product in OmnaBase can run script in every visitor's browser.
	 */
	public function test_text_from_the_shortcode_and_from_omnabase_is_escaped(): void {
		$this->formSettings( array( 'products' => array( '<img src=x onerror=alert(2)>', 'Enfold' ), 'askLicense' => false, 'privacyUrl' => null ) );
		$html = $this->render( 'project="enfold" subject="<script>alert(1)</script>"' );
		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertSame( 0, self::nodes( $html, '//img | //script' )->length, 'no element made from the words' );
		$this->assertContains( '<img src=x onerror=alert(2)>', self::texts( $html, "//select[@name='product']/option" ), 'the product shown as text' );
	}

	/**
	 * What it checks: A signed-in customer finds their name and email filled in, and the page carries WordPress's token for signed-in posts.
	 * Passes when: for a signed-in customer the name and email fields hold their display name and address, and the page (the form or its
	 *   script data) holds the REST nonce WordPress gives that user; for a visitor the email field is empty.
	 * Fails when: customers type their address again, or a signed-in customer's submission is turned away by WordPress as forged.
	 */
	public function test_a_signed_in_customer_finds_name_and_email_filled_in(): void {
		$ada = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'ada@example.org', 'display_name' => 'Ada Lovelace' ) );
		$this->asUserOnTheFront( $ada );
		$html = $this->render( 'project="enfold"' );
		$this->assertSame( 'ada@example.org', self::attr( $html, "//form//input[@name='email']", 'value' ) );
		$this->assertSame( 'Ada Lovelace', self::attr( $html, "//form//input[@name='name']", 'value' ) );
		$this->assertStringContainsString( wp_create_nonce( 'wp_rest' ), $html . $this->pageAssets(), 'the REST nonce for the signed-in user' );

		$this->asVisitor();
		$this->assertSame( '', (string) self::attr( $this->render( 'project="enfold"' ), "//form//input[@name='email']", 'value' ) );
	}

	/**
	 * What it checks: The subject attribute fills the subject field.
	 * Passes when: `[omnabase_form project="enfold" subject="Licence question"]` shows the subject field holding "Licence question".
	 * Fails when: a site owner cannot pre-word the subject of a form placed on, say, the licence page.
	 */
	public function test_the_subject_attribute_fills_the_subject(): void {
		$html = $this->render( 'project="enfold" subject="Licence question"' );
		$this->assertSame( 'Licence question', self::attr( $html, "//form//input[@name='subject']", 'value' ) );
	}

	/**
	 * What it checks: The site's key and secrets never reach the page, its scripts or the form's answers.
	 * Passes when: the form as a visitor and as an administrator, the page's scripts and their data, a time token's answer and a form answer
	 *   hold none of the stored key, webhook secret or facts secret.
	 * Fails when: anyone viewing the page's source can take the site's key and file tickets, or read customers, in its name.
	 */
	public function test_no_key_or_secret_reaches_the_page(): void {
		$out   = array();
		$out[] = $this->render( 'project="enfold"' );
		$this->asUserOnTheFront( $this->admin );
		$out[] = $this->render( 'project="enfold"' );
		$out[] = $this->pageAssets();
		$this->asVisitor();
		$out[] = (string) wp_json_encode( $this->tokenResponse()->get_data() );
		$out[] = (string) wp_json_encode( $this->sendFrom( self::VISITOR_IP )->get_data() );
		foreach ( self::CREDENTIALS as $name => $value ) {
			$this->assertStringNotContainsString( $value, implode( "\n", $out ), "the {$name} value" );
		}
	}
}
