<?php
/**
 * The forum edits and pictures round (OmnaBase `docs/briefs/forum-edits-and-pictures-round.md`; OmnaBase
 * `docs/reviews/forum-edits-and-pictures-round-red-team-report.md`, findings 16, 18, 19 and 22), the builder's own tests beside the red
 * team's `ForumEditsTest`: what the red team left for the builder to test.
 *
 * The words: *bbPress*, the forum plugin; the *outbox*, the plugin's list of calls waiting to be sent, one row per call; a *try*, one attempt
 * to make a row's call; the *idempotency key*, the row's token that lets OmnaBase recognise a repeat of the same call (and refuse it when the
 * body changed); the *shown form*, the post's HTML as a forum visitor reads it; the *revision log*, bbPress's line "This topic was modified …
 * ago by …" under an edited post; a *declared post field*, an extra box of a post (such as "Private data") that travels with its message.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumEditsBuilderTest extends RoundThreeTestCase {

	/** OmnaBase takes the edits of this post (`topic-<id>`, `reply-<id>`). */
	private function omnabaseTakesEdits( string $post ): void {
		$this->answers[ 'PATCH /by-external/' . $post ] = self::answer( 200, array( 'id' => 'msg-' . $post, 'kind' => 'customer', 'editedAt' => gmdate( 'Y-m-d\TH:i:s.000\Z' ) ) );
	}

	/** A front-end edit of a topic, as bbPress's edit form makes it: the save, then its moment. */
	private function editTopic( int $topic, string $content ): void {
		wp_update_post( array( 'ID' => $topic, 'post_content' => $content ) );
		do_action( 'bbp_edit_topic', $topic, (int) bbp_get_topic_forum_id( $topic ), array(), (int) get_post_field( 'post_author', $topic ), true );
	}

	/** The edit calls the plugin made about one post. */
	private function editCalls( string $post ): array {
		return array_values( array_filter( $this->calls, static fn ( $c ) => 'PATCH' === $c['method'] && str_ends_with( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), '/messages/by-external/' . $post ) ) );
	}

	/**
	 * What it checks: A new topic whose first try got no answer is sent again exactly as it was the first time, and an edit the customer made
	 *   in between still reaches the ticket afterwards.
	 * Passes when: the first try is lost (no answer), the customer edits the topic, and the second try carries the very same body and the same
	 *   idempotency key as the first; once OmnaBase has filed it, one edit call follows with the new words.
	 * Fails when: the second try carries a changed body (OmnaBase then refuses it as "the post changed" and the topic waits for the
	 *   administrator), or the customer's edit is lost because the plugin believes OmnaBase already has the new words.
	 */
	public function test_a_lost_answer_keeps_the_first_body_and_the_edit_follows(): void {
		$tries = 0;
		$this->answers['POST /api/public/v1/tickets'] = function ( $args ) use ( &$tries ) {
			++$tries;
			return 1 === $tries ? new \WP_Error( 'http_request_failed', 'Operation timed out' ) : self::answer( 201, $this->ticketAnswer( $this->nextNumber++, $args ) );
		};
		$topic = $this->topic( $this->publicForum, $this->ada, 'Menu gone', 'First words.' );
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		$this->sendEverything( 1 );
		$this->assertSame( '', (string) get_post_meta( $topic, self::TICKET_META, true ), 'the first try got no answer' );

		$this->editTopic( $topic, 'Second words, with the screenshots.' );
		$this->sendEverything( 3 );

		$sent = $this->ticketCalls();
		$this->assertCount( 2, $sent, 'two tries of the topic' );
		$this->assertSame( (string) $sent[0]['args']['body'], (string) $sent[1]['args']['body'], 'the retry carries the first try\'s body' );
		$this->assertSame( 'First words.', self::bodyOf( $sent[1] )['message'] );
		$this->assertSame( self::headerOf( $sent[0], 'Idempotency-Key' ), self::headerOf( $sent[1], 'Idempotency-Key' ) );
		$edits = $this->editCalls( 'topic-' . $topic );
		$this->assertCount( 1, $edits, 'the edit follows once the topic is filed' );
		$this->assertSame( 'Second words, with the screenshots.', self::bodyOf( $edits[0] )['body'] );
	}

	/**
	 * What it checks: bbPress's "This topic was modified … ago by …" line, which the forum really shows under an edited topic, stays out of
	 *   what reaches the ticket. (The red team's own test runs on an admin screen, where bbPress never draws the line; this one runs as a
	 *   forum visitor's page.)
	 * Passes when: on a visitor's page the forum's own display of the edited topic holds "modified", and the edit sent to OmnaBase holds the new
	 *   words without it.
	 * Fails when: every edited post shows "modified 3 minutes ago" in the ticket, frozen at the moment it was sent.
	 */
	public function test_the_revision_log_the_forum_shows_stays_out(): void {
		$topic = $this->filedTopic();
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		set_current_screen( 'front' );
		$this->assertFalse( is_admin(), 'a visitor\'s page' );
		$this->editTopic( $topic, 'Added the screenshots.' );
		$revisions = wp_get_post_revisions( $topic );
		$this->assertNotEmpty( $revisions, 'the edit made a revision' );
		bbp_update_topic_revision_log( array( 'topic_id' => $topic, 'revision_id' => (int) array_key_first( $revisions ), 'author_id' => $this->ada, 'reason' => 'screenshots' ) );
		$this->assertStringContainsString( 'This topic was modified', (string) bbp_get_topic_content( $topic ), 'the forum shows the line' );

		$this->sendEverything( 2 );
		$edits = $this->editCalls( 'topic-' . $topic );
		$this->assertCount( 1, $edits );
		$html = (string) ( self::bodyOf( $edits[0] )['html'] ?? '' );
		$this->assertStringContainsString( 'Added the screenshots.', $html );
		$this->assertStringNotContainsString( 'modified', $html );
		$this->assertStringContainsString( 'This topic was modified', (string) bbp_get_topic_content( $topic ), 'the forum still shows it afterwards' );
	}

	/**
	 * What it checks: Closing a topic, or editing and then trashing it, is no edit for the ticket.
	 * Passes when: closing a filed topic queues no edit at all, and a topic edited and then moved to the trash before its edit went sends no
	 *   edit call.
	 * Fails when: the plugin's own topic close (when the team resolves the ticket) or a moderator's clean-up writes "edited" on the ticket.
	 */
	public function test_a_close_or_a_trashing_sends_no_edit(): void {
		$closed = $this->filedTopic();
		bbp_close_topic( $closed );
		$this->assertSame( array(), $this->forumRows( 'edit', $closed ), 'a close is no edit' );

		$trashed = $this->filedTopic();
		$this->omnabaseTakesEdits( 'topic-' . $trashed );
		$this->editTopic( $trashed, 'Spam link here.' );
		wp_trash_post( $trashed );
		$this->sendEverything( 2 );
		$this->assertSame( array(), $this->editCalls( 'topic-' . $trashed ), 'a trashed topic sends nothing' );
	}

	/**
	 * What it checks: An edit carries the post's declared fields as they are now, and never another member's.
	 * Passes when: Ada empties the "Private data" field of her topic and edits its words: the edit carries no fields (so the ticket's copy is
	 *   emptied too); Ben, another member, edits his reply that has a "Private data" value: his edit carries no fields either.
	 * Fails when: a password a moderator removed from the field stays on the ticket, or another member's private data lands on the customer's
	 *   ticket.
	 */
	public function test_fields_follow_the_post_and_never_another_members(): void {
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'fields' => array( '_av_supp_private_info' => 'Private data' ) ) );
		$topic = $this->topic( $this->publicForum, $this->ada, 'Login', 'Please look.' );
		update_post_meta( $topic, '_av_supp_private_info', 'admin / hunter2' );
		$this->sendEverything( 2 );
		$this->assertNotEmpty( self::bodyOf( $this->ticketCalls()[0] )['fields'] ?? array(), 'the field travelled with the topic' );
		$reply = $this->reply( $topic, $this->ben, 'Same here.' );
		update_post_meta( $reply, '_av_supp_private_info', 'ben / secret' );
		$this->sendEverything( 2 );
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		$this->omnabaseTakesEdits( 'reply-' . $reply );
		$this->calls = array();

		delete_post_meta( $topic, '_av_supp_private_info' );
		$this->editTopic( $topic, 'Please look, the password is removed.' );
		wp_update_post( array( 'ID' => $reply, 'post_content' => 'Same here, still.' ) );
		$this->sendEverything( 2 );

		$topic_edit = $this->editCalls( 'topic-' . $topic );
		$reply_edit = $this->editCalls( 'reply-' . $reply );
		$this->assertCount( 1, $topic_edit );
		$this->assertArrayNotHasKey( 'fields', self::bodyOf( $topic_edit[0] ), 'an emptied field leaves the ticket' );
		$this->assertCount( 1, $reply_edit );
		$this->assertArrayNotHasKey( 'fields', self::bodyOf( $reply_edit[0] ), 'another member\'s fields never travel' );
	}

	/**
	 * What it checks: The team reads what the customer wrote, not what the site shows a logged-out visitor. A site's own display filters
	 *   (pieces of the site's code that change how a forum post is shown) stay out of the formatted version a ticket gets.
	 * Passes when: on a site whose own filter hides email addresses from logged-out visitors and turns a product name into a partner link,
	 *   an edited topic that names an address and that product reaches OmnaBase with the address readable and without the partner link,
	 *   in the plain text and in the formatted version; and a site filter that crashes does not stop the edit from travelling.
	 * Fails when: the support team reads "(Email address hidden if logged out)" where the customer gave their address, sees advertising
	 *   links inside customers' messages, or a faulty filter of the site silently keeps forum posts from becoming tickets.
	 */
	public function test_the_sites_own_display_filters_stay_out_of_the_ticket(): void {
		$topic = $this->filedTopic();
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		$hide  = static fn ( $content ) => preg_replace( '/[a-z0-9._-]+@[a-z0-9.-]+/i', '(Email address hidden if logged out)', (string) $content );
		$link  = static fn ( $content ) => str_replace( 'WooCommerce', '<a href="https://partner.example/ref">WooCommerce</a>', (string) $content );
		$crash = static function () {
			throw new \RuntimeException( 'a faulty filter of the site' );
		};
		add_filter( 'bbp_get_topic_content', $hide, 1 );
		add_filter( 'bbp_get_topic_content', $link, 20 );
		add_filter( 'bbp_get_topic_content', $crash, 99 );
		try {
			$this->editTopic( $topic, 'Write to ada@example.com about WooCommerce.' );
			$this->sendEverything( 2 );
		} finally {
			remove_filter( 'bbp_get_topic_content', $hide, 1 );
			remove_filter( 'bbp_get_topic_content', $link, 20 );
			remove_filter( 'bbp_get_topic_content', $crash, 99 );
		}

		$edits = $this->editCalls( 'topic-' . $topic );
		$this->assertCount( 1, $edits, 'the edit travelled' );
		$body = self::bodyOf( $edits[0] );
		$html = (string) ( $body['html'] ?? '' );
		$this->assertStringContainsString( 'ada@example.com', (string) $body['body'] );
		$this->assertStringContainsString( 'ada@example.com', $html, 'the address the customer wrote is readable' );
		$this->assertStringNotContainsString( 'hidden if logged out', $html );
		$this->assertStringNotContainsString( 'partner.example', $html, 'no partner link from the site' );
	}
}
