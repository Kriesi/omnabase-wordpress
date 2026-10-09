<?php
/**
 * Every option the plugin stores, by name.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The one list of option names. uninstall.php removes each of them, and the structure guard checks that every option name in
 * `src/` is on this list and named in uninstall.php: a new option goes here first.
 */
final class Options {

	/**
	 * The connection: round zero keeps the key here as is (a test hook writes it); round one replaces it with the vault's
	 * encrypted credentials.
	 */
	public const CONNECTION = 'omnabase_connection';

	/**
	 * All options, for uninstall.php and the structure guard.
	 */
	public const ALL = array(
		self::CONNECTION,
	);
}
