<?php
/**
 * One sent form, from the browser's fields to the visitor's sentence.
 *
 * @package OmnaBase
 */

namespace OmnaBase\WordPress\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * The order of the checks (brief choice 8): the honeypot (filled: "sent", nothing goes, nothing counts); the project (one of the key's);
 * the fields (each wrong one named, the token kept for the corrected try); the token (unused, 3 seconds to 2 hours old, the same project;
 * a repeat of the same words answers the first answer); the limits (5 an hour per address, 25 for the site); then OmnaBase at once. The
 * answer is kept with the token, so a double click reads the same sentence and makes no second call.
 */
final class Intake {

	/**
	 * Builds the intake.
	 *
	 * @param Projects $projects The key's projects.
	 * @param Guard    $guard    The honeypot and the limits.
	 * @param Token    $token    The time tokens.
	 * @param Sender   $sender   The call to OmnaBase, the outbox behind it.
	 */
	public function __construct( private Projects $projects, private Guard $guard, private Token $token, private Sender $sender ) {}

	/**
	 * Handles what the form sent.
	 *
	 * @param array<string,mixed> $sent The fields as the browser sent them.
	 */
	public function handle( array $sent ): Answer {
		if ( $this->guard->trapped( $sent ) ) {
			return Answer::trapped();
		}
		$slug = is_string( $sent['project'] ?? null ) ? $sent['project'] : '';
		if ( ! $this->projects->allows( $slug ) ) {
			return Answer::unavailable();
		}
		$fields = Fields::check( $sent );
		if ( array() !== $fields['errors'] ) {
			return Answer::fields( $fields['errors'] );
		}
		$token = is_string( $sent['token'] ?? null ) ? $sent['token'] : '';
		$check = $this->token->take( $token, $slug, Fields::fingerprint( $slug, $fields['body'] ) );
		if ( Token::FRESH !== $check['state'] ) {
			return self::refused( $check );
		}
		$answer = $this->guard->allowSubmission() ? $this->sender->send( $slug, $fields['body'], Token::idempotencyKey( $token ) ) : Answer::later();
		$this->token->remember( $token, $answer );
		return $answer;
	}

	/**
	 * What a token that is not fresh answers.
	 *
	 * @param array{state:string,answer:?Answer} $check The token's check.
	 */
	private static function refused( array $check ): Answer {
		return match ( $check['state'] ) {
			// The same words again: the first answer, or "on its way" while the first click is still being sent.
			Token::REPEAT  => $check['answer'] ?? Answer::onItsWay(),
			Token::USED    => Answer::used(),
			Token::TOO_NEW => Answer::tooQuick(),
			default        => Answer::expired(),
		};
	}
}
