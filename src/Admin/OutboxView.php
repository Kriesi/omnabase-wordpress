<?php
/**
 * The settings screen's outbox section: what waits, what needs the administrator, what went today.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Outbox\Table;
use OmnaBase\WordPress\Outbox\Worker;

defined( 'ABSPATH' ) || exit;

/**
 * The counts, and up to 20 open rows (needing attention first) with their kind in words, the user, the next try and the last reason.
 */
final class OutboxView {

	private const LIST_ROWS = 20;

	/**
	 * Builds the section.
	 *
	 * @param Table  $table  The outbox's table.
	 * @param Worker $worker The kinds in words.
	 */
	public function __construct( private Table $table, private Worker $worker ) {}

	/**
	 * What the template shows.
	 *
	 * @return array{counts:array{waiting:int,attention:int,sent:int},rows:array<int,array<string,mixed>>}
	 */
	public function build(): array {
		$today = (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 ' . wp_timezone_string() );
		$rows  = array();
		foreach ( $this->table->open( self::LIST_ROWS ) as $row ) {
			$user   = get_userdata( (int) $row['subject_id'] );
			$rows[] = array(
				'id'     => (int) $row['id'],
				'state'  => (string) $row['state'],
				'kind'   => $this->worker->label( (string) $row['kind'] ),
				'user'   => $user ? (string) $user->display_name : '#' . (int) $row['subject_id'],
				'nextAt' => (int) $row['next_at'],
				'reason' => (string) $row['last_error'],
			);
		}
		return array( 'counts' => $this->table->counts( $today ), 'rows' => $rows );
	}
}
