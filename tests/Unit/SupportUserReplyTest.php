<?php
/**
 * The "support user replies" round (2026-11-04), the red team's failing tests: the outbox's reply job, without WordPress (the few WordPress
 * and bbPress functions it reads are faked).
 *
 * Brief (OmnaBase `docs/briefs/support-user-replies-round.md`) part one, rule 1: "`whyNot()` skips a reply only when it carries the mark. A
 * reply by the support user without the mark is a moderator's answer … and goes as `from: team` with `siteAuthor` naming the support user's
 * own display name, address and role, like any moderator's." Report `docs/reviews/support-user-replies-round-red-team-report.md` in the
 * OmnaBase repository, findings 1 and 4.
 *
 * The words: *reply*, a bbPress answer under a forum topic; *support user*, the one forum moderator chosen under Settings › OmnaBase ›
 * Forums under whose account OmnaBase's answers are posted when no team member has a moderator account there (on kriesi.at: Günter);
 * *the mark*, the post meta (a named value WordPress keeps beside a post) `_omnabase_message_id` the plugin writes on every reply it posts
 * for OmnaBase; *the posted-as sign*, the post meta `_omnabase_posted_as` (`member` or `support`) the plugin writes on the same replies.
 *
 * Shapes the red team pins (the builder may rename and tell the red team):
 * - The reply job is `OmnaBase\WordPress\Forum\ReplySender`, built with the forum settings, the outbox's table and the post fields, as today.
 * - (chosen, finding 4) A reply that carries the posted-as sign is OmnaBase's own as well, even when its message id is missing (a posting
 *   interrupted between bbPress's save and the mark): the same skip reason, "Not sent: the reply came from OmnaBase."
 *
 * Why these fail today: the job skips every reply by the support user ("Not sent: the reply came from OmnaBase."), and it reads only the
 * message id, never the posted-as sign.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Forum\PostFields;
use OmnaBase\WordPress\Forum\ReplySender;
use OmnaBase\WordPress\Forum\Settings;
use OmnaBase\WordPress\Outbox\Step;
use OmnaBase\WordPress\Outbox\Table;

final class SupportUserReplyTest extends TestCase {

	private const FORUM   = 10;
	private const TOPIC   = 1501200;
	private const TICKET  = 253;
	private const GUENTER = 7;
	private const NINA    = 8;
	private const CUSTOMER = 3;

	/** @var array<int,object> the site's posts by id */
	private array $posts = array();

	/** @var array<int,array<string,string>> post meta by post id */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		$this->posts = array( self::TOPIC => $this->post( self::TOPIC, 'topic', self::CUSTOMER, 0, 'Header Responsive Logo problem' ) );
		$this->meta  = array( self::TOPIC => array( '_omnabase_ticket' => (string) self::TICKET ) );
		$users       = array(
			self::GUENTER  => (object) array( 'ID' => self::GUENTER, 'display_name' => 'Günter', 'user_login' => 'guenter', 'user_email' => 'guenter@kriesi.example' ),
			self::NINA     => (object) array( 'ID' => self::NINA, 'display_name' => 'Nina Moderator', 'user_login' => 'nina', 'user_email' => 'nina@kriesi.example' ),
			self::CUSTOMER => (object) array( 'ID' => self::CUSTOMER, 'display_name' => 'Ada Lovelace', 'user_login' => 'ada', 'user_email' => 'ada@example.org' ),
		);
		Functions\when( 'get_post' )->alias( fn ( $id ) => $this->posts[ (int) $id ] ?? null );
		Functions\when( 'get_post_meta' )->alias( fn ( $id, $key = '', $single = false ) => $this->meta[ (int) $id ][ (string) $key ] ?? '' );
		Functions\when( 'get_option' )->alias( static fn ( $name, $default = false ) => 'omnabase_forum' === $name ? array( 'forums' => array( self::FORUM ), 'support_user' => self::GUENTER ) : $default );
		Functions\when( 'get_userdata' )->alias( static fn ( $id ) => $users[ (int) $id ] ?? false );
		Functions\when( 'user_can' )->alias( static fn ( $id, $cap ) => 'moderate' === $cap && in_array( (int) $id, array( self::GUENTER, self::NINA ), true ) );
		Functions\when( 'bbp_get_public_status_id' )->justReturn( 'publish' );
		Functions\when( 'bbp_get_reply_post_type' )->justReturn( 'reply' );
		Functions\when( 'bbp_get_topic_post_type' )->justReturn( 'topic' );
		Functions\when( 'bbp_get_topic_forum_id' )->justReturn( self::FORUM );
		Functions\when( 'bbp_get_user_display_role' )->alias( static fn ( $id ) => self::CUSTOMER === (int) $id ? 'Participant' : 'Moderator' );
		Functions\when( 'get_permalink' )->alias( static fn ( $p ) => 'https://kriesi.example/support/topic/header-responsive-logo-problem/#post-' . ( is_object( $p ) ? $p->ID : (int) $p ) );
		Functions\when( 'get_the_title' )->justReturn( 'Header Responsive Logo problem' );
		Functions\when( 'is_email' )->alias( static fn ( $e ) => false !== filter_var( (string) $e, FILTER_VALIDATE_EMAIL ) ? $e : false );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- the fake of WordPress's own function
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- the fake of WordPress's own function
		Functions\when( '__' )->returnArg();
	}

	/** A post as WordPress hands it out. */
	private function post( int $id, string $type, int $author, int $parent, string $content ): \WP_Post {
		$post              = \Mockery::mock( 'WP_Post' );
		$post->ID          = $id;
		$post->post_type   = $type;
		$post->post_status = 'publish';
		$post->post_author = (string) $author;
		$post->post_parent = $parent;
		$post->post_title  = 'Reply To: Header Responsive Logo problem';
		$post->post_content = $content;
		return $post;
	}

	/** A published reply on the topic, with these post meta. */
	private function reply( int $id, int $author, string $content, array $meta = array() ): int {
		$this->posts[ $id ] = $this->post( $id, 'reply', $author, self::TOPIC, $content );
		$this->meta[ $id ]  = $meta;
		return $id;
	}

	/** What the reply job decides for a reply's row when its turn comes. */
	private function decide( int $reply ): Step {
		$settings = new Settings();
		$sender   = new ReplySender( $settings, new Table(), new PostFields( $settings ) );
		return $sender->request( array( 'id' => 1, 'kind' => ReplySender::KIND, 'subject_id' => $reply, 'attempts' => 0, 'created_at' => time() ) );
	}

	/**
	 * What it checks: A post the support user writes by hand on the forum reaches the topic's ticket as the team's answer under the support
	 *   user's own name, like any moderator's (ticket 253 lost Günter's remarks this way).
	 * Passes when: Günter's reply without the plugin's mark goes as one call to ticket 253's messages, from the team, already delivered, with
	 *   siteAuthor "Günter", his own address and his forum role, his text and the site's reference "reply-<id>".
	 * Fails when: the support user's own answers stay on the forum and the ticket reads as if the team never wrote them.
	 */
	public function test_the_support_users_own_reply_goes_as_the_teams_answer_under_his_name(): void {
		$reply = $this->reply( 1501320, self::GUENTER, '<p>Please also clear the <strong>Enfold</strong> cache.</p>' );

		$step = $this->decide( $reply );

		$this->assertSame( Step::SEND, $step->kind, 'sent, not skipped: ' . $step->reason );
		$this->assertSame( '/tickets/' . self::TICKET . '/messages', $step->request['path'] ?? null );
		$body = (array) ( $step->request['body'] ?? array() );
		$this->assertSame( 'team', $body['from'] ?? null );
		$this->assertTrue( $body['delivered'] ?? null );
		$this->assertSame( array( 'name' => 'Günter', 'email' => 'guenter@kriesi.example', 'role' => 'Moderator' ), $body['siteAuthor'] ?? null );
		$this->assertSame( 'Please also clear the Enfold cache.', $body['body'] ?? null );
		$this->assertSame( array( 'id' => 'reply-' . $reply ), $body['external'] ?? null );
		$this->assertTrue( $step->request['idempotent'] ?? null, 'the row\'s own idempotency key goes with it' );
	}

	/**
	 * What it checks: (guard) A reply the plugin posted for OmnaBase is never sent back to OmnaBase, whoever it was posted under.
	 * Passes when: replies carrying OmnaBase's message id, under the support user, under another moderator and under the topic's customer,
	 *   are each skipped with "Not sent: the reply came from OmnaBase."
	 * Fails when: the team's answer appears twice on the ticket, the second time as if the forum had answered.
	 *
	 * @dataProvider posters
	 *
	 * @param int $author Who the reply was posted under.
	 */
	public function test_a_marked_reply_is_skipped_whoever_it_was_posted_under( int $author ): void {
		$reply = $this->reply( 1501316, $author, 'Posted from OmnaBase.', array( '_omnabase_message_id' => 'msg-253-1', '_omnabase_posted_as' => 'support' ) );

		$step = $this->decide( $reply );

		$this->assertSame( Step::SKIP, $step->kind );
		$this->assertSame( 'Not sent: the reply came from OmnaBase.', $step->reason );
	}

	/** The accounts a reply can be posted under. */
	public static function posters(): array {
		return array(
			'the support user'   => array( self::GUENTER ),
			'another moderator'  => array( self::NINA ),
			'the topic\'s author' => array( self::CUSTOMER ),
		);
	}

	/**
	 * What it checks: (chosen, finding 4) A reply the plugin posted is recognised as OmnaBase's even when its posting was cut off before the
	 *   message id was written, so it never comes back as a second copy of the team's answer.
	 * Passes when: Nina's reply that carries only the posted-as sign "member" (no message id) is skipped with "Not sent: the reply came from
	 *   OmnaBase."
	 * Fails when: an answer posted under a moderator's account during a hiccup reaches the ticket a second time, as the moderator's forum post.
	 */
	public function test_a_reply_with_only_the_posted_as_sign_is_omnabases_own(): void {
		$reply = $this->reply( 1501317, self::NINA, 'Posted from OmnaBase, cut off before its mark.', array( '_omnabase_posted_as' => 'member' ) );

		$step = $this->decide( $reply );

		$this->assertSame( Step::SKIP, $step->kind, 'skipped as OmnaBase\'s own' );
		$this->assertSame( 'Not sent: the reply came from OmnaBase.', $step->reason );
	}
}
