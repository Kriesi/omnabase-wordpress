<?php
/**
 * A form message that waits in the outbox: `POST /forms/{slug}/submissions` later.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

use OmnaBase\WordPress\Api\Client;
use OmnaBase\WordPress\Outbox\Job;
use OmnaBase\WordPress\Outbox\Step;

defined( 'ABSPATH' ) || exit;

/**
 * The outbox row `form_submission`: a visitor's message whose immediate try found OmnaBase unreachable (or answering a 5xx). Unlike the
 * other rows it has nothing to be rebuilt from, so it carries the project and the body in its payload, sent with the idempotency key the
 * first try had (one ticket however often it is tried). The table empties the payload once the row is sent or discarded.
 */
final class FormJob implements Job {

	public const KIND = 'form_submission';

	private const TIMEOUT_SECONDS = 15;

	/** {@inheritDoc} */
	public function kind(): string {
		return self::KIND;
	}

	/** {@inheritDoc} */
	public function label(): string {
		return __( 'Form message', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function subject( array $row ): string {
		$subject = (string) ( self::payload( $row )['body']['subject'] ?? '' );
		return '' !== $subject ? $subject : __( '(sent or discarded)', 'omnabase' );
	}

	/** {@inheritDoc} */
	public function request( array $row ): Step {
		$payload = self::payload( $row );
		$slug    = (string) ( $payload['project'] ?? '' );
		$body    = $payload['body'] ?? null;
		if ( '' === $slug || ! is_array( $body ) ) {
			return Step::skip( __( 'Not sent: the message is no longer stored on the site.', 'omnabase' ) );
		}
		return Step::send( 'POST', Client::formPath( $slug ), $body, self::TIMEOUT_SECONDS, true );
	}

	/** {@inheritDoc} */
	public function sent( array $row, ?array $answer ): void {
		// Nothing to keep: the visitor was told "on its way", and OmnaBase answers them by email.
	}

	/**
	 * The row's payload.
	 *
	 * @param array<string,mixed> $row The row.
	 * @return array<string,mixed>
	 */
	private static function payload( array $row ): array {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );
		return is_array( $payload ) ? $payload : array();
	}
}
