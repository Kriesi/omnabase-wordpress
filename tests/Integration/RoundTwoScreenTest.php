<?php
/**
 * M13 round two, the red team's failing tests: Settings › OmnaBase gains the customer-data section (a switch per source, the usermeta
 * picker) and the outbox section (what waits, what needs attention, Retry and Discard).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 2: "the facts switches per source and the usermeta picker; … **the
 * outbox** (waiting, needing attention, sent today; the next try and the reason in words; 'Send now', 'Retry', 'Discard')"; choice 5:
 * "Sources, each behind a switch, all off until the admin turns them on (the screen offers 'Turn on' for each one it detects …); keys
 * starting with `_` hidden from the picker by default"; the plugin's `CLAUDE.md`: "Every description on the settings screen … is at most **30
 * words**", "a nonce and `current_user_can( 'manage_options' )` on every admin action". The coordinator: "the settings screen's new section
 * (switches, the meta allow-list field, the outbox state)"; "a dead-letter state after N days shown on the screen with 'Retry'". The words:
 * **usermeta**, extra fields WordPress keeps per user; **nonce**, a short-lived form token against forged requests.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team), besides `RoundTwoTestCase`'s:
 * - The customer-data section is `.ob-wp-settings__facts`: a form to `admin-post.php` with the action `omnabase_save_facts` (its nonce of the
 *   same name), a checkbox per source `omnabase_facts[sources][]` (value: the source) and per offered usermeta key `omnabase_facts[meta][]`.
 * - The outbox section is `.ob-wp-settings__outbox`; a row needing attention shows its reason and two small forms, actions
 *   `omnabase_outbox_retry` and `omnabase_outbox_discard` (nonces of the same names), each with the row's id in `row`.
 *
 * Why these fail today: the screen has neither section and none of the three actions exists.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * The customer-data tests need the shops: bin/test-integration.sh runs them in its second run.
 *
 * @group shops
 */
