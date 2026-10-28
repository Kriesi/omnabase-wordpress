<?php
/**
 * Two more lines for the health section: the last customer-data call and a timer that stopped running the outbox.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Facts\FactsLog;
use OmnaBase\WordPress\Outbox\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * When OmnaBase last asked the site about a customer (from the facts log; nothing about whom), and whether the outbox's runs are overdue:
 * a run that should have happened more than ten minutes ago and did not (2026-10-28: `DISABLE_WP_CRON` alone warned, although most hosts
 * that set it call wp-cron.php from a real cron job). `cronOff` says which sentence fits: the host turned the timer off, or it runs late.
 */
final class HealthNotes {

	/** How late a run may be before the screen warns (WordPress's timer runs it every minute when it runs at all). */
	private const LATE_SECONDS = 600;

	/**
	 * Builds the lines.
	 *
	 * @param FactsLog $facts The facts calls answered.
	 */
	public function __construct( private FactsLog $facts ) {}

	/**
	 * What the health template adds.
	 *
	 * @return array{factsAt:int,cronOff:bool,cronLate:bool}
	 */
	public function build(): array {
		return array(
			'factsAt'  => (int) ( $this->facts->all()[0]['at'] ?? 0 ),
			'cronOff'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'cronLate' => self::outboxLate(),
		);
	}

	/** Whether one of the outbox's scheduled runs is more than ten minutes overdue. */
	private static function outboxLate(): bool {
		foreach ( array( Schedule::HOOK, Schedule::HOOK_NOW ) as $hook ) {
			$next = wp_next_scheduled( $hook );
			if ( false !== $next && $next < time() - self::LATE_SECONDS ) {
				return true;
			}
		}
		return false;
	}
}
