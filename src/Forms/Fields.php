<?php
/**
 * The form's fields, checked the way OmnaBase checks them.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * OmnaBase takes exactly `name, email, subject, message, product?, licence?` and refuses anything else, so the plugin checks the same
 * lengths first and names the wrong field beside it: a name of 1 to 120 characters, a real address, a subject of 1 to 200, a message of 1
 * to 20 000; a product of at most 60 and a licence of 4 to 200 when given. Empty optional fields are left out (OmnaBase refuses an empty
 * licence). Nothing else of what the browser sent (the token, the trap, the project) reaches the body.
 */
final class Fields {

	private const NAME_MAX     = 120;
	private const EMAIL_MAX    = 254;
	private const SUBJECT_MAX  = 200;
	private const MESSAGE_MAX  = 20000;
	private const PRODUCT_MAX  = 60;
	private const LICENCE_MIN  = 4;
	private const LICENCE_MAX  = 200;

	/**
	 * The body for OmnaBase, or the wrong fields' sentences.
	 *
	 * @param array<string,mixed> $sent What the form sent.
	 * @return array{body:array<string,string>,errors:array<string,string>}
	 */
	public static function check( array $sent ): array {
		$body = array(
			'name'    => self::line( $sent, 'name' ),
			'email'   => sanitize_email( self::line( $sent, 'email' ) ),
			'subject' => self::line( $sent, 'subject' ),
			'message' => self::text( $sent, 'message' ),
			'product' => self::line( $sent, 'product' ),
			'licence' => self::line( $sent, 'licence' ),
		);
		$errors = array_filter( self::errors( $body, self::line( $sent, 'email' ) ) );
		return array( 'body' => array_filter( $body, static fn ( string $v ) => '' !== $v ), 'errors' => $errors );
	}

	/**
	 * A message's fingerprint: the same words to the same project are the same message (a double click).
	 *
	 * @param string               $slug The project.
	 * @param array<string,string> $body The body.
	 */
	public static function fingerprint( string $slug, array $body ): string {
		ksort( $body );
		return hash( 'sha256', $slug . '|' . (string) wp_json_encode( $body ) );
	}

	/**
	 * A sentence per wrong field ('' for a good one).
	 *
	 * @param array<string,string> $body  The cleaned fields.
	 * @param string               $typed The address as typed.
	 * @return array<string,string>
	 */
	private static function errors( array $body, string $typed ): array {
		$licence = mb_strlen( $body['licence'] );
		return array(
			'name'    => self::between( $body['name'], 1, self::NAME_MAX ) ? '' : __( 'Please enter your name (at most 120 characters).', 'omnabase' ),
			'email'   => '' !== $body['email'] && is_email( $body['email'] ) && $body['email'] === $typed && strlen( $typed ) <= self::EMAIL_MAX ? '' : __( 'Please enter a valid email address.', 'omnabase' ),
			'subject' => self::between( $body['subject'], 1, self::SUBJECT_MAX ) ? '' : __( 'Please enter a subject (at most 200 characters).', 'omnabase' ),
			'message' => self::between( $body['message'], 1, self::MESSAGE_MAX ) ? '' : __( 'Please enter your message (at most 20,000 characters).', 'omnabase' ),
			'product' => mb_strlen( $body['product'] ) <= self::PRODUCT_MAX ? '' : __( 'Please pick a product from the list.', 'omnabase' ),
			'licence' => 0 === $licence || ( $licence >= self::LICENCE_MIN && $licence <= self::LICENCE_MAX ) ? '' : __( 'Please enter the licence key as you received it.', 'omnabase' ),
		);
	}

	/**
	 * Whether a text's length lies between two numbers.
	 *
	 * @param string $text The text.
	 * @param int    $min  The least.
	 * @param int    $max  The most.
	 */
	private static function between( string $text, int $min, int $max ): bool {
		$length = mb_strlen( $text );
		return $length >= $min && $length <= $max;
	}

	/**
	 * A one-line field, trimmed ('' when missing or not text).
	 *
	 * @param array<string,mixed> $sent The fields.
	 * @param string              $key  The field.
	 */
	private static function line( array $sent, string $key ): string {
		return is_string( $sent[ $key ] ?? null ) ? trim( sanitize_text_field( $sent[ $key ] ) ) : '';
	}

	/**
	 * A multi-line field, its line breaks kept.
	 *
	 * @param array<string,mixed> $sent The fields.
	 * @param string              $key  The field.
	 */
	private static function text( array $sent, string $key ): string {
		return is_string( $sent[ $key ] ?? null ) ? trim( sanitize_textarea_field( $sent[ $key ] ) ) : '';
	}
}
