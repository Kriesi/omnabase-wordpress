<?php
/**
 * The licence-sources round, part one (the red team's failing tests, 2026-11-03), in a real WordPress: a connected site with no shop plugin
 * (kriesi.at's forum) answers OmnaBase's customer question (the signed facts call) with the licence it keeps in a user field (user meta: a
 * value WordPress keeps per user under a name), tells OmnaBase when that field changes, and offers the source on the Customer data tab.
 *
 * Brief: OmnaBase `docs/briefs/licence-sources-round.md`, part one, rules 1 to 8 (Q1: one field, "active" whenever the value is non-empty;
 * Q4: the masked key keeps the last four characters). kriesi.at keeps the Envato purchase code in `av_purchase_code` (its `avia-verifier.php`
 * and `avia-expiring-support.php`), which holds "purchase" and "code", two of the user-field rule's banned words: the case the policy
 * exception (rule 3) exists for.
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team), besides `RoundTwoTestCase`'s and those in
 * `tests/Unit/UserMetaLicenceSourceTest.php`:
 * - The source's id `user_meta_licence`; its settings in the facts option, `omnabase_facts[ 'licence_meta' ] = [ 'key', 'product' ]`.
 * - The Customer data tab's save form (`omnabase_save_facts`) carries the source's switch (`omnabase_facts[sources][]`, value
 *   `user_meta_licence`) and two text fields, `omnabase_facts[licence_meta][key]` and `omnabase_facts[licence_meta][product]`.
 * - (chosen) A field name no user holds yet is stored, and the tab says so beside the field in an element with the class
 *   `ob-wp-settings__licence-meta-unused`.
 * - A change of the field (added, updated, deleted) for a user OmnaBase knows queues one `licence_refresh` row, the same call the shop events
 *   queue; nothing for anyone else, another field, or with the source off.
 *
 * Why these fail today: no source reads a user field as a licence, the tab has no such settings and no user-field change queues a refresh.
 * The two tests marked "(guard)" pass today and must keep passing: nothing is sent or queued while the source is off or the change does
 * not matter.
 *
 * No `@group`: the site this source is for runs no shop plugin, so these tests run in the plain WordPress (the integration runner's first run).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Sync\KnownUsers;

final class UserMetaLicenceTest extends RoundTwoTestCase {

	private const SOURCE = 'user_meta_licence';
	private const FIELD  = 'av_purchase_code';

	/** An Envato purchase code's shape (36 characters, four dashes); made up. */
	private const CODE = '7c1e9a42-5b3d-4f0e-9a21-3c8d5e7f1a2b';

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/* ---------- the answer ---------- */

	/**
	 * What it checks: A forum member with a verified purchase code shows one Enfold licence in OmnaBase, masked, and the code itself never
	 *   leaves the site.
	 * Passes when: with the source on for `av_purchase_code` and "Enfold", OmnaBase's question about Ada answers a licences list of one:
	 *   product "Enfold", key "••••-1a2b", status "active"; OmnaBase's reader accepts the answer; the full code is nowhere in it.
	 * Fails when: the team sees "no licence" for a member who verified their purchase, or the full purchase code reaches OmnaBase.
	 */
	public function test_a_member_with_a_code_answers_one_masked_licence(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, self::FIELD, self::CODE );
		$this->configure( array( self::SOURCE ) );

		$block = $this->blockFor( 'ada@example.org' );
		$raw   = $this->askFacts( 'ada@example.org' )['raw'];

		$this->assertCount( 1, (array) ( $block->licences ?? array() ), $raw );
		$this->assertSame( 'Enfold', $block->licences[0]->product ?? null );
		$this->assertSame( '••••-1a2b', $block->licences[0]->keyMasked ?? null );
		$this->assertSame( 'active', $block->licences[0]->status ?? null );
		$this->assertStringNotContainsString( self::CODE, $raw );
		$this->assertStringNotContainsString( '7c1e9a42', $raw );
	}

	/**
	 * What it checks: A forum ticket names the member by the site's own id, so the licence is the right person's even when they write from
	 *   another address.
	 * Passes when: OmnaBase asks about "ada.work@elsewhere.example" with Ada's site id; the answer carries Ada's licence.
	 * Fails when: a member writing from a work address shows "no licence", or the licence is looked up by the address alone.
	 */
	public function test_the_site_id_finds_the_member_whatever_the_address(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, self::FIELD, self::CODE );
		$this->configure( array( self::SOURCE ) );

		$block = $this->blockFor( 'ada.work@elsewhere.example', self::siteIdOf( $ada ) );

		$this->assertSame( '••••-1a2b', $block->licences[0]->keyMasked ?? null );
	}

	/**
	 * What it checks: A member without a code says "no licence" (an empty list), and a guest gets nothing at all.
	 * Passes when: Ben (an account, no code) answers an empty licences list; an address nobody on the site uses answers `{}`.
	 * Fails when: an empty field reads as a licence, a removed code keeps its licence in OmnaBase, or a guest's ticket shows a licence.
	 */
	public function test_no_code_answers_an_empty_list_and_a_guest_nothing(): void {
		$this->customer( 'ben@example.org', 'Ben Weber' );
		$this->configure( array( self::SOURCE ) );

		$ben = $this->askFacts( 'ben@example.org' );
		$this->assertSame( 200, $ben['status'] );
		$this->assertIsObject( $ben['answer']->omnabase ?? null, 'the answer carries the block: ' . $ben['raw'] );
		$this->assertObjectHasProperty( 'licences', $ben['answer']->omnabase, 'an empty list, not a missing one: ' . $ben['raw'] );
		$this->assertSame( array(), $ben['answer']->omnabase->licences, $ben['raw'] );

		$guest = $this->askFacts( 'stranger@example.org' );
		$this->assertSame( 200, $guest['status'] );
		$this->assertSame( '{}', $guest['raw'] );
	}

	/**
	 * What it checks: (guard) With the source switched off, the code stays on the site.
	 * Passes when: the field and product are filled in but only the profile is on; the answer has no licences and no trace of the code.
	 * Fails when: a source the administrator left off still sends licences.
	 */
	public function test_the_switch_off_answers_no_licence(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, self::FIELD, self::CODE );
		$this->configure( array( 'profile' ) );

		$a = $this->askFacts( 'ada@example.org' );

		$this->assertSame( 200, $a['status'] );
		$this->assertObjectNotHasProperty( 'licences', $a['answer']->omnabase ?? new \stdClass(), $a['raw'] );
		$this->assertStringNotContainsString( '1a2b', $a['raw'] );
	}

	/**
	 * What it checks: The licence field is never sent as an ordinary user field, even with a harmless-looking name that the administrator also
	 *   ticked among the user fields.
	 * Passes when: the licence field is `enfold_buyer_ref` and it is ticked as a user field too; the answer's profile has no such field, the
	 *   full value is nowhere in the answer, and the masked licence is there.
	 * Fails when: the full code travels beside the masked one through the profile.
	 */
	public function test_the_licence_field_is_never_profile_meta(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'enfold_buyer_ref', self::CODE );
		$this->configure( array( 'profile', self::SOURCE ), array( 'enfold_buyer_ref' ), 'enfold_buyer_ref' );

		$a     = $this->askFacts( 'ada@example.org' );
		$block = $this->blockFor( 'ada@example.org' );

		$this->assertArrayNotHasKey( 'enfold_buyer_ref', (array) ( $block->profile->meta ?? array() ) );
		$this->assertStringNotContainsString( self::CODE, $a['raw'] );
		$this->assertSame( '••••-1a2b', $block->licences[0]->keyMasked ?? null );
	}

	/* ---------- freshness ---------- */

	/**
	 * What it checks: A member entering a new code (or losing theirs) reaches OmnaBase without waiting for the next ticket, the way shop
	 *   events do.
	 * Passes when: for a member OmnaBase knows, adding the field, changing it and deleting it each queue one licence refresh (two changes in a
	 *   row still one waiting row), and the outbox then sends one refresh call for them.
	 * Fails when: a renewed code shows the old licence in OmnaBase until someone happens to open the ticket, or each change floods OmnaBase.
	 */
	public function test_a_change_of_the_field_queues_one_refresh_for_a_known_member(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->configure( array( self::SOURCE ) );
		( new KnownUsers() )->mark( $ada );
		$this->omnabaseTakes( $ada );

		foreach ( array( 'added' => fn () => add_user_meta( $ada, self::FIELD, self::CODE ), 'updated' => fn () => update_user_meta( $ada, self::FIELD, 'a1b2c3d4-0000-4000-8000-00000000f00d' ), 'deleted' => fn () => delete_user_meta( $ada, self::FIELD ) ) as $what => $change ) {
			$this->clearOutbox();
			$change();
			$this->assertCount( 1, $this->outboxRows( 'licence_refresh' ), "the field was {$what}" );
		}

		$this->clearOutbox();
		update_user_meta( $ada, self::FIELD, 'one' );
		update_user_meta( $ada, self::FIELD, 'two' );
		$this->assertCount( 1, $this->outboxRows( 'licence_refresh' ), 'two changes in a row: one waiting row' );

		$this->makeDue();
		$this->runOutbox();
		$this->assertCount( 1, $this->callsFor( $ada, 'POST', true ), 'the refresh call went out once' );
	}

	/**
	 * What it checks: (guard) Only a change that matters queues a refresh.
	 * Passes when: nothing is queued for a member OmnaBase does not know, for a change of another field of a known member, or for a change of
	 *   the licence field while the source is switched off.
	 * Fails when: every registration on the forum or every profile save sends a call to OmnaBase (OmnaBase answers "unknown" for strangers),
	 *   or a switched-off source still talks to OmnaBase.
	 */
	public function test_only_a_known_members_licence_field_queues_a_refresh(): void {
		$ada = $this->customer( 'ada@example.org' );
		$ben = $this->customer( 'ben@example.org', 'Ben Weber' );
		( new KnownUsers() )->mark( $ada );
		$this->configure( array( self::SOURCE ) );

		update_user_meta( $ben, self::FIELD, self::CODE );
		$this->assertSame( array(), $this->outboxRows( 'licence_refresh' ), 'unknown member' );

		update_user_meta( $ada, 'description', 'Loves Enfold' );
		$this->assertSame( array(), $this->outboxRows( 'licence_refresh' ), 'another field' );

		$this->configure( array( 'profile' ) );
		update_user_meta( $ada, self::FIELD, self::CODE );
		$this->assertSame( array(), $this->outboxRows( 'licence_refresh' ), 'the source is off' );
	}

	/* ---------- the Customer data tab ---------- */

	/**
	 * What it checks: The Customer data tab offers the new source like the others, with its two fields, in plain and short words.
	 * Passes when: the tab's save form has the source's switch (off), a text field for the field's name and one for the product's name; the
	 *   tab's visible words hold none of the banned developer words ("usermeta", "meta key" …); every description has at most 30 words.
	 * Fails when: the administrator of a site without a shop cannot turn the licence on, or the tab speaks in developer words.
	 */
	public function test_the_tab_offers_the_source_with_its_two_fields(): void {
		$html = $this->openTab( 'data' );
		$form = $this->factsForm( $html );

		$switch = self::query( $html, "//form//input[@type='checkbox'][@name='omnabase_facts[sources][]'][@value='" . self::SOURCE . "']" )->item( 0 );
		$this->assertInstanceOf( \DOMElement::class, $switch, 'the source\'s switch' );
		$this->assertFalse( $switch->hasAttribute( 'checked' ), 'off until turned on' );
		foreach ( array( 'omnabase_facts[licence_meta][key]', 'omnabase_facts[licence_meta][product]' ) as $name ) {
			$this->assertSame( 1, self::query( $html, "//form//input[@name='{$name}']" )->length, $name );
		}
		$this->assertArrayHasKey( 'licence_meta', (array) ( $form['fields']['omnabase_facts'] ?? array() ), 'the two fields are inside the tab\'s one save form' );
		$this->assertSame( array(), self::bannedIn( self::visibleText( $html ) ) );
		foreach ( self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings' ) . ']//*[self::p or self::li or ' . self::hasClass( 'description' ) . ' or ' . self::hasClass( 'ob-form__intro' ) . ' or ' . self::hasClass( 'ob-form-row__help' ) . ']' ) as $node ) {
			$this->assertLessThanOrEqual( 30, str_word_count( self::wordsOf( $node ) ), self::wordsOf( $node ) );
		}
	}

	/**
	 * What it checks: Saving the tab keeps the field's and the product's names, accepts kriesi.at's field although its name holds banned words,
	 *   and accepts a field no user has yet while saying so.
	 * Passes when: posting the tab's own form with the source on, `av_purchase_code` and "Enfold" stores the three; posting `not_filled_yet`
	 *   (no user holds it) stores it too, and the tab then shows the "no user has this field yet" note beside the field.
	 * Fails when: kriesi.at's field is refused, a field the site fills later cannot be set up in advance, or the administrator gets no hint
	 *   that a typo'd name matches nobody.
	 */
	public function test_saving_keeps_the_settings_and_notes_an_unused_field(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, self::FIELD, self::CODE );

		$this->saveTab( self::FIELD, 'Enfold' );
		$saved = (array) get_option( self::FACTS_OPTION );
		$this->assertTrue( (bool) ( $saved['sources'][ self::SOURCE ] ?? false ) );
		$this->assertSame( array( 'key' => self::FIELD, 'product' => 'Enfold' ), array_intersect_key( (array) ( $saved['licence_meta'] ?? array() ), array_flip( array( 'key', 'product' ) ) ) );
		$this->assertSame( 0, self::query( $this->openTab( 'data' ), '//*[' . self::hasClass( 'ob-wp-settings__licence-meta-unused' ) . ']' )->length, 'Ada holds the field: no note' );

		$this->saveTab( 'not_filled_yet', 'Enfold' );
		$this->assertSame( 'not_filled_yet', ( (array) get_option( self::FACTS_OPTION ) )['licence_meta']['key'] ?? null );
		$this->assertSame( 1, self::query( $this->openTab( 'data' ), '//*[' . self::hasClass( 'ob-wp-settings__licence-meta-unused' ) . ']' )->length, 'the note beside an unused field' );
	}

	/**
	 * What it checks: The user-field picker never offers the field named as the licence, even when its name looks harmless.
	 * Passes when: `enfold_buyer_ref` is the licence field and a member holds it; the "Choose user fields" list does not offer it, while it
	 *   still offers billing_country.
	 * Fails when: an administrator can tick the licence field as a profile field and send the full code by accident.
	 */
	public function test_the_picker_never_offers_the_licence_field(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'enfold_buyer_ref', self::CODE );
		update_user_meta( $ada, 'billing_country', 'AT' );
		$this->configure( array( self::SOURCE ), array(), 'enfold_buyer_ref' );

		$offered = array();
		foreach ( self::query( $this->openTab( 'data' ), "//input[@name='omnabase_facts[meta][]']" ) as $box ) {
			$offered[] = $box instanceof \DOMElement ? $box->getAttribute( 'value' ) : '';
		}

		$this->assertContains( 'billing_country', $offered );
		$this->assertNotContains( 'enfold_buyer_ref', $offered );
	}

	/* ---------- helpers ---------- */

	/**
	 * Stores the switches (these sources on, every other off), the ticked user fields and the licence settings.
	 *
	 * @param string[] $sources The sources to turn on.
	 * @param string[] $meta    The ticked user fields.
	 * @param string   $field   The licence field's name.
	 * @param string   $product The product's name.
	 */
	private function configure( array $sources, array $meta = array(), string $field = self::FIELD, string $product = 'Enfold' ): void {
		$all = array_merge( self::ALL_SOURCES, array( self::SOURCE ) );
		update_option(
			self::FACTS_OPTION,
			array(
				'sources'      => array_combine( $all, array_map( static fn ( $s ) => in_array( $s, $sources, true ), $all ) ),
				'meta'         => $meta,
				'licence_meta' => array( 'key' => $field, 'product' => $product ),
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
	 * Posts the tab's own form with the source on and the two fields filled in.
	 *
	 * @param string $field   The licence field's name.
	 * @param string $product The product's name.
	 */
	private function saveTab( string $field, string $product ): void {
		$fields = $this->factsForm( $this->openTab( 'data' ) )['fields'];
		$fields['omnabase_facts']['sources']      = array_values( array_unique( array_merge( (array) ( $fields['omnabase_facts']['sources'] ?? array() ), array( self::SOURCE ) ) ) );
		$fields['omnabase_facts']['licence_meta'] = array( 'key' => $field, 'product' => $product );
		$this->tabIn( $this->postFields( $fields ) );
	}
}
