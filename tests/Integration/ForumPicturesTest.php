<?php
/**
 * The pictures-in-the-text round (OmnaBase, 2026-10-29), the red team's guard: the public forum never shows a picture, not even one on
 * OmnaBase's portal path (inside wp-env's tests WordPress with bbPress loaded: the group "forum").
 *
 * The OmnaBase red team's report (`docs/reviews/pictures-in-the-text-round-red-team-report.md`, findings 7 and 10): OmnaBase's webhook never
 * carries a picture marker or a portal address (it says "[Picture: name]"), and the plugin's helper admits the portal's pictures in the
 * message centre only. A portal address is minted for one signed-in visitor; on a public forum anyone could load it.
 *
 * The words: *webhook*, OmnaBase's signed call to the site when the team answers; *portal address*, the signed picture address of the
 * message centre.
 *
 * Shapes the red team pins: those of `RoundThreeTestCase` (a signed `message.sent` at the events receiver).
 *
 * Why this passes today (guard): the helper admits no picture at all. It must keep passing once the message centre admits portal pictures.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Api\ApiUrl;

/**
 * @group forum
 */
final class ForumPicturesTest extends RoundThreeTestCase {

	/**
	 * What it checks: (guard) A team answer posted to the public forum never carries a picture, even one on OmnaBase's portal path, and keeps
	 *   the "[Picture: name]" words OmnaBase sends in its place.
	 * Passes when: a signed `message.sent` whose HTML holds a paragraph, the words "[Picture: shot.png]" and a portal picture is answered 200;
	 *   the forum reply holds the words and no `img` and no /portal/files/ address.
	 * Fails when: a visitor-bound picture address lands on a public page where anyone can load the customer's screenshot.
	 */
	public function test_the_forum_reply_never_holds_a_portal_picture(): void {
		$topic    = $this->filedTopic();
		$src      = ApiUrl::publicApi( '/portal/files/visitor-token-123' );
		$html     = '<p>Here:</p><p>[Picture: shot.png]</p><p><img src="' . esc_attr( $src ) . '" alt="shot.png" width="800"></p>';
		$response = $this->deliverEvent( 'message.sent', 'dlv-pic-1', $this->teamReply( $topic, 'msg-pic-1', 'Ada', $html, "Here:\n\n[Picture: shot.png]" ) );
		$this->assertSame( 200, $response->get_status() );
		$replies = self::repliesOn( $topic );
		$this->assertCount( 1, $replies );
		$content = $replies[0]->post_content;
		$this->assertStringContainsString( '[Picture: shot.png]', $content );
		$this->assertStringNotContainsString( '<img', $content );
		$this->assertStringNotContainsString( '/portal/files/', $content );
	}
}
