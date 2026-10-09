<?php
/**
 * M13 round two, the red team's failing tests: the shaper, which fits every customer-data answer to OmnaBase's rules before it is sent.
 *
 * Why it matters: OmnaBase reads the site's `omnabase` block strictly, and one field of the wrong type or length refuses the **whole** block
 * (OmnaBase `packages/shared/src/siteFacts.ts`; the brief's review S2). So the team would see nothing from the shop for a customer whose
 * profile holds one long or list-shaped value. Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 5: "**The shaper**
 * writes every answer to OmnaBase's rules before sending: each string cut to the contract's length, array or object meta values dropped,
 * empty maps written as `(object) []` so they arrive as `{}`, keys masked (`••••-` and the last four), the newest 20 per list, the encoded
 * answer under 128 KB; the plugin's own copy of the contract's checks drops a section that would still fail and **logs** it"; the banned
 * usermeta names ("never a key whose name holds `pass`, `token`, `secret`, `key`, `hash`, `session`, `auth`, `otp`, `nonce`, `code`, `licen`,
 * `card`, `iban`, `backup` or `purchase`, never `user_pass`, `session_tokens`, `_two_factor_backup_codes`, even when ticked"); round two's
 * *Red team*: "a ticked serialised meta value, a 2 000-character value and a user with no meta each give an answer OmnaBase's reader
 * accepts; banned meta never appears even when ticked; keys masked; under 128 KB". The coordinator adds `salt` and `capabilities` to the
 * banned parts and asks for "a unit test with a fake answer that OmnaBase's zod schema would refuse → the shaper makes it pass".
 *
 * The judge is `Support\SiteFactsOracle`, OmnaBase's reader rewritten for the tests, itself checked here against verdicts OmnaBase's own
 * code produced (`tests/fixtures/site-facts-zod.json`). Answers are encoded as WordPress's REST server sends them: `wp_json_encode` with no
 * flags, so every non-ASCII character travels as `\uXXXX` and every `/` as `\/` (that is what the 128 KB counts).
 *
 * Shapes the red team pins (chosen; the builder may rename and tell the red team):
 * - `OmnaBase\WordPress\Facts\Shaper`, built with no arguments; `shape( array $facts ): array` answers `[ 'answer' => <the whole answer to
 *   send, an object>, 'dropped' => <names of the sections left out> ]`. `$facts` holds the sections the switched-on sources produced, in the
 *   contract's own field names (`profile` with `registeredAt`, `roles`, `meta`; `orders`, `subscriptions`, `licences` as lists, newest first),
 *   raw: whatever WordPress and the shops handed over. A section that is absent from `$facts` is absent from the answer.
 * - No section at all answers an empty object (`{}`), never a list.
 * - (chosen) The order sections are dropped in when the answer is too large: profile first, then subscriptions, then orders; licences last
 *   (they are what OmnaBase copies on a refresh).
 * - Only these WordPress functions are stubbed (the shaper is pure PHP besides): `wp_json_encode`, `wp_check_invalid_utf8`,
 *   `wp_strip_all_tags`, `wp_parse_url`, `esc_url_raw`, `apply_filters` and the translation and escaping functions.
 *
 * Why these fail today: there is no `Facts\Shaper` (round one's customer receiver answers `{}` to everything).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Facts\Shaper;
use OmnaBase\WordPress\Tests\Unit\Support\SiteFactsOracle;

final class FactsShaperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		Functions\when( 'wp_json_encode' )->alias( static fn ( $data, $options = 0, $depth = 512 ) => json_encode( $data, $options, $depth ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'wp_check_invalid_utf8' )->alias( static fn ( $s, $strip = false ) => mb_check_encoding( (string) $s, 'UTF-8' ) ? (string) $s : ( $strip ? (string) mb_convert_encoding( (string) $s, 'UTF-8', 'UTF-8' ) : '' ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn ( $s ) => trim( strip_tags( (string) $s ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( (string) $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		Functions\when( 'esc_url_raw' )->returnArg();
	}

	/**
	 * What it checks: The tests' stand-in for OmnaBase's reader judges exactly as OmnaBase's own code does.
	 * Passes when: for every answer in the recorded set (58 answers, from "nothing at all" to "a block over 128 KB"), the stand-in accepts it
	 *   exactly when OmnaBase's real reader accepted it.
	 * Fails when: the stand-in drifted from OmnaBase's rules, so every other test in this file could pass while the team's card shows an error.
	 */
	public function test_the_contract_oracle_agrees_with_omnabases_own_reader(): void {
		$cases = json_decode( (string) file_get_contents( OMNABASE_REPO . '/tests/fixtures/site-facts-zod.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertGreaterThan( 50, count( $cases ) );
		foreach ( $cases as $case ) {
			$this->assertSame( $case['accepted'], SiteFactsOracle::accepts( $case['json'] ), $case['name'] . ' (OmnaBase: ' . ( $case['why'] ?? 'accepted' ) . ')' );
		}
	}

	/**
	 * What it checks: Each kind of real-world data that would make OmnaBase refuse the whole block is fixed before sending, and nothing else
	 *   of the answer is lost.
	 * Passes when: for each case below, the raw answer is one OmnaBase refuses (or cannot even be encoded), while the shaped answer is accepted
	 *   and keeps what can be kept (a long value cut, not dropped; the other orders still there).
	 * Fails when: one customer's odd profile field or one broken order blanks the shop's whole section on the team's card, or the plugin answers
	 *   something that is not JSON at all.
	 *
	 * @dataProvider refusedRawAnswers
	 *
	 * @param array    $facts What the sources handed over.
	 * @param callable $check Extra checks on the shaped block (an object), beyond OmnaBase accepting it.
	 */
	public function test_an_answer_omnabase_would_refuse_is_shaped_into_one_it_accepts( array $facts, callable $check ): void {
		$raw = json_encode( array( 'omnabase' => $facts ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertTrue( false === $raw || ! SiteFactsOracle::accepts( $raw ), 'the case is one OmnaBase refuses before shaping: ' . ( false === $raw ? '' : (string) SiteFactsOracle::refusal( $raw ) ) );

		$json = $this->shapedJson( $facts );

		$this->assertNull( SiteFactsOracle::refusal( $json ), 'OmnaBase accepts the shaped answer' );
		$check( json_decode( $json )->omnabase, $this );
	}

	/** The cases: what the sources hand over, and what must survive. */
	public static function refusedRawAnswers(): array {
		$x       = static fn ( int $n, string $c = 'x' ) => str_repeat( $c, $n );
		$order   = static fn ( array $o = array() ) => $o + array( 'number' => '4711', 'date' => '2026-09-30', 'total' => '59.00 EUR', 'status' => 'completed' );
		$licence = static fn ( array $l = array() ) => $l + array( 'product' => 'Enfold', 'keyMasked' => '••••-7Q2X', 'status' => 'active' );
		$meta    = static fn ( array $m ) => array( 'profile' => array( 'registeredAt' => '2021-03-04', 'roles' => array( 'customer' ), 'meta' => $m + array( 'billing_country' => 'AT' ) ) );
		$kept    = static fn ( $b, $t ) => $t->assertSame( 'AT', $b->profile->meta->billing_country ?? null, 'the other meta survives' );
		return array(
			'a 2 000-character meta value'          => array(
				$meta( array( 'bio' => $x( 2000 ) ) ),
				static function ( $b, $t ) use ( $x, $kept ) {
					$t->assertStringStartsWith( $x( 100 ), $b->profile->meta->bio, 'cut, not dropped' );
					$t->assertLessThanOrEqual( 1000, mb_strlen( $b->profile->meta->bio ) );
					$kept( $b, $t );
				},
			),
			'a meta value of 1 500 accented letters' => array(
				$meta( array( 'city' => $x( 1500, 'é' ) ) ),
				static function ( $b, $t ) use ( $kept ) {
					$t->assertTrue( mb_check_encoding( $b->profile->meta->city, 'UTF-8' ), 'cut between letters, never inside one' );
					$t->assertLessThanOrEqual( 1000, mb_strlen( $b->profile->meta->city ) );
					$kept( $b, $t );
				},
			),
			'a meta value of 1 200 emoji'            => array(
				$meta( array( 'mood' => $x( 1200, '😀' ) ) ),
				static fn ( $b, $t ) => $t->assertLessThanOrEqual( 1000, mb_strlen( $b->profile->meta->mood ?? '' ) ),
			),
			'a ticked serialised meta value (a list)' => array(
				$meta( array( 'preferences' => array( 'newsletter' => true, 'tags' => array( 'a', 'b' ) ) ) ),
				static function ( $b, $t ) use ( $kept ) {
					$t->assertFalse( property_exists( $b->profile->meta, 'preferences' ), 'a list or map value is dropped' );
					$kept( $b, $t );
				},
			),
			'an object meta value'                   => array(
				$meta( array( 'display_prefs' => (object) array( 'a' => 1 ) ) ),
				static fn ( $b, $t ) => $kept( $b, $t ),
			),
			'a meta key of 80 characters'            => array(
				$meta( array( $x( 80, 'k' ) => 'v' ) ),
				static function ( $b, $t ) use ( $kept ) {
					foreach ( array_keys( get_object_vars( $b->profile->meta ) ) as $k ) {
						$t->assertLessThanOrEqual( 64, mb_strlen( (string) $k ) );
					}
					$kept( $b, $t );
				},
			),
			'a meta value that is not UTF-8'         => array(
				$meta( array( 'legacy' => "Gr\xFC\xDFe aus Wien" ) ),
				static fn ( $b, $t ) => $kept( $b, $t ),
			),
			'a meta value that is not a finite number' => array(
				$meta( array( 'score' => INF ) ),
				static fn ( $b, $t ) => $kept( $b, $t ),
			),
			'a user with no meta'                    => array(
				array( 'profile' => array( 'registeredAt' => '2021-03-04', 'roles' => array( 'customer' ), 'meta' => array() ) ),
				static fn ( $b, $t ) => $t->assertTrue( ! isset( $b->profile->meta ) || array() === get_object_vars( $b->profile->meta ), 'no meta, or an empty object' ),
			),
			'an empty profile'                       => array(
				array( 'profile' => array() ),
				static fn ( $b, $t ) => $t->assertTrue( ! isset( $b->profile ) || is_object( $b->profile ), 'an object, null or nothing' ),
			),
			'a null registration date'               => array(
				array( 'profile' => array( 'registeredAt' => null, 'roles' => array( 'customer' ) ) ),
				static fn ( $b, $t ) => $t->assertSame( array( 'customer' ), $b->profile->roles ),
			),
			'a role of 70 characters'                => array(
				array( 'profile' => array( 'roles' => array( 'customer', $x( 70, 'r' ) ) ) ),
				static fn ( $b, $t ) => $t->assertContains( 'customer', $b->profile->roles ),
			),
			'an order number that is a number'       => array(
				array( 'orders' => array( $order( array( 'number' => 4711 ) ) ) ),
				static fn ( $b, $t ) => $t->assertSame( '4711', $b->orders[0]->number, 'written as text' ),
			),
			'an order without a number among good ones' => array(
				array( 'orders' => array( $order( array( 'number' => '1' ) ), $order( array( 'number' => '' ) ), $order( array( 'number' => '3' ) ) ) ),
				static fn ( $b, $t ) => $t->assertSame( array( '1', '3' ), array_map( static fn ( $o ) => $o->number, $b->orders ), 'only the broken order goes' ),
			),
			'a null order date'                      => array(
				array( 'orders' => array( $order( array( 'date' => null ) ) ) ),
				static fn ( $b, $t ) => $t->assertSame( '4711', $b->orders[0]->number ),
			),
			'an order status of 100 characters'      => array(
				array( 'orders' => array( $order( array( 'status' => $x( 100, 's' ) ) ) ) ),
				static fn ( $b, $t ) => $t->assertLessThanOrEqual( 60, mb_strlen( $b->orders[0]->status ?? '' ) ),
			),
			'orders with gaps in their keys'         => array(
				array( 'orders' => array( 0 => $order( array( 'number' => '1' ) ), 2 => $order( array( 'number' => '2' ) ) ) ),
				static fn ( $b, $t ) => $t->assertSame( array( '1', '2' ), array_map( static fn ( $o ) => $o->number, $b->orders ), 'a list, in order' ),
			),
			'an admin link of 2 500 characters'      => array(
				array( 'orders' => array( $order( array( 'href' => 'https://shop.example.org/wp-admin/post.php?post=1&x=' . $x( 2450 ) ) ) ) ),
				static fn ( $b, $t ) => $t->assertSame( '4711', $b->orders[0]->number, 'the order stays; the link goes' ),
			),
			'a product of 200 characters'            => array(
				array( 'licences' => array( $licence( array( 'product' => $x( 200, 'p' ) ) ) ) ),
				static function ( $b, $t ) use ( $x ) {
					$t->assertStringStartsWith( $x( 50, 'p' ), $b->licences[0]->product );
					$t->assertLessThanOrEqual( 120, mb_strlen( $b->licences[0]->product ) );
				},
			),
			'a blank product beside a good licence'  => array(
				array( 'licences' => array( $licence( array( 'product' => '   ' ) ), $licence( array( 'product' => 'Omnalingo Pro' ) ) ) ),
				static fn ( $b, $t ) => $t->assertSame( array( 'Omnalingo Pro' ), array_map( static fn ( $l ) => $l->product, $b->licences ) ),
			),
			'a licence site of 300 characters'       => array(
				array( 'licences' => array( $licence( array( 'sites' => array( 'kunde.example', $x( 300, 's' ) ) ) ) ) ),
				static function ( $b, $t ) {
					$t->assertContains( 'kunde.example', $b->licences[0]->sites );
					foreach ( $b->licences[0]->sites as $site ) {
						$t->assertLessThanOrEqual( 200, mb_strlen( $site ) );
					}
				},
			),
			'a subscription product as a number'     => array(
				array( 'subscriptions' => array( array( 'product' => 1234, 'status' => 'active' ) ) ),
				static fn ( $b, $t ) => $t->assertSame( '1234', $b->subscriptions[0]->product ),
			),
		);
	}

	/**
	 * What it checks: Each list holds at most 20 items, the newest ones, and a licence at most 20 sites.
	 * Passes when: 25 orders, 25 subscriptions and 25 licences (newest first) come out as the first 20 of each, in the same order, and a licence
	 *   with 30 sites keeps 20.
	 * Fails when: the plugin sends the oldest orders, or its answer grows with every purchase until OmnaBase cuts it somewhere else.
	 */
	public function test_each_list_keeps_the_newest_twenty(): void {
		$numbers = array_map( 'strval', range( 1, 25 ) );
		$facts   = array(
			'orders'        => array_map( static fn ( $n ) => array( 'number' => $n, 'status' => 'completed' ), $numbers ),
			'subscriptions' => array_map( static fn ( $n ) => array( 'product' => "Plan {$n}" ), $numbers ),
			'licences'      => array_map( static fn ( $n ) => array( 'product' => "Product {$n}", 'sites' => array_map( static fn ( $s ) => "site{$s}.example", range( 1, 30 ) ) ), $numbers ),
		);

		$block = json_decode( $this->shapedJson( $facts ) )->omnabase;

		$this->assertSame( array_slice( $numbers, 0, 20 ), array_map( static fn ( $o ) => $o->number, $block->orders ) );
		$this->assertCount( 20, $block->subscriptions );
		$this->assertSame( 'Plan 1', $block->subscriptions[0]->product );
		$this->assertCount( 20, $block->licences );
		$this->assertCount( 20, $block->licences[0]->sites );
	}

	/**
	 * What it checks: A licence key never leaves the site in full; only its last four characters are shown.
	 * Passes when: a licence handed over with its full key comes out as "••••-7Q2X", and the full key appears nowhere in the answer.
	 * Fails when: the shop's licence keys reach OmnaBase's card and the AI's prompt in clear text, where anyone on the team could copy them.
	 */
	public function test_licence_keys_are_masked(): void {
		$json = $this->shapedJson( array( 'licences' => array( array( 'product' => 'Enfold', 'keyMasked' => 'ABCD-EFGH-IJKL-7Q2X', 'status' => 'active' ) ) ) );

		$this->assertSame( '••••-7Q2X', json_decode( $json )->omnabase->licences[0]->keyMasked );
		$this->assertStringNotContainsString( 'ABCD-EFGH-IJKL', $json );
		$this->assertStringNotContainsString( 'EFGH', $json );
	}

	/**
	 * What it checks: Usermeta whose name suggests a secret is never sent, even when an administrator ticked it.
	 * Passes when: each banned name below is absent from the answer (any capitalisation), while ordinary keys beside it (billing country,
	 *   nickname) are sent.
	 * Fails when: a password hash, login session, two-factor backup code, API token, purchase code or card detail lands on OmnaBase's card and in
	 *   the AI's prompt.
	 *
	 * @dataProvider bannedMetaNames
	 *
	 * @param string $name A usermeta name.
	 */
	public function test_banned_usermeta_never_appears_even_when_ticked( string $name ): void {
		$json = $this->shapedJson( array( 'profile' => array( 'meta' => array( $name => 'do-not-send-7b3e', 'billing_country' => 'AT', 'nickname' => 'ada' ) ) ) );

		$meta = (array) ( json_decode( $json )->omnabase->profile->meta ?? array() );
		$this->assertArrayNotHasKey( $name, $meta );
		$this->assertStringNotContainsString( 'do-not-send-7b3e', $json );
		$this->assertSame( 'AT', $meta['billing_country'] ?? null );
		$this->assertSame( 'ada', $meta['nickname'] ?? null );
	}

	/** The brief's banned names and name parts, the coordinator's additions, and a few spellings. */
	public static function bannedMetaNames(): array {
		$names = array(
			'user_pass', 'session_tokens', '_two_factor_backup_codes', 'wp_capabilities', 'wp_2_capabilities', 'api_key', 'API_KEY', 'stripe_secret',
			'password_hash', 'auth_token', 'PassWord', 'otp_seed', 'nonce_seen', 'login_salt', 'card_last4', 'iban', 'backup_email',
			'envato_purchase_code', 'purchase_date', 'session_expiry', 'activation_code', 'my_licence', 'license_key', 'oauth_state',
		);
		return array_combine( $names, array_map( static fn ( $n ) => array( $n ), $names ) );
	}

	/**
	 * What it checks: When nobody is found, or no source is switched on, the answer is an empty object.
	 * Passes when: shaping nothing gives exactly `{}`, which OmnaBase accepts and shows as "nothing from the site".
	 * Fails when: PHP's empty array is sent as `[]`, which OmnaBase refuses, so the card shows an error instead of an empty section.
	 */
	public function test_nothing_found_is_an_empty_object(): void {
		$this->assertSame( '{}', $this->shapedJson( array() ) );
	}

	/**
	 * What it checks: The whole answer stays under the 128 KB OmnaBase reads, measured as WordPress really sends it (every accented letter
	 *   as a six-byte escape), by leaving out the least important section.
	 * Passes when: 30 meta values of 1 000 accented letters each (60 KB as plain UTF-8, 180 KB as sent) come out under 128 KB, accepted, with
	 *   the profile named as left out and the orders, subscriptions and licences untouched.
	 * Fails when: the plugin measures the wrong encoding and sends an answer OmnaBase stops reading at 128 KB, so the card shows "too large"
	 *   instead of the customer's licences.
	 */
	public function test_an_oversized_answer_leaves_out_the_profile_first_measured_as_sent(): void {
		$meta  = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$meta[ "field_{$i}" ] = str_repeat( 'é', 1000 );
		}
		$facts = array(
			'profile'       => array( 'roles' => array( 'customer' ), 'meta' => $meta ),
			'orders'        => array( array( 'number' => '4711', 'status' => 'completed' ) ),
			'subscriptions' => array( array( 'product' => 'Enfold updates', 'status' => 'active' ) ),
			'licences'      => array( array( 'product' => 'Enfold', 'keyMasked' => '••••-7Q2X', 'status' => 'active' ) ),
		);

		$result = ( new Shaper() )->shape( $facts );
		$json   = (string) json_encode( $result['answer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertLessThanOrEqual( SiteFactsOracle::MAX_BYTES, strlen( $json ) );
		$this->assertNull( SiteFactsOracle::refusal( $json ) );
		$this->assertContains( 'profile', $result['dropped'], 'the left-out section is named (for the log line)' );
		$block = json_decode( $json )->omnabase;
		$this->assertSame( '4711', $block->orders[0]->number );
		$this->assertSame( 'Enfold updates', $block->subscriptions[0]->product );
		$this->assertSame( 'Enfold', $block->licences[0]->product );
	}

	/**
	 * What it checks: When more than the profile has to go, subscriptions go next and the licences and orders stay.
	 * Passes when: an answer of about 160 KB (20 licences with 18 long site names, 20 orders and 20 subscriptions with long admin links) comes
	 *   out under 128 KB and accepted, with subscriptions named as left out and all 20 licences and 20 orders still there.
	 * Fails when: the plugin drops the licences (what the team needs for a licence question, and what OmnaBase copies on a refresh) or sends an
	 *   answer OmnaBase refuses as too large.
	 */
	public function test_subscriptions_go_before_orders_and_licences_stay(): void {
		$link  = static fn ( int $i ) => "https://shop.example.org/wp-admin/post.php?post={$i}&action=edit&ref=" . str_repeat( 'a', 1900 ); // under the contract's 2 000, so the links are kept
		$facts = array(
			'profile'       => array( 'roles' => array( 'customer' ) ),
			'orders'        => array_map( static fn ( $i ) => array( 'number' => (string) $i, 'status' => 'completed', 'href' => $link( $i ) ), range( 1, 20 ) ),
			'subscriptions' => array_map( static fn ( $i ) => array( 'product' => "Plan {$i}", 'status' => 'active', 'href' => $link( $i ) ), range( 1, 20 ) ),
			'licences'      => array_map( static fn ( $i ) => array( 'product' => "Product {$i}", 'status' => 'active', 'sites' => array_map( static fn ( $s ) => str_repeat( 's', 196 ) . ".{$s}", range( 1, 18 ) ) ), range( 1, 20 ) ),
		);
		$raw      = strlen( (string) json_encode( array( 'omnabase' => $facts ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$no_subs  = strlen( (string) json_encode( array( 'omnabase' => array_diff_key( $facts, array( 'subscriptions' => 1 ) ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertGreaterThan( SiteFactsOracle::MAX_BYTES, $raw, 'the case is too large as it stands' );
		$this->assertLessThan( SiteFactsOracle::MAX_BYTES - 4096, $no_subs, 'the case fits once the subscriptions are left out' );

		$result = ( new Shaper() )->shape( $facts );
		$json   = (string) json_encode( $result['answer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->assertLessThanOrEqual( SiteFactsOracle::MAX_BYTES, strlen( $json ) );
		$this->assertNull( SiteFactsOracle::refusal( $json ) );
		$this->assertContains( 'subscriptions', $result['dropped'] );
		$this->assertNotContains( 'licences', $result['dropped'] );
		$block = json_decode( $json )->omnabase;
		$this->assertCount( 20, $block->licences );
		$this->assertCount( 20, $block->orders );
	}

	/** The shaped answer as WordPress's REST server sends it. */
	private function shapedJson( array $facts ): string {
		$result = ( new Shaper() )->shape( $facts );
		$this->assertIsArray( $result['dropped'] ?? null, 'the shaper names what it left out (an empty list when nothing)' );
		$this->assertIsObject( $result['answer'] ?? null, 'the answer is an object, so it can never be sent as a list' );
		$json = json_encode( $result['answer'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertIsString( $json, 'the shaped answer can be encoded: ' . json_last_error_msg() );
		return (string) $json;
	}
}
