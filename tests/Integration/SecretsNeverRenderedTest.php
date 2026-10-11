<?php
/**
 * FIXING ROUND "THE VAULT'S KEY FROM A FILE" (2026-10-11), THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: `rotateSalts()` now
 * also moves the key folder aside (see `RoundOneTestCase`), so the leak test brings the key back with `restoreVaultKey()` instead of
 * `remove_all_filters( 'salt' )`; without it, the front page and the two receivers would be read with an unreadable vault, which would test
 * less. No assertion changed. (The key file's own content is searched for in every page by `VaultFileTest`.)
 *
 * M14 ROUND ONE, THE RED TEAM: ADJUSTED, NOT WEAKENED. What changed and why: the settings screen becomes five tabs and renders only the open
 * one (brief `docs/briefs/m14-settings-cleanup.md`, choice 1), so "the screen" alone would now search only the Connection tab. The leak test
 * now also renders every tab after "Check connection" and searches each for the stored values; the last-four check still reads the
 * Connection tab's HTML (the key's last four sit there, inside Details). Stronger than before, nothing removed.
 *
 * M13 round one, the red team's failing tests: the key and the two secrets never leave the vault: not into a page (HTML or JS), not into a
 * REST answer, not into a log.
 *
 * Brief (OmnaBase `docs/briefs/m13-wordpress-plugin.md`, draft 2): choice 10 "The key and secrets never reach HTML, JS or a log (a guard
 * renders every admin and front page and searches for the **stored values** themselves, the facts secret having no prefix)"; round one's
 * *Red team*: "no rendered page contains a stored value"; review S7 ("The guard … misses the facts secret, which has no prefix … Grep for the
 * stored values instead"). The coordinator: "the key never in HTML/JS/REST answers/logs (a structure test over `src/` and `templates/`, an
 * integration test over the screen's output)".
 *
 * Shapes the red team pins: those of RoundOneTestCase. The log is PHP's error log (where `error_log()` writes), pointed at a file for the
 * test.
 *
 * Why these fail today: there is no connect and no vault, so there is nothing to protect yet.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class SecretsNeverRenderedTest extends RoundOneTestCase {

	/**
	 * What it checks: After a real connect, none of the three stored values appears in anything the site prints or answers.
	 * Passes when: the settings screen (connected, offline, after "Check connection", and each of its five tabs), the admin pages' notices,
	 *   head and footer scripts, the front page's head and footer, the two receivers' answers and the REST index hold neither the key, nor
	 *   the webhook secret, nor the facts secret; the screen does show the key's last four.
	 * Fails when: the key or a secret is printed into a page or a script variable (any visitor, browser extension or page cache could copy
	 *   it and act as the site in OmnaBase).
	 */
	public function test_no_page_script_or_rest_answer_holds_a_stored_value(): void {
		$this->connectFully();
		$this->asAdminOnTheScreen( $this->admin );
		$this->answers = array(
			'GET /api/public/v1/me'       => self::answer( 200, self::meAnswer() ),
			'POST /api/public/v1/me/ping' => self::answer( 202, array( 'ok' => true ) ),
		);
		$out = array();

		$out['the screen'] = $this->renderScreen();
		$location          = $this->adminPost( 'omnabase_check' );
		if ( null !== $location ) {
			$this->followQuery( $location );
		}
		$this->asAdminOnTheScreen( $this->admin );
		$out['the screen after Check connection'] = $this->renderScreen();
		foreach ( array_keys( self::TABS ) as $tab ) {
			$out[ "the {$tab} tab" ] = $this->openTab( $tab );
		}
		$out['the admin head and footer']         = get_echo( 'do_action', array( 'admin_enqueue_scripts', 'settings_page_omnabase' ) ) . get_echo( 'do_action', array( 'admin_head' ) ) . get_echo( 'wp_print_scripts' ) . get_echo( 'do_action', array( 'admin_footer' ) ) . get_echo( 'do_action', array( 'admin_print_footer_scripts' ) );
		$this->answers                            = array();
		$out['the screen offline']                = $this->renderScreen();
		$out['the notices']                       = $this->noticesOnTheDashboardFor( $this->admin );
		$this->rotateSalts();
		$out['the notices, salts changed']        = $this->noticesOnTheDashboardFor( $this->admin );
		$this->restoreVaultKey();

		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		$this->go_to( home_url( '/' ) );
		$out['the front page'] = get_echo( 'wp_head' ) . get_echo( 'wp_footer' );

		$out['the events answer']   = (string) wp_json_encode( $this->deliverEvent( 'ping', 'del-leak' )->get_data() );
		$out['the customer answer'] = (string) wp_json_encode( $this->deliver( '/omnabase/v1/customer', '{"v":2,"customer":{"email":"ada@example.com","external":null}}', self::FACTS )->get_data() );
		$out['the REST index']      = (string) wp_json_encode( $this->rest()->dispatch( new \WP_REST_Request( 'GET', '/' ) )->get_data() );
		$out['the plugin\'s index'] = (string) wp_json_encode( $this->rest()->dispatch( new \WP_REST_Request( 'GET', '/omnabase/v1' ) )->get_data() );

		foreach ( $out as $where => $text ) {
			foreach ( self::CREDENTIALS as $name => $value ) {
				$this->assertStringNotContainsString( $value, $text, "the {$name} value in {$where}" );
				$this->assertStringNotContainsString( wp_json_encode( $value ), $text, "the {$name} value (JSON-escaped) in {$where}" );
			}
		}
		$this->assertStringContainsString( 'obw_…7Q2X', $out['the screen'], 'the key is named by its last four only' );
	}

	/**
	 * What it checks: Failures write no stored value into the error log.
	 * Passes when: a refused exchange, a failed Disconnect and a refused check, with PHP's error log pointed at a file, leave no key or
	 *   secret in that file.
	 * Fails when: a debugging line logs a request with its Authorization header or an exchange answer, so the key ends up in a log file that
	 *   hosts, support staff and backups read.
	 */
	public function test_failures_write_no_stored_value_into_the_log(): void {
		$log = (string) tempnam( sys_get_temp_dir(), 'omnabase-log' );
		$was = ini_set( 'error_log', $log ); // phpcs:ignore WordPress.PHP.IniSet
		try {
			$state = self::fragment( $this->connectLink() )['state'];
			$this->answers['POST /api/public/v1/connect/token'] = self::answer( 500, self::exchangeAnswer() ); // a broken proxy echoing the answer with a 500
			$this->arriveBack( array( 'code' => 'code-' . str_repeat( 'e', 32 ), 'state' => $state ) );

			$this->asAdminOnTheScreen( $this->admin );
			$this->connectFully();
			$this->asAdminOnTheScreen( $this->admin );
			$this->answers = array(
				'GET /api/public/v1/me'       => self::answer( 401, array( 'error' => array( 'code' => 'unauthenticated', 'message' => 'A live site key is needed' ) ) ),
				'POST /api/public/v1/me/ping' => new \WP_Error( 'http_request_failed', 'timeout' ),
				'DELETE /api/public/v1/me'    => self::answer( 500, 'Internal Server Error' ),
			);
			$this->adminPost( 'omnabase_check' );
			$this->asAdminOnTheScreen( $this->admin );
			$this->adminPost( 'omnabase_disconnect' );
		} finally {
			ini_set( 'error_log', (string) $was ); // phpcs:ignore WordPress.PHP.IniSet
		}

		$text = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		foreach ( self::CREDENTIALS as $name => $value ) {
			$this->assertStringNotContainsString( $value, $text, "the {$name} value in the error log" );
		}
		$this->assertSame( 'empty', ( new \OmnaBase\WordPress\Security\Vault() )->state(), 'the run reached Disconnect' );
	}
}
