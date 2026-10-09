<?php
/**
 * The unit and structure layers' bootstrap: Composer's autoloader and the few WordPress constants plugin files check before any
 * test runs. WordPress itself is not loaded; unit tests fake the functions they need with brain/monkey.
 *
 * @package OmnaBase
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Every runtime file starts with `defined( 'ABSPATH' ) || exit;` (direct access refused); the tests are WordPress's stand-in.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/omnabase-phpunit-wordpress/' );
}

// The repository's root, for tests that read files (the header, uninstall.php, src/).
if ( ! defined( 'OMNABASE_REPO' ) ) {
	define( 'OMNABASE_REPO', dirname( __DIR__ ) );
}
