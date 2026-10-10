<?php
/**
 * Fixing round "sites in a subfolder", the red team's failing tests: whether a site can connect, and the one way the plugin writes the site's
 * address (no WordPress loaded: its functions are faked).
 *
 * The founder (2026-10-10): "isn't that a bug? A user installing his WordPress in a subfolder could not use it." The coordinator's design:
 * the site's address becomes its home address with its folder (`https://example.com/blog`); OmnaBase then calls
 * `<home>/wp-json/omnabase/v1/events` and `/customer`; two WordPress sites on one host in different folders are two sites with their own
 * keys. OmnaBase's side (parked beside these files: `packages/shared/src/siteBase.test.ts`) writes a site's address as: https, the host in
 * lower case, the folder with its own letters, no trailing slash, doubled slashes collapsed, the standard port 443 dropped, the root with no
 * path. The plugin sends exactly that, so the approval and the exchange compare the same text.
 *
 * The words: *home address*, WordPress's "Site Address" setting (`home`), where visitors find the site; *WordPress address*, its "WordPress
 * Address" setting (`siteurl`), where WordPress's own files and admin live (usually the same); *permalinks*, WordPress's setting for page
 * addresses ("Plain" means `?p=123` addresses, under which the `/wp-json/` addresses do not exist); *REST address*, the `/wp-json/` address
 * WordPress answers programs at (`rest_url()`); *index permalinks*, a permalink choice with `/index.php/` in every address, under which the
 * REST address is `<home>/index.php/wp-json/` and `<home>/wp-json/` does not answer.
 *
 * Shapes pinned (the builder may rename and tell the red team):
 * - `OmnaBase\WordPress\Connect\SiteCheck`, built with `new SiteCheck()`; `problem(): ?string` as before; `base(): string` replaces
 *   `origin()`.
 * - `problem()` reads `home_url()`, `rest_url()`, the global `$wp_rewrite` (`using_permalinks()`) and, for the admin check, `admin_url()` or
 *   `site_url()` (both faked here, so either may be used).
 * - (chosen) The REST check tolerates a doubled slash: WordPress builds `rest_url()` as the home address plus `/wp-json/`, so a home address
 *   stored with a trailing slash (`https://example.com/blog/`, which WordPress's own sanitising keeps) gives `https://example.com/blog//wp-json/`,
 *   and OmnaBase's `https://example.com/blog/wp-json/…` still reaches it.
 * - (chosen, contingent on OmnaBase's rule) A site whose WordPress address (and so its admin) is not under its home address is refused before
 *   the link is built: OmnaBase's connect page refuses a way back outside the site's folder ("another site"), so the administrator would
 *   approve on OmnaBase only to be refused. The usual "WordPress in its own directory" setup (home at the root, WordPress in `/wp`) is a root
 *   site and connects. If the coordinator rules OmnaBase accepts such a way back, this one case goes (record it in DECISIONS).
 * - The words of the refusals are the builder's; pinned: https and permalinks keep naming their cause, and no refusal speaks of a subfolder
 *   or the root of the domain any more.
 *
 * Why these fail today: `problem()` refuses any home address with a path ("Connect needs WordPress at the root of its domain"), and there is
 * no `base()` (`origin()` drops the path).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Connect\SiteCheck;

final class SiteCheckTest extends TestCase {

	/** The home address the fake WordPress answers. */
	private string $home = 'https://example.com';

	/** The REST address the fake WordPress answers; null: WordPress's own (the home address plus `/wp-json/`). */
	private ?string $rest = null;

	/** The WordPress address; null: the home address. */
	private ?string $siteurl = null;

	/** Whether permalinks are anything but "Plain". */
	private bool $pretty = true;

	protected function setUp(): void {
		parent::setUp();
		$this->home    = 'https://example.com';
		$this->rest    = null;
		$this->siteurl = null;
		$this->pretty  = true;
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_parse_url' )->alias( static fn ( $url, $component = -1 ) => parse_url( (string) $url, $component ) );
		Functions\when( 'home_url' )->alias( fn ( $path = '' ) => $this->home . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) ) );
		Functions\when( 'rest_url' )->alias( fn ( $path = '' ) => ( $this->rest ?? $this->home . '/wp-json/' ) . ltrim( (string) $path, '/' ) );
		Functions\when( 'get_rest_url' )->alias( fn ( $blog = null, $path = '' ) => ( $this->rest ?? $this->home . '/wp-json/' ) . ltrim( (string) $path, '/' ) );
		Functions\when( 'site_url' )->alias( fn ( $path = '' ) => ( $this->siteurl ?? $this->home ) . ( '' === $path ? '' : '/' . ltrim( (string) $path, '/' ) ) );
		Functions\when( 'admin_url' )->alias( fn ( $path = '' ) => ( $this->siteurl ?? $this->home ) . '/wp-admin/' . ltrim( (string) $path, '/' ) );
		Functions\when( 'get_option' )->alias( fn ( $name, $default = false ) => match ( $name ) {
			'home'               => $this->home,
			'siteurl'            => $this->siteurl ?? $this->home,
			'permalink_structure' => $this->pretty ? '/%postname%/' : '',
			default              => $default,
		} );
		$rewrite = \Mockery::mock( 'WP_Rewrite' );
		$rewrite->shouldReceive( 'using_permalinks' )->andReturnUsing( fn () => $this->pretty );
		$rewrite->shouldReceive( 'using_index_permalinks' )->andReturnUsing( fn () => null !== $this->rest && str_contains( (string) $this->rest, '/index.php/' ) );
		$GLOBALS['wp_rewrite'] = $rewrite;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wp_rewrite'] );
		parent::tearDown();
	}

	/**
	 * The normalisation table: the home address as stored, and the site's address as the plugin sends it.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function addresses(): array {
		return array(
			'the root'                          => array( 'https://example.com', 'https://example.com' ),
			'the root with a slash'             => array( 'https://example.com/', 'https://example.com' ),
			'a folder'                          => array( 'https://example.com/blog', 'https://example.com/blog' ),
			'a folder with a trailing slash'    => array( 'https://example.com/blog/', 'https://example.com/blog' ),
			'the host in capitals'              => array( 'https://Example.COM/blog/', 'https://example.com/blog' ),
			'the folder\'s own letters kept'    => array( 'https://example.com/Blog', 'https://example.com/Blog' ),
			'two levels'                        => array( 'https://example.com/shop/en/', 'https://example.com/shop/en' ),
			'doubled slashes'                   => array( 'https://example.com//blog//', 'https://example.com/blog' ),
			'the standard port written out'     => array( 'https://example.com:443/blog/', 'https://example.com/blog' ),
			'the scheme in capitals'            => array( 'HTTPS://example.com/blog', 'https://example.com/blog' ),
			'an encoded space kept as written'  => array( 'https://example.com/my%20blog/', 'https://example.com/my%20blog' ),
			'a sub-site of a folder network'    => array( 'https://localhost/omnabase/', 'https://localhost/omnabase' ),
		);
	}

	/**
	 * What it checks: The plugin writes the site's address one way, the same way OmnaBase does: a WordPress in a subfolder keeps its folder,
	 *   the host's capitals and a trailing or doubled slash never make another site.
	 * Passes when: each home address in the table becomes the expected address: https, the host in lower case, the folder kept with its own
	 *   letters and without a trailing slash, doubled slashes collapsed, the standard port dropped, the root written with no path.
	 * Fails when: the folder is dropped (the blog's replies and customer questions go to the main site's WordPress, which does not know them),
	 *   or the plugin writes "https://Example.com/blog/" while OmnaBase approved "https://example.com/blog", and the exchange is refused as
	 *   made for another site.
	 *
	 * @dataProvider addresses
	 *
	 * @param string $home     The home address as stored.
	 * @param string $expected The site's address as sent.
	 */
	public function test_the_sites_address_is_the_home_address_written_one_way( string $home, string $expected ): void {
		$this->home = $home;

		$this->assertSame( $expected, ( new SiteCheck() )->base() );
	}

	/**
	 * What it checks: The old "origin" (host only) is gone, so nothing can send the host without the folder by mistake.
	 * Passes when: the site check offers no origin() any more.
	 * Fails when: an old caller keeps sending "https://example.com" for a blog in /blog, and OmnaBase files it as another site.
	 */
	public function test_the_host_only_origin_is_gone(): void {
		$this->assertFalse( method_exists( SiteCheck::class, 'origin' ) );
	}

	/**
	 * The home addresses that connect (with WordPress's own REST address for each, unless given).
	 *
	 * @return array<string,array{0:string,1:?string,2:?string}>
	 */
	public static function connectable(): array {
		return array(
			'the root'                                  => array( 'https://example.com', null, null ),
			'a folder'                                  => array( 'https://example.com/blog', null, null ),
			'two levels'                                => array( 'https://example.com/shop/en', null, null ),
			'a sub-site of a folder network'            => array( 'https://localhost/omnabase', null, null ),
			'the standard port written out'             => array( 'https://example.com:443/blog', 'https://example.com:443/blog/wp-json/', null ),
			'the host in capitals'                      => array( 'https://Example.com/Blog', 'https://Example.com/Blog/wp-json/', null ),
			'a trailing slash (REST with a double one)' => array( 'https://example.com/blog/', 'https://example.com/blog//wp-json/', null ),
			'WordPress in its own folder, home at root' => array( 'https://example.com', null, 'https://example.com/wp' ),
			'WordPress in a folder under the home'      => array( 'https://example.com/blog', null, 'https://example.com/blog/wp' ),
		);
	}

	/**
	 * What it checks: A WordPress installed in a subfolder, or a sub-site of a folder network, can connect: nothing about a folder stops
	 *   Connect any more.
	 * Passes when: for each address in the list (the root, a folder, two levels, a network's sub-site, the standard port written out, capitals
	 *   in the host, a trailing slash, WordPress's files in their own folder under the home address) on https with pretty permalinks and
	 *   WordPress's own REST address, the site check finds no problem.
	 * Fails when: the founder's bug stays: a customer whose WordPress lives in /blog clicks Connect and is told the site cannot connect.
	 *
	 * @dataProvider connectable
	 *
	 * @param string      $home    The home address.
	 * @param string|null $rest    The REST address, or null for WordPress's own.
	 * @param string|null $siteurl The WordPress address, or null for the home address.
	 */
	public function test_a_site_in_a_folder_can_connect( string $home, ?string $rest, ?string $siteurl ): void {
		$this->home    = $home;
		$this->rest    = $rest;
		$this->siteurl = $siteurl;

		$this->assertNull( ( new SiteCheck() )->problem() );
	}

	/**
	 * What it checks: The refusals that still matter stay, each with its own reason: no https, another port, "Plain" permalinks.
	 * Passes when: an http site (also one in a folder) is refused naming https; https on port 8443 is refused naming https; "Plain" permalinks
	 *   are refused naming permalinks; none of the three reasons speaks of a subfolder or the root of the domain.
	 * Fails when: a site OmnaBase can never call is let through (the administrator approves, and replies never arrive), or the admin is told
	 *   to move WordPress out of its folder when the real fix is https or a permalink setting.
	 */
	public function test_http_another_port_and_plain_permalinks_are_still_refused_with_their_reason(): void {
		$cases = array(
			'an http site'           => array( 'http://example.com', true, '/https/i' ),
			'an http site in /blog'  => array( 'http://example.com/blog', true, '/https/i' ),
			'port 8443'              => array( 'https://example.com:8443/blog', true, '/https|port/i' ),
			'plain permalinks'       => array( 'https://example.com/blog', false, '/permalink/i' ),
		);
		foreach ( $cases as $name => [ $home, $pretty, $reason ] ) {
			$this->home   = $home;
			$this->rest   = null;
			$this->pretty = $pretty;

			$problem = ( new SiteCheck() )->problem();

			$this->assertNotNull( $problem, "{$name}: refused" );
			$this->assertMatchesRegularExpression( $reason, (string) $problem, "{$name}: the reason" );
			$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', (string) $problem, "{$name}: no word about a folder" );
		}
	}

	/**
	 * What it checks: A site whose WordPress answers programs somewhere other than the home address plus /wp-json/ is refused, because
	 *   that is the only address OmnaBase calls.
	 * Passes when: a REST address moved by a plugin (/blog/api/), one under /index.php/ (index permalinks), one at the host's root while the
	 *   site lives in /blog, and one on another host are each refused with a reason that says nothing about a subfolder.
	 * Fails when: the administrator connects a site whose OmnaBase addresses answer "not found", so replies and customer questions are lost
	 *   without a word.
	 */
	public function test_a_rest_address_elsewhere_is_refused(): void {
		$cases = array(
			'moved by a plugin'          => 'https://example.com/blog/api/',
			'index permalinks'           => 'https://example.com/blog/index.php/wp-json/',
			'at the root, site in /blog' => 'https://example.com/wp-json/',
			'on another host'            => 'https://api.example.com/blog/wp-json/',
		);
		foreach ( $cases as $name => $rest ) {
			$this->home = 'https://example.com/blog';
			$this->rest = $rest;

			$problem = ( new SiteCheck() )->problem();

			$this->assertNotNull( $problem, "{$name}: refused" );
			$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', (string) $problem, "{$name}: no word about a folder" );
		}
	}

	/**
	 * What it checks: (chosen, contingent on OmnaBase's rule) A site whose WordPress admin lives outside its home address's folder is refused
	 *   before the link is built.
	 * Passes when: with the home address https://example.com/blog and WordPress's own address https://example.com/wordpress (so the settings
	 *   page, the way back from OmnaBase, is under /wordpress/wp-admin), the site check finds a problem that says nothing about a subfolder.
	 * Fails when: the administrator approves on OmnaBase, OmnaBase refuses the way back as another site's page, and nobody on the WordPress
	 *   side said why.
	 */
	public function test_an_admin_outside_the_home_folder_is_refused_before_the_link(): void {
		$this->home    = 'https://example.com/blog';
		$this->siteurl = 'https://example.com/wordpress';

		$problem = ( new SiteCheck() )->problem();

		$this->assertNotNull( $problem );
		$this->assertDoesNotMatchRegularExpression( '/sub-?folder|root of (its|the) domain/i', (string) $problem );
	}
}
