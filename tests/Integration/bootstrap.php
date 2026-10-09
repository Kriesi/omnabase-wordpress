<?php
/**
 * The integration layer's bootstrap, inside wp-env's tests-cli container: WordPress's own test library installs a fresh WordPress in the
 * tests database, with the plugin loaded as a must-use plugin would be.
 *
 * M13 round two (the red team): with OMNABASE_TEST_SHOPS=1 (bin/test-integration.sh's second run, the group "shops") the shops load too,
 * because the customer-data tests read real WooCommerce, EDD and License Manager for WooCommerce records: each is required at
 * `muplugins_loaded` when its folder is there (wp-env maps them), and its tables and roles are installed once before the first test
 * (`setup_theme`, outside the tests' transactions). The first run keeps a plain WordPress for the other tests. The paid add-ons (WooCommerce Subscriptions, EDD
 * Software Licensing, EDD Recurring) are never installed here: their fakes under `tests/fakes/` stand in, loaded after the real plugins so
 * a real add-on dropped into `paid/` would win.
 *
 * M13 round three (the red team): with OMNABASE_TEST_FORUM=1 (bin/test-integration.sh's third run, the group "forum") bbPress 2.6 loads
 * too, required at `muplugins_loaded` from wp-env's mapping, because the forum tests make real forums, topics and replies through bbPress's
 * own functions and hooks. The other two runs keep WordPress without it.
 *
 * @package OmnaBase
 */

$omnabase_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/wordpress-phpunit';
if ( ! is_file( $omnabase_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress's test library is not at {$omnabase_tests_dir}: run the integration tests through bin/test-integration.sh (wp-env).\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' );
require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once $omnabase_tests_dir . '/includes/functions.php';

/** The shops the customer-data tests read, by their main files under wp-content/plugins (wp-env's mappings). */
const OMNABASE_TEST_SHOPS = array(
	'woocommerce/woocommerce.php',
	'easy-digital-downloads/easy-digital-downloads.php',
	'license-manager-for-woocommerce/license-manager-for-woocommerce.php',
);

$omnabase_with_shops = '1' === getenv( 'OMNABASE_TEST_SHOPS' );
$omnabase_with_forum = '1' === getenv( 'OMNABASE_TEST_FORUM' );

// EDD makes its own tables when it is built inside a test run (its BerlinDB tables otherwise wait for an admin page's `admin_init`).
if ( $omnabase_with_shops ) {
	define( 'EDD_DOING_TESTS', true );
}

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $omnabase_with_shops, $omnabase_with_forum ) {
		require dirname( __DIR__, 2 ) . '/omnabase.php';
		if ( $omnabase_with_forum && is_file( WP_PLUGIN_DIR . '/bbpress/bbpress.php' ) ) {
			require_once WP_PLUGIN_DIR . '/bbpress/bbpress.php';
		}
		if ( ! $omnabase_with_shops ) {
			return;
		}
		// WooCommerce's admin app (wc-admin) phones home from admin hooks (payment suggestions): off, so the tests' record of outgoing calls
		// holds the plugin's calls alone.
		add_filter( 'woocommerce_admin_disabled', '__return_true' );
		foreach ( OMNABASE_TEST_SHOPS as $main ) {
			if ( is_file( WP_PLUGIN_DIR . '/' . $main ) ) {
				require_once WP_PLUGIN_DIR . '/' . $main;
			}
		}
		foreach ( glob( dirname( __DIR__ ) . '/fakes/*.php' ) ?: array() as $fake ) {
			require_once $fake;
		}
	}
);

// The shops' tables and roles, once, before any test (a table made inside a test would be a temporary one).
tests_add_filter(
	'setup_theme',
	static function () use ( $omnabase_with_shops ) {
		if ( ! $omnabase_with_shops ) {
			return;
		}
		if ( class_exists( 'WC_Install' ) ) {
			WC_Install::install();
		}
		if ( function_exists( 'edd_install' ) ) {
			edd_install();
		}
		if ( class_exists( '\LicenseManagerForWooCommerce\Setup' ) ) {
			\LicenseManagerForWooCommerce\Setup::install();
		}
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride -- the shops added roles (customer, shop_manager): read them again
		wp_roles();
	}
);

require $omnabase_tests_dir . '/includes/bootstrap.php';

// wp-env's tests config defines WP_HOME and WP_SITEURL (http://localhost:8889), and WordPress then answers those constants for the
// `home` and `siteurl` options whatever is stored. A test that puts the site on another address (https, a subfolder) does what a real
// site does: it changes the stored options. So the two constant filters are lifted here, once, for the integration layer only.
remove_filter( 'option_home', '_config_wp_home' );
remove_filter( 'option_siteurl', '_config_wp_siteurl' );

if ( $omnabase_with_shops ) {
	// wp-admin's top-level menu, which wp-admin/menu.php would build: WooCommerce's own `admin_menu` callbacks walk it, and the tests that
	// build Settings › OmnaBase fire `admin_menu` without a real admin request.
	$GLOBALS['menu'] = $GLOBALS['menu'] ?? array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	// EDD's Stripe gateway prints an admin notice through a class it loads only in a real admin request.
	remove_action( 'admin_notices', 'edds_apple_pay_admin_notices_print' );
}
