<?php
/**
 * The settings screen's customer-data section: a switch per detected source, the usermeta picker, the last answer's log line.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Facts\FactsLog;
use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\MetaPolicy;
use OmnaBase\WordPress\Facts\Switches;

defined( 'ABSPATH' ) || exit;

/**
 * Only the sources whose plugin is active are offered, each off until ticked. The picker offers the usermeta keys the newest users have
 * (and any ticked before), never a private (`_…`) or banned key.
 */
final class FactsView {

	/** How many of the newest users the picker looks at for usermeta keys. */
	private const SAMPLE_USERS = 50;

	/**
	 * Builds the section.
	 *
	 * @param Switches      $switches The switches.
	 * @param FactsSource[] $sources  Every source.
	 * @param FactsLog      $log      The facts calls answered.
	 */
	public function __construct( private Switches $switches, private array $sources, private FactsLog $log ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{sources:array<int,array{id:string,label:string,on:bool}>,meta:array<int,array{key:string,on:bool}>,last:?array}
	 */
	public function build(): array {
		$sources = array();
		foreach ( $this->sources as $source ) {
			if ( $source->isAvailable() ) {
				$sources[] = array( 'id' => $source->id(), 'label' => $source->label(), 'on' => $this->switches->isOn( $source->id() ) );
			}
		}
		$ticked = $this->switches->meta();
		$meta   = array_map( static fn ( $key ) => array( 'key' => $key, 'on' => in_array( $key, $ticked, true ) ), $this->offeredKeys( $ticked ) );
		return array( 'sources' => $sources, 'meta' => $meta, 'last' => $this->log->all()[0] ?? null );
	}

	/**
	 * The keys the picker offers, sorted.
	 *
	 * @param string[] $ticked The keys ticked already.
	 * @return string[]
	 */
	private function offeredKeys( array $ticked ): array {
		$keys = $ticked;
		$ids  = get_users( array( 'number' => self::SAMPLE_USERS, 'orderby' => 'registered', 'order' => 'DESC', 'fields' => 'ID' ) );
		foreach ( $ids as $id ) {
			$keys = array_merge( $keys, array_map( 'strval', array_keys( (array) get_user_meta( (int) $id ) ) ) );
		}
		$keys = array_values( array_unique( array_filter( $keys, array( MetaPolicy::class, 'isOffered' ) ) ) );
		sort( $keys );
		return $keys;
	}
}
