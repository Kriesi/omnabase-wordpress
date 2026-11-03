<?php
/**
 * The forum reply mails round (OmnaBase brief docs/briefs/forum-reply-mails-round.md, rules 1 to 3; report
 * docs/reviews/forum-reply-mails-round-red-team-report.md in the OmnaBase repository, findings 19 to 24), the red team's failing tests,
 * written before the code. They run in the forum run (bbPress loaded: bin/test-integration.sh's third run, the group "forum").
 *
 * The words: *topic*, a bbPress forum thread, and its *author*, the customer who opened it; *subscriber*, a forum user who asked bbPress to
 * mail them about new replies in a topic; *webhook*, OmnaBase's signed call to the site (`message.sent` carries a team answer to post); the
 * site's *answer*, what the plugin replies to that call (HTTP 200 and a small JSON body); *filter*, a named hook where code changes a value
 * (bbPress's `bbp_topic_subscription_user_ids` changes the list of users it mails about a reply); *post meta*, a named value WordPress keeps
 * beside a post; *WP-Cron job*, a task WordPress runs a moment later (the plugin mails subscribers that way, outside OmnaBase's five seconds).
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The switch "OmnaBase mails the customer about answers" is `customer_mail` in the forum option (off when missing), the checkbox
 *   `omnabase_forum[customer_mail]` (value 1) in a row right under "Keep topics in step with tickets", with the help "After an answer from
 *   OmnaBase is posted, OmnaBase mails the topic's author in your project's look. The forum's own subscription mail then leaves them out."
 * - The 200 answer to a `message.sent` the plugin posted carries `posted: { id: "reply-<reply id>", url: bbp_get_reply_url( <reply id> ) }`,
 *   and `posted.mail: "omnabase"` only while OmnaBase mails the author; `ok: true` stays.
 * - (chosen, finding 20) Whether OmnaBase mails the author is decided once, when the reply is posted, and kept on the reply as post meta
 *   `_omnabase_customer_mail` (`1`); the subscriber job reads that, not the switch at the job's time, so a switch flipped in between never
 *   gives the author two mails or none.
 * - (chosen, finding 21) A repeated delivery (the same delivery id, or the same message under a new delivery id) answers the same `posted`
 *   (and `mail` by the reply's mark): OmnaBase's retry after a slow first answer must not lose the mail.
 * - (chosen, finding 22) A guest author (no WordPress account; bbPress never checks a guest's address) is never mailed by OmnaBase:
 *   `posted` has no `mail` for a guest's topic, and bbPress's mails are unchanged.
 * - The author is left out of bbPress's mail through bbPress's own filter `bbp_topic_subscription_user_ids` (bbPress 2.6, the installed
 *   version's `bbp_notify_topic_subscribers`), added just around the call and removed after it; every other subscriber is still mailed.
 *
 * Why these fail today: the answer is a bare `{ ok: true }`, the forum option knows no `customer_mail`, the subscriber job mails everyone
 * bbPress lists, and the Forums tab has no such row.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumReplyMailsTest extends RoundThreeTestCase {

	use AdminLookShapes;

	private const SAVE  = 'omnabase_save_forum';
	private const LABEL = 'OmnaBase mails the customer about answers';
	private const HELP  = 'After an answer from OmnaBase is posted, OmnaBase mails the topic\'s author in your project\'s look. The forum\'s own subscription mail then leaves them out.';
	private const META  = '_omnabase_customer_mail';
	private const HOOK  = 'bbp_topic_subscription_user_ids';

	/** The forum settings with OmnaBase's mail on or off (the three test forums marked, the support user chosen). */
	private function customerMail( bool $on ): void {
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'customer_mail' => $on ) );
	}

	/** A delivered `message.sent` for a topic: the answer and the reply it posted. @return array{0:\WP_REST_Response,1:int} */
	private function answered( int $topic, string $message, string $delivery ): array {
		$response = $this->deliverEvent( 'message.sent', $delivery, $this->teamReply( $topic, $message ) );
		$this->assertSame( 200, $response->get_status() );
		$replies = self::repliesOn( $topic );
		return array( $response, $replies ? (int) end( $replies )->ID : 0 );
	}

	/** The answer's body as an array. */
	private static function body( \WP_REST_Response $response ): array {
		$data = $response->get_data();
		return is_array( $data ) ? $data : array();
	}

	/**
	 * The forum section saved through its form, as the screen posts it.
	 *
	 * @param array<string,mixed> $fields The posted `omnabase_forum` fields.
	 */
	private function save( array $fields ): void {
		wp_set_current_user( $this->admin );
		$_POST    = array( 'action' => self::SAVE, 'omnabase_forum' => $fields, '_wpnonce' => wp_create_nonce( self::SAVE ) );
		$_REQUEST = $_POST;
		try {
			do_action( 'admin_post_' . self::SAVE );
		} catch ( Redirected $r ) {
			unset( $r );
		} catch ( \WPDieException $e ) {
			unset( $e );
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
	}

	/**
	 * What it checks: The switch is off for every site until an administrator turns it on, and the Forums tab's form stores it both ways.
	 * Passes when: with nothing stored the answer to OmnaBase carries `posted` without `mail`; saving the form with the box ticked stores
	 *   `customer_mail` true, saving it unticked stores false, and the other forum settings stay as saved.
	 * Fails when: sites start getting OmnaBase's mails without anyone choosing it, or the box cannot be turned on or off.
	 */
	public function test_off_by_default_and_saved_by_the_form(): void {
		$topic            = $this->filedTopic();
		list( $response ) = $this->answered( $topic, 'msg-default', 'dlv-default' );
		$posted           = self::body( $response )['posted'] ?? null;
		$this->assertIsArray( $posted, 'the answer carries posted' );
		$this->assertArrayNotHasKey( 'mail', $posted, 'off when nothing is stored' );

		$this->save( array( 'forums' => array( (string) $this->publicForum ), 'support_user' => (string) $this->support, 'in_step' => '1', 'customer_mail' => '1' ) );
		$saved = (array) get_option( self::FORUM_OPTION, array() );
		$this->assertTrue( (bool) ( $saved['customer_mail'] ?? false ), 'ticked: stored on' );
		$this->assertTrue( (bool) ( $saved['in_step'] ?? false ), 'the other switches as saved' );

		$this->save( array( 'forums' => array( (string) $this->publicForum ), 'support_user' => (string) $this->support, 'in_step' => '1' ) );
		$saved = (array) get_option( self::FORUM_OPTION, array() );
		$this->assertArrayHasKey( 'customer_mail', $saved, 'unticked is stored as off' );
		$this->assertFalse( (bool) $saved['customer_mail'] );
	}

	/**
	 * What it checks: The plugin tells OmnaBase, with each posted answer, where it posted it, and asks for the mail only while the switch is on.
	 * Passes when: with the switch on the answer is `ok: true` with `posted.id` "reply-<the new reply's id>", `posted.url` the reply's own
	 *   address in the forum and `posted.mail` "omnabase", and the reply is marked for OmnaBase's mail; with the switch off the same shape
	 *   without `mail` and without the mark.
	 * Fails when: OmnaBase cannot link the customer to the answer, mails although the site said no, or never mails although it said yes.
	 */
	public function test_the_answer_carries_posted_and_mail_only_when_on(): void {
		$this->customerMail( true );
		$on                       = $this->filedTopic();
		list( $response, $reply ) = $this->answered( $on, 'msg-on', 'dlv-on' );
		$body                     = self::body( $response );
		$this->assertTrue( (bool) ( $body['ok'] ?? false ) );
		$this->assertSame( array( 'id' => 'reply-' . $reply, 'url' => bbp_get_reply_url( $reply ), 'mail' => 'omnabase' ), $body['posted'] ?? null );
		$this->assertSame( '1', (string) get_post_meta( $reply, self::META, true ), 'the reply remembers that OmnaBase mails its topic\'s author' );

		$this->customerMail( false );
		$off                       = $this->filedTopic();
		list( $response2, $reply2 ) = $this->answered( $off, 'msg-off', 'dlv-off' );
		$this->assertSame( array( 'id' => 'reply-' . $reply2, 'url' => bbp_get_reply_url( $reply2 ) ), self::body( $response2 )['posted'] ?? null );
		$this->assertSame( '', (string) get_post_meta( $reply2, self::META, true ) );
	}

	/**
	 * What it checks: A retried delivery still tells OmnaBase where the answer is and whether to mail, so a slow first answer never loses the mail.
	 * Passes when: the same delivery id sent again, and the same message under a new delivery id, are each answered 200 with the same `posted`
	 *   (with `mail`) as the first time, and the topic still has one reply.
	 * Fails when: OmnaBase's retry after a timeout gets a bare "done", so the customer is never mailed, or the answer is posted twice.
	 */
	public function test_a_repeated_delivery_answers_the_same_posted(): void {
		$this->customerMail( true );
		$topic            = $this->filedTopic();
		list( $first )    = $this->answered( $topic, 'msg-again', 'dlv-again' );
		$again            = $this->deliverEvent( 'message.sent', 'dlv-again', $this->teamReply( $topic, 'msg-again' ) );
		$other            = $this->deliverEvent( 'message.sent', 'dlv-again-2', $this->teamReply( $topic, 'msg-again' ) );
		$posted           = self::body( $first )['posted'] ?? null;
		$this->assertIsArray( $posted );
		$this->assertSame( $posted, self::body( $again )['posted'] ?? null, 'the same delivery again' );
		$this->assertSame( $posted, self::body( $other )['posted'] ?? null, 'the same message under a new delivery id' );
		$this->assertCount( 1, self::repliesOn( $topic ) );
	}

	/**
	 * What it checks: While OmnaBase mails the topic's author, bbPress's own mail about that answer leaves the author out and still reaches
	 *   everyone else who follows the topic.
	 * Passes when: Ada (the author) and Ben both follow Ada's topic; with the switch on, after the plugin's job Ben is mailed once and Ada not
	 *   at all; nothing is mailed inside the webhook.
	 * Fails when: Ada gets two mails about one answer (ours and the forum's), or the other followers stop hearing about answers.
	 */
	public function test_on_bbpress_leaves_the_author_out_and_keeps_the_others(): void {
		$this->customerMail( true );
		$topic = $this->filedTopic();
		bbp_add_user_subscription( $this->ada, $topic );
		bbp_add_user_subscription( $this->ben, $topic );
		$this->mails = array();

		$this->answered( $topic, 'msg-subs', 'dlv-subs' );
		$this->assertSame( 0, $this->mailedTo( 'ben@example.org' ), 'no mail inside the webhook' );
		$this->assertGreaterThan( 0, $this->runPluginJobs() );

		$this->assertSame( 1, $this->mailedTo( 'ben@example.org' ), 'the other follower still hears' );
		$this->assertSame( 0, $this->mailedTo( 'ada@example.org' ), 'the author is OmnaBase\'s to mail' );
	}

	/**
	 * What it checks: (guard) With the switch off nothing changes: bbPress mails every follower, the author included.
	 * Passes when: Ada and Ben follow the topic; with the switch off both are mailed once by the plugin's job.
	 * Fails when: turning the new feature off still drops the author from the forum's mails, so they hear nothing.
	 */
	public function test_off_bbpress_mails_everyone_as_before(): void {
		$this->customerMail( false );
		$topic = $this->filedTopic();
		bbp_add_user_subscription( $this->ada, $topic );
		bbp_add_user_subscription( $this->ben, $topic );
		$this->mails = array();

		$this->answered( $topic, 'msg-subs-off', 'dlv-subs-off' );
		$this->runPluginJobs();

		$this->assertSame( 1, $this->mailedTo( 'ben@example.org' ) );
		$this->assertSame( 1, $this->mailedTo( 'ada@example.org' ) );
	}

	/**
	 * What it checks: The choice made when the answer was posted holds for its mails, even if the administrator flips the switch before the
	 *   job runs.
	 * Passes when: an answer posted with the switch on, the switch then turned off before the job: Ada is still left out (OmnaBase mails her);
	 *   an answer posted with the switch off, the switch then turned on: Ada is mailed by bbPress (OmnaBase was told not to).
	 * Fails when: a switch flipped at the wrong moment gives the author two mails about one answer, or none.
	 */
	public function test_the_choice_made_at_posting_holds_for_the_job(): void {
		$this->customerMail( true );
		$first = $this->filedTopic();
		bbp_add_user_subscription( $this->ada, $first );
		$this->answered( $first, 'msg-flip-1', 'dlv-flip-1' );
		$this->customerMail( false );
		$this->mails = array();
		$this->runPluginJobs();
		$this->assertSame( 0, $this->mailedTo( 'ada@example.org' ), 'posted while on: OmnaBase mails her, bbPress leaves her out' );

		$second = $this->filedTopic();
		bbp_add_user_subscription( $this->ada, $second );
		$this->answered( $second, 'msg-flip-2', 'dlv-flip-2' );
		$this->customerMail( true );
		$this->mails = array();
		$this->runPluginJobs();
		$this->assertSame( 1, $this->mailedTo( 'ada@example.org' ), 'posted while off: OmnaBase was told not to mail, bbPress mails her' );
	}

	/**
	 * What it checks: The author is left out through bbPress's own list of followers to mail, only for that one answer.
	 * Passes when: during the plugin's job a site's own callback on `bbp_topic_subscription_user_ids` (run after the plugin's) sees Ben but not
	 *   Ada; after the job nothing of the plugin's is left on that filter.
	 * Fails when: the plugin bypasses bbPress (so other plugins that adjust the list stop working), or its change stays on the filter and drops
	 *   the author from mails about other people's replies too.
	 */
	public function test_the_author_leaves_through_bbpress_filter_only_around_the_call(): void {
		$this->customerMail( true );
		$topic = $this->filedTopic();
		bbp_add_user_subscription( $this->ada, $topic );
		bbp_add_user_subscription( $this->ben, $topic );
		$this->answered( $topic, 'msg-filter', 'dlv-filter' );
		$seen  = null;
		$probe = static function ( $ids ) use ( &$seen ) {
			$seen = array_map( 'intval', (array) $ids );
			return $ids;
		};
		add_filter( self::HOOK, $probe, 99 );
		$this->runPluginJobs();
		remove_filter( self::HOOK, $probe, 99 );

		$this->assertIsArray( $seen, 'bbPress built its list through the filter' );
		$this->assertContains( $this->ben, $seen );
		$this->assertNotContains( $this->ada, $seen );
		$this->assertFalse( has_filter( self::HOOK ), 'nothing of the plugin\'s is left on the filter' );
	}

	/**
	 * What it checks: A guest's topic (no WordPress account, an address nobody checked) never makes OmnaBase mail that address, and bbPress's
	 *   mails stay as they were.
	 * Passes when: with the switch on and guests forwarded, the answer for a guest's topic carries `posted` without `mail` and the reply has
	 *   no mark; Ben, who follows the topic, is still mailed by bbPress.
	 * Fails when: anyone can type a stranger's address into a guest topic and have OmnaBase mail that person in the project's name.
	 */
	public function test_a_guest_author_is_never_mailed_by_omnabase(): void {
		$this->customerMail( true );
		$topic = $this->topic( $this->publicForum, 0, 'Guest question', 'Is there a dark mode?', 'front', 'publish', array( 'name' => 'Gina Guest', 'email' => 'gina@example.org' ) );
		$this->sendEverything( 2 );
		$this->assertNotSame( '', (string) get_post_meta( $topic, self::TICKET_META, true ), 'the guest\'s topic became a ticket' );
		bbp_add_user_subscription( $this->ben, $topic );
		$this->mails = array();

		list( $response, $reply ) = $this->answered( $topic, 'msg-guest', 'dlv-guest' );
		$posted                   = self::body( $response )['posted'] ?? null;
		$this->assertIsArray( $posted );
		$this->assertArrayNotHasKey( 'mail', $posted );
		$this->assertSame( '', (string) get_post_meta( $reply, self::META, true ) );
		$this->runPluginJobs();
		$this->assertSame( 1, $this->mailedTo( 'ben@example.org' ) );
	}

	/**
	 * What it checks: (guard) A `message.sent` the plugin does not post (not one of the site's topics) is answered as before, without `posted`.
	 * Passes when: a team reply for a ticket with no forum topic is answered 200 `{ ok: true }` with no `posted`.
	 * Fails when: OmnaBase is told an answer was posted that the forum never shows.
	 */
	public function test_no_posted_when_nothing_was_posted(): void {
		$this->customerMail( true );
		$data                       = $this->teamReply( $this->filedTopic(), 'msg-none' );
		$data['ticket']['external'] = null;
		$response                   = $this->deliverEvent( 'message.sent', 'dlv-none', $data );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayNotHasKey( 'posted', self::body( $response ) );
	}

	/**
	 * What it checks: The Forums tab shows the switch in the brief's words, under "Keep topics in step with tickets", off by default.
	 * Passes when: the forum rows include one labelled "OmnaBase mails the customer about answers" right after "Keep topics in step with
	 *   tickets", its control is the unchecked checkbox `omnabase_forum[customer_mail]`, and the help sentence is on the page (at most 30 words).
	 * Fails when: administrators cannot find or understand the switch, or it shows as on although nothing was chosen.
	 */
	public function test_the_row_on_the_forums_tab(): void {
		update_option( self::FORUM_OPTION, array( 'forums' => array( $this->publicForum ), 'support_user' => $this->support ) );
		wp_set_current_user( $this->admin );
		$html   = $this->openTab( 'forums' );
		$labels = array();
		$rows   = array();
		foreach ( $this->rowsOf( $html, 'forums' ) as $row ) {
			$labels[]               = $row['label'];
			$rows[ $row['label'] ] = $row['control'];
		}
		$this->assertArrayHasKey( self::LABEL, $rows );
		$at = array_search( 'Keep topics in step with tickets', $labels, true );
		$this->assertNotFalse( $at );
		$this->assertSame( self::LABEL, $labels[ $at + 1 ] ?? null, 'right under "Keep topics in step with tickets"' );
		$box = ( new \DOMXPath( $rows[ self::LABEL ]->ownerDocument ) )->query( ".//input[@type='checkbox'][@name='omnabase_forum[customer_mail]']", $rows[ self::LABEL ] );
		$this->assertSame( 1, $box->length, 'the switch' );
		$this->assertFalse( $box->item( 0 )->hasAttribute( 'checked' ), 'off when nothing is stored' );
		$this->assertStringContainsString( self::HELP, (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES ) ) );
		$this->assertLessThanOrEqual( 30, self::wordCount( self::HELP ) );
	}
}
