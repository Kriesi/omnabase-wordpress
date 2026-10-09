<?php
/**
 * What the shortcode form tells a visitor: a status and one plain sentence.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Every sentence a visitor can read after sending lives here, so the words stay the brief's and never name the site owner's plan, the
 * workspace or OmnaBase's own text. A success may carry the ticket's number; a refusal never does. A field's own error sits under
 * `fields.<name>`, beside the sentence.
 */
final class Answer {

	/**
	 * Builds an answer (use the named constructors).
	 *
	 * @param int                  $status    The HTTP status.
	 * @param string               $message   The sentence the visitor reads.
	 * @param int|null             $reference The ticket's number, on success.
	 * @param array<string,string> $fields    A sentence per wrong field.
	 */
	private function __construct( public readonly int $status, public readonly string $message, public readonly ?int $reference = null, public readonly array $fields = array() ) {}

	/**
	 * OmnaBase filed it.
	 *
	 * @param int $reference The ticket's number.
	 */
	public static function sent( int $reference ): self {
		/* translators: %d: the ticket's number. */
		return new self( 200, sprintf( __( 'Thanks. Your reference is #%d; we answer by email.', 'omnabase' ), $reference ), $reference );
	}

	/** OmnaBase could not take it now; the outbox will send it. */
	public static function onItsWay(): self {
		return new self( 202, __( 'Thanks, your message is on its way. We answer by email.', 'omnabase' ) );
	}

	/** The honeypot was filled: "sent" for the bot, nothing went anywhere. */
	public static function trapped(): self {
		return new self( 200, __( 'Thanks. Your message was sent; we answer by email.', 'omnabase' ) );
	}

	/** Too many messages from this address or this site within the hour. */
	public static function later(): self {
		return new self( 429, __( 'Please try again in an hour.', 'omnabase' ) );
	}

	/** OmnaBase does not take this form now (the project is gone, the plan's limit, a suspended workspace). */
	public static function unavailable(): self {
		return new self( 403, __( 'This form is not available right now.', 'omnabase' ) );
	}

	/**
	 * OmnaBase refused the message itself; the visitor gets an address to write to.
	 *
	 * @param string $email The site's address for support.
	 */
	public static function failed( string $email ): self {
		/* translators: %s: an email address. */
		return new self( 400, sprintf( __( 'Your message could not be sent. Please write to us at %s.', 'omnabase' ), $email ) );
	}

	/**
	 * Some fields are wrong.
	 *
	 * @param array<string,string> $fields A sentence per wrong field.
	 */
	public static function fields( array $fields ): self {
		return new self( 400, __( 'Please check the fields marked.', 'omnabase' ), null, $fields );
	}

	/** No token, an unknown one, an expired one, or one of another form: the page must be loaded again (or JavaScript turned on). */
	public static function expired(): self {
		return new self( 400, __( 'This form has expired. Please reload the page and send again (the form needs JavaScript).', 'omnabase' ) );
	}

	/** Sent faster than a person fills a form. */
	public static function tooQuick(): self {
		return new self( 400, __( 'That was quick. Please wait a moment and send again.', 'omnabase' ) );
	}

	/** The token was used for another message already. */
	public static function used(): self {
		return new self( 409, __( 'This form was sent already. Please reload the page to send another message.', 'omnabase' ) );
	}

	/** The answer as stored with a used token (a double click answers the first click's words again). */
	public function toArray(): array {
		return array( 'status' => $this->status, 'message' => $this->message, 'reference' => $this->reference );
	}

	/**
	 * An answer stored with a token.
	 *
	 * @param array<string,mixed> $stored What `toArray` gave.
	 */
	public static function fromArray( array $stored ): self {
		$reference = isset( $stored['reference'] ) ? (int) $stored['reference'] : null;
		return new self( (int) ( $stored['status'] ?? 202 ), (string) ( $stored['message'] ?? '' ), $reference );
	}

	/** Whether the message was taken (sent, on its way, or the trap's pretended "sent"). */
	public function taken(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/** The REST answer: the sentence, the number on success, the fields' sentences on a field error; never cached. */
	public function toResponse(): \WP_REST_Response {
		$data = array( 'message' => $this->message );
		if ( null !== $this->reference ) {
			$data['reference'] = $this->reference;
		}
		if ( array() !== $this->fields ) {
			$data['fields'] = $this->fields;
		}
		$response = new \WP_REST_Response( $data, $this->status );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		return $response;
	}
}
