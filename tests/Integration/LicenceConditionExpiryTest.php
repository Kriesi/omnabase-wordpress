<?php
/**
 * The licence-and-fields round, piece A (the outside red team's failing tests, 2026-11-04), in a real WordPress: the Customer data tab's
 * licence card offers a condition and an expiry, saves them, shows a sample line, the facts call (OmnaBase's signed question about a
 * customer) answers the support end, and a change of the expiry's user field (user meta: a value WordPress keeps per user under a name)
 * queues the refresh call the way a change of the licence field does.
 *
 * Brief: OmnaBase `docs/briefs/licence-and-fields-round.md`, piece A, rules 1 to 5. kriesi.at keeps the Envato code in `av_purchase_code`
 * and the support end in `av_purchase_data`, ONE list per user `{ purchase_code, purchase_count, buyer, supported_until, sold_at, license,
 * source }`, so the expiry is written `av_purchase_data.supported_until`.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team), besides those in `UserMetaLicenceTest.php` and
 * `tests/Unit/LicenceConditionExpiryTest.php`:
 * - The licence card inside the tab's one save form carries `omnabase_facts[licence_meta][condition]` (a select whose options are `any`,
 *   `equals`, `contains`), `omnabase_facts[licence_meta][condition_text]` and `omnabase_facts[licence_meta][expiry]` (text fields).
 * - Under the card, one sample line in an element with the class `ob-wp-settings__licence-meta-sample`: the product's name, the made-up
 *   mask "••••-1a2b", then "active" without an expiry, or "expires <a day>" with one (the brief's "Enfold ••••-1a2b, expires 12 Mar 2027").
 * - The refresh hooks (WordPress's add, update and delete moments of a user field) watch the field part of the expiry and of a `field.key`
 *   licence field.
 *
 * Why these fail today: the card has two fields only, no sample line, the source knows no expiry and only the licence field's own name
 * queues a refresh.
 *
 * No `@group`: the site this is for runs no shop plugin, so these run in the plain WordPress (the integration runner's first run).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Sync\KnownUsers;

final class LicenceConditionExpiryTest extends RoundTwoTestCase {

	private const SOURCE = 'user_meta_licence';
	private const FIELD  = 'av_purchase_code';
	private const LIST   = 'av_purchase_data';
	private const EXPIRY = 'av_purchase_data.supported_until';

	/** An Envato purchase code's shape (36 characters, four dashes); made up. */
	private const CODE = '7c1e9a42-5b3d-4f0e-9a21-3c8d5e7f1a2b';

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/* ---------- the answer ---------- */

	/**
	 * What it checks: kriesi.at's case end to end: a member whose support ran out shows an expired Enfold licence with its day, one whose
	 *   support runs shows an active one, and nothing of the stored list leaves the site.
	 * Passes when: with the expiry `av_purchase_data.supported_until`, OmnaBase's question about Ada (support until 2020-02-21) answers status
	 *   "expired" with expiresAt "2020-02-21" and about Ben (until 2099-03-12) "active" with "2099-03-12"; OmnaBase's reader accepts both;
	 *   the buyer's name and the full code are in neither.
	 * Fails when: the team helps a member whose support lapsed as if it ran, sees no date at all, or the stored list's private parts leave.
	 */
	public function test_the_facts_call_answers_the_support_end(): void {
		$ada = $this->customer( 'ada@example.org' );
		$ben = $this->customer( 'ben@example.org', 'Ben Weber' );
		update_user_meta( $ada, self::FIELD, self::CODE );
		update_user_meta( $ada, self::LIST, self::purchaseData( '2020-02-21T16:53:06+11:00' ) );
		update_user_meta( $ben, self::FIELD, self::CODE );
		update_user_meta( $ben, self::LIST, self::purchaseData( '2099-03-12T05:25:07+11:00' ) );
		$this->configure( array( self::SOURCE ), array( 'expiry' => self::EXPIRY ) );

		$a = $this->askFacts( 'ada@example.org' );
		$b = $this->askFacts( 'ben@example.org' );

		$this->assertSame( 'expired', $a['answer']->omnabase->licences[0]->status ?? null, $a['raw'] );
		$this->assertSame( '2020-02-21', $a['answer']->omnabase->licences[0]->expiresAt ?? null, $a['raw'] );
		$this->assertSame( 'active', $b['answer']->omnabase->licences[0]->status ?? null, $b['raw'] );
		$this->assertSame( '2099-03-12', $b['answer']->omnabase->licences[0]->expiresAt ?? null, $b['raw'] );
		foreach ( array( $a['raw'], $b['raw'] ) as $raw ) {
			$this->assertStringNotContainsString( 'secret-buyer-7b3e', $raw );
			$this->assertStringNotContainsString( '7c1e9a42', $raw );
		}
	}

	/**
	 * What it checks: A condition on the value decides who holds a licence in the real facts call.
	 * Passes when: with "equals" "enfold-pro", Ada (value "Enfold-Pro") answers one licence and Ben (value "enfold-basic") an empty list.
	 * Fails when: every member with a value shows a licence although the site marks only some values as licensed.
	 */
	public function test_the_condition_decides_in_the_facts_call(): void {
		$ada = $this->customer( 'ada@example.org' );
		$ben = $this->customer( 'ben@example.org', 'Ben Weber' );
		update_user_meta( $ada, 'av_licence_kind', 'Enfold-Pro' );
		update_user_meta( $ben, 'av_licence_kind', 'enfold-basic' );
		$this->configure( array( self::SOURCE ), array( 'condition' => 'equals', 'condition_text' => 'enfold-pro' ), 'av_licence_kind' );

		$this->assertCount( 1, (array) ( $this->blockFor( 'ada@example.org' )->licences ?? array() ) );
		$this->assertSame( array(), $this->blockFor( 'ben@example.org' )->licences ?? null );
	}

	/* ---------- freshness ---------- */

	/**
	 * What it checks: A renewed support period reaches OmnaBase on its own: a change of the expiry's user field queues the refresh call, like
	 *   a change of the licence field does.
	 * Passes when: for a member OmnaBase knows, adding, updating and deleting `av_purchase_data` each queue one licence refresh, while the
	 *   expiry is `av_purchase_data.supported_until`.
	 * Fails when: a member who extended their support still shows "expired" in OmnaBase until someone opens their ticket.
	 */
	public function test_a_change_of_the_expiry_field_queues_a_refresh(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, self::FIELD, self::CODE );
		$this->configure( array( self::SOURCE ), array( 'expiry' => self::EXPIRY ) );
		( new KnownUsers() )->mark( $ada );

		$changes = array(
			'added'   => fn () => add_user_meta( $ada, self::LIST, self::purchaseData( '2026-01-01T00:00:00+00:00' ) ),
			'updated' => fn () => update_user_meta( $ada, self::LIST, self::purchaseData( '2027-01-01T00:00:00+00:00' ) ),
			'deleted' => fn () => delete_user_meta( $ada, self::LIST ),
		);
		foreach ( $changes as $what => $change ) {
			$this->clearOutbox();
			$change();
			$this->assertCount( 1, $this->outboxRows( 'licence_refresh' ), "the expiry's field was {$what}" );
		}
	}

	/**
	 * What it checks: A licence field written `field.key` is watched by its field's name.
	 * Passes when: the licence field is `av_purchase_data.purchase_code`; updating `av_purchase_data` for a known member queues one refresh.
	 * Fails when: a direct-shop buyer's new purchase never reaches OmnaBase, because the hook compares the whole `field.key` text with the
	 *   field's name and never matches.
	 */
	public function test_a_list_key_licence_field_is_watched_by_its_field(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->configure( array( self::SOURCE ), array(), 'av_purchase_data.purchase_code' );
		( new KnownUsers() )->mark( $ada );

		$this->clearOutbox();
		update_user_meta( $ada, self::LIST, self::purchaseData( '2099-03-12T05:25:07+11:00' ) );
		$this->assertCount( 1, $this->outboxRows( 'licence_refresh' ) );
	}

	/**
	 * What it checks: (guard) Only the fields that matter queue a refresh: not another field, not the expiry's field while the source is off.
	 * Passes when: with the expiry set, changing `description` queues nothing; with the source switched off, changing `av_purchase_data`
	 *   queues nothing.
	 * Fails when: every profile save on the forum sends a call to OmnaBase, or a switched-off source still talks to OmnaBase.
	 */
	public function test_guard_other_fields_and_a_switched_off_source_queue_nothing(): void {
		$ada = $this->customer( 'ada@example.org' );
		( new KnownUsers() )->mark( $ada );
		$this->configure( array( self::SOURCE ), array( 'expiry' => self::EXPIRY ) );

		$this->clearOutbox();
		update_user_meta( $ada, 'description', 'Loves Enfold' );
		$this->assertSame( array(), $this->outboxRows( 'licence_refresh' ), 'another field' );

		$this->configure( array( 'profile' ), array( 'expiry' => self::EXPIRY ) );
		update_user_meta( $ada, self::LIST, self::purchaseData( '2099-03-12T05:25:07+11:00' ) );
		$this->assertSame( array(), $this->outboxRows( 'licence_refresh' ), 'the source is off' );
	}

	/* ---------- the Customer data tab ---------- */

	/**
	 * What it checks: The licence card offers the condition and the expiry, inside the tab's one save form, in short plain words.
	 * Passes when: the form has a select `omnabase_facts[licence_meta][condition]` offering exactly "any", "equals" and "contains" (with "any"
	 *   chosen on a fresh site), a text field for the condition's text and one for the expiry; no help line on the tab has more than 30 words
	 *   or starts with "On,"; the tab's words hold no banned developer words.
	 * Fails when: the administrator cannot set a condition or an expiry, or the card's copy breaks the settings-copy rules.
	 */
	public function test_the_card_offers_the_condition_and_the_expiry(): void {
		$html = $this->openTab( 'data' );
		$form = $this->factsForm( $html );

		$options = array();
		foreach ( self::query( $html, "//form//select[@name='omnabase_facts[licence_meta][condition]']/option" ) as $option ) {
			$options[] = $option instanceof \DOMElement ? $option->getAttribute( 'value' ) : '';
		}
		$this->assertSame( array( 'any', 'equals', 'contains' ), $options, 'the condition offers its three choices' );
		$this->assertSame( 'any', $form['fields']['omnabase_facts']['licence_meta']['condition'] ?? null, '"any value" on a fresh site' );
		foreach ( array( 'omnabase_facts[licence_meta][condition_text]', 'omnabase_facts[licence_meta][expiry]' ) as $name ) {
			$this->assertSame( 1, self::query( $html, "//form//input[@name='{$name}']" )->length, $name );
		}
		$this->assertSame( array(), self::bannedIn( self::visibleText( $html ) ) );
		foreach ( self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__licence-meta' ) . ']//*[' . self::hasClass( 'ob-form-row__help' ) . ' or ' . self::hasClass( 'ob-form__intro' ) . ']' ) as $node ) {
			$words = self::wordsOf( $node );
			$this->assertLessThanOrEqual( 30, str_word_count( $words ), $words );
			$this->assertStringStartsNotWith( 'On,', trim( $words ), $words );
		}
	}

	/**
	 * What it checks: Saving the tab keeps the condition, its text and the expiry, and the card's sample line shows the status a licence gets.
	 * Passes when: posting the tab's own form with "contains", "Pro" and `av_purchase_data.supported_until` stores the three in the facts
	 *   option; the sample line then names "Enfold", "••••-1a2b" and "expires"; after saving without an expiry it names "active" and not
	 *   "expires".
	 * Fails when: the administrator's choices are lost on save, or the card shows no example of what the team will see.
	 */
	public function test_saving_keeps_the_settings_and_the_sample_follows(): void {
		$this->saveTab( array( 'key' => self::FIELD, 'product' => 'Enfold', 'condition' => 'contains', 'condition_text' => 'Pro', 'expiry' => self::EXPIRY ) );
		$saved = (array) ( ( (array) get_option( self::FACTS_OPTION ) )['licence_meta'] ?? array() );
		$this->assertSame( array( 'contains', 'Pro', self::EXPIRY ), array( $saved['condition'] ?? null, $saved['condition_text'] ?? null, $saved['expiry'] ?? null ) );

		$sample = $this->sampleLine();
		foreach ( array( 'Enfold', '••••-1a2b', 'expires' ) as $part ) {
			$this->assertStringContainsString( $part, $sample, $part );
		}

		$this->saveTab( array( 'key' => self::FIELD, 'product' => 'Enfold', 'condition' => 'any', 'condition_text' => '', 'expiry' => '' ) );
		$sample = $this->sampleLine();
		$this->assertStringContainsString( 'active', $sample );
		$this->assertStringNotContainsString( 'expires', $sample );
	}

	/* ---------- helpers ---------- */

	/** The card's one sample line, its words. */
	private function sampleLine(): string {
		$nodes = self::query( $this->openTab( 'data' ), '//*[' . self::hasClass( 'ob-wp-settings__licence-meta-sample' ) . ']' );
		$this->assertSame( 1, $nodes->length, 'one sample line under the licence card' );
		return self::wordsOf( $nodes->item( 0 ) );
	}

	/**
	 * kriesi.at's list, in the key order its shop writes it.
	 *
	 * @param string $until The support end as stored.
	 * @return array<string,mixed>
	 */
	private static function purchaseData( string $until ): array {
		return array(
			'purchase_code'   => self::CODE,
			'purchase_count'  => 1,
			'buyer'           => 'secret-buyer-7b3e',
			'supported_until' => $until,
			'sold_at'         => '2019-02-21T16:53:06+11:00',
			'license'         => 'Regular License',
			'source'          => 'envato',
		);
	}

	/**
	 * Stores the switches (these sources on, every other off) and the licence settings.
	 *
	 * @param string[]            $sources The sources to turn on.
	 * @param array<string,mixed> $more    condition, condition_text, expiry.
	 * @param string              $field   The licence field's name.
	 */
	private function configure( array $sources, array $more = array(), string $field = self::FIELD ): void {
		$all = array_merge( self::ALL_SOURCES, array( self::SOURCE ) );
		update_option(
			self::FACTS_OPTION,
			array(
				'sources'      => array_combine( $all, array_map( static fn ( $s ) => in_array( $s, $sources, true ), $all ) ),
				'meta'         => array(),
				'licence_meta' => array( 'key' => $field, 'product' => 'Enfold' ) + $more,
			)
		);
	}

	/** The tab's save form (`omnabase_save_facts`), as the browser would send it. */
	private function factsForm( string $html ): array {
		foreach ( self::formsIn( $html ) as $form ) {
			if ( 'omnabase_save_facts' === $form['action'] ) {
				return $form;
			}
		}
		$this->fail( 'the Customer data tab has its save form' );
	}

	/**
	 * Posts the tab's own form with the source on and the licence card filled in.
	 *
	 * @param array<string,string> $licence The card's fields.
	 */
	private function saveTab( array $licence ): void {
		$fields = $this->factsForm( $this->openTab( 'data' ) )['fields'];
		$fields['omnabase_facts']['sources']      = array_values( array_unique( array_merge( (array) ( $fields['omnabase_facts']['sources'] ?? array() ), array( self::SOURCE ) ) ) );
		$fields['omnabase_facts']['licence_meta'] = $licence;
		$this->tabIn( $this->postFields( $fields ) );
	}
}
