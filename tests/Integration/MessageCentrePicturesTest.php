<?php
/**
 * The pictures-in-the-text round (OmnaBase, 2026-10-29), the red team's failing tests: the message centre shows a team reply's pasted
 * picture where the team put it, loaded from OmnaBase's signed portal address (inside wp-env's tests WordPress, the plain run, OmnaBase faked
 * as `RoundFiveTestCase` fakes it).
 *
 * Brief (OmnaBase `docs/briefs/pictures-in-the-text-round.md`) outcome 2: "the plugin's message centre shows it too"; rule 4: "the plugin's
 * allow-list admits `img` with `src` on the portal's path, `alt`, `width`"; "the plugin side is one small commit in the plugin repository".
 * The OmnaBase red team's report (`docs/reviews/pictures-in-the-text-round-red-team-report.md`, finding 7) pins the portal's form: OmnaBase
 * prints a short signed address on its own public API, `<API>/api/public/v1/portal/files/<token>`, that the visitor's browser loads directly;
 * the plugin needs no file route of its own and passes no bytes through the site's server.
 *
 * The words: *allow-list*, the tags and attributes that may stay; *signed address*, a web address carrying its own proof, so the browser may
 * load that one picture without the site's key; *message bubble*, one message's box in the thread.
 *
 * Shapes the red team pins (the builder may change them and tell the red team):
 * - In the message centre, an `img` whose `src` starts with exactly `ApiUrl::publicApi( '/portal/files/' )` stays, with `alt` and a whole
 *   number `width`; every other picture still goes (another host, another path on OmnaBase's host, a `data:` picture, a look-alike host such
 *   as `<API host>.evil.example`, a path that climbs out with `..`); no other attribute survives on the picture.
 * - The forum reply never carries a picture, not even one on the portal's path (a visitor-bound address must never reach a public page).
 * - The stylesheet keeps a picture inside the bubble: `.ob-wp-messages__body img` gets `max-width: 100%` and `height: auto`.
 *
 * Why these fail today: the helper's allow-list has no `img` at all, so the portal's picture is removed with every other picture; the
 * stylesheet has no rule for a picture in the bubble. The forum test is a guard.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Api\ApiUrl;

final class MessageCentrePicturesTest extends RoundFiveTestCase {

	private const BODY = "//*[contains(concat(' ', normalize-space(@class), ' '), ' ob-wp-messages__body ')]";

	/**
	 * What it checks: A team reply's pasted screenshot shows in the customer's message centre in the text, between the sentences the team
	 *   wrote around it.
	 * Passes when: a team message whose HTML holds a paragraph, OmnaBase's portal picture (src on /portal/files/, alt, width 800) and another
	 *   paragraph prints one picture in its bubble with that exact address, the alt and the width, and both paragraphs.
	 * Fails when: the customer reads the reply on the site without the screenshot the mail showed them.
	 */
	public function test_the_portal_picture_shows_in_the_bubble(): void {
		$this->omnabaseKnows( $this->ada, true );
		$src  = ApiUrl::publicApi( '/portal/files/eyJ2IjoxfQ.c2lnbmF0dXJl-AbC_123' );
		$html = '<p>Here:</p><p><img src="' . esc_attr( $src ) . '" alt="shot.png" width="800"></p><p>That is the button.</p>';
		$n    = $this->ticketFor( $this->ada, 'Pictured answer', array( array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => "Here:\n\n[Picture: shot.png]\n\nThat is the button.", 'bodyHtml' => $html ) ) );
		$page = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$imgs = self::nodes( $page, self::BODY . '//img' );
		$this->assertSame( 1, $imgs->length, 'one picture in the bubble' );
		$img = $imgs->item( 0 );
		$this->assertSame( $src, $img->getAttribute( 'src' ) );
		$this->assertSame( 'shot.png', $img->getAttribute( 'alt' ) );
		$this->assertSame( '800', $img->getAttribute( 'width' ) );
		$this->assertSame( array( 'Here:', 'That is the button.' ), array_values( array_filter( self::texts( $page, self::BODY . '/p' ), 'strlen' ) ) );
	}

	/**
	 * What it checks: Only OmnaBase's own portal pictures pass; any other picture still leaves, whatever it pretends to be.
	 * Passes when: pictures from another server, from another path on OmnaBase's host, from a look-alike host, from a path that climbs out of
	 *   /portal/files/, embedded as data, and a portal picture carrying an error handler, a style and a class are all checked: only the last
	 *   one's picture stays, and it keeps no handler, style or class.
	 * Fails when: a message turns the customer's signed-in page into a tracking beacon, or a picture runs code when it fails to load.
	 */
	public function test_only_the_portal_path_passes(): void {
		$this->omnabaseKnows( $this->ada, true );
		$portal = ApiUrl::publicApi( '/portal/files/' );
		$host   = (string) wp_parse_url( ApiUrl::base(), PHP_URL_HOST );
		$bad    = array(
			'https://tracker.example/p.png',
			ApiUrl::publicApi( '/tickets/12' ),
			ApiUrl::base() . '/api/v1/files/attachments/x/y/z.png',
			'https://' . $host . '.evil.example/api/public/v1/portal/files/tok',
			$portal . '../tickets/1',
			'data:image/png;base64,iVBORw0KGgo=',
		);
		$html = '<p>Words</p>';
		foreach ( $bad as $i => $src ) {
			$html .= '<p><img src="' . esc_attr( $src ) . '" alt="bad' . $i . '"></p>';
		}
		$html .= '<p><img src="' . esc_attr( $portal . 'good-token-123' ) . '" alt="good" onerror="alert(1)" style="width:9999px" class="ob-btn"></p>';
		$n     = $this->ticketFor( $this->ada, 'Mixed pictures', array( array( 'kind' => 'team', 'name' => 'Kriesi Support', 'bodyText' => 'Words', 'bodyHtml' => $html ) ) );
		$page  = $this->view( $this->ada, array( self::TICKET_QUERY => (string) $n ) );
		$imgs  = self::nodes( $page, self::BODY . '//img' );
		$this->assertSame( 1, $imgs->length, 'only the portal picture' );
		$this->assertSame( $portal . 'good-token-123', $imgs->item( 0 )->getAttribute( 'src' ) );
		foreach ( array( 'onerror', 'style', 'class' ) as $name ) {
			$this->assertFalse( $imgs->item( 0 )->hasAttribute( $name ), "no {$name} on the picture" );
		}
		$this->assertStringNotContainsString( 'tracker.example', $page );
		$this->assertStringNotContainsString( '.evil.example', $page );
	}

	/**
	 * What it checks: A wide screenshot never pushes the message centre wider than the page.
	 * Passes when: the source stylesheet and the built one each have a rule for `.ob-wp-messages__body img` with `max-width: 100%` and
	 *   `height: auto`.
	 * Fails when: a 1280-pixel screenshot makes the customer's page scroll sideways on a phone.
	 */
	public function test_the_picture_fits_the_bubble(): void {
		foreach ( array( 'styles/form.css', 'assets/form.css' ) as $file ) {
			$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $file );
			$this->assertMatchesRegularExpression( '#\.ob-wp-messages__body\s+img\b[^{]*\{[^}]*max-width\s*:\s*100%[^}]*\}#', $css, "{$file}: max-width" );
			$this->assertMatchesRegularExpression( '#\.ob-wp-messages__body\s+img\b[^{]*\{[^}]*height\s*:\s*auto[^}]*\}#', $css, "{$file}: height auto" );
		}
	}
}
