<?php
/**
 * The outbox's timer: WP-Cron every minute, and a one-off run right after a new row.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Outbox;

defined( 'ABSPATH' ) || exit;

/**
 * WP-Cron (WordPress's timer, which runs jobs when the site gets visits) runs the worker every minute at `omnabase_outbox`. A new row also
 * schedules a one-off run at `omnabase_outbox_now` and wakes WP-Cron at the end of the request (WordPress's own background call to
 * wp-cron.php, which does not make the visitor wait). Both are cleared on deactivation and uninstall.
 */
final class Schedule {

	public const HOOK      = 'omnabase_outbox';
	public const HOOK_NOW  = 'omnabase_outbox_now';
	public const RECURRENCE = 'omnabase_every_minute';

	/** Whether this request queued a row (WP-Cron is then woken at its end). */
	private bool $woken = false;

	/**
	 * Registers the interval, the two hooks and the recurring event.
	 *
	 * @param Worker $worker The worker the hooks run.
	 */
	public function register( Worker $worker ): void {
		add_filter( 'cron_schedules', array( $this, 'addInterval' ) ); // phpcs:ignore WordPress.WP.CronInterval -- every minute, the brief's outbox rhythm.
		add_action( self::HOOK, array( $worker, 'run' ) );
		add_action( self::HOOK_NOW, array( $worker, 'run' ) );
		add_action( 'shutdown', array( $this, 'wake' ) );
		// At `init`: WordPress's list of intervals is translated (ours and the shops'), and translations load from `init` on.
		if ( did_action( 'init' ) ) {
			$this->ensure();
		} else {
			add_action( 'init', array( $this, 'ensure' ) );
		}
	}

	/**
	 * Adds the one-minute interval.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules WordPress's intervals.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function addInterval( $schedules ): array {
		$schedules                     = is_array( $schedules ) ? $schedules : array();
		$schedules[ self::RECURRENCE ] = array( 'interval' => 60, 'display' => __( 'Every minute (OmnaBase outbox)', 'omnabase' ) );
		return $schedules;
	}

	/** Schedules the recurring run when it is missing. */
	public function ensure(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time(), self::RECURRENCE, self::HOOK );
		}
	}

	/** A one-off run as soon as possible. */
	public function soon(): void {
		if ( false === wp_next_scheduled( self::HOOK_NOW ) ) {
			wp_schedule_single_event( time(), self::HOOK_NOW );
		}
		$this->woken = true;
	}

	/** At the end of a request that queued a row, WP-Cron is woken (not from WP-Cron itself or the command line). */
	public function wake(): void {
		if ( $this->woken && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->woken = false;
			spawn_cron();
		}
	}

	/** Clears both events (deactivation). */
	public static function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::HOOK_NOW );
	}
}
