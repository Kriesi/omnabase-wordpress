<?php
/**
 * M14 round one, the red team's failing tests: every line a site owner sees on the settings screen is in plain words: no word from the one
 * banned list outside a "Details" or "Advanced" block, on any tab and in any connection state; every description within 30 words (the plain
 * run, without bbPress or the shops; `ForumsTabTest` and `PlainWordsShopsTest` cover those tabs' other faces).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 3: "Every sentence a site owner reads is rewritten for someone who
 * knows neither programming nor WordPress's insides, within the 30-word rule. **One banned list, matched as whole words** (`REST`
 * case-sensitive, the rest not): `wp-json`, `REST`, `WP-Cron`, `cron`, `DISABLE_WP_CRON`, `HMAC`, `signature`, `usermeta`, `meta key`,
 * `salts`, `encryption key`, `nonce`, `endpoint`, `webhook`, `scope`, `JSON`, `HTTP`, `401`, `409`, `5xx`. None appears in a visible line of
 * any tab; each lives only inside a Details or Advanced block, with its plain meaning first"; "Order of work › Round one": "no visible line
 * outside Details/Advanced holds a word from the banned list, … the 30-word rule holds on every tab". Review S2 ("one banned list, whole
 * words, 'REST' case-sensitive"). The plugin's `CLAUDE.md`, "Settings copy": every description at most 30 words.
 *
 * How the guard reads a tab (`RoundOneTestCase::visibleText()`): the tab as rendered, scripts and styles left out, every `<details>` whose
 * summary starts with "Details" or "Advanced" left out (any other disclosure, such as "Choose user fields", counts as visible), each piece
 * of text read on its own, web addresses dropped. Plurals of the banned nouns count too ("webhooks", "endpoints"), so the whole-word rule
 * cannot be dodged by an "s".
 *
 * The words: *banned list*, the developer words a site owner should never have to read; *Details / Advanced block*, a closed disclosure an
 * owner opens only on purpose; *tab*, one of the screen's five panels.
 *
 * Why these fail today: the screen is one page (so every tab shows everything), with "Webhook deliveries", a raw "answered 401" in the check
 * table, a 409 in the outbox's last answer, and in other states "/wp-json/ addresses" and "The encryption key is stored in your database".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class PlainWordsTest extends TabsTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
	}

	/** Each tab, for the data provider. */
	public static function tabs(): array {
		return array_combine( array_keys( self::TABS ), array_map( static fn ( $t ) => array( $t ), array_keys( self::TABS ) ) );
	}

	/**
	 * What it checks: No tab of a site in full use shows a developer word on its open page.
	 * Passes when: on a connected site with a failed check, OmnaBase's calls received, rows needing attention and waiting in the outbox and a
	 *   user field to offer, the tab's visible words hold none of the banned words (whole words; REST with its case; plurals too).
	 * Fails when: an owner who knows nothing of programming meets "webhook", "endpoint", "401" or "cron" and stops trusting they set the
	 *   plugin up right.
	 *
	 * @dataProvider tabs
	 */
	public function test_no_banned_word_on_the_open_page_of_a_busy_site( string $tab ): void {
		$this->busySite();
		$this->assertSame( array(), self::bannedIn( self::visibleText( $this->openTab( $tab ) ) ), "the {$tab} tab" );
	}

	/**
	 * What it checks: The Connection tab speaks plainly in every state it can be in, not only when things work.
	 * Passes when: not connected, not connected because the site cannot be connected (plain permalinks), disconnected in OmnaBase (a 401),
	 *   unreadable after the site's secret keys changed, and connected without a check yet: each state's visible words hold no banned word.
	 * Fails when: the moment something goes wrong (the moment an owner reads most carefully) the screen falls back to developer words such as
	 *   "/wp-json/ addresses".
	 */
	public function test_every_connection_state_speaks_plainly(): void {
		$seen = array();
		( new \OmnaBase\WordPress\Security\Vault() )->clear();
		$seen['not connected'] = $this->openTab( 'connection' );
		$this->set_permalink_structure( '' );
		$seen['not connected, plain permalinks'] = $this->openTab( 'connection' );
		$this->set_permalink_structure( '/%postname%/' );

		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
		$seen['connected, never checked'] = $this->openTab( 'connection' );

		$this->answers['GET /api/public/v1/me'] = self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) );
		$this->openTab( 'connection' );
		$seen['disconnected in OmnaBase'] = $this->openTab( 'connection' );

		( new \OmnaBase\WordPress\Security\Vault() )->save( self::CREDENTIALS );
		$this->rotateSalts();
		$seen['unreadable (secret keys changed)'] = $this->openTab( 'connection' );
		remove_all_filters( 'salt' );

		foreach ( $seen as $state => $html ) {
			$this->assertSame( array(), self::bannedIn( self::visibleText( $html ) ), $state );
		}
	}

	/**
	 * What it checks: The guard itself: it catches what it must and lets through what it may.
	 * Passes when: the reading drops a Details block and an Advanced block (with "webhook" inside) but keeps a "Choose user fields" block, drops
	 *   web addresses, and the matching finds "Webhooks", "wp-json", "REST API", "WP-Cron" and "409" while leaving "restore", "rest of",
	 *   "https", "#4090" and "endpointless" alone.
	 * Fails when: the guard misses banned words (every other test here would pass for nothing) or flags ordinary words (the builder cannot
	 *   write normal English).
	 */
	public function test_the_guard_reads_and_matches_as_the_brief_says(): void {
		$html = '<p>Fine words.</p><details><summary>Details</summary>webhook</details><details><summary>Advanced</summary>HMAC</details>'
			. '<details><summary>Choose user fields</summary>usermeta</details><p>See https://example.org/wp-json/x for more.</p>';
		$seen = self::visibleText( $html );
		$this->assertStringNotContainsString( 'webhook', $seen );
		$this->assertStringNotContainsString( 'HMAC', $seen );
		$this->assertStringContainsString( 'usermeta', $seen, 'only Details and Advanced are exempt' );
		$this->assertStringNotContainsString( 'wp-json', $seen, 'addresses are dropped' );

		$this->assertCount( 5, self::bannedIn( 'Webhooks go to /wp-json/ via the REST API; WP-Cron failed with 409.' ) );
		$this->assertSame( array(), self::bannedIn( 'Click restore; the rest of it uses https, ticket #4090, endpointless.' ) );
	}

	/**
	 * What it checks: Every description, paragraph and help line on every tab has at most 30 words.
	 * Passes when: on a site in full use, each paragraph, each element marked as a description and each list item of every tab has 30 words or
	 *   fewer (shortcode tags not counted).
	 * Fails when: a tab grows a paragraph an owner will not read.
	 *
	 * @dataProvider tabs
	 */
	public function test_every_description_on_every_tab_has_at_most_30_words( string $tab ): void {
		$this->busySite();
		$html  = $this->openTab( $tab );
		$lines = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings' ) . ']//*[self::p or self::li or ' . self::hasClass( 'description' ) . ']' );
		$this->assertGreaterThan( 0, $lines->length, "the {$tab} tab has lines to count" );
		foreach ( $lines as $line ) {
			$words = self::wordsOf( $line );
			$this->assertLessThanOrEqual( 30, str_word_count( (string) preg_replace( '/\[[^\]]*\]/', '', $words ) ), "{$tab}: {$words}" );
		}
	}
}
