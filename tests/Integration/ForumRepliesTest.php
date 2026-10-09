<?php
/**
 * M13 round three, the red team's failing tests: replies on the forum reach the topic's ticket, the customer's as the customer's, a
 * moderator's as the team's answer already on the site, everyone else's not at all, and the plugin's own replies never come back.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 7: "A reply **by the topic's author** → `{ from: "customer", body,
 * external { id: "reply-<id>" } }`. … A moderator who **is not a member** → `{ from: "team", delivered: true, siteAuthor: { name: "<support
 * user's name>" } }`: a customer-visible team message, recorded as delivered, the ticket waiting on the customer, never a note … Other
 * users' replies: not sent … **Echo**: the plugin's own replies carry `_omnabase_message_id` post meta and are inserted under a flag; the
 * sending job skips any reply with that meta or by the support user, so a team answer never comes back"; "Edits and deletions are not synced
 * in v1"; round three's *Red team*: "the author's reply a customer message; … a non-member moderator's a customer-visible team message (no
 * note, ticket pending); another user's nothing; the support user's posted reply never sent back". Review S3: "decide in the sending job,
 * not the hook". The coordinator: "a moderator's reply → as the team with `siteAuthor` … under the support user's name, not a note".
 *
 * Shapes the red team pins: those of `RoundThreeTestCase`. (chosen) Every moderator's reply goes with `siteAuthor` (the plugin cannot tell
 * which moderator is a member of the team; the brief's member branch is left to the builder and the coordinator, see the report). (chosen) A
 * reply written while its topic still waits for its ticket waits too and goes after it.
 *
 * Why these fail today: nothing listens to bbPress's replies; there is no reply job.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumRepliesTest extends RoundThreeTestCase {

	/**
	 * What it checks: When the customer answers on their own topic, the answer joins their ticket as the customer's message.
	 * Passes when: Ada's reply on her filed topic makes one call to that ticket's messages, from the customer, with her text as plain text, the
	 *   site's reference "reply-<id>" and an Idempotency-Key; it names no member, no other person and nothing delivered.
	 * Fails when: the customer's follow-up stays on the forum while the ticket looks answered, or it is filed as somebody else's.
	 */
	public function test_the_topic_authors_reply_is_the_customers_message(): void {
		$topic = $this->filedTopic();
		$this->calls = array();
		$reply = $this->reply( $topic, $this->ada, '<p>Still <em>broken</em> after the update.</p>' );

		$this->workOutbox();

		$calls = $this->messageCalls( self::FIRST_NUMBER );
		$this->assertCount( 1, $calls );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'customer', $body['from'] ?? null );
		$this->assertStringContainsString( 'Still broken after the update.', (string) ( $body['body'] ?? '' ) );
		$this->assertStringNotContainsString( '<', (string) $body['body'] );
		$this->assertSame( array( 'id' => 'reply-' . $reply ), $body['external'] ?? null );
		foreach ( array( 'member', 'customer', 'delivered', 'siteAuthor', 'kind' ) as $absent ) {
			$this->assertArrayNotHasKey( $absent, $body, $absent );
		}
		$this->assertNotEmpty( self::headerOf( $calls[0], 'Idempotency-Key' ) );
	}

	/**
	 * What it checks: A moderator's answer on the forum is recorded on the ticket as the team's answer, already delivered, shown under the
	 *   support user's name, never as a hidden note.
	 * Passes when: Nina's (a moderator's) reply makes one call from the team, delivered, with siteAuthor named "Kriesi Support" (the support
	 *   user's display name), her text and "reply-<id>"; it carries no customer and is not a note.
	 * Fails when: the ticket keeps waiting on the team although a moderator answered, the customer gets the answer again by mail, or it hides
	 *   in an internal note.
	 */
	public function test_a_moderators_reply_is_a_delivered_team_message_under_the_support_users_name(): void {
		$topic = $this->filedTopic();
		$this->calls = array();
		$reply = $this->reply( $topic, $this->moderator, 'Please clear the cache.' );

		$this->workOutbox();

		$calls = $this->messageCalls( self::FIRST_NUMBER );
		$this->assertCount( 1, $calls );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'team', $body['from'] ?? null );
		$this->assertTrue( $body['delivered'] ?? null );
		$this->assertSame( 'Kriesi Support', $body['siteAuthor']['name'] ?? null );
		$this->assertStringContainsString( 'Please clear the cache.', (string) ( $body['body'] ?? '' ) );
		$this->assertSame( array( 'id' => 'reply-' . $reply ), $body['external'] ?? null );
		$this->assertArrayNotHasKey( 'customer', $body );
		$this->assertNotSame( 'note', $body['kind'] ?? null );
	}

	/**
	 * What it checks: Replies by anyone else, by the support user, or carrying the plugin's own marker are never sent: only the customer and
	 *   moderators speak on the ticket, and OmnaBase's answers never come back to it.
	 * Passes when: Ben's reply on Ada's topic, a reply the support user typed on the forum, and Nina's reply that carries OmnaBase's message id
	 *   (written after the post was saved, as the plugin's own insert does) make no call to the ticket, and no row needs attention.
	 * Fails when: a stranger's post lands on Ada's ticket as hers, or the team's answer appears twice on the ticket (sent, then echoed back).
	 */
	public function test_other_users_the_support_user_and_marked_replies_are_never_sent(): void {
		$topic = $this->filedTopic();
		$this->calls = array();
		$this->reply( $topic, $this->ben, 'Same here!' );
		$this->reply( $topic, $this->support, 'Typed by hand as the support user.' );
		$marked = $this->reply( $topic, $this->moderator, 'Posted from OmnaBase.' );
		update_post_meta( $marked, self::MESSAGE_META, 'msg-from-omnabase' );

		$this->sendEverything();

		$this->assertCount( 0, $this->messageCalls() );
		$this->assertSame( array(), array_values( array_filter( $this->forumRows( 'reply' ), static fn ( $r ) => 'attention' === $r['state'] ) ) );
	}

	/**
	 * What it checks: Every way a reply gets published sends it once; a held or spam reply waits for the moderator.
	 * Passes when: for each way below Ada's reply makes no call while held or spam and exactly one after it is published.
	 * Fails when: replies written in wp-admin, approved or rescued from spam are lost, or one reply arrives twice.
	 *
	 * @dataProvider waysToPublish
	 *
	 * @param string        $how     `front` or `admin`.
	 * @param string        $status  The reply's first status.
	 * @param callable|null $release What lets it through.
	 */
	public function test_each_way_to_publish_a_reply_sends_it_once( string $how, string $status, ?callable $release ): void {
		$topic = $this->filedTopic();
		$this->calls = array();
		$reply = $this->reply( $topic, $this->ada, 'A follow-up.', $how, $status );
		$this->workOutbox();
		if ( null !== $release ) {
			$this->assertCount( 0, $this->messageCalls(), 'nothing while held or spam' );
			$release( $reply );
		}

		$this->sendEverything();

		$this->assertCount( 1, $this->messageCalls( self::FIRST_NUMBER ) );
	}

	/** The ways bbPress publishes a reply. */
	public static function waysToPublish(): array {
		return array(
			'bbPress\'s front-end form'       => array( 'front', 'publish', null ),
			'wp-admin\'s editor'              => array( 'admin', 'publish', null ),
			'held, then approved'             => array( 'front', 'pending', static fn ( int $id ) => bbp_approve_reply( $id ) ),
			'caught as spam, then marked ham' => array( 'front', 'spam', static fn ( int $id ) => bbp_unspam_reply( $id ) ),
		);
	}

	/**
	 * What it checks: A reply written while its topic still waits for its ticket (OmnaBase's daily limit) is kept and goes right after the
	 *   topic, to the new ticket.
	 * Passes when: the topic is refused with 429 and Ada replies; no message call is made and the reply's row waits; once OmnaBase takes the
	 *   topic, the reply goes to its new ticket number after the topic's call.
	 * Fails when: the customer's follow-up is dropped because its ticket did not exist yet, or it is sent to no ticket at all.
	 */
	public function test_a_reply_waits_for_its_topics_ticket(): void {
		$this->answers['POST /api/public/v1/tickets'] = self::answerWith( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'Today\'s allowance is used', 'details' => array( 'reason' => 'api_tickets' ) ) ), array( 'retry-after' => '3600' ) );
		$topic = $this->topic( $this->publicForum, $this->ada );
		$this->workOutbox();
		$reply = $this->reply( $topic, $this->ada, 'Any news?' );
		$this->workOutbox();
		$this->assertCount( 0, $this->messageCalls() );
		$this->assertSame( array( 'waiting' ), array_column( $this->forumRows( 'reply', $reply ), 'state' ) );

		$this->omnabaseFilesTopics();
		$this->sendEverything();

		$this->assertCount( 1, $this->messageCalls( self::FIRST_NUMBER ) );
		$order = array_map( static fn ( $c ) => (string) wp_parse_url( $c['url'], PHP_URL_PATH ), array_values( array_filter( $this->calls, static fn ( $c ) => 'POST' === $c['method'] ) ) );
		$message = array_search( '/api/public/v1/tickets/' . self::FIRST_NUMBER . '/messages', $order, true );
		$this->assertNotFalse( $message );
		$this->assertLessThan( $message, max( array_keys( $order, '/api/public/v1/tickets', true ) ), 'the topic went before its reply' );
	}

	/**
	 * What it checks: Edits and deletions on the forum are not copied to OmnaBase in this version (the screen says so).
	 * Passes when: after Ada's reply was sent, editing it and then trashing it make no further call to OmnaBase.
	 * Fails when: a typo fix posts the whole reply a second time on the ticket.
	 */
	public function test_edits_and_deletions_are_not_synced(): void {
		$topic = $this->filedTopic();
		$reply = $this->reply( $topic, $this->ada, 'First version.' );
		$this->workOutbox();
		$this->calls = array();

		wp_update_post( array( 'ID' => $reply, 'post_content' => 'Second version.' ) );
		wp_update_post( array( 'ID' => $topic, 'post_content' => 'The topic, edited.' ) );
		wp_trash_post( $reply );
		$this->sendEverything();

		$this->assertSame( array(), array_values( array_filter( $this->calls, static fn ( $c ) => 'GET' !== $c['method'] ) ) );
	}

	/**
	 * What it checks: Replies in a forum the administrator did not tick stay on the forum.
	 * Passes when: Ada's reply on her topic in the unmarked forum queues no reply row and makes no call.
	 * Fails when: a forum the site owner kept out still feeds the inbox through its replies.
	 */
	public function test_replies_in_an_unmarked_forum_send_nothing(): void {
		$topic = $this->topic( $this->unmarkedForum, $this->ada );
		$reply = $this->reply( $topic, $this->ada, 'Chatter.' );

		$this->sendEverything();

		$this->assertSame( array(), $this->forumRows( 'reply', $reply ) );
		$this->assertCount( 0, $this->messageCalls() );
	}
}
