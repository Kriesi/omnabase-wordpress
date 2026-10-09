<?php
/**
 * M13 round three, the red team's failing tests: a topic in a marked forum becomes one public ticket on OmnaBase's `api` channel, through
 * the outbox, however it was made and however busy OmnaBase is.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 7: "**Every forum except hidden ones counts as public** (`public:
 * true` …); hidden forums send `public: false`"; "`bbp_new_topic` / `bbp_new_reply` (bbPress's front-end form), `bbp_approved_topic` …,
 * `bbp_unspammed_topic` …, and `transition_post_status` to `publish` for both post types (topics and replies made in wp-admin); each only
 * queues an outbox row (one per post), and the forum, status and author are checked again when the row is sent. A topic → `POST /tickets`
 * (project, the author's address and name or bbPress's guest name and address, title, first post, `external { id: "topic-<id>", url }`,
 * `replyVia: "site"`, `public`); the ticket number kept as topic meta. Guest topics forward by default (a switch …)"; choice 4: "the
 * idempotency key …; **It never drops a row**: a `429` waits exactly the `Retry-After` it names (the daily limit: until tomorrow) …"; "The
 * screen and health say '12 topics waiting: OmnaBase's daily limit for new tickets, next try tomorrow 00:00'"; round three's *Red team*: "a
 * topic in a private forum filed `public: true`, a hidden one `false`; a topic from the front form, from wp-admin, approved after holding and
 * un-spammed each makes exactly one ticket; with the e2e daily ceiling reached a topic waits …; four 503s still end in one ticket". Review
 * B1 (private forums are read by every logged-in customer) and B2 (50 site tickets a day in a workspace's first 14 days; never lose a
 * topic). `docs/public-api.md`: `POST /tickets { project, customer { email, name }, subject (≤200), message (≤50 000), external { id, url },
 * public, replyVia }`; "The same site sending the same `external.id` again answers 200 with the ticket it made"; `Idempotency-Key`; 429 with
 * `Retry-After` and `details.reason: "api_tickets"`; `409 conflict` for "a topic already filed into a project the key may not touch".
 *
 * Shapes the red team pins: those of `RoundThreeTestCase`. (chosen) A topic with no e-mail address (a guest who left none) is never sent and
 * needs the administrator's attention with the reason in words; (chosen) the coordinator's "409 duplicate" does not exist in the contract (a
 * repeat is a 200 with the ticket made): a 200 is success with that number, a 409 needs attention.
 *
 * Why these fail today: nothing listens to bbPress; there is no topic job, no forum option, no ticket meta, no idempotency key.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumTopicsTest extends RoundThreeTestCase {

	/**
	 * What it checks: A customer's topic in a marked forum becomes one ticket in OmnaBase carrying everything the team needs, and the site
	 *   remembers which ticket it became.
	 * Passes when: after the outbox runs, exactly one new-ticket call went out with the connection's project, Ada's address and name, the
	 *   topic's title and text, the site's own reference "topic-<id>" with the topic's address, replies going back to the site, marked public,
	 *   and the topic keeps the ticket number OmnaBase answered.
	 * Fails when: forum topics never reach the inbox, arrive without the customer or the link back, or the team's answer is mailed instead of
	 *   posted on the forum.
	 */
	public function test_a_topic_from_the_front_form_becomes_one_public_ticket(): void {
		$topic = $this->topic( $this->publicForum, $this->ada, 'The switcher is gone', 'Since 7.1 the language switcher is gone.' );

		$this->workOutbox();

		$calls = $this->ticketCalls();
		$this->assertCount( 1, $calls );
		$body = self::bodyOf( $calls[0] );
		$this->assertSame( self::PROJECT, $body['project'] ?? null );
		$this->assertSame( array( 'email' => 'ada@example.org', 'name' => 'Ada Lovelace' ), $body['customer'] ?? null );
		$this->assertSame( 'The switcher is gone', $body['subject'] ?? null );
		$this->assertStringContainsString( 'Since 7.1 the language switcher is gone.', (string) ( $body['message'] ?? '' ) );
		$this->assertSame( array( 'id' => 'topic-' . $topic, 'url' => (string) get_permalink( $topic ) ), $body['external'] ?? null );
		$this->assertSame( 'site', $body['replyVia'] ?? null );
		$this->assertTrue( $body['public'] ?? null );
		$this->assertContains( $body['channel'] ?? 'api', array( 'api' ), 'the channel, when named, is api' );
		$this->assertSame( (string) self::FIRST_NUMBER, (string) get_post_meta( $topic, self::TICKET_META, true ) );
	}

	/**
	 * What it checks: Every way a topic gets published makes exactly one ticket, never two, and a topic still held for moderation or caught as
	 *   spam makes none until a moderator lets it through.
	 * Passes when: for each way below, no ticket call is made while the topic is held or spam, and exactly one is made after it is published.
	 * Fails when: topics written in wp-admin, approved after holding or rescued from the spam filter never arrive, spam reaches the inbox, or one
	 *   topic becomes two tickets because two of WordPress's moments fired.
	 *
	 * @dataProvider waysToPublish
	 *
	 * @param callable $publish Makes the topic; answers [ topic id, a callable that releases it or null ].
	 */
	public function test_each_way_to_publish_makes_exactly_one_ticket( callable $publish ): void {
		[ $topic, $release ] = $publish( $this );
		$this->workOutbox();
		if ( null !== $release ) {
			$this->assertCount( 0, $this->ticketCalls(), 'nothing is sent while the topic is held or spam' );
			$release( $topic );
			$this->sendEverything();
		}

		$this->sendEverything();

		$this->assertCount( 1, $this->ticketCalls() );
		$this->assertSame( 'topic-' . $topic, self::bodyOf( $this->ticketCalls()[0] )['external']['id'] ?? null );
	}

	/** The ways bbPress publishes a topic. */
	public static function waysToPublish(): array {
		return array(
			'bbPress\'s front-end form'         => array( static fn ( self $t ) => array( $t->topic( $t->publicForum, $t->ada ), null ) ),
			'wp-admin\'s editor'                => array( static fn ( self $t ) => array( $t->topic( $t->publicForum, $t->ada, 'Made in wp-admin', 'Typed by an admin for Ada.', 'admin' ), null ) ),
			'held, then approved'               => array( static fn ( self $t ) => array( $t->topic( $t->publicForum, $t->ada, 'Held', 'Waiting for a moderator.', 'front', 'pending' ), static fn ( int $id ) => bbp_approve_topic( $id ) ) ),
			'caught as spam, then marked ham'   => array( static fn ( self $t ) => array( $t->topic( $t->publicForum, $t->ada, 'Not spam', 'Akismet was wrong.', 'front', 'spam' ), static fn ( int $id ) => bbp_unspam_topic( $id ) ) ),
		);
	}

	/**
	 * What it checks: Only topics in a public or private forum are marked public; a hidden forum's topic is not (review B1: every logged-in
	 *   customer reads a private forum).
	 * Passes when: a topic in the public forum and one in the private forum are sent with public true, one in the hidden forum with false.
	 * Fails when: OmnaBase puts a customer's orders and licences into an answer posted where every customer can read it, or treats a
	 *   moderators-only topic as public and keeps the customer's data from the team.
	 *
	 * @dataProvider visibilities
	 */
	public function test_every_forum_but_a_hidden_one_is_public( string $forum, bool $public ): void {
		$this->topic( $this->{$forum}, $this->ada );

		$this->workOutbox();

		$this->assertCount( 1, $this->ticketCalls() );
		$this->assertSame( $public, self::bodyOf( $this->ticketCalls()[0] )['public'] ?? null );
	}

	/** The three visibilities. */
	public static function visibilities(): array {
		return array(
			'public forum'  => array( 'publicForum', true ),
			'private forum' => array( 'privateForum', true ),
			'hidden forum'  => array( 'hiddenForum', false ),
		);
	}

	/**
	 * What it checks: A forum the administrator did not tick sends nothing, and a topic that went away before its turn is not sent.
	 * Passes when: a topic in the unmarked forum queues no topic row and makes no call; a topic trashed before the outbox ran makes no call.
	 * Fails when: every forum of the site floods the inbox, or a topic the moderator removed still becomes a ticket.
	 */
	public function test_an_unmarked_forum_and_a_trashed_topic_send_nothing(): void {
		$chatter = $this->topic( $this->unmarkedForum, $this->ada );
		$gone    = $this->topic( $this->publicForum, $this->ben );
		wp_trash_post( $gone );

		$this->sendEverything();

		$this->assertSame( array(), $this->forumRows( 'topic', $chatter ), 'no row for the unmarked forum' );
		$this->assertCount( 0, $this->ticketCalls() );
	}

	/**
	 * What it checks: The topic reaches OmnaBase as plain, readable text within OmnaBase's limits.
	 * Passes when: a topic whose text is HTML with a script and 60 000 characters arrives with its words and no tags or script, at most
	 *   50 000 characters; a 250-character title arrives as a subject of at most 200 characters.
	 * Fails when: OmnaBase refuses the topic as too long (it would wait for ever), or the team reads raw HTML and a script's text.
	 */
	public function test_the_topic_is_sent_as_plain_text_within_the_limits(): void {
		$content = '<p>Hello <strong>there</strong> &amp; welcome.</p><script>alert(1)</script>' . str_repeat( 'Lorem ipsum ', 5000 );
		$this->topic( $this->publicForum, $this->ada, str_repeat( 'Long title ', 25 ), $content );

		$this->workOutbox();

		$body = self::bodyOf( $this->ticketCalls()[0] ?? array() );
		$this->assertStringContainsString( 'Hello there & welcome.', (string) ( $body['message'] ?? '' ) );
		$this->assertStringNotContainsString( '<', (string) $body['message'] );
		$this->assertStringNotContainsString( 'alert(1)', (string) $body['message'] );
		$this->assertLessThanOrEqual( 50000, mb_strlen( (string) $body['message'] ) );
		$this->assertGreaterThan( 0, mb_strlen( (string) ( $body['subject'] ?? '' ) ) );
		$this->assertLessThanOrEqual( 200, mb_strlen( (string) $body['subject'] ) );
	}

	/**
	 * What it checks: A guest's topic is filed under the name and address the guest gave; a guest who gave no address is never sent and the
	 *   administrator is told; with the guest switch off guests' topics stay on the forum.
	 * Passes when: the guest's topic goes with "Gast Gustav" and gast@example.net; the one without an address makes no call and its row needs
	 *   attention with a reason naming the missing email address; with guests switched off a guest's topic makes no call.
	 * Fails when: guests' questions vanish without a trace, OmnaBase is sent a ticket it must refuse, or the switch does nothing.
	 */
	public function test_guests_are_filed_by_their_given_address_or_held_back(): void {
		$this->topic( $this->publicForum, 0, 'A guest asks', 'Does it work with WPML?', 'front', 'publish', array( 'name' => 'Gast Gustav', 'email' => 'gast@example.net' ) );
		$silent = $this->topic( $this->publicForum, 0, 'No address', 'Hello?', 'front', 'publish', array( 'name' => 'Nobody', 'email' => '' ) );

		$this->sendEverything();

		$this->assertCount( 1, $this->ticketCalls(), 'the guest with an address only' );
		$this->assertSame( array( 'email' => 'gast@example.net', 'name' => 'Gast Gustav' ), self::bodyOf( $this->ticketCalls()[0] )['customer'] ?? null );
		$rows = $this->forumRows( 'topic', $silent );
		$this->assertCount( 1, $rows, 'the topic without an address is kept, not dropped' );
		$this->assertSame( 'attention', $rows[0]['state'] );
		$this->assertMatchesRegularExpression( '/e-?mail/i', (string) $rows[0]['last_error'] );

		$this->calls = array();
		$this->markForums( array( $this->publicForum ), array( 'guests' => false ) );
		$this->topic( $this->publicForum, 0, 'Guests off', 'Hi.', 'front', 'publish', array( 'name' => 'Gast Gerda', 'email' => 'gerda@example.net' ) );
		$this->sendEverything();
		$this->assertCount( 0, $this->ticketCalls(), 'guests switched off' );
	}

	/**
	 * What it checks: A topic sent again after a lost answer counts once at OmnaBase: every try of one topic carries the same one-time id
	 *   (the idempotency key), and two topics carry different ones.
	 * Passes when: the first try of a topic gets a 503 and the second a 201; both calls carry the same non-empty Idempotency-Key of at most
	 *   200 characters; a second topic's key differs.
	 * Fails when: a network hiccup turns one forum topic into two tickets, or two topics are taken for one.
	 */
	public function test_every_try_of_a_topic_carries_the_same_idempotency_key(): void {
		$tries = 0;
		$this->answers['POST /api/public/v1/tickets'] = function ( $args ) use ( &$tries ) {
			return 1 === ++$tries ? self::answer( 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'Try again.' ) ) ) : self::answer( 201, $this->ticketAnswer( $this->nextNumber++, $args ) );
		};
		$this->topic( $this->publicForum, $this->ada );
		$this->workOutbox();
		$this->sendEverything( 1 );
		$this->topic( $this->publicForum, $this->ben, 'Another one', 'Another question.' );
		$this->workOutbox();

		$calls = $this->ticketCalls();
		$this->assertCount( 3, $calls );
		$first = self::headerOf( $calls[0], 'Idempotency-Key' );
		$this->assertNotEmpty( $first );
		$this->assertMatchesRegularExpression( '/^[\x21-\x7e]{1,200}$/', (string) $first );
		$this->assertSame( $first, self::headerOf( $calls[1], 'Idempotency-Key' ), 'the retry carries the first try\'s key' );
		$this->assertNotSame( $first, self::headerOf( $calls[2], 'Idempotency-Key' ), 'another topic, another key' );
	}

	/**
	 * What it checks: An OmnaBase outage never loses a topic (review B2): four failed tries still end in one ticket.
	 * Passes when: OmnaBase answers 503 four times, then 201; after the outbox's tries the topic has its ticket number, its row is sent, no row
	 *   needs attention, and exactly one try succeeded.
	 * Fails when: a customer's topic is dropped after a few failures during an update of OmnaBase.
	 */
	public function test_four_503s_still_end_in_one_ticket(): void {
		$tries = 0;
		$this->answers['POST /api/public/v1/tickets'] = function ( $args ) use ( &$tries ) {
			return ++$tries <= 4 ? self::answer( 503, array( 'error' => array( 'code' => 'unavailable', 'message' => 'Down for maintenance.' ) ) ) : self::answer( 201, $this->ticketAnswer( $this->nextNumber++, $args ) );
		};
		$topic = $this->topic( $this->publicForum, $this->ada );

		$this->sendEverything( 6 );

		$this->assertCount( 5, $this->ticketCalls() );
		$this->assertSame( (string) self::FIRST_NUMBER, (string) get_post_meta( $topic, self::TICKET_META, true ) );
		$this->assertSame( array( 'sent' ), array_column( $this->forumRows( 'topic', $topic ), 'state' ) );
	}

	/**
	 * What it checks: On a busy day OmnaBase's daily limit for new tickets holds topics back, and they wait until the next day instead of
	 *   being lost; the screen says how many wait and why (review B2: 50 site tickets a day in a new workspace).
	 * Passes when: of 60 topics OmnaBase takes 50 and answers the other 10 with 429 and a Retry-After of two hours; the 10 wait (none needs
	 *   attention, none was dropped) with their next try at least that far ahead; the outbox section says "10 topics waiting" and names the
	 *   daily limit; once the next day comes all 60 topics have their own ticket and no topic was filed twice.
	 * Fails when: the forum's busiest day loses ten customers' questions, the administrator cannot see why topics are missing, or the retry
	 *   hammers OmnaBase all night.
	 */
	public function test_the_daily_limit_holds_topics_until_the_next_day(): void {
		$accepted = 0;
		$this->answers['POST /api/public/v1/tickets'] = function ( $args ) use ( &$accepted ) {
			if ( $accepted >= 50 ) {
				return self::answerWith( 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'Today\'s allowance of tickets from connected sites is used; it starts over tomorrow', 'details' => array( 'reason' => 'api_tickets' ) ) ), array( 'retry-after' => '7200' ) );
			}
			++$accepted;
			return self::answer( 201, $this->ticketAnswer( $this->nextNumber++, $args ) );
		};
		$topics = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$topics[] = $this->topic( $this->publicForum, $this->ada, "Question {$i}", "Question number {$i}." );
		}

		$this->workOutbox( 5 );

		$filed   = array_filter( $topics, fn ( $t ) => '' !== (string) get_post_meta( $t, self::TICKET_META, true ) );
		$waiting = array_values( array_filter( $this->forumRows( 'topic' ), static fn ( $r ) => 'waiting' === $r['state'] ) );
		$this->assertCount( 50, $filed );
		$this->assertCount( 10, $waiting );
		$this->assertSame( array(), array_values( array_filter( $this->forumRows( 'topic' ), static fn ( $r ) => 'attention' === $r['state'] ) ) );
		foreach ( $waiting as $row ) {
			$this->assertGreaterThanOrEqual( time() + 7200 - 60, self::nextAt( $row ) );
		}
		wp_set_current_user( $this->admin );
		$screen = (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $this->renderScreen() ) );
		$this->assertMatchesRegularExpression( '/\b10 topics waiting\b/i', $screen );
		$this->assertMatchesRegularExpression( '/daily limit/i', $screen );

		$accepted = 0; // the next day: OmnaBase takes them again
		$this->sendEverything( 3 );

		$this->assertCount( 60, array_filter( $topics, fn ( $t ) => '' !== (string) get_post_meta( $t, self::TICKET_META, true ) ) );
		$ids = array_map( static fn ( $c ) => self::bodyOf( $c )['external']['id'] ?? '', $this->ticketCalls() );
		$this->assertCount( 70, $ids, '60 accepted tries and the 10 refused ones' );
		$this->assertCount( 60, array_unique( $ids ) );
	}

	/**
	 * What it checks: A topic OmnaBase already filed (its answer to the same reference again is a 200 with the ticket it made) counts as sent
	 *   with that ticket; a topic OmnaBase refuses for good waits for the administrator.
	 * Passes when: a 200 naming ticket 4242 leaves the topic with 4242 and its row sent; a 409 conflict leaves the topic without a number and its
	 *   row needing attention with OmnaBase's message.
	 * Fails when: the site files the same topic again and again, keeps no link to the ticket that exists, or silently forgets a refused topic.
	 */
	public function test_a_repeat_answer_is_success_and_a_conflict_needs_attention(): void {
		$this->answers['POST /api/public/v1/tickets'] = fn ( $args ) => self::answer( 200, $this->ticketAnswer( 4242, $args ) );
		$known = $this->topic( $this->publicForum, $this->ada );
		$this->workOutbox();
		$this->assertSame( '4242', (string) get_post_meta( $known, self::TICKET_META, true ) );
		$this->assertSame( array( 'sent' ), array_column( $this->forumRows( 'topic', $known ), 'state' ) );

		$this->answers['POST /api/public/v1/tickets'] = self::answer( 409, array( 'error' => array( 'code' => 'conflict', 'message' => 'This site\'s topic is already a ticket this key cannot reach' ) ) );
		$refused = $this->topic( $this->publicForum, $this->ben, 'Refused', 'Filed elsewhere.' );
		$this->sendEverything( 2 );

		$this->assertSame( '', (string) get_post_meta( $refused, self::TICKET_META, true ) );
		$rows = $this->forumRows( 'topic', $refused );
		$this->assertSame( array( 'attention' ), array_column( $rows, 'state' ) );
		$this->assertStringContainsString( 'already a ticket', (string) $rows[0]['last_error'] );
	}

	/**
	 * What it checks: One topic is one row in the outbox, however many of WordPress's moments fired for it.
	 * Passes when: a topic made by bbPress's form (the post's publication and bbPress's own moment) leaves exactly one topic row, waiting, about
	 *   that topic, before the outbox runs.
	 * Fails when: two rows race each other and the topic is filed twice.
	 */
	public function test_one_topic_is_one_row(): void {
		$topic = $this->topic( $this->publicForum, $this->ada );

		$rows = $this->forumRows( 'topic', $topic );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'waiting', $rows[0]['state'] );
	}
}
