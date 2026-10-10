<?php
/**
 * Fixing round "sites in a subfolder", the red team's failing tests: Connect from a WordPress installed in a subfolder, inside wp-env's tests
 * WordPress (plain run).
 *
 * The founder (2026-10-10): "isn't that a bug? A user installing his WordPress in a subfolder could not use it." The coordinator's contract:
 * `SiteCheck::problem()` no longer refuses a path; `base()` (was `origin()`) is the home address written one way; "the connect link's `site`,
 * the exchange's `site` and the stored site record carry it; the settings screen's 'This site is known to OmnaBase as …' keeps working; the
 * `siteName()` fallback is the host". OmnaBase's side (fixed in parallel) then calls `<home>/wp-json/omnabase/v1/events` and `/customer`.
 *
 * How the subfolder is made here: the site's two addresses (WordPress's `home`, the "Site Address", and `siteurl`, the "WordPress Address")
 * are set to `https://shop.example.org/sub`, the way a subfolder install stores them. The integration bootstrap already lifts wp-env's fixed
 * addresses for this layer, so the stored options win, as on a real site (round one's refusal test made its subfolder the same way). The
 * development WordPress's harness (which forces the host for browser requests) is not involved in this layer; its parked copy gains a path
 * for the browser loop instead (see the report).
 *
 * The words: *REST address*, the `/wp-json/` address where WordPress answers programs (OmnaBase's calls arrive there); *permalinks*, the page
 * address setting; *the way back*, the settings page OmnaBase sends the administrator back to with a one-time code; *the exchange*, the
 * site's one call that trades that code for its key.
 *
 * Shapes pinned: RoundOneTestCase's (the admin actions, the way back, the fake OmnaBase); the link's and the exchange's `site` field is the
 * site's address with its folder, `https://shop.example.org/sub`, the host lower-cased and the folder's letters kept
 * (`https://Shop.Example.org/Sub` → `https://shop.example.org/Sub`); the link's `return` is the settings page under the folder; the link's
 * `name` falls back to the host alone (`shop.example.org`, no folder) when the site has no title. Refusal words are the builder's: a REST
 * address moved elsewhere is refused with a reason in plain words (no banned technical word) that does not mention a subfolder.
 *
 * Why these fail today: Connect refuses any home address with a path ("Connect needs WordPress at the root of its domain"), and the link and
 * the exchange carry `https://shop.example.org` without the folder.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Options;
use OmnaBase\WordPress\Security\Vault;

final class SubfolderTest extends RoundOneTestCase {

	/** The site in its folder, as the plugin should name it. */
	private const SUB = self::SITE . '/sub';

	public function set_up(): void {
		parent::set_up();
		self::putSiteAt( self::SUB );
	}

	public function tear_down(): void {
		remove_all_filters( 'rest_url_prefix' );
		parent::tear_down();
	}

	/**
	 * What it checks: A WordPress installed in a subfolder is offered Connect, with no refusal on the screen.
	 * Passes when: with the site at https://shop.example.org/sub (https, pretty permalinks), the Connection tab shows the Connect button and
	 *   no sentence about a subfolder or the root of the domain.
	 * Fails when: the founder's bug stays: the customer whose WordPress lives in /sub reads that the site cannot connect and has no button.
	 */
	public function test_a_subfolder_site_is_offered_connect(): void {
		$html = $this->openTab( 'connection' );

		$this->assertGreaterThan( 0, self::query( $html, '//form[.//input[@name="action" and @value="omnabase_connect"]]' )->length, 'the Connect button' );
		$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', wp_strip_all_tags( $html ) );
	}

	/**
	 * What it checks: Connect from a subfolder site names the site with its folder, so OmnaBase calls this WordPress and not the host's root.
	 * Passes when: the link to OmnaBase's connect page carries site = https://shop.example.org/sub, name = the site title, and return = the
	 *   settings page under /sub/wp-admin.
	 * Fails when: the link names https://shop.example.org, so OmnaBase sends the blog's replies and customer questions to the main site's
	 *   WordPress (which does not know them, or belongs to someone else), or sends the administrator back to the wrong admin.
	 */
	public function test_the_link_names_the_site_with_its_folder(): void {
		$p = self::fragment( $this->connectLink() );

		$this->assertSame( self::SUB, $p['site'] );
		$this->assertSame( 'Kriesi Shop', $p['name'] );
		$this->assertSame( self::SUB . '/wp-admin/options-general.php?page=omnabase', $p['return'] );
	}

	/**
	 * What it checks: The exchange (the site's one call that trades the code for its key) names the same site as the link, folder included,
	 *   and the kept connection holds the site's address with its folder.
	 * Passes when: the exchange's site field is https://shop.example.org/sub; the vault then holds the key; the kept connection's site address
	 *   is https://shop.example.org/sub; the Connection tab says "This site is known to OmnaBase as Kriesi Shop."
	 * Fails when: OmnaBase refuses the exchange as made for another site (the link said /sub, the exchange says the root), so the subfolder
	 *   site can never finish connecting, or the screen loses the line naming the site.
	 */
	public function test_the_exchange_and_the_kept_connection_carry_the_folder(): void {
		$answer                 = self::exchangeAnswer();
		$answer['site']['url']  = self::SUB;
		$answer['webhook']['url'] = self::SUB . '/wp-json/omnabase/v1/events';
		$answer['facts']['url'] = self::SUB . '/wp-json/omnabase/v1/customer';

		$this->connectFully( $answer );

		$exchanges = $this->callsTo( 'POST', '/api/public/v1/connect/token' );
		$this->assertCount( 1, $exchanges );
		$this->assertSame( self::SUB, json_decode( (string) $exchanges[0]['args']['body'], true )['site'] ?? null );
		$this->assertSame( 'ok', ( new Vault() )->state() );
		$this->assertSame( self::SUB, (string) ( get_option( Options::SITE, array() )['site']['url'] ?? '' ), 'the kept connection names the site with its folder' );
		$this->answers = array( 'GET /api/public/v1/me' => self::answer( 200, array( 'site' => array( 'id' => 'site-1', 'name' => 'Kriesi Shop', 'url' => self::SUB ) ) + self::meAnswer() ) );
		$this->assertStringContainsString( 'This site is known to OmnaBase as Kriesi Shop.', wp_strip_all_tags( $this->openTab( 'connection' ) ) );
	}

	/**
	 * What it checks: The site's address is written one way even when the stored address has capitals.
	 * Passes when: with the site stored as https://Shop.Example.org/Sub, Connect is offered and the link's site is
	 *   https://shop.example.org/Sub (the host in lower case, the folder's own letters kept), the same text the exchange then sends.
	 * Fails when: capitals in the host make the site refused, or the link and the exchange write the address differently and OmnaBase
	 *   refuses the exchange.
	 */
	public function test_capitals_in_the_host_are_written_in_lower_case_and_the_folder_kept(): void {
		self::putSiteAt( 'https://Shop.Example.org/Sub' );

		$p = self::fragment( $this->connectLink() );
		$this->assertSame( 'https://shop.example.org/Sub', $p['site'] );

		$this->answers['POST /api/public/v1/connect/token'] = self::answer( 200, self::exchangeAnswer() );
		$this->answers['GET /api/public/v1/me']             = self::answer( 200, self::meAnswer() );
		$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'c', 32 ), 'state' => $p['state'] ) );
		$this->assertSame( 'https://shop.example.org/Sub', json_decode( (string) $this->callsTo( 'POST', '/api/public/v1/connect/token' )[0]['args']['body'], true )['site'] ?? null );
	}

	/**
	 * What it checks: A subfolder site without a title is named by its host alone.
	 * Passes when: with an empty site title, the link's name is shop.example.org (no folder, no https).
	 * Fails when: the name is empty (OmnaBase's page refuses the link) or carries the address's folder and scheme into the team's site list.
	 */
	public function test_without_a_title_the_name_is_the_host(): void {
		update_option( 'blogname', '' );

		$this->assertSame( 'shop.example.org', self::fragment( $this->connectLink() )['name'] );
	}

	/**
	 * What it checks: The addresses OmnaBase calls exist under the subfolder, exactly where OmnaBase will call them.
	 * Passes when: WordPress's REST address for the plugin's events receiver is https://shop.example.org/sub/wp-json/omnabase/v1/events, the
	 *   customer receiver's is …/sub/wp-json/omnabase/v1/customer, and both routes are registered.
	 * Fails when: WordPress answers the plugin's routes somewhere other than the address OmnaBase builds from the site's address, so every
	 *   reply and customer question meets "not found".
	 */
	public function test_the_receivers_answer_under_the_folder(): void {
		$this->assertSame( self::SUB . '/wp-json/omnabase/v1/events', rest_url( 'omnabase/v1/events' ) );
		$this->assertSame( self::SUB . '/wp-json/omnabase/v1/customer', rest_url( 'omnabase/v1/customer' ) );
		$routes = $this->rest()->get_routes();
		$this->assertArrayHasKey( '/omnabase/v1/events', $routes );
		$this->assertArrayHasKey( '/omnabase/v1/customer', $routes );
	}

	/**
	 * What it checks: A subfolder site whose REST address was moved elsewhere (another plugin renamed /wp-json/) is refused before the link,
	 *   with a reason in plain words.
	 * Passes when: with WordPress's REST prefix renamed to "api", Connect does not send the browser to OmnaBase and offers no Connect button;
	 *   the screen shows a reason (the card's error line) that names no subfolder, and the tab uses none of the banned technical words
	 *   (wp-json, REST, endpoint …) outside its Details and Advanced blocks.
	 * Fails when: the administrator connects a site whose OmnaBase addresses answer "not found", or is told about folders and "REST" when
	 *   the cause is a renamed address.
	 */
	public function test_a_rest_address_elsewhere_is_refused_in_plain_words(): void {
		add_filter( 'rest_url_prefix', static fn () => 'api' );

		try {
			$location = $this->adminPost( 'omnabase_connect' );
		} catch ( \WPDieException $e ) {
			$location = null;
		}
		$this->assertStringNotContainsString( '/connect#', (string) $location, 'no link to OmnaBase' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$html = $this->openTab( 'connection' );
		$this->assertSame( 0, self::query( $html, '//form[.//input[@name="action" and @value="omnabase_connect"]]' )->length, 'no Connect button' );
		$reasons = self::query( $html, '//*[' . self::hasClass( 'ob-wp-settings__error' ) . ']' );
		$this->assertGreaterThan( 0, $reasons->length, 'the reason is shown' );
		$reason = self::wordsOf( $reasons->item( 0 ) );
		$this->assertNotSame( '', $reason );
		$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', $reason );
		$this->assertSame( array(), self::bannedIn( self::visibleText( $html ) ), 'plain words on the whole tab' );
	}

	/**
	 * What it checks: A subfolder site on http is still refused, for https, not for its folder.
	 * Passes when: with the site at http://shop.example.org/sub, Connect does not send the browser to OmnaBase and the screen names https,
	 *   not a subfolder.
	 * Fails when: an http site is sent to OmnaBase (OmnaBase only calls https sites), or is told to move WordPress out of its folder.
	 */
	public function test_an_http_subfolder_site_is_refused_for_https(): void {
		self::putSiteAt( 'http://shop.example.org/sub' );

		try {
			$location = $this->adminPost( 'omnabase_connect' );
		} catch ( \WPDieException $e ) {
			$location = null;
		}
		$this->assertStringNotContainsString( '/connect#', (string) $location );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$text = wp_strip_all_tags( $this->openTab( 'connection' ) );
		$this->assertMatchesRegularExpression( '/https/i', $text );
		$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', $text );
	}

	/**
	 * Puts the site at an address: WordPress's Site Address and WordPress Address, as a subfolder install stores them.
	 *
	 * @param string $url The address.
	 */
	private static function putSiteAt( string $url ): void {
		update_option( 'home', $url );
		update_option( 'siteurl', $url );
	}
}
