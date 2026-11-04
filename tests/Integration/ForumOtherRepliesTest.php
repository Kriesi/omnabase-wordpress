<?php
/**
 * The composer-and-fixes round (OmnaBase, 2026-11-03), the red team's failing tests for the plugin: a forum reply by someone who is neither
 * the topic's author nor a moderator now reaches the topic's ticket, under that person's name and nothing else.
 *
 * Brief (OmnaBase `docs/briefs/composer-and-fixes-round.md`) rule 11: "Now the plugin sends it as `from: "other"` with the member's display
 * name (no email, no role); OmnaBase stores it as a customer-side message by another participant"; the founder: "do reopen/resurface if
 * another person posts. its a forum and it often happens that people hijack older posts". Report
 * `docs/reviews/composer-and-fixes-round-red-team-report.md` in the OmnaBase repository, findings 20 and 21.
 *
 * The words: *topic* and *reply* are bbPress's forum thread and its answers; the *topic's author* is the customer whose ticket the topic
 * became; a *moderator* is a forum user with bbPress's moderate capability (a permission); a *guest* replies without an account, under a name
 * and address bbPress keeps on the reply; *post fields* are the private values a site declared for its forum posts (a licence key, a site
 * password), which travel with the customer's and the moderator's posts.
 *
 * Shapes the red team pins (the builder may rename and tell the red team): the body is
 * `{ from: "other", participant: { name }, body, external: { id: "reply-<id>" } }` with an Idempotency-Key; the name is the user's display
 * name, a guest's anonymous name; no `customer`, `member`, `siteAuthor`, `delivered`, `kind`, `email` or `fields` key (chosen, finding 20:
 * another person's private data never reaches the customer's ticket). The plugin's own (marked) replies are still never sent (guard, in
 * ForumRepliesTest). (Adjusted 2026-11-04, the support user replies round, the red team; comment only: the support user's hand-written
 * replies are now sent as the team's answer, pinned in ForumRepliesTest and ForumSupportUserRepliesTest.)
 *
 * Why these fail today: `ReplySender::body()` returns null for anyone but the topic's author and moderators, so the reply is skipped with
 * "Not sent: only the topic's author and moderators speak on the ticket".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumOtherRepliesTest extends RoundThreeTestCase {

	/** Harness, changed by the builder on 2026-11-03 (not weakened): the red team's key `_omnabase_red_private` starts with `_omnabase_`, a prefix
	 *  the plugin refuses as a post field (its own keys), so no field was declared and the customer's guard could never pass; any usable key
	 *  checks the same rule. */
	private const PRIVATE = '_red_private_data';

	/**
	 * What it checks: Another forum member's reply on a customer's topic reaches the ticket, named, as "other".
	 * Passes when: Ben's reply on Ada's filed topic makes one call to that ticket's messages from "other", with Ben's display name, his text as
	 *   plain text, the site's reference "reply-<id>" and an Idempotency-Key; it carries no address, no role, no customer, no member, no
	 *   moderator's site author, nothing delivered and no note kind; no row needs attention.
	 * Fails when: the reply is skipped (ticket 395's missing answer), or a bystander's post goes out as the customer's or as the team's.
	 */
	public function test_a_third_members_reply_is_sent_as_other_with_the_name(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();
		$reply       = $this->reply( $topic, $this->ben, '<p>Same here, the <strong>menu</strong> is gone.</p>' );

		$this->workOutbox();

		$calls = $this->messageCalls( self::FIRST_NUMBER );
		$this->assertCount( 1, $calls, 'one call for the reply' );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'other', $body['from'] ?? null );
		$this->assertSame( array( 'name' => 'Ben Weber' ), $body['participant'] ?? null, 'the name only' );
		$this->assertStringContainsString( 'Same here, the menu is gone.', (string) ( $body['body'] ?? '' ) );
		$this->assertStringNotContainsString( '<', (string) $body['body'] );
		$this->assertSame( array( 'id' => 'reply-' . $reply ), $body['external'] ?? null );
		foreach ( array( 'customer', 'member', 'siteAuthor', 'delivered', 'kind', 'email', 'fields' ) as $absent ) {
			$this->assertArrayNotHasKey( $absent, $body, $absent );
		}
		$this->assertNotEmpty( self::headerOf( $calls[0], 'Idempotency-Key' ) );
		$this->assertSame( array(), array_values( array_filter( $this->forumRows( 'reply' ), static fn ( $r ) => 'attention' === $r['state'] ) ) );
	}

	/**
	 * What it checks: Another member's private post fields never travel with their reply.
	 * Passes when: with a declared post field that holds a value on Carl's reply, the call for it has no fields key; (guard) Ada's own reply
	 *   with the same field still carries it.
	 * Fails when: a stranger's licence key or site password lands on Ada's ticket and in Ada's customer profile.
	 */
	public function test_another_members_post_fields_stay_on_the_site(): void {
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'fields' => array( self::PRIVATE => 'Private data' ) ) );
		$topic       = $this->filedTopic();
		$this->calls = array();
		$carl        = $this->reply( $topic, $this->carl, 'Mine too, here is my licence.' );
		update_post_meta( $carl, self::PRIVATE, 'CARL-LICENCE-123' );
		$ada = $this->reply( $topic, $this->ada, 'And mine.' );
		update_post_meta( $ada, self::PRIVATE, 'ADA-LICENCE-456' );

		$this->workOutbox();

		$bodies = array_map( array( self::class, 'bodyOf' ), $this->messageCalls( self::FIRST_NUMBER ) );
		$other  = array_values( array_filter( $bodies, static fn ( $b ) => 'other' === ( $b['from'] ?? null ) ) );
		$mine   = array_values( array_filter( $bodies, static fn ( $b ) => 'customer' === ( $b['from'] ?? null ) ) );
		$this->assertCount( 1, $other, 'Carl\'s reply went as other' );
		$this->assertArrayNotHasKey( 'fields', $other[0], 'no fields from another member' );
		$this->assertStringNotContainsString( 'CARL-LICENCE-123', (string) wp_json_encode( $other[0] ) );
		$this->assertCount( 1, $mine, '(guard) Ada\'s reply' );
		$this->assertSame( 'ADA-LICENCE-456', $mine[0]['fields'][0]['value'] ?? null, '(guard) the customer\'s own field travels as before' );
	}

	/**
	 * What it checks: A guest's reply on someone else's topic goes as "other" under the name the guest gave.
	 * Passes when: a reply without an account, named "Gast Gerda", on Ada's topic makes one call from "other" with that name and no address.
	 * Fails when: guests' answers are dropped, or the guest's address is sent along.
	 */
	public function test_a_guests_reply_on_someone_elses_topic_is_other_with_the_given_name(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();
		$forum       = (int) bbp_get_topic_forum_id( $topic );
		$id          = (int) bbp_insert_reply(
			array( 'post_parent' => $topic, 'post_author' => 0, 'post_content' => 'Same problem on my site.', 'post_status' => 'publish' ),
			array( 'forum_id' => $forum, 'topic_id' => $topic, 'anonymous_name' => 'Gast Gerda', 'anonymous_email' => 'gerda@example.org' )
		);
		$this->assertGreaterThan( 0, $id );
		do_action( 'bbp_new_reply', $id, $topic, $forum, array( 'bbp_anonymous_name' => 'Gast Gerda', 'bbp_anonymous_email' => 'gerda@example.org', 'bbp_anonymous_website' => '' ), 0, false, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bbPress's own moment, fired as bbPress fires it

		$this->workOutbox();

		$calls = $this->messageCalls( self::FIRST_NUMBER );
		$this->assertCount( 1, $calls );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'other', $body['from'] ?? null );
		$this->assertSame( array( 'name' => 'Gast Gerda' ), $body['participant'] ?? null );
		$this->assertStringNotContainsString( 'gerda@example.org', (string) wp_json_encode( $body ) );
	}

	/**
	 * What it checks: The customer's and the moderator's replies keep their shapes beside the new one (it fails today only because the third
	 *   reply is not sent).
	 * Passes when: on one topic Ada's reply goes from the customer, Nina's from the team (delivered, with her site author name) and Ben's from
	 *   other, three calls in the order written.
	 * Fails when: adding the third sender changes how the customer's follow-up or the moderator's answer arrive.
	 */
	public function test_customer_and_moderator_replies_are_unchanged_beside_other(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();
		$this->reply( $topic, $this->ada, 'Still broken.' );
		$this->reply( $topic, $this->moderator, 'Please clear the cache.' );
		$this->reply( $topic, $this->ben, 'Clearing the cache fixed it for me.' );

		$this->workOutbox();

		$from = array_map( static fn ( $c ) => self::bodyOf( $c )['from'] ?? null, $this->messageCalls( self::FIRST_NUMBER ) );
		$this->assertSame( array( 'customer', 'team', 'other' ), $from );
		$team = self::bodyOf( $this->messageCalls( self::FIRST_NUMBER )[1] );
		$this->assertTrue( $team['delivered'] ?? null );
		$this->assertSame( 'Nina Moderator', $team['siteAuthor']['name'] ?? null );
	}
}
