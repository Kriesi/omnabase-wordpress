<?php
/**
 * The "old topics and undelivered replies" round (2026-11-16), part A, the red team's failing tests: the plugin's forum jobs, without
 * WordPress (the few WordPress and bbPress functions they read are faked, and the outbox's table runs on a fake database object).
 *
 * Brief (OmnaBase `docs/briefs/old-topics-and-undelivered-round.md`) part A, rules 1 to 11; report
 * `docs/reviews/old-topics-and-undelivered-round-red-team-report.md` in the OmnaBase repository, findings A1 to A12.
 *
 * The words: a *topic* is a bbPress forum thread; a *reply* an answer under it; a *marked forum* one the plugin's settings hand to OmnaBase; the
 * *outbox* is the plugin's own table of calls still to make to OmnaBase, one *row* per call, sent by a timer; a *hook* is a named moment where a
 * plugin's code runs (here: bbPress publishing a reply); *post meta* is a named value WordPress keeps beside a post; a *moderator* is a forum
 * user with the `moderate` capability (a permission); the *earlier posts* are the replies written before the topic became a ticket.
 *
 * Shapes the red team pins (the builder may rename and tell the red team):
 * - The reply hook (`Triggers::reply`) queues the topic's own row (`forum_topic:<topic>`) before the reply's row when the topic is no ticket and
 *   the reply is by the topic's author, another member or (guests switched on) a guest; a moderator's reply queues only its own row, as today.
 * - `TopicSender` is built with the settings, the connection, the post fields and the outbox's table (a fourth argument, to see which replies
 *   still have their own row coming). It reads the topic's replies with WordPress's `get_posts` (published replies of the topic, oldest first).
 * - An old topic's body adds `postedAt` (the topic's date, `2025-02-03T10:00:00Z` from its GMT date) and `earlier`: the published replies
 *   older than the oldest reply that still has its own row, oldest first, never one carrying OmnaBase's sign, at most the newest 30, each
 *   `{ from, body, postedAt, external: { id: "reply-<id>" } }` plus `siteAuthor { name, role }` for a moderator or `participant { name }` for
 *   another member; with more than 30, `earlierLeftOut` counts the rest. A topic without replies sends today's body (no `earlier`).
 * - When OmnaBase accepts the topic, every earlier reply that travelled is marked delivered (`_omnabase_delivered`).
 * - The reply job's words for a moderator's reply on a topic that is no ticket: "Not sent: its topic is no ticket yet. It becomes one when the
 *   customer or another member replies."
 * - The resend (`Resend`) brings in a customer's reply dropped before the round: it queues the topic, then the reply ("queued").
 *
 * Why these fail today: the hook never queues the topic, the reply job drops the reply ("its topic was never sent"), the topic's body has no
 * history, and the resend refuses ("its topic is no ticket at OmnaBase").
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forum\PostFields;
use OmnaBase\WordPress\Forum\ReplySender;
use OmnaBase\WordPress\Forum\Resend;
use OmnaBase\WordPress\Forum\Settings;
use OmnaBase\WordPress\Forum\TopicSender;
use OmnaBase\WordPress\Forum\Triggers;
use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Outbox\Queue;
use OmnaBase\WordPress\Outbox\Schedule;
use OmnaBase\WordPress\Outbox\Step;
use OmnaBase\WordPress\Outbox\Table;

final class OldTopicReplyTest extends TestCase {

	private const FORUM    = 10;
	private const TOPIC    = 2001;
	private const CUSTOMER = 3;
	private const GUENTER  = 7;
	private const BEN      = 9;

	/** @var array<int,\WP_Post> the site's posts by id */
	private array $posts = array();

	/** @var array<int,array<string,string>> post meta by post id */
	private array $meta = array();

	/** @var array<int,array{0:int,1:string,2:string}> every update_post_meta call: post, key, value */
	private array $written = array();

	/** @var object the fake database: `rows` are the outbox's inserted rows in order, `open` the dedupe keys of open rows */
	private object $db;

	protected function setUp(): void {
		parent::setUp();
		$this->posts = array( self::TOPIC => $this->post( self::TOPIC, 'topic', self::CUSTOMER, 0, 'The menu vanished after the update.', '2025-02-03 10:00:00' ) );
		$this->meta  = array( self::TOPIC => array() ); // no ticket number: the topic was never sent
		$this->db    = self::fakeDb();
		$GLOBALS['wpdb'] = $this->db;
		$users = array(
			self::CUSTOMER => (object) array( 'ID' => self::CUSTOMER, 'display_name' => 'Ada Kunde', 'user_login' => 'ada', 'user_email' => 'ada@kunde.example' ),
			self::GUENTER  => (object) array( 'ID' => self::GUENTER, 'display_name' => 'Günter', 'user_login' => 'guenter', 'user_email' => 'guenter@kriesi.example' ),
			self::BEN      => (object) array( 'ID' => self::BEN, 'display_name' => 'Ben Lerner', 'user_login' => 'ben', 'user_email' => 'ben@member.example' ),
		);
		Functions\when( 'get_post' )->alias( fn ( $id ) => $this->posts[ (int) ( is_object( $id ) ? $id->ID : $id ) ] ?? null );
		Functions\when( 'get_post_meta' )->alias( fn ( $id, $key = '', $single = false ) => $this->meta[ (int) $id ][ (string) $key ] ?? '' );
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->written[]                         = array( (int) $id, (string) $key, (string) $value );
				$this->meta[ (int) $id ][ (string) $key ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'get_posts' )->alias( fn ( $args = array() ) => $this->repliesOf( (int) ( $args['post_parent'] ?? self::TOPIC ) ) );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				if ( Options::FORUM === $name ) {
					return array( 'forums' => array( self::FORUM ), 'guests' => true );
				}
				if ( Options::SITE === $name ) {
					return array( 'projects' => array( array( 'id' => 'proj-1', 'slug' => 'enfold', 'name' => 'Enfold' ) ) );
				}
				return $default;
			}
		);
		Functions\when( 'get_userdata' )->alias( static fn ( $id ) => $users[ (int) $id ] ?? false );
		Functions\when( 'user_can' )->alias( static fn ( $id, $cap ) => 'moderate' === $cap && self::GUENTER === (int) $id );
		Functions\when( 'bbp_get_public_status_id' )->justReturn( 'publish' );
		Functions\when( 'bbp_get_closed_status_id' )->justReturn( 'closed' );
		Functions\when( 'bbp_get_reply_post_type' )->justReturn( 'reply' );
		Functions\when( 'bbp_get_topic_post_type' )->justReturn( 'topic' );
		Functions\when( 'bbp_get_topic_forum_id' )->justReturn( self::FORUM );
		Functions\when( 'bbp_get_forum_visibility' )->justReturn( 'publish' );
		Functions\when( 'bbp_get_user_display_role' )->alias( static fn ( $id ) => self::GUENTER === (int) $id ? 'Moderator' : 'Participant' );
		Functions\when( 'get_permalink' )->alias( static fn ( $p ) => 'https://kriesi.example/support/topic/menu-gone/' );
		Functions\when( 'get_the_title' )->justReturn( 'Menu gone' );
		Functions\when( 'is_email' )->alias( static fn ( $e ) => false !== filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- the fake of WordPress's own function
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- the fake of WordPress's own function
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->justReturn( true );
		Functions\when( 'wp_generate_password' )->alias( static fn ( $n = 12 ) => substr( bin2hex( random_bytes( 32 ) ), 0, (int) $n ) );
		Functions\when( '__' )->returnArg();
		// Added 2026-11-18 (the forum edits and pictures round): the sender now also reads the post as the forum shows it (no password here,
		// the forum's display filters faked as "unchanged") and remembers what it sent; nothing these tests assert changes.
		Functions\when( 'bbp_get_password_required_id' )->justReturn( 0 );
		Functions\when( 'setup_postdata' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn ( $v ) => json_encode( $v ) );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/** The fake database the outbox's table runs on: prepared statements filled in, open rows by dedupe key, inserts kept in order. */
	private static function fakeDb(): object {
		return new class() {
			public string $prefix = 'wp_';
			/** @var array<int,array<string,mixed>> */
			public array $rows = array();
			/** @var string[] */
			public array $open = array();
			public int $insert_id = 0;
			public function prepare( $query, ...$args ) {
				$args = ( 1 === count( $args ) && is_array( $args[0] ) ) ? $args[0] : $args;
				$i    = 0;
				return preg_replace_callback( '/%[sdfi]/', static function ( $m ) use ( &$i, $args ) { $v = $args[ $i++ ] ?? ''; return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'"; }, (string) $query );
			}
			public function get_var( $sql ) {
				foreach ( $this->open as $key ) {
					if ( str_contains( (string) $sql, "'" . $key . "'" ) ) {
						return '1';
					}
				}
				return null;
			}
			public function get_results( $sql, $output = null ) {
				$out = array();
				foreach ( $this->open as $n => $key ) {
					if ( str_contains( (string) $sql, "'" . explode( ':', $key )[0] . "'" ) && str_contains( (string) $sql, (string) (int) explode( ':', $key )[1] ) ) {
						$out[] = array( 'id' => $n + 1, 'state' => 'waiting', 'kind' => explode( ':', $key )[0], 'subject_id' => (int) explode( ':', $key )[1] );
					}
				}
				return $out;
			}
			public function insert( $table, $row, $format = null ) {
				$this->rows[]    = $row;
				$this->open[]    = (string) $row['dedupe_key'];
				$this->insert_id = count( $this->rows );
				return 1;
			}
			public function query( $sql ) {
				return 1;
			}
			public function update( ...$args ) {
				return 1;
			}
		};
	}

	/** A post as WordPress hands it out. */
	private function post( int $id, string $type, int $author, int $parent, string $content, string $gmt ): \WP_Post {
		$post                = \Mockery::mock( 'WP_Post' );
		$post->ID            = $id;
		$post->post_type     = $type;
		$post->post_status   = 'publish';
		$post->post_author   = (string) $author;
		$post->post_parent   = $parent;
		$post->post_title    = 'topic' === $type ? 'Menu gone' : 'Reply To: Menu gone';
		$post->post_content  = $content;
		$post->post_date_gmt = $gmt;
		$post->post_date     = $gmt;
		return $post;
	}

	/** A published reply on the topic. */
	private function reply( int $id, int $author, string $content, string $gmt, array $meta = array() ): int {
		$this->posts[ $id ] = $this->post( $id, 'reply', $author, self::TOPIC, $content, $gmt );
		$this->meta[ $id ]  = $meta;
		return $id;
	}

	/** The topic's published replies, oldest first, as `get_posts` hands them out. @return \WP_Post[] */
	private function repliesOf( int $topic ): array {
		$out = array_filter( $this->posts, static fn ( $p ) => 'reply' === $p->post_type && $topic === (int) $p->post_parent );
		usort( $out, static fn ( $a, $b ) => strcmp( $a->post_date_gmt, $b->post_date_gmt ) ?: $a->ID <=> $b->ID );
		return array_values( $out );
	}

	private function queue(): Queue {
		return new Queue( new Table(), new Schedule() );
	}

	/** The kinds and subjects of the rows the outbox got, in order. @return string[] */
	private function queued(): array {
		return array_map( static fn ( $r ) => (string) $r['dedupe_key'], $this->db->rows );
	}

	private function topicBody(): array {
		$settings = new Settings();
		$sender   = new TopicSender( $settings, new ConnectionInfo(), new PostFields( $settings ), new Table() );
		$step     = $sender->request( array( 'id' => 1, 'kind' => TopicSender::KIND, 'subject_id' => self::TOPIC, 'attempts' => 0, 'created_at' => time() ) );
		$this->assertSame( Step::SEND, $step->kind, 'the topic is sent: ' . $step->reason );
		return (array) ( $step->request['body'] ?? array() );
	}

	/**
	 * What it checks: A customer's reply on a topic OmnaBase never saw starts the topic's hand-over, topic first, reply after.
	 * Passes when: publishing Ada's reply on her own old topic puts two rows in the outbox: the topic's, then the reply's.
	 * Fails when: the reply is dropped as today (9 of 24 forum replies on kriesi.at in one week) and the team never sees the customer came back.
	 */
	public function test_a_customers_reply_on_an_old_topic_queues_the_topic_then_the_reply(): void {
		$reply = $this->reply( 2101, self::CUSTOMER, 'It broke again after 7.3.', '2026-11-15 09:00:00' );
		( new Triggers( $this->queue(), new Settings() ) )->reply( $reply );
		$this->assertSame( array( TopicSender::KIND . ':' . self::TOPIC, ReplySender::KIND . ':' . $reply ), $this->queued() );
	}

	/**
	 * What it checks: Another forum member's reply on an old topic starts it too, with the topic's author as the customer.
	 * Passes when: Ben's reply on Ada's old topic queues the topic's row, then the reply's.
	 * Fails when: a hijacked old topic ("same here") stays invisible to the team.
	 */
	public function test_another_members_reply_queues_the_topic_too(): void {
		$reply = $this->reply( 2102, self::BEN, 'Same here since 7.2.', '2026-11-15 09:05:00' );
		( new Triggers( $this->queue(), new Settings() ) )->reply( $reply );
		$this->assertSame( array( TopicSender::KIND . ':' . self::TOPIC, ReplySender::KIND . ':' . $reply ), $this->queued() );
	}

	/**
	 * What it checks: (guard) A moderator's reply alone never turns an old topic into a ticket (the brief's default for open question 4).
	 * Passes when: Günter's reply on the old topic queues only its own row, and that row is skipped with "Not sent: its topic is no ticket yet. It
	 *   becomes one when the customer or another member replies."
	 * Fails when: every moderator's answer on a years-old topic makes a new ticket nobody needs.
	 */
	public function test_a_moderators_reply_alone_does_not_start_it(): void {
		$reply = $this->reply( 2103, self::GUENTER, 'Please update to 7.3.1.', '2026-11-15 09:10:00' );
		( new Triggers( $this->queue(), new Settings() ) )->reply( $reply );
		$this->assertSame( array( ReplySender::KIND . ':' . $reply ), $this->queued(), 'no topic row' );
		$this->db->open = array(); // the reply's row is the one being worked
		$settings = new Settings();
		$step     = ( new ReplySender( $settings, new Table(), new PostFields( $settings ) ) )->request( array( 'id' => 1, 'kind' => ReplySender::KIND, 'subject_id' => $reply, 'attempts' => 0, 'created_at' => time() ) );
		$this->assertSame( Step::SKIP, $step->kind );
		$this->assertSame( 'Not sent: its topic is no ticket yet. It becomes one when the customer or another member replies.', $step->reason );
	}

	/**
	 * What it checks: An old topic is handed over with its history: its own date, then the earlier replies in order, each with its date and its
	 *   writer, never the reply that started it, a later one or one that came from OmnaBase.
	 * Passes when: the topic's call carries postedAt "2025-02-03T10:00:00Z" and four earlier posts in date order: Ada's (customer), Günter's (team,
	 *   siteAuthor Günter, Moderator), Ben's (other, participant Ben Lerner), a guest's (other, by the name the guest gave); each with the site's
	 *   own id "reply-<id>" and its date; the starting reply, the reply after it and a reply carrying OmnaBase's mark are not among them.
	 * Fails when: the team reads the customer's "it broke again" without the conversation it continues, the reply arrives twice (once as history,
	 *   once as new) and starts no AI draft, or OmnaBase's own answers come back as forum posts.
	 */
	public function test_the_topic_carries_its_earlier_posts_in_order_with_dates_and_writers(): void {
		$a = $this->reply( 2201, self::CUSTOMER, '<p>I cleared the cache.</p>', '2025-02-03 10:05:00' );
		$g = $this->reply( 2202, self::GUENTER, 'Switch the header to classic.', '2025-02-03 10:20:00' );
		$b = $this->reply( 2203, self::BEN, 'Same here.', '2025-02-03 10:40:00' );
		$q = $this->reply( 2204, 0, 'Guest here, same.', '2025-02-04 08:00:00', array( '_bbp_anonymous_name' => 'Gast Gabi', '_bbp_anonymous_email' => 'gabi@guest.example' ) );
		$this->reply( 2205, self::GUENTER, 'Posted from OmnaBase.', '2025-02-05 08:00:00', array( '_omnabase_message_id' => 'msg-1' ) );
		$start = $this->reply( 2301, self::CUSTOMER, 'It broke again after 7.3.', '2026-11-15 09:00:00' );
		$after = $this->reply( 2302, self::CUSTOMER, 'Any news?', '2026-11-15 12:00:00' );
		$this->db->open = array( ReplySender::KIND . ':' . $start, ReplySender::KIND . ':' . $after );

		$body = $this->topicBody();

		$this->assertSame( '2025-02-03T10:00:00Z', $body['postedAt'] ?? null );
		$earlier = (array) ( $body['earlier'] ?? array() );
		$this->assertSame( array( 'reply-' . $a, 'reply-' . $g, 'reply-' . $b, 'reply-' . $q ), array_map( static fn ( $e ) => $e['external']['id'] ?? null, $earlier ) );
		$this->assertSame( array( 'customer', 'team', 'other', 'other' ), array_column( $earlier, 'from' ) );
		$this->assertSame( array( '2025-02-03T10:05:00Z', '2025-02-03T10:20:00Z', '2025-02-03T10:40:00Z', '2025-02-04T08:00:00Z' ), array_column( $earlier, 'postedAt' ) );
		$this->assertSame( 'I cleared the cache.', $earlier[0]['body'] ?? null );
		$this->assertSame( 'Günter', $earlier[1]['siteAuthor']['name'] ?? null );
		$this->assertSame( 'Moderator', $earlier[1]['siteAuthor']['role'] ?? null );
		$this->assertSame( array( 'name' => 'Ben Lerner' ), $earlier[2]['participant'] ?? null );
		$this->assertSame( array( 'name' => 'Gast Gabi' ), $earlier[3]['participant'] ?? null );
		$this->assertArrayNotHasKey( 'earlierLeftOut', $body );
		foreach ( $earlier as $e ) {
			$this->assertArrayNotHasKey( 'fields', $e, 'earlier posts carry no site fields' );
		}
	}

	/**
	 * What it checks: A long topic sends its newest 30 earlier posts and says how many stayed in the forum.
	 * Passes when: with 42 earlier replies the call carries the newest 30 (from the 13th on, oldest first) and earlierLeftOut 12.
	 * Fails when: a long topic makes a call OmnaBase refuses, or the team believes it sees the whole topic.
	 */
	public function test_at_most_the_newest_30_with_the_left_out_count(): void {
		for ( $i = 1; $i <= 42; $i++ ) {
			$this->reply( 3000 + $i, self::CUSTOMER, "Post $i", sprintf( '2025-03-01 08:%02d:00', $i ) );
		}
		$start          = $this->reply( 3999, self::CUSTOMER, 'It broke again.', '2026-11-15 09:00:00' );
		$this->db->open = array( ReplySender::KIND . ':' . $start );

		$body    = $this->topicBody();
		$earlier = (array) ( $body['earlier'] ?? array() );

		$this->assertCount( 30, $earlier );
		$this->assertSame( 'reply-3013', $earlier[0]['external']['id'] ?? null );
		$this->assertSame( 'reply-3042', $earlier[29]['external']['id'] ?? null );
		$this->assertSame( 12, $body['earlierLeftOut'] ?? null );
	}

	/**
	 * What it checks: (guard) A new topic without replies is filed exactly as today.
	 * Passes when: a topic with no replies sends no `earlier` and no `earlierLeftOut`.
	 * Fails when: every new forum question is filed as quiet history and the AI stops drafting for it.
	 */
	public function test_a_new_topic_sends_no_history(): void {
		$body = $this->topicBody();
		$this->assertArrayNotHasKey( 'earlier', $body );
		$this->assertArrayNotHasKey( 'earlierLeftOut', $body );
	}

	/**
	 * What it checks: Every earlier reply that travelled is marked as delivered on the site, so sending it again answers "already sent".
	 * Passes when: after OmnaBase accepted the topic (ticket 812), the topic keeps 812 and both earlier replies carry the delivered mark; the
	 *   starting reply does not (its own row still goes).
	 * Fails when: a resend by hand puts an old post on the ticket a second time, or the starting reply is never sent.
	 */
	public function test_the_earlier_replies_are_marked_delivered_when_the_topic_is_accepted(): void {
		$a              = $this->reply( 2401, self::CUSTOMER, 'Cleared the cache.', '2025-02-03 10:05:00' );
		$g              = $this->reply( 2402, self::GUENTER, 'Try classic.', '2025-02-03 10:20:00' );
		$start          = $this->reply( 2403, self::CUSTOMER, 'Broke again.', '2026-11-15 09:00:00' );
		$this->db->open = array( ReplySender::KIND . ':' . $start );
		$settings       = new Settings();
		$sender         = new TopicSender( $settings, new ConnectionInfo(), new PostFields( $settings ), new Table() );

		$sender->sent( array( 'id' => 1, 'kind' => TopicSender::KIND, 'subject_id' => self::TOPIC ), array( 'number' => 812 ) );

		$this->assertSame( '812', $this->meta[ self::TOPIC ][ Options::TICKET_META ] ?? null );
		$this->assertNotSame( '', $this->meta[ $a ][ Options::DELIVERED_META ] ?? '' );
		$this->assertNotSame( '', $this->meta[ $g ][ Options::DELIVERED_META ] ?? '' );
		$this->assertSame( '', $this->meta[ $start ][ Options::DELIVERED_META ] ?? '' );
	}

	/**
	 * What it checks: (guard) A guest's old topic without a usable address still waits for the administrator, as today.
	 * Passes when: a guest's topic without an address answers "needs attention" when its turn comes.
	 * Fails when: OmnaBase is sent a ticket without a customer, or the topic silently disappears.
	 */
	public function test_a_guests_topic_without_an_address_needs_attention(): void {
		$this->posts[ self::TOPIC ]->post_author = '0';
		$this->reply( 2501, 0, 'Still broken.', '2026-11-15 09:00:00', array( '_bbp_anonymous_name' => 'Gast' ) );
		$settings = new Settings();
		$sender   = new TopicSender( $settings, new ConnectionInfo(), new PostFields( $settings ), new Table() );
		$step     = $sender->request( array( 'id' => 1, 'kind' => TopicSender::KIND, 'subject_id' => self::TOPIC, 'attempts' => 0, 'created_at' => time() ) );
		$this->assertSame( Step::ATTENTION, $step->kind );
	}

	/**
	 * What it checks: The resend command brings in a customer's reply that was dropped before this round, topic first.
	 * Passes when: resending Ada's dropped reply answers "queued" and puts the topic's row, then the reply's, into the outbox.
	 * Fails when: the replies lost before the round can never be brought in, even one by one.
	 */
	public function test_the_resend_brings_in_a_reply_dropped_before(): void {
		$reply = $this->reply( 2601, self::CUSTOMER, 'Hello? Still broken.', '2026-11-10 09:00:00' );
		$answer = ( new Resend( new Settings(), $this->queue(), new Table() ) )->resend( $reply );
		$this->assertSame( 'queued', $answer['status'], $answer['words'] );
		$this->assertSame( array( TopicSender::KIND . ':' . self::TOPIC, ReplySender::KIND . ':' . $reply ), $this->queued() );
	}
}
