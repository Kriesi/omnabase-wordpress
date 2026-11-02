<?php
/**
 * The forum sync and small fixes round (OmnaBase brief docs/briefs/forum-sync-and-small-fixes-round.md, part one rules 1 and 3, and the words
 * section, and the founder's decision on Archived of the same night, rule 14; report docs/reviews/forum-sync-and-small-fixes-round-red-team-report.md
 * in the OmnaBase repository, findings 24 to 29), the red
 * team's failing tests, written before the code. They run in the forum run (bbPress loaded: bin/test-integration.sh's third run).
 *
 * The words: *topic*, a bbPress forum thread; *closing* it is bbPress's own Close (only moderators can still reply; it stays readable),
 * *reopening* its Open; a *hook*, a named moment where a plugin's code runs (`bbp_closed_topic`, `bbp_opened_topic`, WordPress's
 * `transition_post_status`); the *outbox*, the plugin's queue of calls to OmnaBase, retried until they get through; a *webhook*, OmnaBase's
 * signed call to the site (`ticket.resolved`, `ticket.updated` with `changes.status`); `seq`, the event's number in the workspace's log.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - Forum → ticket: closing a filed topic (bbPress's Close, or the status `closed` set in wp-admin) queues one outbox row of the kind
 *   `forum_status` for the topic (`subject_id` the topic's id), and opening it again does the same; the row, when it goes, sends
 *   `PATCH /tickets/{number}` with `{ "status": "resolved" }` for a closed topic and `{ "status": "open" }` for an open one (the topic's status
 *   when the row goes: two quick changes send the last one). A topic that is no ticket queues nothing (guard).
 * - The plugin's own close or reopen, done on OmnaBase's word, queues nothing (no echo).
 * - Ticket → forum: the switch "Keep topics in step with tickets" (`omnabase_forum[in_step]`, stored as `in_step`), on when never stored (the
 *   old `close_resolved` is ignored: development stage), with the help "Closed when the ticket is resolved, reopened when it is open or
 *   pending." (rule 14: Archived is gone); with it on, `ticket.resolved` and `ticket.updated` with status `resolved` close the topic, `open`
 *   or `pending` reopen a closed one; with it off nothing moves. The message centre's `'archived' => 'Closed'` word goes (pinned in the
 *   plugin's FrontendLookTest, adjusted).
 * - (chosen, finding 27) A status webhook older than the last one applied to the topic (a smaller `seq`, e.g. a retried delivery) changes
 *   nothing; the newest applied `seq` is kept on the topic as post meta `_omnabase_status_seq`.
 *
 * Why these fail today: no hook listens to bbPress's close and open; the closer only closes, only on resolve, and only when the old switch
 * (off by default) is on.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumStatusSyncTest extends RoundThreeTestCase {

	use AdminLookShapes;

	public function set_up(): void {
		parent::set_up();
		$this->answers['PATCH '] = fn ( $args, $url ) => self::answer( 200, array( 'number' => (int) basename( (string) wp_parse_url( (string) $url, PHP_URL_PATH ) ), 'status' => (string) ( self::bodyOf( array( 'args' => (array) $args ) )['status'] ?? 'open' ) ) );
	}

	/** A filed topic with the outbox and the recorded calls emptied: what follows is the test's own. */
	private function quietTopic(): int {
		$topic = $this->filedTopic();
		$this->clearOutbox();
		$this->calls = array();
		return $topic;
	}

	/** The `PATCH /tickets/{number}` calls about a topic's ticket, their bodies. */
	private function statusCalls( int $topic ): array {
		$number = (int) get_post_meta( $topic, self::TICKET_META, true );
		return array_map( static fn ( $c ) => self::bodyOf( $c ), $this->callsTo( 'PATCH', '/tickets/' . $number ) );
	}

	/** A signed status webhook about a topic with its own `seq`. */
	private function statusEvent( int $topic, string $type, string $status, int $seq ): \WP_REST_Response {
		$id   = 'evt_' . $seq . '_' . wp_generate_password( 6, false );
		$body = (string) wp_json_encode(
			array(
				'id'        => $id,
				'seq'       => $seq,
				'type'      => $type,
				'createdAt' => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
				'workspace' => array( 'slug' => 'kriesi', 'name' => 'Team Kriesi' ),
				'origin'    => null,
				'data'      => array( 'ticket' => $this->ticketData( $topic, $status ), 'changes' => array( 'status' => $status ) ),
			)
		);
		return $this->deliver( '/omnabase/v1/events', $body, self::WEBHOOKS, array( 'x-omnabase-event' => $type, 'x-omnabase-delivery' => $id ) );
	}

	/**
	 * What it checks: Closing a topic in the forum resolves its ticket in OmnaBase.
	 * Passes when: after a moderator closes the filed topic (bbPress's Close) the outbox holds exactly one status row for the topic, and when it
	 *   goes OmnaBase receives one `PATCH /tickets/{number}` with the status "resolved".
	 * Fails when: a topic the moderator closed sits in OmnaBase's Pending for good, and the team works a question the forum already finished.
	 */
	public function test_closing_in_the_forum_resolves_the_ticket(): void {
		$topic = $this->quietTopic();
		wp_set_current_user( $this->moderator );
		bbp_close_topic( $topic );
		$this->assertTrue( bbp_is_topic_closed( $topic ) );
		$this->assertCount( 1, $this->forumRows( 'status', $topic ), 'one status row, though bbPress fires two hooks' );
		$this->sendEverything( 2 );
		$this->assertSame( array( array( 'status' => 'resolved' ) ), $this->statusCalls( $topic ) );
	}

	/**
	 * What it checks: Reopening a topic in the forum opens its ticket again.
	 * Passes when: after a closed topic is opened again (bbPress's Open) one status row goes and OmnaBase receives the status "open".
	 * Fails when: the moderator reopens a topic for the customer but the ticket stays Resolved, so nobody in the team sees the new answers.
	 */
	public function test_reopening_in_the_forum_opens_the_ticket(): void {
		$topic = $this->quietTopic();
		bbp_close_topic( $topic );
		$this->sendEverything( 2 );
		$this->calls = array();
		bbp_open_topic( $topic );
		$this->assertFalse( bbp_is_topic_closed( $topic ) );
		$this->sendEverything( 2 );
		$this->assertSame( array( array( 'status' => 'open' ) ), $this->statusCalls( $topic ) );
	}

	/**
	 * What it checks: A topic closed from wp-admin's editor counts as closed too.
	 * Passes when: setting the topic's status to "closed" in the editor (only WordPress's status change fires) queues one status row that sends
	 *   "resolved".
	 * Fails when: closing works from the forum page but not from the admin, so the result depends on where the moderator clicked.
	 */
	public function test_closing_in_wp_admin_resolves_the_ticket(): void {
		$topic = $this->quietTopic();
		wp_update_post( array( 'ID' => $topic, 'post_status' => bbp_get_closed_status_id() ) );
		$this->assertCount( 1, $this->forumRows( 'status', $topic ) );
		$this->sendEverything( 2 );
		$this->assertSame( array( array( 'status' => 'resolved' ) ), $this->statusCalls( $topic ) );
	}

	/**
	 * What it checks: (guard) A topic that is no ticket never reaches OmnaBase through a close.
	 * Passes when: closing a topic in a forum that is not marked queues no status row.
	 * Fails when: the plugin calls OmnaBase about topics it never filed.
	 */
	public function test_a_topic_without_a_ticket_queues_nothing(): void {
		$topic = $this->topic( $this->unmarkedForum, $this->ada );
		$this->clearOutbox();
		bbp_close_topic( $topic );
		$this->assertCount( 0, $this->forumRows( 'status', $topic ) );
	}

	/**
	 * What it checks: The plugin's own close and reopen, done because OmnaBase said so, are not sent back.
	 * Passes when: a `ticket.resolved` webhook closes the topic and queues no status row; a `ticket.updated` with status "open" reopens it and
	 *   queues none either; nothing is sent to OmnaBase.
	 * Fails when: every close OmnaBase asks for comes back as a second change, and the two sides keep answering each other.
	 */
	public function test_the_plugins_own_changes_are_not_sent_back(): void {
		$topic = $this->quietTopic();
		$this->assertSame( 200, $this->statusEvent( $topic, 'ticket.resolved', 'resolved', 3001 )->get_status() );
		$this->assertTrue( bbp_is_topic_closed( $topic ), 'closed on OmnaBase\'s word (the switch is on by default)' );
		$this->assertSame( 200, $this->statusEvent( $topic, 'ticket.updated', 'open', 3002 )->get_status() );
		$this->assertFalse( bbp_is_topic_closed( $topic ), 'reopened on OmnaBase\'s word' );
		$this->assertCount( 0, $this->forumRows( 'status', $topic ), 'nothing queued' );
		$this->sendEverything( 2 );
		$this->assertSame( array(), $this->statusCalls( $topic ) );
	}

	/**
	 * What it checks: With the switch on (its default), each status in OmnaBase puts the topic in step.
	 * Passes when: Resolved, arriving as "ticket resolved", closes an open topic; Open and Pending reopen a closed one.
	 * Fails when: a resolved ticket leaves its topic open, or a team answer sent as pending leaves the customer in front of a closed topic.
	 *
	 * Adjusted 2026-11-02 by the builder (the founder's decision on the red team's question 1, recorded in the brief's "After the red team's
	 * review"): "resolved" arriving as "ticket updated" is how OmnaBase now sends the AI's automatic answer that resolved a ticket, and that
	 * one must not close the topic; its row left this list for a test of its own below, which checks the opposite outcome.
	 *
	 * @dataProvider statuses
	 *
	 * @param string $type   The event.
	 * @param string $status The ticket's status.
	 * @param bool   $closed Whether the topic ends closed.
	 */
	public function test_each_status_puts_the_topic_in_step( string $type, string $status, bool $closed ): void {
		$topic = $this->quietTopic();
		if ( ! $closed ) {
			bbp_close_topic( $topic );
			$this->clearOutbox();
		}
		$this->assertSame( 200, $this->statusEvent( $topic, $type, $status, 4000 )->get_status() );
		$this->assertSame( $closed, bbp_is_topic_closed( $topic ) );
	}

	/** The three statuses and the shape each arrives in. */
	public static function statuses(): array {
		return array(
			'resolved (ticket.resolved)'  => array( 'ticket.resolved', 'resolved', true ),
			'open (ticket.updated)'       => array( 'ticket.updated', 'open', false ),
			'pending (ticket.updated)'    => array( 'ticket.updated', 'pending', false ),
		);
	}

	/**
	 * What it checks: The AI's automatic answer that resolves a ticket leaves the forum topic open, so the customer can still say it did not help.
	 * Passes when: "ticket updated" with the status "resolved" (the shape OmnaBase sends for the AI's automatic resolve) leaves an open topic
	 *   open and queues nothing; a person's resolve, "ticket resolved", still closes it afterwards.
	 * Fails when: a bot answer locks the thread at once, and the customer cannot answer "that did not help" in the forum.
	 *
	 * Added 2026-11-02 by the builder: the founder's decision on the red team's question 1 (the brief's "After the red team's review").
	 */
	public function test_the_ais_automatic_resolve_leaves_the_topic_open(): void {
		$topic = $this->quietTopic();
		$this->assertSame( 200, $this->statusEvent( $topic, 'ticket.updated', 'resolved', 4100 )->get_status() );
		$this->assertFalse( bbp_is_topic_closed( $topic ), 'the AI\'s resolve closes nothing' );
		$this->assertCount( 0, $this->forumRows( 'status', $topic ), 'nothing queued' );
		$this->statusEvent( $topic, 'ticket.resolved', 'resolved', 4101 );
		$this->assertTrue( bbp_is_topic_closed( $topic ), 'a person\'s resolve still closes it' );
	}

	/**
	 * What it checks: The switch is on for every site after the update, and turning it off stops both directions of OmnaBase's word.
	 * Passes when: with the old switch stored as off (`close_resolved` false) the topic still closes on a resolve; with the new switch stored off
	 *   a resolve leaves an open topic open and an "open" leaves a closed topic closed.
	 * Fails when: sites that never touched the old switch stay out of step, or an owner who turned it off still sees topics move.
	 */
	public function test_the_switch_is_on_by_default_and_off_means_off(): void {
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'close_resolved' => false ) );
		$topic = $this->quietTopic();
		$this->statusEvent( $topic, 'ticket.resolved', 'resolved', 5001 );
		$this->assertTrue( bbp_is_topic_closed( $topic ), 'the old stored switch is ignored: on' );

		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'in_step' => false ) );
		$open = $this->quietTopic();
		$this->statusEvent( $open, 'ticket.resolved', 'resolved', 5002 );
		$this->assertFalse( bbp_is_topic_closed( $open ), 'off: a resolve closes nothing' );
		bbp_close_topic( $open );
		$this->statusEvent( $open, 'ticket.updated', 'open', 5003 );
		$this->assertTrue( bbp_is_topic_closed( $open ), 'off: an open reopens nothing' );
	}

	/**
	 * What it checks: An older status message arriving late never undoes a newer one.
	 * Passes when: a resolve numbered 6002 closes the topic, and an "open" numbered 6001 arriving afterwards (a retried delivery) leaves it
	 *   closed; the topic remembers 6002 as the last number applied.
	 * Fails when: a delivery that was retried after a network hiccup reopens a topic the team closed a minute later.
	 */
	public function test_an_older_status_message_changes_nothing(): void {
		$topic = $this->quietTopic();
		$this->statusEvent( $topic, 'ticket.resolved', 'resolved', 6002 );
		$this->assertTrue( bbp_is_topic_closed( $topic ) );
		$this->statusEvent( $topic, 'ticket.updated', 'open', 6001 );
		$this->assertTrue( bbp_is_topic_closed( $topic ), 'the older message is ignored' );
		$this->assertSame( 6002, (int) get_post_meta( $topic, '_omnabase_status_seq', true ) );
	}

	/**
	 * What it checks: The Forums tab names the switch in the brief's words.
	 * Passes when: the forum settings have a row labelled "Keep topics in step with tickets" whose control is the checkbox
	 *   `omnabase_forum[in_step]`, checked when nothing is stored, with the help "Closed when the ticket is resolved, reopened when it is open
	 *   or pending."
	 * Fails when: the screen still says "Close the topic when its ticket is resolved" while the plugin also reopens, or shows the
	 *   switch off although it is on.
	 */
	public function test_the_switch_on_the_forums_tab(): void {
		update_option( self::FORUM_OPTION, array( 'forums' => array( $this->publicForum ), 'support_user' => $this->support ) );
		wp_set_current_user( $this->admin );
		$html = $this->openTab( 'forums' );
		$rows = array();
		foreach ( $this->rowsOf( $html, 'forums' ) as $row ) {
			$rows[ $row['label'] ] = $row['control'];
		}
		$this->assertArrayHasKey( 'Keep topics in step with tickets', $rows );
		$box = ( new \DOMXPath( $rows['Keep topics in step with tickets']->ownerDocument ) )->query( ".//input[@type='checkbox'][@name='omnabase_forum[in_step]']", $rows['Keep topics in step with tickets'] );
		$this->assertSame( 1, $box->length, 'the switch' );
		$this->assertTrue( $box->item( 0 )->hasAttribute( 'checked' ), 'on when nothing is stored' );
		$this->assertStringContainsString( 'Closed when the ticket is resolved, reopened when it is open or pending.', (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		$this->assertStringNotContainsString( 'Close the topic when its ticket is resolved', $html );
	}
}
