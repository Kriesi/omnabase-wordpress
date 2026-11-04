<?php
/**
 * The "support user replies" round (2026-11-04), the red team's failing tests: a forum reply that never reached its ticket can be sent
 * again, once, by an administrator from the command line (WP-CLI). The wp-admin door to the same service is a question for the founder
 * (Q4, default: not this round); its tests are parked apart, in `optional-admin-path/`, and are not part of this round's failing set.
 *
 * Brief (OmnaBase `docs/briefs/support-user-replies-round.md`) part two: "A WP-CLI command `wp omnabase forum resend <reply id>` queues the
 * outbox row `forum_reply` for that reply again (the same row kind, the same idempotency rule: a reply already delivered answers 'already
 * sent, nothing to do') … It prints what it did in one line"; "Only replies in a marked forum whose topic has a ticket are accepted; anything
 * else prints why not, with the same words `whyNot()` uses"; Q2 "one reply id per call"; Q3 "A reply already delivered to OmnaBase is not
 * sent twice". Report `docs/reviews/support-user-replies-round-red-team-report.md` in the OmnaBase repository, findings 5, 6 and 7.
 *
 * The words: *outbox*, the plugin's own table of calls to OmnaBase still to make (a row waits, is sent, needs attention, or is given up);
 * *WP-CLI*, WordPress's command line on the server (on a network such as kriesi.at, run with `--url=kriesi.at/support`); *the mark*, the post
 * meta `_omnabase_message_id` on a reply the plugin posted for OmnaBase.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team; every name sits in `resend()` and the admin helpers
 * below):
 * - One service, `OmnaBase\WordPress\Forum\Resend`, built from the forum settings, the outbox's queue and its table, with
 *   `resend( int $reply_id ): array{ status: string, words: string }`. `status` is `queued` (a row now waits, due at once), `waiting` (a row
 *   for it already waited: it is made due now, no second row), `sent` (it already reached OmnaBase: nothing to do) or `refused` (not
 *   accepted; `words` says why). `words` is the one line the command prints; for `queued`, `waiting` and `sent` it names the ticket number.
 *   The command is a thin door to it (and a wp-admin door, if Q4 says yes).
 * - A row needing attention for the reply waits again with a fresh key (the outbox's own Retry), never beside a second row.
 * - (chosen, finding 6) "Already sent" is read from a mark the reply gets when OmnaBase accepted it, so it holds after the outbox forgets its
 *   sent rows (seven days).
 *
 * Why these fail today: there is no resend service and no command.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Forum\Settings;
use OmnaBase\WordPress\Outbox\Queue;
use OmnaBase\WordPress\Outbox\Schedule;
use OmnaBase\WordPress\Outbox\Table;

/**
 * @group forum
 */
final class ForumResendTest extends RoundThreeTestCase {

	/**
	 * Resends one reply through the plugin's service.
	 *
	 * @param int $reply The reply's id.
	 * @return array{status:string,words:string}
	 */
	private function resend( int $reply ): array {
		$class = 'OmnaBase\\WordPress\\Forum\\Resend';
		$this->assertTrue( class_exists( $class ), 'the resend service OmnaBase\\WordPress\\Forum\\Resend' );
		$table = new Table();
		$out   = ( new $class( new Settings(), new Queue( $table, new Schedule() ), $table ) )->resend( $reply );
		$this->assertIsArray( $out );
		$this->assertContains( $out['status'] ?? null, array( 'queued', 'waiting', 'sent', 'refused' ) );
		$this->assertIsString( $out['words'] ?? null );
		$this->assertNotSame( '', trim( $out['words'] ), 'one line of words' );
		$this->assertStringNotContainsString( "\n", $out['words'], 'one line' );
		return $out;
	}

	/** The open rows (waiting or needing attention) about a reply. */
	private function openRows( int $reply ): array {
		return array_values( array_filter( $this->forumRows( 'reply', $reply ), static fn ( $r ) => in_array( $r['state'], array( 'waiting', 'attention' ), true ) ) );
	}

