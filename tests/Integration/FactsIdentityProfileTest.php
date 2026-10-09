<?php
/**
 * M13 round two, the red team's failing tests: the customer receiver answers OmnaBase's question about a customer (the facts call) in a
 * real WordPress: who the person is, their account (the profile), the switches, and the link that makes OmnaBase know the person by the
 * site's own id.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 5: "`/customer` answers facts v2 with the `omnabase` block only.
 * **Identity**: `customer.external` when it names a user of ours (the site id below), else `get_user_by('email')`, else guest records by
 * address …; nobody found answers `{}`. **Sources, each behind a switch, all off until the admin turns them on** … **profile** (registered
 * since, roles, and only the usermeta keys the admin ticks …)"; "**Linking**: a facts call for an address that matches a user OmnaBase does
 * not know by id queues a `PUT` (later calls carry the id)"; choice 6: "The plugin sends `<user id>-<8 random characters>`, made once per user
 * and kept in usermeta (gone with the user, so a reused number gets a new id)"; round two's *Red team*: "the site id wins over the address;
 * a reused user number never matches an old site id; … a ticked serialised meta value, a 2 000-character value and a user with no meta each
 * give an answer OmnaBase's reader accepts; banned meta never appears even when ticked; a switched-off source is absent; … unknown address
 * `{}`". OmnaBase `packages/shared/src/siteFacts.ts`: the profile is `{ registeredAt?, roles?, meta? }` (no display name; the coordinator's
 * "display name" and "found: false" have no place in the contract, see the report). The call: `{ v: 2, project, customer { email,
 * external }, ticket, sentAt }` (`packages/server/src/integrations/endpointRows.ts`, `factsCallBody`).
 *
 * Shapes the red team pins: those of `RoundTwoTestCase`.
 *
 * Why these fail today: the receiver answers `{}` to every call, there is no site id and no outbox.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * The customer-data tests need the shops: bin/test-integration.sh runs them in its second run.
 *
 * @group shops
 */
final class FactsIdentityProfileTest extends RoundTwoTestCase {

	/**
	 * What it checks: Every source is off until the administrator turns it on, so a fresh connection shares nothing about customers.
	 * Passes when: with no switch touched, OmnaBase's question about a known customer with an order and profile data is answered `{}`.
	 * Fails when: connecting the site silently starts sending every customer's account details and orders to OmnaBase.
	 */
	public function test_every_source_is_off_by_default(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'billing_country', 'AT' );
		$this->wooOrder( $ada, 'ada@example.org' );

		$a = $this->askFacts( 'ada@example.org' );

