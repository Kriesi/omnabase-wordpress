<?php
/**
 * The "support user replies" round (2026-11-04), the red team's tests for the founder's correction: the post missing from ticket 253 is by
 * "guenni007", a plain forum member, and kriesi.at sent OmnaBase no call for it at all. Report
 * `docs/reviews/support-user-replies-round-red-team-report.md` in the OmnaBase repository, finding 0 (the top item).
 *
 * (guard) Every way a plain member's reply gets published in a ticked forum on a topic that is a ticket, today's code sends it once as
 *   another participant's post: bbPress's form, wp-admin, a held reply approved, a spam catch rescued, another plugin's plain insert (the way
 *   a REST call, an app or a reply-by-email plugin would save it), with guests switched off, by a member without an email address, and
 *   behind a row that needs attention. These pass today: they show the current code is not what lost the post (the build on kriesi.at
 *   predated 2026-11-03 13:59, when "another member's reply" was added: settled the same day by the coordinator over SSH, the installed
 *   files date from 2026-11-03 01:33 and have no such rule; until then such a reply was given up with "Not sent: only the topic's author
 *   and moderators speak on the ticket."). The fix for guenni007's post is installing the current zip, then the resend (ForumResendTest).
 *
 * The words: *topic* and *reply*, bbPress's forum thread and its answers; *plain member*, a forum user who is neither the topic's author nor
 * a moderator (bbPress's "Participant"); *ticked forum*, a forum whose topics become tickets; *held*, a reply bbPress keeps back for a
 * moderator (too many links, a first post); *spam catch*, a reply a spam filter (Akismet) set aside; *outbox*, the plugin's own table of
 * calls to OmnaBase still to make; *needs attention*, a row the outbox stopped trying until the administrator retries or discards it;
 * *given up*, a row the outbox discarded with a reason ("Not sent: …").
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumMissingReplyTest extends RoundThreeTestCase {

	/**
	 * What it checks: (guard) A plain member's reply on a customer's ticketed topic reaches the ticket once, as another participant's post,
	 *   however it was published and whatever the forum settings say about guests.
	 * Passes when: for each way below exactly one call goes to the topic's ticket, from "other", with the member's forum name and the site's
	 *   reference "reply-<id>", and nothing for the reply is left waiting or needing attention.
	 * Fails when: a helper's remarks on a customer's topic (guenni007's on ticket 253) stay on the forum while the customer thanks him on the
	 *   ticket for words the team cannot read.
	 *
	 * @dataProvider waysToPublish
	 *
	 * @param callable $publish Publishes the member's reply on the topic; answers its id.
	 * @param string   $name    The forum name the ticket should show.
	 */
	public function test_a_plain_members_reply_reaches_the_ticket_as_other( callable $publish, string $name ): void {
		$topic       = $this->filedTopic();
		$this->calls = array();

		$reply = (int) $publish( $this, $topic );
		$this->sendEverything();

		$calls = $this->messageCalls( self::FIRST_NUMBER );
		$this->assertCount( 1, $calls, 'one call for the member\'s reply' );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'other', $body['from'] ?? null );
		$this->assertSame( $name, $body['participant']['name'] ?? null );
		$this->assertSame( array( 'id' => 'reply-' . $reply ), $body['external'] ?? null );
		$open = array_filter( $this->forumRows( 'reply', $reply ), static fn ( $r ) => in_array( $r['state'], array( 'waiting', 'attention' ), true ) );
		$this->assertSame( array(), array_values( $open ) );
	}

	/** The ways a plain member's reply gets published (each closure gets the test and the topic, and answers the reply's id). */
	public static function waysToPublish(): array {
		return array(
			'bbPress\'s front-end form'              => array( static fn ( self $t, int $topic ) => $t->memberReply( $topic ), 'Ben Weber' ),
			'wp-admin\'s editor'                     => array( static fn ( self $t, int $topic ) => $t->memberReply( $topic, 'admin' ), 'Ben Weber' ),
			'held, then approved'                   => array( static fn ( self $t, int $topic ) => $t->releasedReply( $topic, 'pending', static fn ( int $id ) => bbp_approve_reply( $id ) ), 'Ben Weber' ),
			'caught as spam, then marked not spam'  => array( static fn ( self $t, int $topic ) => $t->releasedReply( $topic, 'spam', static fn ( int $id ) => bbp_unspam_reply( $id ) ), 'Ben Weber' ),
			'saved by another plugin (REST, app, mail)' => array( static fn ( self $t, int $topic ) => $t->insertedReply( $topic ), 'Ben Weber' ),
			'with guests switched off'              => array( static fn ( self $t, int $topic ) => $t->withoutGuests( $topic ), 'Ben Weber' ),
			'by a member without an email address'  => array( static fn ( self $t, int $topic ) => $t->byMemberWithoutEmail( $topic ), 'guenni007' ),
			'behind a row that needs attention'     => array( static fn ( self $t, int $topic ) => $t->behindAttention( $topic ), 'Ben Weber' ),
		);
	}

	/** Ben's reply, through bbPress's form (`front`) or wp-admin (`admin`). */
	public function memberReply( int $topic, string $how = 'front' ): int {
		return $this->reply( $topic, $this->ben, 'Additional remarks: give the logo a max-height in the header settings.', $how );
	}

	/** Ben's reply, first held or caught, then let through. */
	public function releasedReply( int $topic, string $status, callable $release ): int {
		$reply = $this->reply( $topic, $this->ben, 'Additional remarks, first held.', 'front', $status );
		$this->workOutbox();
		$this->assertCount( 0, $this->messageCalls(), 'nothing while held' );
		$release( $reply );
		return $reply;
	}

	/** Ben's reply saved by another plugin with WordPress's plain insert (no bbPress form, no bbPress moment). */
	public function insertedReply( int $topic ): int {
		$reply = (int) wp_insert_post( array( 'post_type' => bbp_get_reply_post_type(), 'post_parent' => $topic, 'post_author' => $this->ben, 'post_content' => 'Sent from the forum app.', 'post_status' => 'publish' ) );
		$this->assertGreaterThan( 0, $reply );
		return $reply;
	}

	/** Ben's reply with the forum setting "guests' topics" switched off. */
	public function withoutGuests( int $topic ): int {
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'guests' => false ) );
		return $this->memberReply( $topic );
	}

	/** A reply by a member whose account has no email address and no display name (only the login). */
	public function byMemberWithoutEmail( int $topic ): int {
		$user = (int) self::factory()->user->create( array( 'user_login' => 'guenni007', 'user_email' => '', 'display_name' => '', 'role' => 'subscriber' ) );
		bbp_set_user_role( $user, bbp_get_participant_role() );
		return $this->reply( $topic, $user, 'Additional remarks from a helper.' );
	}

	/** Ben's reply while another call in the outbox needs attention (and another waits for later). */
	public function behindAttention( int $topic ): int {
		$this->plantOutboxRow( true );
		$this->plantOutboxRow( false );
		$this->answers['PUT /customers/by-external'] = self::answer( 503, array() );
		return $this->memberReply( $topic );
	}
}
