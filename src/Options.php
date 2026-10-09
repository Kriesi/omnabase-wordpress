<?php
/**
 * Every option, user meta key, post meta key and transient prefix the plugin stores, by name.
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

	/** The customer-data switches: `[ 'sources' => [ <source> => bool ], 'meta' => [ <usermeta key>, … ] ]`; every source off when missing. */
	public const FACTS = 'omnabase_facts';

	/** The last 20 facts calls answered: time, who was found (user, guest or nobody), the sections sent and the ones left out (never data). */
	public const FACTS_LOG = 'omnabase_facts_log';

	/** The outbox table's schema version; the table is made or changed on load when this is missing or older. */
	public const DB_VERSION = 'omnabase_db_version';

	/**
	 * The forum settings: `[ 'forums' => [ <forum id>, … ], 'support_user' => <user id>, 'closing_line' => <text>, 'guests' => bool,
	 * 'close_resolved' => bool ]`; read and written by `Forum\Settings` alone.
	 */
	public const FORUM = 'omnabase_forum';

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
		self::FACTS,
		self::FACTS_LOG,
		self::DB_VERSION,
		self::FORUM,
	);

	/** User meta: an administrator's pending connect (the state, the sealed verifier, the time), one per administrator. */
	public const PENDING_CONNECT_META = 'omnabase_pending_connect';

	/** User meta: the site's id for a user as OmnaBase knows it (`<user id>-<8 random characters>`), made once, gone with the user. */
	public const SITE_ID_META = 'omnabase_site_id';

	/** User meta: set once OmnaBase knows the user by their site id (the link was accepted, or OmnaBase asked by that id). */
	public const KNOWN_META = 'omnabase_known';

	/** Post meta: the OmnaBase ticket number a topic became. */
	public const TICKET_META = '_omnabase_ticket';

	/** Post meta: OmnaBase's message id on a reply the plugin posted (the echo marker: a reply carrying it is never sent back). */
	public const MESSAGE_META = '_omnabase_message_id';

	/** Transients: a short message for one administrator's next view of the screen (`omnabase_notice_<user id>`). */
	public const NOTICE_TRANSIENT = 'omnabase_notice_';
}
