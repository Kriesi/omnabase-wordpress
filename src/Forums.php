<?php
/**
 * The forum parts, built once and wired together: the settings, the triggers, the three senders, the reply poster, the subscriber mails, the
 * closer (the forum sync round, 2026-11-02: a topic closed or opened in the forum moves its ticket, and the ticket's status moves the topic),
 * the answer's `posted` (the forum reply mails round, 2026-11-03), the resend and its WP-CLI command (the support user replies round, 2026-11-04).
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Cli\ForumCommand;
use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forum\Closer;
use OmnaBase\WordPress\Forum\CustomerMail;
use OmnaBase\WordPress\Forum\PostFields;
use OmnaBase\WordPress\Forum\PostedAnswer;
use OmnaBase\WordPress\Forum\ReplyAuthor;
use OmnaBase\WordPress\Forum\ReplyPoster;
use OmnaBase\WordPress\Forum\ReplySender;
use OmnaBase\WordPress\Forum\Resend;
use OmnaBase\WordPress\Forum\Settings;
use OmnaBase\WordPress\Forum\StatusSender;
use OmnaBase\WordPress\Forum\StatusTriggers;
use OmnaBase\WordPress\Forum\SubscriberMails;
use OmnaBase\WordPress\Forum\TopicSender;
use OmnaBase\WordPress\Forum\Triggers;

defined( 'ABSPATH' ) || exit;

/**
 * Part of the hand-wired container (`Plugin`): what round three adds. The topic and reply jobs join the outbox's worker; `registerBackground()`
 * hooks bbPress's moments, the events receiver's listeners and the subscriber-mail job once per request, however often the plugin is booted
 * (and only while bbPress is active).
 */
final class Forums {

	public readonly Settings $settings;
	private readonly Triggers $triggers;
	private readonly StatusTriggers $statusTriggers;
	private readonly SubscriberMails $mails;
	private readonly ReplyPoster $poster;
	private readonly Closer $closer;
	private readonly PostedAnswer $answer;
	private readonly Resend $resend;

	private static bool $backgroundRegistered = false;

	/**
	 * Builds the parts.
	 *
	 * @param CustomerData $data The outbox (its queue and worker).
	 */
	public function __construct( CustomerData $data ) {
		$connection           = new ConnectionInfo();
		$this->settings       = new Settings();
		$fields               = new PostFields( $this->settings ); // the site-fields round: the declared post fields, both ways
		$this->triggers       = new Triggers( $data->queue, $this->settings );
		$this->statusTriggers = new StatusTriggers( $data->queue );
		$this->mails          = new SubscriberMails();
		$this->poster         = new ReplyPoster( $this->settings, $this->mails, $connection, $fields, new ReplyAuthor( $this->settings ), new CustomerMail( $this->settings ) );
		$this->answer         = new PostedAnswer(); // the forum reply mails round: `posted` in the answer to OmnaBase
		$this->closer         = new Closer( $this->settings );
		$this->resend         = new Resend( $this->settings, $data->queue, $data->table ); // the support user replies round: a skipped reply again
		$data->worker->add( new TopicSender( $this->settings, $connection, $fields ) );
		$data->worker->add( new ReplySender( $this->settings, $data->table, $fields ) );
		$data->worker->add( new StatusSender() );
	}

	/** bbPress's moments, the webhook listeners and the mail job: once per request, with bbPress active. */
	public function registerBackground(): void {
		if ( self::$backgroundRegistered || ! function_exists( 'bbp_get_topic_post_type' ) ) {
			return;
		}
		self::$backgroundRegistered = true;
		$this->triggers->register();
		$this->statusTriggers->register();
		$this->mails->register();
		add_action( 'omnabase_webhook', array( $this->poster, 'handle' ), 10, 2 );
		add_action( 'omnabase_webhook', array( $this->closer, 'handle' ), 10, 2 );
		$this->answer->register();
		ForumCommand::register( $this->resend ); // `wp omnabase forum resend <id>`, only while WP-CLI runs
	}
}
