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
 * (`user_link`, `licence_refresh`, `forum_topic`, `forum_reply`), the user or post it is about, a dedupe key and its own idempotency key
 * (version 2, round three: made once with the row and sent on every try, so a resent topic counts once at OmnaBase); its state is
 * `waiting`, `sent`, `attention` (needs the administrator) or `discarded`. Times are unix seconds. The body is built from the user or post
 * when the row is sent, so the table holds no customer data, with one exception (version 3, round four): a form message has nothing to be
 * rebuilt from, so a `form_submission` row carries its body in `payload` while it waits, and the payload is emptied the moment the row is
 * sent or discarded. Made or changed on load when the schema version (`omnabase_db_version`) is missing or older; dropped by uninstall.php.
 * Every query is prepared.
 */
final class Table {

	public const VERSION = '3';

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
			idempotency_key varchar(64) NOT NULL DEFAULT '',
			state varchar(16) NOT NULL DEFAULT 'waiting',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			next_at bigint(20) NOT NULL DEFAULT 0,
			claimed_until bigint(20) NOT NULL DEFAULT 0,
			last_error text NULL,
			payload longtext NULL,
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
		$row = array( 'kind' => $kind, 'subject_id' => $subject, 'dedupe_key' => $dedupe, 'idempotency_key' => self::newKey(), 'state' => Policy::WAITING, 'attempts' => 0, 'next_at' => $now, 'claimed_until' => 0, 'last_error' => '', 'created_at' => $now );
		return false !== $wpdb->insert( $table, $row, array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d' ) );
	}

	/**
	 * Adds a waiting row that carries its own body and idempotency key (a form message whose immediate try failed): one try made already,
	 * the next after the first back-off; false when a row for the same key waits already (a double click during an outage).
	 *
	 * @param string $kind    The row's kind.
	 * @param string $key     Its idempotency key (also its dedupe key).
	 * @param string $payload The body to send, as JSON.
	 * @param int    $now     The time now.
	 */
	public function carry( string $kind, string $key, string $payload, int $now ): bool {
		global $wpdb;
		$table   = self::name();
		$dedupe  = $kind . ':' . $key;
		$waiting = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE dedupe_key = %s AND state = 'waiting' LIMIT 1", $table, $dedupe ) );
		if ( null !== $waiting ) {
			return false;
		}
		$row = array( 'kind' => $kind, 'subject_id' => 0, 'dedupe_key' => $dedupe, 'idempotency_key' => $key, 'state' => Policy::WAITING, 'attempts' => 1, 'next_at' => $now + 60, 'claimed_until' => 0, 'last_error' => '', 'payload' => $payload, 'created_at' => $now );
		return false !== $wpdb->insert( $table, $row, array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d' ) );
	}

	/**
	 * Whether a row with this dedupe key still waits or needs attention (a reply asks whether its topic is still on its way).
	 *
	 * @param string $dedupe The dedupe key.
	 */
	public function isOpen( string $dedupe ): bool {
		global $wpdb;
		$table = self::name();
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE dedupe_key = %s AND state IN ('waiting', 'attention') LIMIT 1", $table, $dedupe ) );
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
	 * Records a try's outcome and lets go of the row; a sent or discarded row's payload is emptied.
	 *
	 * @param int                                             $id       The row.
	 * @param int                                             $attempts Its tries, this one included.
	 * @param array{state:string,next_at:?int,reason:string} $decision What the policy decided.
	 * @param int                                             $now      The time now.
	 */
	public function finish( int $id, int $attempts, array $decision, int $now ): void {
		global $wpdb;
		$sent = Policy::SENT === $decision['state'];
		$done = $sent || Policy::DISCARDED === $decision['state']; // kept seven days from now, then purged
		$wpdb->update(
			self::name(),
			array( 'state' => $decision['state'], 'attempts' => $attempts, 'next_at' => (int) ( $decision['next_at'] ?? 0 ), 'claimed_until' => 0, 'last_error' => $sent ? '' : $decision['reason'], 'sent_at' => $done ? $now : null ),
			array( 'id' => $id ),
			array( '%s', '%d', '%d', '%d', '%s', '%d' ),
			array( '%d' )
		);
		if ( $done ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET payload = NULL WHERE id = %d', self::name(), $id ) );
		}
	}

	/**
	 * A row needing attention waits again, due now, as if new (its three days start afresh), with a new idempotency key: the old one may be
	 * the reason it needed attention (OmnaBase's "used with another request" after the post was edited), and the site's own reference
	 * (`topic-<id>`, `reply-<id>`) still makes a resend count once.
	 *
	 * @param int $id  The row.
	 * @param int $now The time now.
	 */
	public function retry( int $id, int $now ): bool {
		global $wpdb;
		$table = self::name();
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'waiting', next_at = %d, attempts = 0, created_at = %d, claimed_until = 0, idempotency_key = %s WHERE id = %d AND state = 'attention'", $table, $now, $now, self::newKey(), $id ) );
	}

	/**
	 * The administrator gives a row up: kept seven days as discarded (its payload emptied), then removed with the sent ones.
	 *
	 * @param int $id  The row.
	 * @param int $now The time now.
	 */
	public function discard( int $id, int $now ): bool {
		global $wpdb;
		$table = self::name();
		return 1 === (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'discarded', sent_at = %d, claimed_until = 0, payload = NULL WHERE id = %d AND state IN ('waiting', 'attention')", $table, $now, $id ) );
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
	 * The waiting rows of one kind: how many, the earliest next try, and how many wait for the given reason.
	 *
	 * @param string $kind   The kind.
	 * @param string $reason A reason in words, as the policy writes it.
	 * @return array{count:int,next:int,because:int}
	 */
	public function waitingOf( string $kind, string $reason ): array {
		global $wpdb;
		$table = self::name();
		$row   = (array) $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, MIN(next_at) AS next, SUM(last_error = %s) AS because FROM %i WHERE state = 'waiting' AND kind = %s", $reason, $table, $kind ), ARRAY_A );
		return array( 'count' => (int) ( $row['n'] ?? 0 ), 'next' => (int) ( $row['next'] ?? 0 ), 'because' => (int) ( $row['because'] ?? 0 ) );
	}

	/** A fresh idempotency key: 32 random letters and digits under a prefix that names the plugin. */
	private static function newKey(): string {
		return 'obwp-' . wp_generate_password( 32, false, false );
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
