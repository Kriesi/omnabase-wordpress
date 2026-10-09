<?php
/**
 * Settings › OmnaBase in a real WordPress.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

use OmnaBase\WordPress\Plugin;
use OmnaBase\WordPress\PluginInfo;

/**
 * What it checks: in a real WordPress, the plugin adds Settings › OmnaBase for administrators and for nobody else.
 * Passes when: an administrator's Settings menu holds an "OmnaBase" entry at page=omnabase that needs the administrator's permission,
 *   and a subscriber's menu holds none.
 * Fails when: the entry is missing (an admin cannot find the plugin) or appears for users without the permission (a customer could
 *   open the plugin's settings, later its connection and keys).
 */
final class SettingsMenuTest extends \WP_UnitTestCase {

	public function test_administrators_get_settings_omnabase(): void {
		$this->assertSame( array( 'manage_options', 'OmnaBase' ), $this->menuEntryFor( 'administrator' ) );
	}

	public function test_subscribers_get_no_settings_entry(): void {
		$this->assertNull( $this->menuEntryFor( 'subscriber' ) );
	}

	/**
	 * The Settings submenu entry for page=omnabase as a user with the role sees it: [capability, title], or null.
	 *
	 * @param string $role A WordPress role.
	 * @return array{0:string,1:string}|null
	 */
	private function menuEntryFor( string $role ): ?array {
		global $submenu, $_wp_submenu_nopriv;
		$submenu            = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- a fresh admin menu per test.
		$_wp_submenu_nopriv = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		set_current_screen( 'dashboard' );

		( new Plugin( PluginInfo::fromFile( dirname( __DIR__, 2 ) . '/omnabase.php' ) ) )->boot();
		do_action( 'admin_menu' );

		foreach ( $submenu['options-general.php'] ?? array() as $entry ) {
			if ( 'omnabase' === $entry[2] ) {
				return array( $entry[1], $entry[0] );
			}
		}
		return null;
	}
}
