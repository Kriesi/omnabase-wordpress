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
use OmnaBase\WordPress\Facts\Shaper;
use OmnaBase\WordPress\Facts\ShopVersions;
use OmnaBase\WordPress\Facts\Switches;

defined( 'ABSPATH' ) || exit;

/**
 * Only the sources whose plugin is active are offered, each off until ticked, with "unsupported version" beside one whose plugin is older
 * than the source was built for. The picker offers the usermeta keys the newest users have (and any ticked before), never a private (`_…`)
 * or banned key. The last answer's left-out sections are named in words.
 */
final class FactsView {

	/** How many of the newest users the picker looks at for usermeta keys. */
	private const SAMPLE_USERS = 50;

	/** The shops' minimum versions. */
	private ShopVersions $versions;

	/**
	 * Builds the section.
	 *
	 * @param Switches      $switches The switches.
	 * @param FactsSource[] $sources  Every source.
	 * @param FactsLog      $log      The facts calls answered.
	 */
	public function __construct( private Switches $switches, private array $sources, private FactsLog $log ) {
		$this->versions = new ShopVersions();
	}

	/**
	 * What the template shows.
	 *
	 * @return array{sources:array<int,array{id:string,label:string,on:bool,unsupported:string}>,meta:array<int,array{key:string,on:bool}>,last:?array}
	 */
	public function build(): array {
		$sources = array();
		foreach ( $this->sources as $source ) {
			if ( $source->isAvailable() ) {
				$sources[] = array( 'id' => $source->id(), 'label' => $source->label(), 'on' => $this->switches->isOn( $source->id() ), 'unsupported' => $this->unsupported( $source->id() ) );
			}
		}
		$ticked = $this->switches->meta();
		$meta   = array_map( static fn ( $key ) => array( 'key' => $key, 'on' => in_array( $key, $ticked, true ) ), $this->offeredKeys( $ticked ) );
		$last   = $this->log->all()[0] ?? null;
		if ( null !== $last ) {
			$last['dropped'] = array_map( array( self::class, 'sectionWords' ), (array) $last['dropped'] );
		}
		return array( 'sources' => $sources, 'meta' => $meta, 'last' => $last );
	}

	/**
	 * "Unsupported version …" for a source whose plugin is too old, else ''.
	 *
	 * @param string $source The source's id.
	 */
	private function unsupported( string $source ): string {
		$old = $this->versions->unsupported( $source );
		/* translators: 1: the installed version, 2: the oldest supported version. */
		return null === $old ? '' : sprintf( __( 'unsupported version %1$s; needs %2$s or newer', 'omnabase' ), $old['installed'], $old['minimum'] );
	}

	/**
	 * A left-out section as the screen names it.
	 *
	 * @param string $section The section's name in the log.
	 */
	private static function sectionWords( string $section ): string {
		return match ( $section ) {
			'profile'               => __( 'profile', 'omnabase' ),
			'orders'                => __( 'orders', 'omnabase' ),
			'subscriptions'         => __( 'subscriptions', 'omnabase' ),
			'licences'              => __( 'licences', 'omnabase' ),
			Shaper::OLDEST_LICENCES => __( 'licences (the oldest)', 'omnabase' ),
			default                 => $section,
		};
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
