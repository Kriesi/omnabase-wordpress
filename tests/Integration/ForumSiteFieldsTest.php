<?php
/**
 * The site-fields round (2026-10-29), the red team's failing tests: a forum post's private data travels to OmnaBase with the post, and the
 * team's private data comes back onto the forum reply OmnaBase posts; inside wp-env's tests WordPress with bbPress loaded (the group "forum").
 * Replaces the earlier round's parked `ForumPrivateTopicsTest.php` (private topics are dropped).
 *
 * Brief (OmnaBase `docs/briefs/site-fields-round.md`) rule 5 ("The connector") and its tests ("Plugin (wp-env)"); report
 * `docs/reviews/site-fields-round-red-team-report.md` in the OmnaBase repository, findings 15 to 21.
 *
 * The words: *post meta*, a named value WordPress keeps beside a post (AviaSupport's "Private Content" box saves `_av_supp_private_info`
 * through bbPress's input filters, so `&` is stored as `&amp;` and line breaks as `<br />`); *declared field*, one the administrator named
 * under Settings › OmnaBase › Forums › Post fields (or a developer through the filter `omnabase_forum_fields`); *message.sent*, OmnaBase's
 * signed call that hands a team reply to the site.
 *
 * Shapes the red team pins (the brief's, plus the ones marked chosen; the builder may rename and tell the red team):
 * - The setting is stored in the forum option as `fields` => [ key => name ] (the cleaned rows, `PostFields::cleanRows`); the save takes
 *   `omnabase_forum[fields][n][key|label]` from the Forums tab's one form; a dropped row is named in the next screen's notice.
 * - The Field input offers the site's meta keys of topics and replies through a `<datalist>` (the input's `list` names it): up to 100, none
 *   starting with `_bbp_`.
 * - `POST /tickets` carries `fields` (each declared field whose meta on the topic is non-empty: `[ key, label, value ]`, the value through
 *   `omnabase_forum_field_value( $value, $key, $post_id )`, then made plain) and `replyFields` (every declared field: `[ key, label ]`); no
 *   `fields` key when none has a value. `POST /tickets/{n}/messages` carries `fields` the same way, for the customer's reply and a moderator's.
 * - (chosen) The auto-complete's keys are cached in the transient `omnabase_forum_meta_keys` (an hour).
 * - `message.sent` with `message.fields`: after `bbp_insert_reply`, each field whose key is declared NOW is written as the reply's post meta
 *   (tags stripped, line breaks and `%2F`-style sequences kept), then `omnabase_forum_field_saved( $reply_id, $key, $value )` fires; any
 *   other key writes nothing, even one that was declared when the topic was filed.
 *
 * Why these fail today: no Post fields setting, no datalist, no `fields` or `replyFields` in the bodies, no meta written, no hooks.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * @group forum
 */
final class ForumSiteFieldsTest extends RoundThreeTestCase {

	private const PRIVATE = '_av_supp_private_info';
	private const STORED  = '<p>https://shop.test/wp-admin<br />admin / hunter2&amp;co</p>';
	private const PLAIN   = "https://shop.test/wp-admin\nadmin / hunter2&co";

	/** The forums marked, with these declared fields. */
	private function declare( array $fields ): void {
		$this->markForums( array( $this->publicForum, $this->privateForum, $this->hiddenForum ), array( 'fields' => $fields ) );
	}

