<?php
/**
 * The customer-data parts, built once and wired together: the switches, the sources, the responder, the outbox, the licence copy.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Facts\FactsLog;
use OmnaBase\WordPress\Facts\FactsSource;
use OmnaBase\WordPress\Facts\Identity;
use OmnaBase\WordPress\Facts\Responder;
use OmnaBase\WordPress\Facts\Shaper;
use OmnaBase\WordPress\Facts\Sources;
use OmnaBase\WordPress\Facts\Switches;
use OmnaBase\WordPress\Outbox\Policy;
use OmnaBase\WordPress\Outbox\Queue;
use OmnaBase\WordPress\Outbox\Schedule;
use OmnaBase\WordPress\Outbox\Table;
use OmnaBase\WordPress\Outbox\Worker;
use OmnaBase\WordPress\Security\Vault;
use OmnaBase\WordPress\Sync\KnownUsers;
use OmnaBase\WordPress\Sync\LicenceHooks;
use OmnaBase\WordPress\Sync\LicenceRefresh;
use OmnaBase\WordPress\Sync\SiteId;
use OmnaBase\WordPress\Sync\UserLink;

defined( 'ABSPATH' ) || exit;

/**
 * Part of the hand-wired container (`Plugin`): what round two adds. `registerBackground()` hooks the outbox's timer, the shops' licence
 * events and the fresh start on a new connection; it runs once per request, however often the plugin is booted.
 */
final class CustomerData {

	public readonly Switches $switches;
	public readonly Table $table;
	public readonly Worker $worker;
	public readonly Responder $responder;
	public readonly FactsLog $log;
	/** @var FactsSource[] */
	public readonly array $sources;
	private readonly Schedule $schedule;
	private readonly KnownUsers $known;
	private readonly LicenceRefresh $refresh;

	private static bool $backgroundRegistered = false;

	/**
	 * Builds the parts.
	 *
	 * @param Vault $vault The vault.
	 */
	public function __construct( Vault $vault ) {
		$this->switches = new Switches();
		$this->table    = new Table();
		$this->schedule = new Schedule();
		$this->known    = new KnownUsers();
		$this->log      = new FactsLog();
		$this->sources  = array(
			new Sources\Profile( $this->switches ),
			new Sources\WooCommerce(),
			new Sources\WooSubscriptions(),
			new Sources\Edd(),
			new Sources\EddRecurring(),
			new Sources\EddSoftwareLicensing(),
			new Sources\LicenseManager(),
		);
		$site_ids        = new SiteId();
		$queue           = new Queue( $this->table, $this->schedule );
		$link            = new UserLink( $queue, $site_ids, $this->known );
		$this->refresh   = new LicenceRefresh( $queue, $site_ids, $this->known, $this->switches );
		$this->worker    = new Worker( $this->table, new Policy(), new Client( $vault ), $vault, array( $link, $this->refresh ) );
		$this->responder = new Responder( $this->switches, new Identity( $site_ids ), $this->sources, new Shaper(), $link, $this->log );
	}

	/** The outbox's table and timer, the licence events, the fresh start on a new connection: once per request. */
	public function registerBackground(): void {
		if ( self::$backgroundRegistered ) {
			return;
		}
		self::$backgroundRegistered = true;
		$this->table->maybeInstall();
		$this->schedule->register( $this->worker );
		( new LicenceHooks( $this->refresh ) )->register();
		add_action( 'omnabase_connected', array( $this, 'freshStart' ) );
	}

	/** A new connection (perhaps to another workspace): OmnaBase knows none of the users yet, and the old connection's history goes. */
	public function freshStart(): void {
		$this->known->forgetAll();
		$this->table->purge( PHP_INT_MAX );
	}
}
