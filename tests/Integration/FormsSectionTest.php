<?php
/**
 * The plugin's forms round (2026-10-25), the red team's failing tests: the "Forms and messages" tab of Settings › OmnaBase shows the
 * workspace's forms built in OmnaBase, and the shortcode builder lets the owner pick one (inside wp-env's tests WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`) outcome 4: "the 'Forms and messages' tab's form section shows the workspace's forms with
 * their shortcodes and a 'Build forms in OmnaBase' link to Settings › Forms; the old form settings (products, licence field) go"; outcome 1:
 * "the block's form picker list[s] the workspace's forms". The plugin has no block (report finding 5): question 1's default puts the picker into
 * the existing shortcode builder dialog instead.
 *
 * The words: *tab*, one of the named panels of Settings › OmnaBase (`&tab=pages` is "Forms and messages"); *shortcode builder*, the dialog
 * behind "Build a shortcode" that writes the tag and can make the page; *admin-post*, WordPress's address that hands a posted admin form to the
 * plugin's handler; *nonce*, a short-lived form token against forged requests.
 *
 * Shapes the red team pins here (the builder may rename and tell the red team): the section `.ob-wp-settings__forms` with an h2 "Forms"; each
 * form's name and its tag `[omnabase_form id="<slug>"]` inside it; a link reading "Build forms in OmnaBase" to `<app host>/kriesi/settings/forms`
 * (the app's address for Settings › Forms; the development app host on a development site); in the builder's Create form a select
 * `omnabase_builder[form]` whose options carry the forms' slugs.
 *
 * Why these fail today: the tab knows no forms of OmnaBase's, has no such link, and the builder offers no choice of form.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class FormsSectionTest extends FormEmbedTestCase {

	private const FORMS_SECTION = 'ob-wp-settings__forms';
	private const CREATE  = 'omnabase_create_page';

	/** The address of Settings › Forms in OmnaBase's app for this workspace. */
	private static function formsScreen(): string {
		return substr( self::expectedScript(), 0, -strlen( '/forms.js' ) ) . '/' . self::WORKSPACE . '/settings/forms';
	}

	/** The link reading "Build forms in OmnaBase" in some HTML, by its address ('' when missing). */
	private static function buildLink( string $html ): string {
		foreach ( self::nodes( $html, '//a[@href]' ) as $a ) {
			if ( preg_match( '/^\s*Build forms in OmnaBase\s*$/i', (string) $a->textContent ) ) {
				return html_entity_decode( (string) $a->getAttribute( 'href' ) );
			}
		}
		return '';
	}

	/**
	 * What it checks: The owner sees on the site which forms the workspace has, with the shortcode to paste for each, and finds the way to build
	 *   more in OmnaBase.
	 * Passes when: the Forms and messages tab has a section "Forms" listing "Support" with `[omnabase_form id="support"]` and "Sales question"
	 *   with `[omnabase_form id="sales"]`, and a link "Build forms in OmnaBase" to Settings › Forms of the workspace in OmnaBase's app; every line
	 *   has 30 words or fewer.
	 * Fails when: the owner must guess a form's name to type it into a shortcode, or cannot find where forms are made.
	 */
	public function test_the_tab_lists_the_workspaces_forms_with_their_shortcodes(): void {
		$this->omnabaseListsForms( array( self::SUPPORT_FORM, self::SALES ) );

		$section = self::section( $this->screen( 'pages' ), self::FORMS_SECTION );

		$this->assertNotSame( '', $section, 'the Forms section' );
		$this->assertSame( 1, self::nodes( $section, "//h2[normalize-space(.)='Forms']" )->length, 'its heading "Forms"' );
		$text = html_entity_decode( wp_strip_all_tags( $section ) );
		foreach ( array( 'Support', '[omnabase_form id="support"]', 'Sales question', '[omnabase_form id="sales"]' ) as $words ) {
			$this->assertStringContainsString( $words, $text );
		}
		$this->assertSame( self::formsScreen(), self::buildLink( $section ) );
		foreach ( self::lines( $section ) as $line ) {
			$this->assertLessThanOrEqual( 30, str_word_count( (string) preg_replace( '/\[[^\]]*\]/', '', $line ) ), $line );
		}
	}

	/**
	 * What it checks: A workspace without forms is told so, with the way to make one.
	 * Passes when: with an empty list the section says there are no forms yet and still links to Settings › Forms.
	 * Fails when: the owner sees an empty box and does not know forms are built in OmnaBase.
	 */
	public function test_without_forms_the_section_says_so_and_links_to_omnabase(): void {
		$this->omnabaseListsForms( array() );

		$section = self::section( $this->screen( 'pages' ), self::FORMS_SECTION );

		$this->assertMatchesRegularExpression( '/\bno forms\b/i', wp_strip_all_tags( $section ) );
		$this->assertSame( self::formsScreen(), self::buildLink( $section ) );
	}

	/**
	 * What it checks: When OmnaBase cannot be asked, the tab still opens and still shows the way to OmnaBase.
	 * Passes when: with the list call failing, the Forms and messages tab renders its Forms section with the link to Settings › Forms.
	 * Fails when: an outage at OmnaBase breaks the plugin's settings screen.
	 */
	public function test_the_tab_opens_when_omnabase_cannot_be_asked(): void {
		$this->omnabaseListsForms( new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$section = self::section( $this->screen( 'pages' ), self::FORMS_SECTION );

		$this->assertNotSame( '', $section );
		$this->assertSame( self::formsScreen(), self::buildLink( $section ) );
	}

	/**
	 * What it checks: The shortcode builder lets the owner pick one of the workspace's forms, and the page it makes shows that form.
	 * Passes when: the builder's Create form has a choice of form offering "support" and "sales"; creating a contact page with "sales" picked
	 *   makes one published page whose only OmnaBase tag is `[omnabase_form id="sales"]`; a form name the workspace does not have makes no page.
	 * Fails when: owners must type form names by hand, or a forged post creates pages showing forms that do not exist.
	 */
	public function test_the_builder_offers_the_forms_and_builds_the_tag(): void {
		$this->omnabaseListsForms( array( self::SUPPORT_FORM, self::SALES ) );
		$tab = $this->screen( 'pages' );

		$create = null;
		foreach ( self::formsIn( $tab ) as $form ) {
			if ( self::CREATE === $form['action'] ) {
				$create = $form;
			}
		}
		$this->assertNotNull( $create, 'the builder\'s Create form' );
		$offered = array_map( static fn ( \DOMElement $o ) => (string) $o->getAttribute( 'value' ), iterator_to_array( self::nodes( self::outer( $create['node'] ), "//select[@name='omnabase_builder[form]']//option" ) ) );
		$this->assertContains( 'support', $offered );
		$this->assertContains( 'sales', $offered );

		$made = $this->createWith( $create['fields'], 'sales' );
		$this->assertCount( 1, $made, 'one page made' );
		$this->assertSame( 'publish', get_post_status( $made[0] ) );
		preg_match_all( '/\[omnabase_(?:form|messages)\b[^\]]*\]/', (string) get_post_field( 'post_content', $made[0] ), $tags );
		$this->assertSame( array( '[omnabase_form id="sales"]' ), $tags[0] );

		$this->assertSame( array(), $this->createWith( $create['fields'], 'nope' ), 'an unknown form makes no page' );
	}

	/**
	 * Posts the builder's Create form as the administrator with the contact form and a form picked; the pages it made.
	 *
	 * @param array<string,mixed> $fields The form's own fields (its nonce and action included).
	 * @param string              $form   The form's slug.
	 */
	private function createWith( array $fields, string $form ): array {
		$fields['omnabase_builder'] = array_merge( is_array( $fields['omnabase_builder'] ?? null ) ? $fields['omnabase_builder'] : array(), array( 'kind' => 'form', 'form' => $form ) );
		$before                     = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
		$this->asAdminOnTheScreen( $this->admin );
		$this->postFields( $fields );
		$after = get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true ) );
		return array_values( array_map( 'intval', array_diff( $after, $before ) ) );
	}
}
