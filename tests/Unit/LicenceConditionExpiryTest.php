<?php
/**
 * The licence-and-fields round, piece A (the outside red team's failing tests, 2026-11-04): the connector's licence configurator. "Licence in
 * a user field" (a user field: user meta, a value WordPress keeps per user under a name) learns two things: a condition on the value ("any
 * value", "equals …", "contains …") and an optional expiry, read from a user field holding a date or from one key of a list stored in a user
 * field, written `field.key` (kriesi.at: `av_purchase_data.supported_until`).
 *
 * Brief: OmnaBase `docs/briefs/licence-and-fields-round.md`, piece A, rules 1, 2 and 5; pinned Q4 (a condition's text is compared without
 * capitals and trimmed) and Q5 (an unreadable expiry keeps the licence without a date). The shape of kriesi.at's list is the first red team's
 * finding (`docs/reviews/licence-sources-round-red-team-report.md`, "Where kriesi.at stores the Envato purchase code"): `av_purchase_data`
 * is ONE list per user, `{ purchase_code, purchase_count, buyer, supported_until, sold_at, license, source }`; `supported_until` is an ISO
 * 8601 date with its offset (Envato's own, e.g. `2027-03-12T05:25:07+11:00`, or the direct shop's `gmdate( 'c' )`).
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - The licence settings in the facts option grow three keys: `omnabase_facts[ 'licence_meta' ] = [ 'key', 'product', 'condition' =>
 *   'any'|'equals'|'contains', 'condition_text' => <text>, 'expiry' => <field> or <field.key> ]`; `Switches::licenceMeta()` answers all five
 *   (empty strings and 'any' when unset); `Switches::save()`'s fourth argument carries them.
 * - (chosen, review A3) `field.key`: the part before the FIRST dot is the user field, the rest is one key of the list stored there. The field
 *   part follows the licence field's rule (WordPress's own secrets refused, banned words accepted); a name with an empty part or a second dot
 *   is refused and stored empty. The licence field itself accepts the same syntax (review A2: the direct shop's buyers have only
 *   `av_purchase_data.purchase_code`).
 * - (chosen, review A4) Dates: a Unix time (a number, or a string of 9 to 11 digits) is seconds; an 8-digit string that is a real calendar day
 *   (`20270312`, the way ACF's date picker stores it) is that day; any other text is read only when it names one fixed moment (a word like
 *   "tomorrow" that means another day each day is unreadable); zero, negative and `0000-00-00` are unreadable. A date without a time lasts
 *   the whole day. `expiresAt` is `Y-m-d` (the plugin's other sources' format): a stored date string by its own date part, a Unix time in
 *   the site's time zone.
 * - (chosen, review A5) An equals/contains condition saved with no text is stored as "any value"; an unknown condition word is "any value".
 *
 * Why these fail today: the switches keep only the field's and the product's names, the source knows no condition and no expiry, and every
 * licence it answers is "active" without a date.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Facts\Person;
use OmnaBase\WordPress\Facts\Shaper;
use OmnaBase\WordPress\Facts\Switches;
use OmnaBase\WordPress\Tests\Unit\Support\SiteFactsOracle;

final class LicenceConditionExpiryTest extends TestCase {

	private const SOURCE = 'OmnaBase\\WordPress\\Facts\\Sources\\UserMetaLicence';

	/** An Envato purchase code's shape (36 characters, four dashes); made up. */
	private const CODE = '7c1e9a42-5b3d-4f0e-9a21-3c8d5e7f1a2b';

	/** A support end far in the future and one long past, in Envato's own format (with its offset). */
	private const FUTURE_ISO = '2099-03-12T05:25:07+11:00';
	private const PAST_ISO   = '2020-02-21T16:53:06+11:00';

	/** @var array<string,mixed> The facts option as stored. */
	private array $option = array();

	/** @var array<int,array<string,mixed>> User id => field name => stored value. */
	private array $usermeta = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_check_invalid_utf8' )->alias( static fn ( $s, $strip = false ) => mb_check_encoding( (string) $s, 'UTF-8' ) ? (string) $s : '' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( (string) $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'sanitize_text_field' )->alias( static fn ( $s ) => trim( (string) $s ) );
		Functions\when( 'wp_timezone' )->alias( static fn () => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'wp_date' )->alias( static fn ( $format, $ts = null, $tz = null ) => gmdate( (string) $format, null === $ts ? time() : (int) $ts ) );
		Functions\when( 'current_time' )->alias( static fn ( $type, $gmt = 0 ) => 'timestamp' === $type || 'U' === $type ? time() : gmdate( 'Y-m-d H:i:s' ) );
		Functions\when( 'get_option' )->alias(
			fn ( $name, $default = false ) => match ( $name ) {
				'omnabase_facts'  => $this->option,
				'timezone_string' => 'UTC',
				'gmt_offset'      => 0,
				default           => $default,
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				if ( 'omnabase_facts' === $name ) {
					$this->option = $value;
				}
				return true;
			}
		);
		Functions\when( 'get_userdata' )->alias( fn ( $id ) => isset( $this->usermeta[ (int) $id ] ) ? (object) array( 'ID' => (int) $id ) : false );
		Functions\when( 'metadata_exists' )->alias( fn ( $type, $id, $key ) => 'user' === $type && array_key_exists( (string) $key, $this->usermeta[ (int) $id ] ?? array() ) );
		Functions\when( 'get_user_meta' )->alias(
			function ( $id, $key = '', $single = false ) {
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

	/* ---------- the condition on the value ---------- */

	/**
	 * What it checks: A licence field whose value must equal a word counts only the members whose value is that word, compared without
	 *   capitals and ignoring spaces around it (pinned Q4).
	 * Passes when: with "equals" and the text " Enfold-PRO ", a member holding "enfold-pro" has one licence (its key masked to "••••--pro", the
	 *   last four characters), and a member holding "enfold-basic" has an empty list (no licence now).
	 * Fails when: every member with any value is a licence holder although the site marks only some values as licensed, or a member whose
	 *   value is written in other capitals is shown without a licence.
	 */
	public function test_equals_counts_only_the_matching_value(): void {
		$this->configure( 'av_licence_kind', 'Enfold', array( 'condition' => 'equals', 'condition_text' => ' Enfold-PRO ' ) );
		$this->user( 7, array( 'av_licence_kind' => 'enfold-pro' ) );
		$this->user( 8, array( 'av_licence_kind' => 'enfold-basic' ) );

		$this->assertCount( 1, (array) $this->collect( 7 ), 'the matching value is a licence' );
		$this->assertSame( '••••--pro', ( (array) $this->collect( 7 )[0] )['keyMasked'] ?? null, 'the mask is still the value\'s last four characters' );
		$this->assertSame( array(), $this->collect( 8 ), 'another value is no licence now' );
	}

	/**
	 * What it checks: A licence field whose value must contain a word counts the members whose value holds it anywhere, without capitals.
	 * Passes when: with "contains" and "PRO", "Enfold Pro (lifetime)" is a licence and "Enfold Basic" is an empty list.
	 * Fails when: the "contains" choice behaves like "equals" (a longer value never matches) or like "any value" (every value matches).
	 */
	public function test_contains_counts_a_value_holding_the_text(): void {
		$this->configure( 'av_licence_kind', 'Enfold', array( 'condition' => 'contains', 'condition_text' => 'PRO' ) );
		$this->user( 7, array( 'av_licence_kind' => 'Enfold Pro (lifetime)' ) );
		$this->user( 8, array( 'av_licence_kind' => 'Enfold Basic' ) );

		$this->assertCount( 1, (array) $this->collect( 7 ) );
		$this->assertSame( array(), $this->collect( 8 ) );
	}

	/**
	 * What it checks: (guard) "Any value" keeps today's rule: every non-empty value is a licence, and it is the setting a site starts with.
	 * Passes when: with no condition stored (a site set up before this round) and with "any" stored, a member holding a purchase code has one
	 *   active licence.
	 * Fails when: updating the plugin takes kriesi.at's Enfold licences away because a condition the owner never set filters them out.
	 */
	public function test_guard_any_value_keeps_todays_rule(): void {
		$this->user( 7, array( 'av_purchase_code' => self::CODE ) );

		$this->option = array( 'sources' => array( 'user_meta_licence' => true ), 'meta' => array(), 'licence_meta' => array( 'key' => 'av_purchase_code', 'product' => 'Enfold' ) );
		$this->assertSame( 'active', ( (array) ( $this->collect( 7 )[0] ?? array() ) )['status'] ?? null, 'an option from before this round' );

		$this->configure( 'av_purchase_code', 'Enfold', array( 'condition' => 'any' ) );
		$this->assertSame( 'active', ( (array) ( $this->collect( 7 )[0] ?? array() ) )['status'] ?? null, 'any value' );
	}

	/* ---------- the expiry ---------- */

	/**
	 * What it checks: An expiry read from a user field holding a date: active until that day, with the day carried to OmnaBase.
	 * Passes when: the expiry field `support_until` holds "2099-03-12"; the licence is "active" with expiresAt "2099-03-12"; once shaped, OmnaBase's
	 *   reader accepts the answer.
	 * Fails when: the team cannot see when support ends, or the date makes OmnaBase refuse the whole licences section.
	 */
	public function test_a_date_field_gives_active_with_its_day(): void {
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'support_until' ) );
		$this->user( 7, array( 'av_purchase_code' => self::CODE, 'support_until' => '2099-03-12' ) );

		$licence = $this->onlyLicence( 7 );
		$this->assertSame( 'active', $licence['status'] ?? null );
		$this->assertSame( '2099-03-12', $licence['expiresAt'] ?? null );
		$raw = (string) json_encode( ( new Shaper() )->shape( array( 'licences' => $this->collect( 7 ) ) )['answer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertNull( SiteFactsOracle::refusal( $raw ), $raw );
		$this->assertStringContainsString( '"expiresAt":"2099-03-12"', $raw );
	}

	/**
	 * What it checks: After the expiry day has passed, the licence says "expired" and still carries its day.
	 * Passes when: the expiry field holds "2020-01-31"; the licence is still listed (with its masked key) with status "expired" and expiresAt
	 *   "2020-01-31".
	 * Fails when: a member whose support ran out shows as "active" (the team helps without a valid support licence), or the lapsed licence
	 *   disappears so the team cannot tell "expired" from "never bought".
	 */
	public function test_after_the_day_the_licence_is_expired(): void {
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'support_until' ) );
		$this->user( 7, array( 'av_purchase_code' => self::CODE, 'support_until' => '2020-01-31' ) );

		$licence = $this->onlyLicence( 7 );
		$this->assertSame( 'expired', $licence['status'] ?? null );
		$this->assertSame( '2020-01-31', $licence['expiresAt'] ?? null );
		$this->assertSame( '••••-1a2b', $licence['keyMasked'] ?? null );
	}

	/**
	 * What it checks: A date without a time lasts the whole day: support ending today is still active today.
	 * Passes when: the expiry field holds today's date (the site's time zone); the licence is "active" with today as expiresAt.
	 * Fails when: a member writing on their last day of support is told it has expired.
	 */
	public function test_a_date_without_a_time_lasts_the_whole_day(): void {
		$today = gmdate( 'Y-m-d' );
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'support_until' ) );
		$this->user( 7, array( 'av_purchase_code' => self::CODE, 'support_until' => $today ) );

		$licence = $this->onlyLicence( 7 );
		$this->assertSame( 'active', $licence['status'] ?? null );
		$this->assertSame( $today, $licence['expiresAt'] ?? null );
	}

	/**
	 * What it checks: An expiry stored as a Unix time (seconds since 1970) is read too, as a number and as text.
	 * Passes when: 4102444800 (1 January 2100) gives "active" with "2100-01-01"; the text "1577836800" (1 January 2020) gives "expired" with
	 *   "2020-01-01".
	 * Fails when: sites whose plugins store timestamps show no dates, or a timestamp is read as nonsense (a date in 1970 or in the year 4 million).
	 */
	public function test_a_unix_time_is_read_as_a_number_and_as_text(): void {
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'support_until' ) );
		$this->user( 7, array( 'av_purchase_code' => self::CODE, 'support_until' => 4102444800 ) );
		$this->user( 8, array( 'av_purchase_code' => self::CODE, 'support_until' => '1577836800' ) );

		$this->assertSame( array( 'active', '2100-01-01' ), $this->statusAndDay( 7 ) );
		$this->assertSame( array( 'expired', '2020-01-01' ), $this->statusAndDay( 8 ) );
	}

	/**
	 * What it checks: A date written as eight digits (the way ACF's date picker stores a day, "20990312") is that day, not a Unix time.
	 * Passes when: "20990312" gives "active" with "2099-03-12".
	 * Fails when: the day is read as twenty million seconds after 1970 (August 1970), and every such licence shows as long expired.
	 */
	public function test_eight_digits_are_a_calendar_day(): void {
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'support_until' ) );
		$this->user( 7, array( 'av_purchase_code' => self::CODE, 'support_until' => '20990312' ) );

		$this->assertSame( array( 'active', '2099-03-12' ), $this->statusAndDay( 7 ) );
	}

	/**
	 * What it checks: kriesi.at's real case: the expiry is one key of the list kept in one user field, written `field.key`.
	 * Passes when: the expiry is `av_purchase_data.supported_until` and the member's list holds Envato's date with its offset; a date in 2099
	 *   gives "active" with "2099-03-12", one in 2020 gives "expired" with "2020-02-21"; nothing else of the list (the buyer's name, the code
	 *   inside it) appears in the answer.
	 * Fails when: kriesi.at's support end cannot be read at all, or pieces of the stored list (the full purchase code, the Envato buyer name)
	 *   leave the site with the date.
	 */
	public function test_a_key_inside_a_list_is_read(): void {
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'av_purchase_data.supported_until' ) );
		$this->user( 7, array( 'av_purchase_code' => self::CODE, 'av_purchase_data' => $this->purchaseData( self::FUTURE_ISO ) ) );
		$this->user( 8, array( 'av_purchase_code' => self::CODE, 'av_purchase_data' => $this->purchaseData( self::PAST_ISO ) ) );

		$this->assertSame( array( 'active', '2099-03-12' ), $this->statusAndDay( 7 ) );
		$this->assertSame( array( 'expired', '2020-02-21' ), $this->statusAndDay( 8 ) );
		$out = (string) json_encode( array( $this->collect( 7 ), $this->collect( 8 ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringNotContainsString( 'secret-buyer-7b3e', $out );
		$this->assertStringNotContainsString( '7c1e9a42', $out );
	}

	/**
	 * What it checks: (guard) An expiry that cannot be read keeps the licence, without a date (pinned Q5); it passes today because no expiry
	 *   is read at all, and must keep passing once one is.
	 * Passes when: for a missing key, a missing field, an empty value, "whenever", "tomorrow" (a word that names another day each day), zero,
	 *   "0000-00-00", a negative number, a list where a date should be and a value that is a list instead of a date, the member still has one
	 *   licence, "active", with no expiresAt.
	 * Fails when: one odd row takes a paying member's licence away, the call errors, or a made-up date ("tomorrow", 1970) reaches OmnaBase.
	 */
	public function test_an_unreadable_expiry_keeps_the_licence_without_a_date(): void {
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'av_purchase_data.supported_until' ) );
		$cases = array(
			'missing key'    => array( 'purchase_code' => self::CODE ),
			'not a date'     => $this->purchaseData( 'whenever' ),
			'relative word'  => $this->purchaseData( 'tomorrow' ),
			'zero'           => $this->purchaseData( 0 ),
			'empty date'     => $this->purchaseData( '0000-00-00' ),
			'negative'       => $this->purchaseData( -86400 ),
			'empty text'     => $this->purchaseData( '' ),
			'nested rubbish' => $this->purchaseData( array( 'nested' ) ),
			'not a list'     => '2099-03-12',
		);
		$id = 10;
		foreach ( $cases as $what => $data ) {
			$this->user( ++$id, array( 'av_purchase_code' => self::CODE, 'av_purchase_data' => $data ) );
			$licence = $this->onlyLicence( $id, $what );
			$this->assertSame( 'active', $licence['status'] ?? null, $what );
			$this->assertEmpty( $licence['expiresAt'] ?? null, $what . ': no date' );
		}
		$this->user( 99, array( 'av_purchase_code' => self::CODE ) );
		$this->assertSame( 'active', $this->onlyLicence( 99, 'no list at all' )['status'] ?? null );
	}

	/**
	 * What it checks: (guard) An expiry alone is never a licence: a member with a support date but no licence value has none.
	 * Passes when: the member's list holds a 2099 date but the licence field is empty; the answer is an empty list.
	 * Fails when: every member who once had a purchase list shows a licence although the site's licence field says they hold none.
	 */
	public function test_guard_an_expiry_alone_is_no_licence(): void {
		$this->configure( 'av_purchase_code', 'Enfold', array( 'expiry' => 'av_purchase_data.supported_until' ) );
		$this->user( 7, array( 'av_purchase_data' => $this->purchaseData( self::FUTURE_ISO ) ) );

		$this->assertSame( array(), $this->collect( 7 ) );
	}

	/* ---------- the licence field may be a key of a list too ---------- */

	/**
	 * What it checks: The licence value itself may be one key of a list (review A2): the direct shop's buyers on kriesi.at have their code
	 *   only inside `av_purchase_data`.
	 * Passes when: the licence field is `av_purchase_data.purchase_code` and the expiry `av_purchase_data.supported_until`; a member with only
	 *   the list has one licence masked to the code's last four characters, "active", with "2099-03-12".
	 * Fails when: a customer who bought on kriesi.at directly shows "no licence" in OmnaBase while their forum access works.
	 */
	public function test_the_licence_field_may_be_a_key_of_a_list(): void {
		$this->configure( 'av_purchase_data.purchase_code', 'Enfold', array( 'expiry' => 'av_purchase_data.supported_until' ) );
		$this->user( 7, array( 'av_purchase_data' => $this->purchaseData( self::FUTURE_ISO ) ) );

		$licence = $this->onlyLicence( 7 );
		$this->assertSame( '••••-1a2b', $licence['keyMasked'] ?? null );
		$this->assertSame( array( 'active', '2099-03-12' ), array( $licence['status'] ?? null, $licence['expiresAt'] ?? null ) );
	}

	/* ---------- the settings are kept ---------- */

	/**
	 * What it checks: Saving the tab keeps the condition, its text and the expiry, kriesi.at's list key included.
	 * Passes when: saving "contains", " Pro " and " av_purchase_data.supported_until " stores "contains", "Pro" and
	 *   "av_purchase_data.supported_until", read back through the switches beside the field and the product.
	 * Fails when: the administrator's choice is lost on save, or the list key is refused because its field's name holds a banned word.
	 */
	public function test_saving_keeps_the_condition_and_the_expiry(): void {
		$switches = new Switches();
		$switches->save( array( 'user_meta_licence' ), array( 'user_meta_licence' ), array(), array( 'key' => 'av_purchase_code', 'product' => 'Enfold', 'condition' => 'contains', 'condition_text' => ' Pro ', 'expiry' => ' av_purchase_data.supported_until ' ) );

		$meta = $switches->licenceMeta();
		$this->assertSame( 'av_purchase_code', $meta['key'] ?? null );
		$this->assertSame( 'Enfold', $meta['product'] ?? null );
		$this->assertSame( 'contains', $meta['condition'] ?? null );
		$this->assertSame( 'Pro', $meta['condition_text'] ?? null );
		$this->assertSame( 'av_purchase_data.supported_until', $meta['expiry'] ?? null );
	}

	/**
	 * What it checks: A condition that cannot work is stored as "any value": "equals" or "contains" with no text, or a word the screen
	 *   never offers.
	 * Passes when: "equals" with an empty text, "contains" with spaces only and "regex" each read back as "any" with an empty text.
	 * Fails when: an "equals nothing" condition silently takes every member's licence away, or a forged form stores a condition the source
	 *   does not know.
	 */
	public function test_a_condition_without_text_or_unknown_is_any_value(): void {
		$switches = new Switches();
		foreach ( array( array( 'equals', '' ), array( 'contains', '   ' ), array( 'regex', '.*' ) ) as [ $condition, $text ] ) {
			$switches->save( array( 'user_meta_licence' ), array( 'user_meta_licence' ), array(), array( 'key' => 'av_purchase_code', 'product' => 'Enfold', 'condition' => $condition, 'condition_text' => $text ) );
			$this->assertSame( 'any', $switches->licenceMeta()['condition'] ?? null, $condition );
			$this->assertSame( '', $switches->licenceMeta()['condition_text'] ?? null, $condition );
		}
	}

	/**
	 * What it checks: The expiry follows the licence field's naming rule, and `field.key` has exactly one field and one key.
	 * Passes when: `session_tokens.expires`, `wp_capabilities.administrator`, `a b`, `.supported_until`, `av_purchase_data.`,
	 *   `av_purchase_data.supported_until.day` and a 65-character field part are each stored as an empty expiry; `support_until` and
	 *   `av_purchase_data.supported_until` are kept.
	 * Fails when: a slip of the keyboard reads WordPress's own secrets as a date source, or a name the source cannot read is stored and the
	 *   tab looks set up while no date ever appears.
	 */
	public function test_the_expiry_name_is_checked(): void {
		$switches = new Switches();
		$refused  = array( 'session_tokens.expires', 'wp_capabilities.administrator', 'a b', '.supported_until', 'av_purchase_data.', 'av_purchase_data.supported_until.day', str_repeat( 'a', 65 ) . '.until' );
		foreach ( $refused as $expiry ) {
			$switches->save( array( 'user_meta_licence' ), array( 'user_meta_licence' ), array(), array( 'key' => 'av_purchase_code', 'product' => 'Enfold', 'expiry' => $expiry ) );
			$this->assertSame( '', $switches->licenceMeta()['expiry'] ?? null, $expiry );
		}
		foreach ( array( 'support_until', 'av_purchase_data.supported_until' ) as $expiry ) {
			$switches->save( array( 'user_meta_licence' ), array( 'user_meta_licence' ), array(), array( 'key' => 'av_purchase_code', 'product' => 'Enfold', 'expiry' => $expiry ) );
			$this->assertSame( $expiry, $switches->licenceMeta()['expiry'] ?? null, $expiry );
		}
	}

	/**
	 * What it checks: A site that saved its licence settings before this round reads the new ones as "any value" and no expiry.
	 * Passes when: an option holding only the field and the product answers condition "any", an empty condition text and an empty expiry.
	 * Fails when: the update breaks the Customer data tab or the facts call of kriesi.at, whose option predates the new settings.
	 */
	public function test_an_older_option_reads_any_value_and_no_expiry(): void {
		$this->option = array( 'sources' => array( 'user_meta_licence' => true ), 'meta' => array(), 'licence_meta' => array( 'key' => 'av_purchase_code', 'product' => 'Enfold' ) );

		$meta = ( new Switches() )->licenceMeta();
		$this->assertSame( 'any', $meta['condition'] ?? null );
		$this->assertSame( '', $meta['condition_text'] ?? null );
		$this->assertSame( '', $meta['expiry'] ?? null );
	}

	/* ---------- helpers ---------- */

	/**
	 * What the source answers for a member.
	 *
	 * @param int $id The user id.
	 * @return array<int,mixed>|null
	 */
	private function collect( int $id ): ?array {
		$this->assertTrue( class_exists( self::SOURCE ), 'the source "Licence in a user field" exists' );
		$class = self::SOURCE;
		return ( new $class( new Switches() ) )->collect( new Person( $id, "u{$id}@example.org", true ) );
	}

	/**
	 * The member's one licence, as an array.
	 *
	 * @param int    $id   The user id.
	 * @param string $what What the case is, for the message.
	 * @return array<string,mixed>
	 */
	private function onlyLicence( int $id, string $what = '' ): array {
		$found = $this->collect( $id );
		$this->assertIsArray( $found, $what );
		$this->assertCount( 1, $found, $what . ': one licence' );
		return (array) $found[0];
	}

	/**
	 * The member's licence status and expiry day.
	 *
	 * @param int $id The user id.
	 * @return array{0:mixed,1:mixed}
	 */
	private function statusAndDay( int $id ): array {
		$licence = $this->onlyLicence( $id );
		return array( $licence['status'] ?? null, $licence['expiresAt'] ?? null );
	}

	/**
	 * kriesi.at's list, in the key order its shop writes it (`avia-shop/src/Entitlement.php`).
	 *
	 * @param mixed $until The support end as stored.
	 * @return array<string,mixed>
	 */
	private function purchaseData( $until ): array {
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
	 * Stores the source switched on with its settings.
	 *
	 * @param string              $key     The licence field.
	 * @param string              $product The product's name.
	 * @param array<string,mixed> $more    condition, condition_text, expiry.
	 */
	private function configure( string $key, string $product, array $more = array() ): void {
		$this->option = array(
			'sources'      => array( 'user_meta_licence' => true ),
			'meta'         => array(),
			'licence_meta' => array( 'key' => $key, 'product' => $product ) + $more,
		);
	}

	/**
	 * A member with these fields.
	 *
	 * @param int                 $id   The user id.
	 * @param array<string,mixed> $meta Field name => value.
	 */
	private function user( int $id, array $meta ): void {
		$this->usermeta[ $id ] = $meta;
	}
}
