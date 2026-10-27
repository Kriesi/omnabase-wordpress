<?php
/**
 * The plugin's message-centre HTML round (2026-10-28), the red team's failing test: the forum reply the plugin posts from OmnaBase's answer is
 * cleaned by the plugin's own allow-list (inside wp-env's tests WordPress with bbPress loaded: the group "forum").
 *
 * Brief (OmnaBase `docs/briefs/plugin-message-centre-html-round.md`) outcome 3: "The forum reply posted by the plugin is cleaned by the same
 * allow-list, so a forum post never carries a picture or inline style that WordPress's broad default cleaner would let through"; rule 2: "The
 * forum poster … uses the same helper for the reply's body instead of `wp_kses_post`; the closing line stays as it is."
 *
 * The words: *allow-list*, the tags and attributes that may stay; *wp_kses_post*, WordPress's cleaner for post content, which keeps pictures,
 * inline styles and classes; *inline style*, colours or sizes written into a tag; *closing line*, the "— Ada, Team Kriesi" line the plugin adds.
 *
 * Shapes the red team pins: those of `RoundThreeTestCase` (a signed `message.sent` at the events receiver). OmnaBase's webhook carries the
 * message's stored HTML (not the portal's display shape), so the forum poster's own cleaning is the only fence.
 *
 * Why this fails today: the poster cleans with `wp_kses_post`, which keeps the picture, the `style` and the `class`.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumPostShapeTest extends RoundThreeTestCase {

	/**
	 * What it checks: The team's answer posted on the forum keeps its shape (bold, a list, a link) but carries no picture, no inline style and
	 *   no class, and still ends with the agent's closing line.
	 * Passes when: a signed `message.sent` whose HTML holds bold words, a list, a link, an outside picture, an embedded picture, a paragraph with
	 *   an inline style and a class is answered 200; the forum reply holds the `strong`, the two list items and the link, no `img`, no `style=`
	 *   and no `class=` from the message, and its text ends with "— Ada, Team Kriesi".
	 * Fails when: every visitor of the public forum loads a tracking picture from another server, or the team's answer wears styles the site's
	 *   theme cannot override.
	 */
	public function test_the_forum_reply_holds_no_picture_or_inline_style(): void {
		$topic = $this->filedTopic();
		$html  = '<p style="color:#f00;font-size:40px" class="alignwide has-red-color">Please <strong>update</strong>:</p><ul><li>Clear the cache</li><li>Reload</li></ul>'
			. '<p><img src="https://tracker.example/p.png" alt="t"><img src="data:image/png;base64,iVBORw0KGgo=" alt="d"></p>'
			. '<p>Read <a href="https://example.com/docs">the docs</a>.</p>';
		$response = $this->deliverEvent( 'message.sent', 'dlv-shape-1', $this->teamReply( $topic, 'msg-shape-1', 'Ada', $html, 'Please update: Clear the cache, Reload. Read the docs.' ) );

		$this->assertSame( 200, $response->get_status() );
		$replies = self::repliesOn( $topic );
		$this->assertCount( 1, $replies );
		$content = $replies[0]->post_content;
		$this->assertStringContainsString( '<strong>update</strong>', $content );
		$this->assertSame( 2, preg_match_all( '#<li>#', $content ) );
		$this->assertMatchesRegularExpression( '#<a [^>]*href="https://example\.com/docs"#', $content );
		$this->assertStringNotContainsString( '<img', $content, 'no picture' );
		$this->assertStringNotContainsString( 'tracker.example', $content );
		$this->assertStringNotContainsString( 'style=', $content, 'no inline style' );
		$this->assertStringNotContainsString( 'has-red-color', $content, 'no class from the message' );
		$this->assertStringEndsWith( '— Ada, Team Kriesi', trim( wp_strip_all_tags( $content ) ), 'the closing line stays last' );
	}
}
