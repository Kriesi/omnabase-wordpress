<?php
/**
 * The facts calls answered: the log line naming what was left out.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Facts;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The last 20 answers to OmnaBase's customer question: when, who was found (a user, a guest by address, or nobody), the sections sent and
 * the ones the shaper left out. Never an address, an id or any of the data itself. The settings screen shows the last one.
 */
final class FactsLog {

	private const KEEP = 20;

	/**
	 * Adds one line.
	 *
	 * @param string   $found    `user`, `guest` or `nobody`.
	 * @param string[] $sections The sections the sources produced.
	 * @param string[] $dropped  The sections the shaper left out.
	 */
	public function add( string $found, array $sections, array $dropped ): void {
		$lines = $this->all();
		array_unshift( $lines, array( 'at' => time(), 'found' => $found, 'sections' => array_values( $sections ), 'dropped' => array_values( $dropped ) ) );
		update_option( Options::FACTS_LOG, array_slice( $lines, 0, self::KEEP ), false );
	}

	/**
	 * The lines, newest first.
	 *
	 * @return array<int,array{at:int,found:string,sections:string[],dropped:string[]}>
	 */
	public function all(): array {
		$lines = get_option( Options::FACTS_LOG, array() );
		return is_array( $lines ) ? array_values( array_filter( $lines, 'is_array' ) ) : array();
	}
}
