<?php
/**
 * Two more lines for the health section: the last customer-data call and a switched-off WP-Cron.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Admin;

use OmnaBase\WordPress\Facts\FactsLog;

defined( 'ABSPATH' ) || exit;

/**
 * When OmnaBase last asked the site about a customer (from the facts log; nothing about whom), and whether WordPress's scheduler (WP-Cron)
 * is switched off by `DISABLE_WP_CRON`, in which case the outbox runs only when a real cron job on the server calls wp-cron.php.
 */
final class HealthNotes {

	/**
	 * Builds the lines.
	 *
	 * @param FactsLog $facts The facts calls answered.
	 */
	public function __construct( private FactsLog $facts ) {}

	/**
	 * What the health template adds.
	 *
	 * @return array{factsAt:int,cronOff:bool}
	 */
	public function build(): array {
		return array(
			'factsAt' => (int) ( $this->facts->all()[0]['at'] ?? 0 ),
			'cronOff' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}
}
