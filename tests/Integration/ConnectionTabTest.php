<?php
/**
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

	/**
	 * What it checks: The encryption advice sits in a closed "Advanced" block at the end of the Connection tab.
	 * Passes when: the line marked with the key's source is inside a closed Details whose summary reads "Advanced", that block is the last
	 *   disclosure on the tab, and neither "OMNABASE_ENCRYPTION_KEY" nor "wp-config.php" is on the open page.
	 * Fails when: every owner meets a line of PHP to paste into a settings file on the first screen they open.
	 */
	public function test_the_encryption_advice_sits_in_a_closed_advanced_block_at_the_end(): void {
		$html     = $this->checked( self::answer( 202, array( 'ok' => true ) ), true );
		$advanced = self::disclosures( $html, 'Advanced' );
		$this->assertCount( 1, $advanced, 'one Advanced block' );
		$this->assertFalse( $advanced[0]['open'], 'closed by default' );
		$this->assertNotNull( self::attr( $advanced[0]['html'], '//*[@data-key-source]', 'data-key-source' ), 'the key source line inside it' );
		$all = self::query( $html, '//details' );
		$this->assertSame( $advanced[0]['html'], self::outer( $all->item( $all->length - 1 ) ), 'the last disclosure on the tab' );
		$seen = self::visibleText( $html );
		$this->assertStringNotContainsString( 'OMNABASE_ENCRYPTION_KEY', $seen );
		$this->assertStringNotContainsString( 'wp-config.php', $seen );
	}
}
