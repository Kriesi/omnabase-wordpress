<?php
/**
 * M13 round six, the red team's failing test: "unsupported version" per shop on the settings screen (the shops run).
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2), "Risks": "**WooCommerce and EDD change**: public functions only, each source
 * version-checked ('unsupported version' in health)". The plugin's DECISIONS "M13 round two", "Open for round three": "'unsupported version'
 * checks per shop"; carried to round six. The coordinator: "'unsupported version' checks per shop".
 *
 * The words: *source*, one place customer data comes from (WooCommerce, WooCommerce Subscriptions, Easy Digital Downloads and its add-ons,
 * License Manager for WooCommerce); *fake*, the test stand-in for a paid add-on the founder does not own (round two, `tests/fakes/`), which
 * keeps the version where the real one keeps it (`WC_Subscriptions::$version`).
 *
 * Shapes pinned: the words "unsupported version" on the settings screen in the same line as the source's name; an old WooCommerce
 * Subscriptions is simulated by lowering the fake's `WC_Subscriptions::$version` to 2.0.0 (released 2015), put back after the test. Where on
 * the screen (health or the customer-data section) is the builder's choice.
 *
 * Why this fails today: no version is checked anywhere.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

/**
 * Needs the shops: bin/test-integration.sh runs it in its second run.
 *
 * @group shops
 */
final class ShopVersionsTest extends RoundTwoTestCase {

	private string $version = '';

	public function set_up(): void {
		parent::set_up();
		$this->answers['GET /api/public/v1/me'] = self::answer( 200, self::meAnswer() );
		$this->version = (string) \WC_Subscriptions::$version;
	}

	public function tear_down(): void {
		\WC_Subscriptions::$version = $this->version;
		parent::tear_down();
	}

	/** The screen's lines that say "unsupported version". */
	private function unsupportedLines(): array {
		$this->switchOn( self::ALL_SOURCES );
		$this->asAdminOnTheScreen( $this->admin );
		$doc = new \DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div>' . $this->renderScreen() . '</div>' );
		libxml_clear_errors();
		$out = array();
		foreach ( ( new \DOMXPath( $doc ) )->query( '//tr | //li | //p | //label' ) as $n ) {
			$t = trim( (string) preg_replace( '/\s+/', ' ', (string) $n->textContent ) );
			if ( preg_match( '/unsupported version/i', $t ) ) {
				$out[] = $t;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * What it checks: The screen warns when a shop add-on is older than the plugin supports, and stays quiet for the versions installed here.
	 * Passes when: with the newest WooCommerce, EDD and License Manager (and the add-ons' fakes) no line says "unsupported version"; with
	 *   WooCommerce Subscriptions at 2.0.0 a line naming WooCommerce Subscriptions says "unsupported version".
	 * Fails when: an old add-on silently breaks the customer card (or the site) after an update, and the owner is never told why.
	 */
	public function test_an_old_shop_add_on_reads_unsupported_version(): void {
		$this->assertSame( array(), $this->unsupportedLines(), 'today\'s versions are supported' );

		\WC_Subscriptions::$version = '2.0.0';
		$lines = $this->unsupportedLines();
		$this->assertNotEmpty( array_filter( $lines, static fn ( $l ) => str_contains( $l, 'WooCommerce Subscriptions' ) ), 'a line naming WooCommerce Subscriptions: ' . implode( ' | ', $lines ) );
	}
}
