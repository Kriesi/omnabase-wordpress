<?php
/**
 * The licence-sources round, part one (the red team's failing tests, 2026-11-03): a new customer-data source, "Licence in a user field",
 * reads one licence from a user field (user meta: a value WordPress keeps per user under a name). kriesi.at's forum keeps a member's Envato
 * purchase code there (the field `av_purchase_code`, written by the site's own `avia-verifier.php` at registration and by
 * `avia-expiring-support.php` when a member enters a new code). No shop plugin runs on that site, so today no source reads it and the
 * Enfold customer's card says "no licence".
 *
 * Brief: OmnaBase `docs/briefs/licence-sources-round.md`, part one, rules 1 to 3 and 8; pinned Q1 (one field, "active" whenever the value is
 * non-empty, no expiry) and Q4 (the masked key keeps the last four characters).
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - `OmnaBase\WordPress\Facts\Sources\UserMetaLicence implements FactsSource`, built with the `Switches`; id `user_meta_licence`, section
 *   `licences`, always available (it needs no other plugin).
 * - Its settings live in the facts option beside the switches: `omnabase_facts[ 'licence_meta' ] = [ 'key' => <field name>, 'product' =>
 *   <name shown in OmnaBase> ]`; `Switches::licenceMeta()` reads them (`key` and `product`, empty strings when unset).
 * - `Switches::save()` takes the licence settings as a fourth argument (`[ 'key' => …, 'product' => … ]`).
 * - (chosen, review finding 6) A user whose field holds a list or an object has no readable licence: an empty list, nothing of the value.
 * - (chosen, review finding 7) WordPress's own secrets are never accepted as the licence field: `user_pass`, `session_tokens`,
 *   `_two_factor_backup_codes` and any `…capabilities` / `…user_level` name; neither is a malformed name (MetaPolicy's well-formed rule).
 * - (chosen) With no field name configured the source has nothing to say (null), so switching it on before filling the field revokes nothing.
 *
 * Why these fail today: there is no such source and the switches keep no licence settings.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Facts\Person;
use OmnaBase\WordPress\Facts\Shaper;
use OmnaBase\WordPress\Facts\Sources\Profile;
use OmnaBase\WordPress\Facts\Switches;
use OmnaBase\WordPress\Tests\Unit\Support\SiteFactsOracle;

final class UserMetaLicenceSourceTest extends TestCase {

	private const SOURCE = 'OmnaBase\\WordPress\\Facts\\Sources\\UserMetaLicence';

	/** An Envato purchase code's shape (36 characters, four dashes); made up. */
	private const CODE = '7c1e9a42-5b3d-4f0e-9a21-3c8d5e7f1a2b';

	/** @var array<string,mixed> The facts option as stored. */
	private array $option = array();

	/** @var array<int,array<string,mixed>> User id => field name => stored value. */
	private array $usermeta = array();

	/** @var array<int,object> User id => the user. */
	private array $users = array();

	/** How often a user field was read. */
	private int $metaReads = 0;

	/** @var array<string,mixed>|null What the last `update_option` stored for the facts option. */
	private ?array $saved = null;

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'wp_check_invalid_utf8' )->alias( static fn ( $s, $strip = false ) => mb_check_encoding( (string) $s, 'UTF-8' ) ? (string) $s : '' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( (string) $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( (string) $s ) );
		Functions\when( 'home_url' )->justReturn( 'https://kriesi.example/support' );
		Functions\when( 'admin_url' )->alias( static fn ( $path = '', $scheme = 'admin' ) => 'https://kriesi.example/support/wp-admin/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => 'omnabase_facts' === $name ? $this->option : $default );
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( 'omnabase_facts' === $name ) {
					$this->saved  = $value;
					$this->option = $value;
				}
				return true;
			}
		);
		Functions\when( 'get_userdata' )->alias( fn ( $id ) => $this->users[ (int) $id ] ?? false );
		Functions\when( 'metadata_exists' )->alias( fn ( $type, $id, $key ) => 'user' === $type && array_key_exists( (string) $key, $this->usermeta[ (int) $id ] ?? array() ) );
		Functions\when( 'get_user_meta' )->alias(
			function ( $id, $key = '', $single = false ) {
				++$this->metaReads;
				$meta = $this->usermeta[ (int) $id ] ?? array();
				if ( '' === $key ) {
					return array_map( static fn ( $v ) => array( $v ), $meta );
				}
				if ( ! array_key_exists( (string) $key, $meta ) ) {
					return $single ? '' : array();
				}
				return $single ? $meta[ (string) $key ] : array( $meta[ (string) $key ] );
			}
		);
	}

	/* ---------- the source ---------- */

	/**
	 * What it checks: The new source is one of the customer-data sources, fills the licences section and needs no shop plugin.
	 * Passes when: the source's id is `user_meta_licence`, it fills "licences", it is available on any site, and its name on the settings
	 *   screen avoids developer words ("usermeta", "meta key").
	 * Fails when: the source is missing (kriesi.at keeps answering "no licence" for every Enfold customer), or it appears only when some
	 *   shop plugin is active, or the screen names it in developer words the plain-words rule bans.
	 */
	public function test_the_source_fills_licences_on_any_site(): void {
		$source = $this->source();

		$this->assertSame( 'user_meta_licence', $source->id() );
		$this->assertSame( 'licences', $source->section() );
		$this->assertTrue( $source->isAvailable() );
		$this->assertStringNotContainsStringIgnoringCase( 'usermeta', $source->label() );
		$this->assertStringNotContainsStringIgnoringCase( 'meta key', $source->label() );
	}

	/**
	 * What it checks: A member whose field holds a purchase code has exactly one licence, under the configured product name, with the key
	 *   masked to its last four characters and the status "active".
	 * Passes when: Ada's field holds an Envato-style code; the source answers one licence: product "Enfold", key "••••-1a2b", status "active",
	 *   no version, no expiry and no sites; the full code appears nowhere in what the source hands over; and once shaped, OmnaBase's reader
	 *   accepts the answer.
	 * Fails when: the team sees no licence for a verified member, or the full purchase code (which anyone could use to claim support) leaves
	 *   the site, or the answer carries made-up versions or dates.
	 */
	public function test_a_user_with_a_code_has_one_masked_active_licence(): void {
		$this->configure( 'av_purchase_code', 'Enfold' );
		$this->user( 7, array( 'av_purchase_code' => self::CODE ) );

		$found = $this->source()->collect( new Person( 7, 'ada@example.org', true ) );

		$this->assertIsArray( $found );
		$this->assertCount( 1, $found );
		$licence = (array) $found[0];
		$this->assertSame( 'Enfold', $licence['product'] ?? null );
		$this->assertSame( '••••-1a2b', $licence['keyMasked'] ?? null );
		$this->assertSame( 'active', $licence['status'] ?? null );
		foreach ( array( 'version', 'expiresAt', 'sites' ) as $absent ) {
			$this->assertEmpty( $licence[ $absent ] ?? null, $absent . ' is not known for a code in a user field' );
		}
		$this->assertStringNotContainsString( self::CODE, (string) json_encode( $found ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringNotContainsString( '7c1e9a42', (string) json_encode( $found ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$shaped = ( new Shaper() )->shape( array( 'licences' => $found ) );
		$raw    = (string) json_encode( $shaped['answer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertNull( SiteFactsOracle::refusal( $raw ), $raw );
		$this->assertStringNotContainsString( self::CODE, $raw );
	}

	/**
	 * What it checks: The product's name always reaches OmnaBase, even before the administrator typed one.
	 * Passes when: with the field configured but the product name left empty, the licence still carries a non-empty product name, and the
	 *   shaped answer is accepted by OmnaBase's reader.
	 * Fails when: OmnaBase refuses the whole licences section (its contract requires a product name), so the team sees nothing at all.
	 */
	public function test_an_empty_product_name_still_answers_a_licence_omnabase_accepts(): void {
		$this->configure( 'av_purchase_code', '' );
		$this->user( 7, array( 'av_purchase_code' => self::CODE ) );

		$found = (array) $this->source()->collect( new Person( 7, 'ada@example.org', true ) );

		$this->assertCount( 1, $found );
		$this->assertNotSame( '', trim( (string) ( ( (array) $found[0] )['product'] ?? '' ) ) );
		$raw = (string) json_encode( ( new Shaper() )->shape( array( 'licences' => $found ) )['answer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertNull( SiteFactsOracle::refusal( $raw ), $raw );
		$this->assertStringContainsString( '"licences":[{', $raw );
	}

	/**
	 * What it checks: A member with an empty or missing field has no licence from this source, and says so (an empty list).
	 * Passes when: for a missing field, an empty value and a value of spaces only, the source answers an empty list (not "nothing to say").
	 * Fails when: an empty field reads as a licence, or a member whose code was removed keeps a stored licence in OmnaBase for ever (an empty
	 *   list is how a site says "none now"; OmnaBase's copy-and-charts round, Q7).
	 */
	public function test_an_empty_or_missing_value_answers_an_empty_list(): void {
		$this->configure( 'av_purchase_code', 'Enfold' );
		$this->user( 7, array() );
		$this->user( 8, array( 'av_purchase_code' => '' ) );
		$this->user( 9, array( 'av_purchase_code' => "  \t " ) );

		foreach ( array( 7, 8, 9 ) as $id ) {
			$this->assertSame( array(), $this->source()->collect( new Person( $id, "u{$id}@example.org", false ) ), "user {$id}" );
		}
	}

	/**
	 * What it checks: Someone without an account on the site (a guest known only by address) gets nothing from this source.
	 * Passes when: the source answers "nothing to say" (null) for a guest and never reads any user field.
	 * Fails when: a guest's ticket shows another person's licence, or the source says "no licence" for a guest and so revokes licences
	 *   OmnaBase learnt elsewhere.
	 */
	public function test_a_guest_gets_nothing(): void {
		$this->configure( 'av_purchase_code', 'Enfold' );
		$this->assertNull( $this->source()->collect( new Person( null, 'guest@example.org', false ) ) );
		$this->assertSame( 0, $this->metaReads, 'no user field is read for a guest' );
	}

	/**
	 * What it checks: Switching the source on before naming the field shares nothing and revokes nothing.
	 * Passes when: with no field name stored, the source answers "nothing to say" (null) for a member.
	 * Fails when: an unfinished setup answers "no licence" for every member and wipes their licences in OmnaBase.
	 */
	public function test_no_field_name_means_nothing_to_say(): void {
		$this->option = array( 'sources' => array( 'user_meta_licence' => true ), 'meta' => array() );
		$this->user( 7, array( 'av_purchase_code' => self::CODE ) );

		$this->assertNull( $this->source()->collect( new Person( 7, 'ada@example.org', true ) ) );
	}

	/**
	 * What it checks: A field that holds a list or an object (old rows on a ten-year-old forum do) never breaks the answer and never leaks.
	 * Passes when: the source answers an empty list for such a member, without an error, and nothing of the stored list reaches the answer.
	 * Fails when: one odd old row stops the facts call with an error, or pieces of a stored list (an old code, a buyer's name) are sent.
	 */
	public function test_a_list_shaped_value_is_no_licence_and_never_sent(): void {
		$this->configure( 'av_purchase_code', 'Enfold' );
		$this->user( 7, array( 'av_purchase_code' => array( 'purchase_code' => self::CODE, 'buyer' => 'secret-buyer-7b3e' ) ) );

		$found = $this->source()->collect( new Person( 7, 'ada@example.org', true ) );

		$this->assertSame( array(), $found );
		$this->assertStringNotContainsString( 'secret-buyer-7b3e', (string) json_encode( $found ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * What it checks: A very short stored value (kriesi.at's oldest rows hold the placeholder "1") is shown fully masked.
	 * Passes when: a member whose field holds "1" has one licence whose key reads "••••", never the value itself.
	 * Fails when: a short value is shown as it is (the key is not masked), or such a member gets no licence at all.
	 */
	public function test_a_very_short_value_is_fully_masked(): void {
		$this->configure( 'av_purchase_code', 'Enfold' );
		$this->user( 7, array( 'av_purchase_code' => '1' ) );

		$found = (array) $this->source()->collect( new Person( 7, 'ada@example.org', true ) );

		$this->assertCount( 1, $found );
		$this->assertSame( '••••', ( (array) $found[0] )['keyMasked'] ?? null );
	}

	/* ---------- the profile never sends the licence field ---------- */

	/**
	 * What it checks: The field named as the licence never travels as an ordinary user field, even when the administrator ticked it there
	 *   and its name looks harmless.
	 * Passes when: the licence field is `enfold_buyer_ref` (no banned word in it) and it is also ticked among the user fields; the profile
	 *   carries the other ticked field (billing country) but not this one, and its value appears nowhere in the profile.
	 * Fails when: the full purchase code reaches OmnaBase in clear through the profile, beside the masked licence.
	 */
	public function test_the_profile_never_sends_the_licence_field(): void {
		$this->option = array(
			'sources'      => array( 'profile' => true, 'user_meta_licence' => true ),
			'meta'         => array( 'enfold_buyer_ref', 'billing_country' ),
			'licence_meta' => array( 'key' => 'enfold_buyer_ref', 'product' => 'Enfold' ),
		);
		$this->user( 7, array( 'enfold_buyer_ref' => self::CODE, 'billing_country' => 'AT' ) );

		$profile = ( new Profile( new Switches() ) )->collect( new Person( 7, 'ada@example.org', true ) );

		$this->assertSame( 'AT', $profile['meta']['billing_country'] ?? null );
		$this->assertArrayNotHasKey( 'enfold_buyer_ref', (array) ( $profile['meta'] ?? array() ) );
		$this->assertStringNotContainsString( self::CODE, (string) json_encode( $profile ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------- the switches keep the two settings ---------- */

	/**
	 * What it checks: Saving the customer-data tab keeps the licence field's name and the product's name, even when the field's name holds a
	 *   word the user-field rule bans (the administrator named it as a licence on purpose).
	 * Passes when: saving `av_purchase_code` (it holds "purchase" and "code") and "Enfold" with the source ticked stores both, reads them back
	 *   through the switches, and never adds the field to the ticked user fields.
	 * Fails when: kriesi.at's field cannot be chosen at all (the ban refuses it), or choosing it as a licence also sends it as a profile field.
	 *
	 * Adjusted 2026-11-04 (outside red team, licence-and-fields round, piece A): the licence settings grow a condition, its text and an expiry
	 *   on purpose, so the field and the product are compared one by one instead of as the whole settings list. Not a weakening: both stay
	 *   pinned exactly as before; the three new settings are pinned in `LicenceConditionExpiryTest.php`.
	 */
	public function test_saving_keeps_the_field_and_product_even_with_a_banned_word(): void {
		$switches = new Switches();

		$switches->save( array( 'profile', 'user_meta_licence' ), array( 'user_meta_licence' ), array( 'billing_country' ), array( 'key' => ' av_purchase_code ', 'product' => ' Enfold ' ) );

		$this->assertIsArray( $this->saved );
		$this->assertSame( 'av_purchase_code', $switches->licenceMeta()['key'] ?? null );
		$this->assertSame( 'Enfold', $switches->licenceMeta()['product'] ?? null );
		$this->assertSame( array( 'billing_country' ), $switches->meta() );
		$this->assertTrue( $switches->isOn( 'user_meta_licence' ) );
	}

	/**
	 * What it checks: WordPress's own secrets and malformed names are never accepted as the licence field.
	 * Passes when: saving `user_pass`, `session_tokens`, `_two_factor_backup_codes`, `wp_capabilities`, `wp_user_level`, a name with a space
	 *   and a 65-character name each leaves the licence field empty.
	 * Fails when: a slip of the keyboard makes every logged-in user "licensed" (session tokens) or every member with a role a licence holder
	 *   (capabilities), or a name OmnaBase cannot read is stored.
	 */
	public function test_wordpress_secrets_and_malformed_names_are_refused_as_the_field(): void {
		$switches = new Switches();
		foreach ( array( 'user_pass', 'session_tokens', '_two_factor_backup_codes', 'wp_capabilities', 'wp_user_level', 'purchase code', str_repeat( 'a', 65 ) ) as $key ) {
			$switches->save( array( 'user_meta_licence' ), array( 'user_meta_licence' ), array(), array( 'key' => $key, 'product' => 'Enfold' ) );
			$this->assertSame( '', $switches->licenceMeta()['key'] ?? null, $key );
		}
	}

	/**
	 * What it checks: A site that never saved the new settings reads them as empty, never as an error.
	 * Passes when: with the facts option as it was before this round (sources and user fields only), the switches answer an empty field name
	 *   and an empty product name.
	 * Fails when: an updated site's customer-data tab or facts call breaks because its stored option predates the new settings.
	 *
	 * Adjusted 2026-11-04 (outside red team, licence-and-fields round, piece A): the settings list grows on purpose (condition, its text,
	 *   expiry), so the two names are compared one by one. Not a weakening: both must still read as empty strings; the new settings' empty
	 *   values ("any", '', '') are pinned in `LicenceConditionExpiryTest.php`.
	 */
	public function test_an_older_option_reads_as_empty_settings(): void {
		$this->option = array( 'sources' => array( 'profile' => true ), 'meta' => array( 'billing_country' ) );

		$this->assertSame( '', ( new Switches() )->licenceMeta()['key'] ?? null );
		$this->assertSame( '', ( new Switches() )->licenceMeta()['product'] ?? null );
	}

	/* ---------- helpers ---------- */

	/** The source under test, or a failure naming what is missing. */
	private function source(): object {
		$this->assertTrue( class_exists( self::SOURCE ), 'the source "Licence in a user field" (Facts\\Sources\\UserMetaLicence) does not exist yet' );
		$class = self::SOURCE;
		return new $class( new Switches() );
	}

	/**
	 * Stores the source switched on with its two settings.
	 *
	 * @param string $key     The field's name.
	 * @param string $product The product's name.
	 */
	private function configure( string $key, string $product ): void {
		$this->option = array(
			'sources'      => array( 'user_meta_licence' => true ),
			'meta'         => array(),
			'licence_meta' => array( 'key' => $key, 'product' => $product ),
		);
	}

	/**
	 * A user with these fields.
	 *
	 * @param int                 $id   The user id.
	 * @param array<string,mixed> $meta Field name => value.
	 */
	private function user( int $id, array $meta ): void {
		$this->users[ $id ]    = (object) array( 'ID' => $id, 'user_email' => "u{$id}@example.org", 'user_registered' => '2021-03-04 09:00:00', 'roles' => array( 'participant' ) );
		$this->usermeta[ $id ] = $meta;
	}
}
