<?php
/**
 * The "support user replies" round (2026-11-04), the red team's failing tests: a post the support user writes by hand on the forum reaches
 * the topic's ticket, and OmnaBase's own answers still never come back.
 *
 * Brief (OmnaBase `docs/briefs/support-user-replies-round.md`) part one: "`whyNot()` skips a reply only when it carries the mark. A reply by
 * the support user without the mark is a moderator's answer … and goes as `from: team` with `siteAuthor` naming the support user's own
 * display name, address and role, like any moderator's"; "The hook that queues replies (`Triggers::reply`) applies the same rule"; "Tests …:
 * a reply by the support user without the mark is sent as the team's answer under their name; a reply with the mark is still skipped
 * whoever its author is; a customer's and a stranger's reply are unchanged." Report `docs/reviews/support-user-replies-round-red-team-report.md`
 * in the OmnaBase repository, findings 1, 3 and 4. Ticket 253: Günter (kriesi.at's support user) wrote further remarks on the forum after
 * the founder's answer from OmnaBase had been posted under his account; the plugin never sent them.
 *
 * The words: *topic* and *reply*, bbPress's forum thread and its answers; *support user*, the one forum moderator chosen under Settings ›
 * OmnaBase › Forums, under whose account OmnaBase's answers are posted when no team member has a moderator account with their own email
 * address; *the mark*, the post meta (a named value WordPress keeps beside a post) `_omnabase_message_id` the plugin writes on every reply
 * it posts for OmnaBase; *the posted-as sign*, the post meta `_omnabase_posted_as` written on the same replies; *bbPress's moments*, the
 * hooks (named moments where a plugin's code runs) for a new, approved or rescued-from-spam reply.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The support user's reply without the mark: `{ from: "team", delivered: true, siteAuthor: { name: "Kriesi Support", email:
 *   "support@shop.example.org", role: "Moderator" }, body, external: { id: "reply-<id>" } }`, whichever way bbPress published it.
 * - (chosen, finding 4) The plugin's own reply carries an OmnaBase sign from the moment bbPress publishes it (today the posted-as sign and
 *   the mark are both written after the save, while the plugin's "posting right now" switch is the only thing holding the hook back), and a
 *   reply carrying the posted-as sign is OmnaBase's even without its message id.
 * - Tests marked "(guard)" pass today and must keep passing after the change.
 *
 * Why these fail today: the hook drops every reply by the support user before it is queued, and the reply job would skip it too; the
 * posted-as sign is written after bbPress's publish moment and never read by the reply job.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumSupportUserRepliesTest extends RoundThreeTestCase {

	private const POSTED_AS = '_omnabase_posted_as';

	/** @var array<int,array{message:string,posted_as:string}> what a reply carried at the moment it was published */
	private array $atPublish = array();

	/**
	 * What it checks: A post the support user writes on the forum reaches the ticket as the team's answer under the support user's own
	 *   name, whichever way it was published (ticket 253 lost Günter's remarks this way).
	 * Passes when: the support user's reply, written through bbPress's form, in wp-admin, or held and then approved, makes exactly one call
	 *   to the ticket's messages: from the team, already delivered, siteAuthor "Kriesi Support" with the support user's address and the role
	 *   "Moderator", the text, the site's reference "reply-<id>" and an Idempotency-Key (a token that makes a retried call count once).
	 * Fails when: the support user's hand-written answers stay on the forum while the ticket reads as if the team never said them, or one
	 *   answer arrives twice.
	 *
	 * @dataProvider waysToPublish
	 *
	 * @param string        $how     `front` or `admin`.
	 * @param string        $status  The reply's first status.
	 * @param callable|null $release What lets it through.
	 */
	public function test_the_support_users_own_reply_reaches_the_ticket_as_the_teams_answer( string $how, string $status, ?callable $release ): void {
		$topic       = $this->filedTopic();
		$this->calls = array();

		$reply = $this->reply( $topic, $this->support, '<p>One more thing: clear the <strong>Enfold</strong> cache too.</p>', $how, $status );
		if ( null !== $release ) {
			$this->workOutbox();
			$this->assertCount( 0, $this->messageCalls(), 'nothing while held' );
			$release( $reply );
		}
		$this->sendEverything();

		$calls = $this->messageCalls( self::FIRST_NUMBER );
		$this->assertCount( 1, $calls, 'one call for the support user\'s reply' );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'team', $body['from'] ?? null );
		$this->assertTrue( $body['delivered'] ?? null );
		$this->assertSame( array( 'name' => 'Kriesi Support', 'email' => 'support@shop.example.org', 'role' => 'Moderator' ), $body['siteAuthor'] ?? null );
		$this->assertStringContainsString( 'One more thing: clear the Enfold cache too.', (string) ( $body['body'] ?? '' ) );
		$this->assertSame( array( 'id' => 'reply-' . $reply ), $body['external'] ?? null );
		$this->assertNotEmpty( self::headerOf( $calls[0], 'Idempotency-Key' ) );
		foreach ( array( 'customer', 'member', 'participant', 'kind' ) as $absent ) {
			$this->assertArrayNotHasKey( $absent, $body, $absent );
		}
	}

	/** The ways bbPress publishes the support user's reply. */
	public static function waysToPublish(): array {
		return array(
			'bbPress\'s front-end form' => array( 'front', 'publish', null ),
			'wp-admin\'s editor'        => array( 'admin', 'publish', null ),
			'held, then approved'       => array( 'front', 'pending', static fn ( int $id ) => bbp_approve_reply( $id ) ),
		);
	}

	/**
	 * What it checks: Every voice on one topic reaches the ticket as itself once the support user's own posts count: the customer as the
	 *   customer, a stranger as another participant, the support user as the team.
	 * Passes when: Ada's (the topic's author's), Ben's (another member's) and the support user's replies make three calls: from the customer,
	 *   from "other" under "Ben Weber", and from the team under "Kriesi Support"; none of them is skipped and no row needs attention.
	 * Fails when: the new rule turns the customer's or a stranger's post into the team's, or still drops the support user's.
	 */
	public function test_the_customer_a_stranger_and_the_support_user_each_speak_as_themselves(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();
		$ada         = $this->reply( $topic, $this->ada, 'Thank you!' );
		$ben         = $this->reply( $topic, $this->ben, 'Same problem here.' );
		$support     = $this->reply( $topic, $this->support, 'Glad it works now.' );

		$this->sendEverything();

		$bodies = array();
		foreach ( $this->messageCalls( self::FIRST_NUMBER ) as $call ) {
			$body                                          = self::bodyOf( $call );
			$bodies[ (string) ( $body['external']['id'] ?? '' ) ] = $body;
		}
		$this->assertCount( 3, $bodies, 'three replies, three calls' );
		$this->assertSame( 'customer', $bodies[ 'reply-' . $ada ]['from'] ?? null );
		$this->assertSame( 'other', $bodies[ 'reply-' . $ben ]['from'] ?? null );
		$this->assertSame( 'Ben Weber', $bodies[ 'reply-' . $ben ]['participant']['name'] ?? null );
		$this->assertSame( 'team', $bodies[ 'reply-' . $support ]['from'] ?? null );
		$this->assertSame( 'Kriesi Support', $bodies[ 'reply-' . $support ]['siteAuthor']['name'] ?? null );
		$this->assertSame( array(), array_values( array_filter( $this->forumRows( 'reply' ), static fn ( $r ) => 'attention' === $r['state'] ) ) );
	}

	/**
	 * What it checks: (guard) A reply carrying OmnaBase's mark is never sent, whoever it is posted under.
	 * Passes when: replies by the support user, by Nina (a moderator), by Ada (the topic's author) and by Ben, each given OmnaBase's message
	 *   id right after the save (as the plugin's own posting does), make no call; their rows, if any, are given up as "Not sent: the reply came
	 *   from OmnaBase."
	 * Fails when: the team's answer appears twice on the ticket, the second time as the forum's.
	 */
	public function test_a_marked_reply_is_never_sent_whoever_wrote_it(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();
		foreach ( array( $this->support, $this->moderator, $this->ada, $this->ben ) as $i => $author ) {
			$reply = $this->reply( $topic, $author, 'Posted from OmnaBase.' );
			update_post_meta( $reply, self::MESSAGE_META, 'msg-marked-' . $i );
		}

		$this->sendEverything();

		$this->assertCount( 0, $this->messageCalls() );
		foreach ( $this->forumRows( 'reply' ) as $row ) {
			$this->assertSame( 'discarded', $row['state'] );
			$this->assertSame( 'Not sent: the reply came from OmnaBase.', (string) $row['last_error'] );
		}
	}

	/**
	 * What it checks: (guard) OmnaBase's answer posted under the support user never travels back to OmnaBase: not when it is posted, and not
	 *   when bbPress publishes it again later (a moderator approves it, or rescues it from spam).
	 * Passes when: after a team reply is posted under the support user, after bbPress's "approved" and "not spam" moments fire for it, and
	 *   after the outbox runs, no reply row waits and no message call was made.
	 * Fails when: once the support user's posts count, every answer the team sends from OmnaBase comes back to its own ticket as the forum's.
	 */
	public function test_omnabases_answer_under_the_support_user_never_comes_back(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();

		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-sur-1', $this->teamReply( $topic, 'msg-sur-1' ) )->get_status() );
		$reply = self::repliesOn( $topic )[0];
		$this->assertSame( $this->support, (int) $reply->post_author, 'posted under the support user' );
		do_action( 'bbp_approved_reply', $reply->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bbPress's own moment, fired as bbPress fires it
		do_action( 'bbp_unspammed_reply', $reply->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bbPress's own moment, fired as bbPress fires it
		$this->sendEverything();

		$this->assertSame( array(), array_values( array_filter( $this->forumRows( 'reply' ), static fn ( $r ) => 'waiting' === $r['state'] ) ) );
		$this->assertCount( 0, $this->messageCalls() );
	}

	/**
	 * What it checks: (guard) When the team member who answered in OmnaBase is the support user (their OmnaBase address is the support user's),
	 *   the answer posted under that account still never comes back.
	 * Passes when: a team reply naming the support user's address is posted under the support user with OmnaBase's message id, and after
	 *   bbPress's "approved" moment and the outbox, no message call was made.
	 * Fails when: on a site like kriesi.at, where the support user is a real team member, every answer that member sends from OmnaBase lands
	 *   on the ticket twice.
	 */
	public function test_an_answer_by_the_member_who_is_the_support_user_never_comes_back(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();
		$data        = $this->teamReply( $topic, 'msg-sur-2', 'Kriesi Support' );
		$data['message']['author']['email'] = 'support@shop.example.org';

		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-sur-2', $data )->get_status() );
		$reply = self::repliesOn( $topic )[0];
		$this->assertSame( $this->support, (int) $reply->post_author );
		$this->assertSame( 'msg-sur-2', (string) get_post_meta( $reply->ID, self::MESSAGE_META, true ) );
		do_action( 'bbp_approved_reply', $reply->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bbPress's own moment, fired as bbPress fires it
		$this->sendEverything();

		$this->assertCount( 0, $this->messageCalls() );
	}

	/**
	 * What it checks: (chosen, finding 4) The plugin's own reply already carries an OmnaBase sign at the very moment bbPress publishes it, so
	 *   no listener at that moment, the plugin's own hook included, can take it for a forum member's post.
	 * Passes when: while a team reply is posted (once under the support user, once under Nina's own account), the reply read at WordPress's
	 *   "status changed to published" moment already has its posted-as sign ("support", then "member").
	 * Fails when: the only protection at that moment is the plugin's "posting right now" switch, so a clean-up that drops it, or a second
	 *   plugin that re-publishes the reply inside that moment, sends the team's answer back to its own ticket.
	 */
	public function test_the_plugins_reply_carries_its_sign_when_it_is_published(): void {
		$topic           = $this->filedTopic();
		$this->atPublish = array();
		add_action( 'transition_post_status', array( $this, 'notePublish' ), 1, 3 );

		$this->deliverEvent( 'message.sent', 'dlv-sur-3', $this->teamReply( $topic, 'msg-sur-3' ) );
		$nina = $this->teamReply( $topic, 'msg-sur-4', 'Nina' );
		$nina['message']['author']['email'] = 'nina@shop.example.org';
		$this->deliverEvent( 'message.sent', 'dlv-sur-4', $nina );
		remove_action( 'transition_post_status', array( $this, 'notePublish' ), 1 );

		$this->assertCount( 2, $this->atPublish, 'two replies published' );
		$this->assertSame( array( 'support', 'member' ), array_values( array_column( $this->atPublish, 'posted_as' ) ), 'the sign is there when the reply is published' );
	}

	/**
	 * Notes what a reply carries when it moves into `publish`.
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old status.
	 * @param \WP_Post $post       The post.
	 */
	public function notePublish( $new_status, $old_status, $post ): void {
		if ( 'publish' === $new_status && 'publish' !== $old_status && $post instanceof \WP_Post && bbp_get_reply_post_type() === $post->post_type ) {
			$this->atPublish[ $post->ID ] = array(
				'message'   => (string) get_post_meta( $post->ID, self::MESSAGE_META, true ),
				'posted_as' => (string) get_post_meta( $post->ID, self::POSTED_AS, true ),
			);
		}
	}

	/**
	 * What it checks: (guard; holds today through the old support-user rule, after the change only through finding 4's sign) A reply the
	 *   plugin posted whose message id was never written (the posting stopped half-way) still never travels back when bbPress publishes it
	 *   again.
	 * Passes when: a team reply is posted under the support user, its message id is removed (as if the posting had stopped before it), bbPress's
	 *   "approved" moment fires and the outbox runs: no message call was made.
	 * Fails when: a hiccup during posting makes the team's answer reappear on the ticket as the support user's forum post.
	 */
	public function test_a_posted_reply_without_its_message_id_still_never_comes_back(): void {
		$topic       = $this->filedTopic();
		$this->calls = array();
		$this->deliverEvent( 'message.sent', 'dlv-sur-5', $this->teamReply( $topic, 'msg-sur-5' ) );
		$reply = self::repliesOn( $topic )[0];
		delete_post_meta( $reply->ID, self::MESSAGE_META );

		do_action( 'bbp_approved_reply', $reply->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- bbPress's own moment, fired as bbPress fires it
		$this->sendEverything();

		$this->assertCount( 0, $this->messageCalls() );
	}
}
