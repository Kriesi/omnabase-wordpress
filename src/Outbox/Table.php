<?php
/**
 * The outbox's table: the plugin's one place for SQL.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

use OmnaBase\WordPress\Options;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the outbox is the plugin's own table: no WordPress API reads it, and caching a queue would hide its rows' changes.

/**
 * `{prefix}omnabase_outbox`: one row per call to OmnaBase still to make (or made in the last seven days). A row names its kind
 * (`user_link`, `licence_refresh`), the user it is about and a dedupe key; its state is `waiting`, `sent`, `attention` (needs the
 * administrator) or `discarded`. Times are unix seconds. The body is built from the user when the row is sent, so the table holds no
 * customer data. Made or changed on load when the schema version (`omnabase_db_version`) is missing or older; dropped by uninstall.php.
 * Every query is prepared.
 */
final class Table {

	public const VERSION = '1';

	/** The table's full name. */
	public static function name(): string {
		global $wpdb;
		return $wpdb->prefix . 'omnabase_outbox';
	}

	/** Makes or changes the table when its version is missing or older. */
	public function maybeInstall(): void {
		if ( self::VERSION !== (string) get_option( Options::DB_VERSION, '' ) ) {
			$this->install();
		}
	}

	/** Makes or changes the table (WordPress's dbDelta) and records its version. */
	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::name();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			kind varchar(32) NOT NULL,
			subject_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dedupe_key varchar(191) NOT NULL DEFAULT '',
			state varchar(16) NOT NULL DEFAULT 'waiting',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			next_at bigint(20) NOT NULL DEFAULT 0,
			claimed_until bigint(20) NOT NULL DEFAULT 0,
			last_error text NULL,
			created_at bigint(20) NOT NULL DEFAULT 0,
			sent_at bigint(20) NULL,
			PRIMARY KEY  (id),
			KEY due (state,next_at),
			KEY dedupe (dedupe_key)
			) {$charset};"
		);
		update_option( Options::DB_VERSION, self::VERSION, true );
	}

	/**
	 * Adds a waiting row, due now; false when one with the same dedupe key is waiting already.
	 *
	 * @param string $kind    The row's kind.
	 * @param int    $subject The user it is about.
	 * @param string $dedupe  Its dedupe key.
	 * @param int    $now     The time now.
	 */
	public function add( string $kind, int $subject, string $dedupe, int $now ): bool {
		global $wpdb;
		$table   = self::name();
		$waiting = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE dedupe_key = %s AND state = 'waiting' LIMIT 1", $table, $dedupe ) );
		if ( null !== $waiting ) {
			return false;
		}
		$row = array( 'kind' => $kind, 'subject_id' => $subject, 'dedupe_key' => $dedupe, 'state' => Policy::WAITING, 'attempts' => 0, 'next_at' => $now, 'claimed_until' => 0, 'last_error' => '', 'created_at' => $now );
		return false !== $wpdb->insert( $table, $row, array( '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%d' ) );
	}

	/**
	 * Waiting rows whose next try has come and that no other run holds, oldest first.
	 *
	 * @param int $now   The time now.
	 * @param int $limit At most this many.
	 * @return array<int,array<string,mixed>>
	 */
	public function due( int $now, int $limit ): array {
		global $wpdb;
		$table = self::name();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE state = 'waiting' AND next_at <= %d AND claimed_until < %d ORDER BY next_at ASC, id ASC LIMIT %d", $table, $now, $now, $limit ), ARRAY_A );
	}

	/**
	 * Takes a row for this run (two overlapping runs never send one row twice); false when another run has it.
	 *
	 * @param int $id  The row.
	 * @param int $now The time now.
	 */
	public function claim( int $id, int $now ): bool {
		global $wpdb;
		$table = self::name();
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET claimed_until = %d WHERE id = %d AND state = 'waiting' AND claimed_until < %d", $table, $now + 120, $id, $now ) );
	}

	/**
	 * Records a try's outcome and lets go of the row.
	 *
	 * @param int                                             $id       The row.
	 * @param int                                             $attempts Its tries, this one included.
	 * @param array{state:string,next_at:?int,reason:string} $decision What the policy decided.
	 * @param int                                             $now      The time now.
	 */
	public function finish( int $id, int $attempts, array $decision, int $now ): void {
		global $wpdb;
		$sent = Policy::SENT === $decision['state'];
		$wpdb->update(
			self::name(),
			array( 'state' => $decision['state'], 'attempts' => $attempts, 'next_at' => (int) ( $decision['next_at'] ?? 0 ), 'claimed_until' => 0, 'last_error' => $sent ? '' : $decision['reason'], 'sent_at' => $sent ? $now : null ),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%d', '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * A row needing attention waits again, due now, as if new (its three days start afresh).
	 *
	 * @param int $id  The row.
	 * @param int $now The time now.
	 */
	public function retry( int $id, int $now ): bool {
		global $wpdb;
		$table = self::name();
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'waiting', next_at = %d, attempts = 0, created_at = %d, claimed_until = 0 WHERE id = %d AND state = 'attention'", $table, $now, $now, $id ) );
	}

	/**
	 * The administrator gives a row up: kept seven days as discarded, then removed with the sent ones.
	 *
	 * @param int $id  The row.
	 * @param int $now The time now.
	 */
	public function discard( int $id, int $now ): bool {
		global $wpdb;
		$table = self::name();
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'discarded', sent_at = %d, claimed_until = 0 WHERE id = %d AND state IN ('waiting', 'attention')", $table, $now, $id ) );
	}

	/**
	 * Removes sent and discarded rows older than a time (all of them with 0: a new connection).
	 *
	 * @param int $before Rows finished before this time go; PHP_INT_MAX for all.
	 */
	public function purge( int $before ): void {
		global $wpdb;
		$table = self::name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE state IN ('sent', 'discarded') AND sent_at < %d", $table, $before ) );
	}

	/**
	 * The rows the screen lists: needing attention first, then waiting, at most `$limit`.
	 *
	 * @param int $limit At most this many.
	 * @return array<int,array<string,mixed>>
	 */
	public function open( int $limit ): array {
		global $wpdb;
		$table = self::name();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE state IN ('attention', 'waiting') ORDER BY state = 'attention' DESC, next_at ASC, id ASC LIMIT %d", $table, $limit ), ARRAY_A );
	}

	/**
	 * How many rows wait, need attention, and were sent since a time.
	 *
	 * @param int $since Sent rows counted from this time.
	 * @return array{waiting:int,attention:int,sent:int}
	 */
	public function counts( int $since ): array {
		global $wpdb;
		$table = self::name();
		$row   = (array) $wpdb->get_row( $wpdb->prepare( "SELECT SUM(state = 'waiting') AS waiting, SUM(state = 'attention') AS attention, SUM(state = 'sent' AND sent_at >= %d) AS sent FROM %i", $since, $table ), ARRAY_A );
		return array( 'waiting' => (int) ( $row['waiting'] ?? 0 ), 'attention' => (int) ( $row['attention'] ?? 0 ), 'sent' => (int) ( $row['sent'] ?? 0 ) );
	}
}