	/**
	 * What it checks: The administrator names the forum's private field once, and a mistake is told, not swallowed.
	 * Passes when: the Forums tab's form holds a Post fields row (a Field input with auto-complete, a Name input); saving AviaSupport's key
	 *   "Private data", a key with a space and bbPress's own key stores only the first, and the screen afterwards names the two dropped keys.
	 * Fails when: kriesi.at cannot declare its private data, or a typo silently sends nothing.
	 */
	public function test_the_post_fields_box_saves_rows_and_names_the_dropped(): void {
		$forms = array_values( array_filter( self::formsIn( $this->openTab( 'forums' ) ), static fn ( $f ) => 'omnabase_save_forum' === $f['action'] ) );
		$this->assertCount( 1, $forms );
		$key = self::query( $this->openTab( 'forums' ), '//input[starts-with(@name, "omnabase_forum[fields]") and contains(@name, "[key]")]' );
		$this->assertGreaterThan( 0, $key->length, 'a Field input' );
		$this->assertNotSame( '', (string) $key->item( 0 )->getAttribute( 'list' ), 'the Field input names its datalist' );
		$this->assertGreaterThan( 0, self::query( $this->openTab( 'forums' ), '//input[starts-with(@name, "omnabase_forum[fields]") and contains(@name, "[label]")]' )->length, 'a Name input' );

		$fields = $forms[0]['fields'];
		$fields['omnabase_forum']['fields'] = array(
			array( 'key' => self::PRIVATE, 'label' => 'Private data' ),
			array( 'key' => 'bad key', 'label' => 'Bad' ),
			array( 'key' => '_bbp_forum_id', 'label' => 'Forum' ),
			array( 'key' => '', 'label' => '' ),
		);
		$this->postFields( $fields );
		$this->assertSame( array( self::PRIVATE => 'Private data' ), get_option( self::FORUM_OPTION )['fields'] ?? null );
		$after = $this->openTab( 'forums' );
		$this->assertStringContainsString( 'bad key', $after );
		$this->assertStringContainsString( '_bbp_forum_id', $after );
	}

	/**
	 * What it checks: The Field input suggests the meta keys the site's topics and replies really carry.
	 * Passes when: with a topic carrying AviaSupport's key and a reply carrying another, the datalist offers both, no key starting with
	 *   `_bbp_`, and at most 100.
	 * Fails when: the administrator has to guess AviaSupport's internal name, or the list fills with bbPress's own counters.
	 */
	public function test_the_datalist_offers_the_sites_meta_keys(): void {
		$topic = $this->topic( $this->publicForum, $this->ada );
		update_post_meta( $topic, self::PRIVATE, self::STORED );
		update_post_meta( $this->reply( $topic, $this->ada ), 'my_reply_field', 'x' );
		delete_transient( 'omnabase_forum_meta_keys' );
		$html    = $this->openTab( 'forums' );
		$options = array_map( static fn ( $o ) => (string) $o->getAttribute( 'value' ), iterator_to_array( self::query( $html, '//datalist/option' ) ) );
		$this->assertContains( self::PRIVATE, $options );
		$this->assertContains( 'my_reply_field', $options );
		$this->assertSame( array(), array_values( array_filter( $options, static fn ( $k ) => str_starts_with( $k, '_bbp_' ) ) ) );
		$this->assertLessThanOrEqual( 100, count( $options ) );
	}

	/**
	 * What it checks: kriesi.at's private data goes with the topic, as the plain lines the customer typed, and OmnaBase learns it may send
	 *   the field back.
	 * Passes when: a topic with AviaSupport's stored private data sends one field (key, "Private data", the plain value with the escaped &
	 *   read back) and replyFields naming it; a topic without the meta sends no fields key but still the replyFields.
	 * Fails when: the team never sees the customer's login, sees `&amp;` and `<br />` in it, or cannot answer into the private field.
	 */
	public function test_a_topic_sends_fields_and_reply_fields(): void {
		$this->declare( array( self::PRIVATE => 'Private data' ) );
		$with = $this->topic( $this->publicForum, $this->ada );
		update_post_meta( $with, self::PRIVATE, self::STORED );
		$this->topic( $this->publicForum, $this->ben );
		$this->sendEverything();

		$calls = $this->ticketCalls();
		$this->assertCount( 2, $calls );
		$first  = self::bodyOf( $calls[0] );
		$second = self::bodyOf( $calls[1] );
		$this->assertSame( array( array( 'key' => self::PRIVATE, 'label' => 'Private data', 'value' => self::PLAIN ) ), $first['fields'] ?? null );
		$this->assertSame( array( array( 'key' => self::PRIVATE, 'label' => 'Private data' ) ), $first['replyFields'] ?? null );
		$this->assertArrayNotHasKey( 'fields', $second );
		$this->assertSame( array( array( 'key' => self::PRIVATE, 'label' => 'Private data' ) ), $second['replyFields'] ?? null );
	}

