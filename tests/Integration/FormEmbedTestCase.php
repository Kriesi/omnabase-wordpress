<?php
/**
 * The plugin's forms round (2026-10-25), the red team's integration helpers (inside wp-env's tests WordPress, the plain run unless a test says
 * otherwise): the shortcode `[omnabase_form]` now prints the container of OmnaBase's form embed instead of the Classic form, loads the embed's
 * script from OmnaBase's app host, and, for a signed-in visitor, carries a prefill token the plugin's server asked OmnaBase for.
 *
 * Brief (OmnaBase `docs/briefs/plugin-forms-round.md`) "Rendering": "the shortcode prints `<div data-omnabase-form="<workspace>/<slug>"
 * data-project="<the site's project>" …>` and enqueues `forms.js` once per page from the app host (the Development override points at the local
 * app, as the connect does) … an unknown slug prints a one-line notice for editors and nothing for visitors"; "Verified prefill": "the plugin's
 * server fetches a short-lived prefill token from OmnaBase with the site key for the logged-in user's facts … and prints it on the container".
 * Report `docs/reviews/plugin-forms-round-red-team-report.md`, findings 1 to 8, with the pinned defaults.
 *
 * The words: *shortcode*, a tag such as `[omnabase_form id="support"]` that WordPress replaces with the plugin's output; *embed*, OmnaBase's
 * one script (`forms.js`) that finds every container on a page and draws the form inside it; *container*, the empty `<div>` the plugin prints
 * for the embed; *app host*, OmnaBase's own address (`https://app.omnabase.com`, or a development site's `OMNABASE_APP_URL`); *prefill token*,
 * a short-lived note signed by OmnaBase saying "this site vouches that the signed-in person's address is …", which makes those values show as
 * verified to the team; *page cache*, a plugin or server that keeps a page's HTML and hands the same copy to the next visitor; *enqueue*,
 * WordPress's list of scripts a page will load.
 *
 * Shapes the red team pins here (chosen where the brief names none; the builder may rename and tell the red team; every name sits here):
 * - The container: one element per shortcode carrying `data-omnabase-form="kriesi/<form slug>"` (the workspace's slug kept from Connect) and
 *   `data-project="<project slug>"`, inside the look's wrapper (`.ob-wp`, which carries the Appearance marks); `data-prefill-token` only for a
 *   signed-in visitor; `data-prefill-subject` when the shortcode names a `subject` (question 7: the old attribute fills the form's field keyed
 *   "subject", unverified).
 * - The script: one `<script>` whose address is exactly `<app host>/forms.js` (no version query: the embed's address is OmnaBase's to cache).
 * - The notes for administrators: the existing class `ob-wp-form-notice` and the existing sentence "Only administrators see this note."
 * - The calls: `GET /api/public/v1/forms` (the list, kept a while: one call for two views) and `POST /api/public/v1/forms/prefill` with body
 *   `{ form, project, values: { email, name, order?, licence? } }` (the fake in `RoundFourTestCase`).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

abstract class FormEmbedTestCase extends AppearanceTestCase {

	protected const WORKSPACE = 'kriesi';
	protected const SALES     = array( 'slug' => 'sales', 'name' => 'Sales question', 'defaultFor' => array() );

	/** The production app host. */
	protected const APP = 'https://app.omnabase.com';

	public function set_up(): void {
		parent::set_up();
		$this->calls = array();
	}

	/* ---------- the container and the script ---------- */

	/** Every embed container in some HTML. */
	protected static function containers( string $html ): array {
		return iterator_to_array( self::nodes( $html, '//*[@data-omnabase-form]' ) );
	}

	/** The one embed container in some HTML; fails when there is not exactly one. */
	protected function theContainer( string $html ): \DOMElement {
		$all = self::containers( $html );
		$this->assertCount( 1, $all, 'exactly one embed container in: ' . $html );
		return $all[0];
	}

	/** Where the embed's script must come from: the development app host when the site names one, else OmnaBase's own. */
	protected static function expectedScript(): string {
		$app = defined( 'OMNABASE_APP_URL' ) ? rtrim( (string) constant( 'OMNABASE_APP_URL' ), '/' ) : '';
		return ( '' === $app ? self::APP : $app ) . '/forms.js';
	}

	/**
	 * The addresses of every script whose path ends in forms.js: queued (as the page prints them) or written into the shortcode's own output.
	 *
	 * @param string $html The shortcode's output.
	 */
	protected function embedScripts( string $html = '' ): array {
		preg_match_all( '#<script\b[^>]*\bsrc=[\'"]([^\'"]*forms\.js[^\'"]*)[\'"]#i', $html . $this->pageAssets(), $m );
		return array_map( static fn ( string $src ) => html_entity_decode( $src ), $m[1] );
	}

	/* ---------- who looks ---------- */

	/** A signed-in shop customer with this address and display name. */
	protected function customerNamed( string $email, string $name ): int {
		return (int) self::factory()->user->create( array( 'user_email' => $email, 'display_name' => $name, 'role' => 'subscriber' ) );
	}

	/** The shortcode's output for a user (0: nobody signed in), on a fresh page load. */
	protected function renderAs( int $user, string $atts = '' ): string {
		self::freshQueue();
		0 === $user ? $this->asVisitor() : $this->asUserOnTheFront( $user );
		return $this->render( $atts );
	}

	/** Some post content's output for a user, on a fresh page load. */
	protected function contentAs( int $user, string $content ): string {
		self::freshQueue();
		0 === $user ? $this->asVisitor() : $this->asUserOnTheFront( $user );
		return (string) do_shortcode( $content );
	}

	/** An administrator on the front end (a preview of their own page). */
	protected function adminOnTheFront(): int {
		$this->asUserOnTheFront( $this->admin );
		return $this->admin;
	}

	/** The one prefill call's JSON body; fails when there is not exactly one. */
	protected function thePrefillBody(): array {
		$calls = $this->prefillCalls();
		$this->assertCount( 1, $calls, 'one prefill token asked for' );
		return self::bodyOf( $calls[0] );
	}
}
