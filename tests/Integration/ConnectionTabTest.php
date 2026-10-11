<?php
/**
 * FIXING ROUND "THE VAULT'S KEY FROM A FILE" (2026-10-11), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the founder's order
 * ("go with the file-based key, remove the block") removes the "Stored connection" card and its "Advanced" block, so
 * `test_the_encryption_advice_sits_in_a_closed_advanced_block_at_the_end` cannot stand as it was. It is replaced by two stricter tests: the
 * Connection tab's last block is now the Status card (the Connection card while not connected) and holds no Advanced block, no key-source
 * mark and no "Stored connection"; and no tab, in either state, says anything about how the connection is locked, inside Details too
 * (the old test only kept the advice off the open page; now it may not be anywhere). The other four tests are unchanged.
 *
 * M14 round one, the red team's failing tests: the Connection tab says how the connection is doing in one plain line, keeps the technical
 * detail behind "Details", and the encryption advice behind "Advanced" (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 1, Connection: "**one status line** replaces the health table
 * ('Working both ways · checked 2 minutes ago' / 'OmnaBase cannot reach this site · Details'), wrapped in the kept class
 * `ob-wp-settings__health`; the three directions (with their `data-check`/`data-result` marks), the last customer-data call, the receiver's
 * answer, the Permissions (as words: 'Read and write tickets, read and write customers, forms, the message centre') and the Key's last four
 * behind a 'Details' disclosure; … the encryption-key advice moves into a collapsed 'Advanced' block at the end". Review S1 ("Keep
 * `data-check`/`data-result` on the check rows inside Details … Keep `.ob-wp-settings__health` on the status line's wrapper"), S2 ("Move
 * Permissions and Key into Details, or show them in words").
 *
 * The words: *disclosure*, a line such as "Details" that opens a hidden block when clicked (HTML's `<details>` with a `<summary>`), closed by
 * default; *receiver*, the site's address OmnaBase calls with replies and test pings; *permissions* (scopes), what the site's key may do at
 * OmnaBase, today shown as codes such as `tickets:read`; *encryption-key advice*, the line telling an owner whose secret keys live in the
 * database to add one line to wp-config.php (WordPress's own settings file).
 *
 * Shapes the red team pins (chosen where the brief names none; the builder may rename and tell the red team): the disclosures are
 * `<details>` elements whose `<summary>` starts with "Details" or "Advanced"; the status block keeps `.ob-wp-settings__health` and holds the
 * check rows (`data-check="me|ping|loopback"`) inside its Details; the encryption line keeps its `data-key-source` mark.
 *
 * Why these fail today: the health section is a table of three rows always open, the permissions are codes and the key's last four sit in
 * the open connection table, and the encryption advice is an open paragraph.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class ConnectionTabTest extends TabsTestCase {

	/** The site connected; "Check connection" ran with OmnaBase's ping answered as given and the site's own receiver answering properly. */
	private function checked( array $ping_answer, bool $ping_arrives ): string {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array(
			'GET /api/public/v1/me'       => self::answer( 200, self::meAnswer() ),
			'POST /api/public/v1/me/ping' => $ping_answer,
			'POST /omnabase/v1/events'    => self::answer( 401, array( 'code' => 'omnabase_signature', 'message' => 'unsigned', 'data' => array( 'status' => 401 ) ) ),
		);
		$location = $this->adminPost( 'omnabase_check' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		if ( $ping_arrives ) {
			$this->assertSame( 200, $this->deliverEvent( 'ping', 'del-ping-' . wp_generate_password( 6, false ) )->get_status() );
		}
		$this->asAdminOnTheScreen( $this->admin );
		return $this->openTab( 'connection' );
	}

	/** The status block's HTML (the element with the kept class), or ''. */
	private static function statusBlock( string $html ): string {
		$nodes = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__health' ) . ']' );
		return $nodes->length ? self::outer( $nodes->item( 0 ) ) : '';
	}

	/** The `<details>` elements of some HTML whose summary starts with a word, as HTML. */
	private static function disclosures( string $html, string $summary ): array {
		$out = array();
		foreach ( self::query( $html, '//details' ) as $d ) {
			$s = ( new \DOMXPath( $d->ownerDocument ) )->query( './summary', $d )->item( 0 );
			if ( null !== $s && preg_match( '/^\s*' . preg_quote( $summary, '/' ) . '\b/u', (string) $s->textContent ) ) {
				$out[] = array( 'html' => self::outer( $d ), 'open' => $d->hasAttribute( 'open' ) );
			}
		}
		return $out;
	}

	/**
	 * What it checks: When both directions work, the status block reads "Working both ways" and says when it was checked.
	 * Passes when: after a check whose ping arrived (and the site's receiver refused an unsigned call, as it should), the status block's
	 *   visible words, outside any Details, say "Working both ways" and "checked … ago".
	 * Fails when: an owner must read a three-row table of arrows and codes to learn that everything is fine.
	 */
	public function test_a_healthy_connection_reads_working_both_ways(): void {
		$block = self::statusBlock( $this->checked( self::answer( 202, array( 'ok' => true ) ), true ) );
		$this->assertNotSame( '', $block, 'the status block keeps its class' );
		$seen = self::visibleText( $block );
		$this->assertMatchesRegularExpression( '/working both ways/i', $seen );
		$this->assertMatchesRegularExpression( '/checked\b.*\bago\b/i', $seen );
	}

	/**
	 * What it checks: When OmnaBase cannot reach the site, the status block says so in one plain line, with the detail one click away.
	 * Passes when: after a check whose ping met a refusal at the site, the status block's visible words say "OmnaBase cannot reach this site",
	 *   do not say "Working both ways", and the block holds a closed Details.
	 * Fails when: a broken connection reads as healthy, or the owner gets the raw refusal ("answered 401") instead of a sentence.
	 */
	public function test_a_broken_return_path_reads_omnabase_cannot_reach_this_site(): void {
		$block = self::statusBlock( $this->checked( self::answer( 200, array( 'ok' => false, 'status' => 401 ) ), false ) );
		$seen  = self::visibleText( $block );
		$this->assertMatchesRegularExpression( '/OmnaBase cannot reach this site/i', $seen );
		$this->assertDoesNotMatchRegularExpression( '/working both ways/i', $seen );
		$details = self::disclosures( $block, 'Details' );
		$this->assertCount( 1, $details, 'one Details inside the status block' );
		$this->assertFalse( $details[0]['open'], 'closed by default' );
	}

	/**
	 * What it checks: The three directions, the last customer-data call and the receiver's answer sit inside the status block's Details, with
	 *   their marks kept.
	 * Passes when: inside the status block's Details are the rows marked me, ping and loopback (each with its result mark) and a line about
	 *   OmnaBase's last customer-data call; none of the three rows stands outside a Details.
	 * Fails when: the detail rows stay on the open page (the technical table the founder asked to hide), or their marks are lost and the
	 *   browser flows that read them break.
	 */
	public function test_the_directions_and_the_last_customer_data_call_sit_inside_details(): void {
		$block   = self::statusBlock( $this->checked( self::answer( 200, array( 'ok' => false, 'status' => 401 ) ), false ) );
		$details = self::disclosures( $block, 'Details' );
		$this->assertNotEmpty( $details, 'the status block has a Details' );
		foreach ( array( 'me', 'ping', 'loopback' ) as $check ) {
			$this->assertNotNull( self::attr( $details[0]['html'], "//*[@data-check='{$check}']", 'data-result' ), "the {$check} row with its result inside Details" );
		}
		$this->assertMatchesRegularExpression( '/customer[ -]data/i', self::wordsOf( self::htmlDocument( $details[0]['html'] )->documentElement ), 'the last customer-data call' );
		$this->assertSame( 0, self::query( $block, '//*[@data-check][not(ancestor::details)]' )->length, 'no check row outside a Details' );
	}

	/**
	 * What it checks: The key's permissions are said in words and, with the key's last four characters, wait behind "Details".
	 * Passes when: the Connection tab's visible words hold neither a permission code (tickets:read …) nor the key's last four; a Details on
	 *   the tab holds the key's last four and the permissions as words: read and write tickets, read and write customers, forms, the message
	 *   centre; no permission code appears anywhere on the tab.
	 * Fails when: an owner reads "customers:write portal" and wonders what the site may do, or the key's characters sit on the open page.
	 */
	public function test_permissions_in_words_and_the_key_wait_behind_details(): void {
		$html = $this->checked( self::answer( 202, array( 'ok' => true ) ), true );
		$seen = self::visibleText( $html );
		$this->assertStringNotContainsString( 'obw_…7Q2X', $seen, 'the key\'s last four are not on the open page' );
		foreach ( self::SCOPES as $code ) {
			$this->assertStringNotContainsString( $code, wp_strip_all_tags( $html ), "the code {$code} is replaced by words" );
		}
		$inside = implode( ' ', array_map( static fn ( $d ) => self::wordsOf( self::htmlDocument( $d['html'] )->documentElement ), self::disclosures( $html, 'Details' ) ) );
		$this->assertStringContainsString( 'obw_…7Q2X', $inside, 'the key\'s last four inside a Details' );
		foreach ( array( '/read and write tickets/i', '/read and write customers/i', '/\bforms?\b/i', '/message centre/i' ) as $words ) {
			$this->assertMatchesRegularExpression( $words, $inside );
		}
	}

	/** Each tab, for the data provider. */
	public static function tabs(): array {
		return array_combine( array_keys( self::TABS ), array_map( static fn ( $t ) => array( $t ), array_keys( self::TABS ) ) );
	}

	/** The cards of some HTML (the kit's card class), in page order. */
	private static function cards( string $html ): \DOMNodeList {
		return self::query( $html, '//*[' . self::hasClass( 'ob-form__section' ) . ']' );
	}

	/**
	 * What it checks: The Connection tab ends with the Status card, and the "Stored connection" card with its Advanced block is gone.
	 * Passes when: on a connected site after a check, the tab's last card is the Status card; while not connected, its last card is the
	 *   Connection card; in both states the tab has no closed block whose summary reads "Advanced", nothing with the old card's class or the
	 *   key-source mark, and no "Stored connection" heading.
	 * Fails when: the card the founder removed is still there (or comes back empty), or something new hangs below the Status card.
	 */
	public function test_the_status_card_is_the_tabs_last_block_and_no_advanced_block_is_left(): void {
		$states = array( 'connected' => $this->checked( self::answer( 202, array( 'ok' => true ) ), true ) );
		( new \OmnaBase\WordPress\Security\Vault() )->clear();
		$states['not connected'] = $this->openTab( 'connection' );
		$last = array( 'connected' => 'ob-wp-settings__health', 'not connected' => 'ob-wp-settings__connection' );

		foreach ( $states as $state => $html ) {
			$cards = self::cards( $html );
			$this->assertGreaterThan( 0, $cards->length, "{$state}: the tab has cards" );
			$final = $cards->item( $cards->length - 1 );
			$this->assertStringContainsString( ' ' . $last[ $state ] . ' ', ' ' . $final->getAttribute( 'class' ) . ' ', "{$state}: the last card is {$last[ $state ]}" );
			$this->assertSame( array(), self::disclosures( $html, 'Advanced' ), "{$state}: no Advanced block" );
			foreach ( array( 'ob-wp-settings__advanced', 'ob-wp-settings__security', 'ob-wp-settings__keysource' ) as $class ) {
				$this->assertSame( 0, self::query( $html, '//*[' . self::hasClass( $class ) . ']' )->length, "{$state}: nothing with {$class}" );
			}
			$this->assertSame( 0, self::query( $html, '//*[@data-key-source]' )->length, "{$state}: no key-source mark" );
			$this->assertStringNotContainsString( 'Stored connection', $html, "{$state}: no Stored connection card" );
		}
	}

	/**
	 * What it checks: No tab says anything about how the stored connection is locked: not on the open page, not inside Details.
	 * Passes when: on a site in full use and on a site not connected, the tab's every word (closed blocks included; scripts and styles left
	 *   out; tooltips and labels included) holds neither "Advanced" nor "Stored connection", "lock", "locks", "locked", any word starting
	 *   "encrypt", "wp-config", OMNABASE_ENCRYPTION_KEY, "secret keys" or "salts".
	 * Fails when: the advice the founder removed survives somewhere on the screen (a Details line, a help text, a tooltip), and an owner is
	 *   again told to edit a settings file.
	 *
	 * @dataProvider tabs
	 */
	public function test_no_tab_says_how_the_connection_is_locked( string $tab ): void {
		$this->busySite();
		$states = array( 'in full use' => $this->openTab( $tab ) );
		( new \OmnaBase\WordPress\Security\Vault() )->clear();
		$states['not connected'] = $this->openTab( $tab );

		foreach ( $states as $state => $html ) {
			$words = self::everyWord( $html );
			$this->assertDoesNotMatchRegularExpression( '/\bAdvanced\b/', $words, "{$tab}, {$state}" );
			$this->assertDoesNotMatchRegularExpression( '/Stored connection|\block(s|ed)?\b|\bencrypt\w*|wp-config|OMNABASE_ENCRYPTION_KEY|\bsecret keys\b|\bsalts?\b/i', $words, "{$tab}, {$state}" );
		}
	}

	/**
	 * Every word of some HTML a person could ever read: all text (closed blocks too), and the title, aria-label, placeholder and alt
	 * attributes; scripts, styles and templates left out.
	 *
	 * @param string $html A rendered tab.
	 */
	private static function everyWord( string $html ): string {
		$doc   = self::htmlDocument( $html );
		$xpath = new \DOMXPath( $doc );
		foreach ( iterator_to_array( $xpath->query( '//script | //style | //template' ) ) as $node ) {
			$node->parentNode?->removeChild( $node );
		}
		$parts = array( self::wordsOf( $doc->documentElement ) );
		foreach ( $xpath->query( '//@title | //@aria-label | //@placeholder | //@alt' ) as $attribute ) {
			$parts[] = $attribute->nodeValue;
		}
		return implode( ' ', $parts );
	}
}
