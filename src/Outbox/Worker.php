<?php
/**
 * The outbox's worker: sends the due rows.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Security\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * A run works at most 20 due rows (a backlog never makes one visit slow), each claimed first, so two overlapping runs never send one row
 * twice. Without a readable connection nothing is tried: rows wait for the administrator to reconnect. Each answer goes through the
 * policy (sent, waiting, needing attention). Sent and discarded rows are kept seven days.
 */
final class Worker {

	public const BATCH = 20;

	private const KEEP_SECONDS = 7 * DAY_IN_SECONDS;

	/** @var array<string,Job> The jobs by kind. */
	private array $jobs = array();

	/**
	 * Builds the worker.
	 *
	 * @param Table  $table  The table.
	 * @param Policy $policy What an answer means.
	 * @param Client $client The API client.
	 * @param Vault  $vault  Whether the site is connected.
	 * @param Job[]  $jobs   The kinds of rows.
	 */
	public function __construct( private Table $table, private Policy $policy, private Client $client, private Vault $vault, array $jobs ) {
		foreach ( $jobs as $job ) {
			$this->jobs[ $job->kind() ] = $job;
		}
	}

	/** One run (the WP-Cron job). */
	public function run(): void {
		if ( null === $this->vault->read() ) {
			return;
		}
		$now = time();
		$this->table->purge( $now - self::KEEP_SECONDS );
		foreach ( $this->table->due( $now, self::BATCH ) as $row ) {
			if ( $this->table->claim( (int) $row['id'], $now ) ) {
				$this->work( $row );
			}
		}
	}

	/**
	 * The kind in words, for the screen.
	 *
	 * @param string $kind A row's kind.
	 */
	public function label( string $kind ): string {
		return isset( $this->jobs[ $kind ] ) ? $this->jobs[ $kind ]->label() : $kind;
	}

	/**
	 * Sends one claimed row and records what happened.
	 *
	 * @param array<string,mixed> $row The row.
	 */
	private function work( array $row ): void {
		$job      = $this->jobs[ (string) $row['kind'] ] ?? null;
		$request  = null !== $job ? $job->request( $row ) : null;
		$attempts = (int) $row['attempts'] + 1;
		if ( null === $request ) {
			$this->table->finish( (int) $row['id'], $attempts, array( 'state' => Policy::ATTENTION, 'next_at' => null, 'reason' => __( 'This call can no longer be made: the user it is about was deleted.', 'omnabase' ) ), time() );
			return;
		}
		$outcome  = $this->client->call( $request['method'], $request['path'], $request['body'], $request['timeout'] );
		$decision = $this->policy->decide( $outcome, array( 'attempts' => (int) $row['attempts'], 'created_at' => (int) $row['created_at'] ), time() );
		$this->table->finish( (int) $row['id'], $attempts, $decision, time() );
		if ( Policy::SENT === $decision['state'] ) {
			$job->sent( $row );
		}
	}
}