	/**
	 * What it checks: (guard) A forum that declares no fields sends exactly what it sends today.
	 * Passes when: with no Post fields, a topic carrying AviaSupport's meta sends neither fields nor replyFields.
	 * Fails when: every connected forum suddenly ships its posts' meta to OmnaBase without the administrator asking.
	 */
	public function test_no_declared_fields_nothing_sent(): void {
		$topic = $this->topic( $this->publicForum, $this->ada );
		update_post_meta( $topic, self::PRIVATE, self::STORED );
		$this->sendEverything();
		$body = self::bodyOf( $this->ticketCalls()[0] ?? array() );
		$this->assertNotSame( array(), $body, 'the topic was sent' );
		$this->assertArrayNotHasKey( 'fields', $body );
		$this->assertArrayNotHasKey( 'replyFields', $body );
	}

	/**
	 * What it checks: Later forum posts carry their own private data: the customer's follow-up and a moderator's answer.
	 * Passes when: the customer's reply with the meta sends it as a field; a moderator's reply with the meta sends it with the delivered answer.
	 * Fails when: a login the customer adds later, or a test page a moderator names in the private box, never reaches the ticket.
	 */
	public function test_replies_send_their_fields(): void {
		$this->declare( array( self::PRIVATE => 'Private data' ) );
		$topic = $this->filedTopic();
		update_post_meta( $this->reply( $topic, $this->ada, 'Here is the FTP access.' ), self::PRIVATE, '<p>ftp.shop.test / deploy / pa55</p>' );
		update_post_meta( $this->reply( $topic, $this->moderator, 'Thanks, I checked.' ), self::PRIVATE, 'Test page: /test-menu/' );
		$this->sendEverything();

		$bodies = array_map( array( self::class, 'bodyOf' ), $this->messageCalls( self::FIRST_NUMBER ) );
		$this->assertCount( 2, $bodies );
		$this->assertSame( 'customer', $bodies[0]['from'] ?? null );
		$this->assertSame( array( array( 'key' => self::PRIVATE, 'label' => 'Private data', 'value' => 'ftp.shop.test / deploy / pa55' ) ), $bodies[0]['fields'] ?? null );
		$this->assertSame( 'team', $bodies[1]['from'] ?? null );
		$this->assertSame( array( array( 'key' => self::PRIVATE, 'label' => 'Private data', 'value' => 'Test page: /test-menu/' ) ), $bodies[1]['fields'] ?? null );
	}

	/**
	 * What it checks: A developer can add a field by code and change a value on its way out.
	 * Passes when: a field added through `omnabase_forum_fields` is sent as declared and with its meta; `omnabase_forum_field_value` is asked
	 *   with the value, the key and the topic's id, and what it answers is sent.
	 * Fails when: a site whose private data lives elsewhere (another plugin, an encrypted store) cannot take part.
	 */
	public function test_the_two_filters(): void {
		$this->declare( array( self::PRIVATE => 'Private data' ) );
		$add    = static fn ( $fields ) => (array) $fields + array( 'licence_key' => 'Licence' );
		$asked  = array();
		$change = function ( $value, $key, $post_id ) use ( &$asked ) {
			$asked[] = array( $key, (int) $post_id );
			return 'licence_key' === $key ? 'ENF-' . $value : $value;
		};
		add_filter( 'omnabase_forum_fields', $add );
		add_filter( 'omnabase_forum_field_value', $change, 10, 3 );
		$topic = $this->topic( $this->publicForum, $this->ada );
		update_post_meta( $topic, 'licence_key', '1234' );
		$this->sendEverything();
		remove_filter( 'omnabase_forum_fields', $add );
		remove_filter( 'omnabase_forum_field_value', $change, 10 );

		$body = self::bodyOf( $this->ticketCalls()[0] ?? array() );
		$this->assertContains( array( 'key' => 'licence_key', 'label' => 'Licence' ), $body['replyFields'] ?? array() );
		$this->assertSame( array( array( 'key' => 'licence_key', 'label' => 'Licence', 'value' => 'ENF-1234' ) ), $body['fields'] ?? null );
		$this->assertContains( array( 'licence_key', $topic ), $asked );
	}

