<?php
/**
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the outbox moves to the Activity tab and the health lines to the
 * Connection tab's status block (brief `docs/briefs/m14-settings-cleanup.md`, choice 1; review S1). The two "Send now" tests read the outbox
 * from `screen( 'activity' )`; the facts-call test keeps reading `.ob-wp-settings__health` on the Connection tab (where the line now sits
 * inside the status block's Details, still in the page). The assertions are unchanged.
 *
 * M13 round six, the red team's failing tests: the leftovers the earlier rounds handed to round six, one small test each (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 2: "**the outbox** (waiting, needing attention, sent today; the next
 * try and the reason in words; 'Send now', 'Retry', 'Discard')"; choice 3, health: "the last event and facts call received; warnings for
 * disabled WP-Cron …". The plugin's DECISIONS: "M13 round two", "Open for round three": "'Send now' on the outbox; … health lines for the facts
 * calls and a disabled WP-Cron; 'unsupported version' checks per shop; an idempotency key column when topics join the outbox"; "M13 round
 * three/four" carry them on; "M13 round five", "Left for round six": "a refused New ticket keeps nothing of what was typed (the customer writes
 * it again)". The coordinator: "the leftovers as the brief/DECISIONS assign them (each as one small test)".
 *
 * Not here: the idempotency key column (built in round two: every outbox row carries its own key, `Outbox\Table`); the disabled WP-Cron
 * warning (a constant in wp-config.php, which a test inside one PHP process cannot set and unset: `tests/e2e/cron-health.spec.ts`); the
 * "unsupported version" lines (they need the shops: `ShopVersionsTest`).
 *
 * The words: *outbox*, the plugin's lasting list of calls still to make; *waiting row*, a call the outbox will try again at its next time;
 * *facts call*, OmnaBase's signed question "who is this customer?" that the site answers (round two); *health*, the settings screen's section
 * that says whether the connection works; *nonce*, a short-lived form token against forged requests.
 *
 * Shapes pinned (`RoundSixTestCase`): "Send now" is a button with those words in a form inside the outbox section, for a waiting row; the
 * facts line is a line of the health section naming customer data (or facts) and, once a call arrived, today's date (`Y-m-d`, as the screen
 * writes its other times) or "… ago"; the kept words come back in the New ticket form's `subject` input and its text box.
 *
 * Why these fail today: no "Send now", no facts line in health, and the New ticket form comes back empty after a refusal.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class RoundSixLeftoversTest extends RoundSixTestCase {

	/** A form message whose first try met no OmnaBase: one waiting row, its next try a minute away. */
	private function waitingFormRow(): array {
		$this->omnabaseAnswersSubmissions( new \WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect to app.omnabase.com' ) );
		$this->sendFrom( self::VISITOR_IP );
		$rows = $this->formRows();
		$this->assertCount( 1, $rows, 'one waiting row about a form' );
		$this->assertSame( 'waiting', $rows[0]['state'] );
		$this->assertGreaterThan( time(), self::nextAt( $rows[0] ), 'its next try lies ahead' );
		$this->omnabaseTakesSubmissions();
		return $rows[0];
	}

	/**
	 * What it checks: A waiting call can be sent straight away from the outbox section instead of waiting for its next try.
	 * Passes when: the outbox section shows "Send now" for the waiting row; sending that form (and the outbox's run WordPress starts for it)
	 *   makes the call once more at once and the row reads sent.
	 * Fails when: an owner who just fixed an outage must wait for the next try, or "Send now" sends the message twice.
	 */
	public function test_send_now_sends_a_waiting_row_at_once(): void {
		$this->waitingFormRow();
		$form = self::formWithButton( self::section( $this->screen( 'activity' ), 'ob-wp-settings__outbox' ), 'Send now' );
		$this->assertNotNull( $form, '"Send now" for the waiting row' );
		$this->calls = array();

		$this->postAdmin( $form );
		$this->runOutbox();

		$this->assertCount( 1, $this->submissionCalls(), 'sent once, now' );
		$this->assertSame( 'sent', $this->formRows()[0]['state'] ?? null );
	}

	/**
	 * What it checks: "Send now" needs the form's token.
	 * Passes when: the same form without its nonce leaves the row waiting with its next try ahead, and no call goes on the outbox's run.
	 * Fails when: another site makes an administrator's browser push the outbox, past OmnaBase's waiting times.
	 */
	public function test_send_now_needs_the_forms_token(): void {
		$this->waitingFormRow();
		$form = self::formWithButton( self::section( $this->screen( 'activity' ), 'ob-wp-settings__outbox' ), 'Send now' );
		$this->assertNotNull( $form, '"Send now" for the waiting row' );
		$this->calls = array();

		$this->postAdmin( $form, false );
		$this->runOutbox();

		$this->assertCount( 0, $this->submissionCalls() );
		$row = $this->formRows()[0];
		$this->assertSame( 'waiting', $row['state'] );
		$this->assertGreaterThan( time(), self::nextAt( $row ) );
	}

	/**
	 * What it checks: Health says when OmnaBase last asked the site about a customer.
	 * Passes when: the health section has a line about customer data (or facts) calls; after one facts call arrives, that line holds today's
	 *   date (or "… ago").
	 * Fails when: an owner whose customer cards stay empty cannot tell whether OmnaBase reaches the site at all.
	 */
	public function test_health_names_the_last_facts_call(): void {
		$before = array_values( array_filter( self::lines( self::section( $this->screen(), 'ob-wp-settings__health' ) ), static fn ( $l ) => (bool) preg_match( '/customer[ -]data|facts/i', $l ) ) );
		$this->assertNotEmpty( $before, 'a health line about customer-data calls' );

		$this->switchOn( array( 'profile' ) );
		$this->assertSame( 200, $this->askFacts( self::ADA_EMAIL )['status'] );

		$after = array_values( array_filter( self::lines( self::section( $this->screen(), 'ob-wp-settings__health' ) ), static fn ( $l ) => (bool) preg_match( '/customer[ -]data|facts/i', $l ) ) );
		$today = wp_date( 'Y-m-d' );
		$this->assertNotEmpty( array_filter( $after, static fn ( $l ) => str_contains( $l, $today ) || (bool) preg_match( '/\bago\b|just now/i', $l ) ), 'the line names the call\'s time: ' . implode( ' | ', $after ) );
	}

	/**
	 * What it checks: When OmnaBase refuses a new question, the customer finds their words still in the form, safely, and only they do.
	 * Passes when: after a refused New ticket (OmnaBase's 429) the page's New ticket form holds the typed subject and message (the message's
	 *   markup shown as text, never run); the redirect's address carries none of the words; Ben's view of the page holds neither; after a
	 *   successful New ticket the next form is empty.
	 * Fails when: a customer must write a long question again, another customer sees it, it travels in an address bar or a log, or a message
	 *   with markup runs script in the page.
	 */
	public function test_a_refused_new_ticket_keeps_what_was_typed(): void {
		$this->omnabaseKnows( $this->ada, true );
		$this->omnabaseKnows( $this->ben, true );
		$subject = 'Kept subject six';
		$message = 'Kept words of a long question </textarea><script>alert(1)</script>';
		$this->refusals['create'] = self::refusal( 429, 'rate_limited', 'Today\'s allowance of tickets from connected sites is used', array( 'retry-after' => '3600' ) );

		$sent = $this->send( $this->ada, $this->view( $this->ada ), self::newTicketForm(), array( 'subject' => $subject, 'textarea' => $message ) );
		$this->assertNotNull( $sent['location'], 'redirected back' );
		$this->assertStringNotContainsString( 'Kept', rawurldecode( (string) $sent['location'] ), 'no words in the address' );
		$this->assertCount( 0, self::nodes( $sent['html'], '//script[contains(.,"alert(1)")]' ), 'the markup is never run' );
		$form = self::formIn( $sent['html'], self::newTicketForm() );
		$this->assertNotNull( $form, 'the New ticket form is back' );
		$this->assertSame( $subject, $form['fields']['subject'] ?? null, 'the subject kept' );
		$area = self::nodes( $sent['html'], '//form[' . self::newTicketForm() . ']//textarea' )->item( 0 );
		$this->assertSame( $message, (string) $area->textContent, 'the message kept, as text' );

		$this->assertStringNotContainsString( 'Kept', $this->view( $this->ben ), 'Ben sees none of it' );

		unset( $this->refusals['create'] );
		$done = $this->send( $this->ada, $this->view( $this->ada ), self::newTicketForm(), array( 'subject' => 'A new one', 'textarea' => 'Fine now.' ) );
		$again = self::formIn( $done['html'], self::newTicketForm() );
		$this->assertNotNull( $again );
		$this->assertSame( '', (string) ( $again['fields']['subject'] ?? '' ), 'after a success the form is empty' );
		$this->assertStringNotContainsString( 'Kept words', $done['html'] );
	}
}
