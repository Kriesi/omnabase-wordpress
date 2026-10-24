<?php
/**
 * The plugin's forms round (2026-10-25), the red team's failing tests: what `[omnabase_form]` prints now that forms are built in OmnaBase and
 * drawn by OmnaBase's embed (inside wp-env's tests WordPress, the plain run; OmnaBase faked at the HTTP layer, `RoundFourTestCase`).
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`) outcome 1 ("`[omnabase_form id="<form slug>"]` … render the chosen one through the
 * embed: the plugin prints the container and loads `forms.js` from the app host; with no `id` the project's default form. 'Classic' is
 * gone."), "Rendering" and "The old routes" ("the plugin's time-token and form routes go"). Report findings 5 to 8 and questions 5 to 7.
 * Shapes as pinned in `FormEmbedTestCase`.
 *
 * Why these fail today: the shortcode prints the Classic form (a `<form>` with the plugin's own fields, the trap field, the time token's
 * address) and no container, loads the plugin's own `assets/form.js`, ignores `id`, never asks for the list of forms, and the two Classic
 * routes are registered.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Security\Vault;

final class FormEmbedShortcodeTest extends FormEmbedTestCase {

	/**
	 * What it checks: The shortcode shows the form the site owner picked in OmnaBase, through OmnaBase's embed, inside the plugin's look.
	 * Passes when: `[omnabase_form id="support"]` for a visitor prints one container naming "kriesi/support" and the site's project "enfold",
	 *   inside the wrapper that carries the Appearance marks; the output holds no form of the plugin's own (no `<form>`, no trap field, no time
	 *   token's address).
	 * Fails when: the site keeps showing the old fixed form, a form built in OmnaBase never appears on the site, or the light/dark choice of the
	 *   Appearance tab cannot reach the form.
	 */
	public function test_the_shortcode_prints_the_embeds_container_for_the_chosen_form(): void {
		$html = $this->renderAs( 0, 'id="support"' );

		$box = $this->theContainer( $html );
		$this->assertSame( self::WORKSPACE . '/support', $box->getAttribute( 'data-omnabase-form' ) );
		$this->assertSame( 'enfold', $box->getAttribute( 'data-project' ), 'the site\'s project places the submission' );
		$wrapper = self::wrapperOf( $html );
		$this->assertNotNull( $wrapper, 'the look\'s wrapper' );
		$this->assertSame( 1, self::nodes( $html, "//*[contains(concat(' ', normalize-space(@class), ' '), ' ob-wp ')]//*[@data-omnabase-form]" )->length, 'the container sits inside the wrapper' );
		$this->assertSame( 0, self::nodes( $html, '//form' )->length, 'no form of the plugin\'s own' );
		$this->assertStringNotContainsString( self::HONEYPOT, $html, 'no Classic trap field' );
		$this->assertStringNotContainsString( 'form-token', $html, 'no Classic time token' );
	}

	/**
	 * What it checks: The embed's script comes from OmnaBase's app address (the development one on a development site), once per page, however
	 *   many forms the page holds.
	 * Passes when: a page with two shortcodes (Support and Sales question) prints two containers and exactly one script, whose address is the
	 *   app host followed by /forms.js with no version query, never the address WordPress itself uses to call OmnaBase's API.
	 * Fails when: a page with two forms loads the script twice (two forms drawn twice), a development site loads production's script, or the
	 *   visitor's browser is pointed at an address only the WordPress server can reach.
	 */
	public function test_the_embeds_script_comes_from_the_app_host_once_per_page(): void {
		$this->omnabaseListsForms( array( self::SUPPORT_FORM, self::SALES ) );

		$html = $this->contentAs( 0, '[omnabase_form id="support"] [omnabase_form id="sales"]' );

		$this->assertSame( array( 'kriesi/support', 'kriesi/sales' ), array_map( static fn ( \DOMElement $b ) => $b->getAttribute( 'data-omnabase-form' ), self::containers( $html ) ) );
		$this->assertSame( array( self::expectedScript() ), $this->embedScripts( $html ), 'one script, from the app host' );
		if ( defined( 'OMNABASE_API_URL' ) ) {
			$this->assertStringNotContainsString( (string) wp_parse_url( (string) constant( 'OMNABASE_API_URL' ), PHP_URL_HOST ), implode( ' ', $this->embedScripts( $html ) ), 'never the server-side API address' );
		}
	}

	/**
	 * What it checks: A shortcode without a form named shows the project's default form, so the contact pages sites already have keep working
	 *   after OmnaBase turned each project's old form into its default form.
	 * Passes when: `[omnabase_form]` shows "kriesi/support", the form OmnaBase names as Enfold's default, placed in "enfold".
	 * Fails when: every existing page with a bare [omnabase_form] goes blank after the update.
	 */
	public function test_without_an_id_the_projects_default_form_shows(): void {
		$box = $this->theContainer( $this->renderAs( 0 ) );
		$this->assertSame( self::WORKSPACE . '/support', $box->getAttribute( 'data-omnabase-form' ) );
		$this->assertSame( 'enfold', $box->getAttribute( 'data-project' ) );
	}

	/**
	 * What it checks: On a site whose key covers two projects, the project attribute picks that project's default form.
	 * Passes when: with Omnalingo's default form "omni", `[omnabase_form project="omnalingo"]` shows "kriesi/omni" placed in "omnalingo", and
	 *   `[omnabase_form project="enfold"]` shows "kriesi/support" placed in "enfold".
	 * Fails when: a site with two products shows one product's form on the other's contact page.
	 */
	public function test_the_project_attribute_picks_that_projects_default_form(): void {
		$this->connectedWith(
			array(
				array( 'id' => self::PROJECT, 'slug' => 'enfold', 'name' => 'Enfold' ),
				array( 'id' => 'project-omnalingo', 'slug' => 'omnalingo', 'name' => 'Omnalingo' ),
			)
		);
		$this->omnabaseListsForms( array( self::SUPPORT_FORM, array( 'slug' => 'omni', 'name' => 'Omnalingo help', 'defaultFor' => array( 'omnalingo' ) ) ) );

		$omni = $this->theContainer( $this->renderAs( 0, 'project="omnalingo"' ) );
		$this->assertSame( self::WORKSPACE . '/omni', $omni->getAttribute( 'data-omnabase-form' ) );
		$this->assertSame( 'omnalingo', $omni->getAttribute( 'data-project' ) );
		$enfold = $this->theContainer( $this->renderAs( 0, 'project="enfold"' ) );
		$this->assertSame( self::WORKSPACE . '/support', $enfold->getAttribute( 'data-omnabase-form' ) );
		$this->assertSame( 'enfold', $enfold->getAttribute( 'data-project' ) );
	}

	/**
	 * What it checks: The message centre's form for visitors who are not signed in (`guest="form"`) is the project's form from OmnaBase too.
	 * Passes when: the message centre page holding `[omnabase_messages guest="form"]`, seen by a visitor, prints the embed's container of
	 *   Enfold's default form ("kriesi/support") and no form of the plugin's own.
	 * Fails when: the message centre page keeps offering visitors the retired fixed form, whose messages OmnaBase no longer accepts.
	 */
	public function test_the_message_centres_guest_form_is_the_projects_form(): void {
		wp_update_post( array( 'ID' => $this->picked, 'post_content' => '[omnabase_messages guest="form"]' ) );
		self::freshQueue();

		$html = $this->view( 0 );

		$this->assertSame( self::WORKSPACE . '/support', $this->theContainer( $html )->getAttribute( 'data-omnabase-form' ) );
		$this->assertSame( 0, self::nodes( $html, '//form' )->length, 'no form of the plugin\'s own' );
	}

	/**
	 * What it checks: When nothing tells which form to show, the administrator is told how to fix it and visitors see nothing broken.
	 * Passes when: with no default form for Enfold, `[omnabase_form]` prints nothing a visitor can see and no container, and for an administrator
	 *   one note (the plugin's note class, ending "Only administrators see this note.").
	 * Fails when: visitors meet an empty box or an error, or the owner has no idea why their contact page is empty.
	 */
	public function test_without_an_id_and_no_default_form_admins_read_a_note_visitors_nothing(): void {
		$this->omnabaseListsForms( array( array( 'slug' => 'support', 'name' => 'Support', 'defaultFor' => array() ) ) );

		$visitor = $this->renderAs( 0 );
		$this->assertSame( array(), self::containers( $visitor ) );
		$this->assertSame( '', trim( wp_strip_all_tags( $visitor ) ), 'nothing a visitor reads' );

		$admin = $this->contentAs( $this->adminOnTheFront(), '[omnabase_form]' );
		$this->assertSame( array(), self::containers( $admin ) );
		$this->assertSame( 1, self::nodes( $admin, "//*[contains(concat(' ', normalize-space(@class), ' '), ' ob-wp-form-notice ')]" )->length, 'one note' );
		$this->assertStringContainsString( 'Only administrators see this note.', wp_strip_all_tags( $admin ) );
	}

	/**
	 * What it checks: A form name that OmnaBase does not know (mistyped, archived, still a draft) shows the administrator a note naming it and
	 *   visitors nothing.
	 * Passes when: `[omnabase_form id="nope"]` prints no container and nothing readable for a visitor; for an administrator one note holding "nope".
	 * Fails when: a typo shows visitors "This form is not available." from the embed instead of telling the owner, or a draft form leaks.
	 */
	public function test_an_unknown_form_admins_read_a_note_visitors_nothing(): void {
		$visitor = $this->renderAs( 0, 'id="nope"' );
		$this->assertSame( array(), self::containers( $visitor ) );
		$this->assertSame( '', trim( wp_strip_all_tags( $visitor ) ) );

		$admin = $this->contentAs( $this->adminOnTheFront(), '[omnabase_form id="nope"]' );
		$this->assertSame( array(), self::containers( $admin ) );
		$this->assertStringContainsString( 'nope', wp_strip_all_tags( $admin ) );
		$this->assertStringContainsString( 'Only administrators see this note.', wp_strip_all_tags( $admin ) );
	}

	/**
	 * What it checks: The old `subject` attribute still pre-words the subject, now as a value the person may change (question 7).
	 * Passes when: `[omnabase_form id="support" subject="Billing question"]` puts `data-prefill-subject="Billing question"` on the container.
	 * Fails when: pages that pre-worded the subject lose it silently after the update.
	 */
	public function test_the_subject_attribute_prefills_the_subject_field(): void {
		$box = $this->theContainer( $this->renderAs( 0, 'id="support" subject="Billing question"' ) );
		$this->assertSame( 'Billing question', $box->getAttribute( 'data-prefill-subject' ) );
	}

	/**
	 * What it checks: A busy contact page does not ask OmnaBase for the list of forms on every view.
	 * Passes when: two views of `[omnabase_form id="support"]` make exactly one list call, sent with the site's key.
	 * Fails when: every page view waits for a call to OmnaBase, and a busy page runs into OmnaBase's limits.
	 */
	public function test_the_list_is_asked_once_for_two_views(): void {
		$this->renderAs( 0, 'id="support"' );
		$this->renderAs( 0, 'id="support"' );

		$calls = $this->formListCalls();
		$this->assertCount( 1, $calls, 'one list call for two views' );
		$this->assertSame( 'Bearer ' . self::KEY, self::headerOf( $calls[0]['args'], 'Authorization' ) );
	}

	/**
	 * What it checks: When OmnaBase cannot be asked for the list, a form named in the shortcode still shows; only a shortcode that needs the list
	 *   to know its form stays empty for visitors.
	 * Passes when: with the list call failing, `[omnabase_form id="support"]` still prints its container, and `[omnabase_form]` prints nothing
	 *   for a visitor and a note for an administrator.
	 * Fails when: a short outage of OmnaBase empties every contact page, or the visitor reads a technical error.
	 */
	public function test_when_omnabase_cannot_be_asked_a_named_form_still_shows(): void {
		$this->omnabaseListsForms( new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$this->assertSame( self::WORKSPACE . '/support', $this->theContainer( $this->renderAs( 0, 'id="support"' ) )->getAttribute( 'data-omnabase-form' ) );
		$bare = $this->renderAs( 0 );
		$this->assertSame( array(), self::containers( $bare ) );
		$this->assertSame( '', trim( wp_strip_all_tags( $bare ) ) );
		$this->assertStringContainsString( 'Only administrators see this note.', wp_strip_all_tags( $this->contentAs( $this->adminOnTheFront(), '[omnabase_form]' ) ) );
	}

	/**
	 * What it checks (guard): What an editor writes into the shortcode cannot become markup or script on the page.
	 * Passes when: an id and a subject carrying quotes and a script tag produce no script element and no extra attribute, for a visitor and for
	 *   an administrator's note.
	 * Fails when: someone who may write posts can run script in every visitor's browser through the form's shortcode.
	 */
	public function test_text_from_the_shortcode_is_escaped(): void {
		$bad = 'x&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;';
		foreach ( array( 0, $this->admin ) as $user ) {
			$html = $this->contentAs( $user, '[omnabase_form id="support" subject=\'a" onmouseover="alert(1)\'][omnabase_form id="' . html_entity_decode( $bad ) . '"]' );
			$this->assertSame( 0, self::nodes( $html, '//script' )->length, 'no script element' );
			$this->assertSame( 0, self::nodes( $html, '//*[@onmouseover]' )->length, 'no smuggled attribute' );
		}
	}

	/**
	 * What it checks (guard): The page never carries the site's key or its secrets, signed in or not.
	 * Passes when: neither a visitor's nor a signed-in customer's page (the shortcode and the queued scripts) holds the key, its "obw_" prefix,
	 *   the webhook secret or the facts secret.
	 * Fails when: a visitor could read the site's key from the page and act as the site at OmnaBase.
	 */
	public function test_no_key_or_secret_reaches_the_page(): void {
		$ada = $this->customerNamed( 'ada.page@example.org', 'Ada Page' );
		foreach ( array( 0, $ada ) as $user ) {
			$html = $this->renderAs( $user, 'id="support"' ) . $this->pageAssets();
			foreach ( array( self::KEY, 'obw_', self::WEBHOOKS, self::FACTS ) as $secret ) {
				$this->assertStringNotContainsString( $secret, $html );
			}
		}
	}

	/**
	 * What it checks (guard): A site that is not connected shows the administrator how to connect and visitors nothing.
	 * Passes when: with the vault emptied, `[omnabase_form id="support"]` prints no container and nothing readable for a visitor, and the
	 *   administrator's note.
	 * Fails when: a disconnected site shows a form whose messages go nowhere.
	 */
	public function test_a_site_that_is_not_connected_shows_visitors_nothing(): void {
		( new Vault() )->clear();

		$visitor = $this->renderAs( 0, 'id="support"' );
		$this->assertSame( array(), self::containers( $visitor ) );
		$this->assertSame( '', trim( wp_strip_all_tags( $visitor ) ) );
		$this->assertStringContainsString( 'Only administrators see this note.', wp_strip_all_tags( $this->contentAs( $this->adminOnTheFront(), '[omnabase_form id="support"]' ) ) );
	}

	/**
	 * What it checks: The Classic form's two addresses are gone, so nothing on the site still takes messages the old way.
	 * Passes when: WordPress's list of REST routes (the addresses programs call under /wp-json/) holds neither omnabase/v1/form nor
	 *   omnabase/v1/form-token, while the plugin's other routes (the signed receivers) are still there.
	 * Fails when: bots keep posting to an address whose messages OmnaBase no longer accepts, and the site answers them as if it did.
	 */
	public function test_the_classic_routes_are_gone(): void {
		do_action( 'rest_api_init' );
		$routes = array_keys( rest_get_server()->get_routes() );
		$this->assertNotContains( '/omnabase/v1/form', $routes );
		$this->assertNotContains( '/omnabase/v1/form-token', $routes );
		$this->assertNotSame( array(), array_values( array_filter( $routes, static fn ( string $r ) => str_starts_with( $r, '/omnabase/v1/' ) ) ), 'the plugin\'s other routes stay' );
	}
}
