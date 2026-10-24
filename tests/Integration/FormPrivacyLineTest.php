<?php
/**
 * The plugin's forms round (2026-10-25), the builder's test for the brief's question 11 (OmnaBase `docs/briefs/plugin-forms-round.md`, "After
 * the red team's review": "The plugin prints the site's privacy page link as one small line under the container when the site has one, with
 * a test beside the container tests"). Inside wp-env's tests WordPress, the plain run; OmnaBase faked at the HTTP layer (`RoundFourTestCase`).
 *
 * The words: *privacy page*, the page the site owner picked under Settings › Privacy (WordPress's own setting); *container*, the empty box the
 * shortcode prints for OmnaBase's form embed.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class FormPrivacyLineTest extends FormEmbedTestCase {

	/**
	 * What it checks: A site with a privacy page links it under the form, because OmnaBase's form itself carries no privacy link.
	 * Passes when: with a published privacy page picked under Settings › Privacy, `[omnabase_form id="support"]` prints the container and, after
	 *   it inside the wrapper, one link to that page's address.
	 * Fails when: visitors writing in through the site's form never see where the site explains what happens to their data.
	 */
	public function test_the_sites_privacy_page_is_linked_under_the_form(): void {
		$page = (int) self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Privacy' ) );
		update_option( 'wp_page_for_privacy_policy', $page );

		$html = $this->renderAs( 0, 'id="support"' );

		$this->theContainer( $html );
		$links = self::nodes( $html, "//*[contains(concat(' ', normalize-space(@class), ' '), ' ob-wp ')]//*[@data-omnabase-form]/following::a[@href]" );
		$this->assertSame( 1, $links->length, 'one link after the container' );
		$this->assertSame( (string) get_privacy_policy_url(), html_entity_decode( (string) $links->item( 0 )->getAttribute( 'href' ) ) );
	}

	/**
	 * What it checks: A site without a privacy page shows no empty or broken privacy line.
	 * Passes when: with no privacy page picked, the shortcode's output holds the container and no link at all.
	 * Fails when: visitors meet a "Privacy policy" link that leads nowhere.
	 */
	public function test_without_a_privacy_page_there_is_no_line(): void {
		delete_option( 'wp_page_for_privacy_policy' );

		$html = $this->renderAs( 0, 'id="support"' );

		$this->theContainer( $html );
		$this->assertSame( 0, self::nodes( $html, '//a' )->length, 'no link' );
	}
}
