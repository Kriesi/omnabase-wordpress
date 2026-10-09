<?php
/**
 * Every option, user meta key and transient prefix the plugin stores, by name.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

defined( 'ABSPATH' ) || exit;

/**
 * The one list of stored names. uninstall.php removes each of them, and the structure guard checks that every option name in `src/` is
 * on this list and named in uninstall.php: a new option goes here first.
 */
final class Options {

	/** The vault: the key and both secrets, encrypted (read and written by `Security\Vault` alone). */
	public const VAULT = 'omnabase_vault';

	/** What the connection is, kept from the exchange: the workspace, the site, the permissions and the projects (no secret). */
	public const SITE = 'omnabase_site';

	/** The last "Check connection": when, and what each direction answered. */
	public const HEALTH = 'omnabase_health';

	/** When the last signed `ping` arrived at the events receiver. */
	public const LAST_PING = 'omnabase_last_ping';

	/** The last 20 webhook deliveries received: time, event, delivery id, ticket number, what the plugin did (never content). */
	public const DELIVERIES = 'omnabase_deliveries';

	/** The delivery ids already handled, with the time, kept 30 days (OmnaBase's redelivery window). */
	public const HANDLED = 'omnabase_handled';

	/**
	 * All options, for uninstall.php and the structure guard.
	 */
	public const ALL = array(
		self::VAULT,
		self::SITE,
		self::HEALTH,
		self::LAST_PING,
		self::DELIVERIES,
		self::HANDLED,
	);

	/** User meta: an administrator's pending connect (the state, the sealed verifier, the time), one per administrator. */
	public const PENDING_CONNECT_META = 'omnabase_pending_connect';

	/** Transients: a short message for one administrator's next view of the screen (`omnabase_notice_<user id>`). */
	public const NOTICE_TRANSIENT = 'omnabase_notice_';
}