	/**
	 * What it checks: The team's private data lands in the forum reply's private field, intact, and nothing undeclared is ever written.
	 * Passes when: a message.sent with the declared field, an undeclared key and WordPress's page-template key posts one reply whose
	 *   AviaSupport meta holds the value with its line break and its %2F kept and no script, the other two keys stay empty, and the saved
	 *   action fires once with the reply, the key and the value.
	 * Fails when: the customer cannot read the login the team sent, OmnaBase can write any meta on the site, or a login address is broken.
	 */
	public function test_message_sent_writes_declared_meta_only(): void {
		$this->declare( array( self::PRIVATE => 'Private data' ) );
		$topic = $this->filedTopic();
		$saved = array();
		$hear  = function ( $reply_id, $key, $value ) use ( &$saved ) {
			$saved[] = array( (int) $reply_id, $key, $value );
		};
		add_action( 'omnabase_forum_field_saved', $hear, 10, 3 );
		$value                     = "Login: https://shop.test/wp-login.php?redirect_to=%2Fwp-admin\nolga / Pa55-word<script>alert(1)</script>";
		$data                      = $this->teamReply( $topic, 'msg-fields' );
		$data['message']['fields'] = array(
			array( 'key' => self::PRIVATE, 'label' => 'Private data', 'value' => $value ),
			array( 'key' => 'undeclared_key', 'label' => 'Other', 'value' => 'x' ),
			array( 'key' => '_wp_page_template', 'label' => 'Template', 'value' => 'evil.php' ),
		);

		$response = $this->deliverEvent( 'message.sent', 'dlv-fields', $data );
		remove_action( 'omnabase_forum_field_saved', $hear, 10 );

		$this->assertSame( 200, $response->get_status() );
		$replies = array_values( array_filter( self::repliesOn( $topic ), fn ( $r ) => (int) $r->post_author === $this->support ) );
		$this->assertCount( 1, $replies );
		$stored = (string) get_post_meta( $replies[0]->ID, self::PRIVATE, true );
		$this->assertStringContainsString( 'redirect_to=%2Fwp-admin', $stored );
		$this->assertStringContainsString( "\nolga / Pa55-word", $stored );
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertSame( '', (string) get_post_meta( $replies[0]->ID, 'undeclared_key', true ) );
		$this->assertSame( '', (string) get_post_meta( $replies[0]->ID, '_wp_page_template', true ) );
		$this->assertCount( 1, $saved );
		$this->assertSame( array( $replies[0]->ID, self::PRIVATE ), array( $saved[0][0], $saved[0][1] ) );
	}

	/**
	 * What it checks: (guard: passes today, when nothing is written at all) Only what the administrator declares at the moment of posting is
	 *   written; removing a field stops it at once.
	 * Passes when: a topic filed while the field was declared, then the declaration removed, gets the team's reply posted without the meta.
	 * Fails when: an old declaration keeps letting OmnaBase write a field the administrator has since taken away.
	 */
	public function test_the_declaration_at_post_time_decides(): void {
		$this->declare( array( self::PRIVATE => 'Private data' ) );
		$topic = $this->filedTopic();
		$this->declare( array() );
		$data                      = $this->teamReply( $topic, 'msg-later' );
		$data['message']['fields'] = array( array( 'key' => self::PRIVATE, 'label' => 'Private data', 'value' => 'pw: x1y2' ) );

		$this->assertSame( 200, $this->deliverEvent( 'message.sent', 'dlv-later', $data )->get_status() );
		$replies = array_values( array_filter( self::repliesOn( $topic ), fn ( $r ) => (int) $r->post_author === $this->support ) );
		$this->assertCount( 1, $replies, 'the reply itself is posted' );
		$this->assertSame( '', (string) get_post_meta( $replies[0]->ID, self::PRIVATE, true ) );
	}
}
