<?php
/**
 * Plugin Name:       OmnaBase
 * Plugin URI:        https://omnabase.com/wordpress
 * Description:       Connects your site to OmnaBase, the support inbox: forum topics, form messages and your customers' orders on every ticket.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      8.1
 * Author:            Kriesi Media GmbH
 * Author URI:        https://omnabase.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       omnabase
 * Domain Path:       /languages
 *
 * @package OmnaBase
 */

defined( 'ABSPATH' ) || exit;

// The version, kept equal to the header above (bin/check-version.sh and the structure guard compare them).
define( 'OMNABASE_VERSION', '0.1.0' );

// The PSR-4 autoloader comes from Composer (`composer install` in a checkout; `--no-dev -o` in the release zip).
if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'OmnaBase is missing its vendor folder. Please reinstall the plugin from its zip.', 'omnabase' )
				. '</p></div>';
		}
	);
	return;
}
require __DIR__ . '/vendor/autoload.php';

\OmnaBase\WordPress\Bootstrap::start( __FILE__ );
