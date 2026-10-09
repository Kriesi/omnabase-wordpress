<?php
/**
 * The integration layer's bootstrap, inside wp-env's tests-cli container: WordPress's own test library installs a fresh WordPress in the
 * tests database, with the plugin loaded as a must-use plugin would be. WooCommerce, EDD and bbPress are loaded by the tests that need
 * them (from round two on).
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

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/omnabase.php';
	}
);

require $omnabase_tests_dir . '/includes/bootstrap.php';
