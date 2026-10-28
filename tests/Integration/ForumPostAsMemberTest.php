<?php
/**
 * The post-as-member round (2026-10-29), the red team's failing tests: OmnaBase's reply appears on the forum under the team member's own
 * forum account when the site has a moderator with the member's email address, else under the support user as before.
 *
 * Brief (OmnaBase `docs/briefs/post-as-member-round.md`) rule 2; report `docs/reviews/post-as-member-round-red-team-report.md` in the
 * OmnaBase repository, findings 7 to 14. The words: *moderator*, a forum user holding bbPress's `moderate` right (a permission that lets them
 * reply on closed topics; keymasters, bbPress's forum administrators, hold it too); *support user*, the one moderator chosen under
 * Settings › OmnaBase › Forums whose name the team's answers appeared under until now; *filter*, a named hook where site code changes a value;
 * *subscription*, a forum user's "notify me of follow-up replies" on a topic.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - `message.sent`'s `message.author.email` names the member; the poster is the site user with that address (whatever its case) when they
 *   can moderate, else the support user.
 * - The reply's post meta `_omnabase_posted_as` is `member` when someone other than the support user posted it, `support` otherwise.
 * - The filter `omnabase_forum_reply_author( int $user_id, array $message, \WP_Post $topic )` gets the poster chosen so far (the matched
 *   moderator, else the support user, else 0) and may answer another user id; an answer without `moderate` is replaced by the support user.
 * - Without a support user a matched moderator still posts; with neither the receiver answers 503 and posts nothing.
 * - (chosen, finding 9) A moderator subscribed to the topic stays subscribed after the plugin posts under their account. bbPress before
 *   2.6.20 removes the poster's subscription inside its reply insert when the request carries no subscription tick (wp-env runs 2.6.21,
 *   where that moved to the form's own moment, so this test guards the outcome; the restore protects older forums).
 * - (chosen, finding 8) A reply posted under the member's account queues nothing later either (bbPress approving it again is skipped at the
 *   trigger by the plugin's mark, and the outbox would skip it anyway).
 *
 * Why these fail today: the poster is always the support user and the reply has no `_omnabase_posted_as`; the filter does not exist. The
 * tests marked "(guard)" pass today and must keep passing.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumPostAsMemberTest extends RoundThreeTestCase {

	private const POSTED_AS = '_omnabase_posted_as';
	private const NINA      = 'nina@shop.example.org';

	/** A team reply on a topic, its author naming an address (none when null). */
	private function memberReply( int $topic, string $message, ?string $email, string $agent = 'Nina' ): array {
		$data = $this->teamReply( $topic, $message, $agent );
		if ( null !== $email ) {
			$data['message']['author']['email'] = $email;
		}
		return $data;
	}

	/** The one reply the plugin posted on a topic. */
	private function posted( int $topic ): \WP_Post {
		$replies = self::repliesOn( $topic );
		$this->assertCount( 1, $replies, 'one reply on the forum' );
		return $replies[0];
	}

	/**
	 * What it checks: A team member who is also a moderator on the forum answers under their own forum account, not the shared support one.
	 * Passes when: a `message.sent` naming Nina's address is answered 200; the topic's one reply is written by Nina, is marked "member", keeps
	 *   the closing line "— Nina, Team Kriesi" and OmnaBase's message id; the topic's last reply is it and Nina's forum reply count grew by one.
	 * Fails when: the customer still sees every answer under "Kriesi Support", or the forum's counts and "last reply by" do not follow.
	 */
	public function test_a_moderators_address_posts_the_reply_under_their_own_account(): void {
		$topic  = $this->filedTopic();
		$before = (int) bbp_get_user_reply_count( $this->moderator, true );

		$response = $this->deliverEvent( 'message.sent', 'dlv-pam-1', $this->memberReply( $topic, 'msg-pam-1', self::NINA ) );

		$this->assertSame( 200, $response->get_status() );
		$reply = $this->posted( $topic );
		$this->assertSame( $this->moderator, (int) $reply->post_author, 'posted under Nina\'s account' );
		$this->assertSame( 'member', (string) get_post_meta( $reply->ID, self::POSTED_AS, true ) );
		$this->assertStringContainsString( '— Nina, Team Kriesi', wp_strip_all_tags( $reply->post_content ) );
		$this->assertSame( 'msg-pam-1', (string) get_post_meta( $reply->ID, self::MESSAGE_META, true ) );
		$this->assertSame( $reply->ID, (int) bbp_get_topic_last_reply_id( $topic ) );
		$this->assertSame( $this->moderator, (int) bbp_get_reply_author_id( $reply->ID ) );
		$this->assertSame( $before + 1, (int) bbp_get_user_reply_count( $this->moderator, true ), 'Nina\'s reply count' );
	}

	/**
	 * What it checks: The address matches the forum account however its letters are cased (WordPress compares addresses without case).
	 * Passes when: a `message.sent` naming "NINA@Shop.Example.ORG" posts the reply under Nina's account.
	 * Fails when: a member whose sign-in address is typed in capitals somewhere quietly falls back to the support user.
	 */
	public function test_the_address_matches_whatever_its_case(): void {
		$topic = $this->filedTopic();

		$this->deliverEvent( 'message.sent', 'dlv-pam-case', $this->memberReply( $topic, 'msg-pam-case', 'NINA@Shop.Example.ORG' ) );

		$this->assertSame( $this->moderator, (int) $this->posted( $topic )->post_author );
	}

	/**
	 * What it checks: Someone who has an account on the forum but no moderator rights never answers under it: the support user does.
	 * Passes when: a `message.sent` naming Ben's address (a forum participant) posts the reply under the support user, marked "support".
	 * Fails when: the answer appears under an ordinary customer's account (which bbPress would hide on a closed topic, and Avia Support would
	 *   not show private data under).
	 *
	 * @dataProvider fallbacks
	 *
	 * @param string|null $email The address the event names (null: no email key at all).
	 */
	public function test_without_a_moderator_with_that_address_the_support_user_posts( ?string $email ): void {
		$topic = $this->filedTopic();

		$response = $this->deliverEvent( 'message.sent', 'dlv-pam-fb-' . md5( (string) $email ), $this->memberReply( $topic, 'msg-pam-fb-' . md5( (string) $email ), $email ) );

		$this->assertSame( 200, $response->get_status() );
		$reply = $this->posted( $topic );
		$this->assertSame( $this->support, (int) $reply->post_author, 'the support user posts' );
		$this->assertSame( 'support', (string) get_post_meta( $reply->ID, self::POSTED_AS, true ) );
	}

	/** A participant's address, an address no one on the site has, no address. */
	public static function fallbacks(): array {
		return array(
			'a participant'    => array( 'ben@example.org' ),
			'an unknown one'   => array( 'nobody.here@example.net' ),
			'no email key'     => array( null ),
			'an empty address' => array( '' ),
		);
	}

	/**
	 * What it checks: The support user is now a fallback, not a must: a moderator-member's answer is posted even when none is chosen.
	 * Passes when: with no support user chosen, a `message.sent` naming Nina's address is answered 200 and posted under Nina's account.
	 * Fails when: a site that never picked a support user keeps every member's answer waiting at OmnaBase although the member could post.
	 */
	public function test_without_a_support_user_a_matched_moderator_still_posts(): void {
		$topic = $this->filedTopic();
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'support_user' => 0 ) );

		$response = $this->deliverEvent( 'message.sent', 'dlv-pam-nosup', $this->memberReply( $topic, 'msg-pam-nosup', self::NINA ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->moderator, (int) $this->posted( $topic )->post_author );
	}

	/**
	 * What it checks: (guard) With neither a matching moderator nor a support user, the answer waits at OmnaBase as today.
	 * Passes when: with no support user chosen, a `message.sent` naming Ben's address and one naming no address are each answered 503 and
	 *   nothing is posted.
	 * Fails when: an answer is posted under no one, or OmnaBase is told it arrived and stops trying.
	 */
	public function test_without_a_support_user_and_no_moderator_the_reply_waits(): void {
		$topic = $this->filedTopic();
		$this->markForums( array( $this->publicForum ), array( 'support_user' => 0 ) );

		$ben  = $this->deliverEvent( 'message.sent', 'dlv-pam-wait-1', $this->memberReply( $topic, 'msg-pam-wait-1', 'ben@example.org' ) );
		$none = $this->deliverEvent( 'message.sent', 'dlv-pam-wait-2', $this->memberReply( $topic, 'msg-pam-wait-2', null ) );

		$this->assertSame( array( 503, 503 ), array( $ben->get_status(), $none->get_status() ) );
		$this->assertCount( 0, self::repliesOn( $topic ) );
	}

	/**
	 * What it checks: The answer posted under the member's account never travels back to OmnaBase as a new message (the echo), not when it
	 *   is posted and not when bbPress publishes it again later.
	 * Passes when: after the post under Nina's account no reply row waits in the outbox; after bbPress's "approved" moment fires for it, still
	 *   none; after the outbox runs, no message call was made.
	 * Fails when: every member's answer appears twice on the ticket, the second time as "Nina answered on the forum".
	 */
	public function test_the_reply_posted_as_the_member_is_never_sent_back(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();

		$this->deliverEvent( 'message.sent', 'dlv-pam-echo', $this->memberReply( $topic, 'msg-pam-echo', self::NINA ) );
		$reply = $this->posted( $topic );
		$this->assertSame( $this->moderator, (int) $reply->post_author, 'posted under Nina\'s account' );

		$this->assertSame( array(), $this->forumRows( 'reply' ), 'nothing queued by the post' );
		do_action( 'bbp_approved_reply', $reply->ID );
		$this->assertSame( array(), $this->forumRows( 'reply' ), 'nothing queued by a later approval' );
		$this->sendEverything();
		$this->assertCount( 0, $this->messageCalls() );
	}

	/**
	 * What it checks: (guard) The member's own forum replies, written by hand on the forum, still reach OmnaBase as the team's answer.
	 * Passes when: Nina writes a reply through bbPress's form and the outbox runs: one message call says it is the team's, already delivered,
	 *   with Nina's address in siteAuthor.
	 * Fails when: the new "posted as the member" check also swallows what the member writes on the forum themselves, so the ticket misses it.
	 */
	public function test_the_members_own_forum_reply_still_reaches_omnabase(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();

		$this->reply( $topic, $this->moderator, 'I checked your site: fixed.' );
		$this->sendEverything();

		$calls = $this->messageCalls();
		$this->assertCount( 1, $calls );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'team', $body['from'] ?? null );
		$this->assertTrue( (bool) ( $body['delivered'] ?? false ) );
		$this->assertSame( self::NINA, $body['siteAuthor']['email'] ?? null );
	}

	/**
	 * What it checks: Site code can choose who posts (another moderator, say a team account per language), and the choice is checked again.
	 * Passes when: a filter that answers Otto (a moderator) gets Nina's id, the message and the topic, and the reply is posted under Otto; a
	 *   filter that answers Ben (no moderator rights) gets the reply posted under the support user, marked "support".
	 * Fails when: a site cannot pick its own poster, or a careless filter posts the team's answer under a customer's account.
	 */
	public function test_the_filter_can_choose_another_moderator_but_not_a_customer(): void {
		$otto = $this->person( 'otto', 'otto@shop.example.org', 'Otto Moderator', bbp_get_moderator_role() );
		$seen = array();
		$pick = static function ( $user_id, $message, $topic_post ) use ( $otto, &$seen ) {
			$seen = array( (int) $user_id, is_array( $message ) ? (string) ( $message['id'] ?? '' ) : '', $topic_post instanceof \WP_Post ? $topic_post->ID : 0 );
			return $otto;
		};
		add_filter( 'omnabase_forum_reply_author', $pick, 10, 3 );
		try {
			$topic = $this->filedTopic();
			$this->deliverEvent( 'message.sent', 'dlv-pam-otto', $this->memberReply( $topic, 'msg-pam-otto', self::NINA ) );
			$this->assertSame( $otto, (int) $this->posted( $topic )->post_author, 'the filter chose Otto' );
			$this->assertSame( array( $this->moderator, 'msg-pam-otto', $topic ), $seen, 'the filter heard Nina, the message and the topic' );
		} finally {
			remove_filter( 'omnabase_forum_reply_author', $pick, 10 );
		}

		$ben = $this->ben;
		$bad = static fn () => $ben;
		add_filter( 'omnabase_forum_reply_author', $bad, 10, 3 );
		try {
			$topic = $this->filedTopic();
			$this->deliverEvent( 'message.sent', 'dlv-pam-ben', $this->memberReply( $topic, 'msg-pam-ben', self::NINA ) );
			$reply = $this->posted( $topic );
			$this->assertSame( $this->support, (int) $reply->post_author, 'a non-moderator is replaced by the support user' );
			$this->assertSame( 'support', (string) get_post_meta( $reply->ID, self::POSTED_AS, true ) );
		} finally {
			remove_filter( 'omnabase_forum_reply_author', $bad, 10 );
		}
	}

	/**
	 * What it checks: (chosen; a guard on bbPress 2.6.20 and later) Posting under the member's account does not switch off the member's own
	 *   "notify me" on the topic.
	 * Passes when: Nina is subscribed to the topic; after OmnaBase's answer is posted under her account she is still subscribed.
	 * Fails when: bbPress silently unsubscribes the moderator the first time OmnaBase posts for her, and she no longer hears the customer's
	 *   follow-ups on the forum.
	 */
	public function test_a_subscribed_moderator_stays_subscribed(): void {
		$topic = $this->filedTopic();
		bbp_add_user_subscription( $this->moderator, $topic );
		$this->assertTrue( bbp_is_user_subscribed( $this->moderator, $topic ), 'subscribed before' );

		$this->deliverEvent( 'message.sent', 'dlv-pam-sub', $this->memberReply( $topic, 'msg-pam-sub', self::NINA ) );

		$this->assertSame( $this->moderator, (int) $this->posted( $topic )->post_author, 'posted under Nina\'s account' );
		$this->assertTrue( bbp_is_user_subscribed( $this->moderator, $topic ), 'still subscribed after the post' );
	}
}