	/**
	 * What it checks: A post that the old rule never queued (the support user's own remarks on ticket 253) reaches its ticket after one
	 *   resend, as the team's answer under the support user's name.
	 * Passes when: for the support user's reply with no outbox row, the resend answers "queued" naming the ticket number; one row waits,
	 *   due now; after the outbox runs, one call went to that ticket's messages from the team, delivered, siteAuthor "Kriesi Support",
	 *   with "reply-<id>" and an Idempotency-Key.
	 * Fails when: the founder installs the new version and still cannot get Günter's post onto ticket 253.
	 */
	public function test_a_reply_the_old_rule_skipped_reaches_its_ticket_after_a_resend(): void {
		$topic = $this->filedTopic();
		$reply = $this->reply( $topic, $this->support, 'Further remarks: the logo needs a width in the header settings.' );
		$this->clearOutbox(); // the old version never queued it
		$this->calls = array();

		$out = $this->resend( $reply );

		$this->assertSame( 'queued', $out['status'], $out['words'] );
		$this->assertStringContainsString( (string) self::FIRST_NUMBER, $out['words'], 'names the ticket' );
		$rows = $this->openRows( $reply );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'waiting', $rows[0]['state'] );
		$this->assertLessThanOrEqual( time(), self::nextAt( $rows[0] ), 'due now' );
		$this->workOutbox();
		$calls = $this->messageCalls( self::FIRST_NUMBER );
		$this->assertCount( 1, $calls );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( 'team', $body['from'] ?? null );
		$this->assertTrue( $body['delivered'] ?? null );
		$this->assertSame( 'Kriesi Support', $body['siteAuthor']['name'] ?? null );
		$this->assertSame( array( 'id' => 'reply-' . $reply ), $body['external'] ?? null );
		$this->assertNotEmpty( self::headerOf( $calls[0], 'Idempotency-Key' ) );
	}

	/**
	 * What it checks: A reply whose earlier row was given up (its forum was unticked when its turn came) can be sent once its forum is
	 *   ticked again; the given-up row does not stand in the way.
	 * Passes when: Ada's reply's row is given up while the forum is unticked; after ticking it again the resend answers "queued", a new row
	 *   waits, and the outbox makes one call to the ticket.
	 * Fails when: a reply that was skipped once can never reach its ticket.
	 */
	public function test_a_given_up_row_does_not_block_a_resend(): void {
		$topic = $this->filedTopic();
		$reply = $this->reply( $topic, $this->ada, 'Is there an update?' ); // queued while the forum is ticked
		$this->markForums( array( $this->privateForum ) );
		$this->sendEverything();
		$this->assertSame( array( 'discarded' ), array_column( $this->forumRows( 'reply', $reply ), 'state' ), 'given up while unticked' );
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ) );
		$this->calls = array();

		$this->assertSame( 'queued', $this->resend( $reply )['status'] );

		$this->assertCount( 1, $this->openRows( $reply ) );
		$this->sendEverything();
		$this->assertCount( 1, $this->messageCalls( self::FIRST_NUMBER ) );
	}

	/**
	 * What it checks: Resending a reply whose row still waits (OmnaBase did not answer yet) adds no second row; the waiting one goes now.
	 * Passes when: Ada's reply's first try met a 503 (so its row waits a minute); the resend answers "waiting"; still exactly one open row for
	 *   the reply, now due.
	 * Fails when: an impatient administrator's resend sends the same reply twice, or the answer pretends something new was queued.
	 */
	public function test_a_waiting_row_is_made_due_not_doubled(): void {
		$topic = $this->filedTopic();
		$this->answers['POST /messages'] = self::answer( 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'Try again' ) ) );
		$reply = $this->reply( $topic, $this->ada, 'Hello?' );
		$this->workOutbox();
		$rows = $this->openRows( $reply );
		$this->assertCount( 1, $rows );
		$this->assertGreaterThan( time(), self::nextAt( $rows[0] ), 'the row waits for its next try' );

		$out = $this->resend( $reply );

		$this->assertSame( 'waiting', $out['status'], $out['words'] );
		$this->assertStringContainsString( (string) self::FIRST_NUMBER, $out['words'] );
		$rows = $this->openRows( $reply );
		$this->assertCount( 1, $rows, 'no second row' );
		$this->assertLessThanOrEqual( time(), self::nextAt( $rows[0] ), 'due now' );
	}

	/**
	 * What it checks: Resending a reply whose row needs attention tries that row again (like the Activity tab's Retry), never beside a
	 *   second row.
	 * Passes when: Ada's reply met a 400 (its row needs attention); the resend answers "queued"; exactly one open row for the reply, waiting,
	 *   with a new idempotency key; once OmnaBase accepts, nothing for the reply is open any more.
	 * Fails when: the Activity tab keeps showing the old "needs attention" row after the reply went, or the reply is sent from two rows.
	 */
	public function test_a_row_needing_attention_is_retried_not_doubled(): void {
		$topic = $this->filedTopic();
		$this->answers['POST /messages'] = self::answer( 400, array( 'error' => array( 'code' => 'bad_request', 'message' => 'Body too long' ) ) );
		$reply = $this->reply( $topic, $this->ada, 'A long story.' );
		$this->workOutbox();
		$before = $this->openRows( $reply );
		$this->assertSame( array( 'attention' ), array_column( $before, 'state' ) );

		$this->assertSame( 'queued', $this->resend( $reply )['status'] );

		$rows = $this->openRows( $reply );
		$this->assertCount( 1, $rows, 'one open row' );
		$this->assertSame( 'waiting', $rows[0]['state'] );
		$this->assertNotSame( $before[0]['idempotency_key'], $rows[0]['idempotency_key'], 'a fresh key, as Retry gives' );
		$this->answers['POST /messages'] = self::answer( 201, array( 'id' => 'msg-ok', 'kind' => 'customer' ) );
		$this->sendEverything();
		$this->assertSame( array(), $this->openRows( $reply ) );
	}

	/**
	 * What it checks: A reply that already reached OmnaBase is not sent again, also after the outbox has forgotten it (seven days on).
	 * Passes when: after Ada's reply was accepted, the resend answers "sent" with "already sent" and the ticket number, adds no row and makes
	 *   no call; after the outbox's history is emptied, a second resend answers the same.
	 * Fails when: a resend after a week puts the same post on the ticket again, or the administrator is told it was queued when nothing goes.
	 */
	public function test_a_delivered_reply_is_already_sent(): void {
		$topic = $this->filedTopic();
		$reply = $this->reply( $topic, $this->ada, 'Thanks, all good.' );
		$this->sendEverything();
		$this->assertCount( 1, $this->messageCalls( self::FIRST_NUMBER ) );
		$rows        = count( $this->outboxRows() );
		$this->calls = array();

		$first = $this->resend( $reply );
		$this->assertSame( 'sent', $first['status'], $first['words'] );
		$this->assertMatchesRegularExpression( '/already sent/i', $first['words'] );
		$this->assertStringContainsString( (string) self::FIRST_NUMBER, $first['words'] );
		$this->assertCount( $rows, $this->outboxRows(), 'no new row' );

		$this->clearOutbox(); // seven days later the sent rows are purged
		$this->assertSame( 'sent', $this->resend( $reply )['status'], 'still known as sent' );
		$this->assertSame( array(), $this->outboxRows() );
		$this->sendEverything();
		$this->assertCount( 0, $this->messageCalls() );
	}

	/**
	 * What it checks: A reply that must not or cannot go is refused in the reply job's own words, and nothing is queued.
	 * Passes when: each case answers "refused" and leaves the outbox empty: OmnaBase's own posted reply ("Not sent: the reply came from
	 *   OmnaBase."), a reply in an unticked forum ("Not sent: its forum is no longer marked."), a trashed reply ("Not sent: the reply was
	 *   removed or unpublished before its turn."), a reply on a topic that is no ticket (words naming the ticket), a topic's id and an id
	 *   that is no post at all.
	 * Fails when: the command pushes OmnaBase's own answer back to its ticket, sends from a forum the owner unticked, or quietly does
	 *   nothing with an id that is not a reply.
	 */
	public function test_what_cannot_go_is_refused_in_the_jobs_own_words(): void {
		$topic   = $this->filedTopic();
		$marked  = $this->reply( $topic, $this->moderator, 'Posted from OmnaBase.' );
		$trashed = $this->reply( $topic, $this->ada, 'Oops, wrong topic.' );
		$open    = $this->reply( $topic, $this->ada, 'In a forum that gets unticked.' );
		update_post_meta( $marked, self::MESSAGE_META, 'msg-refused' );
		wp_trash_post( $trashed );
		$fresh = $this->topic( $this->publicForum, $this->ben, 'Never sent' );
		$this->clearOutbox(); // the fresh topic never became a ticket
		$unfiled = $this->reply( $fresh, $this->ben, 'Bump.' );
		$this->clearOutbox();

		$this->assertRefused( 'Not sent: the reply came from OmnaBase.', $this->resend( $marked ) );
		$this->assertRefused( 'Not sent: the reply was removed or unpublished before its turn.', $this->resend( $trashed ) );
		$unfiledOut = $this->resend( $unfiled );
		$this->assertSame( 'refused', $unfiledOut['status'] );
		$this->assertStringContainsString( 'ticket', $unfiledOut['words'] );
		$this->assertSame( 'refused', $this->resend( $topic )['status'], 'a topic is not a reply' );
		$this->assertSame( 'refused', $this->resend( 987654321 )['status'], 'no such post' );
		$this->markForums( array( $this->privateForum ) );
		$this->assertRefused( 'Not sent: its forum is no longer marked.', $this->resend( $open ) );

		$this->assertSame( array(), $this->outboxRows(), 'nothing queued' );
	}

	/**
	 * A refusal in these words.
	 *
	 * @param string                            $words The reply job's words.
	 * @param array{status:string,words:string} $out   What the resend answered.
	 */
	private function assertRefused( string $words, array $out ): void {
		$this->assertSame( 'refused', $out['status'], $out['words'] );
		$this->assertSame( $words, $out['words'] );
	}
}
