<?php
/**
 * M13 round six, the red team's failing tests: the suggested privacy-policy text and the personal-data exporter.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2) choice 10: "The privacy-policy text (`wp_add_privacy_policy_content`) names
 * OmnaBase (Kriesi Media GmbH) as processor and what is sent: forum topics and replies, form messages, the switched-on customer data, licences
 * on change, and what the site receives (replies and status changes of its own tickets)"; "Round six": "the privacy text, personal-data
 * exporter hooks for the little stored locally". The plugin's DECISIONS "M13 round four" and "M13 round five" add the form messages and the
 * message centre to that text. The coordinator: "the privacy-policy text … naming OmnaBase as a processor and the pieces that send data (the
 * forum, the form, the message centre, the customer-data call)".
 *
 * The words: *privacy-policy text*, a suggested paragraph WordPress shows the site owner under Settings › Privacy to copy into their own
 * policy; *processor*, a company handling personal data on the site owner's behalf (GDPR's word); *exporter*, the piece WordPress's
 * Tools › Export Personal Data calls to gather what a plugin stores about one email address; *site id*, the plugin's id for a WordPress user
 * as OmnaBase knows them (round two). WordPress accepts the text only during `admin_init` (the moment wp-admin starts a page); added at
 * any other moment it records a mistake, which fails a test here.
 *
 * Why these fail today: the plugin registers no privacy text and no exporter.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Sync\SiteId;

final class PrivacyTest extends RoundOneTestCase {

	/** The suggested texts WordPress collected during an admin page's start, by plugin name. */
	private function suggestedTexts(): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-privacy-policy-content.php';
		$this->asAdminOnTheScreen( $this->admin );
		// WordPress's own headers at that moment: a test process has printed already, so they would warn
		remove_action( 'admin_init', 'send_frame_options_header', 10 );
		remove_action( 'admin_init', 'wp_admin_headers' );
		do_action( 'admin_init' );
		$prop = new \ReflectionProperty( \WP_Privacy_Policy_Content::class, 'policy_content' );
		$prop->setAccessible( true );
		$out = array();
		foreach ( (array) $prop->getValue() as $entry ) {
			$out[ (string) $entry['plugin_name'] ] = (string) $entry['policy_text'];
		}
		return $out;
	}

	/** The plugin's exporters from WordPress's list. */
	private static function exporters(): array {
		return array_filter( (array) apply_filters( 'wp_privacy_personal_data_exporters', array() ), static fn ( $e ) => str_contains( (string) ( $e['exporter_friendly_name'] ?? '' ), 'OmnaBase' ) );
	}

	/**
	 * What it checks: The site owner gets a suggested privacy-policy paragraph that names OmnaBase as the processor and every piece that sends
	 *   data, in time for WordPress's privacy screen.
	 * Passes when: during an admin page's start the plugin adds one text under a name holding "OmnaBase"; it names Kriesi Media GmbH,
	 *   app.omnabase.com, the word processor, forum topics and replies, form messages, the message centre, customer data (orders or
	 *   licences) and what the site receives back (replies and status changes), and links OmnaBase's privacy policy over https.
	 * Fails when: a shop owner's policy stays silent about the support service their customers' messages and orders go to, which the GDPR
	 *   requires them to name.
	 */
	public function test_the_privacy_text_names_omnabase_and_what_is_sent(): void {
		$texts = array_filter( $this->suggestedTexts(), static fn ( $name ) => str_contains( $name, 'OmnaBase' ), ARRAY_FILTER_USE_KEY );
		$this->assertCount( 1, $texts, 'one suggested text from the plugin' );
		$text = wp_strip_all_tags( (string) array_values( $texts )[0] );
		foreach ( array(
			'Kriesi Media GmbH',
			'app.omnabase.com',
			'/processor|processes[^.]*on (your|the site owner\'s|our) behalf/i',
			'/forum topics?[^.]*repl(y|ies)|repl(y|ies)[^.]*forum/i',
			'/form messages?|contact form/i',
			'/message centre/i',
			'/customer data|orders?|licen[cs]es?/i',
			'/(receives?|sends? back|returns?)[^.]*(repl(y|ies)|status)/i',
		) as $needle ) {
			if ( str_starts_with( $needle, '/' ) ) {
				$this->assertMatchesRegularExpression( $needle, $text );
			} else {
				$this->assertStringContainsString( $needle, $text );
			}
		}
		$this->assertMatchesRegularExpression( '#https://[a-z.]*omnabase\.com/[^\s"<>]*privacy#i', (string) array_values( $texts )[0], 'a link to OmnaBase\'s own privacy policy' );
		$this->assertStringNotContainsString( 'PLACEHOLDER', (string) array_values( $texts )[0] );
	}

	/**
	 * What it checks: WordPress's "Export Personal Data" tool finds what the plugin stores about a customer.
	 * Passes when: an exporter named with "OmnaBase" is registered; for a customer the plugin knows, it returns at least one item whose values
	 *   hold the customer's site id, and says it is done; for an address nobody uses, it returns nothing and is done.
	 * Fails when: a customer asks the shop for their data and the export leaves out the id that ties them to their support history.
	 */
	public function test_the_exporter_returns_the_site_id(): void {
		$exporters = self::exporters();
		$this->assertNotEmpty( $exporters, 'an OmnaBase exporter in WordPress\'s list' );
		$exporter = array_values( $exporters )[0];
		$ada      = (int) self::factory()->user->create( array( 'user_email' => 'ada.privacy@example.org', 'role' => 'subscriber' ) );
		$site_id  = ( new SiteId() )->forUser( $ada );
		update_user_meta( $ada, 'omnabase_known', '1' );

		$answer = call_user_func( $exporter['callback'], 'ada.privacy@example.org', 1 );
		$this->assertTrue( (bool) ( $answer['done'] ?? false ) );
		$values = array();
		foreach ( (array) ( $answer['data'] ?? array() ) as $item ) {
			foreach ( (array) ( $item['data'] ?? array() ) as $pair ) {
				$values[] = (string) ( $pair['value'] ?? '' );
			}
		}
		$this->assertContains( $site_id, $values, 'the site id is in the export' );

		$nobody = call_user_func( $exporter['callback'], 'nobody.here@example.org', 1 );
		$this->assertSame( array(), (array) ( $nobody['data'] ?? array( 'missing' ) ) );
		$this->assertTrue( (bool) ( $nobody['done'] ?? false ) );
	}
}
