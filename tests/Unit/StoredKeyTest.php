<?php
/**
 * The stored key (round zero's stand-in for the vault).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Tests\Unit;

use Brain\Monkey\Functions;
use OmnaBase\WordPress\Api\StoredKey;
use OmnaBase\WordPress\Options;

/**
 * What it checks: the site's OmnaBase key is kept in the plugin's one connection option, never loaded on every page, and an empty
 *   or damaged option counts as "not connected".
 * Passes when: saving writes the key under the connection option with autoload off; reading it back gives the key; a missing,
 *   empty or wrongly shaped option reads as no key.
 * Fails when: the key lands under another name (uninstall would leave it behind on the customer's site), is loaded into memory on
 *   every page view, or a damaged option makes the settings screen claim a connection that does not exist.
 */
final class StoredKeyTest extends TestCase {

	public function test_a_saved_key_is_written_to_the_connection_option_without_autoload(): void {
		Functions\expect( 'update_option' )->once()->with( Options::CONNECTION, array( 'key' => 'obw_example' ), false );

		( new StoredKey() )->save( 'obw_example' );
	}

	public function test_the_stored_key_reads_back(): void {
		Functions\when( 'get_option' )->justReturn( array( 'key' => 'obw_example' ) );

		$this->assertSame( 'obw_example', ( new StoredKey() )->get() );
	}

	/**
	 * @dataProvider notConnected
	 *
	 * @param mixed $stored What the option holds.
	 */
	public function test_a_missing_empty_or_damaged_option_means_not_connected( $stored ): void {
		Functions\when( 'get_option' )->justReturn( $stored );

		$this->assertNull( ( new StoredKey() )->get() );
	}

	/** @return array<string,array{mixed}> */
	public static function notConnected(): array {
		return array(
			'missing'      => array( array() ),
			'empty key'    => array( array( 'key' => '' ) ),
			'a bare value' => array( 'obw_example' ),
			'false'        => array( false ),
		);
	}
}
