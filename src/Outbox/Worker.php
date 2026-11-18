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
 * twice. Without a readable connection, or while OmnaBase refuses the key (401: disconnected in OmnaBase), nothing is tried: rows wait,
 * their tries untouched, for the administrator to connect again. Each row's job says what to do with
 * it (send, wait, skip, ask the administrator); each answer goes through the policy (sent, waiting, needing attention). A call the job marks
 * idempotent carries the row's own Idempotency-Key, the same on every try, and (the forum edits and pictures round, 2026-11-18) the same
 * body: the first try's body is kept in the row and sent again on every retry, so a post whose shown form changed between two tries (a link
 * preview filled in) is never read by OmnaBase as "the post changed". A step that drops "not found" ends quietly at OmnaBase's 404. Sent
 * and discarded rows are kept seven days.
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
			$this->add( $job );
		}
	}

	/**
	 * Adds a kind of row (the forum's jobs join the customer-data ones).
	 *
	 * @param Job $job The job.
	 */
	public function add( Job $job ): void {
		$this->jobs[ $job->kind() ] = $job;
	}

	/** One run (the WP-Cron job). */
	public function run(): void {
		if ( ! $this->mayCall() ) {
			return;
		}
		$now = time();
		$this->table->purge( $now - self::KEEP_SECONDS );
		foreach ( $this->table->due( $now, self::BATCH ) as $row ) {
			if ( ! $this->mayCall() ) {
				return;
			}
			if ( $this->table->claim( (int) $row['id'], $now ) ) {
				$this->work( $row );
			}
		}
	}

	/** Whether the site may call OmnaBase: a readable key that OmnaBase has not refused (a refused one pauses the outbox until Connect). */
	private function mayCall(): bool {
		return null !== $this->vault->read() && ! $this->vault->isRevoked();
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
	 * What a row is about, in words, for the screen.
	 *
	 * @param array<string,mixed> $row The row.
	 */
	public function subject( array $row ): string {
		$job = $this->jobs[ (string) $row['kind'] ] ?? null;
		return null !== $job ? $job->subject( $row ) : '#' . (int) $row['subject_id'];
	}

	/**
	 * Works one claimed row and records what happened.
	 *
	 * @param array<string,mixed> $row The row.
	 */
	private function work( array $row ): void {
		$job  = $this->jobs[ (string) $row['kind'] ] ?? null;
		$step = null !== $job ? $job->request( $row ) : Step::attention( __( 'The plugin no longer knows this kind of call.', 'omnabase' ) );
		if ( Step::SEND !== $step->kind ) {
			$this->table->finish( (int) $row['id'], (int) $row['attempts'], $this->decideWithout( $step, $row ), time() );
			return;
		}
		$outcome = $this->send( $this->sameBody( (array) $step->request, $row ), $row );
		if ( 401 === $outcome['status'] ) {
			$this->table->finish( (int) $row['id'], (int) $row['attempts'], Policy::paused( time() ), time() );
			return;
		}
		if ( 404 === $outcome['status'] && $step->dropsNotFound ) {
			$this->table->finish( (int) $row['id'], (int) $row['attempts'] + 1, array( 'state' => Policy::DISCARDED, 'next_at' => null, 'reason' => __( 'Not sent: OmnaBase does not have this post.', 'omnabase' ) ), time() );
			return;
		}
		$decision = $this->policy->decide( $outcome, array( 'attempts' => (int) $row['attempts'], 'created_at' => (int) $row['created_at'] ), time() );
		$this->table->finish( (int) $row['id'], (int) $row['attempts'] + 1, $decision, time() );
		if ( Policy::SENT === $decision['state'] && null !== $job ) {
			$job->sent( $row, $outcome['body'] );
		}
	}

	/**
	 * An idempotent call goes with the body of its row's first try: kept in the row at that try, read back on every later one.
	 *
	 * @param array{method:string,path:string,body:mixed,timeout:int,idempotent:bool} $request The call as the job built it now.
	 * @param array<string,mixed>                                                   $row     The row.
	 * @return array{method:string,path:string,body:mixed,timeout:int,idempotent:bool}
	 */
	private function sameBody( array $request, array $row ): array {
		if ( ! $request['idempotent'] ) {
			return $request;
		}
		$kept = (string) ( $row['payload'] ?? '' );
		if ( '' === $kept ) {
			$this->table->keep( (int) $row['id'], (string) wp_json_encode( $request['body'] ) );
			return $request;
		}
		$request['body'] = json_decode( $kept ); // objects, not arrays: an empty object stays one
		return $request;
	}

	/**
	 * The call, with the row's idempotency key when the job asks for it.
	 *
	 * @param array{method:string,path:string,body:mixed,timeout:int,idempotent:bool} $request The call.
	 * @param array<string,mixed>                                                   $row     The row.
	 * @return array{status:?int,headers:array<string,string>,body:?array,error:?string}
	 */
	private function send( array $request, array $row ): array {
		$key     = (string) ( $row['idempotency_key'] ?? '' );
		$headers = $request['idempotent'] && '' !== $key ? array( 'Idempotency-Key' => $key ) : array();
		return $this->client->call( $request['method'], $request['path'], $request['body'], $request['timeout'], $headers );
	}

	/**
	 * The row's next state when nothing was sent: waiting a while, discarded, or needing attention.
	 *
	 * @param Step                $step What the job said.
	 * @param array<string,mixed> $row  The row.
	 * @return array{state:string,next_at:?int,reason:string}
	 */
	private function decideWithout( Step $step, array $row ): array {
		if ( Step::WAIT === $step->kind ) {
			return $this->policy->hold( $step->seconds, $step->reason, array( 'created_at' => (int) $row['created_at'] ), time() );
		}
		$state = Step::SKIP === $step->kind ? Policy::DISCARDED : Policy::ATTENTION;
		return array( 'state' => $state, 'next_at' => null, 'reason' => $step->reason );
	}
}
