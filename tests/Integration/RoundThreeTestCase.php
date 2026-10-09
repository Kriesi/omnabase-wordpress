<?php
/**
 * M13 round three, the red team's integration helpers (inside wp-env's tests WordPress with bbPress 2.6 loaded: bin/test-integration.sh's
 * third run, OMNABASE_TEST_FORUM=1, the group "forum"): a connected site, forums of each visibility, the people of a forum (two customers, a
 * moderator, the support user, another participant), topics and replies made the ways bbPress makes them, OmnaBase faked at the HTTP layer
 * (round one's `pre_http_request` fake) answering topics with ticket numbers, OmnaBase's signed `message.sent` and `ticket.updated`
 * webhooks, and WordPress's mails caught (the `pre_wp_mail` filter) so a test can tell when bbPress's subscriber mails leave.
 *
 * The words: *topic* and *reply*, bbPress's two kinds of forum post; *marked forum*, a forum the administrator ticked so its topics become
 * tickets; *hidden forum*, readable by moderators only; *private forum*, readable by every logged-in user; *anonymous poster*, a guest who
 * gives a name and an e-mail address bbPress never checks; *support user*, the one WordPress user under whose name OmnaBase's replies appear.
 *
 * Shapes the red team pins here (chosen; the builder may rename and tell the red team; every name sits in this file):
 * - The forum settings live in one option, `omnabase_forum`: `[ 'forums' => [ <forum id>, … ], 'support_user' => <user id>,
 *   'closing_line' => <text with {agent} and {workspace}>, 'guests' => bool, 'close_resolved' => bool ]`. Missing keys: no forum marked, no
 *   support user, the default closing line "— {agent}, {workspace}", guests forwarded (the brief's default), closing on resolve off.
 * - The ticket number is kept on the topic as post meta `_omnabase_ticket`; OmnaBase's message id on a reply the plugin posted as
 *   `_omnabase_message_id` (the brief's name).
 * - The project of a new ticket is the connection's first project (`omnabase_site`'s `projects`, round one's record).
 * - Outbox rows about a topic or a reply carry the post's id in `subject_id` and a kind holding "topic" or "reply".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

abstract class RoundThreeTestCase extends RoundTwoTestCase {

	protected const FORUM_OPTION = 'omnabase_forum';
	protected const TICKET_META  = '_omnabase_ticket';
	protected const MESSAGE_META = '_omnabase_message_id';
	protected const FIRST_NUMBER = 1001;

	protected int $support   = 0;
	protected int $moderator = 0;
	protected int $ada       = 0;
	protected int $ben       = 0;
	protected int $carl      = 0;

	protected int $publicForum   = 0;
	protected int $privateForum  = 0;
	protected int $hiddenForum   = 0;
	protected int $unmarkedForum = 0;

	/** @var array<int,array{to:mixed,subject:string}> the mails WordPress was asked to send */
	protected array $mails = array();

	/** the next ticket number the fake hands out */
	protected int $nextNumber = self::FIRST_NUMBER;

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( function_exists( 'bbp_insert_topic' ), 'bbPress is not loaded: the forum tests run in bin/test-integration.sh\'s third run (OMNABASE_TEST_FORUM=1, --group forum)' );
		update_option(
			'omnabase_site',
			array(
				'workspace'   => array( 'name' => 'Team Kriesi', 'slug' => 'kriesi' ),
				'site'        => array( 'name' => 'Kriesi Shop', 'url' => self::SITE ),
				'scopes'      => self::SCOPES,
				'projects'    => array( array( 'id' => self::PROJECT, 'slug' => 'enfold', 'name' => 'Enfold' ) ),
				'events'      => self::EVENTS,
				'connectedAt' => time(),
			),
			false
		);
		$this->support   = $this->person( 'support', 'support@shop.example.org', 'Kriesi Support', bbp_get_moderator_role() );
		$this->moderator = $this->person( 'nina', 'nina@shop.example.org', 'Nina Moderator', bbp_get_moderator_role() );
		$this->ada       = $this->person( 'ada', 'ada@example.org', 'Ada Lovelace', bbp_get_participant_role() );
		$this->ben       = $this->person( 'ben', 'ben@example.org', 'Ben Weber', bbp_get_participant_role() );
		$this->carl      = $this->person( 'carl', 'carl@example.org', 'Carl Other', bbp_get_participant_role() );

		$this->publicForum   = (int) bbp_insert_forum( array( 'post_title' => 'Support', 'post_content' => 'The support forum.' ) );
		$this->privateForum  = (int) bbp_insert_forum( array( 'post_title' => 'Members', 'post_content' => 'For logged-in customers.' ) );
		$this->hiddenForum   = (int) bbp_insert_forum( array( 'post_title' => 'Staff', 'post_content' => 'Moderators only.' ) );
		$this->unmarkedForum = (int) bbp_insert_forum( array( 'post_title' => 'Off topic', 'post_content' => 'Chatter.' ) );
		bbp_privatize_forum( $this->privateForum );
		bbp_hide_forum( $this->hiddenForum );
		$this->assertSame( 'private', bbp_get_forum_visibility( $this->privateForum ) );
		$this->assertSame( 'hidden', bbp_get_forum_visibility( $this->hiddenForum ) );

		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ) );
		$this->nextNumber = self::FIRST_NUMBER;
		$this->omnabaseFilesTopics();
		$this->answers['POST /messages'] = fn () => self::answer( 201, array( 'id' => 'msg-' . wp_generate_password( 8, false ), 'kind' => 'customer' ) );
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );

		$this->mails = array();
		add_filter( 'pre_wp_mail', array( $this, 'catchMail' ), 10, 2 );
		$this->clearOutbox();
		$this->calls = array();
	}

	/* ---------- people and settings ---------- */

	/** A forum user with a bbPress role. */
	protected function person( string $login, string $email, string $name, string $forum_role ): int {
		$id = (int) self::factory()->user->create( array( 'user_login' => $login . '_' . wp_generate_password( 4, false, false ), 'user_email' => $email, 'display_name' => $name, 'role' => 'subscriber' ) );
		bbp_set_user_role( $id, $forum_role );
		return $id;
	}

	/**
	 * The forum settings: these forums marked, the support user chosen, anything else as given.
	 *
	 * @param int[]               $forums The marked forums.
	 * @param array<string,mixed> $more   Other keys (`closing_line`, `guests`, `close_resolved`, `support_user`).
	 */
	protected function markForums( array $forums, array $more = array() ): void {
		update_option( self::FORUM_OPTION, array_merge( array( 'forums' => array_map( 'intval', $forums ), 'support_user' => $this->support ), $more ) );
	}

	/* ---------- topics and replies, made the ways bbPress makes them ---------- */

	/**
	 * A topic. `front`: bbPress's own form (the post, then `bbp_new_topic`); `admin`: wp-admin's editor (the post alone: only
	 * `transition_post_status` fires). A held topic is `pending`, one caught by the spam filter `spam`.
	 *
	 * @param int    $forum   The forum.
	 * @param int    $author  The author (0 for an anonymous poster).
	 * @param string $title   The title.
	 * @param string $content The first post.
	 * @param string $how     `front` or `admin`.
	 * @param string $status  `publish`, `pending` or `spam`.
	 * @param array  $guest   An anonymous poster's `name` and `email`.
	 */
	protected function topic( int $forum, int $author, string $title = 'The switcher is gone', string $content = 'Since 7.1 the language switcher is gone.', string $how = 'front', string $status = 'publish', array $guest = array() ): int {
		$meta = array( 'forum_id' => $forum );
		if ( $guest ) {
			$meta['anonymous_name']  = $guest['name'] ?? '';
			$meta['anonymous_email'] = $guest['email'] ?? '';
		}
		$id = (int) bbp_insert_topic( array( 'post_parent' => $forum, 'post_author' => $author, 'post_title' => $title, 'post_content' => $content, 'post_status' => $status ), $meta );
		$this->assertGreaterThan( 0, $id, 'the topic was made' );
		if ( 'front' === $how && 'publish' === $status ) {
			$anonymous = $guest ? array( 'bbp_anonymous_name' => $guest['name'] ?? '', 'bbp_anonymous_email' => $guest['email'] ?? '', 'bbp_anonymous_website' => '' ) : array();
			do_action( 'bbp_new_topic', $id, $forum, $anonymous, $author );
		}
		return $id;
	}

	/**
	 * A reply on a topic, made like a topic (`front`: the post, then `bbp_new_reply`; `admin`: the post alone).
	 *
	 * @param int    $topic   The topic.
	 * @param int    $author  The author.
	 * @param string $content The reply.
	 * @param string $how     `front` or `admin`.
	 * @param string $status  `publish`, `pending` or `spam`.
	 */
	protected function reply( int $topic, int $author, string $content = 'Still broken after the update.', string $how = 'front', string $status = 'publish' ): int {
		$forum = (int) bbp_get_topic_forum_id( $topic );
		$id    = (int) bbp_insert_reply( array( 'post_parent' => $topic, 'post_author' => $author, 'post_content' => $content, 'post_status' => $status ), array( 'forum_id' => $forum, 'topic_id' => $topic ) );
		$this->assertGreaterThan( 0, $id, 'the reply was made' );
		if ( 'front' === $how && 'publish' === $status ) {
			do_action( 'bbp_new_reply', $id, $topic, $forum, array(), $author, false, 0 );
		}
		return $id;
	}

	/* ---------- the outbox, worked ---------- */

	/** Runs the outbox until nothing more is due now (a few runs; each works at most 20 rows). */
	protected function workOutbox( int $runs = 5 ): void {
		for ( $i = 0; $i < $runs; $i++ ) {
			$this->runOutbox();
		}
	}

	/** Every waiting row becomes due, then the outbox is worked. */
	protected function sendEverything( int $runs = 5 ): void {
		for ( $i = 0; $i < $runs; $i++ ) {
			$this->makeDue();
			$this->runOutbox();
		}
	}

	/** The outbox rows about a topic (`topic`) or a reply (`reply`), optionally about one post. */
	protected function forumRows( string $what, ?int $post = null ): array {
		return array_values( array_filter( $this->outboxRows(), static fn ( $r ) => str_contains( (string) $r['kind'], $what ) && ( null === $post || (int) $r['subject_id'] === $post ) ) );
	}

	/** A topic, filed with OmnaBase through the outbox; its ticket number. */
	protected function filedTopic( int $forum = 0, int $author = 0 ): int {
		$topic = $this->topic( $forum ?: $this->publicForum, $author ?: $this->ada );
		$this->sendEverything( 2 );
		$this->assertNotSame( '', (string) get_post_meta( $topic, self::TICKET_META, true ), 'the topic became a ticket' );
		return $topic;
	}

	/* ---------- OmnaBase, faked ---------- */

	/** OmnaBase files every topic: 201 with the next number (the ticket as `docs/public-api.md` answers it). */
	protected function omnabaseFilesTopics(): void {
		$this->answers['POST /api/public/v1/tickets'] = fn ( $args ) => self::answer( 201, $this->ticketAnswer( $this->nextNumber++, $args ) );
	}

	/** The ticket OmnaBase answers for a request. */
	protected function ticketAnswer( int $number, array $args ): array {
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$body = is_array( $body ) ? $body : array();
		return array( 'number' => $number, 'subject' => (string) ( $body['subject'] ?? '' ), 'status' => 'open', 'projectId' => self::PROJECT, 'channel' => 'api', 'external' => $body['external'] ?? null, 'replyVia' => $body['replyVia'] ?? 'mail', 'public' => (bool) ( $body['public'] ?? false ), 'messages' => array() );
	}

	/**
	 * A fake answer with headers (a 429's `Retry-After`).
	 *
	 * @param int                  $status  The status.
	 * @param mixed                $body    The body.
	 * @param array<string,string> $headers The headers.
	 */
	protected static function answerWith( int $status, $body, array $headers ): array {
		$a            = self::answer( $status, $body );
		$a['headers'] = array_merge( $a['headers'], $headers );
		return $a;
	}

	/** The `POST /tickets` calls the plugin made. */
	protected function ticketCalls(): array {
		return $this->callsTo( 'POST', '/api/public/v1/tickets' );
	}

	/** The `POST /tickets/{number}/messages` calls. */
	protected function messageCalls( ?int $number = null ): array {
		return array_values( array_filter( $this->callsTo( 'POST', '/messages' ), static fn ( $c ) => null === $number || str_ends_with( (string) wp_parse_url( $c['url'], PHP_URL_PATH ), '/tickets/' . $number . '/messages' ) ) );
	}

	/** A call's JSON body. */
	protected static function bodyOf( array $call ): array {
		$b = json_decode( (string) ( $call['args']['body'] ?? '' ), true );
		return is_array( $b ) ? $b : array();
	}

	/** A call's header, whatever its case; null when absent. */
	protected static function headerOf( array $call, string $name ): ?string {
		foreach ( (array) ( $call['args']['headers'] ?? array() ) as $k => $v ) {
			if ( strtolower( (string) $k ) === strtolower( $name ) ) {
				return (string) $v;
			}
		}
		return null;
	}

	/* ---------- OmnaBase's webhooks ---------- */

	/**
	 * `message.sent` for a team reply on a topic's ticket (`docs/public-api.md`, "The events").
	 *
	 * @param int    $topic   The topic (its ticket number and `topic-<id>`).
	 * @param string $message OmnaBase's message id.
	 * @param string $agent   The agent's name.
	 * @param string $html    The reply as HTML.
	 * @param string $text    The reply as text.
	 */
	protected function teamReply( int $topic, string $message, string $agent = 'Ada', string $html = '<p>Please update to version 2.1.</p>', string $text = 'Please update to version 2.1.' ): array {
		return array(
			'ticket'  => $this->ticketData( $topic, 'pending' ),
			'message' => array( 'id' => $message, 'direction' => 'out', 'kind' => 'team', 'author' => array( 'name' => $agent ), 'bodyText' => $text, 'bodyHtml' => $html, 'external' => null, 'createdAt' => gmdate( 'Y-m-d\TH:i:s.000\Z' ) ),
		);
	}

	/** The `ticket` part of an event about a topic's ticket. */
	protected function ticketData( int $topic, string $status ): array {
		return array(
			'number'   => (int) get_post_meta( $topic, self::TICKET_META, true ),
			'subject'  => get_the_title( $topic ),
			'status'   => $status,
			'customer' => array( 'id' => 'cust-1', 'name' => 'Ada Lovelace', 'email' => 'ada@example.org' ),
			'external' => array( 'id' => 'topic-' . $topic, 'url' => (string) get_permalink( $topic ) ),
			'replyVia' => 'site',
			'public'   => true,
		);
	}

	/** The replies on a topic (any status), oldest first. */
	protected static function repliesOn( int $topic ): array {
		return get_posts( array( 'post_type' => bbp_get_reply_post_type(), 'post_parent' => $topic, 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC', 'numberposts' => -1, 'suppress_filters' => true ) );
	}

	/* ---------- mails ---------- */

	/**
	 * Catches a mail instead of sending it.
	 *
	 * @param mixed $return Null to send.
	 * @param array $atts   The mail.
	 * @return bool
	 */
	public function catchMail( $return, $atts ) {
		$this->mails[] = array( 'to' => $atts['to'] ?? '', 'subject' => (string) ( $atts['subject'] ?? '' ), 'headers' => $atts['headers'] ?? '' );
		return true;
	}

	/** Whether any caught mail went to an address (in To or Bcc). */
	protected function mailedTo( string $email ): int {
		$n = 0;
		foreach ( $this->mails as $m ) {
			$all = strtolower( wp_json_encode( $m ) );
			$n  += str_contains( $all, strtolower( $email ) ) ? 1 : 0;
		}
		return $n;
	}

	/** Runs every scheduled event of the plugin's own except the outbox's (a one-off job a handler left for later). */
	protected function runPluginJobs(): int {
		$ran = 0;
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				if ( ! str_starts_with( (string) $hook, 'omnabase_' ) || in_array( $hook, array( 'omnabase_outbox', 'omnabase_outbox_now' ), true ) ) {
					continue;
				}
				foreach ( (array) $instances as $instance ) {
					wp_unschedule_event( wp_next_scheduled( $hook, $instance['args'] ?? array() ), $hook, $instance['args'] ?? array() );
					do_action_ref_array( $hook, $instance['args'] ?? array() );
					++$ran;
				}
			}
		}
		return $ran;
	}
}
