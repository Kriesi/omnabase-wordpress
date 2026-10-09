<?php
/**
 * The forum parts, built once and wired together: the settings, the triggers, the two senders, the reply poster, the subscriber mails, the
 * closer.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress;

use OmnaBase\WordPress\Connect\ConnectionInfo;
use OmnaBase\WordPress\Forum\Closer;
use OmnaBase\WordPress\Forum\ReplyPoster;
use OmnaBase\WordPress\Forum\ReplySender;
use OmnaBase\WordPress\Forum\Settings;
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
	private readonly SubscriberMails $mails;
	private readonly ReplyPoster $poster;
	private readonly Closer $closer;

	private static bool $backgroundRegistered = false;

	/**
	 * Builds the parts.
	 *
	 * @param CustomerData $data The outbox (its queue and worker).
	 */
	public function __construct( CustomerData $data ) {
		$connection     = new ConnectionInfo();
		$this->settings = new Settings();
		$this->triggers = new Triggers( $data->queue, $this->settings );
		$this->mails    = new SubscriberMails();
		$this->poster   = new ReplyPoster( $this->settings, $this->mails, $connection );
		$this->closer   = new Closer( $this->settings );
		$data->worker->add( new TopicSender( $this->settings, $connection ) );
		$data->worker->add( new ReplySender( $this->settings, $data->table ) );
	}

	/** bbPress's moments, the webhook listeners and the mail job: once per request, with bbPress active. */
	public function registerBackground(): void {
		if ( self::$backgroundRegistered || ! function_exists( 'bbp_get_topic_post_type' ) ) {
			return;
		}
		self::$backgroundRegistered = true;
		$this->triggers->register();
		$this->mails->register();
		add_action( 'omnabase_webhook', array( $this->poster, 'handle' ), 10, 2 );
		add_action( 'omnabase_webhook', array( $this->closer, 'handle' ), 10, 2 );
	}
}
