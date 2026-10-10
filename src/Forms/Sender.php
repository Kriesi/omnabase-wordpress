<?php
/**
 * Sends a visitor's message to OmnaBase at once, the outbox behind it.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Outbox\Table;

defined( 'ABSPATH' ) || exit;

/**
 * The visitor waits for OmnaBase's answer (`POST /forms/{slug}/submissions` with the token's Idempotency-Key):
 * - 2xx: "Your reference is #n";
 * - no answer, a 5xx (OmnaBase's proxy answers 502/503 while it restarts) or OmnaBase still busy with the same key: the message waits in the
 *   outbox with the same key and body, and the visitor reads "on its way";
 * - 429: "Please try again in an hour", never queued (queuing would step round the limit);
 * - 403 or 404 (the project gone, the plan's limit, a suspended workspace): "This form is not available right now";
 * - anything else (a 400): "could not be sent" with the site's address, and the kept form settings are forgotten (the products may have
 *   changed).
 */
final class Sender {

	/**
	 * Builds the sender.
	 *
	 * @param Client   $client   The API client.
	 * @param Table    $table    The outbox's table.
	 * @param Settings $settings The kept form settings.
	 */
	public function __construct( private Client $client, private Table $table, private Settings $settings ) {}

	/**
	 * Sends one message and says what the visitor reads.
	 *
	 * @param string               $slug The project's slug.
	 * @param array<string,string> $body The fields OmnaBase takes.
	 * @param string               $key  The Idempotency-Key.
	 */
	public function send( string $slug, array $body, string $key ): Answer {
		$outcome = $this->client->submitForm( $slug, $body, $key );
		$status  = $outcome['status'];
		if ( null !== $status && $status >= 200 && $status < 300 ) {
			return Answer::sent( (int) ( $outcome['body']['reference'] ?? 0 ) );
		}
		if ( self::passing( $outcome ) ) {
			$this->table->carry( FormJob::KIND, $key, (string) wp_json_encode( array( 'project' => $slug, 'body' => $body ) ), time() );
			return Answer::onItsWay();
		}
		if ( 429 === $status ) {
			return Answer::later();
		}
		if ( 403 === $status || 404 === $status ) {
			return Answer::unavailable();
		}
		$this->settings->forget( $slug );
		return Answer::failed( self::supportAddress() );
	}

	/**
	 * Whether the failure may pass by waiting (the outbox's own reading): no answer, a 5xx, an earlier try with the key still running, or
	 * OmnaBase's 401 (someone disconnected the site there: the message waits for Connect, like the outbox's other rows).
	 *
	 * @param array{status:?int,body:?array} $outcome The answer.
	 */
	private static function passing( array $outcome ): bool {
		$status = $outcome['status'];
		$reason = $outcome['body']['error']['details']['reason'] ?? '';
		return null === $status || 401 === $status || $status >= 500 || ( 409 === $status && 'idempotency_in_flight' === $reason );
	}

	/** The address a visitor whose message was refused writes to: the site's own, unless a filter names another. */
	private static function supportAddress(): string {
		/**
		 * The email address the form names when OmnaBase refused a message.
		 *
		 * @param string $email The site's administration address.
		 */
		$email = apply_filters( 'omnabase_form_support_email', (string) get_bloginfo( 'admin_email' ) );
		return is_string( $email ) && is_email( $email ) ? $email : (string) get_bloginfo( 'admin_email' );
	}
}
