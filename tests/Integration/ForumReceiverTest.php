<?php
/**
 * M13 round three, the red team's failing tests: OmnaBase's reply on a topic's ticket (a signed `message.sent` at the events receiver)
 * appears on the forum as a reply by the support user, once, with the agent's name in a closing line; a topic that is gone answers 409;
 * bbPress's subscriber mails leave after the answer; a resolved ticket closes the topic only when switched on.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 7: "**OmnaBase's reply** (`message.sent` whose ticket `external.id`
 * is one of our topics) → `bbp_insert_reply` by the **support user**, the agent's name in a closing line ('— Ada, Team Kriesi'), HTML through
 * `wp_kses_post`, the topic's freshness and counts updated as bbPress's own form does; answered 200 at once, the subscriber mails sent by a
 * cron job afterwards (bbPress's mails would otherwise run inside OmnaBase's 5 seconds). The message id makes a redelivery a no-op. A topic
 * gone or trashed → 409 (never 410) … `ticket.updated` to resolved closes the topic only when the admin turns that on"; round three's *Red
 * team*: "the support user's posted reply never sent back; a redelivery and the same message id twice give one forum reply; the 200 comes
 * before subscriber mails; a trashed topic answers 409; `ticket.updated` to resolved closes only when switched on". Review S3 (the echo; the
 * mails inside OmnaBase's 5 seconds). `docs/public-api.md`: "`410 Gone` … switches the address off at once". Round one's receiver: a handled
 * delivery id is recorded only after its handling ran through. The coordinator: "the receiver answering 2xx only after the post exists";
 * "a topic closed → the reply still posted (bbPress allows moderators)".
 *
 * Shapes the red team pins: those of `RoundThreeTestCase`. (chosen) A `message.sent` for a ticket that is not one of the site's topics
 * (no `external`) is answered 200 and posts nothing. (chosen) With no support user chosen the receiver answers an error other than 410 and
 * posts nothing (OmnaBase retries while the administrator picks one). (chosen) A resolve arrives either as `ticket.updated` with the status
 * resolved (the brief's words) or as `ticket.resolved` (what OmnaBase's fan-out sends today; see the report's contradiction): both close the
 * topic when the switch is on.
 *
 * Why these fail today: nothing listens to `omnabase_webhook`, so every delivery is answered 200 and nothing is posted.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumReceiverTest extends RoundThreeTestCase {

	/**
	 * What it checks: The team's answer in OmnaBase appears on the forum under the support user, with the agent's name, as a real bbPress
	 *   reply.
	 * Passes when: a signed `message.sent` for Ada's topic is answered 200; the topic then has one published reply by the support user holding
	 *   the answer's formatting (bold kept, a script removed) and the closing line "— Ada, Team Kriesi", marked with OmnaBase's message id; the
	 *   topic's reply count is 1 and its last reply is that reply.
	 * Fails when: the customer never sees the answer on the forum, sees it under a random account or without who answered, or a script in a
	 *   reply runs in visitors' browsers.
	 */
	public function test_omnabases_reply_is_posted_by_the_support_user_with_a_closing_line(): void {
		$topic = $this->filedTopic();
		$data  = $this->teamReply( $topic, 'msg-1', 'Ada', '<p>Please update to <strong>2.1</strong>.</p><script>alert(1)</script>', 'Please update to 2.1.' );

		$response = $this->deliverEvent( 'message.sent', 'dlv-1', $data );

		$this->assertSame( 200, $response->get_status() );
		$replies = self::repliesOn( $topic );
		$this->assertCount( 1, $replies );
		$reply = $replies[0];
		$this->assertSame( $this->support, (int) $reply->post_author );
		$this->assertSame( bbp_get_public_status_id(), $reply->post_status );
		$this->assertStringContainsString( '<strong>2.1</strong>', $reply->post_content );
		$this->assertStringNotContainsString( '<script', $reply->post_content );
		$this->assertStringContainsString( '— Ada, Team Kriesi', wp_strip_all_tags( $reply->post_content ) );
		$this->assertSame( 'msg-1', (string) get_post_meta( $reply->ID, self::MESSAGE_META, true ) );
		$this->assertSame( 1, (int) bbp_get_topic_reply_count( $topic, true ) );
		$this->assertSame( $reply->ID, (int) bbp_get_topic_last_reply_id( $topic ) );
	}

	/**
	 * What it checks: The site's own posting of OmnaBase's answer never travels back to OmnaBase (review S3, the echo).
	 * Passes when: posting the answer goes through bbPress (its "a reply was inserted" moment fires) and yet no reply row is queued, and after
	 *   the outbox runs no message call was made.
	 * Fails when: every team answer appears twice on the ticket, the second time as the site's.
	 */
	public function test_posting_omnabases_reply_queues_nothing(): void {
		$topic  = $this->filedTopic();
		$this->calls = array();
		$before = did_action( 'bbp_insert_reply' );

		$this->deliverEvent( 'message.sent', 'dlv-echo', $this->teamReply( $topic, 'msg-echo' ) );

		$this->assertGreaterThan( $before, did_action( 'bbp_insert_reply' ), 'the reply went through bbPress' );
		$this->assertSame( array(), $this->forumRows( 'reply' ) );
		$this->sendEverything();
		$this->assertCount( 0, $this->messageCalls() );
	}

	/**
	 * What it checks: One answer is one forum reply, however often OmnaBase delivers it.
	 * Passes when: the same delivery twice and the same message id under a second delivery id are each answered 200, and the topic still has
	 *   exactly one reply.
	 * Fails when: a retry after a slow answer posts the team's reply two or three times on the forum.
	 */
	public function test_a_redelivery_and_the_same_message_twice_give_one_reply(): void {
		$topic = $this->filedTopic();
		$data  = $this->teamReply( $topic, 'msg-twice' );

		$first  = $this->deliverEvent( 'message.sent', 'dlv-a', $data );
		$again  = $this->deliverEvent( 'message.sent', 'dlv-a', $data );
		$second = $this->deliverEvent( 'message.sent', 'dlv-b', $data );

		$this->assertSame( array( 200, 200, 200 ), array( $first->get_status(), $again->get_status(), $second->get_status() ) );
		$this->assertCount( 1, self::repliesOn( $topic ) );
	}

	/**
	 * What it checks: A topic that was trashed or deleted answers 409 so OmnaBase keeps trying and then falls back to mail, never 410 (which
	 *   would switch the site's address off for every topic); the try is logged, and once the topic is restored a redelivery posts the answer.
	 * Passes when: for a trashed topic the receiver answers 409, posts nothing, and its delivery log row does not say handled; after the topic is
	 *   restored the same delivery is answered 200 and posts the reply; a permanently deleted topic also answers 409.
	 * Fails when: OmnaBase believes the answer was posted when it was not, or one removed topic switches off the whole forum's link to OmnaBase.
	 */
	public function test_a_trashed_or_deleted_topic_answers_409_and_a_restored_one_gets_the_reply(): void {
		$topic = $this->filedTopic();
		$data  = $this->teamReply( $topic, 'msg-trash' );
		wp_trash_post( $topic );

		$refused = $this->deliverEvent( 'message.sent', 'dlv-trash', $data );

		$this->assertSame( 409, $refused->get_status() );
		$this->assertCount( 0, array_filter( self::repliesOn( $topic ), static fn ( $r ) => 'trash' !== $r->post_status ) );
		$log = (array) get_option( 'omnabase_deliveries', array() );
		$this->assertNotSame( 'handled', $log[0]['outcome'] ?? null, 'the log tells the administrator it was not posted' );

		wp_untrash_post( $topic );
		wp_publish_post( $topic );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-trash', $data )->get_status() );
		$this->assertCount( 1, self::repliesOn( $topic ) );

		$deleted = $this->filedTopic();
		$data    = $this->teamReply( $deleted, 'msg-deleted' );
		wp_delete_post( $deleted, true );
		$this->assertSame( 409, $this->deliverEvent( 'message.sent', 'dlv-deleted', $data )->get_status() );
	}

	/**
	 * What it checks: The receiver says "done" only once the reply exists: when the reply cannot be saved, OmnaBase is told to try again.
	 * Passes when: with WordPress refusing to save the post the receiver answers an error (not 2xx, not 410) and nothing is posted; once saving
	 *   works, the same delivery is answered 200 and the reply is there.
	 * Fails when: OmnaBase marks the answer delivered although it never appeared on the forum, and the customer waits for ever.
	 */
	public function test_the_receiver_answers_2xx_only_after_the_reply_exists(): void {
		$topic = $this->filedTopic();
		$data  = $this->teamReply( $topic, 'msg-fail' );
		add_filter( 'wp_insert_post_empty_content', '__return_true' );

		$failed = $this->deliverEvent( 'message.sent', 'dlv-fail', $data );

		remove_filter( 'wp_insert_post_empty_content', '__return_true' );
		$this->assertGreaterThanOrEqual( 400, $failed->get_status() );
		$this->assertNotSame( 410, $failed->get_status() );
		$this->assertCount( 0, self::repliesOn( $topic ) );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-fail', $data )->get_status() );
		$this->assertCount( 1, self::repliesOn( $topic ) );
	}

	/**
	 * What it checks: A closed topic still gets the team's answer (moderators may reply on closed topics), visible like any other reply.
	 * Passes when: after a moderator closed Ada's topic, OmnaBase's reply is answered 200 and the topic has one published reply by the support
	 *   user (not one stored with the topic's "closed" status, which bbPress would hide).
	 * Fails when: the team's answer to a closed topic is lost or invisible, and OmnaBase thinks it was delivered.
	 */
	public function test_a_closed_topic_still_gets_the_reply(): void {
		$topic = $this->filedTopic();
		bbp_close_topic( $topic );

		$response = $this->deliverEvent( 'message.sent', 'dlv-closed', $this->teamReply( $topic, 'msg-closed' ) );

		$this->assertSame( 200, $response->get_status() );
		$replies = self::repliesOn( $topic );
		$this->assertCount( 1, $replies );
		$this->assertSame( bbp_get_public_status_id(), $replies[0]->post_status );
		// Builder's adjustment (DECISIONS "M13 round three", test adjustment 1): the red team's last line asked bbPress whether the reply is
		// "published", but bbPress answers that only for a reply whose topic is open too, so it is false for every reply on a closed topic,
		// also for the ones written before the moderator closed it. What the test means, "visible like any other reply", is bbPress counting
		// it among the topic's shown replies (the count the topic's page and its forum list print).
		$this->assertSame( 1, (int) bbp_get_topic_reply_count( $topic, true ), 'bbPress counts it among the topic\'s shown replies' );
	}

	/**
	 * What it checks: bbPress's mails to the topic's subscribers leave after the receiver has answered, never inside OmnaBase's five seconds.
	 * Passes when: Ben follows Ada's topic; while the receiver handles OmnaBase's reply no mail is sent; a job of the plugin's is scheduled; when
	 *   it runs, Ben is mailed once about the new reply.
	 * Fails when: a slow mail server makes OmnaBase's call time out and the reply is posted again on the retry, or subscribers never hear of the
	 *   team's answer.
	 */
	public function test_subscriber_mails_leave_after_the_answer(): void {
		$topic = $this->filedTopic();
		bbp_add_user_subscription( $this->ben, $topic );
		$this->mails = array();

		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-mail', $this->teamReply( $topic, 'msg-mail' ) )->get_status() );

		$this->assertSame( 0, $this->mailedTo( 'ben@example.org' ), 'no mail inside the receiver' );
		$this->assertGreaterThan( 0, $this->runPluginJobs(), 'a job of the plugin\'s was left for later' );
		$this->assertSame( 1, $this->mailedTo( 'ben@example.org' ) );
	}

	/**
	 * What it checks: The closing line follows the administrator's setting.
	 * Passes when: with the closing line "Cheers, {agent} from {workspace}" the posted reply ends with "Cheers, Ada from Team Kriesi" and not the
	 *   default line.
	 * Fails when: the site owner cannot word how the team signs its forum answers.
	 */
	public function test_the_closing_line_follows_the_setting(): void {
		$topic = $this->filedTopic();
		$this->markForums( array( $this->publicForum ), array( 'closing_line' => 'Cheers, {agent} from {workspace}' ) );

		$this->deliverEvent( 'message.sent', 'dlv-closing', $this->teamReply( $topic, 'msg-closing' ) );

		$text = wp_strip_all_tags( (string) ( self::repliesOn( $topic )[0]->post_content ?? '' ) );
		$this->assertStringContainsString( 'Cheers, Ada from Team Kriesi', $text );
		$this->assertStringNotContainsString( '— Ada, Team Kriesi', $text );
	}

	/**
	 * What it checks: Only the team's replies on the site's own topics are posted: a customer's message, or a reply on a ticket that is not one
	 *   of the site's topics, posts nothing; without a support user nothing is posted and OmnaBase is asked to try again later.
	 * Passes when: a `message.sent` whose message comes in from the customer, and one for a ticket with no site reference, are answered 200 and
	 *   post nothing; with no support user chosen, the team's reply is answered with an error other than 410 and posts nothing.
	 * Fails when: the customer's own words reappear under the support user, a stranger's ticket is posted into a topic, or replies appear under
	 *   no one.
	 */
	public function test_only_the_teams_replies_on_the_sites_topics_are_posted(): void {
		$topic = $this->filedTopic();
		$in    = $this->teamReply( $topic, 'msg-in' );
		$in['message']['direction'] = 'in';
		$in['message']['kind']      = 'customer';
		$none  = $this->teamReply( $topic, 'msg-none' );
		$none['ticket']['external'] = null;

		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-in', $in )->get_status() );
		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-none', $none )->get_status() );
		$this->assertCount( 0, self::repliesOn( $topic ) );

		$this->markForums( array( $this->publicForum ), array( 'support_user' => 0 ) );
		$status = $this->deliverEvent( 'message.sent', 'dlv-nobody', $this->teamReply( $topic, 'msg-nobody' ) )->get_status();
		$this->assertGreaterThanOrEqual( 400, $status );
		$this->assertNotSame( 410, $status );
		$this->assertCount( 0, self::repliesOn( $topic ) );
	}

	/**
	 * What it checks: A ticket resolved in OmnaBase closes its forum topic only when the administrator turned that on.
	 * Passes when: with the switch off the resolve leaves the topic open; with it on, the resolve closes it (answered 200 both times).
	 * Fails when: topics close behind the site owner's back, or the switch does nothing.
	 *
	 * @dataProvider resolves
	 *
	 * @param string $type    The event type.
	 * @param array  $changes The event's `changes`.
	 */
	public function test_a_resolved_ticket_closes_the_topic_only_when_switched_on( string $type, array $changes ): void {
		$topic = $this->filedTopic();

		$off = $this->deliverEvent( $type, 'dlv-off', array( 'ticket' => $this->ticketData( $topic, 'resolved' ), 'changes' => $changes ) );
		$this->assertSame( 200, $off->get_status() );
		$this->assertFalse( bbp_is_topic_closed( $topic ), 'switched off: the topic stays open' );

		$this->markForums( array( $this->publicForum ), array( 'close_resolved' => true ) );
		$on = $this->deliverEvent( $type, 'dlv-on', array( 'ticket' => $this->ticketData( $topic, 'resolved' ), 'changes' => $changes ) );
		$this->assertSame( 200, $on->get_status() );
		$this->assertTrue( bbp_is_topic_closed( $topic ), 'switched on: the topic is closed' );
	}

	/** The two shapes a resolve can arrive in. */
	public static function resolves(): array {
		return array(
			'ticket.updated to resolved (the brief)' => array( 'ticket.updated', array( 'status' => 'resolved' ) ),
			'ticket.resolved (OmnaBase\'s fan-out)'  => array( 'ticket.resolved', array( 'status' => 'resolved' ) ),
		);
	}
}
