<?php
/**
 * M14 round one, the red team's failing tests: the plugin's row on WordPress's Plugins list gets a "Settings" link and a description that
 * says where to set it up (the plain run).
 *
 * Brief (OmnaBase `docs/briefs/m14-settings-cleanup.md`, draft 2) choice 5: "**A 'Settings' link on the Plugins list** beside Deactivate,
 * and the plugin's row description ends with 'Set it up under Settings › OmnaBase.'"; "Order of work › Round one": "the Plugins list has the
 * Settings link".
 *
 * The words: *Plugins list*, wp-admin's Plugins screen, one row per plugin with links such as Activate, Deactivate; *action links*, those
 * links, which WordPress lets a plugin extend through its `plugin_action_links` filters (a filter: a named moment where a plugin may change a
 * list before WordPress uses it); *row description*, the text under the plugin's name, read from the plugin file's header.
 *
 * Shapes pinned: WordPress's own filters, applied as its Plugins list applies them (`plugin_action_links`, then
 * `plugin_action_links_<plugin file>`), and its own header reader (`get_plugin_data`); the link's words "Settings" (WordPress's convention).
 *
 * Why these fail today: no Settings link is added, and the description ends "… orders on every ticket.".
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Integration;

final class PluginsListTest extends RoundOneTestCase {

	/** The plugin's main file as WordPress names it on the Plugins list (`omnabase/omnabase.php` in a normal install). */
	private static function pluginFile(): string {
		return plugin_basename( dirname( __DIR__, 2 ) . '/omnabase.php' );
	}

	/**
	 * What it checks: The plugin's row on the Plugins list has a "Settings" link to Settings › OmnaBase, beside WordPress's own links.
	 * Passes when: WordPress's action-link filters, run for the plugin's row with Deactivate in it, add a link reading "Settings" whose address
	 *   is options-general.php?page=omnabase, and keep Deactivate.
	 * Fails when: an owner who just activated the plugin has to hunt through the Settings menu to find where it is set up.
	 */
	public function test_the_plugins_row_has_a_settings_link(): void {
		$file  = self::pluginFile();
		$links = apply_filters( 'plugin_action_links', array( 'deactivate' => '<a href="plugins.php?action=deactivate">Deactivate</a>' ), $file, array(), 'all' );
		$links = apply_filters( "plugin_action_links_{$file}", $links, $file, array(), 'all' );

		$this->assertContains( '<a href="plugins.php?action=deactivate">Deactivate</a>', $links, 'Deactivate stays' );
		$settings = array();
		foreach ( $links as $link ) {
			$a = self::query( (string) $link, '//a' )->item( 0 );
			if ( $a instanceof \DOMElement && 'Settings' === self::wordsOf( $a ) ) {
				$settings[] = html_entity_decode( (string) $a->getAttribute( 'href' ) );
			}
		}
		$this->assertCount( 1, $settings, 'one "Settings" link' );
		$this->assertStringContainsString( 'options-general.php?page=omnabase', $settings[0] );
	}

	/**
	 * What it checks: The plugin's description on the Plugins list ends by saying where to set it up.
	 * Passes when: WordPress reads the plugin's description from its header and it ends with "Set it up under Settings › OmnaBase.".
	 * Fails when: the row says what the plugin does but not where to go next.
	 */
	public function test_the_row_description_says_where_to_set_it_up(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$data = get_plugin_data( dirname( __DIR__, 2 ) . '/omnabase.php', false, false );
		$this->assertStringEndsWith( 'Set it up under Settings › OmnaBase.', (string) $data['Description'] );
	}
}
