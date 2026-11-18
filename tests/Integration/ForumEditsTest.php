<?php
/**
 * The forum edits and pictures round (OmnaBase `docs/briefs/forum-edits-and-pictures-round.md`, Part A items 2, 7 and 9, Part B items 10,
 * 11, 13 and 14; OmnaBase `docs/reviews/forum-edits-and-pictures-round-red-team-report.md`, findings 1, 8, 16 to 24), the red team's failing
 * tests: an edited forum post reaches its ticket, and every post travels in two forms, its plain text and the post as the forum shows it.
 * Inside wp-env's tests WordPress with bbPress loaded (the group "forum"), OmnaBase faked at the HTTP layer.
 *
 * The words: *bbPress*, the forum plugin; a *hook*, a named moment where a plugin's code runs (`bbp_edit_topic` and `bbp_edit_reply` after an
 * edit in the forum, `post_updated` after any save, the admin's editor included); the *outbox*, the plugin's list of calls waiting to be
 * sent, one waiting row per post; the *idempotency key*, the row's own token that lets OmnaBase recognise a repeat of the same call; the *shown
 * form*, the post's HTML as a forum visitor reads it (paragraphs, links, pictures); the *revision log*, bbPress's line "This topic was
 * modified 3 minutes ago by …" under an edited post.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team; every name sits in this file):
 * - An edit queues one outbox row of a kind holding "edit" (`forum_edit`) per post; three quick edits leave one waiting row, and its call
 *   carries the words of the last one.
 * - The call is `PATCH /api/public/v1/tickets/{number}/messages/by-external/{topic-<id>|reply-<id>}` with `{ body, html?, fields? }`, sent
 *   without an idempotency key (finding 17: setting a text twice is harmless, and a key would meet `idempotency_mismatch` whenever the
 *   post changes between a lost answer and the retry).
 * - A save that changes neither the text nor the declared fields sends nothing; a post OmnaBase has not received yet sends no edit (its own
 *   waiting row carries the new words); OmnaBase's 404 drops the row (no "needs attention").
 * - The plugin's own writes never count as an edit; a reply OmnaBase posted is not followed when someone edits it in the forum (finding 8:
 *   the brief's question 1 default overturned for those replies).
 * - `POST /tickets` and `POST /tickets/{number}/messages` carry `html`, the shown form, without the revision log and never for a post behind
 *   a password; over 200 000 characters it is left out (finding 21: OmnaBase refuses the whole call above that).
 *
 * Why these fail today: edits queue nothing (the triggers' docblock: "Edits … queue nothing") and the senders send the plain text alone.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumEditsTest extends RoundThreeTestCase {

	private const SHOT = 'https://i.ibb.co/abc/shot-1.png';

	/** OmnaBase takes the edits of these posts (`topic-<id>`, `reply-<id>`): 200 with the message, edited now. */
	private function omnabaseTakesEdits( string ...$posts ): void {
		foreach ( $posts as $post ) {
			$this->answers[ 'PATCH /by-external/' . $post ] = self::answer( 200, array( 'id' => 'msg-' . $post, 'kind' => 'customer', 'editedAt' => gmdate( 'Y-m-d\TH:i:s.000\Z' ) ) );
		}
	}

	/** A front-end edit, as bbPress's edit form makes it: the save, then its hook. */
	private function editTopic( int $topic, string $content ): void {
		wp_update_post( array( 'ID' => $topic, 'post_content' => $content ) );
		do_action( 'bbp_edit_topic', $topic, (int) bbp_get_topic_forum_id( $topic ), array(), (int) get_post_field( 'post_author', $topic ), true );
	}

	/** A front-end edit of a reply. */
	private function editReply( int $reply, string $content ): void {
		$topic = (int) bbp_get_reply_topic_id( $reply );
		wp_update_post( array( 'ID' => $reply, 'post_content' => $content ) );
		do_action( 'bbp_edit_reply', $reply, $topic, (int) bbp_get_topic_forum_id( $topic ), array(), (int) get_post_field( 'post_author', $reply ), true, 0 );
	}

	/** The edit calls the plugin made, optionally about one post (`topic-<id>`, `reply-<id>`). */
	private function editCalls( string $post = '' ): array {
		return array_values( array_filter( $this->calls, static fn ( $c ) => 'PATCH' === $c['method'] && str_contains( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), '/messages/by-external/' . $post ) ) );
	}

	/**
	 * What it checks: Ticket 435: the customer edits their topic three times in a row to add the screenshots; the ticket gets the last words once.
	 * Passes when: the three edits leave one waiting outbox row for the topic; working the outbox makes exactly one call, a PATCH on the topic's
	 *   own id under its ticket number, carrying the last text, the address of the picture as a line of the text, and the shown form with the
	 *   picture in it; the call carries no idempotency key.
	 * Fails when: an edit never reaches OmnaBase (the ticket keeps the first text for good), or three edits make three calls.
	 */
	public function test_three_quick_edits_send_the_last_words_once(): void {
		$topic = $this->filedTopic();
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		$this->calls = array();
		$this->editTopic( $topic, 'Since 7.1 the language switcher is gone. Here the screenshots' );
		$this->editTopic( $topic, "Since 7.1 the language switcher is gone. Here the screenshots\n\n" . self::SHOT );
		$this->editTopic( $topic, "Since 7.1 the language switcher is gone. Here the screenshots\n\n<img src=\"" . self::SHOT . '" alt="shot" />' );
		$this->assertCount( 1, array_filter( $this->forumRows( 'edit', $topic ), static fn ( $r ) => 'waiting' === $r['state'] ), 'one waiting row for the topic' );

		$this->sendEverything( 2 );
		$calls = $this->editCalls( 'topic-' . $topic );
		$this->assertCount( 1, $calls, 'one call for three edits' );
		$this->assertStringEndsWith( '/api/public/v1/tickets/' . self::FIRST_NUMBER . '/messages/by-external/topic-' . $topic, (string) wp_parse_url( $calls[0]['url'], PHP_URL_PATH ) );
		$body = self::bodyOf( $calls[0] );
		$this->assertStringStartsWith( 'Since 7.1 the language switcher is gone. Here the screenshots', (string) $body['body'] );
		$this->assertMatchesRegularExpression( '#(^|\n)' . preg_quote( self::SHOT, '#' ) . '(\n|$)#', (string) $body['body'], 'the picture\'s address is a line of the text' );
		$this->assertMatchesRegularExpression( '#<img [^>]*src="' . preg_quote( self::SHOT, '#' ) . '"#', (string) ( $body['html'] ?? '' ), 'the shown form holds the picture' );
		$this->assertNull( self::headerOf( $calls[0], 'Idempotency-Key' ), 'an edit carries no idempotency key' );
	}

	/**
	 * What it checks: An edit made in the WordPress admin's editor travels like one made in the forum.
	 * Passes when: saving the topic with new words in the admin (only WordPress's own "post updated" moment fires) leads to one PATCH with them.
	 * Fails when: a moderator's correction in wp-admin never reaches the ticket.
	 */
	public function test_an_admin_edit_travels(): void {
		$topic = $this->filedTopic();
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		$this->calls = array();
		wp_update_post( array( 'ID' => $topic, 'post_content' => 'Corrected in the admin.' ) );
		$this->sendEverything( 2 );
		$calls = $this->editCalls( 'topic-' . $topic );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'Corrected in the admin.', self::bodyOf( $calls[0] )['body'] );
	}

	/**
	 * What it checks: (guard) A save that changes nothing sends nothing.
	 * Passes when: the topic is saved again with the very words it has (a moderator opening and saving it, a status change) and no PATCH goes.
	 * Fails when: every save of a post costs a call and could write "edited" on a message nobody changed.
	 */
	public function test_a_save_without_a_change_sends_nothing(): void {
		$topic = $this->filedTopic();
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		$this->calls = array();
		$this->editTopic( $topic, (string) get_post_field( 'post_content', $topic ) );
		$this->sendEverything( 2 );
		$this->assertSame( array(), $this->editCalls(), 'no edit call' );
	}

	/**
	 * What it checks: A customer's reply that OmnaBase already has follows its edit, under its own id.
	 * Passes when: the reply is sent, then edited in the forum; one PATCH goes to `reply-<id>` on the topic's ticket with the new words.
	 * Fails when: only topics follow an edit and the customer's later corrections stay invisible.
	 */
	public function test_a_reply_edit_travels(): void {
		$topic = $this->filedTopic();
		$reply = $this->reply( $topic, $this->ada, 'Still broken.' );
		$this->sendEverything( 2 );
		$this->omnabaseTakesEdits( 'reply-' . $reply );
		$this->calls = array();
		$this->editReply( $reply, 'Still broken after clearing the cache.' );
		$this->sendEverything( 2 );
		$calls = $this->editCalls( 'reply-' . $reply );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'Still broken after clearing the cache.', self::bodyOf( $calls[0] )['body'] );
	}

	/**
	 * What it checks: (guard) An edit made while the post itself still waits to go sends no edit: the waiting post goes with the new words.
	 * Passes when: a topic is edited before its row is worked; working the outbox files it once with the edited words and makes no PATCH.
	 * Fails when: the edit races the first send (an edit of a post OmnaBase does not know yet, refused and lost), or the first text is filed.
	 */
	public function test_an_edit_before_the_first_send_rides_with_it(): void {
		$topic = $this->topic( $this->publicForum, $this->ada );
		$this->editTopic( $topic, 'Edited before it went.' );
		$this->sendEverything( 3 );
		$this->assertCount( 1, $this->ticketCalls() );
		$this->assertSame( 'Edited before it went.', self::bodyOf( $this->ticketCalls()[0] )['message'] );
		$this->assertSame( array(), $this->editCalls(), 'no edit of a post OmnaBase does not have' );
	}

	/**
	 * What it checks: OmnaBase answering "not found" for an edit (a post it never filed) drops the call quietly.
	 * Passes when: the PATCH is answered 404 and the edit's row ends neither waiting nor needing the administrator's attention.
	 * Fails when: the Activity tab fills with "needs attention" rows for posts OmnaBase never had.
	 */
	public function test_not_found_drops_the_edit(): void {
		$topic = $this->filedTopic();
		$this->answers[ 'PATCH /by-external/topic-' . $topic ] = self::answer( 404, array( 'error' => array( 'code' => 'not_found', 'message' => 'No such message' ) ) );
		$this->editTopic( $topic, 'Edited, but OmnaBase lost the ticket.' );
		$this->sendEverything( 2 );
		$this->assertCount( 1, $this->editCalls( 'topic-' . $topic ) );
		foreach ( $this->forumRows( 'edit', $topic ) as $row ) {
			$this->assertNotContains( $row['state'], array( 'waiting', 'attention' ), 'dropped, not waiting or needing attention' );
		}
	}

	/**
	 * What it checks: (guard) The plugin's own writes into a reply it posted from OmnaBase never count as an edit, and neither does a forum
	 *   edit of such a reply (the ticket keeps what the team wrote in OmnaBase).
	 * Passes when: a team reply is posted through OmnaBase's webhook, then the plugin-like update of its content (as the late pictures job does)
	 *   and a moderator's forum edit of it both leave no edit call.
	 * Fails when: the plugin echoes its own picture update back as an "edit" of the team's reply, or the forum's version of a reply (with its
	 *   closing line and the forum's own picture copies) replaces the team's message.
	 */
	public function test_the_plugins_own_reply_is_never_an_edit(): void {
		$topic = $this->filedTopic();
		$this->deliverEvent( 'message.sent', 'dlv-edits-1', $this->teamReply( $topic, 'msg-edits-1', 'Ada', '<p>Please update to 2.1.</p>', 'Please update to 2.1.' ) );
		$posted = self::repliesOn( $topic )[0];
		$this->omnabaseTakesEdits( 'topic-' . $topic, 'reply-' . $posted->ID );
		$this->calls = array();
		wp_update_post( array( 'ID' => $posted->ID, 'post_content' => $posted->post_content . "\n\n<img src=\"https://shop.example.org/wp-content/uploads/shot.png\" />" ) );
		$this->editReply( $posted->ID, 'Please update to 2.1 and clear the cache.' );
		$this->sendEverything( 2 );
		$this->assertSame( array(), $this->editCalls(), 'no edit call for OmnaBase\'s own reply' );
	}

	/**
	 * What it checks: A new topic travels in both forms: its plain text, with each picture's address as a line, and the post as the forum shows
	 *   it.
	 * Passes when: a topic holding a picture is filed with a `message` whose lines include the picture's address and an `html` holding the
	 *   picture tag with that address.
	 * Fails when: a picture in a post vanishes from the ticket without a trace, as it does today.
	 */
	public function test_a_topic_travels_with_its_shown_form(): void {
		$this->topic( $this->publicForum, $this->ada, 'Menu gone', "The menu is gone, see:\n\n<img src=\"" . self::SHOT . '" alt="menu" />' );
		$this->sendEverything( 2 );
		$body = self::bodyOf( $this->ticketCalls()[0] );
		$this->assertMatchesRegularExpression( '#(^|\n)' . preg_quote( self::SHOT, '#' ) . '(\n|$)#', (string) $body['message'] );
		$this->assertMatchesRegularExpression( '#<img [^>]*src="' . preg_quote( self::SHOT, '#' ) . '"#', (string) ( $body['html'] ?? '' ) );
	}

	/**
	 * What it checks: A reply travels in both forms too (the reply call takes the formatted version, as the new-ticket call does).
	 * Passes when: a customer's reply holding a picture is sent with `html` holding the picture tag.
	 * Fails when: replies stay plain text while topics show pictures.
	 */
	public function test_a_reply_travels_with_its_shown_form(): void {
		$topic = $this->filedTopic();
		$this->reply( $topic, $this->ada, "Here it is:\n\n<img src=\"" . self::SHOT . '" alt="shot" />' );
		$this->sendEverything( 2 );
		$body = self::bodyOf( $this->messageCalls( self::FIRST_NUMBER )[0] );
		$this->assertMatchesRegularExpression( '#<img [^>]*src="' . preg_quote( self::SHOT, '#' ) . '"#', (string) ( $body['html'] ?? '' ) );
	}

	/**
	 * What it checks: (guard) A topic behind a password sends no shown form (the forum would show a password box, not the post).
	 * Passes when: a topic with a post password is filed with its plain text and without `html`.
	 * Fails when: the ticket shows "This content is password protected" in place of the customer's words.
	 */
	public function test_a_password_topic_sends_no_shown_form(): void {
		$topic = $this->topic( $this->publicForum, $this->ada, 'Private', 'My site is at example.org.', 'admin' );
		wp_update_post( array( 'ID' => $topic, 'post_password' => 'secret' ) ); // before its row is worked: the sender reads the post at its turn
		$this->sendEverything( 2 );
		$body = self::bodyOf( $this->ticketCalls()[0] );
		$this->assertSame( 'My site is at example.org.', $body['message'] );
		$this->assertArrayNotHasKey( 'html', $body );
	}

	/**
	 * What it checks: The shown form leaves out bbPress's "This topic was modified … ago by …" line.
	 * Passes when: an edited topic with a revision log entry (written the way bbPress writes it) sends an edit whose `html` holds the new
	 *   words and not the "modified" line. (Not confirmed on the test WordPress that bbPress draws the log there: the red team's one run read
	 *   an empty forum view; the builder checks the log really shows before trusting a pass.)
	 * Fails when: every edited post shows "modified 3 minutes ago" in the ticket, frozen at the moment it was sent.
	 */
	public function test_the_revision_log_stays_out(): void {
		$topic = $this->filedTopic();
		$this->omnabaseTakesEdits( 'topic-' . $topic );
		$this->calls = array();
		$this->editTopic( $topic, 'Added the screenshots.' );
		$revisions = wp_get_post_revisions( $topic );
		if ( $revisions ) {
			bbp_update_topic_revision_log( array( 'topic_id' => $topic, 'revision_id' => (int) array_key_first( $revisions ), 'author_id' => $this->ada, 'reason' => 'screenshots' ) );
		}
		$this->sendEverything( 2 );
		$calls = $this->editCalls( 'topic-' . $topic );
		$this->assertCount( 1, $calls );
		$html = (string) ( self::bodyOf( $calls[0] )['html'] ?? '' );
		$this->assertStringContainsString( 'Added the screenshots.', $html, 'the shown form carries the new words' );
		$this->assertStringNotContainsString( 'modified', $html, 'without the revision log' );
	}

	/**
	 * What it checks: (guard) A post whose shown form is larger than OmnaBase takes still files, with its plain text alone.
	 * Passes when: a topic whose HTML is over 200 000 characters is filed with `message` and without `html`.
	 * Fails when: OmnaBase refuses the whole call for the HTML's size and the topic waits in the outbox for ever.
	 */
	public function test_a_huge_shown_form_is_left_out(): void {
		$this->topic( $this->publicForum, $this->ada, 'Log', 'The log: ' . str_repeat( '<b>x</b> ', 30000 ) );
		$this->sendEverything( 2 );
		$this->assertCount( 1, $this->ticketCalls() );
		$this->assertArrayNotHasKey( 'html', self::bodyOf( $this->ticketCalls()[0] ) );
	}
}
