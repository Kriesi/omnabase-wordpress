<?php
/**
 * M13 round five, the red team's failing tests: OmnaBase's two refusals of a site user, and a changed WordPress email (inside wp-env's tests
 * WordPress, the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 9: "`409 address_taken` → 'This email address belongs to another
 * account in our support. Please write to us by email.'; `409 forgotten` → 'Your support history was deleted at your request. Write to us to
 * start again.'"; "**E-mail change**: `profile_update` and Woo's account edit queue the new address; OmnaBase adds it unconfirmed, replies stay
 * on each ticket's own address, and the prompt returns (Q5)"; round five: "`Sync/ProfileWatcher`"; its *Red team*: "`address_taken` and
 * `forgotten` shown in their words; an e-mail change sends the new address and the prompt returns"; choice 5 ("*Alternative: also `PUT` every
 * buyer*", not taken: only users OmnaBase knows are sent). The coordinator: "a changed WordPress e-mail sent at once"; "the 'address taken' and
 * 'forgotten' refusals in plain words; never a plan's name".
 *
 * Shapes the red team pins: `RoundFiveTestCase`; the outbox's job `omnabase_outbox` (round two). (chosen) The new address reaches OmnaBase
 * either at once or through the outbox (the brief's "queue" and the coordinator's "at once" both pass): the test works the outbox before it
 * looks. (chosen) The refusals are matched by their key phrases ("belongs to another account", "deleted at your request").
 *
 * Why these fail today: no message centre exists, and nothing listens to a profile change.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class MessageCentreIdentityTest extends RoundFiveTestCase {

	/**
	 * What it checks: When OmnaBase refuses the customer's address as another person's, the customer reads the brief's plain sentence and sees
	 *   nothing else.
	 * Passes when: with `PUT` answering 409 `address_taken`, the page says the address belongs to another account and to write by email; no
	 *   portal call is made, no prompt shows, and none of "409", "conflict", "plan" or OmnaBase's own message appears.
	 * Fails when: a customer whose address someone else proved sees an error code, a broken box, or (worse) a list that is not theirs.
	 */
	public function test_address_taken_reads_in_the_briefs_words(): void {
		$this->refusals['sync'] = self::conflict( 'address_taken', 'This address belongs to a customer this key cannot reach' );
		$html = $this->view( $this->ada );
		$text = self::text( $html );
		$this->assertMatchesRegularExpression( '/belongs to another account/i', $text );
		$this->assertMatchesRegularExpression( '/by email/i', $text );
		$this->assertSame( array(), $this->portalCalls(), 'nothing is read for a refused user' );
		$this->assertFalse( self::hasPrompt( $html ) );
		$this->assertDoesNotMatchRegularExpression( '/\b409\b|conflict|\bplan\b|this key cannot reach/i', $text );
	}

	/**
	 * What it checks: When the customer asked OmnaBase to delete their data, the message centre says so in the brief's plain sentence.
	 * Passes when: with `PUT` answering 409 `forgotten`, the page says the support history was deleted at their request (and how to start
	 *   again), makes no portal call and shows none of "409", "conflict", "tombstone" or OmnaBase's own message.
	 * Fails when: a customer who asked to be forgotten sees an error, or is re-added to the support system by visiting the page.
	 */
	public function test_forgotten_reads_in_the_briefs_words(): void {
		$this->refusals['sync'] = self::conflict( 'forgotten', 'This person asked to be deleted; they can be linked again once they write in' );
		$html = $this->view( $this->ada );
		$text = self::text( $html );
		$this->assertMatchesRegularExpression( '/deleted at your request/i', $text );
		$this->assertSame( array(), $this->portalCalls() );
		$this->assertDoesNotMatchRegularExpression( '/\b409\b|conflict|tombstone|linked again once/i', $text );
	}

	/**
	 * What it checks: A customer who changes their email in WordPress has the new address sent to OmnaBase, and the message centre asks them to
	 *   confirm again (Q5).
	 * Passes when: a confirmed Ada who used the message centre changes her address in her profile; once the outbox has run, OmnaBase has
	 *   received `PUT /customers/by-external/<her site id>` with the new address; her next view shows the prompt again and every portal call
	 *   still names her site id.
	 * Fails when: OmnaBase keeps writing to the old address's history without asking, or whoever took over an account and changed its address
	 *   keeps reading the previous owner's older conversations.
	 */
	public function test_a_changed_email_is_sent_and_the_prompt_returns(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->assertFalse( self::hasPrompt( $this->view( $this->ada ) ), 'confirmed before' );
		$this->calls = array();
		wp_update_user( array( 'ID' => $this->ada, 'user_email' => 'ada.new@example.com' ) );
		$this->makeDue();
		$this->runOutbox();
		$sent = array_map( static fn ( $c ) => self::bodyOf( $c )['email'] ?? null, $this->syncCalls( $this->ada ) );
		$this->assertContains( 'ada.new@example.com', $sent, 'the new address reached OmnaBase' );
		$html = $this->view( $this->ada );
		$this->assertTrue( self::hasPrompt( $html ), 'the prompt returns' );
		$this->assertSame( array( self::siteIdOf( $this->ada ) ), $this->namedVisitors(), 'still named by her site id' );
	}

	/**
	 * What it checks: Only users OmnaBase knows are sent when their address changes (choice 5: the plugin does not make every user a customer).
	 * Passes when: Ada (who used the message centre) and Carl (who never did and was never linked) both change their address; once the outbox
	 *   has run, Ada's new address reached OmnaBase and no `PUT` for Carl was made.
	 * Fails when: every profile edit on the site creates a customer in OmnaBase, filling the team's customer list with people who never asked
	 *   anything (or nobody's change is sent at all).
	 */
	public function test_only_known_users_changes_are_sent(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->view( $this->ada );
		$carl = $this->person( 'carl@example.com', 'Carl Unknown' );
		$this->calls = array();
		wp_update_user( array( 'ID' => $this->ada, 'user_email' => 'ada.other@example.com' ) );
		wp_update_user( array( 'ID' => $carl, 'user_email' => 'carl.new@example.com' ) );
		$this->makeDue();
		$this->runOutbox();
		$this->assertContains( 'ada.other@example.com', array_map( static fn ( $c ) => self::bodyOf( $c )['email'] ?? null, $this->syncCalls( $this->ada ) ), 'the known user\'s change' );
		$this->assertSame( array(), $this->syncCalls( $carl ), 'nothing for a user OmnaBase does not know' );
	}

	/**
	 * What it checks: A profile save that does not change the address sends nothing.
	 * Passes when: after Ada's first view (which tells OmnaBase about her), saving her profile with only a new biography leads, once the
	 *   outbox has run, to no `PUT`.
	 * Fails when: every profile save costs an API call and an outbox row.
	 */
	public function test_a_profile_save_without_a_new_address_sends_nothing(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->view( $this->ada );
		$this->assertNotEmpty( $this->syncCalls( $this->ada ), 'the first view told OmnaBase about Ada' );
		$this->calls = array();
		wp_update_user( array( 'ID' => $this->ada, 'description' => 'Designer in Vienna' ) );
		$this->makeDue();
		$this->runOutbox();
		$this->assertSame( array(), $this->syncCalls( $this->ada ) );
	}
}