final class RoundTwoScreenTest extends RoundTwoTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/**
	 * Posts an admin form with fields.
	 *
	 * @param string $action The admin-post action (also the nonce's name).
	 * @param array  $fields The form's fields.
	 * @param bool   $nonce  Whether the nonce is sent.
	 * @return string|null Where the handler redirected, or null.
	 */
	private function submit( string $action, array $fields, bool $nonce = true ): ?string {
		$_POST    = array( 'action' => $action ) + $fields + ( $nonce ? array( '_wpnonce' => wp_create_nonce( $action ) ) : array() );
		$_REQUEST = $_POST;
		try {
			do_action( 'admin_post_' . $action );
		} catch ( Redirected $r ) {
			return $r->location;
		} catch ( \WPDieException $e ) {
			return null;
		} finally {
			$_POST    = array();
			$_REQUEST = array();
		}
		return null;
	}

	/** The values of the inputs with a name, in some HTML. */
	private static function values( string $html, string $xpath ): array {
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>' );
		libxml_clear_errors();
		$out = array();
		foreach ( ( new \DOMXPath( $doc ) )->query( $xpath ) as $node ) {
			$out[] = $node instanceof \DOMElement ? $node->getAttribute( 'value' ) : (string) $node->nodeValue;
		}
		return $out;
	}

	/**
	 * What it checks: The screen offers a switch for every customer-data source it finds on the site, each off until the administrator turns
	 *   it on.
	 * Passes when: the customer-data section has one checkbox for each of profile, WooCommerce, WooCommerce Subscriptions, EDD, EDD Recurring,
	 *   EDD Software Licensing and License Manager for WooCommerce (all present here), and none is checked.
	 * Fails when: an administrator cannot choose what is shared, or a source starts out shared without their say.
	 */
	public function test_a_switch_per_detected_source_all_off(): void {
		$html = $this->renderScreen();

		$offered = self::values( $html, '//*[contains(@class,"ob-wp-settings__facts")]//input[@type="checkbox" and @name="omnabase_facts[sources][]"]' );
		$checked = self::values( $html, '//*[contains(@class,"ob-wp-settings__facts")]//input[@type="checkbox" and @name="omnabase_facts[sources][]" and @checked]' );
		$this->assertEqualsCanonicalizing( self::ALL_SOURCES, $offered );
		$this->assertSame( array(), $checked );
	}

	/**
	 * What it checks: Saving the section turns on exactly the ticked sources and keeps the ticked usermeta, for an administrator with the
	 *   form's token only.
	 * Passes when: posting WooCommerce, License Manager and billing_country stores those (every other source off); the same post without the
	 *   token, or by a subscriber, changes nothing.
	 * Fails when: a forged link turns on sharing of customer data, or the screen saves something other than what was ticked.
	 */
	public function test_saving_turns_on_exactly_the_ticked_sources(): void {
		$fields = array( 'omnabase_facts' => array( 'sources' => array( 'woocommerce', 'lmfw' ), 'meta' => array( 'billing_country' ) ) );

		$this->submit( 'omnabase_save_facts', $fields, false );
		$this->assertFalse( get_option( self::FACTS_OPTION ), 'no token, nothing stored' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->submit( 'omnabase_save_facts', $fields );
		$this->assertFalse( get_option( self::FACTS_OPTION ), 'a subscriber stores nothing' );
		wp_set_current_user( $this->admin );
		$this->submit( 'omnabase_save_facts', $fields );

		$saved = (array) get_option( self::FACTS_OPTION );
		foreach ( self::ALL_SOURCES as $source ) {
			$this->assertSame( in_array( $source, array( 'woocommerce', 'lmfw' ), true ), (bool) ( $saved['sources'][ $source ] ?? false ), $source );
		}
		$this->assertSame( array( 'billing_country' ), array_values( (array) ( $saved['meta'] ?? array() ) ) );
	}

	/**
	 * What it checks: The usermeta picker offers the site's ordinary usermeta keys and hides private and secret-looking ones.
	 * Passes when: with billing_country, _private_flag, session_tokens and api_token stored for a customer, the picker offers billing_country
	 *   and none of the other three.
	 * Fails when: the picker invites an administrator to share login sessions or tokens with a tick.
	 */
	public function test_the_meta_picker_hides_private_and_banned_keys(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'billing_country', 'AT' );
		update_user_meta( $ada, '_private_flag', '1' );
		update_user_meta( $ada, 'session_tokens', array( 'x' => array() ) );
		update_user_meta( $ada, 'api_token', 'tok' );

		$offered = self::values( $this->renderScreen(), '//*[contains(@class,"ob-wp-settings__facts")]//input[@name="omnabase_facts[meta][]"]' );

		$this->assertContains( 'billing_country', $offered );
		foreach ( array( '_private_flag', 'session_tokens', 'api_token' ) as $hidden ) {
			$this->assertNotContains( $hidden, $offered, $hidden );
		}
	}

	/**
	 * What it checks: The outbox section shows what waits and what needs the administrator, with the reason in words, and Retry brings a row
	 *   back.
	 * Passes when: a refresh OmnaBase refused (400) shows in the outbox section with its reason and a Retry form for that row; posting Retry with
	 *   the token makes the row wait again, due now.
	 * Fails when: a stuck call is invisible, so the admin never learns that customer data stopped reaching OmnaBase, or cannot send it again.
	 */
	public function test_a_row_needing_attention_shows_with_its_reason_and_retry(): void {
		$row  = $this->rowNeedingAttention();
		$html = $this->renderScreen();

		// Builder's adjustment (DECISIONS "Round two"): `values()` reads an element's `value` attribute, so the section's text is read
		// through its text nodes (the element itself answered blank strings, and the reason could never be found in them).
		$section = self::values( $html, '//*[contains(@class,"ob-wp-settings__outbox")]//text()' );
		$this->assertNotEmpty( $section, 'the outbox section' );
		$this->assertStringContainsString( (string) $row['last_error'], html_entity_decode( implode( ' ', $section ) ), 'the reason in words' );
		$ids = self::values( $html, '//form[.//input[@name="action" and @value="omnabase_outbox_retry"]]//input[@name="row"]' );
		$this->assertSame( array( (string) $row['id'] ), $ids );

		$this->submit( 'omnabase_outbox_retry', array( 'row' => (string) $row['id'] ) );

		$after = $this->outboxRows( 'licence_refresh' )[0];
		$this->assertSame( 'waiting', $after['state'] );
		$this->assertLessThanOrEqual( time() + 5, self::nextAt( $after ) );
	}

	/**
	 * What it checks: Discard removes a stuck row from the outbox only when the administrator asks, with the form's token.
	 * Passes when: Discard without the token leaves the row needing attention; with it the row no longer counts as waiting or needing
	 *   attention.
	 * Fails when: a forged link empties the outbox, or a hopeless row can never be cleared.
	 */
	public function test_discard_needs_the_token(): void {
		$row = $this->rowNeedingAttention();

		$this->submit( 'omnabase_outbox_discard', array( 'row' => (string) $row['id'] ), false );
		$this->assertSame( 'attention', $this->outboxRows( 'licence_refresh' )[0]['state'] );
		$this->submit( 'omnabase_outbox_discard', array( 'row' => (string) $row['id'] ) );

		$left = array_filter( $this->outboxRows(), static fn ( $r ) => in_array( $r['state'], array( 'waiting', 'attention' ), true ) );
		$this->assertSame( array(), array_values( $left ) );
	}

	/**
	 * What it checks: The new sections' help texts follow the screen's rule of at most 30 words each.
	 * Passes when: every description inside the customer-data and outbox sections has 30 words or fewer.
	 * Fails when: the screen grows paragraphs an administrator will not read.
	 */
	public function test_the_new_descriptions_have_at_most_30_words(): void {
		$this->rowNeedingAttention();
		// Builder's adjustment (DECISIONS "Round two"): each description's text, through its text nodes (`values()` read the elements'
		// blank `value` attributes, so every count was zero and the check could not fail).
		$texts = self::values( $this->renderScreen(), '//*[contains(@class,"ob-wp-settings__facts") or contains(@class,"ob-wp-settings__outbox")]//*[contains(concat(" ",@class," ")," description ")]/text()' );

		$this->assertNotEmpty( $texts, 'each section has its one-line description' );
		foreach ( $texts as $text ) {
			$this->assertLessThanOrEqual( 30, str_word_count( trim( (string) preg_replace( '/\s+/', ' ', $text ) ) ), $text );
		}
	}

	/** A licence refresh OmnaBase refused with 400: a row needing attention, made the real way. */
	private function rowNeedingAttention(): array {
		$ada = $this->customer( 'ada@example.org' );
		$this->linkToOmnaBase( $ada );
		$this->switchOn( self::ALL_SOURCES );
		$id = rawurlencode( self::siteIdOf( $ada ) );
		$this->answers[ "POST /customers/by-external/{$id}/refresh" ] = self::answer( 400, array( 'error' => array( 'code' => 'bad_request', 'message' => 'The request did not match its schema' ) ) );
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'processing' )->update_status( 'completed' );
		$this->makeDue();
		$this->runOutbox();
		$row = $this->outboxRows( 'licence_refresh' )[0] ?? array();
		$this->assertSame( 'attention', $row['state'] ?? null );
		return $row;
	}
}
