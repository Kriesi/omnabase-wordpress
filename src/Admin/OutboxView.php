<?php
/**
 * The settings screen's outbox section: what waits, what needs the administrator, what went today.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Forum\TopicSender;
use OmnaBase\WordPress\Outbox\Policy;
use OmnaBase\WordPress\Outbox\Table;
use OmnaBase\WordPress\Outbox\Worker;

defined( 'ABSPATH' ) || exit;

/**
 * The counts, the forum topics waiting (and whether OmnaBase's daily limit holds them), and up to 20 open rows (needing attention first) with
 * their kind in words, what they are about, the next try and the last reason.
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
	 * @return array{counts:array{waiting:int,attention:int,sent:int},topics:array{count:int,next:int,because:int},rows:array<int,array<string,mixed>>}
	 */
	public function build(): array {
		$today = (int) strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 ' . wp_timezone_string() );
		$rows  = array();
		foreach ( $this->table->open( self::LIST_ROWS ) as $row ) {
			$rows[] = array(
				'id'     => (int) $row['id'],
				'state'  => (string) $row['state'],
				'kind'   => $this->worker->label( (string) $row['kind'] ),
				'user'   => $this->worker->subject( $row ),
				'nextAt' => (int) $row['next_at'],
				'reason' => (string) $row['last_error'],
			);
		}
		return array(
			'counts' => $this->table->counts( $today ),
			'topics' => $this->table->waitingOf( TopicSender::KIND, Policy::dailyLimit() ),
			'rows'   => $rows,
		);
	}
}