		$this->assertSame( 200, $a['status'] );
		$this->assertSame( '{}', $a['raw'] );
	}

	/**
	 * What it checks: An address nobody on the site uses gets an empty object, never a list or an error.
	 * Passes when: with every source on, a question about stranger@example.org answers 200 with exactly `{}`.
	 * Fails when: the answer is `[]` (OmnaBase refuses it, the card shows an error) or a 404 (OmnaBase counts the endpoint as failing).
	 */
	public function test_an_unknown_address_answers_an_empty_object(): void {
		$this->switchOn( self::ALL_SOURCES );

		$a = $this->askFacts( 'stranger@example.org' );

		$this->assertSame( 200, $a['status'] );
		$this->assertSame( '{}', $a['raw'] );
	}

	/**
	 * What it checks: With the profile switched on, the team sees when the customer registered and their roles, and no usermeta unless the
	 *   administrator ticked it.
	 * Passes when: Ada (registered 2021-03-04, a customer) is found by her address; the answer carries registeredAt starting 2021-03-04 and the
	 *   role "customer"; with nothing ticked no usermeta is sent (her nickname and billing country stay home).
	 * Fails when: the team cannot tell a long-time customer from a new one, or the plugin shares usermeta nobody chose.
	 */
	public function test_the_profile_carries_registration_and_roles_and_no_unticked_meta(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'billing_country', 'AT' );
		update_user_meta( $ada, 'nickname', 'countess' );
		$this->switchOn( array( 'profile' ) );

		$block = $this->blockFor( 'ada@example.org' );

		$this->assertStringStartsWith( '2021-03-04', (string) ( $block->profile->registeredAt ?? '' ) );
		$this->assertSame( array( 'customer' ), $block->profile->roles ?? null );
		$this->assertSame( array(), (array) ( $block->profile->meta ?? array() ), 'no usermeta without a tick' );
		$this->assertStringNotContainsString( 'countess', $this->askFacts( 'ada@example.org' )['raw'] );
	}

	/**
	 * What it checks: The usermeta an administrator ticks is sent, safely, whatever shape WordPress stores it in, and secret-looking meta never
	 *   is.
	 * Passes when: of the ticked keys, billing_country and a 2 000-character bio are sent (the bio cut to OmnaBase's 1 000), a serialised list
	 *   is left out, and user_pass, session_tokens, the two-factor backup codes, wp_capabilities and an API key are absent with their values; OmnaBase
	 *   accepts the answer.
	 * Fails when: one long or list-shaped value blanks the whole section on the card, or a session token or backup code reaches OmnaBase.
	 */
	public function test_ticked_usermeta_is_sent_shaped_and_banned_meta_never(): void {
		$ada = $this->customer( 'ada@example.org' );
		update_user_meta( $ada, 'billing_country', 'AT' );
		update_user_meta( $ada, 'description', str_repeat( 'b', 2000 ) );
		update_user_meta( $ada, 'newsletter_prefs', array( 'weekly' => true, 'topics' => array( 'enfold', 'omnalingo' ) ) );
		update_user_meta( $ada, 'session_tokens', array( 'tok-7b3e' => array( 'expiration' => time() + 3600 ) ) );
		update_user_meta( $ada, '_two_factor_backup_codes', array( 'code-7b3e' ) );
		update_user_meta( $ada, 'api_key', 'secret-7b3e' );
		$this->switchOn( array( 'profile' ), array( 'billing_country', 'description', 'newsletter_prefs', 'user_pass', 'session_tokens', '_two_factor_backup_codes', 'wp_capabilities', 'api_key' ) );

		$a     = $this->askFacts( 'ada@example.org' );
		$block = $this->blockFor( 'ada@example.org' );
		$meta  = (array) ( $block->profile->meta ?? array() );

		$this->assertSame( 'AT', $meta['billing_country'] ?? null );
		$this->assertStringStartsWith( str_repeat( 'b', 100 ), (string) ( $meta['description'] ?? '' ) );
		$this->assertLessThanOrEqual( 1000, mb_strlen( (string) ( $meta['description'] ?? '' ) ) );
		foreach ( array( 'newsletter_prefs', 'user_pass', 'session_tokens', '_two_factor_backup_codes', 'wp_capabilities', 'api_key' ) as $gone ) {
			$this->assertArrayNotHasKey( $gone, $meta, $gone );
		}
		foreach ( array( 'tok-7b3e', 'code-7b3e', 'secret-7b3e', get_userdata( $ada )->user_pass ) as $value ) {
			$this->assertStringNotContainsString( $value, $a['raw'] );
		}
	}

	/**
	 * What it checks: A customer without any of the ticked usermeta still gets an answer OmnaBase accepts.
	 * Passes when: with billing_country ticked but never set for Ben, his profile arrives with roles and an empty or missing meta, and OmnaBase's
	 *   reader accepts it (no `[]` where an object belongs).
	 * Fails when: the empty meta goes out as a list and OmnaBase refuses the whole block for every customer who never filled in the field.
	 */
	public function test_a_user_with_no_meta_is_accepted(): void {
		$this->customer( 'ben@example.org', 'Ben Weber' );
		$this->switchOn( array( 'profile' ), array( 'billing_country' ) );

		$a = $this->askFacts( 'ben@example.org' );

		$this->assertStringNotContainsString( '"meta":[]', $a['raw'] );
		$this->assertStringNotContainsString( '"profile":[]', $a['raw'] );
		$this->assertSame( array( 'customer' ), $this->blockFor( 'ben@example.org' )->profile->roles ?? null );
	}

	/**
	 * What it checks: Each user's site id is made once, kept, and looks like "<user id>-<8 characters>".
	 * Passes when: Ada's id matches that form, is the same on a second ask, differs from Ben's, and leads back to Ada.
	 * Fails when: the id changes between calls (OmnaBase loses the link and the customer's history), or two users share one.
	 */
	public function test_the_site_id_is_made_once_and_leads_back_to_the_user(): void {
		$ada = $this->customer( 'ada@example.org' );
		$ben = $this->customer( 'ben@example.org', 'Ben Weber' );

		$id = self::siteIdOf( $ada );

		$this->assertMatchesRegularExpression( '/^' . $ada . '-[a-z0-9]{8}$/', $id );
		$this->assertSame( $id, self::siteIdOf( $ada ) );
		$this->assertNotSame( $id, self::siteIdOf( $ben ) );
		$this->assertSame( $ada, ( new \OmnaBase\WordPress\Sync\SiteId() )->userFor( $id ) );
	}

	/**
	 * What it checks: When OmnaBase names the site's own id for a person, that person is answered, even if the ticket came from another
	 *   address.
	 * Passes when: a question carrying Ben's site id and Ada's address answers with Ben's profile (his ticked nickname), not Ada's.
	 * Fails when: a customer who writes from a colleague's address sees the colleague's orders and licences on the team's card.
	 */
	public function test_the_site_id_wins_over_the_address(): void {
		$ada = $this->customer( 'ada@example.org' );
		$ben = $this->customer( 'ben@example.org', 'Ben Weber' );
		update_user_meta( $ada, 'nickname', 'countess' );
		update_user_meta( $ben, 'nickname', 'benno' );
		$this->switchOn( array( 'profile' ), array( 'nickname' ) );

		$block = $this->blockFor( 'ada@example.org', self::siteIdOf( $ben ) );

		$this->assertSame( 'benno', $block->profile->meta->nickname ?? null );
	}

	/**
	 * What it checks: A site id that belonged to a deleted user never matches the new user who later gets the same number.
	 * Passes when: user 4242 is given a site id and deleted, a new user is made with the same number 4242, and a question carrying the old site
	 *   id with an unknown address answers `{}`; the new user's own site id is a different one.
	 * Fails when: after a database restore a stranger inherits a deleted customer's identity in OmnaBase, with their tickets and licences.
	 */
	public function test_a_reused_user_number_never_matches_an_old_site_id(): void {
		global $wpdb;
		// Builder's adjustment (DECISIONS "Round two"): moving a user to another number by hand, as a database restore does, leaves WordPress's
		// user cache asking for empty keys, and WordPress warns about that ("Cache key must not be an empty string"). The warning comes from
		// these setup lines, not from the plugin, so it is expected here instead of failing the test.
		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::add' );
		$this->setExpectedIncorrectUsage( 'WP_Object_Cache::delete' );
		$this->switchOn( self::ALL_SOURCES, array( 'nickname' ) );
		$old = $this->customer( 'gone@example.org', 'Gone User' );
		$wpdb->update( $wpdb->users, array( 'ID' => 4242 ), array( 'ID' => $old ) ); // phpcs:ignore WordPress.DB
		$wpdb->update( $wpdb->usermeta, array( 'user_id' => 4242 ), array( 'user_id' => $old ) ); // phpcs:ignore WordPress.DB
		clean_user_cache( $old );
		$old_site_id = self::siteIdOf( 4242 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( 4242 );
		$wpdb->insert( $wpdb->users, array( 'ID' => 4242, 'user_login' => 'reused', 'user_email' => 'reused@example.org', 'user_registered' => gmdate( 'Y-m-d H:i:s' ), 'display_name' => 'Reused' ) ); // phpcs:ignore WordPress.DB
		clean_user_cache( 4242 );
		( new \WP_User( 4242 ) )->set_role( 'customer' );
		update_user_meta( 4242, 'nickname', 'newcomer' );

		$a = $this->askFacts( 'nobody@example.org', $old_site_id );

		$this->assertSame( '{}', $a['raw'], 'the old site id names nobody' );
		$this->assertNotSame( $old_site_id, self::siteIdOf( 4242 ) );
	}

	/**
	 * What it checks: A switched-off source leaves no trace in the answer.
	 * Passes when: with only the profile on, Ada's answer has no orders, subscriptions or licences keys at all, though she has a WooCommerce
	 *   order and a License Manager licence.
	 * Fails when: an administrator switches WooCommerce off and the orders still reach OmnaBase.
	 */
	public function test_a_switched_off_source_is_absent(): void {
		$ada     = $this->customer( 'ada@example.org' );
		$product = $this->product();
		$this->wooOrder( $ada, 'ada@example.org', '2026-09-30 10:00:00', 'completed', $product );
		lmfwc_add_license( 'OMNA-TEST-0000-7Q2X', array( 'product_id' => $product->get_id(), 'user_id' => $ada, 'status' => 2 ) );
		$this->switchOn( array( 'profile' ) );

		$block = $this->blockFor( 'ada@example.org' );

		foreach ( array( 'orders', 'subscriptions', 'licences' ) as $section ) {
			$this->assertFalse( property_exists( $block, $section ), "{$section} is absent" );
		}
	}

	/**
	 * What it checks: When OmnaBase asks about an address that belongs to a user it does not know by id yet, the plugin queues the link, once.
	 * Passes when: two questions about Ada's address without a site id leave exactly one waiting "user_link" row; a question that already
	 *   carries Ben's site id and one about an unknown address queue nothing.
	 * Fails when: OmnaBase never learns the site's id for its customers (no licence refresh can ever reach them), or every card view adds a row.
	 */
	public function test_an_address_known_here_but_not_by_id_queues_one_link(): void {
		$this->customer( 'ada@example.org' );
		$ben = $this->customer( 'ben@example.org', 'Ben Weber' );
		$this->switchOn( array( 'profile' ) );

		$this->askFacts( 'ada@example.org' );
		$this->askFacts( 'ada@example.org' );
		$this->askFacts( 'ben@example.org', self::siteIdOf( $ben ) );
		$this->askFacts( 'stranger@example.org' );

		$links = $this->outboxRows( 'user_link' );
		$this->assertCount( 1, $links, 'one link, for Ada' );
		$this->assertSame( 'waiting', $links[0]['state'] );
	}

	/**
	 * What it checks: The queued link tells OmnaBase the site's id and the person's address and name.
	 * Passes when: the worker sends one `PUT /api/public/v1/customers/by-external/<Ada's site id>` with the site's key, her address and name,
	 *   and OmnaBase's 201 marks the row sent.
	 * Fails when: the link goes to the wrong address, without the key, or with WordPress's bare user number (which a reused number could
	 *   inherit).
	 */
	public function test_the_link_is_sent_with_the_site_id_address_and_name(): void {
		$ada = $this->customer( 'ada@example.org' );
		$this->omnabaseTakes( $ada );
		$this->switchOn( array( 'profile' ) );
		$this->askFacts( 'ada@example.org' );
		$this->makeDue();

		$this->runOutbox();

		$puts = $this->callsFor( $ada, 'PUT', false );
		$this->assertCount( 1, $puts );
		$this->assertSame( 'Bearer ' . self::KEY, $puts[0]['args']['headers']['Authorization'] ?? null );
		$body = json_decode( (string) $puts[0]['args']['body'], true );
		$this->assertSame( 'ada@example.org', $body['email'] ?? null );
		$this->assertSame( 'Ada Lovelace', $body['name'] ?? null );
		$this->assertSame( 'sent', $this->outboxRows( 'user_link' )[0]['state'] );
	}
}
