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

	/** The message centre (round five): `[ 'page' => <page id> ]`, the page the administrator picked; read and written by `Portal\PickedPage` alone. */
	public const PORTAL = 'omnabase_portal';

	/**
	 * The front-end switches (round six): `[ 'theme_only' => bool, 'my_account' => bool, 'first_shown' => [ <tag> => <time> ] ]`, the "theme
	 * styles only" switch, WooCommerce's My Account "Support" link (both off when missing) and when each tag first showed on the public site
	 * (M14). Read and written by `Forms\Display` alone.
	 */
	public const DISPLAY = 'omnabase_display';

	/** The settings screen's setup line (M14): `[ 'dismissed' => <time> ]` once the administrator dismissed it. Read and written by `Admin\SetupLine` alone. */
	public const SETUP = 'omnabase_setup';

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
		self::PORTAL,
		self::DISPLAY,
		self::SETUP,
	);

	/** User meta: an administrator's pending connect (the state, the sealed verifier, the time), one per administrator. */
	public const PENDING_CONNECT_META = 'omnabase_pending_connect';

	/** User meta: the site's id for a user as OmnaBase knows it (`<user id>-<8 random characters>`), made once, gone with the user. */
	public const SITE_ID_META = 'omnabase_site_id';

	/** User meta: set once OmnaBase knows the user by their site id (the link was accepted, or OmnaBase asked by that id). */
	public const KNOWN_META = 'omnabase_known';

	/**
	 * User meta: the message centre's fingerprint of what OmnaBase was last told about the user (a hash of the address and the name), kept only
	 * while OmnaBase says the address is confirmed; a view with the same fingerprint sends no `PUT` (round five).
	 */
	public const PORTAL_FINGERPRINT_META = 'omnabase_portal_fingerprint';

	/** User meta: when the user asked the message centre for a confirmation link, the last day's times (three a day, round five). */
	public const PORTAL_LINKS_META = 'omnabase_portal_links';

	/**
	 * User meta: the words of a New ticket that OmnaBase refused (round six), `[ 'subject', 'message', 'at' ]`, shown again in that user's
	 * form for an hour and forgotten after a successful New ticket.
	 */
	public const PORTAL_DRAFT_META = 'omnabase_portal_draft';

	/** Post meta: the OmnaBase ticket number a topic became. */
	public const TICKET_META = '_omnabase_ticket';

	/** Post meta: OmnaBase's message id on a reply the plugin posted (the echo marker: a reply carrying it is never sent back). */
	public const MESSAGE_META = '_omnabase_message_id';

	/** Transients: a short message for one administrator's next view of the screen (`omnabase_notice_<user id>`). */
	public const NOTICE_TRANSIENT = 'omnabase_notice_';

	/**
	 * Transients: what the shortcode form keeps for a while (round four), all under this prefix: the time tokens (`…t_<hash>`, two hours),
	 * the counted submissions and token requests per visitor address (`…a_<hash>`, `…ta_<hash>`, an hour) and for the site (`…site`), the
	 * project's cached form settings (`…s_<slug hash>`, an hour).
	 */
	public const FORM_TRANSIENT = 'omnabase_form_';
}
